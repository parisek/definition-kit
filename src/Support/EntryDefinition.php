<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

/**
 * Tells a styleguide page or doc from a component, and says what each may carry.
 *
 * The rule is the directory, the same one parisek/styleguide uses to type an
 * entry: a definition directory below a `page` directory is a page, one below
 * a `doc` directory is a doc. The styleguide walks each tree recursively, so
 * `page/<id>/`, `page/_partials/` and `doc/<group>/<id>/` all count. The
 * nearest `page`, `doc` or `component` ancestor decides. The `$schema` comment
 * line is not the rule. It is an editor hint, authors omit it or point it at
 * a stale path, and a YAML parser never sees it.
 *
 * Neither type has a CMS projection (no acf.json, block.json or component.yml)
 * or an input contract, so the projection commands skip both.
 */
final class EntryDefinition
{
    /** The entry types with their own schema, `schemas/<type>.schema.json`. */
    public const TYPES = ['page', 'doc'];

    private const COMPONENT_ONLY_KEYS = ['fields', 'kind', 'wp', 'key', 'mcp'];

    /**
     * Keys a doc does not carry beyond the component-only ones. The styleguide
     * forces `responsive: false` for a doc and forwards `render` only for a
     * component. The SPA hides the usage panel and the link bar on a doc
     * route, and files docs in one flat sidebar group without `category`.
     */
    private const DOC_ONLY_REFUSED_KEYS = ['render', 'responsive', 'usage', 'category', 'web', 'asana', 'figma', 'drupal'];

    /** @return 'page'|'doc'|null */
    public static function typeOfDirectory(string $dir): ?string
    {
        // A mistyped path is not an entry. Classifying it by name alone made
        // the projection commands report a clean SKIP for a missing directory.
        $real = realpath(rtrim($dir, '/'));

        if (false === $real || !is_dir($real)) {
            return null;
        }

        for ($dir = \dirname($real); \dirname($dir) !== $dir; $dir = \dirname($dir)) {
            $name = basename($dir);
            if ('page' === $name || 'doc' === $name) {
                return $name;
            }
            if ('component' === $name) {
                return null;
            }
        }

        return null;
    }

    /** @return 'page'|'doc'|null */
    public static function typeOfYaml(string $path): ?string
    {
        // The definition is the YAML the styleguide pairs with a template:
        // `<dir>/<id>.yaml` next to `<dir>/<id>.twig`, or `<type>/<id>/<id>.yaml`.
        // A sidecar such as `fixtures.yaml` is neither.
        $dir = \dirname($path);
        $id = basename($path, '.yaml');

        if (!str_ends_with($path, '.yaml') || ($id !== basename($dir) && !is_file("{$dir}/{$id}.twig"))) {
            return null;
        }

        return self::typeOfDirectory($dir);
    }

    /**
     * The `$schema` header for an entry YAML written into $entryDir.
     *
     * `<type>/<id>/` needs four `../` to reach the theme's vendor/ (id, type
     * root, templates, static). Each directory level between the type root
     * and $entryDir adds one, so `page/<group>/<id>/` needs five.
     */
    public static function schemaHeaderFor(string $entryDir): string
    {
        $real = realpath(rtrim($entryDir, '/'));
        $type = self::typeOfDirectory($entryDir);
        if (false === $real || null === $type) {
            throw new \InvalidArgumentException("not a page or doc directory: {$entryDir}");
        }
        $depth = 1;
        for ($dir = \dirname($real); \dirname($dir) !== $dir && $type !== basename($dir); $dir = \dirname($dir)) {
            $depth++;
        }

        return '# yaml-language-server: $schema=' . str_repeat('../', $depth + 3) . "vendor/parisek/definition-kit/schemas/{$type}.schema.json";
    }

    public static function skipReason(string $type): string
    {
        return "a {$type} has no CMS projection or input contract ({$type}.schema.json)";
    }

    /**
     * One line per refused key present, naming why it is refused.
     *
     * @param array<mixed>|object $document
     * @param 'page'|'doc' $type
     * @return list<string>
     */
    public static function refusedKeyMessages(array|object $document, string $type): array
    {
        $keys = array_keys(is_object($document) ? get_object_vars($document) : $document);
        $messages = [];
        foreach (self::COMPONENT_ONLY_KEYS as $key) {
            if (in_array($key, $keys, true)) {
                $messages[] = "`{$key}:` is a component key; a {$type} does not carry it ({$type}.schema.json)";
            }
        }
        if ('doc' === $type) {
            foreach (self::DOC_ONLY_REFUSED_KEYS as $key) {
                if (in_array($key, $keys, true)) {
                    $messages[] = "`{$key}:` has no effect on a doc in the styleguide; a doc does not carry it (doc.schema.json)";
                }
            }
        }

        return $messages;
    }
}
