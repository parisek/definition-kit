<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalField;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalTypeMap;

/**
 * Drupal paragraph config -> the `fields:` map of a definition. The Drupal
 * counterpart of AcfJsonReader: the deployed CMS schema is the truth a
 * definition is bootstrapped from, and `fields-lint-drupal` compares the two
 * from then on.
 *
 * The output is built so that `fields-lint-drupal` reports it clean against
 * the same config. Every rule below has its inverse in DrupalDriftLinter.
 *
 * Field names:
 *   1. Display evidence ({@see DisplayEvidence}), when given: the template
 *      prop path the PHP display code writes the field to
 *      (`heading.title`, `button`, `items.name`).
 *   2. Otherwise a form-display field_group: `group_heading` holding
 *      `field_title` becomes `heading: {type: object, fields: {title: …}}`.
 *   3. Otherwise the leaf name ({@see DrupalSettings::leafName()}).
 *   A `drupal.field` pin is written wherever the leaf does not reach the
 *   field by convention (`button` -> `field_link`).
 *
 * Fields in `drupal.ignore_fields` are left out. Labels and descriptions come
 * from the field instance.
 *
 * Types (see DrupalTypeMap for the table): a Drupal storage type other than
 * the canonical one for the chosen kit type is pinned in `drupal.storage`.
 * entity_reference_revisions becomes a `list` (several values), an `object`
 * pinned by `drupal.field` (one value) or a `flexible_content` (several target
 * bundles, one layout per bundle). Media and other references pin
 * `drupal.target_bundles` unless `of:` already says it.
 *
 * @phpstan-import-type Entry from DisplayEvidence
 */
final class DrupalParagraphReader
{
    public function __construct(
        private readonly DrupalConfig $config,
        private readonly DrupalSettings $settings = new DrupalSettings(),
        private readonly ?DisplayEvidence $evidence = null,
        private readonly DrupalTypeMap $types = new DrupalTypeMap(),
    ) {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function read(string $bundle): array
    {
        if (!$this->config->hasBundle($bundle)) {
            throw new \DomainException("Paragraph type '{$bundle}' does not exist in {$this->config->directory()}.");
        }

        return $this->readBundle($bundle, null !== $this->evidence ? $this->evidence->forBundle($bundle) : [], [$bundle]);
    }

    /**
     * Reads several aliased bundles (`drupal.bundle_aliases` mapping more
     * than one paragraph type onto the same component) and merges them into
     * one `fields:` map. A top-level field present on every bundle is
     * written once, unscoped, same as {@see read()}. A top-level field
     * present on only SOME of the bundles gets `drupal.bundles` naming
     * exactly that subset, so a later `fields-lint-drupal` and
     * `fields-generate --target=drupal --dry-run` know it never belonged on
     * the others (#gap-3: previously unresolvable, reported as DRIFT on
     * whichever bundle lacked it).
     *
     * When two bundles disagree on the SHAPE of a same-named field (a
     * different type, say), the first bundle's shape wins and the
     * disagreement is left for a human to resolve by hand — this reader
     * only merges field PRESENCE, never reconciles conflicting shapes.
     *
     * @param non-empty-list<string> $bundles primary bundle first
     * @return array<string,array<string,mixed>>
     */
    public function readMerged(array $bundles): array
    {
        if (1 === count($bundles)) {
            return $this->read($bundles[0]);
        }

        /** @var array<string,array<string,mixed>> $byBundle */
        $byBundle = [];
        foreach ($bundles as $bundle) {
            if (!$this->config->hasBundle($bundle)) {
                throw new \DomainException("Paragraph type '{$bundle}' does not exist in {$this->config->directory()}.");
            }
            $byBundle[$bundle] = $this->readBundle($bundle, null !== $this->evidence ? $this->evidence->forBundle($bundle) : [], [$bundle]);
        }

        $merged = [];
        foreach ($byBundle as $bundle => $fields) {
            foreach ($fields as $name => $field) {
                if (isset($merged[$name])) {
                    continue;
                }
                $presentOn = array_keys(array_filter($byBundle, static fn (array $f): bool => array_key_exists($name, $f)));
                if (count($presentOn) < count($bundles)) {
                    $field['drupal'] = ['bundles' => $presentOn] + ($field['drupal'] ?? []);
                    $field = $this->orderKeys($field);
                }
                $merged[$name] = $field;
            }
        }

        return $merged;
    }

    /**
     * @param list<array{path: list<string>, field: string, children?: list<array{path: list<string>, field: string}>}> $evidence
     * @param list<string> $stack
     * @return array<string,array<string,mixed>>
     */
    private function readBundle(string $bundle, array $evidence, array $stack): array
    {
        $byField = [];
        foreach ($evidence as $entry) {
            $machine = 'field_' . $entry['field'];
            $byField[$machine] ??= $entry;
        }
        $groupOf = [];
        $groupLabels = [];
        foreach ($this->config->groups($bundle) as $groupName => $group) {
            $groupLeaf = str_starts_with($groupName, 'group_') ? substr($groupName, 6) : $groupName;
            $groupLabels[$groupLeaf] = $group['label'];
            foreach ($group['children'] as $child) {
                $groupOf[$child] = $groupLeaf;
            }
        }

        $tree = [];
        foreach ($this->config->fields($bundle) as $machine => $drupalField) {
            if ($this->settings->ignores($machine)) {
                continue;
            }
            $leaf = $this->settings->leafName($machine, $bundle);
            $entry = $byField[$machine] ?? null;
            if (null !== $entry && [] !== $entry['path']) {
                $path = $entry['path'];
            } elseif (isset($groupOf[$machine])) {
                $path = [$groupOf[$machine], $leaf];
            } else {
                $path = [$leaf];
            }

            $field = $this->mapField($drupalField, $bundle, $entry['children'] ?? [], $stack);
            // Every field read off a real Drupal bundle field is, by
            // construction, editor-authored content — `role: field`, the
            // same value DrupalDriftLinter already assumes for a field with
            // no `role:` key (see the class doc header). Written explicitly
            // regardless of `--assume-role` (that flag is for a genuinely
            // ambiguous twig-only field with no acf.json AND no Drupal
            // bundle field behind it — AcfJsonReader/TwigFieldTypeMapper's
            // territory, never this reader's) so ContractLinter's `isset`
            // gate (src/Contract/ContractLinter.php::fieldsWithoutARole())
            // sees a component this reader produced as typed instead of
            // reporting it UNTYPED for a role that was always implicit.
            $field['role'] = 'field';
            $last = $path[count($path) - 1];
            $isErrObject = 'object' === $field['type'];
            if ($isErrObject || $this->settings->conventionalFieldName($last, $bundle) !== $machine) {
                $field['drupal'] = ['field' => $machine] + ($field['drupal'] ?? []);
            }
            $field = $this->orderKeys($field);

            if (!$this->insert($tree, $path, $field, $groupLabels)) {
                // The evidence path collides with another field: fall back to the leaf name.
                if (!isset($tree[$leaf])) {
                    if (!$isErrObject && $this->settings->conventionalFieldName($leaf, $bundle) === $machine) {
                        unset($field['drupal']['field']);
                        if ([] === $field['drupal']) {
                            unset($field['drupal']);
                        }
                    }
                    $tree[$leaf] = $field;
                } else {
                    throw new \DomainException("Cannot place Drupal field {$machine} of '{$bundle}': both `" . implode('.', $path) . "` and `{$leaf}` are taken.");
                }
            }
        }

        return $tree;
    }

    /**
     * @param array<string,mixed> $tree
     * @param non-empty-list<string> $path
     * @param array<string,mixed> $field
     * @param array<string,string> $labels group leaf => label
     */
    private function insert(array &$tree, array $path, array $field, array $labels): bool
    {
        $head = $path[0];
        $path = array_slice($path, 1);
        if ([] === $path) {
            if (isset($tree[$head])) {
                return false;
            }
            $tree[$head] = $field;

            return true;
        }
        if (isset($tree[$head]) && !$this->isGroupObject($tree[$head])) {
            return false;
        }
        if (!isset($tree[$head])) {
            $tree[$head] = [
                'type' => 'object',
                'role' => 'field',
                'label' => $labels[$head] ?? ucfirst(str_replace('_', ' ', $head)),
                'fields' => [],
            ];
        }
        $children = $tree[$head]['fields'];
        if (!$this->insert($children, $path, $field, $labels)) {
            return false;
        }
        $tree[$head]['fields'] = $children;

        return true;
    }

    private function isGroupObject(mixed $node): bool
    {
        return is_array($node) && 'object' === ($node['type'] ?? null) && !isset($node['drupal']['field']);
    }

    /**
     * @param list<array{path: list<string>, field: string}> $childEvidence
     * @param list<string> $stack
     * @return array<string,mixed>
     */
    private function mapField(DrupalField $d, string $bundle, array $childEvidence, array $stack): array
    {
        $field = match ($d->type) {
            'string' => ['type' => 'text'],
            'string_long' => ['type' => 'text', 'multiline' => true],
            'text_long', 'text', 'text_with_summary' => ['type' => 'richtext'],
            'email', 'telephone' => ['type' => 'text'],
            'integer', 'decimal', 'float' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'list_string', 'list_integer', 'list_float' => $this->select($d),
            'link' => ['type' => 'link', 'shape' => 0 === (int) ($d->settings['title'] ?? 1) ? 'url' : 'link'],
            'datetime', 'daterange', 'timestamp' => ['type' => 'date'],
            'image' => ['type' => 'media', 'kind' => 'image'],
            'file' => ['type' => 'media', 'kind' => 'file'],
            'entity_reference' => $this->reference($d),
            'entity_reference_revisions' => $this->nested($d, $bundle, $childEvidence, $stack),
            default => throw new \DomainException(sprintf(
                "Drupal field %s on '%s' has storage type '%s', which the kit has no type for.",
                $d->name,
                $bundle,
                $d->type,
            )),
        };

        $field['label'] = '' !== trim($d->label) ? $d->label : $d->name;
        if ('' !== trim($d->description)) {
            $field['description'] = $d->description;
        }
        if ($d->required) {
            $field['required'] = true;
        }

        if (!in_array($field['type'], ['list', 'object', 'flexible_content'], true) && $d->isMultiple()) {
            if ('media' === $field['type'] && 'image' === ($field['kind'] ?? null)) {
                $field['kind'] = 'gallery';
            }
            $field['multiple'] = true;
        }
        if ($d->cardinality > 1 && 'object' !== $field['type']) {
            $field['max'] = $d->cardinality;
        }

        $canonical = $this->types->canonical($field);
        if ($canonical !== $d->type && !('media' === $field['type'] && 'entity_reference' === $d->type)) {
            $field['drupal'] = ['storage' => $d->type] + ($field['drupal'] ?? []);
        }

        return $field;
    }

    /** @return array<string,mixed> */
    private function select(DrupalField $d): array
    {
        $options = $d->allowedValues();
        if ([] === $options) {
            throw new \DomainException(sprintf(
                "Drupal field %s on '%s' is a %s with no allowed values in the export (allowed_values_function?) — the kit's select needs options.",
                $d->name,
                $d->bundle,
                $d->type,
            ));
        }
        if (array_is_list($options)) {
            throw new \DomainException(sprintf(
                "Drupal field %s on '%s' has allowed values keyed 0, 1, 2, … — a definition cannot keep them as a map.",
                $d->name,
                $d->bundle,
            ));
        }

        return ['type' => 'select', 'options' => $options];
    }

    /** @return array<string,mixed> */
    private function reference(DrupalField $d): array
    {
        $targetType = $d->targetType();
        $bundles = $d->targetBundles();

        if ('media' === $targetType) {
            $allImages = [] !== $bundles && [] === array_filter($bundles, static fn (string $b): bool => !str_contains($b, 'image'));
            $field = ['type' => 'media', 'kind' => $allImages ? 'image' : 'file'];
            if ([] !== $bundles) {
                $field['drupal'] = ['target_bundles' => $bundles];
            }

            return $field;
        }
        if ('taxonomy_term' === $targetType && 1 === count($bundles)) {
            return ['type' => 'reference', 'of' => 'term:' . $bundles[0]];
        }
        if ('node' === $targetType && [] !== $bundles) {
            return ['type' => 'reference', 'of' => implode(',', array_map(static fn (string $b): string => "post:{$b}", $bundles))];
        }

        // A generic Drupal entity reference — a config entity such as
        // `webform`, or any content entity outside the post/taxonomy/media
        // vocabulary above. `entity:<type>[:<bundle>,…]` carries the target
        // type AND the bundle restriction (or its absence, meaning "any") in
        // one neutral `of:`; see ADR 0009.
        $of = 'entity:' . ($targetType ?? 'node');
        if ([] !== $bundles) {
            $of .= ':' . implode(',', $bundles);
        }

        return ['type' => 'reference', 'of' => $of];
    }

    /**
     * @param list<array{path: list<string>, field: string}> $childEvidence
     * @param list<string> $stack
     * @return array<string,mixed>
     */
    private function nested(DrupalField $d, string $bundle, array $childEvidence, array $stack): array
    {
        $targets = $d->targetBundles();
        if ([] === $targets) {
            throw new \DomainException(sprintf(
                "Drupal field %s on '%s' may hold any paragraph type — a definition cannot describe that; restrict target_bundles first.",
                $d->name,
                $bundle,
            ));
        }
        foreach ($targets as $target) {
            if (in_array($target, $stack, true)) {
                throw new \DomainException("Paragraph type '{$target}' nests itself through {$d->name} on '{$bundle}'.");
            }
            if (!$this->config->hasBundle($target)) {
                throw new \DomainException("Drupal field {$d->name} on '{$bundle}' targets paragraph type '{$target}', which is not in the export.");
            }
        }

        if (count($targets) > 1) {
            $layouts = [];
            foreach ($targets as $target) {
                $fields = $this->readBundle($target, [], [...$stack, $target]);
                if ([] === $fields) {
                    throw new \DomainException("Paragraph type '{$target}' (a target of {$d->name} on '{$bundle}') has no fields to describe as a layout.");
                }
                $layouts[$target] = ['label' => $this->config->bundleLabel($target), 'fields' => $fields];
            }

            return ['type' => 'flexible_content', 'layouts' => $layouts];
        }

        $target = $targets[0];
        $children = $this->readBundle($target, $childEvidence, [...$stack, $target]);
        $single = !$d->isMultiple();
        $field = ['type' => $single ? 'object' : 'list'];
        if ([] === $children) {
            $field['open'] = true;
        } else {
            $field['fields'] = $children;
        }
        if ($single || "{$bundle}_item" !== $target) {
            $field['drupal'] = ['target_bundles' => [$target]];
        }

        return $field;
    }

    /**
     * Stable, readable key order: what the field is, then what it holds, then
     * the Drupal escape hatch.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function orderKeys(array $field): array
    {
        $order = ['type', 'role', 'label', 'description', 'required', 'multiline', 'kind', 'shape', 'of', 'multiple', 'max', 'options', 'open', 'fields', 'layouts', 'drupal'];
        $out = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $field)) {
                $out[$key] = $field[$key];
            }
        }
        if (isset($out['drupal']) && is_array($out['drupal'])) {
            $drupalOrder = ['field', 'storage', 'target_type', 'target_bundles', 'bundles'];
            $out['drupal'] = array_merge(array_intersect_key(array_flip($drupalOrder), $out['drupal']), $out['drupal']);
        }

        return $out + $field;
    }
}
