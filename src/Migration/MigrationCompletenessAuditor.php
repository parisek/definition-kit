<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Baseline\TypeDefaults;

/**
 * Proves the migration is lossless in the authored-semantic-layer sense
 * (ADR 0006): every raw ACF field property is accounted for by exactly one
 * of — (a) equal to the type-defaults baseline, (b) semantically lifted AND
 * genuinely reconstructible from the migrated field's emitted output (this
 * is verified, not assumed — see the per-prop checks below), (c) present
 * verbatim under the migrated field's `wp:`, or (d) the field is an
 * accordion (documented drop). Anything else is a silent-data-loss bug.
 *
 * "Reconstructible" means: applying the inverse of the lift to the emitted
 * output reproduces the raw ACF value. This intentionally does NOT check
 * whether dávka 3 could reconstruct byte-identical acf.json — see Task 9's
 * docblock in the plan for why that's out of scope here.
 */
final class MigrationCompletenessAuditor
{
    /**
     * Structural identifiers/bookkeeping — no reconstructible data of their
     * own (either pure identity, or verified separately via recursion).
     */
    private const STRUCTURAL_PROPS = ['name', 'key', 'sub_fields', 'parent_repeater'];

    private const NUMBER_CONSTRAINT_PROPS = ['min', 'max', 'step'];
    private const REPEATER_BOUND_PROPS = ['min', 'max'];
    private const DIMENSION_PROPS = ['min_width', 'max_width', 'min_height', 'max_height'];

    public function __construct(
        private readonly TypeDefaults $typeDefaults = new TypeDefaults(),
        private readonly AbstractTypeMapper $typeMapper = new AbstractTypeMapper(),
        private readonly WpmlTranslatableMapper $wpmlMapper = new WpmlTranslatableMapper(),
        private readonly \Parisek\DefinitionKit\Generator\FieldReconstructor $fieldReconstructor
            = new \Parisek\DefinitionKit\Generator\FieldReconstructor(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $acfFields
     * @param array<string, array<string,mixed>> $definitionFields
     * @return list<string>
     */
    public function audit(array $acfFields, array $definitionFields, string $pathPrefix = ''): array
    {
        $keyNameMap = [];
        $this->buildKeyNameMap($acfFields, $keyNameMap);

        return $this->auditFields($acfFields, $definitionFields, $pathPrefix, $keyNameMap);
    }

    /**
     * @param list<array<string,mixed>> $acfFields
     * @param array<string, array<string,mixed>> $definitionFields
     * @param array<string,string> $keyNameMap
     * @return list<string>
     */
    private function auditFields(array $acfFields, array $definitionFields, string $pathPrefix, array $keyNameMap): array
    {
        $violations = [];
        $nameKeyMap = array_flip($keyNameMap);

        foreach ($acfFields as $acfField) {
            $type = (string) ($acfField['type'] ?? '');
            $name = (string) ($acfField['name'] ?? '');
            $path = '' === $pathPrefix ? $name : "{$pathPrefix}.{$name}";

            if ('accordion' === $type) {
                continue;
            }

            if (!array_key_exists($name, $definitionFields)) {
                $violations[] = "{$path}: field missing from migrated definition entirely";
                continue;
            }

            $defField = $definitionFields[$name];
            $wp = (array) ($defField['wp'] ?? []);

            $accounted = self::STRUCTURAL_PROPS;

            // --- type + type-specific extras + ambiguous-type wp marker —
            // "does the migrated definition's abstract vocabulary correctly
            // describe this raw field", the forward direction, unchanged.
            $mapped = $this->typeMapper->map($acfField);
            foreach ($mapped['consumed'] as $prop) {
                $accounted[] = $prop;
            }
            if (($defField['type'] ?? null) !== $mapped['type']) {
                $violations[] = sprintf(
                    "%s.type: raw type '%s' migrates to '%s' but migrated field has type '%s' — not reconstructible",
                    $path,
                    $type,
                    $mapped['type'],
                    (string) ($defField['type'] ?? ''),
                );
            }
            foreach ($mapped['extra'] as $extraKey => $extraVal) {
                if (!$this->looseEquals($defField[$extraKey] ?? null, $extraVal)) {
                    $violations[] = sprintf(
                        '%s.%s: expected %s (derived from raw type/props) but migrated field has %s — not reconstructible',
                        $path,
                        $extraKey,
                        json_encode($extraVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        json_encode($defField[$extraKey] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    );
                }
            }
            foreach (($mapped['wp'] ?? []) as $wpKey => $wpVal) {
                if (!$this->looseEquals($wp[$wpKey] ?? null, $wpVal)) {
                    $violations[] = sprintf(
                        "%s.%s: raw type '%s' is ambiguous with a sibling ACF type and needs wp.%s=%s to stay "
                        . 'reconstructible — missing/mismatched in migrated field',
                        $path,
                        $wpKey,
                        $type,
                        $wpKey,
                        json_encode($wpVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    );
                }
            }

            // --- everything reconstructible via FieldReconstructor — the
            // SAME per-prop value-lift logic Generator\FieldsGenerator uses
            // to build acf.json from scratch (Task 7). A bug here breaks
            // the audit and the generator identically.
            $reconstructed = $this->fieldReconstructor->reconstruct($defField, $nameKeyMap);

            $rawRequired = $acfField['required'] ?? null;
            if (0 === $rawRequired || 1 === $rawRequired) {
                $accounted[] = 'required';
                $expected = 1 === $rawRequired;
                $actual = 1 === $reconstructed['required'];
                if ($expected !== $actual) {
                    $violations[] = sprintf(
                        '%s.required: raw required=%s not reconstructible from migrated field.required=%s',
                        $path,
                        json_encode($rawRequired),
                        json_encode($defField['required'] ?? null),
                    );
                }
            }

            $accounted[] = 'instructions';
            $rawInstructions = (string) ($acfField['instructions'] ?? '');
            if ('' !== $rawInstructions && $reconstructed['instructions'] !== $rawInstructions) {
                $violations[] = "{$path}.instructions: not reconstructible from migrated field.description";
            }

            $accounted[] = 'label';
            $rawLabel = (string) ($acfField['label'] ?? '');
            if ('' !== $rawLabel && $reconstructed['label'] !== $rawLabel) {
                $violations[] = "{$path}.label: not reconstructible from migrated field.label";
            }

            $rawWpml = $acfField['wpml_cf_preferences'] ?? null;
            // flexible_content is excluded — see FieldReconstructor::NO_AUTO_WPML_TYPES
            // and AcfJsonReader's own wpml exclusion for the corpus rationale;
            // whatever value is present survives via the generic "everything
            // else" leftover/wp: check at the bottom of this method instead.
            if ('flexible_content' !== $type && is_int($rawWpml) && $this->wpmlMapper->isCanonical($type, $rawWpml)) {
                $accounted[] = 'wpml_cf_preferences';
                if ($reconstructed['wpml_cf_preferences'] !== $rawWpml) {
                    $violations[] = sprintf(
                        '%s.wpml_cf_preferences: raw value %d not reconstructible from migrated field.translatable=%s',
                        $path,
                        $rawWpml,
                        json_encode($defField['translatable'] ?? null),
                    );
                }
            }

            $accounted[] = 'maxlength';
            $rawMaxlength = $acfField['maxlength'] ?? null;
            if (!empty($rawMaxlength) && ($reconstructed['maxlength'] ?? null) !== (int) $rawMaxlength) {
                $violations[] = "{$path}.maxlength: not reconstructible from migrated field.maxlength";
            }

            if ('number' === $type) {
                foreach (self::NUMBER_CONSTRAINT_PROPS as $prop) {
                    $accounted[] = $prop;
                    if (isset($acfField[$prop]) && '' !== $acfField[$prop]
                        && ($reconstructed[$prop] ?? null) !== ($acfField[$prop] + 0)
                    ) {
                        $violations[] = "{$path}.{$prop}: not reconstructible from migrated field.{$prop}";
                    }
                }
            }

            if (in_array($type, ['repeater', 'flexible_content'], true)) {
                foreach (self::REPEATER_BOUND_PROPS as $prop) {
                    $accounted[] = $prop;
                    $raw = $acfField[$prop] ?? '';
                    if ('' !== $raw && 0 !== $raw && '0' !== $raw
                        && ($reconstructed[$prop] ?? null) !== ($raw + 0)
                    ) {
                        $violations[] = "{$path}.{$prop}: not reconstructible from migrated field.{$prop}";
                    }
                }
            }

            $accounted[] = 'mime_types';
            $rawMime = $acfField['mime_types'] ?? null;
            if (!empty($rawMime)) {
                $expectedAccept = array_values(array_filter(array_map('trim', explode(',', (string) $rawMime))));
                $actualMime = isset($reconstructed['mime_types'])
                    ? array_values(array_filter(array_map('trim', explode(',', $reconstructed['mime_types']))))
                    : null;
                if ($actualMime !== $expectedAccept) {
                    $violations[] = "{$path}.mime_types: not reconstructible from migrated field.accept";
                }
            }

            $accounted[] = 'max_size';
            $rawMaxSize = $acfField['max_size'] ?? null;
            if (!empty($rawMaxSize) && ($reconstructed['max_size'] ?? null) !== ($rawMaxSize + 0)) {
                $violations[] = "{$path}.max_size: not reconstructible from migrated field.max_size";
            }

            foreach (self::DIMENSION_PROPS as $dim) {
                $accounted[] = $dim;
                $rawDim = $acfField[$dim] ?? null;
                if (!empty($rawDim) && ($reconstructed[$dim] ?? null) !== ($rawDim + 0)) {
                    $violations[] = "{$path}.{$dim}: not reconstructible from migrated field.{$dim}";
                }
            }

            $accounted[] = 'placeholder';
            if (isset($acfField['placeholder']) && '' !== $acfField['placeholder']
                && ($reconstructed['placeholder'] ?? null) !== (string) $acfField['placeholder']
            ) {
                $violations[] = "{$path}.placeholder: not reconstructible from migrated field.placeholder";
            }

            // --- conditional_logic: branches on whether the migrated
            // definition itself set visible_when — equivalent to re-deriving
            // the branch from the raw value (as dávka 2 did) for a correctly
            // migrated definition, since the reader only ever sets
            // visible_when when the raw condition was mappable, and simpler:
            // it removes the auditor's last direct dependency on
            // Migration\VisibleWhenMapper.
            $accounted[] = 'conditional_logic';
            $rawCl = $acfField['conditional_logic'] ?? false;
            $looksLikeNoCondition = false === $rawCl || 0 === $rawCl || empty($rawCl);
            if (!$looksLikeNoCondition) {
                if (isset($defField['visible_when'])) {
                    if (!$this->looseEquals($reconstructed['conditional_logic'], $rawCl)) {
                        $violations[] = "{$path}.conditional_logic: not reconstructible from migrated field.visible_when";
                    }
                } elseif (!$this->looseEquals($wp['conditional_logic'] ?? null, $rawCl)) {
                    $violations[] = "{$path}.conditional_logic: complex condition not preserved verbatim in migrated field.wp.conditional_logic";
                }
            }

            // --- everything else: either a baseline default, or must be
            // present verbatim in wp: — otherwise it's silent data loss.
            foreach ($acfField as $prop => $value) {
                if (in_array($prop, $accounted, true)) {
                    continue;
                }
                if ($this->typeDefaults->isDefault($type, $prop, $value)) {
                    continue;
                }
                if (array_key_exists($prop, $wp) && $this->looseEquals($wp[$prop], $value)) {
                    continue;
                }
                $violations[] = sprintf(
                    '%s.%s: value %s is neither a baseline default, a lifted semantic key, nor present in wp: — silent data loss',
                    $path,
                    $prop,
                    json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );
            }

            if (!empty($acfField['sub_fields'])) {
                $childDefFields = (array) ($defField['fields'] ?? []);
                /** @var list<array<string,mixed>> $subFields */
                $subFields = (array) $acfField['sub_fields'];
                $violations = [
                    ...$violations,
                    ...$this->auditFields($subFields, $childDefFields, $path, $keyNameMap),
                ];
            }

            if (!empty($acfField['layouts'])) {
                $defLayouts = (array) ($defField['layouts'] ?? []);
                /** @var list<array<string,mixed>> $layouts */
                $layouts = (array) $acfField['layouts'];
                // Finding B (auditor half) — the migrated definition
                // collapses layouts into a name-keyed map, so iterating
                // the raw ACF layout LIST and looking each one up in that
                // already-collapsed map independently is blind to
                // duplicates: if two raw layouts share a name AND happen
                // to have an identical sub-field shape, every iteration
                // "matches" the same single migrated layout and reports
                // zero violations — even though one whole raw layout was
                // silently discarded during migration. Track raw layout
                // names seen in THIS list and flag the duplicate directly,
                // independent of whatever the migrated definition looks
                // like.
                $seenLayoutNames = [];
                foreach ($layouts as $layout) {
                    $layoutName = (string) ($layout['name'] ?? '');
                    $layoutPath = "{$path}.{$layoutName}";

                    if (isset($seenLayoutNames[$layoutName])) {
                        $violations[] = "{$layoutPath}: duplicate layout name in raw ACF source — "
                            . 'one of these layouts was silently discarded during migration';
                        continue;
                    }
                    $seenLayoutNames[$layoutName] = true;

                    if (!array_key_exists($layoutName, $defLayouts)) {
                        $violations[] = "{$layoutPath}: layout missing from migrated definition entirely";
                        continue;
                    }
                    $defLayout = $defLayouts[$layoutName];
                    /** @var list<array<string,mixed>> $layoutSubFields */
                    $layoutSubFields = (array) ($layout['sub_fields'] ?? []);
                    $violations = [
                        ...$violations,
                        ...$this->auditFields($layoutSubFields, (array) ($defLayout['fields'] ?? []), $layoutPath, $keyNameMap),
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,string> $map
     */
    private function buildKeyNameMap(array $fields, array &$map): void
    {
        foreach ($fields as $f) {
            if (isset($f['key'], $f['name'])) {
                $map[(string) $f['key']] = (string) $f['name'];
            }
            if (!empty($f['sub_fields'])) {
                /** @var list<array<string,mixed>> $subFields */
                $subFields = (array) $f['sub_fields'];
                $this->buildKeyNameMap($subFields, $map);
            }
            if (!empty($f['layouts'])) {
                /** @var list<array<string,mixed>> $layouts */
                $layouts = (array) $f['layouts'];
                foreach ($layouts as $layout) {
                    /** @var list<array<string,mixed>> $layoutSubFields */
                    $layoutSubFields = (array) ($layout['sub_fields'] ?? []);
                    $this->buildKeyNameMap($layoutSubFields, $map);
                }
            }
        }
    }

    /**
     * ACF stores booleans inconsistently (`0`/`1` ints, `false`/`true` bools,
     * even `"0"`/`"1"` strings) — loose-compare on the bool axis specifically,
     * strict elsewhere. Mirrors TypeDefaults::valuesEqual().
     */
    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (int) (bool) $a === (int) (bool) $b;
        }
        if (is_array($a) && is_array($b)) {
            return $a == $b;
        }
        if (is_array($a) || is_array($b)) {
            return false;
        }
        return $a === $b || (string) $a === (string) $b;
    }
}
