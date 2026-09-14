<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

/**
 * Tells a styleguide page from a component, and says what a page may carry.
 *
 * The rule is the directory, the same one parisek/styleguide uses to type an
 * entry: a definition directory below a `page` directory is a page. The
 * styleguide walks the page tree recursively, so `page/<id>/`,
 * `page/_partials/` and `page/<group>/<id>/` all count. The nearest
 * `page` or `component` ancestor decides. The `$schema` comment line is not
 * the rule. It is an editor hint, authors omit it or point it at a stale
 * path, and a YAML parser never sees it.
 *
 * A page has no CMS projection (no acf.json, block.json or component.yml) and
 * no input contract, so the projection commands skip it.
 */
final class PageDefinition
{
    /** The header `fields-migrate` writes on top of `page/<id>/<id>.yaml`. */
    public const SCHEMA_HEADER = '# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json';

    /**
     * The `$schema` header for a page YAML written into $pageDir.
     *
     * `page/<id>/` needs four `../` to reach the theme's vendor/ (id, page,
     * templates, static). Each directory level between the page root and
     * $pageDir adds one, so `page/<group>/<id>/` needs five.
     */
    public static function schemaHeaderFor(string $pageDir): string
    {
        $real = realpath(rtrim($pageDir, '/'));
        $depth = 1;
        if (false !== $real) {
            for ($dir = \dirname($real); \dirname($dir) !== $dir && 'page' !== basename($dir); $dir = \dirname($dir)) {
                $depth++;
            }
        }

        return '# yaml-language-server: $schema=' . str_repeat('../', $depth + 3) . 'vendor/parisek/definition-kit/schemas/page.schema.json';
    }

    /** Component keys a page must not carry. */
    public const COMPONENT_ONLY_KEYS = ['fields', 'kind', 'wp', 'key', 'mcp'];

    public const SKIP_REASON = 'a page has no CMS projection or input contract (page.schema.json)';

    public static function isPageDirectory(string $dir): bool
    {
        // A mistyped path is not a page. Classifying it by name alone made
        // the projection commands report a clean SKIP for a missing directory.
        $real = realpath(rtrim($dir, '/'));

        if (false === $real || !is_dir($real)) {
            return false;
        }

        for ($dir = \dirname($real); \dirname($dir) !== $dir; $dir = \dirname($dir)) {
            $name = basename($dir);
            if ('page' === $name) {
                return true;
            }
            if ('component' === $name) {
                return false;
            }
        }

        return false;
    }

    public static function isPageYaml(string $path): bool
    {
        // The definition is the YAML the styleguide pairs with a template:
        // `<dir>/<id>.yaml` next to `<dir>/<id>.twig`, or `page/<id>/<id>.yaml`.
        // A sidecar such as `fixtures.yaml` is neither.
        $dir = \dirname($path);
        $id = basename($path, '.yaml');

        return str_ends_with($path, '.yaml')
            && ($id === basename($dir) || is_file("{$dir}/{$id}.twig"))
            && self::isPageDirectory($dir);
    }

    /**
     * One line per component-only key present, naming why it is refused.
     *
     * @param array<mixed>|object $document
     * @return list<string>
     */
    public static function componentOnlyKeyMessages(array|object $document): array
    {
        $keys = array_keys(is_object($document) ? get_object_vars($document) : $document);
        $messages = [];
        foreach (self::COMPONENT_ONLY_KEYS as $key) {
            if (in_array($key, $keys, true)) {
                $messages[] = "`{$key}:` is a component key; a page does not carry it (page.schema.json)";
            }
        }

        return $messages;
    }
}
