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

    private const OVERLAY_LABEL = 'type map overlay (drupal.type_map)';

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
            self::validate($parsed, $path, 'type map', true);
            self::$cache[$path] = $parsed;
        }
        $map = self::$cache[$path];
        if (null !== $overlay) {
            $over = self::parse($overlay, self::OVERLAY_LABEL);
            self::validate($over, $overlay, self::OVERLAY_LABEL, false);
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
            // The merged map must be as complete as the bundled one.
            self::validate($map, $overlay, self::OVERLAY_LABEL, true);
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
     * The one rule set for the bundled map, an overlay and the merged result.
     * A complete map (the bundled one, the merged one) needs `kit_types` and
     * `storage_types`, and every storage type its `module`, `widget` and
     * `formatter`. An overlay is partial: it may carry any section, and a
     * `storage_types` entry may state only the keys it changes.
     *
     * @param array<string,mixed> $map
     */
    private static function validate(array $map, string $source, string $label, bool $complete): void
    {
        $fail = static fn (string $why): \RuntimeException => new \RuntimeException("Malformed Drupal {$label} {$source}: {$why}");

        $unknown = array_diff(array_map('strval', array_keys($map)), self::SECTIONS);
        if ([] !== $unknown) {
            throw $fail('unknown section(s) ' . implode(', ', $unknown) . ' — expected: ' . implode(', ', self::SECTIONS) . '.');
        }
        if ($complete) {
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
            if (!is_array($entry)) {
                throw $fail("storage_types.{$type} must be a map.");
            }
            if (($complete || isset($entry['module'])) && (!is_string($entry['module'] ?? null) || '' === $entry['module'])) {
                throw $fail("storage_types.{$type} needs a `module` (core for a core field type).");
            }
            foreach (['widget', 'formatter'] as $plugin) {
                $has = isset($entry[$plugin]);
                if (($complete || $has) && (!is_array($entry[$plugin] ?? null) || (($complete || isset($entry[$plugin]['type'])) && (!is_string($entry[$plugin]['type'] ?? null) || '' === $entry[$plugin]['type'])))) {
                    throw $fail("storage_types.{$type} needs a {$plugin} with a `type`.");
                }
            }
            foreach ((array) ($entry['legacy_settings'] ?? []) as $legacyKey => $rule) {
                if (!is_array($rule) || !is_string($rule['into'] ?? null) || !is_string($rule['override'] ?? null) || !is_array($rule['universe'] ?? null) || !array_is_list($rule['universe'])) {
                    throw $fail("storage_types.{$type}.legacy_settings.{$legacyKey} needs `into`, `override` and a `universe` list.");
                }
            }
            if (isset($entry['part_settings_applies']) && (!is_array($entry['part_settings_applies']) || [] !== array_filter($entry['part_settings_applies'], static fn (mixed $v): bool => !is_array($v) || !array_is_list($v)))) {
                throw $fail("storage_types.{$type}.part_settings_applies must map a setting to a list of subfield types.");
            }
            foreach (['settings_keys', 'part_settings_keys', 'checkbox_settings'] as $listKey) {
                if (isset($entry[$listKey]) && (!is_array($entry[$listKey]) || !array_is_list($entry[$listKey]) || [] !== array_filter($entry[$listKey], static fn (mixed $k): bool => !is_string($k) || '' === $k))) {
                    throw $fail("storage_types.{$type}.{$listKey} must be a list of setting names.");
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
                if (!is_array($entry) || (($complete || isset($entry['type'])) && (!is_string($entry['type'] ?? null) || '' === $entry['type'])) || (isset($entry['settings']) && !is_array($entry['settings']))) {
                    throw $fail("media_reference needs a {$plugin} with a `type` and a map of `settings`.");
                }
            }
        }
        foreach ((array) ($map['target_types'] ?? []) as $target => $entry) {
            if (!is_array($entry) || (($complete || isset($entry['module'])) && (!is_string($entry['module'] ?? null) || '' === $entry['module']))) {
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
            if (in_array($section, ['storage_types', 'target_types', 'media_reference'], true)) {
                $base[$section] = self::deepMerge((array) ($base[$section] ?? []), (array) $entries);
                continue;
            }
            $base[$section] = array_replace((array) ($base[$section] ?? []), (array) $entries);
        }

        return $base;
    }

    /**
     * Maps merge key by key, all the way down. A list, a scalar or a type
     * change replaces the value. An empty map in the overlay changes nothing.
     *
     * @param array<mixed> $base
     * @param array<mixed> $over
     * @return array<mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $isMap = static fn (mixed $v): bool => is_array($v) && ([] === $v || !array_is_list($v));
            $base[$key] = $isMap($value) && $isMap($base[$key] ?? null)
                ? self::deepMerge($base[$key], $value)
                : $value;
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

    /**
     * The settings of a storage type that change what the field does, and
     * that a definition keeps in `drupal.settings` (address: countries and
     * field overrides; language_field: language range and lists). Each key
     * has a default in the entry's `storage_settings` or `instance_settings`.
     *
     * @return list<string>
     */
    public function settingsKeys(string $storageType): array
    {
        return self::names($this->map['storage_types'][$storageType]['settings_keys'] ?? []);
    }

    /**
     * The same for the parts of a field item (double_field: the subfield
     * storage settings and the instance limits), kept in the part's
     * `drupal.settings`.
     *
     * @return list<string>
     */
    public function partSettingsKeys(string $storageType, ?string $subfield = null): array
    {
        $keys = self::names($this->map['storage_types'][$storageType]['part_settings_keys'] ?? []);
        if (null === $subfield) {
            return $keys;
        }
        $applies = $this->map['storage_types'][$storageType]['part_settings_applies'] ?? [];

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => !is_array($applies[$key] ?? null) || in_array($subfield, $applies[$key], true),
        ));
    }

    /**
     * Reads a deprecated setting as the modern one the module would use
     * (address: `fields` becomes a `hidden` override for each address field
     * it leaves out), and empties the deprecated key.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function normalizeSettings(string $storageType, array $settings): array
    {
        $rules = $this->map['storage_types'][$storageType]['legacy_settings'] ?? [];
        foreach (is_array($rules) ? $rules : [] as $legacyKey => $rule) {
            $legacy = $settings[$legacyKey] ?? null;
            if (!is_array($rule) || !is_array($legacy) || [] === $legacy) {
                continue;
            }
            $kept = array_map('strval', array_values($legacy));
            $into = [];
            foreach ((array) $rule['universe'] as $name) {
                if (!in_array((string) $name, $kept, true)) {
                    $into[(string) $name] = ['override' => (string) $rule['override']];
                }
            }
            $settings[(string) $rule['into']] = $into;
            $settings[(string) $legacyKey] = [];
        }

        return $settings;
    }

    /** @return list<string> the deprecated keys that `normalizeSettings()` empties */
    public function legacySettingKeys(string $storageType): array
    {
        $rules = $this->map['storage_types'][$storageType]['legacy_settings'] ?? [];

        return is_array($rules) ? array_map('strval', array_keys($rules)) : [];
    }

    /** @return 'storage'|'instance'|null where the generator writes a setting */
    public function settingScope(string $storageType, string $key, ?string $part = null): ?string
    {
        $entry = $this->map['storage_types'][$storageType] ?? [];
        $storage = null === $part ? ($entry['storage_settings'] ?? []) : ($entry['storage_settings']['storage'][$part] ?? []);
        $instance = null === $part ? ($entry['instance_settings'] ?? []) : ($entry['instance_settings'][$part] ?? []);
        if (is_array($instance) && array_key_exists($key, $instance)) {
            return 'instance';
        }

        return is_array($storage) && array_key_exists($key, $storage) ? 'storage' : null;
    }

    /** The default of a setting: the value of a new field. */
    public function settingDefault(string $storageType, string $key, ?string $part = null): mixed
    {
        $entry = $this->map['storage_types'][$storageType] ?? [];
        $scope = $this->settingScope($storageType, $key, $part);
        $source = 'instance' === $scope ? ($entry['instance_settings'] ?? []) : ($entry['storage_settings'] ?? []);
        if (null !== $part) {
            $source = 'instance' === $scope ? ($source[$part] ?? []) : ($source['storage'][$part] ?? []);
        }

        return is_array($source) ? ($source[$key] ?? null) : null;
    }

    /**
     * The value of each setting key as the field has it: the exported value,
     * the default for a key the export does not carry.
     *
     * @param array<string,mixed> $settings the field's storage and instance settings, merged
     * @return array<string,mixed>
     */
    public function actualSettings(string $storageType, array $settings, ?string $part = null): array
    {
        $values = [];
        $subfield = null !== $part && is_string($settings['storage'][$part]['type'] ?? null) ? $settings['storage'][$part]['type'] : null;
        if (null === $part) {
            $settings = $this->normalizeSettings($storageType, $settings);
        }
        foreach (null === $part ? $this->settingsKeys($storageType) : $this->partSettingsKeys($storageType, $subfield) as $key) {
            $holder = null === $part ? $settings : (array) ('instance' === $this->settingScope($storageType, $key, $part) ? ($settings[$part] ?? []) : ($settings['storage'][$part] ?? []));
            $default = $this->settingDefault($storageType, $key, $part);
            $value = $this->selected($storageType, $key, $holder[$key] ?? $default, $part);
            // A checkbox setting that Drupal saved as 0 or 1 (a color field's `opacity`) is the boolean the module reads.
            $values[$key] = is_bool($default) && in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : $value;
        }

        return $values;
    }

    /**
     * What a definition says about each setting key: its `drupal.settings`
     * value, the default for a key it leaves out.
     *
     * @param array<mixed> $declared `drupal.settings` of the field or the part
     * @param string|null $subfield the part's subfield storage type, to leave out the keys that do not apply to it
     * @return array<string,mixed>
     */
    public function declaredSettings(string $storageType, array $declared, ?string $part = null, ?string $subfield = null): array
    {
        $values = [];
        foreach (null === $part ? $this->settingsKeys($storageType) : $this->partSettingsKeys($storageType, $subfield) as $key) {
            $values[$key] = $this->selected($storageType, $key, array_key_exists($key, $declared) ? $declared[$key] : $this->settingDefault($storageType, $key, $part), $part);
        }

        return $values;
    }

    /**
     * A `checkboxes` setting (`checkbox_settings`) keeps an entry for every
     * option and stores `0` for an unchecked one; the module reads only the
     * checked ones. This keeps the checked options, each as `option: option`
     * (the form's own value), so `{youtube: '0'}` and `[]` read the same.
     * Any other setting passes through.
     */
    private function selected(string $storageType, string $key, mixed $value, ?string $part): mixed
    {
        if (null !== $part || !is_array($value) || !in_array($key, self::names($this->map['storage_types'][$storageType]['checkbox_settings'] ?? []), true)) {
            return $value;
        }
        $kept = [];
        if (array_is_list($value)) {
            // `[youtube, vimeo]`: the options by name.
            $value = array_fill_keys(array_map('strval', $value), true);
        }
        foreach ($value as $option => $checked) {
            if (null !== $checked && false !== $checked && 0 !== $checked && '' !== $checked && '0' !== $checked) {
                $kept[(string) $option] = (string) $option;
            }
        }

        return $kept;
    }

    /**
     * The settings of a field that differ from the default, for a definition's
     * `drupal.settings`.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function residue(string $storageType, array $settings, ?string $part = null): array
    {
        $residue = [];
        foreach ($this->actualSettings($storageType, $settings, $part) as $key => $value) {
            if (!self::sameSetting($value, $this->settingDefault($storageType, $key, $part))) {
                $residue[$key] = $value;
            }
        }

        return $residue;
    }

    /**
     * Two setting values are the same when they read the same: scalars by
     * their text (`1` and `'1'`), `null`, `''` and an empty map alike, maps
     * by key without order.
     */
    public static function sameSetting(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if ([] === $value) {
                return '';
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = self::normalize($v);
            }
            ksort($out);

            return $out;
        }

        return null === $value || '' === $value ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    /** @return list<string> */
    private static function names(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
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
