<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\AbstractTypeMapper;

final class AbstractTypeMapperTest extends TestCase
{
    private AbstractTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AbstractTypeMapper();
    }

    public function test_text_and_email_map_to_text_with_no_extras(): void
    {
        foreach (['text', 'email'] as $acfType) {
            $result = $this->mapper->map(['type' => $acfType, 'name' => 'f']);
            self::assertSame('text', $result['type']);
            self::assertSame([], $result['extra']);
            self::assertSame(['type'], $result['consumed']);
        }
    }

    public function test_textarea_maps_to_text_with_multiline(): void
    {
        $result = $this->mapper->map(['type' => 'textarea', 'name' => 'f']);
        self::assertSame('text', $result['type']);
        self::assertSame(['multiline' => true], $result['extra']);
    }

    public function test_wysiwyg_maps_to_richtext(): void
    {
        self::assertSame('richtext', $this->mapper->map(['type' => 'wysiwyg', 'name' => 'f'])['type']);
    }

    public function test_number_and_true_false(): void
    {
        self::assertSame('number', $this->mapper->map(['type' => 'number', 'name' => 'f'])['type']);
        self::assertSame('boolean', $this->mapper->map(['type' => 'true_false', 'name' => 'f'])['type']);
    }

    public function test_select_lifts_choices_and_multiple(): void
    {
        $result = $this->mapper->map([
            'type' => 'select', 'name' => 'f', 'choices' => ['a' => 'A', 'b' => 'B'], 'multiple' => 1,
        ]);
        self::assertSame('select', $result['type']);
        self::assertSame(['options' => ['a' => 'A', 'b' => 'B'], 'multiple' => true], $result['extra']);
        self::assertSame(['type', 'choices', 'multiple'], $result['consumed']);
    }

    public function test_select_omits_multiple_when_falsy(): void
    {
        $result = $this->mapper->map(['type' => 'select', 'name' => 'f', 'choices' => ['a' => 'A'], 'multiple' => 0]);
        self::assertArrayNotHasKey('multiple', $result['extra']);
    }

    public function test_button_group_maps_to_select_with_options(): void
    {
        $result = $this->mapper->map(['type' => 'button_group', 'name' => 'f', 'choices' => ['x' => 'X']]);
        self::assertSame('select', $result['type']);
        self::assertSame(['options' => ['x' => 'X']], $result['extra']);
    }

    public function test_media_types_carry_kind(): void
    {
        self::assertSame(['kind' => 'image'], $this->mapper->map(['type' => 'image', 'name' => 'f'])['extra']);
        self::assertSame(['kind' => 'file'], $this->mapper->map(['type' => 'file', 'name' => 'f'])['extra']);
        self::assertSame(
            ['kind' => 'gallery', 'multiple' => true],
            $this->mapper->map(['type' => 'gallery', 'name' => 'f'])['extra'],
        );
    }

    public function test_link_and_url_carry_shape(): void
    {
        self::assertSame(['shape' => 'link'], $this->mapper->map(['type' => 'link', 'name' => 'f'])['extra']);
        self::assertSame(['shape' => 'url'], $this->mapper->map(['type' => 'url', 'name' => 'f'])['extra']);
    }

    public function test_post_object_joins_post_types_and_lifts_multiple(): void
    {
        $result = $this->mapper->map([
            'type' => 'post_object', 'name' => 'f', 'post_type' => ['article', 'page'], 'multiple' => 1,
        ]);
        self::assertSame('reference', $result['type']);
        self::assertSame('post:article,post:page', $result['extra']['of']);
        self::assertTrue($result['extra']['multiple']);
    }

    public function test_google_map_maps_to_reference_geo(): void
    {
        $result = $this->mapper->map(['type' => 'google_map', 'name' => 'f']);
        self::assertSame('reference', $result['type']);
        self::assertSame(['of' => 'geo'], $result['extra']);
    }

    public function test_date_picker_maps_to_date(): void
    {
        self::assertSame('date', $this->mapper->map(['type' => 'date_picker', 'name' => 'f'])['type']);
    }

    public function test_group_consumes_sub_fields(): void
    {
        $result = $this->mapper->map(['type' => 'group', 'name' => 'f']);
        self::assertSame('group', $result['type']);
        self::assertSame(['type', 'sub_fields'], $result['consumed']);
    }

    public function test_repeater_lifts_button_label_to_add_label(): void
    {
        $result = $this->mapper->map(['type' => 'repeater', 'name' => 'f', 'button_label' => 'Přidat řádek']);
        self::assertSame(['add_label' => 'Přidat řádek'], $result['extra']);
        self::assertSame(['type', 'sub_fields', 'button_label'], $result['consumed']);
    }

    public function test_repeater_omits_add_label_when_button_label_empty(): void
    {
        $result = $this->mapper->map(['type' => 'repeater', 'name' => 'f', 'button_label' => '']);
        self::assertSame([], $result['extra']);
        self::assertSame(['type', 'sub_fields', 'button_label'], $result['consumed']);
    }

    public function test_unsupported_type_throws_domain_exception(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/color_picker/');
        $this->mapper->map(['type' => 'color_picker', 'name' => 'accent']);
    }
}
