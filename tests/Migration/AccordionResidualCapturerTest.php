<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\AccordionResidualCapturer;

final class AccordionResidualCapturerTest extends TestCase
{
    private AccordionResidualCapturer $capturer;

    protected function setUp(): void
    {
        $this->capturer = new AccordionResidualCapturer();
    }

    /**
     * A real accordion pseudo-field at the tool's baseline.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function baselineAccordion(array $overrides = []): array
    {
        return [
            'key' => 'field_demo_a',
            'allow_in_bindings' => 0,
            'label' => 'Section',
            'name' => '',
            'aria-label' => '',
            'type' => 'accordion',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
            'wpml_cf_preferences' => 0,
            'open' => 0,
            'multi_expand' => 0,
            'endpoint' => 0,
            ...$overrides,
        ];
    }

    public function test_fully_baseline_accordion_captures_nothing(): void
    {
        self::assertSame([], $this->capturer->capture($this->baselineAccordion()));
    }

    public function test_instructions_are_captured_verbatim(): void
    {
        $residual = $this->capturer->capture($this->baselineAccordion(['instructions' => 'Menu se vypisuje automaticky']));
        self::assertSame(['instructions' => 'Menu se vypisuje automaticky'], $residual);
    }

    public function test_nonzero_wpml_is_captured_by_real_acf_name(): void
    {
        $residual = $this->capturer->capture($this->baselineAccordion(['wpml_cf_preferences' => 1]));
        self::assertSame(['wpml_cf_preferences' => 1], $residual);
    }

    public function test_multiple_nonbaseline_props_are_all_captured(): void
    {
        $residual = $this->capturer->capture($this->baselineAccordion([
            'instructions' => 'Help',
            'wpml_cf_preferences' => 1,
            'multi_expand' => 1,
        ]));
        self::assertSame(['instructions' => 'Help', 'wpml_cf_preferences' => 1, 'multi_expand' => 1], $residual);
    }

    public function test_identity_triple_is_never_captured_even_when_present(): void
    {
        // key/label/open are stored separately by the caller; the baseline is
        // built from them, so they must never appear in the residual.
        $residual = $this->capturer->capture($this->baselineAccordion([
            'label' => 'A Different Label',
            'open' => 1,
        ]));
        self::assertArrayNotHasKey('key', $residual);
        self::assertArrayNotHasKey('label', $residual);
        self::assertArrayNotHasKey('open', $residual);
    }

    public function test_nested_wrapper_override_is_captured_whole(): void
    {
        $residual = $this->capturer->capture($this->baselineAccordion([
            'wrapper' => ['width' => '', 'class' => 'my-class', 'id' => ''],
        ]));
        self::assertSame(['wrapper' => ['width' => '', 'class' => 'my-class', 'id' => '']], $residual);
    }
}
