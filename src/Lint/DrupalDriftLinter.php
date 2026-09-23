<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Drupal\BundleResolver;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalField;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalTypeMap;
use Parisek\DefinitionKit\Support\StructuralType;

/**
 * Compares a definition against the Drupal paragraph bundle(s) it describes.
 *
 * Unlike {@see DriftLinter}, nothing is generated here: the Drupal config is
 * hand-built in the Drupal admin and exported, and the definition is compared
 * with it field by field. Compared, and only this:
 *
 *   - field set: a definition field with no Drupal field, a Drupal field with
 *     no definition field (minus `drupal.ignore_fields`);
 *   - storage type, through {@see DrupalTypeMap};
 *   - cardinality: one value or several, and a fixed limit against `max:`;
 *   - the required flag;
 *   - reference targets: target entity type and target bundles;
 *   - nested paragraph bundles: a `list` recurses into the bundle its
 *     entity_reference_revisions field targets, a `flexible_content` into one
 *     bundle per layout.
 *
 * Not compared: labels, descriptions, translatability, widget and formatter
 * settings, weights, field_group layout, uuid/_core/dependencies.
 *
 * Mapping from a definition field to a Drupal field:
 *
 *   - Only `role: field` (the default, inherited by descendants) maps to a
 *     Drupal field. Every other role is runtime data the editor never enters.
 *   - The Drupal field is `drupal.field` when pinned, otherwise
 *     {@see DrupalSettings::conventionalFieldName()} of the leaf name.
 *   - An `object` without a `drupal.field` pin is a field_group: its children
 *     are fields of the same bundle, named by their own leaf (`heading.title`
 *     -> `field_title`). An `object` pinned to an entity_reference_revisions
 *     field is a single nested paragraph.
 *   - A `list` expects the child bundle `<bundle>_item` unless
 *     `drupal.target_bundles` pins another.
 */
final class DrupalDriftLinter
{
    /** @var array<string,true> bundle => compared by some lint() call */
    private array $claimed = [];

    public function __construct(
        private readonly DrupalConfig $config,
        private readonly DrupalSettings $settings = new DrupalSettings(),
        private readonly DrupalTypeMap $types = new DrupalTypeMap(),
    ) {
    }

    /** @param array<mixed> $definition */
    public function lint(array $definition, string $componentSlug): DrupalDriftResult
    {
        $definition = StructuralType::normalize($definition);
        $link = is_string($definition['drupal'] ?? null) ? $definition['drupal'] : null;
        $bundles = (new BundleResolver($this->settings))->resolve($componentSlug, $link, $this->config);

        if ([] === $bundles) {
            $reason = null !== $link
                ? 'the drupal: link names no paragraph type, and no bundle '
                    . BundleResolver::conventionalBundle($componentSlug) . ' exists'
                : 'no paragraph bundle: no drupal: link, no bundle '
                    . BundleResolver::conventionalBundle($componentSlug) . ', no bundle_aliases entry';

            return DrupalDriftResult::skip($componentSlug, $reason);
        }

        $findings = [];
        foreach ($bundles as $bundle => $source) {
            $this->claimed[$bundle] = true;
            if (!$this->config->hasBundle($bundle)) {
                $findings[] = sprintf(
                    '%s: paragraph type does not exist in the config export (named by the %s)',
                    $bundle,
                    BundleResolver::SOURCE_LINK === $source ? 'drupal: link' : 'bundle_aliases setting',
                );
                continue;
            }
            $this->lintBundle((array) ($definition['fields'] ?? []), $bundle, '', $findings, [$bundle]);
        }

        return DrupalDriftResult::compared($componentSlug, array_keys($bundles), $findings);
    }

    /**
     * Bundles in the config export that no lint() call compared, that no
     * `bundle_aliases` entry leads to a linted component, and that
     * `bundles_without_component` does not list. On a site where an unknown
     * bundle renders a fallback ("implementation missing") component, each one
     * is a paragraph type an editor can add and no template describes.
     *
     * @return list<string>
     */
    public function unclaimedBundles(): array
    {
        return array_values(array_filter(
            $this->config->bundles(),
            fn (string $bundle): bool => !isset($this->claimed[$bundle])
                && !in_array($bundle, $this->settings->bundlesWithoutComponent, true),
        ));
    }

    /**
     * @param array<mixed> $fields
     * @param list<string> $findings
     * @param list<string> $stack bundles on the current nesting path, for cycle detection
     */
    private function lintBundle(array $fields, string $bundle, string $prefix, array &$findings, array $stack): void
    {
        $drupalFields = $this->config->fields($bundle);
        /** @var array<string,string> $claimed Drupal field => definition path */
        $claimed = [];
        $this->lintLevel($fields, $bundle, $prefix, $drupalFields, $claimed, $findings, $stack, 'field');

        foreach ($drupalFields as $name => $drupalField) {
            if (isset($claimed[$name]) || $this->settings->ignores($name)) {
                continue;
            }
            $findings[] = sprintf(
                '%s: %s (%s): Drupal field has no definition field%s',
                $bundle,
                $name,
                $drupalField->type,
                '' !== $prefix ? " under `{$prefix}`" : '',
            );
        }
    }

    /**
     * @param array<mixed> $fields
     * @param array<string,DrupalField> $drupalFields
     * @param array<string,string> $claimed
     * @param list<string> $findings
     * @param list<string> $stack
     */
    private function lintLevel(
        array $fields,
        string $bundle,
        string $prefix,
        array $drupalFields,
        array &$claimed,
        array &$findings,
        array $stack,
        string $inheritedRole,
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
            $type = StructuralType::canonical((string) ($field['type'] ?? ''));
            $pin = is_string($field['drupal']['field'] ?? null) ? $field['drupal']['field'] : null;

            if (StructuralType::OBJECT === $type && null === $pin) {
                // A field_group: the children live on this same bundle.
                $this->lintLevel((array) ($field['fields'] ?? []), $bundle, $path, $drupalFields, $claimed, $findings, $stack, $role);
                continue;
            }

            $machine = $pin ?? $this->settings->conventionalFieldName($name, $bundle);
            if (!isset($drupalFields[$machine])) {
                $findings[] = sprintf(
                    '%s: %s: no Drupal field %s%s',
                    $bundle,
                    $path,
                    $machine,
                    null === $pin ? ' (by convention; pin another with drupal.field)' : ' (pinned by drupal.field)',
                );
                continue;
            }
            if (isset($claimed[$machine])) {
                $findings[] = sprintf('%s: %s: Drupal field %s is already mapped to `%s`', $bundle, $path, $machine, $claimed[$machine]);
                continue;
            }
            $claimed[$machine] = $path;
            $this->compareField($field, $type, $drupalFields[$machine], $bundle, $path, $findings, $stack);
        }
    }

    /**
     * @param array<mixed> $field
     * @param list<string> $findings
     * @param list<string> $stack
     */
    private function compareField(
        array $field,
        string $type,
        DrupalField $drupal,
        string $bundle,
        string $path,
        array &$findings,
        array $stack,
    ): void {
        $at = "{$bundle}: {$path} ({$drupal->name})";

        /** @var array<string,mixed> $field */
        $accepted = $this->types->accepted($field);
        $typeMatches = in_array($drupal->type, $accepted, true);
        if (!$typeMatches) {
            $findings[] = sprintf(
                '%s: storage type %s; the definition type %s expects %s',
                $at,
                $drupal->type,
                $this->types->key($field),
                [] === $accepted ? 'no Drupal storage' : implode('|', $accepted),
            );
        }

        $required = true === ($field['required'] ?? false);
        if ($required !== $drupal->required) {
            $findings[] = sprintf(
                '%s: required is %s in Drupal, %s in the definition',
                $at,
                $drupal->required ? 'on' : 'off',
                $required ? 'on' : 'off',
            );
        }

        if (!$typeMatches) {
            // Cardinality and targets of a different storage type only repeat the finding above.
            return;
        }

        $this->compareCardinality($field, $type, $drupal, $at, $findings);

        $expectedTargetType = $this->types->expectedTargetType($field, $drupal->type);
        $actualTargetType = $drupal->targetType();
        if (null !== $expectedTargetType && null !== $actualTargetType && $expectedTargetType !== $actualTargetType) {
            $findings[] = sprintf('%s: references %s entities; the definition expects %s', $at, $actualTargetType, $expectedTargetType);

            return;
        }

        if ('entity_reference_revisions' === $drupal->type) {
            $this->compareNested($field, $type, $drupal, $bundle, $path, $at, $findings, $stack);

            return;
        }

        $expectedBundles = $this->types->expectedTargetBundles($field);
        if (null !== $expectedBundles && $expectedBundles !== $drupal->targetBundles()) {
            $findings[] = sprintf(
                '%s: targets bundle(s) %s; the definition expects %s',
                $at,
                $this->bundleList($drupal->targetBundles()),
                $this->bundleList($expectedBundles),
            );
        }
    }

    /**
     * @param array<mixed> $field
     * @param list<string> $findings
     */
    private function compareCardinality(array $field, string $type, DrupalField $drupal, string $at, array &$findings): void
    {
        $expectsMany = in_array($type, [StructuralType::LIST, 'flexible_content'], true)
            || true === ($field['multiple'] ?? false)
            || ('media' === $type && 'gallery' === ($field['kind'] ?? null));
        $limit = DrupalField::UNLIMITED === $drupal->cardinality ? 'unlimited' : (string) $drupal->cardinality;

        if ($expectsMany && !$drupal->isMultiple()) {
            $findings[] = "{$at}: single-value in Drupal (cardinality 1); the definition expects several values";

            return;
        }
        if (!$expectsMany && $drupal->isMultiple()) {
            $findings[] = "{$at}: multi-value in Drupal (cardinality {$limit}); the definition expects one value";

            return;
        }
        if (!$expectsMany) {
            return;
        }
        $max = is_int($field['max'] ?? null) ? $field['max'] : null;
        if ($drupal->cardinality > 1 && $max !== $drupal->cardinality) {
            $findings[] = sprintf(
                '%s: Drupal allows at most %d values; the definition sets %s',
                $at,
                $drupal->cardinality,
                null === $max ? 'no max' : "max: {$max}",
            );
        } elseif (DrupalField::UNLIMITED === $drupal->cardinality && null !== $max && $max > 0) {
            $findings[] = "{$at}: Drupal allows unlimited values; the definition sets max: {$max}";
        }
    }

    /**
     * @param array<mixed> $field
     * @param list<string> $findings
     * @param list<string> $stack
     */
    private function compareNested(
        array $field,
        string $type,
        DrupalField $drupal,
        string $bundle,
        string $path,
        string $at,
        array &$findings,
        array $stack,
    ): void {
        $targets = $drupal->targetBundles();

        if ('flexible_content' === $type) {
            $layouts = array_map('strval', array_keys((array) ($field['layouts'] ?? [])));
            sort($layouts);
            if ($layouts !== $targets) {
                $findings[] = sprintf(
                    '%s: targets paragraph type(s) %s; the definition has layouts %s',
                    $at,
                    $this->bundleList($targets),
                    $this->bundleList($layouts),
                );
            }
            foreach ((array) ($field['layouts'] ?? []) as $layoutName => $layout) {
                $layoutName = (string) $layoutName;
                if (in_array($layoutName, $targets, true) && is_array($layout)) {
                    $this->descend((array) ($layout['fields'] ?? []), $layoutName, "{$path}.{$layoutName}", $findings, $stack);
                }
            }

            return;
        }

        $pinned = $this->types->expectedTargetBundles($field);
        $expected = $pinned ?? (StructuralType::LIST === $type ? ["{$bundle}_item"] : null);
        if (null !== $expected && $expected !== $targets) {
            $findings[] = sprintf(
                '%s: targets paragraph type(s) %s; the definition expects %s%s',
                $at,
                $this->bundleList($targets),
                $this->bundleList($expected),
                null === $pinned ? ' (by convention; pin another with drupal.target_bundles)' : '',
            );
        }

        if (true === ($field['open'] ?? false)) {
            return;
        }
        $nested = 1 === count($targets) ? $targets[0] : (null !== $expected && 1 === count($expected) ? $expected[0] : null);
        if (null === $nested) {
            $findings[] = "{$at}: cannot tell which paragraph type the nested fields describe; pin drupal.target_bundles";

            return;
        }
        $this->descend((array) ($field['fields'] ?? []), $nested, $path, $findings, $stack);
    }

    /**
     * @param array<mixed> $fields
     * @param list<string> $findings
     * @param list<string> $stack
     */
    private function descend(array $fields, string $nested, string $path, array &$findings, array $stack): void
    {
        $this->claimed[$nested] = true;
        if (!$this->config->hasBundle($nested)) {
            $findings[] = "{$nested}: {$path}: nested paragraph type does not exist in the config export";

            return;
        }
        if (in_array($nested, $stack, true)) {
            // A bundle that nests itself: compared once, on the way in.
            return;
        }
        $this->lintBundle($fields, $nested, $path, $findings, [...$stack, $nested]);
    }

    /** @param list<string> $bundles */
    private function bundleList(array $bundles): string
    {
        return [] === $bundles ? '(any)' : implode(', ', $bundles);
    }
}
