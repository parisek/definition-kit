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

    public function test_accordion_replays_captured_residual_verbatim(): void
    {
        // Residual props (real ACF names) overlay the baseline pseudo-field.
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [[
                'key' => 'field_demo_a', 'label' => 'Menu', 'open' => 0,
                'instructions' => 'Menu se vypisuje automaticky', 'wpml_cf_preferences' => 1,
                'before' => 'title',
            ]]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(1, $group['fields'][0]['wpml_cf_preferences']);
        self::assertSame('Menu se vypisuje automaticky', $group['fields'][0]['instructions']);
    }

    public function test_accordion_without_residual_keeps_baseline_values(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'before' => 'title']]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(0, $group['fields'][0]['wpml_cf_preferences']);
        self::assertSame('', $group['fields'][0]['instructions']);
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

    // --- mixed accordion + message ordering at one anchor ------------------
    //
    // Migration\AcfJsonReader::flushPendingPseudo() attaches `seq` ONLY when
    // an anchor mixes both pseudo-field kinds — never on a single-kind
    // anchor (every test above this point has none, and stays byte-for-byte
    // unaffected). These tests exercise `seq` doing its job: reproducing
    // the true authored order in every mixed shape observed or plausible —
    // message-then-accordion (the real corpus shape, umbili's image-promo),
    // its reverse, and a trailing mixed sequence.

    public function test_message_then_accordion_at_one_anchor_preserves_authored_order_via_seq(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => [
                'messages' => [['key' => 'field_demo_msg', 'label' => 'M', 'name' => '', 'message' => 'Hi', 'before' => 'title', 'seq' => 0]],
                'accordions' => [['key' => 'field_demo_acc', 'label' => 'A', 'open' => 0, 'before' => 'title', 'seq' => 1]],
            ],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(['message', 'accordion', 'text'], array_column($group['fields'], 'type'));
        self::assertArrayNotHasKey('seq', $group['fields'][0]);
        self::assertArrayNotHasKey('seq', $group['fields'][1]);
    }

    public function test_accordion_then_message_at_one_anchor_preserves_authored_order_via_seq(): void
    {
        // The reverse stacking of the case above — no example in the fleet,
        // but `seq` must reproduce it identically either way; the fixed
        // "messages ahead of accordions" fallback only applies when NEITHER
        // entry carries a `seq` at all.
        $tree = [
            'name' => 'Demo',
            'wp' => [
                'accordions' => [['key' => 'field_demo_acc', 'label' => 'A', 'open' => 0, 'before' => 'title', 'seq' => 0]],
                'messages' => [['key' => 'field_demo_msg', 'label' => 'M', 'name' => '', 'message' => 'Hi', 'before' => 'title', 'seq' => 1]],
            ],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(['accordion', 'message', 'text'], array_column($group['fields'], 'type'));
    }

    public function test_trailing_mixed_sequence_preserves_authored_order_via_seq(): void
    {
        $tree = [
            'name' => 'Demo',
            'wp' => [
                'accordions' => [['key' => 'field_demo_acc', 'label' => 'A', 'open' => 0, 'before' => null, 'seq' => 1]],
                'messages' => [['key' => 'field_demo_msg', 'label' => 'M', 'name' => '', 'message' => 'Hi', 'before' => null, 'seq' => 0]],
            ],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertSame(['text', 'message', 'accordion'], array_column($group['fields'], 'type'));
    }

    public function test_single_kind_anchor_never_carries_seq_and_leaks_none(): void
    {
        // Guards the byte-stability claim: a pure-accordion (or pure-message)
        // anchor's replayed pseudo-field must not carry a `seq` key even
        // when the CAPTURED wp.accordions/wp.messages entry has one attached
        // by mistake (e.g. hand-authored YAML) — it is always excluded from
        // the overlay via ACCORDION_RESIDUAL_EXCLUDED_PROPS/
        // MESSAGE_RESIDUAL_EXCLUDED_PROPS, never just omitted by convention.
        $tree = [
            'name' => 'Demo',
            'wp' => ['accordions' => [['key' => 'field_demo_acc', 'label' => 'A', 'open' => 0, 'before' => 'title', 'seq' => 0]]],
            'fields' => ['title' => ['type' => 'text', 'label' => 'T']],
        ];
        $group = $this->builder->build($tree, [['type' => 'text', 'name' => 'title']], 'demo', 1700000000);

        self::assertArrayNotHasKey('seq', $group['fields'][0]);
    }
}
