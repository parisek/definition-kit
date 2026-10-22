<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/**
 * Warns when a `kind: block` definition leaves `postTypes` open without a reason.
 *
 * An empty or missing `wp.block.acf.postTypes` puts the block in the inserter
 * of every post type. That is right for a footer inside a reusable `wp_block`
 * and for a site with one post type. Most of the time it is a forgotten
 * default, and the two cases look the same in the definition. This linter
 * sorts them. A restricted block is silent. An open block is silent when a
 * root `dev` item starts with `post-types: open` and gives a reason. Anything
 * else gets a WARNING: the generated JSON is valid, so nothing blocks
 * `fields-generate`.
 *
 * `dev` is a free-text annotation (null, one string, or a list of strings),
 * so the exception needs no schema change. The marker and its reason must sit
 * in the same item. A marker with no reason still counts as stated, and gets
 * its own warning, so the gap stays visible.
 *
 * Only a WordPress block has `postTypes`. A Drupal project (signalled like in
 * {@see KindLinter}) is skipped.
 */
final class PostTypesLinter
{
    private const MARKER = '/^post-types:\s*open(?![\w-])[\s,:;.\-]*(?<reason>.*)$/isu';

    public function __construct(private readonly bool $isDrupalProject = false)
    {
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        if ($this->isDrupalProject || 'block' !== ($definition['kind'] ?? null)) {
            return [];
        }

        if ([] !== $this->postTypes($definition)) {
            return [];
        }

        $name = basename($definitionPath);
        $reason = $this->exceptionReason($definition['dev'] ?? null);

        if (null === $reason) {
            return [[
                'severity' => 'warning',
                'message' => sprintf(
                    '%s: `wp.block.acf.postTypes` is empty or missing, so the block is open to every post type. '
                    . 'List the post types, or add a root `dev` item that starts with `post-types: open, ` and gives the reason.',
                    $name,
                ),
            ]];
        }

        if ('' === $reason) {
            return [[
                'severity' => 'warning',
                'message' => sprintf(
                    '%s: `dev` says `post-types: open` but gives no reason. Add one after the comma.',
                    $name,
                ),
            ]];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<string>
     */
    private function postTypes(array $definition): array
    {
        $wp = $definition['wp'] ?? null;
        $block = is_array($wp) ? ($wp['block'] ?? null) : null;
        $acf = is_array($block) ? ($block['acf'] ?? null) : null;
        $value = is_array($acf) ? ($acf['postTypes'] ?? null) : null;

        $items = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_string($item) && '' !== trim($item),
        ));
    }

    /** The reason after the marker in ONE `dev` item, or null when no item carries the marker. */
    private function exceptionReason(mixed $dev): ?string
    {
        $items = is_array($dev) ? $dev : [$dev];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $item));
            if (1 === preg_match(self::MARKER, $text, $match)) {
                return trim($match['reason'], " \t\n\r\0\x0B'\"");
            }
        }

        return null;
    }
}
