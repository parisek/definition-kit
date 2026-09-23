<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Parisek\DefinitionKit\Support\StructuralType;
use Symfony\Component\Yaml\Yaml;

/**
 * The kit's CMS-neutral field types against Drupal field storage types, read
 * from `schemas/drupal-type-map.yaml`.
 *
 * `accepted()` is what the drift-lint tolerates for a definition field with
 * no `drupal.storage` pin. `canonical()` is the one type a migration leaves
 * implicit and the generator creates: a Drupal field of any other accepted
 * type gets a `drupal.storage` pin, so the definition still says exactly
 * which storage it expects.
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
 *
 * `storageType()` and the widget/formatter lookups serve the generator
 * (ADR 0002): what a new storage, instance and display entry look like.
 */
final class DrupalTypeMap
{
    public const DEFAULT_PATH = __DIR__ . '/../../schemas/drupal-type-map.yaml';

    /** @var array<string,array<string,mixed>> path => parsed map */
    private static array $cache = [];

    /** @var array<string,mixed> */
    private readonly array $map;

    public function __construct(?string $path = null)
    {
        $path ??= self::DEFAULT_PATH;
        if (!isset(self::$cache[$path])) {
            $parsed = Yaml::parseFile($path);
            if (!is_array($parsed) || !is_array($parsed['kit_types'] ?? null) || !is_array($parsed['storage_types'] ?? null)) {
                throw new \RuntimeException("Malformed Drupal type map (needs kit_types and storage_types): {$path}");
            }
            self::$cache[$path] = $parsed;
        }
        $this->map = self::$cache[$path];
    }

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

        return $this->acceptedFor($this->key($field));
    }

    /** @param array<string,mixed> $field */
    public function canonical(array $field): ?string
    {
        return $this->acceptedFor($this->key($field))[0] ?? null;
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
        if (str_starts_with($of, 'entity:')) {
            return self::entityOfTargetType($of);
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
        if (str_starts_with($of, 'entity:')) {
            $bundles = self::entityOfTargetBundles($of);
            sort($bundles);

            return $bundles;
        }

        return null;
    }

    /**
     * Splits an `entity:<target_type>[:<bundle>[,<bundle>…]]` `of:` value.
     * The target type is the segment right after `entity:`; a second `:`
     * starts an optional comma list of bundles.
     */
    private static function entityOfTargetType(string $of): string
    {
        $rest = substr($of, strlen('entity:'));
        $colon = strpos($rest, ':');

        return false === $colon ? $rest : substr($rest, 0, $colon);
    }

    /**
     * The bundle list of an `entity:` `of:` value. An empty list means no
     * restriction — Drupal's own `target_bundles: null` sentinel, the same
     * "any bundle" DrupalField::targetBundles() reads back from the export.
     *
     * @return list<string>
     */
    private static function entityOfTargetBundles(string $of): array
    {
        $rest = substr($of, strlen('entity:'));
        $colon = strpos($rest, ':');
        if (false === $colon) {
            return [];
        }
        $bundles = substr($rest, $colon + 1);

        return '' === $bundles ? [] : explode(',', $bundles);
    }

    /**
     * The storage types a kit type key accepts, canonical first.
     *
     * @return list<string>
     */
    public function acceptedFor(string $key): array
    {
        $types = $this->map['kit_types'][$key] ?? [];

        return is_array($types) ? array_values(array_map('strval', $types)) : [];
    }

    /** @return list<string> every kit type key the map knows */
    public function kitTypeKeys(): array
    {
        return array_map('strval', array_keys($this->map['kit_types']));
    }

    /** @return list<string> every storage type the generator can create */
    public function storageTypes(): array
    {
        return array_map('strval', array_keys($this->map['storage_types']));
    }

    /**
     * What a new field of this storage type looks like.
     *
     * @return array{module: string, storage_settings: array<string,mixed>, instance_settings: array<string,mixed>, widget: array{type: string, settings: array<string,mixed>}, formatter: array{type: string, label: string, settings: array<string,mixed>}}
     */
    public function storageType(string $storageType): array
    {
        $entry = $this->map['storage_types'][$storageType] ?? null;
        if (!is_array($entry)) {
            throw new \DomainException("The Drupal type map has no storage type '{$storageType}'.");
        }

        return [
            'module' => (string) ($entry['module'] ?? 'core'),
            'storage_settings' => self::map($entry['storage_settings'] ?? []),
            'instance_settings' => self::map($entry['instance_settings'] ?? []),
            'widget' => self::plugin($entry['widget'] ?? null, $storageType, 'widget'),
            'formatter' => self::plugin($entry['formatter'] ?? null, $storageType, 'formatter') + ['label' => 'above'],
        ];
    }

    /**
     * The default widget for a storage type. A reference to media uses the
     * media library widget instead of the entity_reference one.
     *
     * @return array{type: string, settings: array<string,mixed>}
     */
    public function widget(string $storageType, ?string $targetType = null): array
    {
        if ('entity_reference' === $storageType && 'media' === $targetType) {
            return self::plugin($this->map['media_reference']['widget'] ?? null, 'media_reference', 'widget');
        }

        return $this->storageType($storageType)['widget'];
    }

    /** @return array{type: string, label: string, settings: array<string,mixed>} */
    public function formatter(string $storageType, ?string $targetType = null): array
    {
        if ('entity_reference' === $storageType && 'media' === $targetType) {
            return self::plugin($this->map['media_reference']['formatter'] ?? null, 'media_reference', 'formatter') + ['label' => 'hidden'];
        }

        return $this->storageType($storageType)['formatter'];
    }

    /** The module that provides a widget plugin, or null for core or an unknown plugin. */
    public function widgetModule(string $widget): ?string
    {
        return self::module($this->map['widget_modules'][$widget] ?? null);
    }

    /** The module that provides a formatter plugin, or null for core or an unknown plugin. */
    public function formatterModule(string $formatter): ?string
    {
        return self::module($this->map['formatter_modules'][$formatter] ?? null);
    }

    /** The module that provides a field type, or null for core. */
    public function storageModule(string $storageType): ?string
    {
        return self::module($this->storageType($storageType)['module']);
    }

    /** The module that provides an entity type, or null when the map does not know it. */
    public function targetTypeModule(string $targetType): ?string
    {
        return self::module($this->map['target_types'][$targetType]['module'] ?? null);
    }

    /**
     * The config name prefix of a target entity type's bundles
     * (`media.type`), or null when its bundles are not config entities.
     */
    public function bundleConfigPrefix(string $targetType): ?string
    {
        $prefix = $this->map['target_types'][$targetType]['bundle_config'] ?? null;

        return is_string($prefix) && '' !== $prefix ? $prefix : null;
    }

    private static function module(mixed $module): ?string
    {
        return is_string($module) && '' !== $module && 'core' !== $module ? $module : null;
    }

    /** @return array<string,mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array{type: string, settings: array<string,mixed>} */
    private static function plugin(mixed $entry, string $owner, string $what): array
    {
        if (!is_array($entry) || !is_string($entry['type'] ?? null)) {
            throw new \DomainException("The Drupal type map gives '{$owner}' no {$what} type.");
        }
        $plugin = ['type' => $entry['type'], 'settings' => self::map($entry['settings'] ?? [])];
        if (is_string($entry['label'] ?? null)) {
            $plugin['label'] = $entry['label'];
        }

        return $plugin;
    }
}
