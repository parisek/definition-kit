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
 * (`text`/`email`, `select`/`button_group`-without-multiple) — for the
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
        $postTypes = (array) ($acfField['post_type'] ?? []);
        $extra = ['of' => implode(',', array_map(static fn ($t): string => 'post:' . (string) $t, $postTypes))];
        if (1 === (int) ($acfField['multiple'] ?? 0)) {
            $extra['multiple'] = true;
        }
        return ['type' => 'reference', 'extra' => $extra, 'consumed' => ['type', 'post_type', 'multiple']];
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
}
