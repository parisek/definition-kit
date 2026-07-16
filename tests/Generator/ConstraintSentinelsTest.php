<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\ConstraintSentinels;

final class ConstraintSentinelsTest extends TestCase
{
    public function test_text_maxlength_sentinel_is_empty_string(): void
    {
        self::assertSame(['maxlength' => ''], (new ConstraintSentinels())->forType('text'));
    }

    public function test_repeater_min_max_sentinel_is_zero(): void
    {
        self::assertSame(['min' => 0, 'max' => 0], (new ConstraintSentinels())->forType('repeater'));
    }

    public function test_image_dimension_sentinels_are_zero_and_min_size_is_absent(): void
    {
        $sentinels = (new ConstraintSentinels())->forType('image');
        self::assertSame(0, $sentinels['min_width']);
        self::assertSame(0, $sentinels['max_size']);
        self::assertArrayNotHasKey('min_size', $sentinels);
    }

    public function test_file_sentinels_are_empty_string(): void
    {
        self::assertSame(
            ['min_size' => '', 'max_size' => '', 'mime_types' => ''],
            (new ConstraintSentinels())->forType('file'),
        );
    }

    public function test_type_with_no_constraint_props_returns_empty_array(): void
    {
        self::assertSame([], (new ConstraintSentinels())->forType('true_false'));
    }

    public function test_unknown_type_returns_empty_array(): void
    {
        self::assertSame([], (new ConstraintSentinels())->forType('unknown_type'));
    }

    public function test_custom_path_is_honoured(): void
    {
        $path = sys_get_temp_dir() . '/constraint-sentinels-' . uniqid('', true) . '.yaml';
        file_put_contents($path, "text:\n  maxlength: 42\n");
        $sentinels = (new ConstraintSentinels($path))->forType('text');
        @unlink($path);
        self::assertSame(['maxlength' => 42], $sentinels);
    }
}
