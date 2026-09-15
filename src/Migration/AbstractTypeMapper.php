<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Maps a raw ACF field array onto the abstract vocabulary from
 * component.fields.schema.json — decides ONLY `type` plus the type-specific
 * modifier keys (`kind`/`shape`/`multiline`/`multiple`/`of`/`options`/
 * `add_label`) and which raw ACF props those keys fully account for.
 * Everything else (label/description/translatable/constraints/
 * visible_when/key/wp: leftover) is AcfJsonReader's job, applied uniformly
 * across every type.
 *
 * Some raw ACF types collapse onto an identical abstract signature
 * (`text`/`email`, `select`/`button_group`/`radio`-without-multiple) — for the
 * minority member of each collision, `map()` also returns a `wp` hint
 * (`acf_type`) so the raw type stays reconstructible; the majority member
 * needs none (its absence is itself the signal).
 */
final class AbstractTypeMapper
{
    /**
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>, wp?: array<string,mixed>}
     */
    public function map(array $acfField): array
    {
        $type = (string) ($acfField['type'] ?? '');

        return match ($type) {
            // `text` and `email` collapse to the identical abstract signature
            // (type:text, no distinguishing extra) — email's raw type would be
            // unreconstructible without a marker, so it gets `wp.acf_type`.
            // `text` is the majority/default case and needs none.
            'text', 'email' => [
                'type' => 'text',
                'extra' => [],
                'consumed' => ['type'],
                'wp' => 'email' === $type ? ['acf_type' => 'email'] : [],
            ],
            'textarea' => ['type' => 'text', 'extra' => ['multiline' => true], 'consumed' => ['type']],
            'wysiwyg' => ['type' => 'richtext', 'extra' => [], 'consumed' => ['type']],
            'number' => ['type' => 'number', 'extra' => [], 'consumed' => ['type']],
            'true_false' => ['type' => 'boolean', 'extra' => [], 'consumed' => ['type']],
            'select' => $this->select($acfField),
            // Collides with a multiple-less `select` (identical signature) —
            // `select` is the majority/default case, so `button_group` is the
            // one that needs the `wp.acf_type` marker.
            'button_group' => [
                'type' => 'select',
                'extra' => ['options' => (array) ($acfField['choices'] ?? [])],
                'consumed' => ['type', 'choices'],
                'wp' => ['acf_type' => 'button_group'],
            ],
            // Collides with a multiple `select` (identical signature) —
            // `select` is the majority/default case, so `checkbox` carries the
            // `wp.acf_type` marker. ACF's checkbox has no `multiple` prop of
            // its own; it is multi-value by field design, which is exactly
            // what the abstract `multiple: true` records.
            'checkbox' => [
                'type' => 'select',
                'extra' => ['options' => (array) ($acfField['choices'] ?? []), 'multiple' => true],
                'consumed' => ['type', 'choices'],
                'wp' => ['acf_type' => 'checkbox'],
            ],
            // Collides with a multiple-less `select`, exactly like
            // `button_group` — a radio is a single choice rendered as a list.
            // The `wp.acf_type` marker is what lets the generator emit
            // `radio` again instead of a `select` nobody authored. Radio-only
            // props (`other_choice`, `save_other_choice`, `layout`, …) that
            // deviate from the baseline survive in the `wp:` bag.
            'radio' => [
                'type' => 'select',
                'extra' => ['options' => (array) ($acfField['choices'] ?? [])],
                'consumed' => ['type', 'choices'],
                'wp' => ['acf_type' => 'radio'],
            ],
            // Relationship collapses onto `reference` with `of: post:<type>[,post:<type>...]`
            // — the same signature `post_object` uses — but a relationship
            // is ALWAYS multi-value by field design (ACF has no single-value
            // mode for it, unlike post_object's opt-in `multiple`), so
            // `multiple: true` is emitted unconditionally rather than
            // derived from a raw prop. `wp.acf_type` disambiguates the
            // otherwise-identical `of: post:x, multiple: true` shape a
            // multi-value post_object would also produce. `min`/`max`/
            // `filters`/`elements`/`taxonomy`/`return_format`/
            // `bidirectional_target` are relationship-only ACF knobs with
            // no abstract home — left unconsumed here, so they fall
            // through to the type-defaults baseline / a field's `wp:` bag
            // exactly like post_object's own `taxonomy`/`return_format`.
            'relationship' => [
                'type' => 'reference',
                'extra' => [
                    'of' => implode(',', array_map(
                        static fn (string $t): string => 'post:' . $t,
                        $this->normalizedPostTypes($acfField),
                    )),
                    'multiple' => true,
                ],
                'consumed' => ['type', 'post_type'],
                'wp' => ['acf_type' => 'relationship'],
            ],
            // Range is a number with a slider UI — same abstract signature
            // as `number` (min/max/step are lifted as constraints exactly
            // like a plain number field, see AcfJsonReader). `wp.acf_type`
            // is what lets the generator emit `range` again instead of a
            // `number` nobody authored.
            'range' => [
                'type' => 'number',
                'extra' => [],
                'consumed' => ['type'],
                'wp' => ['acf_type' => 'range'],
            ],
            'image' => ['type' => 'media', 'extra' => ['kind' => 'image'], 'consumed' => ['type']],
            'file' => ['type' => 'media', 'extra' => ['kind' => 'file'], 'consumed' => ['type']],
            'gallery' => ['type' => 'media', 'extra' => ['kind' => 'gallery', 'multiple' => true], 'consumed' => ['type']],
            'link' => ['type' => 'link', 'extra' => ['shape' => 'link'], 'consumed' => ['type']],
            'url' => ['type' => 'link', 'extra' => ['shape' => 'url'], 'consumed' => ['type']],
            'post_object' => $this->postObject($acfField),
            'google_map' => ['type' => 'reference', 'extra' => ['of' => 'geo'], 'consumed' => ['type']],
            // `field_type` (select|multi_select|checkbox|radio) is deliberately
            // NOT consumed: it is an ACF-only editor-UI axis with no abstract
            // home, so the common value falls out via the type-defaults
            // baseline and anything else survives verbatim in the `wp:` bag.
            'taxonomy' => $this->taxonomy($acfField),
            'date_picker' => ['type' => 'date', 'extra' => [], 'consumed' => ['type']],
            'group' => ['type' => 'group', 'extra' => [], 'consumed' => ['type', 'sub_fields']],
            'repeater' => $this->repeater($acfField),
            'flexible_content' => $this->flexibleContent($acfField),
            default => throw new \DomainException(sprintf(
                "Unsupported ACF field type '%s' for field '%s' — add a case to AbstractTypeMapper::map().",
                $type,
                (string) ($acfField['name'] ?? '?'),
            )),
        };
    }

    /**
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>}
     */
    private function select(array $acfField): array
    {
        $extra = ['options' => (array) ($acfField['choices'] ?? [])];
        if (1 === (int) ($acfField['multiple'] ?? 0)) {
            $extra['multiple'] = true;
        }
        return ['type' => 'select', 'extra' => $extra, 'consumed' => ['type', 'choices', 'multiple']];
    }

    /**
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>}
     */
    private function taxonomy(array $acfField): array
    {
        $taxonomy = (string) ($acfField['taxonomy'] ?? '');
        // A taxonomy field with no target is a broken ACF export; migrating it
        // would produce `of: "term:"`, which the reverse mapper rejects anyway.
        // Fail here, where the offending field name is still in scope.
        if ('' === $taxonomy) {
            throw new \DomainException(sprintf(
                "ACF taxonomy field '%s' has no 'taxonomy' target — cannot derive a 'term:<taxonomy>' reference.",
                (string) ($acfField['name'] ?? '?'),
            ));
        }
        return [
            'type' => 'reference',
            'extra' => ['of' => 'term:' . $taxonomy],
            'consumed' => ['type', 'taxonomy'],
        ];
    }

    /**
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>}
     */
    private function postObject(array $acfField): array
    {
        $extra = ['of' => implode(',', array_map(
            static fn (string $t): string => 'post:' . $t,
            $this->normalizedPostTypes($acfField),
        ))];
        if (1 === (int) ($acfField['multiple'] ?? 0)) {
            $extra['multiple'] = true;
        }
        return ['type' => 'reference', 'extra' => $extra, 'consumed' => ['type', 'post_type', 'multiple']];
    }

    /**
     * An empty/missing raw `post_type` means "no restriction — every post
     * type" in ACF (both `relationship` and `post_object`), NOT "restricted
     * to nothing". `implode(',', [])` used to emit `of: ""` here, which
     * `component.fields.schema.json`'s `of` pattern rejects outright — a
     * schema-invalid definition nobody could author by hand either, so it
     * silently broke migration for any unrestricted relationship/post_object
     * (a normal, common ACF shape — restricting to specific post types is
     * the opt-in case, not the default).
     *
     * `any` is WordPress's OWN reserved sentinel for this exact meaning
     * (`WP_Query`/`get_posts(['post_type' => 'any'])`) — reusing it here
     * means `of: post:any` reads as "no restriction" to anyone who already
     * knows WordPress, not a bespoke convention this tool invented. Also
     * normalizes a post_type carried as a bare empty string (`''`) or an
     * array containing one, rather than a genuinely empty array — both
     * shapes are observed across the corpus for OTHER post_object props
     * (e.g. `taxonomy`), so the same ACF-version-era inconsistency is
     * assumed possible here too.
     *
     * @param array<string,mixed> $acfField
     * @return list<string> at least one non-empty post type name, `['any']`
     *                       when the raw field authored no restriction
     */
    private function normalizedPostTypes(array $acfField): array
    {
        $postTypes = array_values(array_filter(
            array_map('strval', (array) ($acfField['post_type'] ?? [])),
            static fn (string $t): bool => '' !== $t,
        ));
        return [] === $postTypes ? ['any'] : $postTypes;
    }

    /**
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>}
     */
    private function repeater(array $acfField): array
    {
        $extra = [];
        if (!empty($acfField['button_label'])) {
            $extra['add_label'] = (string) $acfField['button_label'];
        }
        return ['type' => 'repeater', 'extra' => $extra, 'consumed' => ['type', 'sub_fields', 'button_label']];
    }

    /**
     * `layouts` is consumed here as a single unit (like repeater's own
     * `sub_fields`) — AcfJsonReader::readField() is what actually
     * recurses into each layout's own sub_fields, exactly mirroring the
     * repeater/group recursion one level down.
     *
     * @param array<string,mixed> $acfField
     * @return array{type: string, extra: array<string,mixed>, consumed: list<string>}
     */
    private function flexibleContent(array $acfField): array
    {
        $extra = [];
        if (!empty($acfField['button_label'])) {
            $extra['add_label'] = (string) $acfField['button_label'];
        }
        return ['type' => 'flexible_content', 'extra' => $extra, 'consumed' => ['type', 'layouts', 'button_label']];
    }
}
