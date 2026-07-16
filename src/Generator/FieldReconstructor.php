<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Migration\VisibleWhenMapper;
use Parisek\DefinitionKit\Migration\WpmlTranslatableMapper;

/**
 * Reconstructs every raw-ACF prop that is DERIVABLE from a migrated
 * semantic field alone — type + type-native extras, required, label,
 * instructions, wpml_cf_preferences, constraints, placeholder,
 * conditional_logic. This is the shared core the task brief calls out
 * explicitly: Migration\MigrationCompletenessAuditor already proved this
 * computation correct per-prop; Generator\FieldsGenerator (Task 7) is
 * this SAME computation run to build acf.json from scratch, layered
 * under acf-defaults-baseline.yaml + constraint-sentinels.yaml and over
 * a field's own `wp:` overrides. Does NOT apply the type-defaults
 * baseline, constraint-sentinel empties, `wp:` overlay, or structural
 * props (key/name/sub_fields) — callers layer those on top.
 */
final class FieldReconstructor
{
    private const CONTAINER_ACF_TYPES = ['group', 'repeater'];

    public function __construct(
        private readonly AbstractTypeReverseMapper $typeMapper = new AbstractTypeReverseMapper(),
        private readonly VisibleWhenMapper $visibleWhenMapper = new VisibleWhenMapper(),
        private readonly WpmlTranslatableMapper $wpmlMapper = new WpmlTranslatableMapper(),
    ) {
    }

    /**
     * @param array<string,mixed> $semanticField
     * @param array<string,string> $nameKeyMap field name => field key
     * @return array<string,mixed>
     */
    public function reconstruct(array $semanticField, array $nameKeyMap): array
    {
        $mapped = $this->typeMapper->reverse($semanticField);
        $acfType = $mapped['acfType'];

        $out = ['type' => $acfType, ...$mapped['extra']];

        $out['required'] = true === ($semanticField['required'] ?? false) ? 1 : 0;
        $out['label'] = (string) ($semanticField['label'] ?? '');
        $out['instructions'] = (string) ($semanticField['description'] ?? '');

        $isContainer = in_array($acfType, self::CONTAINER_ACF_TYPES, true);
        $out['wpml_cf_preferences'] = $this->wpmlMapper->toWpmlPreference(
            $acfType,
            !$isContainer && true === ($semanticField['translatable'] ?? false),
        );

        if (isset($semanticField['maxlength'])) {
            $out['maxlength'] = (int) $semanticField['maxlength'];
        }

        if ('number' === $acfType) {
            foreach (['min', 'max', 'step'] as $prop) {
                if (isset($semanticField[$prop])) {
                    $out[$prop] = $semanticField[$prop];
                }
            }
        }
        if ('repeater' === $acfType) {
            foreach (['min', 'max'] as $prop) {
                if (isset($semanticField[$prop])) {
                    $out[$prop] = $semanticField[$prop];
                }
            }
        }

        if (isset($semanticField['accept'])) {
            $out['mime_types'] = implode(',', (array) $semanticField['accept']);
        }
        if (isset($semanticField['max_size'])) {
            $out['max_size'] = $semanticField['max_size'];
        }
        foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $dim) {
            if (isset($semanticField[$dim])) {
                $out[$dim] = $semanticField[$dim];
            }
        }

        if (isset($semanticField['placeholder'])) {
            $out['placeholder'] = (string) $semanticField['placeholder'];
        }

        $out['conditional_logic'] = isset($semanticField['visible_when'])
            ? $this->visibleWhenMapper->toConditionalLogic($semanticField['visible_when'], $nameKeyMap)
            : false;

        return $out;
    }
}
