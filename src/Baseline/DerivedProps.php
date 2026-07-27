<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Baseline;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the framework-derived props table (schemas/derived-props-baseline.yaml)
 * — enrichment the field-formatting layer builds out of a sibling field
 * (issue #27, phase 4).
 *
 * Unlike FrameworkProps, this table suppresses nothing. It only lets
 * `fields-roles` propose `role: derived` with a `from:` that names a sibling
 * the linter can then verify — a checkable claim, not an exemption.
 */
final class DerivedProps
{
    /** @var array<string,string> prop name => the abstract type of the sibling it comes from */
    private array $table;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/derived-props-baseline.yaml';
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            throw new \RuntimeException("Malformed derived props baseline: {$path}");
        }

        $this->table = [];
        foreach ($parsed as $prop => $entry) {
            if (is_array($entry) && isset($entry['from_type']) && is_string($entry['from_type'])) {
                $this->table[(string) $prop] = $entry['from_type'];
            }
        }
    }

    /**
     * The sibling a prop is derived from, or null when nothing in the table
     * explains it.
     *
     * @param array<string,mixed> $siblings the `fields:` map the prop would live in
     */
    public function originOf(string $prop, array $siblings): ?string
    {
        $fromType = $this->table[$prop] ?? null;
        if (null === $fromType) {
            return null;
        }

        foreach ($siblings as $name => $field) {
            if (is_array($field) && ($field['type'] ?? null) === $fromType) {
                return (string) $name;
            }
        }

        // The table knows this prop, but this component has no field it could
        // have been built from. Proposing `derived` anyway would write a
        // `from:` that dangles — exactly the assertion the linter exists to
        // reject. Leave it for a human.
        return null;
    }
}
