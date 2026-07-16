<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Support\StructuralDiff;

final class StructuralDiffTest extends TestCase
{
    public function test_identical_trees_have_no_diff(): void
    {
        $tree = ['a' => 1, 'b' => ['c' => 'x']];
        self::assertSame([], StructuralDiff::diff($tree, $tree));
    }

    #[DataProvider('conditionalLogicEmptyShapes')]
    public function test_conditional_logic_treats_false_0_and_empty_array_as_equivalent(mixed $a, mixed $b): void
    {
        self::assertSame([], StructuralDiff::diff(['conditional_logic' => $a], ['conditional_logic' => $b]));
    }

    /** @return list<array{0:mixed,1:mixed}> */
    public static function conditionalLogicEmptyShapes(): array
    {
        return [[false, 0], [false, []], [0, []], [false, false]];
    }

    public function test_bounded_numeric_props_tolerate_numeric_string_vs_int(): void
    {
        self::assertSame([], StructuralDiff::diff(['maxlength' => '60'], ['maxlength' => 60]));
        self::assertSame([], StructuralDiff::diff(['min_width' => 100], ['min_width' => '100']));
    }

    public function test_bounded_numeric_prop_empty_string_sentinel_is_not_swallowed(): void
    {
        $diffs = StructuralDiff::diff(['min_width' => ''], ['min_width' => 0]);
        self::assertNotSame([], $diffs);
    }

    public function test_default_value_bool_vs_empty_is_a_real_diff_not_normalized(): void
    {
        self::assertNotSame([], StructuralDiff::diff(['default_value' => false], ['default_value' => 0]));
    }

    public function test_value_diff_entry_carries_kind_prop_path_expected_actual(): void
    {
        $diffs = StructuralDiff::diff(['fields' => [['name' => 'x', 'label' => 'A']]], ['fields' => [['name' => 'x', 'label' => 'B']]]);
        self::assertSame([[
            'kind' => 'value',
            'path' => '.fields[0].label',
            'prop' => 'label',
            'expected' => 'A',
            'actual' => 'B',
        ]], $diffs);
    }

    public function test_missing_key_entry_carries_kind_missing(): void
    {
        $diffs = StructuralDiff::diff(['a' => 1, 'b' => 2], ['a' => 1]);
        self::assertSame('missing', $diffs[0]['kind']);
        self::assertSame('b', $diffs[0]['prop']);
    }

    public function test_unexpected_key_entry_carries_kind_unexpected(): void
    {
        $diffs = StructuralDiff::diff(['a' => 1], ['a' => 1, 'b' => 2]);
        self::assertSame('unexpected', $diffs[0]['kind']);
        self::assertSame('b', $diffs[0]['prop']);
    }

    public function test_format_entry_matches_the_legacy_string_shape(): void
    {
        $entry = ['kind' => 'value', 'path' => '.label', 'prop' => 'label', 'expected' => 'A', 'actual' => 'B'];
        self::assertSame('.label: expected "A", got "B"', StructuralDiff::formatEntry($entry));
    }
}
