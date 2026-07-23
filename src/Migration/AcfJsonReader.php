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
        'add_label', 'placeholder', 'visible_when', 'fields', 'role', 'key', 'wp',
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
        $wpml = $acfField['wpml_cf_preferences'] ?? null;
        if (is_int($wpml) && $this->wpmlMapper->isCanonical($acfType, $wpml)) {
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
        if ('repeater' === $acfType) {
            // 0 is ACF's own "no limit" sentinel for repeater row bounds —
            // omitted, matching ACF's UI semantics. Deliberate correction
            // vs. the prototype (transform3.php), which dropped repeater
            // min/max unconditionally — see Global Constraints.
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
