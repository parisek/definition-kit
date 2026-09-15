<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\TwigFieldTypeMapper;

final class TwigFieldTypeMapperTest extends TestCase
{
    private TwigFieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TwigFieldTypeMapper();
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function simpleTypeCases(): iterable
    {
        yield 'text' => [['type' => 'text'], ['type' => 'text', 'role' => 'parent']];
        yield 'textarea' => [['type' => 'textarea'], ['type' => 'text', 'multiline' => true, 'role' => 'parent']];
        yield 'wysiwyg' => [['type' => 'wysiwyg'], ['type' => 'richtext', 'role' => 'parent']];
        yield 'html' => [['type' => 'html'], ['type' => 'richtext', 'wp' => ['twig_type' => 'html'], 'role' => 'parent']];
        yield 'url' => [['type' => 'url'], ['type' => 'link', 'shape' => 'url', 'role' => 'parent']];
        yield 'link' => [['type' => 'link'], ['type' => 'link', 'shape' => 'link', 'role' => 'parent']];
        yield 'email' => [['type' => 'email'], ['type' => 'text', 'wp' => ['acf_type' => 'email'], 'role' => 'parent']];
        yield 'phone' => [['type' => 'phone'], ['type' => 'text', 'wp' => ['acf_type' => 'phone'], 'role' => 'parent']];
        yield 'number' => [['type' => 'number'], ['type' => 'number', 'role' => 'parent']];
        yield 'boolean' => [['type' => 'boolean'], ['type' => 'boolean', 'role' => 'parent']];
        yield 'true_false' => [['type' => 'true_false'], ['type' => 'boolean', 'wp' => ['acf_type' => 'true_false'], 'role' => 'parent']];
        yield 'image' => [['type' => 'image'], ['type' => 'media', 'kind' => 'image', 'role' => 'parent']];
        yield 'file' => [['type' => 'file'], ['type' => 'media', 'kind' => 'file', 'role' => 'parent']];
        yield 'gallery' => [['type' => 'gallery'], ['type' => 'media', 'kind' => 'gallery', 'multiple' => true, 'role' => 'parent']];
        yield 'video' => [['type' => 'video'], ['type' => 'media', 'kind' => 'file', 'wp' => ['twig_type' => 'video'], 'role' => 'parent']];
        yield 'date' => [['type' => 'date'], ['type' => 'date', 'role' => 'parent']];
        yield 'post_object' => [['type' => 'post_object'], ['type' => 'reference', 'role' => 'parent']];
    }

    /**
     * @param array<string, mixed> $twigField
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('simpleTypeCases')]
    public function test_maps_each_twig_type_to_its_abstract_shape(array $twigField, array $expected): void
    {
        self::assertSame($expected, $this->mapper->map($twigField, 'field'));
    }

    public function test_title_becomes_label(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'title' => 'Icon'], 'icon');
        self::assertSame('Icon', $out['label']);
    }

    public function test_description_is_kept(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'description' => 'Icon name from icons.twig'], 'icon');
        self::assertSame('Icon name from icons.twig', $out['description']);
    }

    public function test_required_1_becomes_boolean_true(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'required' => 1], 'title');
        self::assertTrue($out['required']);
    }

    public function test_required_is_omitted_when_absent(): void
    {
        $out = $this->mapper->map(['type' => 'text'], 'title');
        self::assertArrayNotHasKey('required', $out);
    }

    public function test_select_comma_options_become_a_key_equals_value_map(): void
    {
        $out = $this->mapper->map(['type' => 'select', 'options' => '_blank, _self'], 'target');
        self::assertSame(['_blank' => '_blank', '_self' => '_self'], $out['options']);
    }

    public function test_select_choices_map_is_used_verbatim(): void
    {
        $out = $this->mapper->map([
            'type' => 'select',
            'choices' => ['facebook' => 'Facebook', 'instagram' => 'Instagram'],
        ], 'icon');
        self::assertSame(['facebook' => 'Facebook', 'instagram' => 'Instagram'], $out['options']);
    }

    public function test_group_recurses_into_nested_fields(): void
    {
        $out = $this->mapper->map([
            'type' => 'group',
            'fields' => [
                'title' => ['type' => 'text', 'required' => 1],
                'perex' => ['type' => 'textarea'],
            ],
        ], 'heading');

        self::assertSame('group', $out['type']);
        self::assertSame(['type' => 'text', 'required' => true, 'role' => 'parent'], $out['fields']['title']);
        self::assertSame(['type' => 'text', 'multiline' => true, 'role' => 'parent'], $out['fields']['perex']);
    }

    public function test_repeater_recurses_into_nested_fields(): void
    {
        $out = $this->mapper->map([
            'type' => 'repeater',
            'fields' => ['url' => ['type' => 'url']],
        ], 'items');

        self::assertSame('repeater', $out['type']);
        self::assertSame(['type' => 'link', 'shape' => 'url', 'role' => 'parent'], $out['fields']['url']);
    }

    public function test_group_with_no_nested_fields_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mapper->map(['type' => 'group'], 'empty');
    }

    public function test_array_with_nested_fields_resolves_to_group(): void
    {
        // See the class doc header for why `array` resolves to `group` rather
        // than `repeater` — the twig annotation carries no cardinality.
        $out = $this->mapper->map([
            'type' => 'array',
            'fields' => ['url' => ['type' => 'url']],
        ], 'categories');

        self::assertSame('group', $out['type']);
        self::assertSame('array', $out['wp']['twig_type']);
        self::assertSame(['type' => 'link', 'shape' => 'url', 'role' => 'parent'], $out['fields']['url']);
    }

    public function test_array_with_no_nested_fields_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mapper->map(['type' => 'array'], 'items');
    }

    public function test_unknown_twig_type_throws_naming_the_field(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/wpforms/');
        $this->mapper->map(['type' => 'wpforms'], 'form');
    }
}
