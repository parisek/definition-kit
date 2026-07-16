<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Mcp\McpDefaultsLibrary;

final class McpDefaultsLibraryTest extends TestCase
{
    public function test_hints_for_media_image_returns_the_seeded_hint(): void
    {
        $hints = (new McpDefaultsLibrary())->hintsFor('media', 'image');
        self::assertCount(1, $hints);
        self::assertStringContainsString('highest-quality', $hints[0]);
    }

    public function test_hints_for_media_without_kind_returns_empty(): void
    {
        self::assertSame([], (new McpDefaultsLibrary())->hintsFor('media'));
    }

    public function test_hints_for_unknown_type_returns_empty(): void
    {
        self::assertSame([], (new McpDefaultsLibrary())->hintsFor('text'));
    }

    public function test_hints_for_type_level_list_without_kind_discriminator(): void
    {
        $path = sys_get_temp_dir() . '/mcp-defaults-' . uniqid('', true) . '.yaml';
        file_put_contents($path, "richtext:\n  - 'Keep formatting minimal.'\n");
        $hints = (new McpDefaultsLibrary($path))->hintsFor('richtext');
        @unlink($path);
        self::assertSame(['Keep formatting minimal.'], $hints);
    }
}
