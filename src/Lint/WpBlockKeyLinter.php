<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Generator\BlockJsonGenerator;

/**
 * Warns when root `wp.block` carries a key the generator does not replay.
 *
 * `BlockJsonGenerator` overlays a fixed set of sections from `wp.block`
 * (`BlockJsonGenerator::OVERLAY_SECTIONS`). The schema keeps `wp` open, so any
 * other key passes validation and is then dropped without a trace. The author
 * reads that as "authored correctly", and the drift persists with no hint why
 * (issue #51). This linter makes the drop visible. Severity is WARNING: the
 * generated JSON is valid, so nothing should block `fields-generate`.
 */
final class WpBlockKeyLinter
{
    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $wp = $definition['wp'] ?? null;
        $block = is_array($wp) ? ($wp['block'] ?? null) : null;
        if (!is_array($block)) {
            return [];
        }

        $findings = [];
        foreach (array_keys($block) as $key) {
            if (in_array($key, BlockJsonGenerator::OVERLAY_SECTIONS, true)) {
                continue;
            }
            $findings[] = [
                'severity' => 'warning',
                'message' => sprintf(
                    '%s: `wp.block.%s` is ignored. `fields-generate` replays only: %s.',
                    basename($definitionPath),
                    $key,
                    implode(', ', BlockJsonGenerator::OVERLAY_SECTIONS),
                ),
            ];
        }

        return $findings;
    }
}
