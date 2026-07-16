<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\RootFieldGroupBuilder;

final class RootFieldGroupBuilderTest extends TestCase
{
    private RootFieldGroupBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RootFieldGroupBuilder();
    }

    public function test_root_boilerplate_defaults(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(0, $group['menu_order']);
        self::assertSame('normal', $group['position']);
        self::assertSame('default', $group['style']);
        self::assertSame('top', $group['label_placement']);
        self::assertSame('label', $group['instruction_placement']);
        self::assertSame('', $group['hide_on_screen']);
        self::assertTrue($group['active']);
        self::assertSame('', $group['description']);
        self::assertSame(0, $group['show_in_rest']);
        self::assertSame('advanced', $group['acfml_field_group_mode']);
    }

    public function test_key_derives_by_convention_when_not_pinned(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        self::assertSame('group_demo', $group['key']);
    }

    public function test_key_is_pinned_when_present_on_the_tree(): void
    {
        $tree = ['name' => 'Demo', 'key' => 'group_legacy_hash', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        self::assertSame('group_legacy_hash', $group['key']);
    }

    public function test_title_comes_from_tree_name(): void
    {
        $tree = ['name' => 'Service - feature', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'service-feature', 1700000000);
        self::assertSame('Service - feature', $group['title']);
    }

    public function test_location_derives_from_component_slug(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'service-feature', 1700000000);
        self::assertSame(
            [[['param' => 'block', 'operator' => '==', 'value' => 'acf/service-feature']]],
            $group['location'],
        );
    }

    public function test_modified_is_the_injected_value_never_computed_internally(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1234567890);
        self::assertSame(1234567890, $group['modified']);
    }

    public function test_root_wp_overrides_a_default(): void
    {
        $tree = ['name' => 'Demo', 'wp' => ['show_in_rest' => 1], 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        self::assertSame(1, $group['show_in_rest']);
    }

    public function test_root_wp_accordions_key_never_leaks_into_the_root_object(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'before' => 'title']]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        self::assertArrayNotHasKey('accordions', $group);
    }

    public function test_root_wp_block_key_never_leaks_into_the_acf_group(): void
    {
        // `wp.block` is block.json-only config; it must not pollute the acf.json
        // field-group root when the rest of the root `wp:` bag is merged in.
        $tree = [
            'name' => 'Demo',
            'wp' => ['block' => ['acf' => ['postTypes' => ['page']]], 'description' => 'Group desc'],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        self::assertArrayNotHasKey('block', $group);
        self::assertSame('Group desc', $group['description']);
    }

    public function test_no_accordions_means_raw_fields_pass_through_unchanged(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'T']]];
        $rawFields = [['type' => 'text', 'name' => 'title']];
        $group = $this->builder->build($tree, $rawFields, 'demo', 1700000000);
        self::assertSame($rawFields, $group['fields']);
    }

    public function test_single_accordion_is_inserted_before_its_before_field(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_header_accordion', 'label' => 'Hlavička', 'open' => 0, 'before' => 'heading']]],
            'fields' => ['heading' => ['type' => 'group', 'label' => 'H', 'fields' => []]],
        ];
        $group = $this->builder->build($tree, [['type' => 'group', 'name' => 'heading']], 'demo', 1700000000);

        self::assertCount(2, $group['fields']);
        self::assertSame('accordion', $group['fields'][0]['type']);
        self::assertSame('field_demo_header_accordion', $group['fields'][0]['key']);
        self::assertSame('Hlavička', $group['fields'][0]['label']);
        self::assertSame(0, $group['fields'][0]['open']);
        self::assertSame('group', $group['fields'][1]['type']);
    }

    public function test_reconstructed_accordion_pseudo_field_carries_the_fixed_acf_boilerplate(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 1, 'before' => 'title']]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);
        $accordion = $group['fields'][0];

        self::assertSame('', $accordion['name']);
        self::assertSame(0, $accordion['allow_in_bindings']);
        self::assertSame('', $accordion['aria-label']);
        self::assertSame('', $accordion['instructions']);
        self::assertSame(0, $accordion['required']);
        self::assertSame(0, $accordion['conditional_logic']);
        self::assertSame(['width' => '', 'class' => '', 'id' => ''], $accordion['wrapper']);
        self::assertSame(0, $accordion['wpml_cf_preferences']);
        self::assertSame(0, $accordion['multi_expand']);
        self::assertSame(0, $accordion['endpoint']);
    }

    public function test_accordion_replays_captured_nonzero_wpml(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'wpml' => 1, 'before' => 'title']]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(1, $group['fields'][0]['wpml_cf_preferences']);
    }

    public function test_accordion_without_captured_wpml_defaults_to_zero(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'before' => 'title']]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(0, $group['fields'][0]['wpml_cf_preferences']);
    }

    public function test_multiple_accordions_each_placed_before_their_own_field(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                ['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'before' => 'title'],
                ['key' => 'field_demo_b', 'label' => 'B', 'open' => 1, 'before' => 'spacing'],
            ]],
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'T'],
                'spacing' => ['type' => 'select', 'label' => 'S', 'options' => ['a' => 'A']],
            ],
        ];
        $group = $this->builder->build($tree, [
            ['type' => 'text', 'name' => 'title'],
            ['type' => 'select', 'name' => 'spacing'],
        ], 'demo', 1700000000);

        self::assertSame(
            ['accordion', 'text', 'accordion', 'select'],
            array_column($group['fields'], 'type'),
        );
    }

    public function test_trailing_accordion_with_null_before_is_appended_at_the_end(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_trailing', 'label' => 'Trailing', 'open' => 0, 'before' => null]]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(['text', 'accordion'], array_column($group['fields'], 'type'));
    }
}
