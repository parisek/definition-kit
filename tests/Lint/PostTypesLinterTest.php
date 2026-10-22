<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use Parisek\DefinitionKit\Lint\PostTypesLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostTypesLinterTest extends TestCase
{
    /**
     * @param list<string>|null $postTypes null leaves the key out
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function block(?array $postTypes, array $extra = []): array
    {
        $acf = null === $postTypes ? ['mode' => 'preview'] : ['postTypes' => $postTypes];

        return ['kind' => 'block', 'wp' => ['block' => ['acf' => $acf]]] + $extra;
    }

    public function test_a_restricted_block_is_silent(): void
    {
        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $this->block(['page'])));
    }

    /** @param list<string>|null $postTypes */
    #[DataProvider('openShapes')]
    public function test_an_open_block_without_a_reason_warns(?array $postTypes): void
    {
        $findings = (new PostTypesLinter())->lint('demo.yaml', $this->block($postTypes));

        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('demo.yaml: `wp.block.acf.postTypes` is empty or missing', $findings[0]['message']);
        self::assertStringContainsString('post-types: open', $findings[0]['message']);
    }

    /** @return iterable<string,array{list<string>|null}> */
    public static function openShapes(): iterable
    {
        yield 'empty list' => [[]];
        yield 'key missing' => [null];
        yield 'blank entry' => [['']];
    }

    public function test_a_stated_exception_in_a_dev_list_is_silent(): void
    {
        $definition = $this->block([], ['dev' => ['Other note.', 'post-types: open, lives in a reusable wp_block']]);

        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_a_stated_exception_in_one_string_is_silent(): void
    {
        $definition = $this->block([], ['dev' => "post-types: open, one post type on this site\n"]);

        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_a_folded_string_is_read_as_one_item(): void
    {
        $definition = $this->block([], ['dev' => "post-types: open,\n  reason on the second line"]);

        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_the_marker_without_a_reason_warns_about_the_reason(): void
    {
        $findings = (new PostTypesLinter())->lint('demo.yaml', $this->block([], ['dev' => ['post-types: open']]));

        self::assertCount(1, $findings);
        self::assertStringContainsString('gives no reason', $findings[0]['message']);
    }

    public function test_a_later_reason_wins_over_an_earlier_empty_marker(): void
    {
        $definition = $this->block([], ['dev' => ['post-types: open', 'post-types: open, reusable-only']]);

        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_an_unrelated_dev_note_does_not_count(): void
    {
        $findings = (new PostTypesLinter())->lint('demo.yaml', $this->block([], ['dev' => ['Deviation from upstream: x']]));

        self::assertCount(1, $findings);
        self::assertStringContainsString('is empty or missing', $findings[0]['message']);
    }

    public function test_the_marker_and_its_reason_must_share_one_item(): void
    {
        $findings = (new PostTypesLinter())->lint('demo.yaml', $this->block([], ['dev' => ['post-types:', 'open, unrelated']]));

        self::assertCount(1, $findings);
        self::assertStringContainsString('is empty or missing', $findings[0]['message']);
    }

    public function test_a_word_that_only_starts_with_open_is_not_the_marker(): void
    {
        $findings = (new PostTypesLinter())->lint('demo.yaml', $this->block([], ['dev' => ['post-types: open-ended thoughts']]));

        self::assertCount(1, $findings);
        self::assertStringContainsString('is empty or missing', $findings[0]['message']);
    }

    public function test_a_marker_does_not_rescue_nor_flag_a_restricted_block(): void
    {
        $definition = $this->block(['page'], ['dev' => ['post-types: open, stale']]);

        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_a_field_named_post_types_is_not_the_block_registration(): void
    {
        $definition = $this->block([]) + ['fields' => ['postTypes' => ['type' => 'text']]];

        self::assertCount(1, (new PostTypesLinter())->lint('demo.yaml', $definition));
    }

    public function test_only_a_block_is_checked(): void
    {
        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', ['kind' => 'element'] + $this->block([])));
        self::assertSame([], (new PostTypesLinter())->lint('demo.yaml', ['name' => 'Demo']));
    }

    public function test_a_drupal_project_is_skipped(): void
    {
        self::assertSame([], (new PostTypesLinter(isDrupalProject: true))->lint('demo.yaml', $this->block([])));
    }
}
