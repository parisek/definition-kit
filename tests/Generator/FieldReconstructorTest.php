<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\FieldReconstructor;

final class FieldReconstructorTest extends TestCase
{
    private FieldReconstructor $reconstructor;

    protected function setUp(): void
    {
        $this->reconstructor = new FieldReconstructor();
    }

    public function test_always_present_props_on_a_minimal_field(): void
    {
        $out = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'Nadpis'], []);
        self::assertSame('text', $out['type']);
        self::assertSame(0, $out['required']);
        self::assertSame('Nadpis', $out['label']);
        self::assertSame('', $out['instructions']);
        self::assertSame(1, $out['wpml_cf_preferences']);
        self::assertFalse($out['conditional_logic']);
    }

    public function test_required_true_reconstructs_to_one(): void
    {
        $out = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T', 'required' => true], []);
        self::assertSame(1, $out['required']);
    }

    public function test_description_reconstructs_to_instructions(): void
    {
        $out = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T', 'description' => 'Help text'], []);
        self::assertSame('Help text', $out['instructions']);
    }

    public function test_translatable_true_reconstructs_to_wpml_two(): void
    {
        $out = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T', 'translatable' => true], []);
        self::assertSame(2, $out['wpml_cf_preferences']);
    }

    public function test_container_type_always_reconstructs_wpml_three_even_if_translatable_set(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'group', 'label' => 'T', 'translatable' => true, 'fields' => ['x' => ['type' => 'text', 'label' => 'X']]],
            [],
        );
        self::assertSame(3, $out['wpml_cf_preferences']);
    }

    public function test_maxlength_present_only_when_authored(): void
    {
        $withoutIt = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T'], []);
        self::assertArrayNotHasKey('maxlength', $withoutIt);

        $withIt = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T', 'maxlength' => 60], []);
        self::assertSame(60, $withIt['maxlength']);
    }

    public function test_number_min_max_step_reconstructed(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'number', 'label' => 'T', 'min' => 1, 'max' => 10, 'step' => 1],
            [],
        );
        self::assertSame(1, $out['min']);
        self::assertSame(10, $out['max']);
        self::assertSame(1, $out['step']);
    }

    public function test_repeater_min_max_reconstructed_but_no_step_field_exists_in_acf(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'repeater', 'label' => 'T', 'min' => 1, 'max' => 5, 'fields' => ['x' => ['type' => 'text', 'label' => 'X']]],
            [],
        );
        self::assertSame(1, $out['min']);
        self::assertSame(5, $out['max']);
    }

    public function test_accept_reconstructs_to_joined_mime_types(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'media', 'kind' => 'file', 'label' => 'T', 'accept' => ['pdf', 'docx']],
            [],
        );
        self::assertSame('pdf,docx', $out['mime_types']);
    }

    public function test_max_size_and_dimensions_reconstructed(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'media', 'kind' => 'image', 'label' => 'T', 'max_size' => 10, 'min_width' => 800, 'max_height' => 2000],
            [],
        );
        self::assertSame(10, $out['max_size']);
        self::assertSame(800, $out['min_width']);
        self::assertSame(2000, $out['max_height']);
        self::assertArrayNotHasKey('min_height', $out);
    }

    public function test_placeholder_reconstructed_when_authored(): void
    {
        $out = $this->reconstructor->reconstruct(['type' => 'text', 'label' => 'T', 'placeholder' => 'Zadejte'], []);
        self::assertSame('Zadejte', $out['placeholder']);
    }

    public function test_visible_when_reconstructs_to_conditional_logic(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'text', 'label' => 'T', 'visible_when' => ['field' => 'title', 'not_empty' => true]],
            ['title' => 'field_demo_title'],
        );
        self::assertSame(
            [[['field' => 'field_demo_title', 'operator' => '!=empty', 'value' => '']]],
            $out['conditional_logic'],
        );
    }

    public function test_type_and_extras_delegate_to_abstract_type_reverse_mapper(): void
    {
        $out = $this->reconstructor->reconstruct(
            ['type' => 'select', 'label' => 'T', 'options' => ['a' => 'A']],
            [],
        );
        self::assertSame('select', $out['type']);
        self::assertSame(['a' => 'A'], $out['choices']);
    }
}
