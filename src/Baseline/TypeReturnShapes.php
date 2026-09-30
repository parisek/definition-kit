<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Baseline;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the return-shape table (schemas/type-return-shapes.yaml) and answers
 * "which keys can a template read below this declared field?" (issue #43).
 *
 * The contract check stops at a declared leaf, so a typo below it is accepted.
 * This table lets it look one level further for the types whose return shape
 * is known. A type with no entry returns null and keeps the old behaviour.
 *
 * ## Projects override it
 *
 * The shipped file describes parisek/timber-kit's `formatFile()`. A project on
 * another framework, or one ahead of the shipped table, states its own:
 * `discoverFor()` looks for `type-return-shapes.yaml` next to the components
 * root, then one level up. A project file REPLACES the shipped one, as
 * framework-props-baseline.yaml does, so a project can read the whole table
 * off one page. A type it does not list is unchecked, which is how a project
 * switches the check off for a type.
 */
final class TypeReturnShapes
{
    /** @var array<string,array<string,list<string>>> type => kind => keys */
    private array $shapes = [];

    /**
     * The table governing a components root: the project's own if it has one,
     * otherwise the shipped timber-kit table.
     *
     * @return array{shapes: self, path: ?string}
     */
    public static function discoverFor(string $componentsRoot): array
    {
        $componentsRoot = rtrim($componentsRoot, '/');

        foreach ([$componentsRoot, dirname($componentsRoot)] as $directory) {
            $candidate = $directory . '/type-return-shapes.yaml';
            if (is_file($candidate)) {
                return ['shapes' => new self($candidate), 'path' => $candidate];
            }
        }

        return ['shapes' => new self(), 'path' => null];
    }

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/type-return-shapes.yaml';
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            throw new \RuntimeException("Malformed type return shapes: {$path}");
        }

        foreach ($parsed as $type => $kinds) {
            if (!is_array($kinds)) {
                continue;
            }
            foreach ($kinds as $kind => $keys) {
                if (is_array($keys) && [] !== $keys) {
                    $this->shapes[(string) $type][(string) $kind] = array_values(array_map(strval(...), $keys));
                }
            }
        }
    }

    /**
     * The keys a declared field returns, or null when the table has no entry
     * (the caller must then accept everything below the leaf).
     *
     * @param array<string,mixed> $field
     * @return list<string>|null
     */
    public function returnsFor(array $field): ?array
    {
        $type = $field['type'] ?? null;
        $kind = $field['kind'] ?? null;
        if (!is_string($type) || !is_string($kind)) {
            return null;
        }

        return $this->shapes[$type][$kind] ?? null;
    }
}
