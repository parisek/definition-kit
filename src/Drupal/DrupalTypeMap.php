<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Parisek\DefinitionKit\Support\StructuralType;

/**
 * The kit's CMS-neutral field types against Drupal field storage types.
 *
 * `accepted()` is what the drift-lint tolerates for a definition field with
 * no `drupal.storage` pin. `canonical()` is the one type a migration leaves
 * implicit: a Drupal field of any other accepted type gets a `drupal.storage`
 * pin, so the definition still says exactly which storage it expects.
 *
 *   kit type                 canonical                   also accepted
 *   text                     string                      string_long, email, telephone
 *   text (multiline: true)   string_long                 text_long, text
 *   richtext                 text_long                   text, text_with_summary
 *   number                   integer                     decimal, float
 *   boolean                  boolean
 *   select                   list_string                 list_integer, list_float
 *   media                    entity_reference (media)    image, file
 *   link                     link
 *   reference                entity_reference
 *   date                     datetime                    daterange, timestamp
 *   object                   (a field_group, no storage) entity_reference_revisions when pinned by drupal.field
 *   list                     entity_reference_revisions
 *   flexible_content         entity_reference_revisions
 */
final class DrupalTypeMap
{
    private const ACCEPTED = [
        'text' => ['string', 'string_long', 'email', 'telephone'],
        'text_multiline' => ['string_long', 'text_long', 'text'],
        'richtext' => ['text_long', 'text', 'text_with_summary'],
        'number' => ['integer', 'decimal', 'float'],
        'boolean' => ['boolean'],
        'select' => ['list_string', 'list_integer', 'list_float'],
        'media' => ['entity_reference', 'image', 'file'],
        'link' => ['link'],
        'reference' => ['entity_reference'],
        'date' => ['datetime', 'daterange', 'timestamp'],
        'object' => ['entity_reference_revisions'],
        'list' => ['entity_reference_revisions'],
        'flexible_content' => ['entity_reference_revisions'],
    ];

    /** @param array<string,mixed> $field */
    public function key(array $field): string
    {
        $type = StructuralType::canonical((string) ($field['type'] ?? ''));

        return 'text' === $type && true === ($field['multiline'] ?? false) ? 'text_multiline' : $type;
    }

    /**
     * @param array<string,mixed> $field
     * @return list<string>
     */
    public function accepted(array $field): array
    {
        $pinned = $field['drupal']['storage'] ?? null;
        if (is_string($pinned)) {
            return [$pinned];
        }

        return self::ACCEPTED[$this->key($field)] ?? [];
    }

    /** @param array<string,mixed> $field */
    public function canonical(array $field): ?string
    {
        return self::ACCEPTED[$this->key($field)][0] ?? null;
    }

    /**
     * The entity type a reference must target, or null when the definition
     * does not say.
     *
     * @param array<string,mixed> $field
     */
    public function expectedTargetType(array $field, string $drupalType): ?string
    {
        $pinned = $field['drupal']['target_type'] ?? null;
        if (is_string($pinned)) {
            return $pinned;
        }
        if ('entity_reference_revisions' === $drupalType) {
            return 'paragraph';
        }
        if ('entity_reference' !== $drupalType) {
            return null;
        }
        $type = (string) ($field['type'] ?? '');
        if ('media' === $type) {
            return 'media';
        }
        $of = (string) ($field['of'] ?? '');
        if (str_starts_with($of, 'term:')) {
            return 'taxonomy_term';
        }
        if (str_starts_with($of, 'post:')) {
            return 'node';
        }

        return null;
    }

    /**
     * The bundles an entity_reference must allow, or null when the definition
     * does not say. Paragraph targets are resolved by the linter, not here.
     *
     * @param array<string,mixed> $field
     * @return list<string>|null
     */
    public function expectedTargetBundles(array $field): ?array
    {
        $pinned = $field['drupal']['target_bundles'] ?? null;
        if (is_array($pinned)) {
            $bundles = array_values(array_map('strval', $pinned));
            sort($bundles);

            return $bundles;
        }
        $of = (string) ($field['of'] ?? '');
        if (str_starts_with($of, 'term:')) {
            return [substr($of, 5)];
        }
        if (str_starts_with($of, 'post:')) {
            $bundles = array_map(static fn (string $p): string => substr($p, 5), explode(',', $of));
            sort($bundles);

            return $bundles;
        }

        return null;
    }
}
