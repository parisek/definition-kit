<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\AcfJsonReader;

final class AcfJsonReaderTest extends TestCase
{
    private AcfJsonReader $reader;

    protected function setUp(): void
    {
        $this->reader = new AcfJsonReader();
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function group(array $fields, string $key = 'group_demo'): array
    {
        return ['key' => $key, 'title' => 'Demo', 'fields' => $fields];
    }

    public function test_root_name_falls_back_to_acf_title_without_twig(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');
        self::assertSame('Demo', $tree['name']);
    }

    public function test_root_name_prefers_twig_front_comment(): void
    {
        $twig = "{#\nname: Demo (twig)\nusage: homepage\nfields:\n#}\n";
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo', $twig);
        self::assertSame('Demo (twig)', $tree['name']);
        self::assertSame(['homepage'], $tree['usage']);
    }

    public function test_usage_is_distilled_into_a_native_list_even_for_a_single_value(): void
    {
        // `usage` is a multi-value list in the definition: a single twig
        // front-comment value still becomes a 1-element list so the key's
        // type is uniform for every downstream consumer.
        $twig = "{#\nname: Demo\nusage: homepage\nfields:\n#}\n";
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo', $twig);
        self::assertSame(['homepage'], $tree['usage']);
    }

    public function test_usage_comma_string_is_split_into_a_trimmed_list(): void
    {
        // Twig authoring convention is a comma-separated string; the
        // migration splits it into YAML's native sequence, trimming each id
        // and dropping empty entries (trailing comma, doubled comma).
        $twig = "{#\nname: Demo\nusage: 404, article-list ,, homepage,\nfields:\n#}\n";
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo', $twig);
        self::assertSame(['404', 'article-list', 'homepage'], $tree['usage']);
    }

    public function test_root_metadata_description_weight_responsive_are_distilled_and_coerced(): void
    {
        $twig = "{#\nname: Demo\nusage: homepage\ndescription: Demo description\nweight: 20\nresponsive: true\nfields:\n#}\n";
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo', $twig);

        self::assertSame('Demo description', $tree['description']);
        self::assertSame(20, $tree['weight']);
        self::assertTrue($tree['responsive']);
    }

    public function test_kind_is_carried_from_twig_front_comment(): void
    {
        // `kind` (block/section/element/part/utility — ADR 0012) is styleguide
        // root metadata the schema models and KindLinter validates. It must
        // survive migration or the styleguide (YAML-first) reads no kind and
        // every migrated definition trips KindLinter's "declares no kind".
        $twig = "{#\nname: Demo\nusage: homepage\ncategory: Gutenberg\nkind: block\nrender: bleed\nfields:\n#}\n";
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo', $twig);

        self::assertSame('block', $tree['kind']);
    }

    public function test_acf_root_description_is_captured_into_wp_description_not_metadata_description(): void
    {
        // Two DIFFERENT descriptions on the same component: the twig
        // front-comment `description` (component documentation metadata)
        // and the acf.json field-group's own root `description` (an ACF
        // prop, usually the baseline ''). They must land in distinct keys —
        // conflating them would leak twig doc text into acf.json or drop
        // the ACF group's real description.
        $twig = "{#\nname: Demo\nusage: homepage\ndescription: Twig doc description\nfields:\n#}\n";
        $acf = $this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]);
        $acf['description'] = 'ACF group own description';

        $tree = $this->reader->read($acf, 'demo', $twig);

        self::assertSame('Twig doc description', $tree['description']);
        self::assertSame('ACF group own description', $tree['wp']['description']);
    }

    public function test_acf_root_baseline_empty_description_is_not_lifted_into_wp(): void
    {
        $acf = $this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]);
        $acf['description'] = '';

        $tree = $this->reader->read($acf, 'demo');

        self::assertArrayNotHasKey('wp', $tree);
    }

    public function test_root_key_order_is_metadata_then_fields_then_wp_last(): void
    {
        // Accordion presence forces a root `wp:` bag — proves it lands after
        // `fields:`, matching FieldsYamlWriter's dumped key order.
        $twig = "{#\nname: Demo\nusage: homepage\nfields:\n#}\n";
        $acf = $this->group([
            ['key' => 'field_demo_acc', 'type' => 'accordion', 'label' => 'Section', 'open' => 0],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]);
        $tree = $this->reader->read($acf, 'demo', $twig);

        self::assertSame(['name', 'usage', 'fields', 'wp'], array_keys($tree));
    }

    public function test_group_key_matching_convention_is_not_pinned(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ], 'group_demo'), 'demo');
        self::assertArrayNotHasKey('key', $tree);
    }

    public function test_group_key_deviating_from_convention_is_pinned(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_service-feature_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ], 'group_service-feature'), 'service-feature');
        self::assertArrayNotHasKey('key', $tree); // hyphenated slug IS the convention here — no pin
    }

    public function test_accordion_fields_are_dropped(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_acc', 'name' => '', 'type' => 'accordion', 'label' => 'Section'],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');
        self::assertCount(1, $tree['fields']);
        self::assertArrayHasKey('title', $tree['fields']);
    }

    public function test_accordion_metadata_is_captured_into_root_wp_accordions(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_header_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'Hlavička', 'open' => 0],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');

        self::assertSame([[
            'key' => 'field_demo_header_accordion',
            'label' => 'Hlavička',
            'open' => 0,
            'before' => 'title',
        ]], $tree['wp']['accordions']);
    }

    public function test_accordion_nonbaseline_props_are_captured_verbatim_by_real_acf_name(): void
    {
        // Real mairateam accordions (page-header-service) carry section
        // `instructions` and a non-zero `wpml_cf_preferences` where the tool's
        // baseline is '' / 0. The generic self-diff captures every non-baseline
        // prop verbatim, keyed by its real ACF prop name — no per-prop special
        // case. Baseline props (name/type/required/…) stay dropped.
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_header_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'Menu', 'open' => 0, 'instructions' => 'Menu se vypisuje automaticky', 'wpml_cf_preferences' => 1],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');

        self::assertSame([[
            'key' => 'field_demo_header_accordion',
            'label' => 'Menu',
            'open' => 0,
            'instructions' => 'Menu se vypisuje automaticky',
            'wpml_cf_preferences' => 1,
            'before' => 'title',
        ]], $tree['wp']['accordions']);
    }

    public function test_fully_baseline_accordion_captures_no_residual(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_header_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'Hlavička', 'open' => 0, 'instructions' => '', 'wpml_cf_preferences' => 0],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');

        self::assertSame(['key', 'label', 'open', 'before'], array_keys($tree['wp']['accordions'][0]));
    }

    public function test_multiple_accordions_each_capture_their_own_before_field(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_a_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'A', 'open' => 0],
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
            ['key' => 'field_demo_b_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'B', 'open' => 1],
            ['key' => 'field_demo_spacing', 'name' => 'spacing', 'label' => 'Spacing', 'type' => 'select', 'choices' => ['a' => 'A']],
        ]), 'demo');

        self::assertSame('title', $tree['wp']['accordions'][0]['before']);
        self::assertSame(1, $tree['wp']['accordions'][1]['open']);
        self::assertSame('spacing', $tree['wp']['accordions'][1]['before']);
    }

    public function test_trailing_accordion_with_nothing_after_captures_null_before(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
            ['key' => 'field_demo_trailing_accordion', 'name' => '', 'type' => 'accordion', 'label' => 'Trailing', 'open' => 0],
        ]), 'demo');

        self::assertNull($tree['wp']['accordions'][0]['before']);
    }

    public function test_no_accordions_means_no_root_wp_key_at_all(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');

        self::assertArrayNotHasKey('wp', $tree);
    }

    public function test_field_with_no_deviation_carries_no_wp_key(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'instructions' => '', 'required' => 0, 'conditional_logic' => false,
            'wrapper' => ['width' => '', 'class' => '', 'id' => ''], 'aria-label' => '',
            'allow_in_bindings' => 0, 'default_value' => '', 'placeholder' => '',
            'prepend' => '', 'append' => '', 'wpml_cf_preferences' => 1,
        ]]), 'demo');
        self::assertArrayNotHasKey('wp', $tree['fields']['title']);
    }

    public function test_wpml_2_lifts_to_translatable_true(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'wpml_cf_preferences' => 2,
        ]]), 'demo');
        self::assertTrue($tree['fields']['title']['translatable']);
    }

    public function test_wpml_1_does_not_emit_translatable(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'wpml_cf_preferences' => 1,
        ]]), 'demo');
        self::assertArrayNotHasKey('translatable', $tree['fields']['title']);
    }

    public function test_maxlength_lifted_when_nonzero(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text', 'maxlength' => '60',
        ]]), 'demo');
        self::assertSame(60, $tree['fields']['title']['maxlength']);
    }

    public function test_number_min_max_step_lifted_including_zero(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_qty', 'name' => 'qty', 'label' => 'Množství', 'type' => 'number',
            'min' => 0, 'max' => 10, 'step' => 1,
        ]]), 'demo');
        self::assertSame(0, $tree['fields']['qty']['min']);
        self::assertSame(10, $tree['fields']['qty']['max']);
        self::assertSame(1, $tree['fields']['qty']['step']);
    }

    public function test_repeater_min_max_zero_is_omitted_as_acf_no_limit_sentinel(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'repeater',
            'min' => 0, 'max' => 0,
            'sub_fields' => [['key' => 'field_demo_items_v', 'name' => 'v', 'label' => 'V', 'type' => 'text']],
        ]]), 'demo');
        self::assertArrayNotHasKey('min', $tree['fields']['items']);
        self::assertArrayNotHasKey('max', $tree['fields']['items']);
    }

    public function test_repeater_max_nonzero_is_lifted_closing_the_prototype_gap(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'repeater',
            'min' => 0, 'max' => 3,
            'sub_fields' => [['key' => 'field_demo_items_v', 'name' => 'v', 'label' => 'V', 'type' => 'text']],
        ]]), 'demo');
        self::assertSame(3, $tree['fields']['items']['max']);
    }

    public function test_mime_types_lifted_to_accept_list(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_doc', 'name' => 'doc', 'label' => 'Dokument', 'type' => 'file',
            'mime_types' => 'pdf, docx',
        ]]), 'demo');
        self::assertSame(['pdf', 'docx'], $tree['fields']['doc']['accept']);
    }

    public function test_placeholder_lifted_when_nonempty(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'placeholder' => 'Zadejte nadpis',
        ]]), 'demo');
        self::assertSame('Zadejte nadpis', $tree['fields']['title']['placeholder']);
    }

    public function test_visible_when_lifted_from_conditional_logic(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
            [
                'key' => 'field_demo_sub', 'name' => 'sub', 'label' => 'Sub', 'type' => 'text',
                'conditional_logic' => [[['field' => 'field_demo_title', 'operator' => '!=empty']]],
            ],
        ]), 'demo');
        self::assertSame(['field' => 'title', 'not_empty' => true], $tree['fields']['sub']['visible_when']);
    }

    public function test_unmappable_conditional_logic_falls_back_to_wp(): void
    {
        $cl = [[
            ['field' => 'field_x', 'operator' => '==', 'value' => 'a'],
            ['field' => 'field_y', 'operator' => '==', 'value' => 'b'],
        ]];
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_sub', 'name' => 'sub', 'label' => 'Sub', 'type' => 'text',
            'conditional_logic' => $cl,
        ]]), 'demo');
        self::assertArrayNotHasKey('visible_when', $tree['fields']['sub']);
        self::assertSame($cl, $tree['fields']['sub']['wp']['conditional_logic']);
    }

    public function test_field_key_matching_convention_is_not_pinned(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');
        self::assertArrayNotHasKey('key', $tree['fields']['title']);
    }

    public function test_field_key_deviating_from_convention_is_pinned(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_legacy_hash_abc123', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');
        self::assertSame('field_demo_legacy_hash_abc123', $tree['fields']['title']['key']);
    }

    public function test_nested_group_key_uses_dotted_name_chain(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_heading', 'name' => 'heading', 'label' => 'Nadpis', 'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_demo_heading_title', 'name' => 'title', 'label' => 'T', 'type' => 'text'],
            ],
        ]]), 'demo');
        self::assertArrayNotHasKey('key', $tree['fields']['heading']['fields']['title']);
    }

    public function test_leftover_non_default_prop_lands_in_wp(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_body', 'name' => 'body', 'label' => 'Text', 'type' => 'wysiwyg',
            'toolbar' => 'full', // deviates from baseline default 'basic'
        ]]), 'demo');
        self::assertSame('full', $tree['fields']['body']['wp']['toolbar']);
    }

    public function test_mcp_and_dev_are_never_emitted_by_the_migrator(): void
    {
        $tree = $this->reader->read($this->group([
            ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
        ]), 'demo');
        self::assertArrayNotHasKey('mcp', $tree['fields']['title']);
        self::assertArrayNotHasKey('dev', $tree['fields']['title']);
    }

    public function test_group_with_zero_non_accordion_sub_fields_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reader->read($this->group([[
            'key' => 'field_demo_g', 'name' => 'g', 'label' => 'G', 'type' => 'group',
            'sub_fields' => [['key' => 'field_demo_g_acc', 'name' => '', 'type' => 'accordion', 'label' => 'S']],
        ]]), 'demo');
    }

    // --- flexible_content ---------------------------------------------

    public function test_flexible_content_lifts_layouts_keyed_by_name(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'button_label' => 'Add Položky', 'min' => 2, 'max' => 2,
            'layouts' => [
                [
                    'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                    'sub_fields' => [
                        ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                    ],
                    'min' => '', 'max' => '', 'location' => null,
                ],
                [
                    'key' => 'layout_demo_items_image', 'name' => 'image', 'label' => 'Obrázek', 'display' => 'block',
                    'sub_fields' => [
                        ['key' => 'field_demo_items_image_image', 'name' => 'image', 'label' => 'Obrázek', 'type' => 'image'],
                    ],
                    'min' => '', 'max' => '', 'location' => null,
                ],
            ],
        ]]), 'demo');

        $items = $tree['fields']['items'];
        self::assertSame('flexible_content', $items['type']);
        self::assertSame('Add Položky', $items['add_label']);
        self::assertSame(2, $items['min']);
        self::assertSame(2, $items['max']);
        self::assertSame(['title', 'image'], array_keys($items['layouts']));
        self::assertSame('Nadpis', $items['layouts']['title']['label']);
        // Finding 1 (round 3) — a layout's key is ALWAYS pinned, even when
        // it matches the derived convention. Unlike ordinary fields (whose
        // `name` IS the identity, so omit-if-matching is safe), a layout's
        // YAML map key is cosmetic — only `key` is the real ACF identity.
        // Always pinning means renaming the map key later can never
        // silently re-derive a different key. See
        // test_flexible_content_layout_key_is_pinned_even_when_matching_convention_so_renaming_the_map_key_is_safe.
        self::assertSame('layout_demo_items_title', $items['layouts']['title']['key']);
        self::assertSame('text', $items['layouts']['title']['fields']['title']['type']);
        self::assertSame('media', $items['layouts']['image']['fields']['image']['type']);
        self::assertSame('image', $items['layouts']['image']['fields']['image']['kind']);
    }

    public function test_flexible_content_layout_key_deviating_from_convention_is_pinned(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_legacy_hash_abc123', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');

        self::assertSame('layout_demo_legacy_hash_abc123', $tree['fields']['items']['layouts']['title']['key']);
    }

    /**
     * Finding 1 (round 3, CRITICAL) — the regression this pinning fix
     * exists for. Migrate a layout whose ACF key happens to already match
     * the derived convention (so, pre-fix, AcfJsonReader would NOT have
     * pinned it), then simulate a maintainer renaming the layout's YAML
     * map key (`title` -> `heading`) — a routine, innocent-looking
     * refactor. Assert the migrated layout's `key` is present and
     * unchanged by that rename: FieldsGenerator must regenerate the exact
     * same `layout_demo_items_title` key regardless of the map key,
     * because AcfJsonReader always pins it verbatim at migration time.
     * Before the fix, this key would silently re-derive to
     * `layout_demo_items_heading`, orphaning any `acf_fc_layout: "title"`
     * postmeta already stored in production.
     */
    public function test_flexible_content_layout_key_is_pinned_even_when_matching_convention_so_renaming_the_map_key_is_safe(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');

        $migratedLayout = $tree['fields']['items']['layouts']['title'];
        self::assertSame('layout_demo_items_title', $migratedLayout['key']);

        // Simulate the maintainer renaming the map key post-migration —
        // the YAML the generator now sees carries the SAME pinned `key`
        // under a different map key.
        $renamedLayouts = ['heading' => $migratedLayout];
        $renamedTree = $tree;
        $renamedTree['fields']['items']['layouts'] = $renamedLayouts;

        $generated = (new \Parisek\DefinitionKit\Generator\FieldsGenerator())->generate($renamedTree, 'demo', 1);
        $regeneratedLayout = $generated['fields'][0]['layouts'][0];

        self::assertSame('heading', $regeneratedLayout['name']);
        self::assertSame(
            'layout_demo_items_title',
            $regeneratedLayout['key'],
            'Renaming the layout map key must not change its ACF key — otherwise '
            . 'every acf_fc_layout="title" value already stored in postmeta is orphaned.',
        );
    }

    public function test_flexible_content_min_max_zero_is_omitted_as_acf_no_limit_sentinel(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'min' => 0, 'max' => 0,
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');
        self::assertArrayNotHasKey('min', $tree['fields']['items']);
        self::assertArrayNotHasKey('max', $tree['fields']['items']);
    }

    public function test_flexible_content_wpml_cf_preferences_is_never_lifted_to_translatable(): void
    {
        // Real corpus shows 1/2 on some projects, absent on others, never 3
        // — never lift to `translatable`, whatever value is present.
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'wpml_cf_preferences' => 2,
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');
        self::assertArrayNotHasKey('translatable', $tree['fields']['items']);
        self::assertSame(2, $tree['fields']['items']['wp']['wpml_cf_preferences']);
    }

    public function test_flexible_content_absent_wpml_cf_preferences_leaves_no_wp_trace(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');
        self::assertArrayNotHasKey('wpml_cf_preferences', $tree['fields']['items']['wp'] ?? []);
    }

    public function test_flexible_content_layout_sub_field_carries_no_parent_repeater_key_expectation(): void
    {
        // Nested key derivation treats the layout name as just another
        // nesting segment — field_<slug>_<fcName>_<layoutName>_<subName>.
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_cta', 'name' => 'cta', 'label' => 'CTA', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_cta_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');
        self::assertArrayNotHasKey('key', $tree['fields']['items']['layouts']['cta']['fields']['title']);
    }

    public function test_flexible_content_with_zero_layouts_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [],
        ]]), 'demo');
    }

    public function test_flexible_content_layout_with_zero_non_accordion_sub_fields_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_empty', 'name' => 'empty', 'label' => 'Empty', 'display' => 'block',
                'sub_fields' => [['key' => 'field_demo_items_empty_acc', 'name' => '', 'type' => 'accordion', 'label' => 'S']],
            ]],
        ]]), 'demo');
    }

    /**
     * Finding B (CRITICAL) — two layouts sharing the same `name` collapse
     * into one PHP array key (`$out[$layoutName] = …`) with no collision
     * check: the earlier layout AND its key silently vanish. Reproduced
     * live against a synthetic ACF export by an adversarial reviewer.
     * The reader must throw, matching the style of the adjacent
     * empty-layouts / empty-sub-fields guards above.
     */
    public function test_flexible_content_duplicate_layout_names_throws_instead_of_silently_overwriting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [
                [
                    'key' => 'layout_demo_items_title_v1', 'name' => 'title', 'label' => 'Nadpis V1', 'display' => 'block',
                    'sub_fields' => [
                        ['key' => 'field_demo_items_title_v1_a', 'name' => 'a', 'label' => 'A', 'type' => 'text'],
                    ],
                ],
                [
                    'key' => 'layout_demo_items_title_v2', 'name' => 'title', 'label' => 'Nadpis V2', 'display' => 'block',
                    'sub_fields' => [
                        ['key' => 'field_demo_items_title_v2_b', 'name' => 'b', 'label' => 'B', 'type' => 'text'],
                    ],
                ],
            ],
        ]]), 'demo');
    }

    /**
     * Finding C (CRITICAL) — a layout authored with a non-default
     * `display` (`table` / `row`) must round-trip verbatim, not be
     * silently dropped (the generator side currently hardcodes `block`
     * unconditionally, so the reader must actually capture the raw
     * value for the round-trip to be possible at all).
     */
    public function test_flexible_content_layout_non_default_display_is_captured(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'table',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');

        self::assertSame('table', $tree['fields']['items']['layouts']['title']['wp']['display']);
    }

    public function test_flexible_content_layout_default_display_leaves_no_wp_trace(): void
    {
        $tree = $this->reader->read($this->group([[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Položky', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_title', 'name' => 'title', 'label' => 'Nadpis', 'display' => 'block',
                'sub_fields' => [
                    ['key' => 'field_demo_items_title_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text'],
                ],
            ]],
        ]]), 'demo');

        self::assertArrayNotHasKey('wp', $tree['fields']['items']['layouts']['title']);
    }
}
