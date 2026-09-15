<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Migration\StructuralTypeRenamer;
use PHPUnit\Framework\TestCase;

final class StructuralTypeRenamerTest extends TestCase
{
    private StructuralTypeRenamer $renamer;

    protected function setUp(): void
    {
        $this->renamer = new StructuralTypeRenamer();
    }

    public function test_renames_types_and_preserves_comments_quoting_and_order(): void
    {
        $source = <<<'YAML'
            # Demo definition
            name: Demo
            fields:
              heading:   # the heading block
                type: group    # one object
                label: Heading
                fields:
                  title:
                    type: text
                    label: Title

              items:
                label: Items
                type: "repeater"
                fields:
                  link:
                    type: 'group'
                    fields:
                      url:
                        type: link

            YAML;

        $result = $this->renamer->rename($source);

        self::assertSame(3, $result['renamed']);
        self::assertSame(str_replace(
            ['type: group    # one object', 'type: "repeater"', "type: 'group'"],
            ['type: object    # one object', 'type: "list"', "type: 'object'"],
            $source,
        ), $result['source']);
    }

    public function test_leaves_other_keys_and_values_named_group_or_repeater_alone(): void
    {
        $source = <<<'YAML'
            name: Demo
            fields:
              group:
                type: text
                label: group
                description: |
                  Rows of a repeater.
                  type: repeater
              repeater:
                type: select
                options:
                  group: Group
                  repeater: Repeater
              legacy:
                type: text
                wp:
                  acf_type: repeater
            YAML;

        $result = $this->renamer->rename($source);

        self::assertSame(0, $result['renamed']);
        self::assertSame($source, $result['source']);
    }

    public function test_skips_a_block_scalar_line_but_renames_the_real_type(): void
    {
        $source = <<<'YAML'
            fields:
              items:
                description: |
                  type: repeater
                type: repeater
                fields:
                  t:
                    type: text
            YAML;

        $result = $this->renamer->rename($source);

        self::assertSame(1, $result['renamed']);
        self::assertStringContainsString("description: |\n      type: repeater\n    type: list\n", $result['source']);
    }

    public function test_is_idempotent(): void
    {
        $source = "fields:\n  a:\n    type: repeater\n    fields:\n      b:\n        type: group\n        fields:\n          c:\n            type: text\n";
        $once = $this->renamer->rename($source);
        $twice = $this->renamer->rename($once['source']);

        self::assertSame(2, $once['renamed']);
        self::assertSame(0, $twice['renamed']);
        self::assertSame($once['source'], $twice['source']);
    }

    public function test_renames_inside_flexible_content_layouts(): void
    {
        $source = "fields:\n  sections:\n    type: flexible_content\n    layouts:\n      hero:\n        label: Hero\n        fields:\n          cards:\n            type: repeater\n            fields:\n              t:\n                type: text\n";

        $result = $this->renamer->rename($source);

        self::assertSame(1, $result['renamed']);
        self::assertStringContainsString('type: list', $result['source']);
    }

    public function test_refuses_an_alias_it_cannot_rewrite_line_by_line(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rename it by hand');

        $this->renamer->rename("fields:\n  a: { type: group, fields: { b: { type: text } } }\n");
    }

    public function test_preserves_crlf_line_endings(): void
    {
        $source = "fields:\r\n  a:\r\n    type: group\r\n    fields:\r\n      b:\r\n        type: text\r\n";

        self::assertSame(str_replace('type: group', 'type: object', $source), $this->renamer->rename($source)['source']);
    }
}
