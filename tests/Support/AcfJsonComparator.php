<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\StructuralDiff;

/**
 * Thin string-formatting wrapper over Support\StructuralDiff, kept
 * test-only (this exact class, this exact signature) so every existing
 * caller (AcfJsonComparatorTest, GenerationRoundTripTest) needs zero
 * changes. The actual diff algorithm now lives in production code — see
 * Support\StructuralDiff's docblock for why (Lint\DriftLinter needs it
 * outside autoload-dev).
 */
final class AcfJsonComparator
{
    /** @return list<string> human-readable diffs; empty means structurally equal */
    public static function diff(mixed $expected, mixed $actual, string $path = ''): array
    {
        return array_map(
            StructuralDiff::formatEntry(...),
            StructuralDiff::diff($expected, $actual, $path),
        );
    }
}
