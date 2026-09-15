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

    public function test_array_with_nested_fields_is_refused_not_guessed(): void
    {
        // Decided by @parisek on issue #75 / PR #76: `array` is ambiguous
        // between a single nested object and a list, and nothing in the
        // annotation resolves that ambiguity — see the class doc header.
        // Mapping it to `group` would mis-describe a list-shaped field like
        // `categories`.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'categories' has twig type 'array', which is ambiguous between a single nested object and a "
            . "list — re-annotate it as 'group' (one nested object) or 'repeater' (a list) before migrating.",
        );
        $this->mapper->map([
            'type' => 'array',
            'fields' => ['url' => ['type' => 'url']],
        ], 'categories');
    }

    public function test_bare_array_with_no_nested_fields_is_also_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'items' has twig type 'array', which is ambiguous between a single nested object and a "
            . "list — re-annotate it as 'group' (one nested object) or 'repeater' (a list) before migrating.",
        );
        $this->mapper->map(['type' => 'array'], 'items');
    }

    public function test_unknown_twig_type_throws_naming_the_field(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/wpforms/');
        $this->mapper->map(['type' => 'wpforms'], 'form');
    }

    // --- Codex review round 1, finding 1: unrecognised props must never be
    // silently dropped -------------------------------------------------

    public function test_placeholder_is_kept(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'placeholder' => 'you@example.com'], 'email');
        self::assertSame('you@example.com', $out['placeholder']);
    }

    public function test_unrecognised_prop_throws_naming_the_field_and_the_prop(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'search' has twig annotation prop(s) with no mapping to the abstract schema: 'cms_type'. "
            . 'Add a mapping to TwigFieldTypeMapper::map(), or remove the prop from the annotation.',
        );
        $this->mapper->map(['type' => 'text', 'title' => 'Search', 'cms_type' => 'string'], 'search');
    }

    public function test_multiple_unrecognised_props_are_all_named(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'search' has twig annotation prop(s) with no mapping to the abstract schema: 'foo', 'bar'.",
        );
        $this->mapper->map(['type' => 'text', 'foo' => '1', 'bar' => '2'], 'search');
    }

    public function test_unrecognised_prop_on_a_nested_field_names_the_full_path(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Field 'heading.title' has twig annotation prop(s)");
        $this->mapper->map([
            'type' => 'group',
            'fields' => ['title' => ['type' => 'text', 'unmapped' => 'x']],
        ], 'heading');
    }

    // --- Codex review round 1, finding 2: `select` must validate options
    // locally rather than emit schema-invalid YAML ----------------------

    public function test_select_with_no_options_or_choices_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'mode' is a twig 'select' with no (or empty) `options:`/`choices:` — "
            . 'the schema requires at least one option.',
        );
        $this->mapper->map(['type' => 'select'], 'mode');
    }

    public function test_select_with_empty_options_string_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'select', 'options' => ''], 'mode');
    }

    public function test_select_with_empty_choices_map_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'select', 'choices' => []], 'mode');
    }

    public function test_select_with_non_string_choice_label_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'mode' has a non-string option label for choice '1' (int) — "
            . 'the schema requires every option value to be a string.',
        );
        $this->mapper->map(['type' => 'select', 'choices' => [1 => 1]], 'mode');
    }

    public function test_select_on_a_nested_field_names_the_full_path_on_failure(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Field 'items.mode' is a twig 'select'");
        $this->mapper->map([
            'type' => 'repeater',
            'fields' => ['mode' => ['type' => 'select']],
        ], 'items');
    }

    // --- Codex review round 2 -------------------------------------------

    public function test_select_options_as_a_yaml_list_are_read_like_the_comma_shorthand(): void
    {
        // Finding 1: `options: [compact, expanded]` used to be cast
        // straight to a string (PHP's array-to-string coercion), producing
        // a single bogus `Array: Array` option that still passed schema
        // validation.
        $out = $this->mapper->map(['type' => 'select', 'options' => ['compact', 'expanded']], 'mode');
        self::assertSame(['compact' => 'compact', 'expanded' => 'expanded'], $out['options']);
    }

    public function test_select_options_list_with_a_non_scalar_entry_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'select', 'options' => [['nested' => 'array']]], 'mode');
    }

    public function test_select_options_of_the_wrong_type_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'select', 'options' => 42], 'mode');
    }

    public function test_select_with_both_options_and_choices_throws_ambiguity(): void
    {
        // Finding 2: `choices` used to win silently over a present
        // `options`, discarding it with no diagnostic.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Field 'mode' has both `options:` and `choices:` — only one select option source is allowed.",
        );
        $this->mapper->map([
            'type' => 'select',
            'options' => 'a, b',
            'choices' => ['a' => 'A', 'b' => 'B'],
        ], 'mode');
    }

    public function test_select_with_choices_of_the_wrong_type_throws(): void
    {
        // Finding 2: a malformed `choices:` (e.g. a plain string) used to
        // fall through silently to `options` when both were present, or to
        // "no options" when they weren't — either way the bad `choices:`
        // itself was never reported.
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'select', 'choices' => 'not-a-map'], 'mode');
    }

    public function test_required_yes_is_refused_not_silently_ignored(): void
    {
        // Finding 3: any value outside {true, 1, '1', false, 0, '0', null}
        // used to be marked consumed and silently treated as "not
        // required" — the constraint vanished from the migrated field with
        // no diagnostic.
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'text', 'required' => 'yes'], 'title');
    }

    public function test_required_2_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->map(['type' => 'text', 'required' => 2], 'title');
    }

    public function test_required_0_is_accepted_as_not_required(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'required' => 0], 'title');
        self::assertArrayNotHasKey('required', $out);
    }

    public function test_required_false_is_accepted_as_not_required(): void
    {
        $out = $this->mapper->map(['type' => 'text', 'required' => false], 'title');
        self::assertArrayNotHasKey('required', $out);
    }
}
