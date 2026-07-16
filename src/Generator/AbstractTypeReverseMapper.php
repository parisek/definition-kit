<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

/**
 * Inverse of Migration\AbstractTypeMapper::map() — picks the concrete ACF
 * field type from a semantic field's abstract `type` + modifier keys
 * (`kind`/`shape`/`of`/`multiple`) and a `wp.acf_type` disambiguation
 * marker where the abstract vocabulary collapses two ACF types onto one
 * signature (text/email, select/button_group). Every collision rule here
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
            'number' => ['acfType' => 'number', 'extra' => []],
            'boolean' => ['acfType' => 'true_false', 'extra' => []],
            'select' => $this->select($semanticField, $wpAcfType),
            'media' => $this->media($semanticField),
            'link' => $this->link($semanticField),
            'reference' => $this->reference($semanticField),
            'date' => ['acfType' => 'date_picker', 'extra' => []],
            'group' => ['acfType' => 'group', 'extra' => []],
            'repeater' => $this->repeater($semanticField),
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
     * @param array<string,mixed> $field
     * @return array{acfType: string, extra: array<string,mixed>}
     */
    private function select(array $field, mixed $wpAcfType): array
    {
        $extra = ['choices' => (array) ($field['options'] ?? [])];
        if (true === ($field['multiple'] ?? false)) {
            $extra['multiple'] = 1;
        }
        return ['acfType' => 'button_group' === $wpAcfType ? 'button_group' : 'select', 'extra' => $extra];
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
    private function reference(array $field): array
    {
        $of = (string) ($field['of'] ?? '');
        if ('geo' === $of) {
            return ['acfType' => 'google_map', 'extra' => []];
        }
        if (str_starts_with($of, 'post:')) {
            $postTypes = array_map(
                static fn (string $part): string => substr($part, strlen('post:')),
                explode(',', $of),
            );
            $extra = ['post_type' => $postTypes];
            if (true === ($field['multiple'] ?? false)) {
                $extra['multiple'] = 1;
            }
            return ['acfType' => 'post_object', 'extra' => $extra];
        }
        throw new \DomainException(sprintf(
            "reference field has unsupported 'of' target '%s' — expected 'geo' or a 'post:<type>[,post:<type>...]' list.",
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
}
