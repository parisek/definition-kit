<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * The two structural abstract types and their aliases (issue #79).
 *
 * `object` is one nested object, `list` is a list of objects. `group` and
 * `repeater` are the older, ACF-named aliases of the same two shapes. The
 * schema accepts all four with identical keys.
 *
 * Aliases resolve to the canonical name here, once, when a definition is
 * read. Code past that point branches only on `object`/`list`. Two entry
 * points call {@see normalize()}: {@see parseFile()} for a definition read
 * from disk, and `FieldsGenerator::generate()` for a tree built in memory.
 * The call is idempotent, so a tree that passes both is unchanged.
 *
 * An ACF field type is a different vocabulary. `group` and `repeater` in
 * acf.json, in the ACF baselines and in `AbstractTypeMapper`'s input stay
 * as they are.
 */
final class StructuralType
{
    public const OBJECT = 'object';
    public const LIST = 'list';

    /** Alias => canonical name. */
    public const ALIASES = [
        'group' => self::OBJECT,
        'repeater' => self::LIST,
    ];

    public static function canonical(string $type): string
    {
        return self::ALIASES[$type] ?? $type;
    }

    /**
     * Rewrite every alias `type:` in a definition tree to its canonical name.
     * Walks `fields:` at every depth and the `fields:` of every
     * flexible-content layout. Touches no other key.
     *
     * @param array<mixed> $definition
     * @return array<mixed>
     */
    public static function normalize(array $definition): array
    {
        if (isset($definition['fields']) && is_array($definition['fields'])) {
            $definition['fields'] = self::normalizeFields($definition['fields']);
        }
        return $definition;
    }

    /**
     * Parse a definition YAML file and normalize its structural types. The
     * read point for any code that branches on a field's `type:`.
     */
    public static function parseFile(string $path): mixed
    {
        $parsed = Yaml::parseFile($path);
        return is_array($parsed) ? self::normalize($parsed) : $parsed;
    }

    /**
     * @param array<mixed> $fields
     * @return array<mixed>
     */
    private static function normalizeFields(array $fields): array
    {
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            if (isset($field['type']) && is_string($field['type'])) {
                $field['type'] = self::canonical($field['type']);
            }
            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = self::normalizeFields($field['fields']);
            }
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout)) {
                        $field['layouts'][$layoutName] = self::normalize($layout);
                    }
                }
            }
            $fields[$name] = $field;
        }
        return $fields;
    }
}
