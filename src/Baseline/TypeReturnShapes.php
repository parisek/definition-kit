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
 */
final class TypeReturnShapes
{
    /** @var array<string,array<string,list<string>>> type => kind => keys */
    private array $shapes = [];

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
                if (is_array($keys)) {
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
