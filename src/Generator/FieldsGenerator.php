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
 *
 * ## WordPress data-identity invariants (round 6)
 *
 * Every prior round's fix guarded ONE hole this YAML→ACF mapping could
 * produce, one at a time (R1 `key` collisions, R2 the scan missing
 * accordions, R3 a layout rename changing its `key`, R4 pinning the
 * `key` not also pinning the `name`, R5 a `wp.key` override desyncing
 * `conditional_logic` resolution + an accordion exemption keyed on an
 * empty `name` value instead of the field's `accordion` type). This is
 * the set of identity invariants guarded AS OF round 6 — see the note
 * at the end of this list for why it is deliberately not claimed to be
 * the complete set:
 *
 * 1. **Field `key` is globally unique** across the entire assembled
 *    tree — every ordinary field, every container's sub_fields, every
 *    flexible_content layout's OWN key, and every layout's sub_fields,
 *    PLUS accordion pseudo-fields interleaved by RootFieldGroupBuilder.
 *    Guarded by {@see assertGloballyUniqueKeys()} / {@see collectKeys()},
 *    run over the FINAL assembled tree (`built['fields']`), not the
 *    pre-accordion-interleave `$orderedRawFields` (R2's fix).
 * 2. **Layout `key` is globally unique** — same guard, same global
 *    `$seen` set as (1); a layout's key colliding with an ordinary
 *    field's key (or another layout's) is caught identically.
 * 3. **Layout `name` (the `acf_fc_layout` postmeta value) is unique
 *    WITHIN its own flexible_content field** — checked independently of
 *    (2) because `key` and `name` are two separate ACF identity axes: a
 *    saved row is matched to its layout by `name`, never by `key`, so
 *    two layouts can have distinct keys yet still collide on name. Only
 *    scoped per-field (not globally) — the same layout name in two
 *    DIFFERENT flexible_content fields is harmless, each field owns its
 *    own `acf_fc_layout` namespace. Guarded in {@see buildLayouts()}.
 * 4. **Field `name` (the WordPress postmeta key for the field's VALUE)
 *    is unique among direct siblings under the same parent** — checked
 *    independently of (1)/(2) because `key` and `name` are separate
 *    axes here too: the schema's `wp:` overlay is a fully open object
 *    (see FieldsGeneratorTest::test_wp_overlay_wins_over_baseline_and_reconstruction)
 *    and can repoint `name` onto anything, including a value that
 *    collides with a sibling authored under a different definition-map
 *    key (hence a different, non-colliding `key`). Scoped per-level
 *    (root fields / one container's sub_fields / one layout's
 *    sub_fields) — the same name at a DIFFERENT nesting level is not a
 *    collision. Accordion pseudo-fields are exempt (canonical ACF shape
 *    is `name: ''` for every accordion; several legitimately coexist at
 *    one level). Guarded in {@see collectKeys()} via
 *    {@see assertNameUnseenAtThisLevel()}.
 * 5. **Every `key` referenced by any emitted `conditional_logic` entry
 *    must exist somewhere in the emitted tree.** `visible_when` is
 *    resolved to `conditional_logic` (via VisibleWhenMapper::toConditionalLogic())
 *    against a name=>key map built from the RAW semantic fields, one
 *    level before that same level's fields are actually reconstructed
 *    with their FINAL keys. `deriveOrPinKey()` — the single function
 *    both `siblingKeyMap()` (map-building) and `buildField()`
 *    (field-building) delegate to — is therefore the one place that
 *    must resolve a key with EXACTLY the same precedence buildField()'s
 *    own final merge order uses, or the two computations silently
 *    diverge and a `conditional_logic` entry ends up pointing at a key
 *    nothing was ever emitted under (round 6 / Defect 1: found because
 *    a field pinned its key through `wp.key` rather than the top-level
 *    `key:` prop `deriveOrPinKey()` used to look at exclusively — the
 *    `wp:` overlay is merged in LAST in buildField(), so `wp.key` must
 *    win the SAME way there). Guarded structurally by keeping
 *    `deriveOrPinKey()` the single source of truth for key precedence;
 *    regression-proven in FieldsGeneratorTest by walking the whole
 *    generated tree and asserting every `conditional_logic` reference
 *    resolves, rather than asserting one hardcoded expected key (a
 *    hardcoded expectation would keep passing even if both sides of the
 *    computation drifted onto the same wrong value together).
 *
 *    A field's own pinned `key` and pinned `name` remain independent
 *    pins that need not agree with each other — ACF allows a field's
 *    `key` and `name` to be unrelated strings, and this class never
 *    derives one from the other. But — as (5) above demonstrates — `key`
 *    pinning is NOT a single code path: the top-level `key:` prop and
 *    the open `wp.key` escape hatch are two independent ways to set the
 *    same final value, and any code that reads a field's key must go
 *    through `deriveOrPinKey()` rather than reaching into `$field['key']`
 *    directly, or it will only ever see one of the two paths.
 *
 * What is deliberately NOT guarded, and why:
 * - Duplicate keys in a hand-written YAML `fields:` / `layouts:` map
 *   (e.g. two `title:` entries) are a YAML-parsing concern, not a
 *   PHP-array concern — by the time `$definitionTree['fields']` reaches
 *   this class, the YAML parser has already collapsed duplicates
 *   (last-one-wins) with no diagnostic. That collapse happens upstream
 *   of this class's input and is out of scope for a generator-level
 *   guard; a YAML-authoring lint (duplicate-key detection) would need
 *   to run before the parse step, not after it.
 *
 * ## This list is not, and has never been, complete
 *
 * Five rounds of review have each found ONE more hole in this same
 * class, one at a time, and every round's docblock update claimed (or
 * implied) the enumeration was now exhaustive. It wasn't, twice over
 * (round 5's own invariant (5) turned out to be flatly wrong — see
 * above). Do not read this list as "the complete set of invariants";
 * read it as "the invariants guarded as of the round that last touched
 * this file." The structural reason new ones keep appearing: the
 * schema's `wp:` bag is a fully open object that can override ANY
 * property this class emits, at every nesting level, and several of
 * those properties are independently consumed by a SECOND component
 * downstream (VisibleWhenMapper reading a key derived here;
 * RootFieldGroupBuilder reading a field's `type`/`name` shape;
 * AcfJsonReader's own inverse mapping on the migration side). Every
 * property with a second consumer is a candidate for the same class of
 * bug: whoever added the override path didn't also update every place
 * that pre-computes or assumes that property's value ahead of the
 * override being applied. Treat a new report the same way — as a real
 * gap to close and document, not as proof the previous "complete" list
 * was written carelessly.
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
        // Finding D (round 5) — `key` uniqueness (above) does not imply
        // `name` uniqueness. ACF's postmeta key for a field's VALUE is the
        // field's `name`, scoped to its immediate parent container — the
        // schema's `wp:` overlay is a fully open object (see
        // test_wp_overlay_wins_over_baseline_and_reconstruction) and can
        // repoint `name` onto anything, including a value that collides
        // with a sibling's. Two sibling fields sharing one `name` alias
        // the same postmeta row even when their derived `key`s differ.
        // Scoped to THIS level only (`$fields` is always one container's
        // own direct children — root fields, one group/repeater's
        // sub_fields, or one layout's sub_fields) — the same `name` at a
        // DIFFERENT nesting level is not a collision.
        $seenNamesThisLevel = [];
        foreach ($fields as $field) {
            $this->assertKeyUnseen((string) $field['key'], $seen);
            // Accordion pseudo-fields always carry `name: ''` by canonical
            // ACF shape (RootFieldGroupBuilder::accordionBaseline()) —
            // several accordions legitimately coexist at the same level
            // (each is its own key-guarded section marker), so they are
            // deliberately exempt from the sibling-uniqueness check.
            //
            // Defect 2 (MEDIUM, confirmed) — the exemption used to be
            // keyed on the VALUE ('' === name) rather than the SHAPE
            // (type === 'accordion'). `wp:` is a fully open escape-hatch
            // object (see test_sibling_fields_cannot_collide_on_name_via_wp_overlay),
            // so an ORDINARY field can set `wp: {name: ''}` and silently
            // escape the guard the same way an accordion legitimately
            // does — two such fields alias the same ('' ) postmeta name
            // with no diagnostic. Discriminate by the field's own `type`
            // (accordionBaseline() always emits `type: 'accordion'`)
            // instead, so the exemption can only ever match a genuine
            // accordion pseudo-field.
            if ('accordion' !== ($field['type'] ?? null)) {
                $this->assertNameUnseenAtThisLevel((string) $field['name'], $seenNamesThisLevel);
            }

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
     * @param array<string,bool> $seenNamesThisLevel
     */
    private function assertNameUnseenAtThisLevel(string $name, array &$seenNamesThisLevel): void
    {
        if (isset($seenNamesThisLevel[$name])) {
            throw new GenerationValidationException(sprintf(
                "Generated field name '%s' is shared by two sibling fields under the same parent. "
                . "ACF's postmeta key for a field's value is its `name`, scoped to its parent — two "
                . "sibling fields sharing one `name` (whether from the definition's own field-map key "
                . 'or a `wp: {name: …}` overlay) would alias the same WordPress postmeta row. Rename '
                . 'one of the colliding fields, or remove the overlapping `wp.name` override.',
                $name,
            ));
        }
        $seenNamesThisLevel[$name] = true;
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
        $seenAcfNames = [];
        foreach ($layoutDefs as $layoutName => $layoutDef) {
            // Finding 1 (round 4, CRITICAL) — the ACF `name` (what WordPress
            // stores in `acf_fc_layout` postmeta) is pinned verbatim by
            // AcfJsonReader::readLayouts() via the layout's own `name:` key,
            // exactly like `key` already was per round 3. The YAML map key
            // (`$layoutName`) is a cosmetic authoring label a maintainer can
            // rename freely; trusting it as the ACF name would silently
            // rewrite postmeta identity on every rename. Prefer the pinned
            // `name:` when present; fall back to the map key only for
            // hand-authored definitions that never went through migration
            // (a brand-new layout authored directly in YAML, where the map
            // key genuinely IS the only name that has ever existed).
            $acfName = (string) ($layoutDef['name'] ?? $layoutName);

            // Round 5 — `key` uniqueness (guarded elsewhere) does not imply
            // `name` uniqueness. ACF matches a saved flex-content row's
            // layout by `acf_fc_layout` == the layout's `name`, never its
            // `key` — two layouts in the SAME flexible_content field can
            // pin distinct keys yet still collide on `name`, making rows
            // indistinguishable to WordPress at render/save time. This
            // must be checked per-field (a repeated name across two
            // DIFFERENT flexible_content fields is harmless — each field
            // has its own `acf_fc_layout` namespace).
            if (isset($seenAcfNames[$acfName])) {
                throw new GenerationValidationException(sprintf(
                    "Layout name '%s' is used by two layouts in the same flexible_content field '%s'. "
                    . "ACF matches a saved row's layout by `acf_fc_layout` == name, not `key` — two "
                    . 'layouts sharing one name would be indistinguishable to WordPress even though '
                    . 'their keys differ. Rename one of the layouts (or pin a distinct `name:`).',
                    $acfName,
                    implode('.', $nameChain),
                ));
            }
            $seenAcfNames[$acfName] = true;

            $layoutChain = [...$nameChain, $acfName];
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
                'name' => $acfName,
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
        // Defect 1 (HIGH, confirmed) — a field's ACF `key` can be pinned
        // through TWO independent paths: the top-level `key:` prop (what
        // AcfJsonReader::readFields() always writes for a migrated field
        // whose real key deviates from the derived convention), or the
        // open `wp:` escape hatch (`wp.key`, e.g. a hand-authored
        // definition, or any future migration path that stashes it
        // there). `buildField()`'s final merge already gives `wp.key`
        // priority over the derived/top-level `key` (the `wp` overlay is
        // merged in LAST). This method must resolve a field's key with
        // that exact same precedence — it is the SAME lookup
        // `siblingKeyMap()` uses to build the name=>key map that
        // VisibleWhenMapper::toConditionalLogic() resolves `visible_when`
        // against. Reading only the top-level `key` here (as before)
        // let a `wp.key`-pinned field's `conditional_logic` reference
        // the wrong (derived) key — a dangling reference to a field that
        // was never emitted under that key.
        $wpKey = $field['wp']['key'] ?? null;
        if (null !== $wpKey) {
            return (string) $wpKey;
        }

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
