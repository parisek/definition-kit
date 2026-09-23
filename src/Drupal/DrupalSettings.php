<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Parisek\DefinitionKit\Support\KeyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * The `drupal:` section of a project's `definition-kit.yaml`. Every key is
 * optional:
 *
 * ```yaml
 * drupal:
 *   field_naming: generic          # generic (default) | prefixed
 *   bundle_aliases:                # paragraph bundle => component directory
 *     html: content
 *   bundles_without_component:     # bundles that render no component on purpose
 *     - from_library
 *   ignore_fields:                 # framework fields present on many bundles
 *     - field_wrapper_id
 *   # Generator only (fields-generate --target=drupal, ADR 0002):
 *   langcode: cs                   # langcode of new config entities (default en)
 *   text_format: basic             # allowed format of a new richtext instance
 *   media_bundles:                 # media kind => media types of a new media field
 *     image: [image]
 *   host_fields:                   # fields that get each new top-level paragraph type as a target
 *     - field.field.node.page.field_paragraphs
 *   translation: true              # new bundles get language.content_settings (content_translation)
 *   view_display: hidden           # where a new field goes on the view display: content (default) | hidden
 *   field_config_cardinality: true # an instance may narrow its storage's cardinality (contrib module)
 *   baseline: drupal-baseline.yaml # merged over schemas/drupal-defaults-baseline.yaml, relative to this file
 * ```
 *
 * Discovery matches {@see KeyStyle::discoverFor()}: next to the components
 * root, then one directory up. A file without a `drupal:` key is skipped, so a
 * nearer file that only sets `key_style` does not mask a `drupal:` section one
 * level up. An unknown key or a wrong value type throws and names the file:
 * a silent fallback would change what the lint reports without any sign why.
 */
final class DrupalSettings
{
    public const NAMING_GENERIC = 'generic';
    public const NAMING_PREFIXED = 'prefixed';

    public const VIEW_CONTENT = 'content';
    public const VIEW_HIDDEN = 'hidden';

    private const KEYS = [
        'field_naming',
        'bundle_aliases',
        'bundles_without_component',
        'ignore_fields',
        'langcode',
        'text_format',
        'media_bundles',
        'host_fields',
        'translation',
        'view_display',
        'field_config_cardinality',
        'baseline',
    ];

    /**
     * @param array<string,string> $bundleAliases bundle => component
     * @param list<string> $bundlesWithoutComponent
     * @param list<string> $ignoreFields
     * @param array<string,list<string>> $mediaBundles media kind => media types
     * @param list<string> $hostFields config names of fields that host top-level paragraph types
     * @param string|null $baseline absolute path of the project baseline file
     */
    public function __construct(
        public readonly string $fieldNaming = self::NAMING_GENERIC,
        public readonly array $bundleAliases = [],
        public readonly array $bundlesWithoutComponent = [],
        public readonly array $ignoreFields = [],
        public readonly ?string $path = null,
        public readonly string $langcode = 'en',
        public readonly ?string $textFormat = null,
        public readonly array $mediaBundles = [],
        public readonly array $hostFields = [],
        public readonly bool $translation = false,
        public readonly string $viewDisplay = self::VIEW_CONTENT,
        public readonly bool $fieldConfigCardinality = false,
        public readonly ?string $baseline = null,
    ) {
        $where = null !== $path ? " in {$path}" : '';
        if (!in_array($fieldNaming, [self::NAMING_GENERIC, self::NAMING_PREFIXED], true)) {
            throw new \RuntimeException(sprintf(
                "Invalid drupal.field_naming '%s'%s — expected generic|prefixed.",
                $fieldNaming,
                $where,
            ));
        }
        if (!in_array($viewDisplay, [self::VIEW_CONTENT, self::VIEW_HIDDEN], true)) {
            throw new \RuntimeException("Invalid drupal.view_display '{$viewDisplay}'{$where} — expected content|hidden.");
        }
        foreach ($hostFields as $hostField) {
            if (1 !== preg_match('/^field\.field\.[a-z0-9_]+\.[a-z0-9_]+\.[a-z0-9_]+$/', $hostField)) {
                throw new \RuntimeException("drupal.host_fields{$where}: '{$hostField}' is not a field.field.<entity>.<bundle>.<field> config name.");
            }
        }
    }

    public static function discoverFor(string $componentsRoot): self
    {
        $componentsRoot = rtrim($componentsRoot, '/');
        foreach ([$componentsRoot, \dirname($componentsRoot)] as $directory) {
            $candidate = $directory . '/' . KeyStyle::CONFIG_FILENAME;
            if (!is_file($candidate)) {
                continue;
            }
            $parsed = Yaml::parseFile($candidate);
            if (!is_array($parsed) || !array_key_exists('drupal', $parsed)) {
                continue;
            }

            return self::fromArray($parsed['drupal'], $candidate);
        }

        return new self();
    }

    public static function fromArray(mixed $section, ?string $path = null): self
    {
        $where = null !== $path ? " in {$path}" : '';
        if (null === $section) {
            return new self(path: $path);
        }
        if (!is_array($section) || array_is_list($section) && [] !== $section) {
            throw new \RuntimeException("drupal:{$where} must be a map.");
        }
        $unknown = array_diff(array_map('strval', array_keys($section)), self::KEYS);
        if ([] !== $unknown) {
            throw new \RuntimeException(sprintf(
                'Unknown drupal: key(s)%s: %s — expected: %s.',
                $where,
                implode(', ', $unknown),
                implode(', ', self::KEYS),
            ));
        }

        $naming = $section['field_naming'] ?? self::NAMING_GENERIC;
        if (!is_string($naming)) {
            throw new \RuntimeException("drupal.field_naming{$where} must be a string.");
        }

        $aliases = $section['bundle_aliases'] ?? [];
        if (!is_array($aliases) || (array_is_list($aliases) && [] !== $aliases)) {
            throw new \RuntimeException("drupal.bundle_aliases{$where} must be a map of bundle => component.");
        }
        $bundleAliases = [];
        foreach ($aliases as $bundle => $component) {
            if (!is_string($component) || '' === $component) {
                throw new \RuntimeException("drupal.bundle_aliases.{$bundle}{$where} must name a component directory.");
            }
            $bundleAliases[(string) $bundle] = $component;
        }

        $mediaBundles = [];
        $media = $section['media_bundles'] ?? [];
        if (!is_array($media) || (array_is_list($media) && [] !== $media)) {
            throw new \RuntimeException("drupal.media_bundles{$where} must be a map of media kind => media types.");
        }
        foreach ($media as $kind => $types) {
            $mediaBundles[(string) $kind] = self::stringList($media, (string) $kind, $where, 'media_bundles.');
        }

        $baseline = self::optionalString($section, 'baseline', $where);
        if (null !== $baseline && !str_starts_with($baseline, '/')) {
            $baseline = (null !== $path ? \dirname($path) : (string) getcwd()) . '/' . $baseline;
        }

        return new self(
            fieldNaming: $naming,
            bundleAliases: $bundleAliases,
            bundlesWithoutComponent: self::stringList($section, 'bundles_without_component', $where),
            ignoreFields: self::stringList($section, 'ignore_fields', $where),
            path: $path,
            langcode: self::optionalString($section, 'langcode', $where) ?? 'en',
            textFormat: self::optionalString($section, 'text_format', $where),
            mediaBundles: $mediaBundles,
            hostFields: self::stringList($section, 'host_fields', $where),
            translation: self::optionalBool($section, 'translation', $where) ?? false,
            viewDisplay: self::optionalString($section, 'view_display', $where) ?? self::VIEW_CONTENT,
            fieldConfigCardinality: self::optionalBool($section, 'field_config_cardinality', $where) ?? false,
            baseline: $baseline,
        );
    }

    /** @param array<mixed> $section */
    private static function optionalString(array $section, string $key, string $where): ?string
    {
        $value = $section[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value) {
            throw new \RuntimeException("drupal.{$key}{$where} must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<mixed> $section */
    private static function optionalBool(array $section, string $key, string $where): ?bool
    {
        $value = $section[$key] ?? null;
        if (null !== $value && !is_bool($value)) {
            throw new \RuntimeException("drupal.{$key}{$where} must be true or false.");
        }

        return $value;
    }

    public function ignores(string $fieldName): bool
    {
        return in_array($fieldName, $this->ignoreFields, true);
    }

    /**
     * The Drupal field machine name a definition field maps to when it
     * carries no `drupal.field` pin. `generic` shares storage across bundles
     * (`field_<leaf>`); `prefixed` is the per-bundle storage of tailwind-base
     * ADR-0005 (`field_<bundle>_<leaf>`).
     */
    public function conventionalFieldName(string $leaf, string $bundle): string
    {
        return self::NAMING_PREFIXED === $this->fieldNaming
            ? "field_{$bundle}_{$leaf}"
            : "field_{$leaf}";
    }

    /**
     * The definition field name a Drupal field gets when nothing better is
     * known — the inverse of {@see conventionalFieldName()}.
     */
    public function leafName(string $fieldName, string $bundle): string
    {
        $prefix = self::NAMING_PREFIXED === $this->fieldNaming ? "field_{$bundle}_" : 'field_';
        if (str_starts_with($fieldName, $prefix) && strlen($fieldName) > strlen($prefix)) {
            return substr($fieldName, strlen($prefix));
        }
        if (str_starts_with($fieldName, 'field_') && strlen($fieldName) > 6) {
            return substr($fieldName, 6);
        }

        return $fieldName;
    }

    /**
     * @param array<mixed> $section
     * @return list<string>
     */
    private static function stringList(array $section, string $key, string $where, string $prefix = ''): array
    {
        $value = $section[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("drupal.{$prefix}{$key}{$where} must be a list.");
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || '' === $item) {
                throw new \RuntimeException("drupal.{$prefix}{$key}{$where} must list non-empty strings.");
            }
            $list[] = $item;
        }

        return $list;
    }
}
