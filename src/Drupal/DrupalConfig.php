<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Symfony\Component\Yaml\Yaml;

/**
 * The paragraph part of a Drupal config export (`drush config:export`), read
 * from a directory the caller names. The directory is always an argument:
 * a project's committed `config/sync` can lag its live database, and a
 * fresh export into another directory must be lintable without a code change.
 *
 * Files read (all others are ignored):
 *
 *   paragraphs.paragraphs_type.<bundle>.yml          the bundle exists
 *   field.storage.paragraph.<name>.yml               storage type, cardinality, storage settings
 *   field.field.paragraph.<bundle>.<name>.yml        the instance: label, required, instance settings
 *   core.entity_form_display.paragraph.<bundle>.default.yml   form presence and field_group groups
 *
 * Effective cardinality honours the `field_config_cardinality` contrib
 * module: its per-instance `cardinality_config` overrides the storage value.
 */
final class DrupalConfig
{
    /**
     * @param array<string,array{label: string, description: string}> $bundles
     * @param array<string,array<string,DrupalField>> $fields bundle => field name => field
     * @param array<string,array<string,array{label: string, children: list<string>}>> $groups bundle => group name => group
     */
    private function __construct(
        private readonly string $directory,
        private readonly array $bundles,
        private readonly array $fields,
        private readonly array $groups,
    ) {
    }

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory)) {
            throw new \RuntimeException("Drupal config directory not found: {$directory}");
        }

        $bundles = [];
        foreach (self::glob($directory, 'paragraphs.paragraphs_type.*.yml') as $path) {
            $data = self::parse($path);
            $id = (string) ($data['id'] ?? self::suffix($path, 'paragraphs.paragraphs_type.'));
            $bundles[$id] = [
                'label' => (string) ($data['label'] ?? $id),
                'description' => (string) ($data['description'] ?? ''),
            ];
        }
        if ([] === $bundles) {
            throw new \RuntimeException(
                "No paragraphs.paragraphs_type.*.yml in {$directory} — is this a Drupal config export?"
            );
        }
        ksort($bundles);

        $storages = [];
        foreach (self::glob($directory, 'field.storage.paragraph.*.yml') as $path) {
            $data = self::parse($path);
            $name = (string) ($data['field_name'] ?? self::suffix($path, 'field.storage.paragraph.'));
            $storages[$name] = $data;
        }

        $forms = [];
        $groups = [];
        foreach (self::glob($directory, 'core.entity_form_display.paragraph.*.default.yml') as $path) {
            $data = self::parse($path);
            $bundle = (string) ($data['bundle'] ?? '');
            if ('' === $bundle) {
                continue;
            }
            $forms[$bundle] = $data;
            $fieldGroups = $data['third_party_settings']['field_group'] ?? [];
            foreach (is_array($fieldGroups) ? $fieldGroups : [] as $groupName => $group) {
                if (!is_array($group)) {
                    continue;
                }
                $groups[$bundle][(string) $groupName] = [
                    'label' => (string) ($group['label'] ?? $groupName),
                    'children' => array_values(array_map('strval', (array) ($group['children'] ?? []))),
                ];
            }
        }

        $fields = [];
        foreach (self::glob($directory, 'field.field.paragraph.*.yml') as $path) {
            $data = self::parse($path);
            $bundle = (string) ($data['bundle'] ?? '');
            $name = (string) ($data['field_name'] ?? '');
            if ('' === $bundle || '' === $name) {
                continue;
            }
            $storage = $storages[$name] ?? [];
            $type = (string) ($data['field_type'] ?? $storage['type'] ?? '');

            $cardinality = self::effectiveCardinality($storage, $data);

            $settings = array_replace(
                is_array($storage['settings'] ?? null) ? $storage['settings'] : [],
                is_array($data['settings'] ?? null) ? $data['settings'] : [],
            );

            $onForm = null;
            $weight = null;
            $widget = null;
            if (isset($forms[$bundle])) {
                $content = (array) ($forms[$bundle]['content'] ?? []);
                $onForm = array_key_exists($name, $content);
                if ($onForm && is_array($content[$name])) {
                    $weight = isset($content[$name]['weight']) ? (int) $content[$name]['weight'] : null;
                    $widget = isset($content[$name]['type']) ? (string) $content[$name]['type'] : null;
                }
            }

            $fields[$bundle][$name] = new DrupalField(
                bundle: $bundle,
                name: $name,
                type: $type,
                label: (string) ($data['label'] ?? $name),
                description: (string) ($data['description'] ?? ''),
                required: true === ($data['required'] ?? false),
                cardinality: $cardinality,
                settings: $settings,
                onForm: $onForm,
                formWeight: $weight,
                widget: $widget,
            );
        }

        return new self($directory, $bundles, $fields, $groups);
    }

    /**
     * The cardinality an instance really has: the storage value, unless the
     * `field_config_cardinality` contrib module narrows it on the instance.
     *
     * @param array<mixed> $storage field.storage.* config
     * @param array<mixed> $instance field.field.* config
     */
    public static function effectiveCardinality(array $storage, array $instance): int
    {
        $cardinality = (int) ($storage['cardinality'] ?? 1);
        $override = self::cardinalityOverride($instance);

        return $override ?? $cardinality;
    }

    /**
     * The `field_config_cardinality` override on an instance, or null when
     * it has none.
     *
     * @param array<mixed> $instance
     */
    public static function cardinalityOverride(array $instance): ?int
    {
        $override = $instance['third_party_settings']['field_config_cardinality']['cardinality_config'] ?? null;
        if (is_scalar($override) && '' !== (string) $override && is_numeric((string) $override)) {
            return (int) $override;
        }

        return null;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return list<string> */
    public function bundles(): array
    {
        return array_keys($this->bundles);
    }

    public function hasBundle(string $bundle): bool
    {
        return isset($this->bundles[$bundle]);
    }

    public function bundleLabel(string $bundle): string
    {
        return $this->bundles[$bundle]['label'] ?? $bundle;
    }

    /**
     * Fields of a bundle in editor order: form weight first (fields without a
     * weight last), then machine name.
     *
     * @return array<string,DrupalField>
     */
    public function fields(string $bundle): array
    {
        $fields = $this->fields[$bundle] ?? [];
        uasort($fields, static function (DrupalField $a, DrupalField $b): int {
            $wa = $a->formWeight ?? PHP_INT_MAX;
            $wb = $b->formWeight ?? PHP_INT_MAX;

            return [$wa, $a->name] <=> [$wb, $b->name];
        });

        return $fields;
    }

    /**
     * field_group groups on the bundle's default form display.
     *
     * @return array<string,array{label: string, children: list<string>}>
     */
    public function groups(string $bundle): array
    {
        return $this->groups[$bundle] ?? [];
    }

    /** @return list<string> */
    private static function glob(string $directory, string $pattern): array
    {
        $paths = glob($directory . '/' . $pattern) ?: [];
        sort($paths);

        return $paths;
    }

    /** @return array<string,mixed> */
    private static function parse(string $path): array
    {
        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            throw new \RuntimeException("Not a YAML map: {$path}");
        }

        return $data;
    }

    private static function suffix(string $path, string $prefix): string
    {
        return substr(basename($path, '.yml'), strlen($prefix));
    }
}
