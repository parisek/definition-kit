<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Support\StructuralType;

/**
 * Inverse of Migration\AbstractTypeMapper::map() — picks the concrete ACF
 * field type from a semantic field's abstract `type` + modifier keys
 * (`kind`/`shape`/`of`/`multiple`) and a `wp.acf_type` disambiguation
 * marker where the abstract vocabulary collapses two ACF types onto one
 * signature (text/email, select/button_group/radio/checkbox). Every collision rule here
 * mirrors AbstractTypeMapper's own docblock — this class does not invent
 * new type-mapping judgment calls, it only runs them backwards.
 */
final class AbstractTypeReverseMapper
{
    /**
     * @param array<string,mixed> $semanticField
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    public function reverse(array $semanticField): array
    {
        $type = (string) ($semanticField['type'] ?? '');
        $wpAcfType = $semanticField['wp']['acf_type'] ?? null;

        return match ($type) {
            'text' => $this->text($semanticField, $wpAcfType),
            'richtext' => ['acfType' => 'wysiwyg', 'extra' => []],
            'number' => $this->number($wpAcfType),
            'boolean' => ['acfType' => 'true_false', 'extra' => []],
            'select' => $this->select($semanticField, $wpAcfType),
            'media' => $this->media($semanticField),
            'link' => $this->link($semanticField),
            'reference' => $this->reference($semanticField, $wpAcfType),
            'date' => ['acfType' => 'date_picker', 'extra' => []],
            StructuralType::OBJECT => ['acfType' => 'group', 'extra' => []],
            StructuralType::LIST => $this->repeater($semanticField),
            'flexible_content' => $this->flexibleContent($semanticField),
            default => throw new \DomainException(sprintf(
                "Unsupported abstract type '%s' — add a case to AbstractTypeReverseMapper::reverse().",
                $type,
            )),
        };
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function text(array $field, mixed $wpAcfType): array
    {
        if (true === ($field['multiline'] ?? false)) {
            return ['acfType' => 'textarea', 'extra' => []];
        }
        return ['acfType' => 'email' === $wpAcfType ? 'email' : 'text', 'extra' => []];
    }

    /**
     * `range` collides with a plain `number` (identical signature — both
     * lift min/max/step as constraints, neither has a type-specific extra
     * key) — the `wp.acf_type` marker is what lets this stay reconstructible.
     *
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function number(mixed $wpAcfType): array
    {
        return ['acfType' => 'range' === $wpAcfType ? 'range' : 'number', 'extra' => []];
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function select(array $field, mixed $wpAcfType): array
    {
        $extra = ['choices' => (array) ($field['options'] ?? [])];
        // ACF's checkbox is multi-value by field design and has no `multiple`
        // prop — emitting one would be an invented ACF key.
        if ('checkbox' === $wpAcfType) {
            return ['acfType' => 'checkbox', 'extra' => $extra];
        }
        if (true === ($field['multiple'] ?? false)) {
            $extra['multiple'] = 1;
        }
        // `button_group` and `radio` are single-choice by field design too.
        if (in_array($wpAcfType, ['button_group', 'radio'], true)) {
            return ['acfType' => $wpAcfType, 'extra' => $extra];
        }
        return ['acfType' => 'select', 'extra' => $extra];
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function media(array $field): array
    {
        $kind = (string) ($field['kind'] ?? '');
        return match ($kind) {
            'image', 'file', 'gallery' => ['acfType' => $kind, 'extra' => []],
            default => throw new \DomainException(
                "media field is missing a valid 'kind' (image|file|gallery) — cannot pick a concrete ACF type.",
            ),
        };
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function link(array $field): array
    {
        $shape = (string) ($field['shape'] ?? 'link');
        return match ($shape) {
            'url' => ['acfType' => 'url', 'extra' => []],
            default => ['acfType' => 'link', 'extra' => []],
        };
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function reference(array $field, mixed $wpAcfType): array
    {
        $of = (string) ($field['of'] ?? '');
        if ('geo' === $of) {
            return ['acfType' => 'google_map', 'extra' => []];
        }
        if (str_starts_with($of, 'term:')) {
            $taxonomy = substr($of, strlen('term:'));
            // An ACF taxonomy field with `taxonomy: ""` renders an empty term
            // picker — fail loudly rather than emit a dead field.
            if ('' === $taxonomy) {
                throw new \DomainException(
                    "reference field has an empty 'term:' target — expected 'term:<taxonomy>'.",
                );
            }
            return ['acfType' => 'taxonomy', 'extra' => ['taxonomy' => $taxonomy]];
        }
        if (str_starts_with($of, 'post:')) {
            $postTypes = array_map(
                static fn (string $part): string => substr($part, strlen('post:')),
                explode(',', $of),
            );
            // `post:any` is the reverse of AbstractTypeMapper::normalizedPostTypes()'s
            // forward normalization — WordPress's own reserved "no
            // restriction" sentinel, standing in for the raw field's
            // genuinely empty `post_type: []`. Reversing it to a literal
            // `['any']` would emit an ACF post type that does not exist.
            $extra = ['post_type' => ['any'] === $postTypes ? [] : $postTypes];
            // A relationship is always multi-value by field design — it has
            // no raw `multiple` prop of its own, unlike post_object, so none
            // is emitted here (see AbstractTypeMapper::map()'s own relationship
            // docblock for the forward direction of this same asymmetry).
            if ('relationship' === $wpAcfType) {
                return ['acfType' => 'relationship', 'extra' => $extra];
            }
            if (true === ($field['multiple'] ?? false)) {
                $extra['multiple'] = 1;
            }
            return ['acfType' => 'post_object', 'extra' => $extra];
        }
        throw new \DomainException(sprintf(
            "reference field has unsupported 'of' target '%s' — expected 'geo', 'term:<taxonomy>', or a 'post:<type>[,post:<type>...]' list.",
            $of,
        ));
    }

    /**
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function repeater(array $field): array
    {
        $extra = [];
        if (!empty($field['add_label'])) {
            $extra['button_label'] = (string) $field['add_label'];
        }
        return ['acfType' => 'repeater', 'extra' => $extra];
    }

    /**
     * Mirrors repeater() — flexible_content shares the identical
     * add_label <-> button_label bijection.
     *
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function flexibleContent(array $field): array
    {
        $extra = [];
        if (!empty($field['add_label'])) {
            $extra['button_label'] = (string) $field['add_label'];
        }
        return ['acfType' => 'flexible_content', 'extra' => $extra];
    }
}
