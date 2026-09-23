<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalBaseline;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalTypeMap;

/**
 * Bundle specs + a config export -> a {@see Plan} (ADR 0002).
 *
 * The planner starts from a working copy of every entity the export has and
 * changes only owned keys in it. A missing entity is built from the type map
 * and the defaults baseline. At the end each entity it looked at is
 * classified: CREATE (not in the export), UPDATE (an owned key changed),
 * REUSE (nothing changed), or REFUSE (the change needs a data migration or
 * contradicts itself).
 *
 * Merge rules:
 *   - it never removes a file, an instance, a display entry or a group;
 *   - an existing storage keeps its type, target type and cardinality; a
 *     definition that needs other values is refused;
 *   - an instance narrows an unlimited or larger storage through
 *     `field_config_cardinality`, when `drupal.field_config_cardinality` is on;
 *   - a new field joins the form display (and the view display, in the
 *     region `drupal.view_display` names); an existing entry stays as it is;
 *   - displays, groups and language settings are created with a new bundle
 *     only.
 */
final class DrupalConfigPlanner
{
    private const DEPENDENCY_ORDER = ['config', 'content', 'module', 'theme', 'enforced'];

    /** @var array<string,array<string,mixed>|null> name => entity as the export has it */
    private array $original = [];

    /** @var array<string,array<string,mixed>> name => entity as it will be */
    private array $working = [];

    /** @var array<string,list<string>> name => reasons for the UPDATE */
    private array $reasons = [];

    /** @var array<string,list<string>> name => reasons for the REFUSE */
    private array $refusals = [];

    /** @var array<string,array{type: string, cardinality: int}> field machine name => storage */
    private array $storages = [];

    /** @var array<string,true> bundles this plan creates */
    private array $createdBundles = [];

    private readonly DrupalBaseline $baseline;

    public function __construct(
        private readonly ConfigStore $store,
        private readonly DrupalSettings $settings = new DrupalSettings(),
        ?DrupalBaseline $baseline = null,
        private readonly DrupalTypeMap $types = new DrupalTypeMap(),
    ) {
        $this->baseline = $baseline ?? DrupalBaseline::load($settings->baseline);
    }

    /** @param list<BundleSpec> $specs */
    public function plan(array $specs): Plan
    {
        $this->original = $this->working = $this->reasons = $this->refusals = $this->storages = $this->createdBundles = [];
        $specs = $this->dedupe($specs);

        $byStorage = [];
        foreach ($specs as $spec) {
            foreach ($spec->fields as $field) {
                $byStorage[$field->machine][] = $field;
            }
        }

        foreach ($specs as $spec) {
            $this->paragraphType($spec);
        }
        foreach ($byStorage as $machine => $fields) {
            $this->storage($machine, $fields);
        }
        foreach ($specs as $spec) {
            $this->instances($spec);
            $this->displays($spec);
        }
        foreach ($specs as $spec) {
            if ($spec->topLevel && isset($this->createdBundles[$spec->bundle])) {
                $this->hostFields($spec->bundle);
            }
        }

        return $this->build();
    }

    // ---------------------------------------------------------------- bundles

    /**
     * @param list<BundleSpec> $specs
     * @return list<BundleSpec>
     */
    private function dedupe(array $specs): array
    {
        $seen = [];
        $out = [];
        foreach ($specs as $spec) {
            if (!isset($seen[$spec->bundle])) {
                $seen[$spec->bundle] = $spec;
                $out[] = $spec;
                continue;
            }
            $first = $seen[$spec->bundle];
            if (self::fieldSignatures($first) !== self::fieldSignatures($spec)) {
                $this->refuse(
                    "paragraphs.paragraphs_type.{$spec->bundle}",
                    "{$first->component} and {$spec->component} describe this paragraph type with different fields",
                );
            }
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private static function fieldSignatures(BundleSpec $spec): array
    {
        $signatures = [];
        foreach ($spec->fields as $field) {
            $signatures[$field->machine] = $field->signature();
        }
        ksort($signatures);

        return $signatures;
    }

    private function paragraphType(BundleSpec $spec): void
    {
        $name = "paragraphs.paragraphs_type.{$spec->bundle}";
        if (null !== $this->load($name)) {
            return;
        }
        $this->createdBundles[$spec->bundle] = true;
        $base = $this->baseline->section('paragraphs_type');
        $thirdParty = self::map($base['third_party_settings'] ?? []);
        $entity = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies([], self::thirdPartyModules($thirdParty)),
        ];
        if ([] !== $thirdParty) {
            $entity['third_party_settings'] = $thirdParty;
        }
        $entity += [
            'id' => $spec->bundle,
            'label' => $spec->label,
            'icon_uuid' => $base['icon_uuid'] ?? null,
            'icon_default' => $base['icon_default'] ?? null,
            'description' => $spec->description,
            'behavior_plugins' => self::map($base['behavior_plugins'] ?? []),
        ];
        $this->working[$name] = $entity;
    }

    // --------------------------------------------------------------- storages

    /** @param list<FieldSpec> $fields */
    private function storage(string $machine, array $fields): void
    {
        $name = "field.storage.paragraph.{$machine}";
        $existing = $this->load($name);

        $allowed = null;
        foreach ($fields as $field) {
            if (null === $field->allowedValues) {
                continue;
            }
            if (null !== $allowed && $allowed !== $field->allowedValues) {
                $this->refuse($name, "shared by fields with different options (`{$fields[0]->path}` on {$fields[0]->bundle}, `{$field->path}` on {$field->bundle})");

                return;
            }
            $allowed = $field->allowedValues;
        }

        null === $existing
            ? $this->createStorage($name, $machine, $fields, $allowed)
            : $this->mergeStorage($name, $machine, $existing, $fields, $allowed);
    }

    /**
     * @param array<string,mixed> $existing
     * @param list<FieldSpec> $fields
     * @param array<string,string>|null $allowed
     */
    private function mergeStorage(string $name, string $machine, array $existing, array $fields, ?array $allowed): void
    {
        $type = (string) ($existing['type'] ?? '');
        $cardinality = (int) ($existing['cardinality'] ?? 1);
        $refused = false;
        foreach ($fields as $field) {
            if (!in_array($type, $field->accepted, true)) {
                $this->refuse($name, sprintf(
                    'storage type %s; `%s` on %s needs %s — a storage type change needs a data migration',
                    $type,
                    $field->path,
                    $field->bundle,
                    implode('|', $field->accepted),
                ));
                $refused = true;
                continue;
            }
            $targetType = $existing['settings']['target_type'] ?? null;
            if (null !== $field->targetType && is_string($targetType) && $targetType !== $field->targetType) {
                $this->refuse($name, "references {$targetType}; `{$field->path}` on {$field->bundle} needs {$field->targetType} — a target type change needs a data migration");
                $refused = true;
                continue;
            }
            if (!self::fits($field->cardinality, $cardinality)) {
                $this->refuse($name, sprintf(
                    'cardinality %s; `%s` on %s needs %s — a storage cardinality change needs a data migration',
                    self::cardinalityText($cardinality),
                    $field->path,
                    $field->bundle,
                    self::cardinalityText($field->cardinality),
                ));
                $refused = true;
            }
        }
        if ($refused) {
            return;
        }
        $this->storages[$machine] = ['type' => $type, 'cardinality' => $cardinality];

        if (null !== $allowed) {
            $current = self::allowedValues($existing['settings']['allowed_values'] ?? []);
            $dropped = array_diff(array_map('strval', array_keys($current)), array_keys($allowed));
            if ([] !== $dropped) {
                $this->refuse($name, 'drops allowed value(s) ' . implode(', ', $dropped) . ' that the storage has — removing a value needs a data check');

                return;
            }
            if (!self::sameAllowedValues($current, $allowed)) {
                $this->working[$name]['settings']['allowed_values'] = self::allowedValuesList($allowed, $type);
                $this->because($name, 'allowed_values');
            }
        }
    }

    /**
     * @param list<FieldSpec> $fields
     * @param array<string,string>|null $allowed
     */
    private function createStorage(string $name, string $machine, array $fields, ?array $allowed): void
    {
        $candidates = $fields[0]->accepted;
        $pinned = null;
        foreach ($fields as $field) {
            $candidates = array_values(array_intersect($candidates, $field->accepted));
            if ($field->storagePinned) {
                $pinned = $field->accepted[0];
            }
        }
        if ([] === $candidates) {
            $this->refuse($name, 'fields that share it need different storage types (' . implode('; ', array_map(
                static fn (FieldSpec $f): string => "`{$f->path}` on {$f->bundle}: " . implode('|', $f->accepted),
                $fields,
            )) . ')');

            return;
        }
        $type = null !== $pinned && in_array($pinned, $candidates, true) ? $pinned : $candidates[0];

        $cardinalities = array_values(array_unique(array_map(static fn (FieldSpec $f): int => $f->cardinality, $fields)));
        if (1 === count($cardinalities)) {
            $cardinality = $cardinalities[0];
        } elseif ($this->settings->fieldConfigCardinality) {
            $cardinality = in_array(-1, $cardinalities, true) ? -1 : max(1, ...$cardinalities);
        } else {
            $this->refuse($name, 'fields that share it need different cardinalities (' . implode(', ', array_map(
                static fn (FieldSpec $f): string => "`{$f->path}` on {$f->bundle}: " . self::cardinalityText($f->cardinality),
                $fields,
            )) . ') — turn on drupal.field_config_cardinality or pin separate fields');

            return;
        }

        $targetTypes = array_values(array_unique(array_filter(array_map(static fn (FieldSpec $f): ?string => $f->targetType, $fields))));
        if (count($targetTypes) > 1) {
            $this->refuse($name, 'fields that share it reference different entity types (' . implode(', ', $targetTypes) . ')');

            return;
        }

        $info = $this->types->storageType($type);
        $settings = $info['storage_settings'];
        if ([] !== $targetTypes && array_key_exists('target_type', $settings) && 'file' !== $settings['target_type']) {
            $settings['target_type'] = $targetTypes[0];
        }
        if (null !== $allowed) {
            $settings['allowed_values'] = self::allowedValuesList($allowed, $type);
        }
        $modules = [$this->types->storageModule($type), $this->types->targetTypeModule('paragraph')];
        if (is_string($settings['target_type'] ?? null)) {
            $modules[] = $this->types->targetTypeModule($settings['target_type']);
        }

        $base = $this->baseline->section('field_storage');
        $this->working[$name] = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies([], $modules),
            'id' => "paragraph.{$machine}",
            'field_name' => $machine,
            'entity_type' => 'paragraph',
            'type' => $type,
            'settings' => $settings,
            'module' => $info['module'],
            'locked' => $base['locked'] ?? false,
            'cardinality' => $cardinality,
            'translatable' => $base['translatable'] ?? true,
            'indexes' => self::map($base['indexes'] ?? []),
            'persist_with_no_fields' => $base['persist_with_no_fields'] ?? false,
            'custom_storage' => $base['custom_storage'] ?? false,
        ];
        $this->storages[$machine] = ['type' => $type, 'cardinality' => $cardinality];
    }

    // -------------------------------------------------------------- instances

    private function instances(BundleSpec $spec): void
    {
        foreach ($spec->fields as $field) {
            $storage = $this->storages[$field->machine] ?? null;
            if (null === $storage) {
                continue; // refused on the storage
            }
            $name = "field.field.paragraph.{$spec->bundle}.{$field->machine}";
            null === $this->load($name)
                ? $this->createInstance($name, $spec, $field, $storage)
                : $this->mergeInstance($name, $spec, $field, $storage);
        }
    }

    /** @param array{type: string, cardinality: int} $storage */
    private function mergeInstance(string $name, BundleSpec $spec, FieldSpec $field, array $storage): void
    {
        $entity = $this->working[$name];
        $type = (string) ($entity['field_type'] ?? $storage['type']);
        if ($type !== $storage['type']) {
            $this->refuse($name, "field_type {$type} differs from its storage type {$storage['type']}");

            return;
        }

        if ($spec->ownsText) {
            $this->own($name, 'label', $field->label);
            $this->own($name, 'description', $field->description);
        }
        $this->own($name, 'required', $field->required);
        if (null !== $field->translatable) {
            $this->own($name, 'translatable', $field->translatable);
        }

        $effective = DrupalConfig::effectiveCardinality(['cardinality' => $storage['cardinality']], $entity);
        if ($effective !== $field->cardinality) {
            if (!$this->narrow($name, $field, $storage['cardinality'])) {
                return;
            }
            $this->because($name, 'cardinality ' . self::cardinalityText($effective) . ' -> ' . self::cardinalityText($field->cardinality));
        }

        if (null !== $field->targetBundles && null !== $field->targetType) {
            $this->ownTargets($name, $field->targetType, $field->targetBundles);
        }

        if (null !== $field->linkUrlOnly) {
            $title = (int) ($entity['settings']['title'] ?? 1);
            if ($field->linkUrlOnly && 0 !== $title) {
                $this->working[$name]['settings']['title'] = 0;
                $this->because($name, 'link title');
            } elseif (!$field->linkUrlOnly && 0 === $title) {
                $this->working[$name]['settings']['title'] = (int) ($this->types->storageType('link')['instance_settings']['title'] ?? 1);
                $this->because($name, 'link title');
            }
        }
    }

    /** @param array{type: string, cardinality: int} $storage */
    private function createInstance(string $name, BundleSpec $spec, FieldSpec $field, array $storage): void
    {
        $type = $storage['type'];
        $info = $this->types->storageType($type);
        $settings = $info['instance_settings'];
        $config = ["field.storage.paragraph.{$field->machine}", "paragraphs.paragraphs_type.{$spec->bundle}"];
        $modules = [$this->types->storageModule($type)];

        if (array_key_exists('allowed_formats', $settings) && null !== $this->settings->textFormat) {
            $settings['allowed_formats'] = [$this->settings->textFormat];
            $config[] = "filter.format.{$this->settings->textFormat}";
        }
        $targetType = $this->storageTargetType($field->machine);
        if (in_array($type, ['entity_reference', 'entity_reference_revisions'], true) && null !== $targetType) {
            $settings['handler'] = "default:{$targetType}";
            $bundles = $field->targetBundles;
            if (null === $bundles && 'media' === $targetType) {
                $bundles = $this->mediaBundles($field->mediaKind ?? 'image');
            }
            $handler = self::map($settings['handler_settings'] ?? []);
            $handler['target_bundles'] = null === $bundles ? null : self::bundleMap($bundles);
            if ('entity_reference_revisions' === $type) {
                $handler['negate'] = 0;
                $handler['target_bundles_drag_drop'] = [];
                foreach ($bundles ?? [] as $i => $bundle) {
                    $handler['target_bundles_drag_drop'][$bundle] = ['weight' => $i, 'enabled' => true];
                }
            }
            $settings['handler_settings'] = $handler;
            $prefix = $this->types->bundleConfigPrefix($targetType);
            foreach (null !== $prefix ? $bundles ?? [] : [] as $bundle) {
                $config[] = "{$prefix}.{$bundle}";
            }
        }
        if (null !== $field->linkUrlOnly && $field->linkUrlOnly) {
            $settings['title'] = 0;
        }

        $base = $this->baseline->section('field_instance');
        $thirdParty = self::map($base['third_party_settings'] ?? []);
        if ($field->cardinality !== $storage['cardinality']) {
            if (!$this->settings->fieldConfigCardinality) {
                $this->refuseNarrowing($name, $field, $storage['cardinality']);

                return;
            }
            $thirdParty['field_config_cardinality'] = ['cardinality_config' => (string) $field->cardinality]
                + $this->baseline->section('field_config_cardinality');
        }
        $modules = [...$modules, ...self::thirdPartyModules($thirdParty)];

        $entity = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies($config, $modules),
        ];
        if ([] !== $thirdParty) {
            $entity['third_party_settings'] = $thirdParty;
        }
        $entity += [
            'id' => "paragraph.{$spec->bundle}.{$field->machine}",
            'field_name' => $field->machine,
            'entity_type' => 'paragraph',
            'bundle' => $spec->bundle,
            'label' => $field->label,
            'description' => $field->description,
            'required' => $field->required,
            'translatable' => $field->translatable ?? ($base['translatable'] ?? true),
            'default_value' => self::map($base['default_value'] ?? []),
            'default_value_callback' => $base['default_value_callback'] ?? '',
            'settings' => $settings,
            'field_type' => $type,
        ];
        $this->working[$name] = $entity;
    }

    /**
     * Give an existing instance the cardinality the definition needs, through
     * `field_config_cardinality`. False when that is not possible.
     */
    private function narrow(string $name, FieldSpec $field, int $storageCardinality): bool
    {
        $entity = $this->working[$name];
        $hasOverride = null !== DrupalConfig::cardinalityOverride($entity);
        if ($field->cardinality === $storageCardinality && $hasOverride) {
            $this->working[$name]['third_party_settings']['field_config_cardinality']['cardinality_config'] = (string) $field->cardinality;

            return true;
        }
        if (!$this->settings->fieldConfigCardinality) {
            $this->refuseNarrowing($name, $field, $storageCardinality);

            return false;
        }
        $current = self::map($entity['third_party_settings']['field_config_cardinality'] ?? []);
        $current['cardinality_config'] = (string) $field->cardinality;
        $current += $this->baseline->section('field_config_cardinality');
        $thirdParty = self::map($entity['third_party_settings'] ?? []);
        $thirdParty['field_config_cardinality'] = $current;
        $this->working[$name] = self::withKey($this->working[$name], 'third_party_settings', $thirdParty, 'id');
        $this->addDependencies($name, [], ['field_config_cardinality']);

        return true;
    }

    private function refuseNarrowing(string $name, FieldSpec $field, int $storageCardinality): void
    {
        $this->refuse($name, sprintf(
            '`%s` needs cardinality %s on a storage with %s — turn on drupal.field_config_cardinality to narrow it per bundle',
            $field->path,
            self::cardinalityText($field->cardinality),
            self::cardinalityText($storageCardinality),
        ));
    }

    /** @param list<string> $bundles */
    private function ownTargets(string $name, string $targetType, array $bundles): void
    {
        $handler = self::map($this->working[$name]['settings']['handler_settings'] ?? []);
        $current = is_array($handler['target_bundles'] ?? null) ? array_map('strval', array_keys($handler['target_bundles'])) : [];
        sort($current);
        $wanted = $bundles;
        sort($wanted);
        $negated = !empty($handler['negate']);
        if ($current === $wanted && !$negated) {
            return;
        }

        $handler['target_bundles'] = self::bundleMap($wanted);
        if ($negated) {
            $handler['negate'] = 0;
        }
        if (is_array($handler['target_bundles_drag_drop'] ?? null)) {
            $dragDrop = $handler['target_bundles_drag_drop'];
            $weight = self::maxWeight($dragDrop);
            foreach (array_keys($dragDrop) as $bundle) {
                if (is_array($dragDrop[$bundle])) {
                    $dragDrop[$bundle]['enabled'] = in_array((string) $bundle, $wanted, true);
                }
            }
            foreach ($wanted as $bundle) {
                if (!isset($dragDrop[$bundle])) {
                    $dragDrop[$bundle] = ['weight' => ++$weight, 'enabled' => true];
                }
            }
            ksort($dragDrop);
            $handler['target_bundles_drag_drop'] = $dragDrop;
        }
        $this->working[$name]['settings']['handler_settings'] = $handler;

        $prefix = $this->types->bundleConfigPrefix($targetType);
        if (null !== $prefix) {
            $own = "paragraphs.paragraphs_type." . ($this->working[$name]['bundle'] ?? '');
            $remove = array_map(static fn (string $b): string => "{$prefix}.{$b}", array_diff($current, $wanted));
            $remove = array_values(array_filter($remove, static fn (string $dep): bool => $dep !== $own));
            $this->removeDependencies($name, $remove);
            $this->addDependencies($name, array_map(static fn (string $b): string => "{$prefix}.{$b}", $wanted), []);
        }
        $this->because($name, 'target_bundles');
    }

    // --------------------------------------------------------------- displays

    private function displays(BundleSpec $spec): void
    {
        $fields = array_values(array_filter($spec->fields, fn (FieldSpec $f): bool => isset($this->storages[$f->machine])));
        $form = "core.entity_form_display.paragraph.{$spec->bundle}.default";
        $view = "core.entity_view_display.paragraph.{$spec->bundle}.default";

        if (isset($this->createdBundles[$spec->bundle])) {
            if (null === $this->load($form)) {
                $this->createFormDisplay($form, $spec, $fields);
            }
            if (null === $this->load($view)) {
                $this->createViewDisplay($view, $spec, $fields);
            }
            $language = "language.content_settings.paragraph.{$spec->bundle}";
            if ($this->settings->translation && null === $this->load($language)) {
                $this->createLanguageSettings($language, $spec->bundle);
            }

            return;
        }

        $new = array_values(array_filter(
            $fields,
            fn (FieldSpec $f): bool => isset($this->working["field.field.paragraph.{$spec->bundle}.{$f->machine}"])
                && null === ($this->original["field.field.paragraph.{$spec->bundle}.{$f->machine}"] ?? null),
        ));
        if (null !== $this->load($form)) {
            $this->addToFormDisplay($form, $spec->bundle, $new);
        }
        if (null !== $this->load($view)) {
            $this->addToViewDisplay($view, $spec->bundle, $new);
        }
    }

    /** @param list<FieldSpec> $fields */
    private function createFormDisplay(string $name, BundleSpec $spec, array $fields): void
    {
        $base = $this->baseline->section('form_display');
        $content = [];
        $modules = [];
        $config = [];
        $weights = [];
        foreach ($fields as $weight => $field) {
            [$entry, $module] = $this->widgetEntry($field, $weight);
            $content[$field->machine] = $entry;
            $modules = [...$modules, $module, ...self::thirdPartyModules($entry['third_party_settings'])];
            $config[] = "field.field.paragraph.{$spec->bundle}.{$field->machine}";
            $weights[$field->machine] = $weight;
        }
        if ($this->settings->translation) {
            $content['translation'] = self::map($base['translation'] ?? []);
        }
        ksort($content);

        $thirdParty = [];
        if ([] !== $spec->groups) {
            $groupBase = self::map($base['field_group'] ?? []);
            $groups = [];
            foreach ($spec->groups as $group => $info) {
                $children = array_values(array_filter($info['children'], static fn (string $c): bool => isset($weights[$c]) || isset($spec->groups[$c])));
                $groups[$group] = [
                    'children' => $children,
                    'label' => $info['label'],
                    'region' => $groupBase['region'] ?? 'content',
                    'parent_name' => $info['parent'],
                    'weight' => self::groupWeight($group, $spec->groups, $weights),
                    'format_type' => $groupBase['format_type'] ?? 'details',
                    'format_settings' => self::map($groupBase['format_settings'] ?? []),
                ];
            }
            $thirdParty['field_group'] = $groups;
            $modules[] = 'field_group';
        }

        $entity = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies([...$config, "paragraphs.paragraphs_type.{$spec->bundle}"], $modules),
        ];
        if ([] !== $thirdParty) {
            $entity['third_party_settings'] = $thirdParty;
        }
        $entity += [
            'id' => "paragraph.{$spec->bundle}.default",
            'targetEntityType' => 'paragraph',
            'bundle' => $spec->bundle,
            'mode' => 'default',
            'content' => $content,
            'hidden' => self::map($base['hidden'] ?? []),
        ];
        $this->working[$name] = $entity;
    }

    /** @param list<FieldSpec> $fields */
    private function addToFormDisplay(string $name, string $bundle, array $fields): void
    {
        $entity = $this->working[$name];
        $content = self::map($entity['content'] ?? []);
        $hidden = self::map($entity['hidden'] ?? []);
        $weight = self::maxWeight($content);
        foreach ($fields as $field) {
            if (isset($content[$field->machine]) || isset($hidden[$field->machine])) {
                continue;
            }
            [$entry, $module] = $this->widgetEntry($field, ++$weight);
            $content[$field->machine] = $entry;
            $this->addDependencies($name, ["field.field.paragraph.{$bundle}.{$field->machine}"], [$module, ...self::thirdPartyModules($entry['third_party_settings'])]);
            $group = [] !== $field->groups ? $field->groups[count($field->groups) - 1] : null;
            $groupChildren = null !== $group ? ($this->working[$name]['third_party_settings']['field_group'][$group]['children'] ?? null) : null;
            if (null !== $group && is_array($groupChildren)) {
                $groupChildren[] = $field->machine;
                $this->working[$name]['third_party_settings']['field_group'][$group]['children'] = $groupChildren;
            }
            $this->because($name, "adds {$field->machine}");
        }
        ksort($content);
        $this->working[$name]['content'] = $content;
    }

    /** @param list<FieldSpec> $fields */
    private function createViewDisplay(string $name, BundleSpec $spec, array $fields): void
    {
        $base = $this->baseline->section('view_display');
        $content = self::map($base['content'] ?? []);
        $hidden = self::map($base['hidden'] ?? []);
        $modules = [];
        $config = [];
        foreach ($fields as $weight => $field) {
            $config[] = "field.field.paragraph.{$spec->bundle}.{$field->machine}";
            if (DrupalSettings::VIEW_HIDDEN === $this->settings->viewDisplay) {
                $hidden[$field->machine] = true;
                continue;
            }
            [$entry, $module] = $this->formatterEntry($field, $weight);
            $content[$field->machine] = $entry;
            $modules[] = $module;
        }
        ksort($content);
        ksort($hidden);
        $this->working[$name] = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies([...$config, "paragraphs.paragraphs_type.{$spec->bundle}"], $modules),
            'id' => "paragraph.{$spec->bundle}.default",
            'targetEntityType' => 'paragraph',
            'bundle' => $spec->bundle,
            'mode' => 'default',
            'content' => $content,
            'hidden' => $hidden,
        ];
    }

    /** @param list<FieldSpec> $fields */
    private function addToViewDisplay(string $name, string $bundle, array $fields): void
    {
        $content = self::map($this->working[$name]['content'] ?? []);
        $hidden = self::map($this->working[$name]['hidden'] ?? []);
        $weight = self::maxWeight($content);
        foreach ($fields as $field) {
            if (isset($content[$field->machine]) || isset($hidden[$field->machine])) {
                continue;
            }
            $module = null;
            if (DrupalSettings::VIEW_HIDDEN === $this->settings->viewDisplay) {
                $hidden[$field->machine] = true;
            } else {
                [$entry, $module] = $this->formatterEntry($field, ++$weight);
                $content[$field->machine] = $entry;
            }
            $this->addDependencies($name, ["field.field.paragraph.{$bundle}.{$field->machine}"], [$module]);
            $this->because($name, "adds {$field->machine}");
        }
        ksort($content);
        ksort($hidden);
        $this->working[$name]['content'] = $content;
        $this->working[$name]['hidden'] = $hidden;
    }

    /** @return array{0: array{type: string, weight: int, region: string, settings: array<string,mixed>, third_party_settings: array<string,mixed>}, 1: ?string} */
    private function widgetEntry(FieldSpec $field, int $weight): array
    {
        $type = $this->storages[$field->machine]['type'];
        $default = $this->types->widget($type, $this->storageTargetType($field->machine));
        $widget = $field->widget ?? $default['type'];

        return [[
            'type' => $widget,
            'weight' => $weight,
            'region' => 'content',
            'settings' => $widget === $default['type'] ? $default['settings'] : [],
            'third_party_settings' => $this->baseline->widgetThirdPartySettings($widget),
        ], $this->types->widgetModule($widget)];
    }

    /** @return array{0: array<string,mixed>, 1: ?string} */
    private function formatterEntry(FieldSpec $field, int $weight): array
    {
        $type = $this->storages[$field->machine]['type'];
        $default = $this->types->formatter($type, $this->storageTargetType($field->machine));
        $formatter = $field->formatter ?? $default['type'];

        return [[
            'type' => $formatter,
            'label' => $default['label'],
            'settings' => $formatter === $default['type'] ? $default['settings'] : [],
            'third_party_settings' => [],
            'weight' => $weight,
            'region' => 'content',
        ], $this->types->formatterModule($formatter)];
    }

    private function createLanguageSettings(string $name, string $bundle): void
    {
        $base = $this->baseline->section('language_content_settings');
        $thirdParty = self::map($base['third_party_settings'] ?? []);
        $entity = [
            'langcode' => $this->settings->langcode,
            'status' => $base['status'] ?? true,
            'dependencies' => self::dependencies(["paragraphs.paragraphs_type.{$bundle}"], self::thirdPartyModules($thirdParty)),
        ];
        if ([] !== $thirdParty) {
            $entity['third_party_settings'] = $thirdParty;
        }
        $this->working[$name] = $entity + [
            'id' => "paragraph.{$bundle}",
            'target_entity_type_id' => 'paragraph',
            'target_bundle' => $bundle,
            'default_langcode' => $base['default_langcode'] ?? 'site_default',
            'language_alterable' => $base['language_alterable'] ?? false,
        ];
    }

    // ------------------------------------------------------------ host fields

    private function hostFields(string $bundle): void
    {
        foreach ($this->settings->hostFields as $name) {
            if (null === $this->load($name)) {
                $this->refuse($name, "drupal.host_fields names this field, and the export does not have it (new paragraph type {$bundle} needs a host)");
                continue;
            }
            $handler = self::map($this->working[$name]['settings']['handler_settings'] ?? []);
            if (!empty($handler['negate'])) {
                continue; // an exclusion list already allows the new bundle
            }
            $targets = self::map($handler['target_bundles'] ?? []);
            if (isset($targets[$bundle])) {
                continue;
            }
            $targets[$bundle] = $bundle;
            $handler['target_bundles'] = $targets;
            if (is_array($handler['target_bundles_drag_drop'] ?? null)) {
                $dragDrop = $handler['target_bundles_drag_drop'];
                $dragDrop[$bundle] = ['weight' => self::maxWeight($dragDrop) + 1, 'enabled' => true];
                ksort($dragDrop);
                $handler['target_bundles_drag_drop'] = $dragDrop;
            }
            $this->working[$name]['settings']['handler_settings'] = $handler;
            $this->addDependencies($name, ["paragraphs.paragraphs_type.{$bundle}"], []);
            $this->because($name, "adds target {$bundle}");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed>|null */
    private function load(string $name): ?array
    {
        if (!array_key_exists($name, $this->original)) {
            $this->original[$name] = $this->store->get($name);
            if (null !== $this->original[$name]) {
                $this->working[$name] = $this->original[$name];
            }
        }

        return $this->working[$name] ?? null;
    }

    private function own(string $name, string $key, mixed $value): void
    {
        $current = $this->working[$name][$key] ?? null;
        if (is_bool($value) ? (bool) $current !== $value : (string) $current !== $value) {
            $this->working[$name][$key] = $value;
            $this->because($name, $key);
        }
    }

    private function because(string $name, string $reason): void
    {
        if (!in_array($reason, $this->reasons[$name] ?? [], true)) {
            $this->reasons[$name][] = $reason;
        }
    }

    private function refuse(string $name, string $reason): void
    {
        $this->refusals[$name][] = $reason;
    }

    private function storageTargetType(string $machine): ?string
    {
        $target = $this->working["field.storage.paragraph.{$machine}"]['settings']['target_type'] ?? null;

        return is_string($target) ? $target : null;
    }

    /** @return list<string> */
    private function mediaBundles(string $kind): array
    {
        return $this->settings->mediaBundles[$kind]
            ?? ('gallery' === $kind ? ($this->settings->mediaBundles['image'] ?? null) : null)
            ?? $this->baseline->mediaBundles($kind);
    }

    /**
     * @param list<string> $config
     * @param list<string|null> $modules
     */
    private function addDependencies(string $name, array $config, array $modules): void
    {
        $dependencies = self::map($this->working[$name]['dependencies'] ?? []);
        foreach (['config' => $config, 'module' => array_values(array_filter($modules))] as $kind => $add) {
            $current = array_values(array_map('strval', (array) ($dependencies[$kind] ?? [])));
            $missing = array_diff($add, $current);
            if ([] === $missing) {
                continue;
            }
            $merged = array_values(array_unique([...$current, ...$missing]));
            sort($merged);
            $dependencies[$kind] = $merged;
        }
        $this->working[$name]['dependencies'] = self::orderDependencies($dependencies);
    }

    /** @param list<string> $remove */
    private function removeDependencies(string $name, array $remove): void
    {
        $dependencies = self::map($this->working[$name]['dependencies'] ?? []);
        if (!is_array($dependencies['config'] ?? null) || [] === $remove) {
            return;
        }
        $dependencies['config'] = array_values(array_diff(array_map('strval', $dependencies['config']), $remove));
        if ([] === $dependencies['config']) {
            unset($dependencies['config']);
        }
        $this->working[$name]['dependencies'] = $dependencies;
    }

    private function build(): Plan
    {
        $entries = [];
        $names = array_unique([...array_keys($this->working), ...array_keys($this->refusals)]);
        foreach ($names as $name) {
            if (isset($this->refusals[$name])) {
                $entries[] = new PlanEntry(PlanEntry::REFUSE, $name, $this->refusals[$name]);
                continue;
            }
            $data = $this->working[$name];
            $original = $this->original[$name] ?? null;
            if (null === $original) {
                $entries[] = new PlanEntry(PlanEntry::CREATE, $name, [], $data);
            } elseif (self::canonical($data) !== self::canonical($original)) {
                $entries[] = new PlanEntry(PlanEntry::UPDATE, $name, $this->reasons[$name] ?? ['dependencies'], $data);
            } else {
                $entries[] = new PlanEntry(PlanEntry::REUSE, $name);
            }
        }

        return new Plan($entries);
    }

    /**
     * Maps sorted by key at every depth, lists kept in order, so that key
     * order alone is never a change.
     */
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map([self::class, 'canonical'], $value);
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private static function fits(int $need, int $storage): bool
    {
        if (-1 === $storage) {
            return true;
        }

        return -1 !== $need && $need <= $storage;
    }

    private static function cardinalityText(int $cardinality): string
    {
        return -1 === $cardinality ? 'unlimited' : (string) $cardinality;
    }

    /**
     * @param mixed $raw allowed_values as exported: a list of {value, label} rows, or a value => label map
     * @return array<string,string>
     */
    private static function allowedValues(mixed $raw): array
    {
        $values = [];
        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (is_array($row) && array_key_exists('value', $row)) {
                $values[(string) $row['value']] = (string) ($row['label'] ?? $row['value']);
            } elseif (is_scalar($row)) {
                $values[(string) $key] = (string) $row;
            }
        }

        return $values;
    }

    /**
     * @param array<string,string> $a
     * @param array<string,string> $b
     */
    private static function sameAllowedValues(array $a, array $b): bool
    {
        return array_map('strval', array_keys($a)) === array_map('strval', array_keys($b))
            && array_values($a) === array_values($b);
    }

    /**
     * @param array<string,string> $allowed
     * @return list<array{value: int|float|string, label: string}>
     */
    private static function allowedValuesList(array $allowed, string $type): array
    {
        $rows = [];
        foreach ($allowed as $value => $label) {
            $value = (string) $value;
            $typed = match ($type) {
                'list_integer' => (int) $value,
                'list_float' => (float) $value,
                default => $value,
            };
            $rows[] = ['value' => $typed, 'label' => $label];
        }

        return $rows;
    }

    /**
     * @param list<string> $bundles
     * @return array<string,string>
     */
    private static function bundleMap(array $bundles): array
    {
        $map = [];
        foreach ($bundles as $bundle) {
            $map[$bundle] = $bundle;
        }

        return $map;
    }

    /**
     * The highest weight among display or drag-drop entries, or -1 when none
     * has one, so that the next entry gets weight 0. Weights can be negative.
     *
     * @param array<mixed> $entries
     */
    private static function maxWeight(array $entries): int
    {
        $max = null;
        foreach ($entries as $entry) {
            if (is_array($entry) && is_numeric($entry['weight'] ?? null)) {
                $max = null === $max ? (int) $entry['weight'] : max($max, (int) $entry['weight']);
            }
        }

        return $max ?? -1;
    }

    /**
     * @param array<string,array{label: string, parent: string, children: list<string>}> $groups
     * @param array<string,int> $weights
     */
    private static function groupWeight(string $group, array $groups, array $weights): int
    {
        $min = PHP_INT_MAX;
        foreach ($groups[$group]['children'] as $child) {
            $min = min($min, $weights[$child] ?? (isset($groups[$child]) ? self::groupWeight($child, $groups, $weights) : PHP_INT_MAX));
        }

        return PHP_INT_MAX === $min ? 0 : $min;
    }

    /**
     * @param array<mixed> $thirdParty
     * @return list<string>
     */
    private static function thirdPartyModules(array $thirdParty): array
    {
        return array_map('strval', array_keys($thirdParty));
    }

    /**
     * @param list<string> $config
     * @param list<string|null> $modules
     * @return array<string,list<string>>
     */
    private static function dependencies(array $config, array $modules): array
    {
        $dependencies = [];
        $config = array_values(array_unique($config));
        $modules = array_values(array_unique(array_filter($modules, static fn (?string $m): bool => null !== $m && '' !== $m)));
        if ([] !== $config) {
            sort($config);
            $dependencies['config'] = $config;
        }
        if ([] !== $modules) {
            sort($modules);
            $dependencies['module'] = $modules;
        }

        return $dependencies;
    }

    /**
     * @param array<string,mixed> $dependencies
     * @return array<string,mixed>
     */
    private static function orderDependencies(array $dependencies): array
    {
        $ordered = [];
        foreach (self::DEPENDENCY_ORDER as $kind) {
            if (array_key_exists($kind, $dependencies)) {
                $ordered[$kind] = $dependencies[$kind];
            }
        }

        return $ordered + $dependencies;
    }

    /**
     * Set a top-level key; a new key goes right before `$before`, where a
     * Drupal export puts it.
     *
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private static function withKey(array $entity, string $key, mixed $value, string $before): array
    {
        if (array_key_exists($key, $entity) || !array_key_exists($before, $entity)) {
            $entity[$key] = $value;

            return $entity;
        }
        $out = [];
        foreach ($entity as $k => $v) {
            if ($k === $before) {
                $out[$key] = $value;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
