<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use Parisek\DefinitionKit\Lint\WpBlockKeyLinter;
use PHPUnit\Framework\TestCase;

final class WpBlockKeyLinterTest extends TestCase
{
    public function test_a_key_the_generator_ignores_warns(): void
    {
        $findings = (new WpBlockKeyLinter())->lint('demo.yaml', [
            'wp' => ['block' => ['description' => 'ok', 'icon' => 'x', 'title' => 'y']],
        ]);

        self::assertCount(2, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString("demo.yaml: `wp.block.icon` is ignored", $findings[0]['message']);
        self::assertStringContainsString('`wp.block.title`', $findings[1]['message']);
        self::assertStringContainsString('description, keywords, acf, supports, attributes, example', $findings[0]['message']);
    }

    public function test_every_overlay_section_is_accepted(): void
    {
        $block = array_fill_keys(['description', 'keywords', 'acf', 'supports', 'attributes', 'example'], null);

        self::assertSame([], (new WpBlockKeyLinter())->lint('demo.yaml', ['wp' => ['block' => $block]]));
    }

    public function test_no_wp_block_is_clean(): void
    {
        self::assertSame([], (new WpBlockKeyLinter())->lint('demo.yaml', ['name' => 'Demo']));
        self::assertSame([], (new WpBlockKeyLinter())->lint('demo.yaml', ['wp' => ['wpml' => 1]]));
    }
}
