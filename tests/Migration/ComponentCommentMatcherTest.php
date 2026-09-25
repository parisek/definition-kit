<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Migration\ComponentCommentMatcher;
use PHPUnit\Framework\TestCase;

final class ComponentCommentMatcherTest extends TestCase
{
    public function test_matching_scalars_produce_no_diff(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['name' => 'Demo', 'category' => 'Content'],
            ['name' => 'Demo', 'category' => 'Content', 'fields' => []],
        );

        self::assertSame([], $diffs);
    }

    public function test_usage_matches_regardless_of_list_order(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['usage' => 'career, home'],
            ['usage' => ['home', 'career']],
        );

        self::assertSame([], $diffs);
    }

    public function test_usage_mismatch_is_reported(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['usage' => 'career'],
            ['usage' => ['home']],
        );

        self::assertCount(1, $diffs);
        self::assertStringContainsString('usage:', $diffs[0]);
    }

    public function test_missing_yaml_key_is_reported(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['asana' => 'https://app.asana.com/0/1/2'],
            ['name' => 'Demo'],
        );

        self::assertCount(1, $diffs);
        self::assertStringContainsString('asana', $diffs[0]);
        self::assertStringContainsString('no such key', $diffs[0]);
    }

    public function test_differing_scalar_is_reported(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['category' => 'Block'],
            ['category' => 'Content'],
        );

        self::assertSame(["category: comment='Block' yaml='Content'"], $diffs);
    }

    public function test_extra_yaml_keys_the_comment_does_not_have_are_ignored(): void
    {
        $matcher = new ComponentCommentMatcher();

        $diffs = $matcher->diff(
            ['name' => 'Demo'],
            ['name' => 'Demo', 'kind' => 'block', 'wp' => ['block' => []], 'mcp' => ['note']],
        );

        self::assertSame([], $diffs);
    }
}
