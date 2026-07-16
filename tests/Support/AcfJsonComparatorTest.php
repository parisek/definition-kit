<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves AcfJsonComparator's normalization is narrow (two documented ACF
 * serialization quirks only), not the blanket bool-vs-empty equivalence
 * it used to apply to every prop — that blanket rule masked real diffs
 * on boolean-ish props like `default_value` (see GenerationRoundTripTest's
 * docblock history and the definition-kit README's "Fixed during the
 * dávka-3 review round" note for the `true_false.default_value` bug this
 * exact gap hid).
 */
final class AcfJsonComparatorTest extends TestCase
{
    public function test_identical_trees_have_no_diff(): void
    {
        $tree = ['a' => 1, 'b' => ['c' => 'x']];
        self::assertSame([], AcfJsonComparator::diff($tree, $tree));
    }

    #[DataProvider('conditionalLogicEmptyShapes')]
    public function test_conditional_logic_treats_false_0_and_empty_array_as_equivalent(mixed $a, mixed $b): void
    {
        self::assertSame([], AcfJsonComparator::diff(
            ['conditional_logic' => $a],
            ['conditional_logic' => $b],
        ));
    }

    /** @return list<array{0:mixed,1:mixed}> */
    public static function conditionalLogicEmptyShapes(): array
    {
        return [[false, 0], [false, []], [0, []], [false, false]];
    }

    public function test_bounded_numeric_props_tolerate_numeric_string_vs_int(): void
    {
        self::assertSame([], AcfJsonComparator::diff(['maxlength' => '60'], ['maxlength' => 60]));
        self::assertSame([], AcfJsonComparator::diff(['min_width' => 100], ['min_width' => '100']));
    }

    /**
     * The empty-string sentinel on a bounded numeric prop is a REAL,
     * intentionally-visible diff (the documented image-dimension-sentinel
     * residual) — the numeric-string/int normalization must not swallow
     * a non-numeric empty string on the same axis.
     */
    public function test_bounded_numeric_prop_empty_string_sentinel_is_not_swallowed(): void
    {
        $diffs = AcfJsonComparator::diff(['min_width' => ''], ['min_width' => 0]);
        self::assertNotSame([], $diffs);
    }

    /**
     * The exact bug this comparator tightening caught: `default_value`
     * is NOT in the documented-quirk allowlist, so `false` (bool) vs `0`
     * (int) — or `false` vs `''` — must surface as a real diff, unlike
     * `conditional_logic`'s deliberately-loosened empty shapes.
     */
    public function test_default_value_bool_vs_empty_is_a_real_diff_not_normalized(): void
    {
        self::assertNotSame([], AcfJsonComparator::diff(['default_value' => false], ['default_value' => 0]));
        self::assertNotSame([], AcfJsonComparator::diff(['default_value' => false], ['default_value' => '']));
    }
}
