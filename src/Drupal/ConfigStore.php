<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Symfony\Component\Yaml\Yaml;

/**
 * Raw config entities of a `drush config:export` directory, by config name
 * (`field.storage.paragraph.field_title`, without `.yml`), parsed on first
 * use. The generator (ADR 0002) reads and merges whole entities, so it needs
 * every key, not the lint's view of them ({@see DrupalConfig}).
 *
 * An empty directory is valid: generation into a fresh export creates
 * everything.
 */
final class ConfigStore
{
    /** @var array<string,array<string,mixed>|null> name => parsed entity, null when the file does not exist */
    private array $cache = [];

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("Drupal config directory not found: {$directory}");
        }
    }

    public function directory(): string
    {
        return rtrim($this->directory, '/');
    }

    public function path(string $name): string
    {
        return $this->directory() . '/' . $name . '.yml';
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array
    {
        if (!array_key_exists($name, $this->cache)) {
            $path = $this->path($name);
            if (!is_file($path)) {
                $this->cache[$name] = null;
            } else {
                $data = Yaml::parseFile($path);
                if (!is_array($data)) {
                    throw new \RuntimeException("Not a YAML map: {$path}");
                }
                $this->cache[$name] = $data;
            }
        }

        return $this->cache[$name];
    }

    /**
     * Config names that start with a prefix, sorted.
     *
     * @return list<string>
     */
    public function names(string $prefix): array
    {
        $names = [];
        foreach (glob($this->directory() . '/' . $prefix . '*.yml') ?: [] as $path) {
            $names[] = basename($path, '.yml');
        }
        sort($names);

        return $names;
    }
}
