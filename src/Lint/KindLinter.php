<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/**
 * Validates the component `kind` (tailwind-base ADR 0012).
 *
 * Only `block` is machine-checkable: it is the one value with a filesystem
 * counterpart (`block.json`), so a mismatch in either direction is a real
 * contradiction. `section`/`element`/`part`/`utility` are authorial intent —
 * ADR 0012 measured that no derivable rule separates them, which is the whole
 * reason the key exists. The linter therefore never infers them and never
 * auto-fixes.
 *
 * Missing `kind` is a WARNING, not an error: the downstream backfill has not
 * run yet, and failing every un-migrated definition would make the tool
 * unusable during the migration it is meant to support.
 */
final class KindLinter
{
    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $kind = $definition['kind'] ?? null;

        if (null === $kind) {
            return [[
                'severity' => 'warning',
                'message' => sprintf(
                    '%s declares no `kind`. Add one of block/section/element/part/utility — see ADR 0012.',
                    basename($definitionPath)
                ),
            ]];
        }

        // Resolve symlinks before deriving the component directory. A definition
        // reached through a symlinked FILE would otherwise have dirname() point at
        // the link's directory rather than the component's, so a perfectly valid
        // `kind: block` next to its block.json reports as a contradiction. Falls
        // back to the raw path when realpath() fails (file does not exist yet —
        // callers may lint an in-memory definition).
        $resolved = realpath($definitionPath);
        $componentDir = dirname(false !== $resolved ? $resolved : $definitionPath);

        $hasBlockJson = is_file($componentDir . '/block.json');

        if ('block' === $kind && !$hasBlockJson) {
            return [[
                'severity' => 'error',
                'message' => sprintf('%s declares `kind: block` but has no block.json.', basename($definitionPath)),
            ]];
        }

        if ('block' !== $kind && $hasBlockJson) {
            return [[
                'severity' => 'error',
                'message' => sprintf(
                    '%s has a block.json but declares `kind: %s` — an editor-insertable component is `block`.',
                    basename($definitionPath),
                    $kind
                ),
            ]];
        }

        return [];
    }
}
