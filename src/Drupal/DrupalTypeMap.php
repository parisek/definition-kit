<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Parisek\DefinitionKit\Support\StructuralType;
use Symfony\Component\Yaml\Exception\ParseException;
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
 *   text                     string                      string_long, email, telephone, language_field
 *   text (multiline: true)   string_long                 text_long, text
 *   richtext                 text_long                   text, text_with_summary
 *   number                   integer                     decimal, float
 *   boolean                  boolean
 *   select                   list_string                 list_integer, list_float
 *   media                    entity_reference (media)    image, file
 *   link                     link
 *   reference                entity_reference            webform
 *   date                     datetime                    daterange, timestamp
 *   object                   (a field_group, no storage) entity_reference_revisions when pinned by drupal.field; double_field, address
 *   list                     entity_reference_revisions  double_field, address
 *   flexible_content         entity_reference_revisions
 *
 * `storageType()` and the widget/formatter lookups serve the generator
 * (ADR 0002): what a new storage, instance and display entry look like.
 *
 * A project can extend the bundled map with an overlay file
 * (`drupal.type_map` in definition-kit.yaml, see {@see forSettings()}). The
 * overlay is checked by the same rules as the bundled map, and the merge too.
 */
final class DrupalTypeMap
{
    public const DEFAULT_PATH = __DIR__ . '/../../schemas/drupal-type-map.yaml';

    private const SECTIONS = ['kit_types', 'storage_types', 'media_reference', 'target_types', 'widget_modules', 'formatter_modules'];

    /** @var array<string,array<string,mixed>> path => parsed map */
    private static array $cache = [];

    /** @var array<string,mixed> */
    private readonly array $map;

    /**
     * @param string|null $path the bundled map (default: schemas/drupal-type-map.yaml)
     * @param string|null $overlay a project overlay, merged over the bundled map
     */
    public function __construct(?string $path = null, ?string $overlay = null)
    {
        $path ??= self::DEFAULT_PATH;
        if (!isset(self::$cache[$path])) {
            $parsed = self::parse($path, 'type map');
            self::validate($parsed, $path, true);
            self::$cache[$path] = $parsed;
        }
        $map = self::$cache[$path];
        if (null !== $overlay) {
            $over = self::parse($overlay, 'type map overlay (drupal.type_map)');
            self::validate($over, $overlay, false);
            $known = array_map('strval', array_keys((array) $map['kit_types']));
            $misspelled = array_diff(array_map('strval', array_keys((array) ($over['kit_types'] ?? []))), $known);
            if ([] !== $misspelled) {
                throw new \RuntimeException(sprintf(
                    'Malformed Drupal type map overlay (drupal.type_map) %s: kit_types.%s is not a kit type — expected one of: %s.',
                    $overlay,
                    implode(', kit_types.', $misspelled),
                    implode(', ', $known),
                ));
            }
            $map = self::merge($map, $over);
            self::validateKitTypes($map, $overlay);
        }
        $this->map = $map;
    }

    /** The bundled map with the project overlay of the settings, when it names one. */
    public static function forSettings(DrupalSettings $settings): self
    {
        return new self(null, $settings->typeMap);
    }

    /** @return array<string,mixed> */
    private static function parse(string $path, string $what): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Drupal {$what} not found: {$path}");
        }
        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException("Malformed Drupal {$what} {$path}: " . $e->getMessage(), 0, $e);
        }
        if (null === $parsed) {
            $parsed = []; // an empty or comment-only file
        }
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw new \RuntimeException("Malformed Drupal {$what} {$path}: the file must be a map of sections (" . implode(', ', self::SECTIONS) . ').');
        }

        return $parsed;
    }

    /**
     * The one rule set for the bundled map and for an overlay. The bundled map
     * needs `kit_types` and `storage_types`; an overlay may carry any section.
     *
     * @param array<string,mixed> $map
     */
    private static function validate(array $map, string $source, bool $bundled): void
    {
        $label = $bundled ? 'type map' : 'type map overlay (drupal.type_map)';
        $fail = static fn (string $why): \RuntimeException => new \RuntimeException("Malformed Drupal {$label} {$source}: {$why}");

        $unknown = array_diff(array_map('strval', array_keys($map)), self::SECTIONS);
        if ([] !== $unknown) {
            throw $fail('unknown section(s) ' . implode(', ', $unknown) . ' — expected: ' . implode(', ', self::SECTIONS) . '.');
        }
        if ($bundled) {
            foreach (['kit_types', 'storage_types'] as $required) {
                if (!is_array($map[$required] ?? null)) {
                    throw $fail("needs kit_types and storage_types ({$required} is missing).");
                }
            }
        }
        foreach ($map as $section => $value) {
            if (!is_array($value) || ([] !== $value && array_is_list($value))) {
                throw $fail("{$section} must be a map.");
            }
        }

        foreach ((array) ($map['kit_types'] ?? []) as $key => $types) {
            if (!is_array($types) || !array_is_list($types) || [] !== array_filter($types, static fn (mixed $t): bool => !is_string($t) || '' === $t)) {
                throw $fail("kit_types.{$key} must be a list of storage type names.");
            }
        }
        foreach ((array) ($map['storage_types'] ?? []) as $type => $entry) {
            if (!is_array($entry) || !is_string($entry['module'] ?? null) || '' === $entry['module']) {
                throw $fail("storage_types.{$type} needs a `module` (core for a core field type).");
            }
            foreach (['widget', 'formatter'] as $plugin) {
                if (!is_array($entry[$plugin] ?? null) || !is_string($entry[$plugin]['type'] ?? null) || '' === $entry[$plugin]['type']) {
                    throw $fail("storage_types.{$type} needs a {$plugin} with a `type`.");
                }
            }
            foreach (['storage_settings', 'instance_settings', 'part_types'] as $mapKey) {
                if (isset($entry[$mapKey]) && !is_array($entry[$mapKey])) {
                    throw $fail("storage_types.{$type}.{$mapKey} must be a map.");
                }
            }
            foreach ((array) ($entry['part_types'] ?? []) as $subfield => $kinds) {
                if (!is_array($kinds) || !array_is_list($kinds) || [] === $kinds || [] !== array_filter($kinds, static fn (mixed $k): bool => !is_string($k) || '' === $k)) {
                    throw $fail("storage_types.{$type}.part_types.{$subfield} must be a list of kit types.");
                }
            }
            if (isset($entry['part_widgets']) && (!is_array($entry['part_widgets']) || array_is_list($entry['part_widgets']) || [] !== array_filter($entry['part_widgets'], static fn (mixed $w): bool => !is_string($w) || '' === $w))) {
                throw $fail("storage_types.{$type}.part_widgets must be a map of subfield type => widget type.");
            }
            foreach (['widget', 'formatter'] as $plugin) {
                if (isset($entry[$plugin]['settings']) && !is_array($entry[$plugin]['settings'])) {
                    throw $fail("storage_types.{$type}.{$plugin}.settings must be a map.");
                }
            }
            if (isset($entry['parts']) && (!is_array($entry['parts']) || !array_is_list($entry['parts']) || [] !== array_filter($entry['parts'], static fn (mixed $p): bool => !is_string($p) || '' === $p))) {
                throw $fail("storage_types.{$type}.parts must be a list of names.");
            }
        }
        foreach (['widget_modules', 'formatter_modules'] as $section) {
            foreach ((array) ($map[$section] ?? []) as $plugin => $module) {
                if (!is_string($module) || '' === $module) {
                    throw $fail("{$section}.{$plugin} must name a module.");
                }
            }
        }
        if (isset($map['media_reference'])) {
            foreach (['widget', 'formatter'] as $plugin) {
                $entry = $map['media_reference'][$plugin] ?? null;
                if (!is_array($entry) || !is_string($entry['type'] ?? null) || '' === $entry['type'] || (isset($entry['settings']) && !is_array($entry['settings']))) {
                    throw $fail("media_reference needs a {$plugin} with a `type` and a map of `settings`.");
                }
            }
        }
        foreach ((array) ($map['target_types'] ?? []) as $target => $entry) {
            if (!is_array($entry) || !is_string($entry['module'] ?? null) || '' === $entry['module']) {
                throw $fail("target_types.{$target} needs a `module`.");
            }
            if (isset($entry['bundle_config']) && !is_string($entry['bundle_config'])) {
                throw $fail("target_types.{$target}.bundle_config must be a config prefix or null.");
            }
        }
    }

    /**
     * Every storage type a `kit_types` list names must exist in
     * `storage_types`, or the generator could not create it.
     *
     * @param array<string,mixed> $map
     */
    private static function validateKitTypes(array $map, string $source): void
    {
        foreach ((array) ($map['kit_types'] ?? []) as $key => $types) {
            foreach ((array) $types as $type) {
                if (!isset($map['storage_types'][$type])) {
                    throw new \RuntimeException("Malformed Drupal type map overlay (drupal.type_map) {$source}: kit_types.{$key} names storage type '{$type}', which no storage_types entry defines.");
                }
            }
        }
        // A structured value is one item (`object`) or several (`list`); the
        // migration picks by cardinality, so a type must be listed under both.
        $object = (array) ($map['kit_types']['object'] ?? []);
        $list = (array) ($map['kit_types']['list'] ?? []);
        foreach (array_unique([...array_diff($object, $list), ...array_diff($list, $object)]) as $type) {
            $has = in_array($type, $object, true) ? 'object' : 'list';
            throw new \RuntimeException("Malformed Drupal type map overlay (drupal.type_map) {$source}: storage type '{$type}' is listed under kit_types.{$has} only — list it under both object and list, because the migration picks one by cardinality.");
        }
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    private static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $section => $entries) {
            if ('kit_types' === $section) {
                foreach ((array) $entries as $key => $types) {
                    $base['kit_types'][$key] = array_values(array_unique([...(array) ($base['kit_types'][$key] ?? []), ...(array) $types]));
                }
                continue;
            }
            $base[$section] = 'media_reference' === $section ? $entries : array_replace((array) ($base[$section] ?? []), (array) $entries);
        }

        return $base;
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
        if ('entity_reference' !== $drupalType && !$this->isReference($drupalType)) {
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
            throw new \DomainException("The Drupal type map has no storage type '{$storageType}'. Map it in a type map overlay: set drupal.type_map in definition-kit.yaml (README, Drupal paragraphs).");
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
     * The kit type keys that accept a storage type, in map order. Empty for a
     * storage type no kit type lists.
     *
     * @return list<string>
     */
    public function kitKeysFor(string $storageType): array
    {
        $keys = [];
        foreach ($this->map['kit_types'] as $key => $types) {
            if (is_array($types) && in_array($storageType, $types, true)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /** True for an entity reference type: `entity_reference` and a storage type listed under the kit type `reference`. */
    public function isReference(string $storageType): bool
    {
        return 'entity_reference' === $storageType || in_array('reference', $this->kitKeysFor($storageType), true);
    }

    /**
     * The named values one item of a storage type holds (double_field:
     * first, second). Empty for a type with one value.
     *
     * @return list<string>
     */
    public function parts(string $storageType): array
    {
        $parts = $this->map['storage_types'][$storageType]['parts'] ?? [];

        return is_array($parts) ? array_values(array_map('strval', $parts)) : [];
    }

    /**
     * The kit types a part of this subfield storage type accepts, the one the
     * migration writes first.
     *
     * @return list<string>
     */
    public function partKitTypes(string $storageType, string $subfieldType): array
    {
        $types = $this->map['storage_types'][$storageType]['part_types'][$subfieldType] ?? [];

        return is_array($types) ? array_values(array_map('strval', $types)) : [];
    }

    /**
     * The subfield storage type to create for a part of this kit type, or null
     * when none fits. The first `part_types` entry that lists the kit type
     * wins, so the order of `part_types` in the map decides (`number` gives
     * `float` because `float` comes before `integer`).
     */
    public function partStorageType(string $storageType, string $kitType): ?string
    {
        $partTypes = $this->map['storage_types'][$storageType]['part_types'] ?? [];
        foreach (is_array($partTypes) ? $partTypes : [] as $subfield => $kitTypes) {
            if (is_array($kitTypes) && in_array($kitType, $kitTypes, true)) {
                return (string) $subfield;
            }
        }

        return null;
    }

    /**
     * The sub-widget for a part of a new field: by the list flag first, then
     * by the subfield storage type, then the `default` entry of `part_widgets`.
     */
    public function partWidget(string $storageType, string $subfieldType, bool $list): string
    {
        $widgets = $this->map['storage_types'][$storageType]['part_widgets'] ?? [];
        $widgets = is_array($widgets) ? $widgets : [];
        $widget = $list ? ($widgets['list'] ?? null) : null;
        $widget ??= $widgets[$subfieldType] ?? $widgets['default'] ?? 'textfield';

        return (string) $widget;
    }

    /**
     * True when a `list`, `object` or `flexible_content` field is stored as
     * nested paragraphs. A `list` or `object` pinned to a structured contrib
     * storage (double_field, address) holds its values in the field itself.
     *
     * @param array<string,mixed> $field
     */
    public function nestsParagraphs(array $field): bool
    {
        if (!in_array(StructuralType::canonical((string) ($field['type'] ?? '')), [StructuralType::LIST, StructuralType::OBJECT, 'flexible_content'], true)) {
            return false;
        }

        return 'entity_reference_revisions' === ($this->accepted($field)[0] ?? null);
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
