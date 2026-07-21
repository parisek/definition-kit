<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\AbstractTypeReverseMapper;

final class AbstractTypeReverseMapperTest extends TestCase
{
    private AbstractTypeReverseMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AbstractTypeReverseMapper();
    }

    public function test_select_with_wp_acf_type_checkbox_reverses_to_checkbox(): void
    {
        $result = $this->mapper->reverse([
            'type' => 'select',
            'label' => 'T',
            'options' => ['x' => 'X'],
            'multiple' => true,
            'wp' => ['acf_type' => 'checkbox'],
        ]);
        self::assertSame('checkbox', $result['acfType']);
        // ACF's checkbox has no `multiple` prop — emitting one would invent a key.
        self::assertSame(['choices' => ['x' => 'X']], $result['extra']);
    }

    public function test_reference_with_term_target_reverses_to_taxonomy(): void
    {
        $result = $this->mapper->reverse(['type' => 'reference', 'label' => 'T', 'of' => 'term:product_cat']);
        self::assertSame('taxonomy', $result['acfType']);
        self::assertSame(['taxonomy' => 'product_cat'], $result['extra']);
    }

    public function test_reference_with_empty_term_target_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->reverse(['type' => 'reference', 'label' => 'T', 'of' => 'term:']);
    }

    public function test_text_reverses_to_text_by_default(): void
    {
        $result = $this->mapper->reverse(['type' => 'text', 'label' => 'T']);
        self::assertSame('text', $result['acfType']);
        self::assertSame([], $result['extra']);
    }

    public function test_text_with_wp_acf_type_email_reverses_to_email(): void
    {
        $result = $this->mapper->reverse(['type' => 'text', 'label' => 'T', 'wp' => ['acf_type' => 'email']]);
        self::assertSame('email', $result['acfType']);
    }

    public function test_text_with_multiline_reverses_to_textarea(): void
    {
        $result = $this->mapper->reverse(['type' => 'text', 'label' => 'T', 'multiline' => true]);
        self::assertSame('textarea', $result['acfType']);
    }

    public function test_richtext_reverses_to_wysiwyg(): void
    {
        self::assertSame('wysiwyg', $this->mapper->reverse(['type' => 'richtext', 'label' => 'T'])['acfType']);
    }

    public function test_number_and_boolean(): void
    {
        self::assertSame('number', $this->mapper->reverse(['type' => 'number', 'label' => 'T'])['acfType']);
        self::assertSame('true_false', $this->mapper->reverse(['type' => 'boolean', 'label' => 'T'])['acfType']);
    }

    public function test_select_reverses_to_select_by_default_rebuilding_choices(): void
    {
        $result = $this->mapper->reverse([
            'type' => 'select', 'label' => 'T', 'options' => ['a' => 'A', 'b' => 'B'], 'multiple' => true,
        ]);
        self::assertSame('select', $result['acfType']);
        self::assertSame(['a' => 'A', 'b' => 'B'], $result['extra']['choices']);
        self::assertSame(1, $result['extra']['multiple']);
    }

    public function test_select_omits_multiple_extra_when_absent(): void
    {
        $result = $this->mapper->reverse(['type' => 'select', 'label' => 'T', 'options' => ['a' => 'A']]);
        self::assertArrayNotHasKey('multiple', $result['extra']);
    }

    public function test_select_with_wp_acf_type_button_group_reverses_to_button_group(): void
    {
        $result = $this->mapper->reverse([
            'type' => 'select', 'label' => 'T', 'options' => ['x' => 'X'], 'wp' => ['acf_type' => 'button_group'],
        ]);
        self::assertSame('button_group', $result['acfType']);
        self::assertSame(['x' => 'X'], $result['extra']['choices']);
    }

    public function test_media_kind_selects_concrete_acf_type(): void
    {
        self::assertSame('image', $this->mapper->reverse(['type' => 'media', 'kind' => 'image', 'label' => 'T'])['acfType']);
        self::assertSame('file', $this->mapper->reverse(['type' => 'media', 'kind' => 'file', 'label' => 'T'])['acfType']);
        self::assertSame('gallery', $this->mapper->reverse(['type' => 'media', 'kind' => 'gallery', 'label' => 'T'])['acfType']);
    }

    public function test_media_without_kind_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->reverse(['type' => 'media', 'label' => 'T']);
    }

    public function test_link_shape_selects_concrete_acf_type(): void
    {
        self::assertSame('link', $this->mapper->reverse(['type' => 'link', 'shape' => 'link', 'label' => 'T'])['acfType']);
        self::assertSame('url', $this->mapper->reverse(['type' => 'link', 'shape' => 'url', 'label' => 'T'])['acfType']);
    }

    public function test_link_without_shape_defaults_to_link(): void
    {
        self::assertSame('link', $this->mapper->reverse(['type' => 'link', 'label' => 'T'])['acfType']);
    }

    public function test_reference_of_geo_reverses_to_google_map(): void
    {
        self::assertSame('google_map', $this->mapper->reverse(['type' => 'reference', 'of' => 'geo', 'label' => 'T'])['acfType']);
    }

    public function test_reference_of_post_types_reverses_to_post_object_splitting_the_list(): void
    {
        $result = $this->mapper->reverse([
            'type' => 'reference', 'of' => 'post:article,post:page', 'multiple' => true, 'label' => 'T',
        ]);
        self::assertSame('post_object', $result['acfType']);
        self::assertSame(['article', 'page'], $result['extra']['post_type']);
        self::assertSame(1, $result['extra']['multiple']);
    }

    public function test_reference_with_unsupported_of_target_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->reverse(['type' => 'reference', 'of' => 'taxonomy:category', 'label' => 'T']);
    }

    public function test_date_reverses_to_date_picker(): void
    {
        self::assertSame('date_picker', $this->mapper->reverse(['type' => 'date', 'label' => 'T'])['acfType']);
    }

    public function test_group_reverses_to_group(): void
    {
        self::assertSame('group', $this->mapper->reverse(['type' => 'group', 'label' => 'T'])['acfType']);
    }

    public function test_repeater_rebuilds_button_label_from_add_label(): void
    {
        $result = $this->mapper->reverse(['type' => 'repeater', 'label' => 'T', 'add_label' => 'Přidat řádek']);
        self::assertSame('repeater', $result['acfType']);
        self::assertSame('Přidat řádek', $result['extra']['button_label']);
    }

    public function test_repeater_omits_button_label_extra_when_add_label_absent(): void
    {
        $result = $this->mapper->reverse(['type' => 'repeater', 'label' => 'T']);
        self::assertArrayNotHasKey('button_label', $result['extra']);
    }

    public function test_unsupported_abstract_type_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->reverse(['type' => 'not_a_real_type', 'label' => 'T']);
    }
}
