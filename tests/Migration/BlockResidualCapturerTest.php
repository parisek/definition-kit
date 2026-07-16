<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Migration\BlockResidualCapturer;

final class BlockResidualCapturerTest extends TestCase
{
    private BlockResidualCapturer $capturer;
    private BlockJsonGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BlockJsonGenerator();
        $this->capturer = new BlockResidualCapturer($this->generator);
    }

    /**
     * The block.json the generator derives for a bare non-bleed tree.
     *
     * @param array<string,mixed> $tree
     * @return array<string,mixed>
     */
    private function derived(array $tree = ['name' => 'Demo']): array
    {
        return $this->generator->generate($tree, 'demo');
    }

    public function test_all_derived_block_captures_nothing(): void
    {
        $real = $this->derived();
        self::assertSame([], $this->capturer->capture(['name' => 'Demo'], 'demo', $real));
    }

    public function test_post_types_restriction_is_captured_as_the_acf_section_verbatim(): void
    {
        $real = $this->derived();
        $real['acf']['postTypes'] = ['page'];

        $wpBlock = $this->capturer->capture(['name' => 'Demo'], 'demo', $real);

        self::assertSame(['acf'], array_keys($wpBlock));
        self::assertSame($real['acf'], $wpBlock['acf']);
        self::assertSame(['page'], $wpBlock['acf']['postTypes']);
    }

    public function test_supports_override_is_captured_as_the_supports_section_verbatim(): void
    {
        $real = $this->derived();
        $real['supports']['align'] = ['full', 'wide'];

        $wpBlock = $this->capturer->capture(['name' => 'Demo'], 'demo', $real);

        self::assertSame(['supports'], array_keys($wpBlock));
        self::assertSame($real['supports'], $wpBlock['supports']);
    }

    public function test_attributes_override_is_captured_when_real_carries_more(): void
    {
        // Tree is non-bleed → generator derives attributes:null. Real carries a
        // real attributes object → data loss direction → capture verbatim.
        $real = $this->derived();
        $real['attributes'] = ['align' => ['type' => 'string', 'default' => 'full']];

        $wpBlock = $this->capturer->capture(['name' => 'Demo'], 'demo', $real);

        self::assertSame(['attributes'], array_keys($wpBlock));
        self::assertSame($real['attributes'], $wpBlock['attributes']);
    }

    public function test_section_the_real_block_omits_is_not_captured(): void
    {
        // Generator emits attributes:null; a real block.json that OMITS the
        // attributes key is the "generator adds a prop real lacks" direction —
        // a baseline-add, out of scope (resolved at adoption regeneration), so
        // it must NOT be captured.
        $real = $this->derived();
        unset($real['attributes']);

        $wpBlock = $this->capturer->capture(['name' => 'Demo'], 'demo', $real);

        self::assertArrayNotHasKey('attributes', $wpBlock);
    }

    public function test_only_the_three_config_sections_are_ever_captured(): void
    {
        // A divergent title / description / icon must never leak into wp.block —
        // those are fully derived and owned by the generator.
        $real = $this->derived();
        $real['title'] = 'Something Else';
        $real['description'] = 'nonsense';
        $real['acf']['postTypes'] = ['page'];

        $wpBlock = $this->capturer->capture(['name' => 'Demo'], 'demo', $real);

        self::assertSame(['acf'], array_keys($wpBlock));
    }
}
