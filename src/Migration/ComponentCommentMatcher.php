<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Decides whether a component's twig front-comment is safe to strip because
 * `<name>.yaml` already carries everything the comment says.
 *
 * Used by `fields-migrate --strip-comment` for a component that was already
 * migrated — the comment was left behind (issue: component migration used
 * to leave it, unlike page/doc migration), and the yaml next to it is the
 * complete definition. Stripping is refused, per key, whenever the yaml
 * doesn't say the same thing: never lose data, even a stale comment.
 *
 * Only compares keys the comment actually has (TwigMetadataReader::read()'s
 * root-metadata set). A key the yaml carries and the comment doesn't
 * (`fields`, `kind`, `wp`, `mcp`, ...) is not this class's concern.
 */
final class ComponentCommentMatcher
{
    /**
     * @param array<string,string> $commentMeta TwigMetadataReader::read()'s output
     * @param array<string,mixed> $yamlTree the parsed <name>.yaml
     * @return list<string> human-readable differences; empty means it is safe to strip
     */
    public function diff(array $commentMeta, array $yamlTree): array
    {
        $diffs = [];
        foreach ($commentMeta as $key => $rawValue) {
            if (!array_key_exists($key, $yamlTree)) {
                $diffs[] = "{$key}: comment has '{$rawValue}', yaml has no such key";
                continue;
            }
            if (!$this->valuesMatch($key, $rawValue, $yamlTree[$key])) {
                $diffs[] = sprintf("%s: comment='%s' yaml=%s", $key, $rawValue, $this->describe($yamlTree[$key]));
            }
        }

        return $diffs;
    }

    private function valuesMatch(string $key, string $commentValue, mixed $yamlValue): bool
    {
        // `usage: a, b` in the comment vs. `usage: [a, b]` in the yaml — the
        // canonical shape a component's yaml stores lists in. Compared as
        // sets: migration doesn't guarantee order, and neither should this.
        if ('usage' === $key) {
            $commentList = array_values(array_filter(
                array_map('trim', explode(',', $commentValue)),
                static fn (string $v): bool => '' !== $v,
            ));
            $yamlList = is_array($yamlValue)
                ? array_map(static fn (mixed $v): string => (string) $v, $yamlValue)
                : array_values(array_filter(array_map('trim', explode(',', (string) $yamlValue)), static fn (string $v): bool => '' !== $v));
            sort($commentList);
            sort($yamlList);

            return $commentList === $yamlList;
        }

        if (is_bool($yamlValue)) {
            return strtolower(trim($commentValue)) === ($yamlValue ? 'true' : 'false');
        }

        if (is_array($yamlValue)) {
            // A comment root key is always a scalar string (TwigMetadataReader
            // never returns a list); a yaml key holding a list here is a
            // structural mismatch, not something to render and compare.
            return false;
        }

        return trim($commentValue) === trim((string) $yamlValue);
    }

    private function describe(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map(static fn (mixed $v): string => (string) $v, $value)) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return "'{$value}'";
    }
}
