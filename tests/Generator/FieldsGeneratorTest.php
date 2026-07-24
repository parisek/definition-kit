<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\FieldsGenerator;

final class FieldsGeneratorTest extends TestCase
{
    private FieldsGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FieldsGenerator();
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $rootExtra
     * @return array<string,mixed>
     */
    private function tree(array $fields, array $rootExtra = []): array
    {
        return [...$rootExtra, 'name' => $rootExtra['name'] ?? 'Demo', 'fields' => $fields];
    }

    public function test_field_gets_baseline_defaults_merged_in(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ]), 'demo', 1700000000);

        $field = $group['fields'][0];
        self::assertSame('', $field['placeholder']); // baseline text default
        self::assertSame(0, $field['allow_in_bindings']); // baseline common default
    }

    public function test_constraint_sentinel_fills_in_when_not_authored(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ]), 'demo', 1700000000);
        self::assertSame('', $group['fields'][0]['maxlength']);
    }

    public function test_authored_constraint_overrides_the_sentinel(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'maxlength' => 60],
        ]), 'demo', 1700000000);
        self::assertSame(60, $group['fields'][0]['maxlength']);
    }

    public function test_wp_overlay_wins_over_baseline_and_reconstruction(): void
    {
        $group = $this->generator->generate($this->tree([
            'body' => ['type' => 'richtext', 'label' => 'Text', 'wp' => ['toolbar' => 'full']],
        ]), 'demo', 1700000000);
        self::assertSame('full', $group['fields'][0]['toolbar']);
    }

    /**
     * `wp.acf_type` is AbstractTypeMapper's own migration-time
     * disambiguation marker (see AbstractTypeReverseMapper) — it is
     * consumed to pick the concrete ACF type ('email' here, since the
     * abstract 'text' vocabulary collapses text/email onto one
     * signature) and must NEVER be overlaid verbatim into the generated
     * acf.json field, which has no such prop in real ACF exports.
     */
    public function test_wp_acf_type_marker_is_stripped_not_leaked_into_generated_output(): void
    {
        $group = $this->generator->generate($this->tree([
            'contact_email' => ['type' => 'text', 'label' => 'E-mail', 'wp' => ['acf_type' => 'email']],
        ]), 'demo', 1700000000);

        $field = $group['fields'][0];
        self::assertSame('email', $field['type']);
        self::assertArrayNotHasKey('acf_type', $field);
    }

    /**
     * Root component-metadata keys (usage/category/render/web/asana/figma/
     * drupal/description/weight/responsive) are authoring-only annotation —
     * only `name` maps to acf `title`. None of them are real ACF field-group
     * props, so none may leak into the generated field group.
     *
     * `description` is the one exception worth calling out: real ACF field
     * groups DO have their own root `description` prop (RootFieldGroupBuilder
     * ::ROOT_DEFAULTS always emits `''`), so the key itself is legitimately
     * present — the assertion here is that its VALUE is the ACF baseline,
     * never the authored component-metadata text.
     */
    public function test_root_metadata_keys_never_leak_into_generated_acf_json(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'usage' => 'homepage-v2',
            'category' => 'Gutenberg',
            'render' => 'bleed',
            'web' => 'https://example.com/demo',
            'asana' => 'https://app.asana.com/1/1/task/1',
            'figma' => 'https://figma.com/file/x?node-id=1-2',
            'drupal' => 'paragraph--demo',
            'description' => 'Demo description',
            'weight' => 20,
            'responsive' => true,
        ]), 'demo', 1700000000);

        foreach (['usage', 'category', 'render', 'web', 'asana', 'figma', 'drupal', 'weight', 'responsive'] as $metaKey) {
            self::assertArrayNotHasKey($metaKey, $group, "root metadata key '{$metaKey}' leaked into generated acf.json");
        }
        self::assertSame('', $group['description'], 'authored root description leaked into the real ACF description prop');
        self::assertSame('Demo', $group['title']);
    }

    public function test_key_derives_by_convention(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ]), 'demo', 1700000000);
        self::assertSame('field_demo_title', $group['fields'][0]['key']);
    }

    public function test_key_is_pinned_when_authored(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'key' => 'field_demo_legacy_hash'],
        ]), 'demo', 1700000000);
        self::assertSame('field_demo_legacy_hash', $group['fields'][0]['key']);
    }

    public function test_name_comes_from_the_fields_map_key(): void
    {
        $group = $this->generator->generate($this->tree([
            'product_title' => ['type' => 'text', 'label' => 'T'],
        ]), 'demo', 1700000000);
        self::assertSame('product_title', $group['fields'][0]['name']);
    }

    public function test_group_recurses_and_derives_dotted_child_keys(): void
    {
        $group = $this->generator->generate($this->tree([
            'heading' => ['type' => 'group', 'label' => 'H', 'fields' => [
                'title' => ['type' => 'text', 'label' => 'T'],
            ]],
        ]), 'demo', 1700000000);

        $headingField = $group['fields'][0];
        self::assertSame('group', $headingField['type']);
        self::assertCount(1, $headingField['sub_fields']);
        self::assertSame('field_demo_heading_title', $headingField['sub_fields'][0]['key']);
        self::assertArrayNotHasKey('parent_repeater', $headingField['sub_fields'][0]);
    }

    public function test_repeater_sub_fields_carry_parent_repeater(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'repeater', 'label' => 'I', 'fields' => [
                'label' => ['type' => 'text', 'label' => 'L'],
            ]],
        ]), 'demo', 1700000000);

        $itemsField = $group['fields'][0];
        self::assertSame('field_demo_items', $itemsField['key']);
        self::assertSame('field_demo_items', $itemsField['sub_fields'][0]['parent_repeater']);
    }

    /**
     * Verified against the real ACF Pro plugin source (see FieldsGenerator's
     * docblock at the `parent_repeater` assignment): a repeater's own
     * `load_field()` array_map()s `parent_repeater` onto every DIRECT
     * sub_field regardless of that sub_field's own type — the group
     * container itself gets it — but `group`'s `load_field()` has no such
     * propagation, so the group's OWN children (the repeater's
     * grandchildren) get nothing at all.
     */
    public function test_parent_repeater_lands_on_the_group_container_but_not_its_grandchildren(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'repeater', 'label' => 'I', 'fields' => [
                'meta' => ['type' => 'group', 'label' => 'M', 'fields' => [
                    'label' => ['type' => 'text', 'label' => 'L'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        $metaField = $group['fields'][0]['sub_fields'][0];
        self::assertSame('field_demo_items', $metaField['parent_repeater']); // the group container carries it
        self::assertArrayNotHasKey('parent_repeater', $metaField['sub_fields'][0]); // its children don't
    }

    /**
     * repeater -> repeater case (the real corpus shape, e.g.
     * reference-detail's items -> tags/stats): the inner repeater is a
     * direct child of the outer one, so it carries `parent_repeater`
     * pointing at the OUTER repeater's key; the inner repeater's own
     * children then carry `parent_repeater` pointing at the INNER
     * repeater's key (nearest enclosing repeater, not the outermost).
     */
    public function test_parent_repeater_on_a_nested_repeater_points_at_the_nearest_enclosing_one(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'repeater', 'label' => 'I', 'fields' => [
                'tags' => ['type' => 'repeater', 'label' => 'T', 'fields' => [
                    'label' => ['type' => 'text', 'label' => 'L'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        $tagsField = $group['fields'][0]['sub_fields'][0];
        self::assertSame('field_demo_items', $tagsField['parent_repeater']);
        self::assertSame('field_demo_items_tags', $tagsField['sub_fields'][0]['parent_repeater']);
    }

    public function test_flexible_content_builds_layouts_keyed_by_layout_name(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'add_label' => 'Add Položky', 'min' => 2, 'max' => 2, 'layouts' => [
                'title' => ['label' => 'Nadpis', 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
                'image' => ['label' => 'Obrázek', 'fields' => [
                    'image' => ['type' => 'media', 'kind' => 'image', 'label' => 'Obrázek'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        $itemsField = $group['fields'][0];
        self::assertSame('flexible_content', $itemsField['type']);
        self::assertSame('field_demo_items', $itemsField['key']);
        self::assertSame('Add Položky', $itemsField['button_label']);
        self::assertSame(2, $itemsField['min']);
        self::assertSame(2, $itemsField['max']);
        self::assertArrayNotHasKey('wpml_cf_preferences', $itemsField);

        self::assertCount(2, $itemsField['layouts']);
        [$titleLayout, $imageLayout] = $itemsField['layouts'];

        self::assertSame('layout_demo_items_title', $titleLayout['key']);
        self::assertSame('title', $titleLayout['name']);
        self::assertSame('Nadpis', $titleLayout['label']);
        self::assertSame('block', $titleLayout['display']);
        self::assertSame('', $titleLayout['min']);
        self::assertSame('', $titleLayout['max']);
        self::assertNull($titleLayout['location']);
        self::assertSame('field_demo_items_title_title', $titleLayout['sub_fields'][0]['key']);
        self::assertArrayNotHasKey('parent_repeater', $titleLayout['sub_fields'][0]);

        self::assertSame('layout_demo_items_image', $imageLayout['key']);
        self::assertSame('field_demo_items_image_image', $imageLayout['sub_fields'][0]['key']);
    }

    public function test_flexible_content_layout_key_can_be_pinned(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'layouts' => [
                'title' => ['label' => 'Nadpis', 'key' => 'layout_legacy_hash_abc123', 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame('layout_legacy_hash_abc123', $group['fields'][0]['layouts'][0]['key']);
    }

    public function test_flexible_content_layout_min_max_are_reconstructed_when_authored(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'layouts' => [
                'title' => ['label' => 'Nadpis', 'min' => 1, 'max' => 3, 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame(1, $group['fields'][0]['layouts'][0]['min']);
        self::assertSame(3, $group['fields'][0]['layouts'][0]['max']);
    }

    public function test_visible_when_resolves_against_sibling_fields_only(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'T'],
            'sub' => ['type' => 'text', 'label' => 'S', 'visible_when' => ['field' => 'title', 'not_empty' => true]],
        ]), 'demo', 1700000000);

        $subField = $group['fields'][1];
        self::assertSame('field_demo_title', $subField['conditional_logic'][0][0]['field']);
    }

    public function test_same_local_name_at_different_nesting_levels_does_not_collide(): void
    {
        $group = $this->generator->generate($this->tree([
            'heading' => ['type' => 'group', 'label' => 'H', 'fields' => [
                'title' => ['type' => 'text', 'label' => 'T1'],
            ]],
            'feature' => ['type' => 'group', 'label' => 'F', 'fields' => [
                'title' => ['type' => 'text', 'label' => 'T2'],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame('field_demo_heading_title', $group['fields'][0]['sub_fields'][0]['key']);
        self::assertSame('field_demo_feature_title', $group['fields'][1]['sub_fields'][0]['key']);
    }

    public function test_delegates_root_assembly_to_root_field_group_builder(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], ['usage' => 'homepage']), 'demo', 1700000000);

        self::assertSame('group_demo', $group['key']);
        self::assertSame([[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]], $group['location']);
        self::assertSame(1700000000, $group['modified']);
    }

    public function test_accordions_are_replayed_by_generate(): void
    {
        $group = $this->generator->generate($this->tree(
            ['title' => ['type' => 'text', 'label' => 'T']],
            ['wp' => ['accordions' => [['key' => 'field_demo_a', 'label' => 'A', 'open' => 0, 'before' => 'title']]]],
        ), 'demo', 1700000000);

        self::assertSame(['accordion', 'text'], array_column($group['fields'], 'type'));
    }

    /**
     * Finding A (CRITICAL) — a flexible_content field named `a_b` with a
     * layout `c` derives the exact same underscore-joined key
     * (`field_demo_items_a_b_c`) as a sibling flexible_content field `a`
     * whose layout is `b_c`. Two different ACF fields aliasing one
     * postmeta key is irreversible editor data loss the moment both
     * layouts are ever populated on the same post. The generator must
     * refuse to emit such a tree rather than silently produce a
     * colliding pair of `field_*` keys.
     */
    public function test_flexible_content_layout_name_ambiguity_produces_colliding_keys_without_a_guard(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/field_demo_items_a_b_c/');

        $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'a_b' => ['label' => 'A B', 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
                'a' => ['label' => 'A', 'fields' => [
                    'b_c' => ['type' => 'text', 'label' => 'B C'],
                ]],
            ]],
        ], ['name' => 'Demo']), 'demo', 1700000000);
    }

    /**
     * Same collision class without flexible_content at all — two ordinary
     * fields whose name-chain segments underscore-join identically
     * (`a` + `b_c` vs `a_b` + `c`). The uniqueness guard must be global,
     * not flexible_content-specific.
     */
    public function test_ordinary_nested_group_field_name_ambiguity_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);

        $this->generator->generate($this->tree([
            'a_b' => ['type' => 'group', 'label' => 'A B', 'fields' => [
                'c' => ['type' => 'text', 'label' => 'C'],
            ]],
            'a' => ['type' => 'group', 'label' => 'A', 'fields' => [
                'b_c' => ['type' => 'text', 'label' => 'B C'],
            ]],
        ]), 'demo', 1700000000);
    }

    /**
     * Sanity control — the same fixture with distinguishable names must
     * keep working (the guard must not be over-broad / false-positive).
     */
    public function test_distinct_flexible_content_layout_names_generate_without_collision(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'alpha' => ['label' => 'Alpha', 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
            ]],
            'other' => ['type' => 'flexible_content', 'label' => 'Other', 'layouts' => [
                'beta' => ['label' => 'Beta', 'fields' => [
                    'd' => ['type' => 'text', 'label' => 'D'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertCount(2, $group['fields']);
    }

    /**
     * Finding C (CRITICAL) — layout `display` and `location` must be
     * captured verbatim when authored non-default, not hardcoded to
     * `block` / `null`.
     */
    public function test_flexible_content_layout_display_is_reconstructed_when_non_default(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'layouts' => [
                'title' => ['label' => 'Nadpis', 'wp' => ['display' => 'table'], 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame('table', $group['fields'][0]['layouts'][0]['display']);
    }

    public function test_flexible_content_layout_display_defaults_to_block_when_not_authored(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'layouts' => [
                'title' => ['label' => 'Nadpis', 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame('block', $group['fields'][0]['layouts'][0]['display']);
    }
}
