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

    /**
     * Round 7 — the deny-list widens beyond the round-6 scalar identity
     * triple (`key`/`name`/`type`) to also cover STRUCTURAL containers
     * (`fields`/`sub_fields`/`layouts`) and a CROSS-REFERENCE
     * (`parent_repeater`). Round 7 found four bypasses the round-6 set
     * missed, all the same root cause wearing a different prop name:
     *
     * - `wp.key` / `wp.fields` at the ROOT (assertNoIdentityPropsInWpOverlay()
     *   never walked the definition tree's own `wp`, only `fields`) —
     *   RootFieldGroupBuilder::build() merges root `wp:` with HIGHEST
     *   precedence over the `key`/`fields` it just assembled, so an
     *   overlay silently repointed the group's key or (most severely)
     *   zeroed out the entire generated `fields` list.
     * - `wp.sub_fields` / `wp.layouts` on an ORDINARY (non-container,
     *   non-flexible_content) field — buildField() merges `wpOverrides`
     *   onto `$field` BEFORE the container/flexible_content branch would
     *   overwrite `sub_fields`/`layouts` from real derived children; a
     *   leaf field never re-derives either key, so a smuggled array
     *   (carrying its own bogus key/name/type triple) survives verbatim.
     * - `wp.parent_repeater` on a repeater's child — merged AFTER the
     *   real, ACF-computed `parent_repeater` is assigned from nesting,
     *   silently overwriting it. `parent_repeater` has no legitimate
     *   authoring path at all (always re-derived), so it is reserved
     *   outright rather than gaining an "alternative" sanctioned prop.
     *
     * `conditional_logic` is deliberately NOT in this list — see
     * {@see assertConditionalLogicReferencesResolve()}'s docblock for why
     * it gets a validation gate instead of an outright ban.
     */
    private const RESERVED_WP_PROPS = ['key', 'name', 'type', 'fields', 'sub_fields', 'layouts', 'parent_repeater'];

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

        // Round 6 defensive check — belt-and-braces alongside the JSON
        // Schema deny-list (component.fields.schema.json `wpOverlay`
        // $def). Callers that build a definition tree in memory and hand
        // it to FieldsGenerator directly (every test in this class, plus
        // any future in-process caller) never go through
        // FieldsSchemaValidator, so the schema-level guard alone would
        // leave this class's own public contract unguarded. Must run
        // BEFORE siblingKeyMap() below — that call (via deriveOrPinKey())
        // is the FIRST thing that would have read a forbidden `wp.key`
        // had this check not already rejected it.
        //
        // Round 7 — the ROOT node's own `wp:` bag is checked FIRST and
        // separately: it is not a member of `$fields` (it lives directly
        // on `$definitionTree`), so the recursive walk below never saw it.
        // RootFieldGroupBuilder::build() merges root `wp:` with HIGHEST
        // precedence over the `key`/`fields` it just assembled — an
        // unguarded root `wp.key` silently repoints the group's key, and
        // an unguarded root `wp.fields` silently zeroes the entire
        // generated `fields` list (the most severe finding of round 7).
        $this->assertNoReservedWpProps((array) ($definitionTree['wp'] ?? []), [], false);
        $this->assertNoIdentityPropsInWpOverlay($fields, []);

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
        $this->assertConditionalLogicReferencesResolve($builtFields);

        return $built;
    }

    /**
     * `wp.conditional_logic` is deliberately NOT in {@see RESERVED_WP_PROPS}
     * — unlike the identity/structural props, it is a REAL fallback
     * Migration\AcfJsonReader emits whenever raw ACF conditional_logic is
     * too complex to reduce to the abstract `visible_when` vocabulary
     * (2+ AND conditions, 2+ OR groups, or an unmapped operator — see
     * VisibleWhenMapper::map()). Forbidding it outright would break that
     * round-trip for any component whose ACF source ever used such
     * conditional logic, with no sanctioned replacement to fall back to.
     *
     * What round 7 DOES require: the fallback's references must resolve.
     * A `conditional_logic` entry pointing at a key that exists nowhere
     * in the generated tree is a dangling reference — ACF's editor would
     * show it as broken with zero diagnostic from this tool. This walks
     * the FINAL assembled tree (same shape/order as
     * {@see assertGloballyUniqueKeys()}) collecting every key that
     * exists, then re-walks checking every `conditional_logic` entry's
     * `field` reference against that set.
     *
     * @param list<array<string,mixed>> $builtFields
     */
    private function assertConditionalLogicReferencesResolve(array $builtFields): void
    {
        $allKeys = [];
        $referencedKeys = [];
        $this->collectKeysAndConditionalLogicRefs($builtFields, $allKeys, $referencedKeys);

        foreach ($referencedKeys as $referencedKey) {
            if (!in_array($referencedKey, $allKeys, true)) {
                throw new GenerationValidationException(sprintf(
                    "A `wp.conditional_logic` fallback references key '%s', which does not exist "
                    . 'anywhere in the generated tree — this is a dangling reference ACF\'s editor '
                    . 'would show as broken. Fix the referenced key, or express the condition through '
                    . '`visible_when:` if it fits the single-condition vocabulary.',
                    $referencedKey,
                ));
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @param list<string> $allKeys
     * @param list<string> $referencedKeys
     */
    private function collectKeysAndConditionalLogicRefs(array $fields, array &$allKeys, array &$referencedKeys): void
    {
        foreach ($fields as $field) {
            $allKeys[] = (string) $field['key'];

            $conditionalLogic = $field['conditional_logic'] ?? false;
            if (is_array($conditionalLogic)) {
                foreach ($conditionalLogic as $orGroup) {
                    foreach ((array) $orGroup as $cond) {
                        if (isset($cond['field'])) {
                            $referencedKeys[] = (string) $cond['field'];
                        }
                    }
                }
            }

            if (!empty($field['sub_fields'])) {
                /** @var list<array<string,mixed>> $subFields */
                $subFields = (array) $field['sub_fields'];
                $this->collectKeysAndConditionalLogicRefs($subFields, $allKeys, $referencedKeys);
            }

            if (!empty($field['layouts'])) {
                /** @var list<array<string,mixed>> $layouts */
                $layouts = (array) $field['layouts'];
                foreach ($layouts as $layout) {
                    $allKeys[] = (string) $layout['key'];
                    /** @var list<array<string,mixed>> $layoutSubFields */
                    $layoutSubFields = (array) ($layout['sub_fields'] ?? []);
                    $this->collectKeysAndConditionalLogicRefs($layoutSubFields, $allKeys, $referencedKeys);
                }
            }
        }
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
     * Round 6 — `key`/`name`/`type` are a field's WHOLE identity: `key` is
     * the ACF postmeta key, `name` is the ACF postmeta name, `type` is the
     * field-shape discriminator every downstream consumer (baseline
     * lookup, sentinel lookup, container-vs-leaf branching, the accordion
     * exemption in {@see collectKeys()}) reads. `wp:` is a fully open
     * escape-hatch object merged with HIGHEST precedence in `buildField()`
     * — so `wp.key`/`wp.name`/`wp.type` are each an independent, silently
     * diverging SECOND path onto the same value the top-level `key:` /
     * the definition-map key / the top-level `type:` prop already own.
     * Every round-6 defect (dangling `conditional_logic` reference via
     * `wp.key`, a sibling-name collision escaping the uniqueness guard via
     * `wp.name`, that SAME guard's accordion exemption re-escaped via
     * `wp: {type: 'accordion'}`, a bogus/null key shipped via `wp.key`)
     * is the same root cause wearing a different prop name. Rather than
     * add a fourth ad hoc guard for whichever prop is discovered next,
     * this closes the whole class structurally: identity has EXACTLY ONE
     * path, full stop. Mirrors the schema-level deny-list
     * (component.fields.schema.json `wpOverlay` $def) — this is the
     * generator's own belt-and-braces copy, since callers that build a
     * tree in memory never go through FieldsSchemaValidator (see
     * `generate()`'s docblock note above the call site).
     *
     * Walks the RAW (pre-generation) definition tree — `fields:` maps and
     * flexible_content `layouts:` maps — recursively, so the check runs
     * before ANY key derivation happens (deriveOrPinKey() included).
     * Layout `wp:` is checked too even though `buildLayouts()` never reads
     * `layout.wp.type` (layouts have no `type` prop to begin with) — the
     * schema forbids the same three props there for a symmetric reason:
     * `buildLayouts()` DOES read `layoutDef['wp']['location']` /
     * `['display']` today, and a future prop added to that read list
     * would inherit the same divergence hazard `wp.key` had for fields.
     *
     * @param array<string,mixed> $fields name => field definition, one level
     * @param list<string> $pathChain dot-joined breadcrumb for error messages
     */
    private function assertNoIdentityPropsInWpOverlay(array $fields, array $pathChain): void
    {
        foreach ($fields as $name => $field) {
            $chain = [...$pathChain, (string) $name];
            $this->assertNoReservedWpProps((array) ($field['wp'] ?? []), $chain, false);

            if (!empty($field['fields']) && is_array($field['fields'])) {
                $this->assertNoIdentityPropsInWpOverlay($field['fields'], $chain);
            }

            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layoutDef) {
                    $layoutChain = [...$chain, (string) $layoutName];
                    $this->assertNoReservedWpProps(
                        (array) (((array) $layoutDef)['wp'] ?? []),
                        $layoutChain,
                        true,
                    );

                    $layoutFields = (array) (((array) $layoutDef)['fields'] ?? []);
                    if ([] !== $layoutFields) {
                        $this->assertNoIdentityPropsInWpOverlay($layoutFields, $layoutChain);
                    }
                }
            }
        }
    }

    /**
     * Shared reserved-prop check used for the ROOT node's own `wp:` bag,
     * every field's `wp:` bag, and every flexible_content layout's `wp:`
     * bag — one deny-list ({@see RESERVED_WP_PROPS}), one place that
     * throws, so root/field/layout can never silently drift apart on
     * which props are reserved (round 7's root cause: the schema and the
     * generator's own check independently forgot to cover the root node).
     *
     * @param array<string,mixed> $wp
     * @param list<string> $chain dot-joined breadcrumb for the error message; empty means "root"
     */
    private function assertNoReservedWpProps(array $wp, array $chain, bool $isLayout): void
    {
        $subject = [] === $chain ? 'The root field group' : ($isLayout ? "Layout '" . implode('.', $chain) . "'" : "Field '" . implode('.', $chain) . "'");

        foreach (self::RESERVED_WP_PROPS as $forbidden) {
            if (array_key_exists($forbidden, $wp)) {
                throw new GenerationValidationException(sprintf(
                    "%s sets `wp.%s`, which is forbidden — `%s` is part of a %s identity (or, for "
                    . '`fields`/`sub_fields`/`layouts`/`parent_repeater`, structural/derived metadata '
                    . 'with the same single-source requirement) and must have exactly one source. %s',
                    $subject,
                    $forbidden,
                    $forbidden,
                    [] === $chain ? "field group's" : ($isLayout ? "layout's" : "field's"),
                    $this->wpIdentityPropAlternative($forbidden, $isLayout, [] === $chain),
                ));
            }
        }
    }

    /** Names the sanctioned alternative for a forbidden `wp.<prop>` override, for the exception message. */
    private function wpIdentityPropAlternative(string $forbidden, bool $isLayout, bool $isRoot = false): string
    {
        if ($isRoot) {
            return match ($forbidden) {
                'key' => "Pin the field group's key with the top-level `key:` prop (pattern ^group_) instead.",
                'fields' => 'Author the component\'s `fields:` map at the top level instead — that is the ONLY source the generated `fields` list is assembled from.',
                default => 'There is no sanctioned root-level use of this prop; remove it from `wp:`.',
            };
        }

        return match ($forbidden) {
            'key' => $isLayout
                ? "Pin the layout's key with the top-level `key:` prop (pattern ^layout_) instead."
                : "Pin the field's key with the top-level `key:` prop (pattern ^field_) instead.",
            'name' => $isLayout
                ? "Set the layout's ACF name with its own top-level `name:` prop instead."
                : 'The field name is derived from its own YAML field-map key — rename that map key instead.',
            'type' => 'Use the top-level `type:` prop (the semantic type enum) instead.',
            'fields', 'sub_fields' => "Author this container's children with its own top-level `fields:` map instead.",
            'layouts' => "Author this flexible_content field's variants with its own top-level `layouts:` map instead.",
            'parent_repeater' => "`parent_repeater` is always re-derived from nesting; there is no sanctioned way to author it directly — remove it from `wp:`.",
            default => '',
        };
    }

    /**
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
        // Round 6 — `wp.key` used to be read here too (a field's ACF key
        // had two independent pinning paths: the top-level `key:` prop, or
        // the `wp:` escape hatch). That second path is now rejected
        // upstream by assertNoIdentityPropsInWpOverlay(), called at the
        // very top of generate() before siblingKeyMap() (which calls this
        // method) ever runs — so `$field['wp']['key']` is guaranteed absent
        // by the time this method executes. Identity now has exactly ONE
        // path: the top-level `key:` prop, falling back to the derived
        // convention.
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
