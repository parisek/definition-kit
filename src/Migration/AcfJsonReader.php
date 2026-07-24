<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Baseline\TypeDefaults;

/**
 * Reads a decoded acf.json plus (optionally) the component's twig source
 * into the intermediate PHP tree FieldsYamlWriter validates and dumps.
 * This is the authored-semantic-layer transform per ADR 0006: props equal
 * to the type-defaults baseline are dropped, everything with an abstract
 * slot is lifted, and only genuine deviations survive in a field's `wp:`.
 */
final class AcfJsonReader
{
    private const FIELD_KEY_ORDER = [
        'type', 'label', 'description', 'mcp', 'dev', 'translatable', 'required',
        'maxlength', 'min', 'max', 'step', 'accept', 'max_size',
        'min_width', 'max_width', 'min_height', 'max_height',
        'kind', 'shape', 'multiline', 'of', 'multiple', 'options',
        'add_label', 'placeholder', 'visible_when', 'fields', 'layouts', 'role', 'key', 'wp',
    ];

    /** Structural boilerplate dropped unconditionally, never lifted, never in wp:. */
    private const ALWAYS_DROPPED = ['parent_repeater'];

    public function __construct(
        private readonly AbstractTypeMapper $typeMapper = new AbstractTypeMapper(),
        private readonly VisibleWhenMapper $visibleWhenMapper = new VisibleWhenMapper(),
        private readonly TypeDefaults $typeDefaults = new TypeDefaults(),
        private readonly TwigMetadataReader $twigMetadataReader = new TwigMetadataReader(),
        private readonly WpmlTranslatableMapper $wpmlMapper = new WpmlTranslatableMapper(),
        private readonly AccordionResidualCapturer $accordionCapturer = new AccordionResidualCapturer(),
    ) {
    }

    /**
     * @param array<string,mixed> $acfJson
     * @return array<string,mixed>
     */
    public function read(array $acfJson, string $componentSlug, ?string $twigSource = null): array
    {
        $keyNameMap = [];
        $this->buildKeyNameMap((array) ($acfJson['fields'] ?? []), $keyNameMap);

        $twigMeta = null !== $twigSource ? $this->twigMetadataReader->read($twigSource) : [];

        $root = [];
        $root['name'] = '' !== ($twigMeta['name'] ?? '') ? $twigMeta['name'] : (string) ($acfJson['title'] ?? '');
        // `usage` is a native multi-value list in the definition. The twig
        // front-comment authoring convention is a comma-separated string
        // (`usage: 404, article-list`), split here into a trimmed, non-empty
        // list so the generated <name>.yaml carries YAML's native sequence
        // syntax. A single usage still becomes a 1-element list, keeping the
        // key's type uniform for every downstream consumer. Emitted before
        // the plain string metadata below so the dumped key order stays
        // name → usage → category → … (matching the twig front-comment).
        if (isset($twigMeta['usage']) && '' !== $twigMeta['usage']) {
            $usageIds = array_values(array_filter(
                array_map('trim', explode(',', $twigMeta['usage'])),
                static fn(string $id): bool => '' !== $id,
            ));
            if ([] !== $usageIds) {
                $root['usage'] = $usageIds;
            }
        }
        foreach (['category', 'kind', 'render', 'web', 'asana', 'figma', 'drupal', 'description'] as $metaKey) {
            if (isset($twigMeta[$metaKey]) && '' !== $twigMeta[$metaKey]) {
                $root[$metaKey] = $twigMeta[$metaKey];
            }
        }
        // `weight`/`responsive` are the only root metadata keys with a
        // non-string schema type — TwigMetadataReader hands back every
        // front-comment value as a raw string, so they're coerced here
        // (int / bool) rather than in the reader, which stays type-agnostic.
        if (isset($twigMeta['weight']) && is_numeric($twigMeta['weight'])) {
            $root['weight'] = (int) $twigMeta['weight'];
        }
        if (isset($twigMeta['responsive']) && '' !== $twigMeta['responsive']) {
            $normalized = strtolower(trim((string) $twigMeta['responsive']));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                $root['responsive'] = true;
            } elseif (in_array($normalized, ['false', '0', 'no'], true)) {
                $root['responsive'] = false;
            }
        }

        $groupKey = (string) ($acfJson['key'] ?? '');
        $expectedGroupKey = 'group_' . $componentSlug;
        if ($groupKey !== $expectedGroupKey) {
            $root['key'] = $groupKey;
        }

        // The ACF field-group's OWN root `description` (distinct from the
        // twig front-comment `description` captured into root metadata
        // above — that's component documentation, this is the group's own
        // ACF prop). Only non-baseline (baseline is '', per
        // Generator\RootFieldGroupBuilder::ROOT_DEFAULTS) values are
        // captured, and verbatim into the root `wp:` escape hatch — never
        // into the metadata `description:` key, which would conflate the
        // two.
        $acfRootDescription = (string) ($acfJson['description'] ?? '');
        if ('' !== $acfRootDescription) {
            $root['wp']['description'] = $acfRootDescription;
        }

        $fields = [];
        $accordions = [];
        $pendingAccordion = null;
        foreach ((array) ($acfJson['fields'] ?? []) as $acfField) {
            if ('accordion' === ($acfField['type'] ?? null)) {
                // An accordion carries no data of its own that survives migration
                // elsewhere in this reader — its {key, label, open} identity plus
                // "which field did it precede" is captured verbatim here, since
                // it is genuinely unrecoverable from any other part of the
                // semantic layer (accordion section titles are freely-authored
                // and don't reliably derive from the fields they group — see
                // the generator plan's Global Constraints). Replayed by
                // Generator\RootFieldGroupBuilder.
                //
                // Any further non-baseline prop the accordion carries (section
                // `instructions`, a non-zero `wpml_cf_preferences`, `multi_expand`,
                // …) is captured verbatim by AccordionResidualCapturer via a
                // self-diff against the generator's baseline — generalising the
                // former wpml-only special case so no per-prop special case
                // accumulates. A fully-baseline accordion adds no residual.
                $pendingAccordion = [
                    'key' => (string) $acfField['key'],
                    'label' => (string) ($acfField['label'] ?? ''),
                    'open' => (int) ($acfField['open'] ?? 0),
                    ...$this->accordionCapturer->capture($acfField),
                ];
                continue;
            }
            if (null !== $pendingAccordion) {
                $accordions[] = [...$pendingAccordion, 'before' => (string) $acfField['name']];
                $pendingAccordion = null;
            }
            $fields[(string) $acfField['name']] = $this->readField($acfField, $componentSlug, [], $keyNameMap);
        }
        if (null !== $pendingAccordion) {
            // Trailing accordion with nothing after it — never observed in the
            // corpus, but a real ACF possibility. `before: null` tells
            // Generator\RootFieldGroupBuilder to append it after the last field
            // rather than silently dropping it.
            $accordions[] = [...$pendingAccordion, 'before' => null];
        }
        $root['fields'] = $fields;

        // Root `wp:` is emitted last, after `fields:` — mirrors the per-field
        // convention (a field's own `wp:` also sits last within that field)
        // and keeps the authored metadata block readable at the top of the
        // file, uninterrupted by the escape hatch.
        if ([] !== $accordions) {
            $root['wp']['accordions'] = $accordions;
        }

        return $root;
    }

    /**
     * @param array<string,mixed> $acfField
     * @param list<string> $nameChain
     * @param array<string,string> $keyNameMap
     * @return array<string,mixed>
     */
    private function readField(array $acfField, string $componentSlug, array $nameChain, array $keyNameMap): array
    {
        $acfType = (string) $acfField['type'];
        $mapped = $this->typeMapper->map($acfField);

        $out = ['type' => $mapped['type'], ...$mapped['extra']];
        $consumed = [
            'type', 'name', 'key', 'label', 'instructions', 'conditional_logic',
            ...$mapped['consumed'],
        ];

        if ('' !== (string) ($acfField['label'] ?? '')) {
            $out['label'] = (string) $acfField['label'];
        }
        if ('' !== (string) ($acfField['instructions'] ?? '')) {
            $out['description'] = (string) $acfField['instructions'];
        }

        // `required` is lifted ONLY for the canonical ACF shape (int 0/1) —
        // any other raw shape (the corpus also has bool `required: false`)
        // is left in wp: rather than mis-cast, matching the reversibility
        // audit from the superseded PR #267.
        $rawRequired = $acfField['required'] ?? null;
        if (0 === $rawRequired || 1 === $rawRequired) {
            if (1 === $rawRequired) {
                $out['required'] = true;
            }
            $consumed[] = 'required';
        }

        // translatable — only the canonical wpml_cf_preferences shape for this
        // field's kind (leaf: 1/2, container: 3) is lifted; an anomalous value
        // (e.g. a leaf carrying 3, a container carrying 2) is left verbatim in
        // wp: so it round-trips losslessly — see WpmlTranslatableMapper.
        //
        // flexible_content is deliberately EXCLUDED here regardless of value —
        // see acf-defaults-baseline.yaml's flexible_content comment: real ACF
        // exports are inconsistent (absent entirely, or a leaf-shaped 1/2,
        // never observed as a container's 3), so this prop is never lifted to
        // `translatable` for this type; whatever raw value is present (if
        // any) survives verbatim in wp.wpml_cf_preferences via the leftover
        // computation below instead.
        $wpml = $acfField['wpml_cf_preferences'] ?? null;
        if ('flexible_content' !== $acfType && is_int($wpml) && $this->wpmlMapper->isCanonical($acfType, $wpml)) {
            if ($this->wpmlMapper->translatable($acfType, $wpml)) {
                $out['translatable'] = true;
            }
            $consumed[] = 'wpml_cf_preferences';
        }

        // constraints — always consumed regardless of whether they're
        // lifted, since the baseline deliberately doesn't list them (they
        // vary per field, not per type — see acf-defaults-baseline.yaml's
        // header comment).
        if (!empty($acfField['maxlength'])) {
            $out['maxlength'] = (int) $acfField['maxlength'];
        }
        $consumed[] = 'maxlength';

        if ('number' === $acfType) {
            foreach (['min', 'max', 'step'] as $prop) {
                if (isset($acfField[$prop]) && '' !== $acfField[$prop]) {
                    $out[$prop] = $acfField[$prop] + 0;
                }
                $consumed[] = $prop;
            }
        }
        if (in_array($acfType, ['repeater', 'flexible_content'], true)) {
            // 0 is ACF's own "no limit" sentinel for repeater/flexible_content
            // row bounds — omitted, matching ACF's UI semantics. Deliberate
            // correction vs. the prototype (transform3.php), which dropped
            // repeater min/max unconditionally — see Global Constraints.
            // flexible_content shares the identical raw shape and sentinel
            // convention (verified against the corpus: eprukaz's two fixtures
            // author real 2/2 bounds; ettin/pm-a leave both as the '' sentinel;
            // perfectaparasols authors min:1 with max left at the 0 sentinel).
            foreach (['min', 'max'] as $prop) {
                $raw = $acfField[$prop] ?? '';
                if ('' !== $raw && 0 !== $raw && '0' !== $raw) {
                    $out[$prop] = $raw + 0;
                }
                $consumed[] = $prop;
            }
        }

        if (!empty($acfField['mime_types'])) {
            $out['accept'] = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $acfField['mime_types']),
            )));
        }
        $consumed[] = 'mime_types';

        if (!empty($acfField['max_size'])) {
            $out['max_size'] = $acfField['max_size'] + 0;
        }
        $consumed[] = 'max_size';

        foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $dim) {
            if (!empty($acfField[$dim])) {
                $out[$dim] = $acfField[$dim] + 0;
            }
            $consumed[] = $dim;
        }

        if (isset($acfField['placeholder']) && '' !== $acfField['placeholder']) {
            $out['placeholder'] = (string) $acfField['placeholder'];
        }
        $consumed[] = 'placeholder';

        $vw = $this->visibleWhenMapper->map($acfField['conditional_logic'] ?? false, $keyNameMap);
        if (null !== $vw['visible_when']) {
            $out['visible_when'] = $vw['visible_when'];
        } elseif (null !== $vw['fallback']) {
            $out['wp']['conditional_logic'] = $vw['fallback'];
        }

        if (!empty($acfField['sub_fields'])) {
            $childChain = [...$nameChain, (string) $acfField['name']];
            $children = [];
            foreach ((array) $acfField['sub_fields'] as $sub) {
                if ('accordion' === ($sub['type'] ?? null)) {
                    continue;
                }
                $children[(string) $sub['name']] = $this->readField($sub, $componentSlug, $childChain, $keyNameMap);
            }
            if ([] === $children) {
                throw new \RuntimeException(sprintf(
                    "Field '%s' is a group/repeater with zero non-accordion sub-fields after migration — "
                    . 'the schema forbids an empty fields map.',
                    (string) $acfField['name'],
                ));
            }
            $out['fields'] = $children;
            $consumed[] = 'sub_fields';
        }

        if ('flexible_content' === $acfType) {
            if (empty($acfField['layouts'])) {
                throw new \RuntimeException(sprintf(
                    "Field '%s' is a flexible_content with zero layouts after migration — "
                    . 'the schema forbids an empty layouts map.',
                    (string) $acfField['name'],
                ));
            }
            $childChain = [...$nameChain, (string) $acfField['name']];
            /** @var list<array<string,mixed>> $rawLayouts */
            $rawLayouts = (array) $acfField['layouts'];
            $out['layouts'] = $this->readLayouts($rawLayouts, $componentSlug, $childChain, $keyNameMap);
            $consumed[] = 'layouts';
        }

        $expectedKey = 'field_' . $componentSlug . '_' . implode('_', [...$nameChain, (string) $acfField['name']]);
        if ((string) $acfField['key'] !== $expectedKey) {
            $out['key'] = (string) $acfField['key'];
        }

        $leftover = $this->typeDefaults->stripDefaults($acfType, $acfField);
        foreach ([...$consumed, ...self::ALWAYS_DROPPED] as $prop) {
            unset($leftover[$prop]);
        }
        // Ambiguous-type marker (see AbstractTypeMapper) — merged in first so
        // real leftover deviations still win key-for-key on conflict (none
        // expected, `acf_type` is never a raw ACF prop).
        $wpMerged = array_merge($mapped['wp'] ?? [], $leftover);
        if ([] !== $wpMerged) {
            $out['wp'] = array_merge($out['wp'] ?? [], $wpMerged);
            ksort($out['wp']);
        }

        return $this->orderField($out);
    }

    /**
     * Recurses into a flexible_content field's `layouts` array — each raw
     * ACF layout becomes one entry in the abstract `layouts` map, keyed by
     * its own `name` (mirroring how a group/repeater's `sub_fields` become
     * a map keyed by field name — see this class's own doc header). The
     * layout's own sub_fields recurse through the SAME readField() used
     * for ordinary nesting, one chain segment deeper (`[...$nameChain,
     * $layoutName]`), so field keys/`parent_repeater` derive identically
     * to any other nested field — a layout is "just another nesting level"
     * from the key-derivation and conditional-logic-resolution point of
     * view, it only carries its own `label`/`key`/`min`/`max` on top.
     *
     * @param list<array<string,mixed>> $layouts raw ACF layouts, in source order
     * @param list<string> $nameChain the flexible_content field's OWN full name chain
     * @param array<string,string> $keyNameMap
     * @return array<string,mixed> layout name => layout definition
     */
    private function readLayouts(array $layouts, string $componentSlug, array $nameChain, array $keyNameMap): array
    {
        $out = [];
        foreach ($layouts as $layout) {
            $layoutName = (string) $layout['name'];
            $layoutChain = [...$nameChain, $layoutName];

            // Finding B (CRITICAL) — `$out[$layoutName] = …` below would
            // silently overwrite an earlier layout of the same name (and
            // discard its key) with no diagnostic at all. Mirrors the
            // adjacent empty-layouts / empty-sub-fields guards' style.
            if (array_key_exists($layoutName, $out)) {
                throw new \RuntimeException(sprintf(
                    "Duplicate layout name '%s' in flexible_content field '%s' — "
                    . 'two ACF layouts sharing one name would collapse into a single '
                    . 'migrated layout, silently discarding the earlier one and its key.',
                    $layoutName,
                    implode('.', $nameChain),
                ));
            }

            $children = [];
            foreach ((array) ($layout['sub_fields'] ?? []) as $sub) {
                if ('accordion' === ($sub['type'] ?? null)) {
                    continue;
                }
                $children[(string) $sub['name']] = $this->readField($sub, $componentSlug, $layoutChain, $keyNameMap);
            }
            if ([] === $children) {
                throw new \RuntimeException(sprintf(
                    "Layout '%s' has zero non-accordion sub-fields after migration — "
                    . 'the schema forbids an empty fields map.',
                    $layoutName,
                ));
            }

            $layoutOut = [];
            if ('' !== (string) ($layout['label'] ?? '')) {
                $layoutOut['label'] = (string) $layout['label'];
            }
            // min/max share the exact repeater/flexible_content field-level
            // sentinel rule ('' and 0 both mean "no per-layout row limit
            // authored") — see the field-level min/max handling above.
            foreach (['min', 'max'] as $prop) {
                $raw = $layout[$prop] ?? '';
                if ('' !== $raw && 0 !== $raw && '0' !== $raw) {
                    $layoutOut[$prop] = $raw + 0;
                }
            }
            $layoutOut['fields'] = $children;

            // Finding C (CRITICAL) — `display` (block|table|row) and
            // `location` are canonical ACF layout props the generator
            // side previously hardcoded to 'block' / null unconditionally,
            // silently rewriting any layout authored with a non-default
            // value. Capture them verbatim into the layout's own `wp:`
            // escape hatch whenever they deviate from the ACF default, so
            // FieldsGenerator can replay them instead of guessing.
            //
            // Deliberate canonicalisation, not presence-loss (round 3,
            // finding 3): this is VALUE-level round-tripping, not
            // presence-level. A layout's `display`/`location` keys are
            // always present in real ACF Local JSON exports — every real
            // corpus fixture with flexible_content confirms it (see
            // `test_flexible_content_layout_non_default_display_and_location_round_trip_by_value`
            // in GenerationRoundTripTest) — so "absent" never actually
            // happens; the only thing this canonicalises is an EXPLICITLY
            // authored default value (`display: "block"`, `location: null`)
            // down to omitting the `wp:` entry, which regenerates the
            // identical default value either way. A non-default value is
            // never coerced — it always round-trips byte-for-byte via the
            // `wp:` bag below.
            $layoutWp = [];
            $display = (string) ($layout['display'] ?? 'block');
            if ('block' !== $display) {
                $layoutWp['display'] = $display;
            }
            $location = $layout['location'] ?? null;
            if (null !== $location) {
                $layoutWp['location'] = $location;
            }
            if ([] !== $layoutWp) {
                $layoutOut['wp'] = $layoutWp;
            }

            // Finding 1 (round 3, CRITICAL) — unlike an ordinary field's key
            // (only pinned when it deviates from the derived convention —
            // that omit-if-matching behaviour is deliberate, long-tested
            // doctrine for FIELDS, see `test_field_key_omitted_when_matches_convention`-
            // style assertions elsewhere in this class), a LAYOUT's key is
            // ALWAYS pinned verbatim, unconditionally. The two cases aren't
            // symmetric: a field's `name` is itself the ACF-authoritative
            // identity (renaming the YAML map key IS renaming the field,
            // same postmeta key either way), but a flexible_content layout's
            // map key is a purely cosmetic authoring label — the *real*
            // ACF/WordPress identity of a layout is its `key`
            // (`acf_fc_layout` values stored in postmeta reference the
            // layout's `name`, and the layout's OWN identity for Admin
            // "Sync" routing is `key`). If the key is pinned only when it
            // deviates from the derived convention, renaming a layout whose
            // key happens to already match the convention re-derives a
            // DIFFERENT key on next generation — silently orphaning every
            // `acf_fc_layout` value already stored for that layout, with no
            // diagnostic. Always emitting `key` here freezes that identity
            // at migration time regardless of what the YAML map key is
            // renamed to afterwards.
            $layoutOut['key'] = (string) $layout['key'];

            // Finding 1 (round 4, CRITICAL) — the round-3 fix pinned `key`
            // but left `name` implicit (derived from the YAML map key by
            // FieldsGenerator::buildLayouts()). That's the wrong half: ACF
            // stores `acf_fc_layout` postmeta BY NAME, not by key, and a
            // layout's map key is a purely cosmetic authoring label (see
            // the `key` pinning rationale above — the same asymmetry
            // applies identically to `name`). Renaming the map key must
            // not change what's stored in postmeta, so `name` is pinned
            // verbatim here exactly like `key`, unconditionally — not only
            // when it deviates from the map key. FieldsGenerator now reads
            // this pinned `name` instead of trusting the map key.
            $layoutOut['name'] = $layoutName;

            $out[$layoutName] = $layoutOut;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
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
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function orderField(array $field): array
    {
        $ordered = [];
        foreach (self::FIELD_KEY_ORDER as $key) {
            if (array_key_exists($key, $field)) {
                $ordered[$key] = $field[$key];
            }
        }
        foreach ($field as $key => $value) {
            $ordered[$key] ??= $value;
        }
        return $ordered;
    }
}
