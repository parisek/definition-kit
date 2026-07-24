<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Baseline\TypeDefaults;

/**
 * The generator orchestrator: baseline ⊕ constraint sentinels ⊕
 * FieldReconstructor's per-field reconstruction ⊕ structural props
 * (key/name/parent_repeater) ⊕ a field's own `wp:` overrides (highest
 * priority, always wins) — recursively, then handed to
 * RootFieldGroupBuilder for root assembly + accordion re-insertion.
 */
final class FieldsGenerator
{
    private const CONTAINER_TYPES = ['group', 'repeater'];

    /**
     * Internal-only markers Migration\AcfJsonReader stashes inside a
     * field's `wp` bag that are NOT real ACF props and must never reach
     * generated acf.json. `acf_type` is AbstractTypeMapper's
     * disambiguation marker (already consumed by
     * AbstractTypeReverseMapper::reverse() above, via `buildField()` ->
     * `FieldReconstructor::reconstruct()` -> `AbstractTypeReverseMapper`)
     * — overlaying it verbatim would emit a bogus `acf_type` prop ACF
     * itself never writes. `accordions` is root-only (AcfJsonReader sets
     * it exclusively on the definition tree's own `wp` bag, never on a
     * per-field one) — listed here defensively so a future migration bug
     * that mistakenly attaches it to a field can't leak it either;
     * RootFieldGroupBuilder is the sole legitimate consumer.
     */
    private const INTERNAL_WP_MARKERS = ['acf_type', 'accordions'];

    public function __construct(
        private readonly TypeDefaults $typeDefaults = new TypeDefaults(),
        private readonly ConstraintSentinels $constraintSentinels = new ConstraintSentinels(),
        private readonly FieldReconstructor $fieldReconstructor = new FieldReconstructor(),
        private readonly RootFieldGroupBuilder $rootBuilder = new RootFieldGroupBuilder(),
    ) {
    }

    /**
     * @param array<string,mixed> $definitionTree
     * @return array<string,mixed>
     */
    public function generate(array $definitionTree, string $componentSlug, int $modifiedAt): array
    {
        $fields = (array) ($definitionTree['fields'] ?? []);
        $siblingNameKeyMap = $this->siblingKeyMap($fields, $componentSlug, []);

        $orderedRawFields = [];
        foreach ($fields as $name => $semanticField) {
            $orderedRawFields[] = $this->buildField(
                $semanticField,
                $componentSlug,
                [$name],
                $siblingNameKeyMap,
            );
        }

        $built = $this->rootBuilder->build($definitionTree, $orderedRawFields, $componentSlug, $modifiedAt);

        // Finding 2 (round 3, HIGH) — the uniqueness scan MUST run over the
        // final assembled `fields` list (built['fields']), not
        // $orderedRawFields. RootFieldGroupBuilder::build() interleaves
        // accordion pseudo-fields (from root `wp.accordions`) into that
        // list — an accordion's own `key` (or a collision between two
        // accordions, or an accordion and an ordinary field) would
        // otherwise slip past this guard entirely, since accordions don't
        // exist yet at the point $orderedRawFields is assembled.
        /** @var list<array<string,mixed>> $builtFields */
        $builtFields = $built['fields'];
        $this->assertGloballyUniqueKeys($builtFields);

        return $built;
    }

    /**
     * Finding A (CRITICAL) — key derivation underscore-joins the name
     * chain, so a flexible_content layout `a_b` + field `c` and a
     * sibling layout `a` + field `b_c` both derive `field_<slug>_a_b_c`.
     * Two ACF fields sharing one key alias the SAME WordPress postmeta
     * row — silent, irreversible editor data loss the moment both are
     * ever populated. No ambiguity is acceptable regardless of how it
     * arose (ordinary nesting, repeater sub_fields, or flexible_content
     * layouts) — walk the ENTIRE generated tree (fields, their
     * sub_fields, and every flexible_content layout's own key plus its
     * own sub_fields) and fail loudly the moment two nodes claim the
     * same `key`.
     *
     * @param list<array<string,mixed>> $fields
     */
    private function assertGloballyUniqueKeys(array $fields): void
    {
        $seen = [];
        $this->collectKeys($fields, $seen);
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,bool> $seen
     */
    private function collectKeys(array $fields, array &$seen): void
    {
        foreach ($fields as $field) {
            $this->assertKeyUnseen((string) $field['key'], $seen);

            if (!empty($field['sub_fields'])) {
                /** @var list<array<string,mixed>> $subFields */
                $subFields = (array) $field['sub_fields'];
                $this->collectKeys($subFields, $seen);
            }

            if (!empty($field['layouts'])) {
                /** @var list<array<string,mixed>> $layouts */
                $layouts = (array) $field['layouts'];
                foreach ($layouts as $layout) {
                    $this->assertKeyUnseen((string) $layout['key'], $seen);
                    /** @var list<array<string,mixed>> $layoutSubFields */
                    $layoutSubFields = (array) ($layout['sub_fields'] ?? []);
                    $this->collectKeys($layoutSubFields, $seen);
                }
            }
        }
    }

    /**
     * @param array<string,bool> $seen
     */
    private function assertKeyUnseen(string $key, array &$seen): void
    {
        if (isset($seen[$key])) {
            throw new GenerationValidationException(sprintf(
                "Generated key '%s' collides with another field/layout in the same component. "
                . 'Two ACF elements sharing one key would alias the same WordPress postmeta row — '
                . 'rename one of the colliding fields/layouts (or pin an explicit `key:` on one of '
                . 'them) so the underscore-joined name chains no longer produce the same key.',
                $key,
            ));
        }
        $seen[$key] = true;
    }

    /**
     * @param array<string,mixed> $semanticField
     * @param list<string> $nameChain
     * @param array<string,string> $siblingNameKeyMap this level's own field-name => key map only
     * @return array<string,mixed>
     */
    private function buildField(
        array $semanticField,
        string $componentSlug,
        array $nameChain,
        array $siblingNameKeyMap,
        ?string $parentRepeaterKey = null,
    ): array {
        $reconstructed = $this->fieldReconstructor->reconstruct($semanticField, $siblingNameKeyMap);
        $acfType = $reconstructed['type'];

        $baseline = $this->typeDefaults->forType($acfType);
        $sentinels = $this->constraintSentinels->forType($acfType);
        $key = $this->deriveOrPinKey($semanticField, $componentSlug, $nameChain);

        $field = array_merge($baseline, $sentinels, $reconstructed, [
            'key' => $key,
            'name' => $nameChain[count($nameChain) - 1],
        ]);
        $isContainerField = in_array($acfType, self::CONTAINER_TYPES, true);
        // parent_repeater is ACF-computed structural metadata, re-derived
        // here from nesting (dropped unconditionally by the migration —
        // see acf-defaults-baseline.yaml's own header comment). Verified
        // against the real ACF Pro plugin source
        // (pro/fields/class-acf-field-repeater.php::load_field(), which
        // acf_get_fields()'s its OWN direct sub_fields and array_map()s
        // `parent_repeater = $field['key']` onto every one of them —
        // whatever their own type is, container or leaf, one level only.
        // class-acf-field-group.php::load_field() (the `group` type) has
        // no equivalent array_map at all, so a group NEVER propagates
        // parent_repeater to its own children, even when the group itself
        // sits inside a repeater and carries parent_repeater on itself.
        // Net effect: ONLY a field's IMMEDIATE parent being a repeater
        // matters — a leaf two levels down through an intermediate group
        // gets no parent_repeater at all; the group container directly
        // under the repeater gets it, and so does a leaf directly under
        // the repeater.
        if (null !== $parentRepeaterKey) {
            $field['parent_repeater'] = $parentRepeaterKey;
        }
        $wpOverrides = array_diff_key((array) ($semanticField['wp'] ?? []), array_flip(self::INTERNAL_WP_MARKERS));
        $field = array_merge($field, $wpOverrides);

        if ($isContainerField) {
            $childFields = (array) ($semanticField['fields'] ?? []);
            $childSiblingMap = $this->siblingKeyMap($childFields, $componentSlug, $nameChain);
            // Only a repeater re-stamps parent_repeater on its own direct
            // children (to its own key) — a group never propagates one,
            // regardless of whether the group itself carries one (see the
            // ACF-source note above `$field['parent_repeater']` assignment).
            $childParentRepeaterKey = 'repeater' === $acfType ? $key : null;

            $subFields = [];
            foreach ($childFields as $childName => $childField) {
                $subFields[] = $this->buildField(
                    $childField,
                    $componentSlug,
                    [...$nameChain, $childName],
                    $childSiblingMap,
                    $childParentRepeaterKey,
                );
            }
            $field['sub_fields'] = $subFields;
        } elseif ('flexible_content' === $acfType) {
            $field['layouts'] = $this->buildLayouts(
                (array) ($semanticField['layouts'] ?? []),
                $componentSlug,
                [...$nameChain],
            );
        }

        return $this->orderAcfProps($field);
    }

    /**
     * Builds a flexible_content field's raw `layouts` array from the
     * abstract `layouts` map (name => layout definition) — the
     * layout-shaped counterpart to the `sub_fields` loop above. Each
     * layout's own sub_fields recurse through the SAME buildField() used
     * for ordinary nesting, one chain segment deeper (`[...$nameChain,
     * $layoutName]`), so keys/conditional-logic resolution derive
     * identically to any other nested field.
     *
     * A layout's own children never carry `parent_repeater` — verified
     * against both eprukaz corpus fixtures (split-content,
     * box-price-reference): every sub-field nested inside a
     * flexible_content layout carries no `parent_repeater` at all, unlike
     * a repeater's direct children. Passing `null` below reproduces that.
     *
     * @param array<string,mixed> $layoutDefs layout name => layout definition
     * @param list<string> $nameChain the flexible_content field's OWN full name chain
     * @return list<array<string,mixed>>
     */
    private function buildLayouts(array $layoutDefs, string $componentSlug, array $nameChain): array
    {
        $layouts = [];
        foreach ($layoutDefs as $layoutName => $layoutDef) {
            $layoutChain = [...$nameChain, (string) $layoutName];
            $layoutKey = (string) ($layoutDef['key'] ?? ('layout_' . $componentSlug . '_' . implode('_', $layoutChain)));

            $childFields = (array) ($layoutDef['fields'] ?? []);
            $childSiblingMap = $this->siblingKeyMap($childFields, $componentSlug, $layoutChain);

            $subFields = [];
            foreach ($childFields as $childName => $childField) {
                $subFields[] = $this->buildField(
                    $childField,
                    $componentSlug,
                    [...$layoutChain, $childName],
                    $childSiblingMap,
                    null,
                );
            }

            $layoutWp = (array) ($layoutDef['wp'] ?? []);

            $layout = [
                'key' => $layoutKey,
                'name' => (string) $layoutName,
                'label' => (string) ($layoutDef['label'] ?? ''),
                // Finding C — `display`/`location` are canonical ACF
                // layout props (block|table|row for display) captured
                // verbatim by AcfJsonReader::readLayouts() into the
                // layout's `wp:` escape hatch whenever authored non-default;
                // replay them here instead of hardcoding the default.
                'display' => (string) ($layoutWp['display'] ?? 'block'),
                'sub_fields' => $subFields,
                'min' => $layoutDef['min'] ?? '',
                'max' => $layoutDef['max'] ?? '',
                'location' => $layoutWp['location'] ?? null,
            ];

            $layouts[] = $layout;
        }
        return $layouts;
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $parentChain
     * @return array<string,string> local field name => resolved key, for THIS level's fields only
     */
    private function siblingKeyMap(array $fields, string $componentSlug, array $parentChain): array
    {
        $map = [];
        foreach ($fields as $name => $field) {
            $map[$name] = $this->deriveOrPinKey($field, $componentSlug, [...$parentChain, $name]);
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<string> $nameChain
     */
    private function deriveOrPinKey(array $field, string $componentSlug, array $nameChain): string
    {
        return (string) ($field['key'] ?? ('field_' . $componentSlug . '_' . implode('_', $nameChain)));
    }

    /**
     * Cosmetic only — no test asserts exact key order (JSON key order has
     * no functional meaning to ACF/WordPress; see this plan's Global
     * Constraints). Leads with the props real ACF exports lead with, for
     * human-readable generated JSON; everything else follows unchanged.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function orderAcfProps(array $field): array
    {
        $head = [
            'key', 'allow_in_bindings', 'label', 'name', 'aria-label', 'type',
            'instructions', 'required', 'conditional_logic', 'wrapper',
        ];
        $ordered = [];
        foreach ($head as $prop) {
            if (array_key_exists($prop, $field)) {
                $ordered[$prop] = $field[$prop];
                unset($field[$prop]);
            }
        }
        return [...$ordered, ...$field];
    }
}
