<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\BundleResolver;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalTypeMap;
use Parisek\DefinitionKit\Support\StructuralType;

/**
 * A definition -> the paragraph types and field instances it describes.
 *
 * The mapping is the one {@see \Parisek\DefinitionKit\Lint\DrupalDriftLinter}
 * checks, read in the other direction, so that the generator's output is
 * lint-clean by construction:
 *
 *   - bundles: {@see BundleResolver} (root `drupal:` link, snake_case
 *     convention when the export has the bundle, `drupal.bundle_aliases`);
 *   - only `role: field` (the default, inherited) becomes a Drupal field;
 *   - the machine name is `drupal.field`, else
 *     {@see DrupalSettings::conventionalFieldName()} of the leaf;
 *   - an `object` without a `drupal.field` pin is a field_group: its
 *     children are fields of the same bundle;
 *   - a `list` targets `drupal.target_bundles`, else `<bundle>_item`; an
 *     `object` pinned by `drupal.field` targets its one
 *     `drupal.target_bundles`; a `flexible_content` targets one bundle per
 *     layout. Each target is a nested bundle, described by the nested fields.
 */
final class BundleSpecBuilder
{
    public function __construct(
        private readonly DrupalSettings $settings = new DrupalSettings(),
        private readonly DrupalTypeMap $types = new DrupalTypeMap(),
    ) {
    }

    /**
     * @param array<mixed> $definition
     * @return list<BundleSpec> empty when the component describes no paragraph type
     */
    public function build(array $definition, string $componentSlug, ?DrupalConfig $config): array
    {
        $definition = StructuralType::normalize($definition);
        $link = is_string($definition['drupal'] ?? null) ? $definition['drupal'] : null;
        $bundles = (new BundleResolver($this->settings))->resolve($componentSlug, $link, $config);

        $label = is_string($definition['name'] ?? null) && '' !== trim($definition['name'])
            ? $definition['name']
            : self::humanize(BundleResolver::conventionalBundle($componentSlug));
        $description = is_string($definition['description'] ?? null) ? trim($definition['description']) : '';
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];

        $specs = [];
        foreach ($bundles as $bundle => $source) {
            $this->bundle(
                $specs,
                $fields,
                $bundle,
                $componentSlug,
                $label,
                $description,
                BundleResolver::SOURCE_ALIAS !== $source,
                true,
                [$bundle],
            );
        }

        return $specs;
    }

    /**
     * Human label for a bundle the definition gives no name: `card_list_item`
     * -> `Card list item`.
     */
    public static function humanize(string $bundle): string
    {
        return ucfirst(str_replace('_', ' ', $bundle));
    }

    /**
     * @param list<BundleSpec> $specs
     * @param array<mixed> $fields
     * @param list<string> $stack
     */
    private function bundle(
        array &$specs,
        array $fields,
        string $bundle,
        string $component,
        string $label,
        string $description,
        bool $ownsText,
        bool $topLevel,
        array $stack,
    ): void {
        self::assertMachineName($bundle, "paragraph type of {$component}");
        $fieldSpecs = [];
        $groups = [];
        $nested = [];
        $this->level($fields, $bundle, $component, '', 'field', [], $fieldSpecs, $groups, $nested, $ownsText, $stack);

        $specs[] = new BundleSpec($bundle, $component, $label, $description, $ownsText, $topLevel, $fieldSpecs, $groups);
        foreach ($nested as $child) {
            $this->bundle(
                $specs,
                $child['fields'],
                $child['bundle'],
                $component,
                $child['label'],
                '',
                $ownsText,
                false,
                [...$stack, $child['bundle']],
            );
        }
    }

    /**
     * @param array<mixed> $fields
     * @param list<string> $groupPath
     * @param list<FieldSpec> $fieldSpecs
     * @param array<string,array{label: string, parent: string, children: list<string>}> $groups
     * @param list<array{bundle: string, label: string, fields: array<mixed>}> $nested
     * @param list<string> $stack
     */
    private function level(
        array $fields,
        string $bundle,
        string $component,
        string $prefix,
        string $inheritedRole,
        array $groupPath,
        array &$fieldSpecs,
        array &$groups,
        array &$nested,
        bool $ownsText,
        array $stack,
    ): void {
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) $name;
            $path = '' === $prefix ? $name : "{$prefix}.{$name}";
            $role = is_string($field['role'] ?? null) ? $field['role'] : $inheritedRole;
            if ('field' !== $role) {
                continue;
            }
            $bundleScope = is_array($field['drupal']['bundles'] ?? null) ? $field['drupal']['bundles'] : null;
            if (null !== $bundleScope && !in_array($bundle, $bundleScope, true)) {
                // Scoped to other aliased bundle(s) only — never create or
                // update it on this one. Mirrors DrupalDriftLinter's own
                // handling of `drupal.bundles`, so the plan stays lint-clean.
                continue;
            }
            $type = StructuralType::canonical((string) ($field['type'] ?? ''));
            $pin = is_string($field['drupal']['field'] ?? null) ? $field['drupal']['field'] : null;
            $parentGroup = [] !== $groupPath ? $groupPath[count($groupPath) - 1] : '';

            if (StructuralType::OBJECT === $type && null === $pin) {
                $group = 'group_' . $name;
                if (isset($groups[$group])) {
                    throw new \DomainException("{$component}: two objects named `{$name}` on paragraph type '{$bundle}' would share field_group {$group}.");
                }
                $groups[$group] = [
                    'label' => is_string($field['label'] ?? null) ? $field['label'] : self::humanize($name),
                    'parent' => $parentGroup,
                    'children' => [],
                ];
                self::addChild($groups, $parentGroup, $group);
                $this->level((array) ($field['fields'] ?? []), $bundle, $component, $path, $role, [...$groupPath, $group], $fieldSpecs, $groups, $nested, $ownsText, $stack);
                continue;
            }

            $machine = $pin ?? $this->settings->conventionalFieldName($name, $bundle);
            self::assertMachineName($machine, "{$component}: `{$path}`");
            foreach ($fieldSpecs as $existing) {
                if ($existing->machine === $machine) {
                    throw new \DomainException("{$component}: `{$path}` and `{$existing->path}` both map to Drupal field {$machine} on '{$bundle}'.");
                }
            }

            $targets = null;
            if (in_array($type, [StructuralType::LIST, StructuralType::OBJECT, 'flexible_content'], true)) {
                $targets = $this->nested($field, $type, $bundle, $component, $path, $nested, $stack);
            }
            $fieldSpecs[] = $this->field($field, $type, $bundle, $machine, $component, $path, $targets, $groupPath);
            self::addChild($groups, $parentGroup, $machine);
        }
    }

    /**
     * @param array<mixed> $field
     * @param list<array{bundle: string, label: string, fields: array<mixed>}> $nested
     * @param list<string> $stack
     * @return list<string> the target bundles
     */
    private function nested(array $field, string $type, string $bundle, string $component, string $path, array &$nested, array $stack): array
    {
        if (isset($field['of'])) {
            throw new \DomainException("{$component}: `{$path}` borrows a shape through of:, which has no Drupal paragraph type to generate.");
        }
        if ('flexible_content' === $type) {
            $layouts = is_array($field['layouts'] ?? null) ? $field['layouts'] : [];
            if ([] === $layouts) {
                throw new \DomainException("{$component}: `{$path}` has no layouts.");
            }
            $targets = [];
            foreach ($layouts as $layoutName => $layout) {
                $layoutName = (string) $layoutName;
                $this->guardCycle($layoutName, $stack, $component, $path);
                $targets[] = $layoutName;
                $nested[] = [
                    'bundle' => $layoutName,
                    'label' => is_array($layout) && is_string($layout['label'] ?? null) ? $layout['label'] : self::humanize($layoutName),
                    'fields' => is_array($layout) && is_array($layout['fields'] ?? null) ? $layout['fields'] : [],
                ];
            }
            sort($targets);

            return $targets;
        }

        $pinned = $this->types->expectedTargetBundles($field);
        if (StructuralType::LIST === $type) {
            $targets = $pinned ?? ["{$bundle}_item"];
        } elseif (null !== $pinned) {
            $targets = $pinned;
        } else {
            throw new \DomainException("{$component}: `{$path}` is an object pinned to a Drupal field; name its paragraph type in drupal.target_bundles.");
        }
        if (1 !== count($targets)) {
            throw new \DomainException("{$component}: `{$path}` targets several paragraph types; describe them as a flexible_content with one layout each.");
        }
        $this->guardCycle($targets[0], $stack, $component, $path);
        $nested[] = [
            'bundle' => $targets[0],
            'label' => self::humanize($targets[0]),
            'fields' => true === ($field['open'] ?? false) ? [] : (is_array($field['fields'] ?? null) ? $field['fields'] : []),
        ];

        return $targets;
    }

    /**
     * @param array<mixed> $field
     * @param list<string>|null $nestedTargets
     * @param list<string> $groupPath
     */
    private function field(array $field, string $type, string $bundle, string $machine, string $component, string $path, ?array $nestedTargets, array $groupPath): FieldSpec
    {
        /** @var array<string,mixed> $field */
        $accepted = $this->types->accepted($field);
        if ([] === $accepted) {
            throw new \DomainException("{$component}: `{$path}` has type '{$type}', which has no Drupal storage type.");
        }
        $storagePinned = is_string($field['drupal']['storage'] ?? null);

        $max = is_int($field['max'] ?? null) && $field['max'] > 0 ? $field['max'] : null;
        $many = in_array($type, [StructuralType::LIST, 'flexible_content'], true)
            || true === ($field['multiple'] ?? false)
            || ('media' === $type && 'gallery' === ($field['kind'] ?? null));
        $cardinality = StructuralType::OBJECT === $type ? 1 : ($many ? ($max ?? -1) : 1);

        $targetType = null;
        $targetBundles = null;
        if (null !== $nestedTargets) {
            $targetType = 'paragraph';
            $targetBundles = $nestedTargets;
        } elseif (in_array($type, ['media', 'reference'], true)) {
            $targetType = $this->types->expectedTargetType($field, $storagePinned ? $accepted[0] : 'entity_reference');
            $targetBundles = $this->types->expectedTargetBundles($field);
        }

        $allowed = null;
        if ('select' === $type) {
            $allowed = [];
            foreach ((array) ($field['options'] ?? []) as $value => $optionLabel) {
                $allowed[(string) $value] = is_scalar($optionLabel) ? (string) $optionLabel : (string) $value;
            }
        }

        $shape = $field['shape'] ?? null;

        return new FieldSpec(
            bundle: $bundle,
            machine: $machine,
            path: $path,
            accepted: $accepted,
            storagePinned: $storagePinned,
            label: is_string($field['label'] ?? null) && '' !== trim($field['label']) ? $field['label'] : $machine,
            description: is_string($field['description'] ?? null) ? $field['description'] : '',
            required: true === ($field['required'] ?? false),
            translatable: is_bool($field['translatable'] ?? null) ? $field['translatable'] : null,
            cardinality: $cardinality,
            targetType: $targetType,
            targetBundles: $targetBundles,
            allowedValues: $allowed,
            linkUrlOnly: 'link' === $type ? 'url' === $shape : null,
            mediaKind: 'media' === $type && is_string($field['kind'] ?? null) ? $field['kind'] : ('media' === $type ? 'image' : null),
            widget: is_string($field['drupal']['widget'] ?? null) ? $field['drupal']['widget'] : null,
            formatter: is_string($field['drupal']['formatter'] ?? null) ? $field['drupal']['formatter'] : null,
            groups: $groupPath,
        );
    }

    /**
     * @param array<string,array{label: string, parent: string, children: list<string>}> $groups
     * @param-out array<string,array{label: string, parent: string, children: list<string>}> $groups
     */
    private static function addChild(array &$groups, string $parent, string $child): void
    {
        if (!isset($groups[$parent])) {
            return;
        }
        $group = $groups[$parent];
        $group['children'][] = $child;
        $groups[$parent] = $group;
    }

    /** @param list<string> $stack */
    private function guardCycle(string $target, array $stack, string $component, string $path): void
    {
        if (in_array($target, $stack, true)) {
            throw new \DomainException("{$component}: `{$path}` nests paragraph type '{$target}' inside itself.");
        }
    }

    private static function assertMachineName(string $name, string $where): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name) || strlen($name) > 32) {
            throw new \DomainException("{$where}: '{$name}' is not a Drupal machine name (a-z, 0-9, _, at most 32 characters).");
        }
    }
}
