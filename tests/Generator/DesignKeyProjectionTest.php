<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #111. `design:` has no CMS projection. The test runs the real
 * `fields-generate` on one component twice, with and without the key, and
 * compares the written files byte for byte. A pinned `modified` in a seeded
 * acf.json keeps the timestamp out of the comparison.
 */
final class DesignKeyProjectionTest extends TestCase
{
    private const DEFINITION = <<<'YAML'
        name: Example card
        category: Content
        kind: block
        description: A card with a title and an image.
        fields:
          title:
            type: text
            label: Title
          items:
            type: list
            label: Items
            fields:
              label:
                type: text
                label: Label

        YAML;

    private const DESIGN = <<<'YAML'
        design:
          - part: root
            sources:
              canvas:
                desktop-1280: { ref: Example.dc.html, select: 'a.card' }
              screenshots:
                mobile-390: { ref: example-card-mobile@2x.png, scale: 2, region: { x: 0, y: 120, width: 390, height: 640 } }
          - part: item
            site: '.example-card__item'
            repeat: true
            target: page/example-full
            sources:
              canvas:
                desktop-1280: { ref: Example.dc.html, select: 'li.item', state: hover, reveal: [{ action: hover, selector: 'li.item' }] }

        YAML;

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob("{$root}/example-card/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir("{$root}/example-card");
            rmdir($root);
        }
    }

    /** @return array{acf: string, block: string} */
    private function generate(string $yaml): array
    {
        $root = sys_get_temp_dir() . '/dk-111-' . uniqid('', true);
        $this->roots[] = $root;
        $dir = "{$root}/example-card";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/example-card.yaml", $yaml);
        file_put_contents("{$dir}/acf.json", json_encode(['key' => 'group_example_card', 'modified' => 1700000000], JSON_PRETTY_PRINT));

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/fields-generate') . ' ' . escapeshellarg($dir) . ' 2>&1',
            $output,
            $exitCode,
        );
        self::assertSame(0, $exitCode, implode("\n", $output));

        return [
            'acf' => (string) file_get_contents("{$dir}/acf.json"),
            'block' => (string) file_get_contents("{$dir}/block.json"),
        ];
    }

    #[Test]
    public function acf_json_and_block_json_are_byte_identical_with_and_without_design(): void
    {
        $without = $this->generate(self::DEFINITION);
        $with = $this->generate(self::DEFINITION . self::DESIGN);

        self::assertStringNotContainsString('design', $without['acf'] . $without['block']);
        self::assertSame($without['acf'], $with['acf']);
        self::assertSame($without['block'], $with['block']);
    }
}
