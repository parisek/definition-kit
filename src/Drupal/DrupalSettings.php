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

    private const KEYS = ['field_naming', 'bundle_aliases', 'bundles_without_component', 'ignore_fields'];

    /**
     * @param array<string,string> $bundleAliases bundle => component
     * @param list<string> $bundlesWithoutComponent
     * @param list<string> $ignoreFields
     */
    public function __construct(
        public readonly string $fieldNaming = self::NAMING_GENERIC,
        public readonly array $bundleAliases = [],
        public readonly array $bundlesWithoutComponent = [],
        public readonly array $ignoreFields = [],
        public readonly ?string $path = null,
    ) {
        if (!in_array($fieldNaming, [self::NAMING_GENERIC, self::NAMING_PREFIXED], true)) {
            throw new \RuntimeException(sprintf(
                "Invalid drupal.field_naming '%s'%s — expected generic|prefixed.",
                $fieldNaming,
                null !== $path ? " in {$path}" : '',
            ));
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

        return new self(
            fieldNaming: $naming,
            bundleAliases: $bundleAliases,
            bundlesWithoutComponent: self::stringList($section, 'bundles_without_component', $where),
            ignoreFields: self::stringList($section, 'ignore_fields', $where),
            path: $path,
        );
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
    private static function stringList(array $section, string $key, string $where): array
    {
        $value = $section[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("drupal.{$key}{$where} must be a list.");
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || '' === $item) {
                throw new \RuntimeException("drupal.{$key}{$where} must list non-empty strings.");
            }
            $list[] = $item;
        }

        return $list;
    }
}
