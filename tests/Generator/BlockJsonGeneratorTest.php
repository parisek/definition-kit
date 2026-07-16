<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;

final class BlockJsonGeneratorTest extends TestCase
{
    private BlockJsonGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BlockJsonGenerator();
    }

    public function test_fixed_boilerplate_props(): void
    {
        $block = $this->generator->generate(['name' => 'Demo'], 'demo');
        self::assertSame(3, $block['apiVersion']);
        self::assertSame('acf/demo', $block['name']);
        self::assertSame('Demo', $block['title']);
        self::assertNull($block['description']);
        self::assertSame('theme', $block['category']);
        self::assertSame('preview', $block['acf']['mode']);
        self::assertSame('Parisek\\TimberKit\\BlockRenderer::render', $block['acf']['renderCallback']);
    }

    public function test_icon_is_loaded_from_the_shared_asset_and_is_nonempty_svg(): void
    {
        $block = $this->generator->generate(['name' => 'Demo'], 'demo');
        self::assertStringStartsWith('<svg', $block['icon']);
    }

    public function test_bleed_render_gets_full_align_support_and_attribute(): void
    {
        $block = $this->generator->generate(['name' => 'Demo', 'render' => 'bleed'], 'demo');
        self::assertSame(['full'], $block['supports']['align']);
        self::assertSame(['type' => 'string', 'default' => 'full'], $block['attributes']['align']);
    }

    public function test_non_bleed_render_omits_align_entirely(): void
    {
        foreach (['inset', 'chrome', 'overlay', ''] as $render) {
            $tree = '' === $render ? ['name' => 'Demo'] : ['name' => 'Demo', 'render' => $render];
            $block = $this->generator->generate($tree, 'demo');
            self::assertArrayNotHasKey('align', $block['supports'], "render={$render}");
            self::assertNull($block['attributes'], "render={$render}");
        }
    }

    public function test_common_supports_flags_present_regardless_of_render(): void
    {
        $block = $this->generator->generate(['name' => 'Demo'], 'demo');
        self::assertFalse($block['supports']['mode']);
        self::assertFalse($block['supports']['spacing']);
        self::assertTrue($block['supports']['anchor']);
        self::assertTrue($block['supports']['className']);
    }

    public function test_fresh_generation_emits_an_empty_placeholder_example(): void
    {
        $block = $this->generator->generate(['name' => 'Demo'], 'demo');
        self::assertSame([], $block['example']['attributes']['data']);
    }

    public function test_bleed_placeholder_example_carries_align_full(): void
    {
        $block = $this->generator->generate(['name' => 'Demo', 'render' => 'bleed'], 'demo');
        self::assertSame('full', $block['example']['attributes']['align']);
    }

    public function test_existing_example_is_preserved_verbatim_never_regenerated(): void
    {
        $existing = [
            'example' => [
                'viewportWidth' => 1280,
                'attributes' => ['align' => 'full', 'mode' => 'preview', 'data' => ['title' => 'Real editorial content']],
            ],
        ];
        $block = $this->generator->generate(['name' => 'Demo', 'render' => 'bleed'], 'demo', $existing);
        self::assertSame('Real editorial content', $block['example']['attributes']['data']['title']);
    }

    public function test_custom_icon_path_is_honoured(): void
    {
        $path = sys_get_temp_dir() . '/block-icon-' . uniqid('', true) . '.svg';
        file_put_contents($path, '<svg>test</svg>');
        $block = (new BlockJsonGenerator($path))->generate(['name' => 'Demo'], 'demo');
        @unlink($path);
        self::assertSame('<svg>test</svg>', $block['icon']);
    }

    public function test_wp_block_overlay_replaces_config_sections_verbatim(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['block' => [
                'acf' => ['mode' => 'preview', 'renderCallback' => 'X::render', 'postTypes' => ['page']],
                'supports' => ['align' => ['full'], 'mode' => false, 'spacing' => false, 'anchor' => true, 'className' => true],
            ]],
        ];
        $block = $this->generator->generate($tree, 'demo');

        self::assertSame(['page'], $block['acf']['postTypes']);
        self::assertSame(['full'], $block['supports']['align']);
    }

    public function test_wp_block_overlay_can_set_a_section_to_a_captured_value_including_null(): void
    {
        $tree = ['name' => 'Demo', 'wp' => ['block' => ['attributes' => null]]];
        $block = $this->generator->generate($tree, 'demo');
        self::assertNull($block['attributes']);
    }

    public function test_absent_wp_block_leaves_output_byte_identical_to_derivation(): void
    {
        // No-regression guard for the reference set: a definition with no
        // wp.block must generate exactly what the tool produced before the
        // overlay existed.
        $withoutWp = $this->generator->generate(['name' => 'Demo', 'render' => 'bleed'], 'demo');
        $withEmptyWp = $this->generator->generate(['name' => 'Demo', 'render' => 'bleed', 'wp' => []], 'demo');
        self::assertSame($withoutWp, $withEmptyWp);
    }

    public function test_wp_bag_never_leaks_into_the_generated_block(): void
    {
        $tree = ['name' => 'Demo', 'wp' => ['block' => ['acf' => ['postTypes' => ['page']]], 'accordions' => []]];
        $block = $this->generator->generate($tree, 'demo');
        self::assertArrayNotHasKey('wp', $block);
    }
}
