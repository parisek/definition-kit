<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

/**
 * Tells a styleguide page from a component, and says what a page may carry.
 *
 * The rule is the directory, the same one parisek/styleguide uses to type an
 * entry: a definition directory whose parent is named `page` is a page
 * (`<templates>/page/<id>/`). The `$schema` comment line is not the rule. It
 * is an editor hint, authors omit it or point it at a stale path, and a YAML
 * parser never sees it.
 *
 * A page has no CMS projection (no acf.json, block.json or component.yml) and
 * no input contract, so the projection commands skip it.
 */
final class PageDefinition
{
    /** The header `fields-migrate` writes on top of a page YAML. */
    public const SCHEMA_HEADER = '# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json';

    /** Component keys a page must not carry. */
    public const COMPONENT_ONLY_KEYS = ['fields', 'kind', 'wp', 'key', 'mcp'];

    public const SKIP_REASON = 'a page has no CMS projection or input contract (page.schema.json)';

    public static function isPageDirectory(string $dir): bool
    {
        // A mistyped path is not a page. Classifying it by name alone made
        // the projection commands report a clean SKIP for a missing directory.
        $real = realpath(rtrim($dir, '/'));

        return false !== $real && is_dir($real) && 'page' === basename(\dirname($real));
    }

    public static function isPageYaml(string $path): bool
    {
        // Only the definition itself, `page/<id>/<id>.yaml`, not a sidecar
        // YAML that happens to live in the same directory.
        return basename($path) === basename(\dirname($path)) . '.yaml'
            && self::isPageDirectory(\dirname($path));
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
