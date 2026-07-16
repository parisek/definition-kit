<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Baseline;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Baseline\TypeDefaults;

final class TypeDefaultsTest extends TestCase
{
    public function test_for_type_merges_common_and_type_specific_defaults(): void
    {
        $defaults = (new TypeDefaults())->forType('text');
        self::assertSame('', $defaults['placeholder']);
        self::assertSame(0, $defaults['allow_in_bindings']); // from `common`
    }

    public function test_is_default_true_for_matching_common_prop(): void
    {
        self::assertTrue((new TypeDefaults())->isDefault('text', 'allow_in_bindings', 0));
    }

    public function test_is_default_handles_loose_bool_int_equivalence(): void
    {
        self::assertTrue((new TypeDefaults())->isDefault('true_false', 'default_value', false));
    }

    public function test_is_default_false_for_deviating_value(): void
    {
        self::assertFalse((new TypeDefaults())->isDefault('select', 'return_format', 'label'));
    }

    public function test_is_default_false_for_prop_not_in_baseline(): void
    {
        self::assertFalse((new TypeDefaults())->isDefault('text', 'instructions', ''));
    }

    public function test_strip_defaults_removes_only_matching_props(): void
    {
        $field = [
            'type' => 'text',
            'name' => 'title',
            'label' => 'Title',
            'placeholder' => 'Zadejte nadpis', // deviates
            'prepend' => '', // matches default
            'allow_in_bindings' => 0, // matches common default
        ];
        $stripped = (new TypeDefaults())->stripDefaults('text', $field);
        self::assertSame(
            ['type' => 'text', 'name' => 'title', 'label' => 'Title', 'placeholder' => 'Zadejte nadpis'],
            $stripped,
        );
    }

    public function test_for_type_falls_back_to_common_only_for_unknown_type(): void
    {
        $defaults = (new TypeDefaults())->forType('unknown_type');
        self::assertSame(0, $defaults['allow_in_bindings']);
        self::assertArrayNotHasKey('placeholder', $defaults);
    }
}
