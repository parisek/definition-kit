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
     * Finding 2 (round 3, HIGH) — `generate()` runs
     * `assertGloballyUniqueKeys($orderedRawFields)` BEFORE
     * `RootFieldGroupBuilder::build()` re-inserts accordion pseudo-fields
     * from root `wp.accordions` into the final assembled `fields` list.
     * A duplicate key hidden in an accordion (colliding with either an
     * ordinary field's key or another accordion's key) therefore slips
     * straight past the "global" uniqueness guard and reaches the
     * generated acf.json — exactly the postmeta-aliasing hazard the guard
     * exists to catch, just via a different injection point than
     * fields/layouts. The guard must see the FINAL assembled fields list,
     * accordions included, not just the ordinary fields it validates
     * today.
     */
    public function test_accordion_key_colliding_with_an_ordinary_field_key_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/field_demo_title/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                ['key' => 'field_demo_title', 'label' => 'Section', 'open' => 0, 'before' => 'title'],
            ]],
        ]), 'demo', 1700000000);
    }

    /**
     * Same collision class, but between two accordions' own keys — no
     * ordinary field involved at all, proving the guard must scan the
     * accordion list itself, not just cross-check it against fields.
     */
    public function test_two_accordions_sharing_the_same_key_are_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/field_demo_dup_accordion/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                ['key' => 'field_demo_dup_accordion', 'label' => 'A', 'open' => 0, 'before' => 'title'],
                ['key' => 'field_demo_dup_accordion', 'label' => 'B', 'open' => 0],
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
     * Round 5 — two layouts in the SAME flexible_content field pin
     * different `key`s (so the existing key-uniqueness guard is silent)
     * but the SAME `name:`. ACF matches a rendered flex-content row's
     * layout by `acf_fc_layout` == the layout's `name`, not its `key` —
     * two layouts sharing one name are indistinguishable to WordPress at
     * render/save time even though their field-group keys never collide.
     * This is a distinct hazard from the key-collision guard above and
     * must be caught independently of it.
     */
    public function test_two_layouts_in_the_same_flexible_content_field_cannot_share_a_pinned_name(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/title/');

        $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'layout_one' => ['label' => 'Layout One', 'key' => 'layout_demo_items_one', 'name' => 'title', 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
                'layout_two' => ['label' => 'Layout Two', 'key' => 'layout_demo_items_two', 'name' => 'title', 'fields' => [
                    'd' => ['type' => 'text', 'label' => 'D'],
                ]],
            ]],
        ]), 'demo', 1700000000);
    }

    /**
     * Sanity control — the SAME layout `name` in two DIFFERENT
     * flexible_content fields must keep working; `acf_fc_layout` is only
     * ambiguous within a single flex-content field's own rows.
     */
    public function test_same_layout_name_in_different_flexible_content_fields_does_not_collide(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'title' => ['label' => 'Title', 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
            ]],
            'other' => ['type' => 'flexible_content', 'label' => 'Other', 'layouts' => [
                'title' => ['label' => 'Title', 'fields' => [
                    'd' => ['type' => 'text', 'label' => 'D'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertCount(2, $group['fields']);
    }

    /**
     * Round 5 — `wp:` overlay deliberately wins over every derived prop
     * (see test_wp_overlay_wins_over_baseline_and_reconstruction), and
     * that includes `name` — the schema's `wp` bag USED to be a fully open
     * object with no key exclusions, which meant two sibling fields at the
     * same nesting level (different YAML map keys, hence different derived
     * `key`s, so the key-uniqueness guard stayed silent) could each pin
     * `wp: {name: "same"}` and collide on the ACTUAL ACF `name` — which
     * is the WordPress postmeta key.
     *
     * Round 6 closes this structurally: `wp.name` is now rejected
     * OUTRIGHT (assertNoIdentityPropsInWpOverlay(), called before any
     * field is built), so the collision described above can no longer
     * even be attempted — a field's name has exactly one source, its own
     * YAML field-map key, and two sibling map keys can never be equal
     * (they're PHP array keys). This test now asserts the deny-list
     * rejection itself, naming the sanctioned alternative.
     */
    public function test_wp_name_override_is_rejected_naming_the_alternative(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.name/');
        $this->expectExceptionMessageMatches('/field-map key/');

        $this->generator->generate($this->tree([
            'field_one' => ['type' => 'text', 'label' => 'One', 'wp' => ['name' => 'clash']],
            'field_two' => ['type' => 'text', 'label' => 'Two'],
        ]), 'demo', 1700000000);
    }

    /**
     * Sanity control — the SAME field-map key used at DIFFERENT nesting
     * levels (root vs inside a group) is not a collision; ACF namespaces
     * a field's postmeta identity per parent container, not globally. No
     * `wp:` overlay involved — this exercises the ordinary, sanctioned
     * naming path (ordinary field-map key), which is now the ONLY path.
     */
    public function test_same_field_map_key_at_different_nesting_levels_does_not_collide(): void
    {
        $group = $this->generator->generate($this->tree([
            'field_one' => ['type' => 'text', 'label' => 'One'],
            'wrapper' => ['type' => 'group', 'label' => 'Wrapper', 'fields' => [
                'field_one' => ['type' => 'text', 'label' => 'One (nested)'],
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

    /**
     * Defect 1 (HIGH) — `generate()` builds the sibling name=>key map used
     * to resolve `visible_when` from the RAW semantic fields, before a
     * field's own `wp: {key: …}` overlay is applied. `siblingKeyMap()` (via
     * `deriveOrPinKey()`) only ever reads a field's top-level `key:` prop —
     * it never sees `wp.key` — so a field pinning its ACF key through the
     * `wp:` escape hatch (as opposed to the top-level `key:` prop the
     * migration path itself always uses) gets a `conditional_logic` entry
     * pointing at the DERIVED key while the field ships under the
     * OVERRIDDEN key. The referenced key then exists nowhere in the
     * generated tree — ACF's conditional-logic UI would show a dangling
     * reference to a field that was never created.
     *
     * The assertion walks the ENTIRE generated tree and checks that every
     * key any `conditional_logic` entry references actually exists
     * somewhere in the emitted fields/layouts — not that it equals one
     * hardcoded expected string, which would keep passing even if both the
     * override and the sibling map silently agreed on the WRONG key.
     */
    public function test_conditional_logic_references_survive_a_pinned_key(): void
    {
        $group = $this->generator->generate($this->tree([
            'toggle' => ['type' => 'boolean', 'label' => 'Toggle', 'key' => 'field_test_custom_toggle_key'],
            'conditional_field' => [
                'type' => 'text',
                'label' => 'Conditional',
                'visible_when' => ['field' => 'toggle', 'equals' => true],
            ],
        ], ['name' => 'Test']), 'test', 1700000000);

        $allKeys = [];
        $referencedKeys = [];
        $this->collectAllKeysAndConditionalLogicRefs($group['fields'], $allKeys, $referencedKeys);

        foreach ($referencedKeys as $referencedKey) {
            self::assertContains(
                $referencedKey,
                $allKeys,
                sprintf(
                    "conditional_logic references key '%s', which does not exist anywhere in the "
                    . 'generated tree — a wp.key override desynced the sibling name=>key map used to '
                    . 'resolve visible_when.',
                    $referencedKey,
                ),
            );
        }

        // Sanity: the pinned key really did win (otherwise the assertion
        // above would trivially pass by both sides using the wrong,
        // but mutually-consistent, derived key).
        self::assertContains('field_test_custom_toggle_key', $allKeys);
        self::assertNotContains('field_test_toggle', $allKeys);
    }

    /**
     * Walks a generated `fields` (or `layouts`/`sub_fields`) list
     * recursively, collecting (a) every `key` that exists anywhere in the
     * tree and (b) every field `key` referenced by any `conditional_logic`
     * entry anywhere in the tree.
     *
     * @param list<array<string,mixed>> $fields
     * @param list<string> $allKeys
     * @param list<string> $referencedKeys
     */
    private function collectAllKeysAndConditionalLogicRefs(array $fields, array &$allKeys, array &$referencedKeys): void
    {
        foreach ($fields as $field) {
            $allKeys[] = (string) $field['key'];

            $conditionalLogic = $field['conditional_logic'] ?? false;
            if (is_array($conditionalLogic)) {
                foreach ($conditionalLogic as $orGroup) {
                    foreach ((array) $orGroup as $cond) {
                        $referencedKeys[] = (string) $cond['field'];
                    }
                }
            }

            if (!empty($field['sub_fields'])) {
                /** @var list<array<string,mixed>> $subFields */
                $subFields = (array) $field['sub_fields'];
                $this->collectAllKeysAndConditionalLogicRefs($subFields, $allKeys, $referencedKeys);
            }

            if (!empty($field['layouts'])) {
                /** @var list<array<string,mixed>> $layouts */
                $layouts = (array) $field['layouts'];
                foreach ($layouts as $layout) {
                    $allKeys[] = (string) $layout['key'];
                    /** @var list<array<string,mixed>> $layoutSubFields */
                    $layoutSubFields = (array) ($layout['sub_fields'] ?? []);
                    $this->collectAllKeysAndConditionalLogicRefs($layoutSubFields, $allKeys, $referencedKeys);
                }
            }
        }
    }

    /**
     * Defect 1, nested case — the same `wp.key` desync reproduces one
     * level deeper, inside a group's `sub_fields`, since
     * `buildField()`'s container branch builds `$childSiblingMap` via the
     * same un-fixed `siblingKeyMap()` for every nesting level.
     */
    public function test_conditional_logic_references_survive_a_pinned_key_inside_a_group(): void
    {
        $group = $this->generator->generate($this->tree([
            'wrapper' => ['type' => 'group', 'label' => 'Wrapper', 'fields' => [
                'toggle' => ['type' => 'boolean', 'label' => 'Toggle', 'key' => 'field_test_custom_nested_key'],
                'conditional_field' => [
                    'type' => 'text',
                    'label' => 'Conditional',
                    'visible_when' => ['field' => 'toggle', 'equals' => true],
                ],
            ]],
        ], ['name' => 'Test']), 'test', 1700000000);

        $allKeys = [];
        $referencedKeys = [];
        $this->collectAllKeysAndConditionalLogicRefs($group['fields'], $allKeys, $referencedKeys);

        foreach ($referencedKeys as $referencedKey) {
            self::assertContains($referencedKey, $allKeys);
        }
        self::assertContains('field_test_custom_nested_key', $allKeys);
    }

    /**
     * Defect 2 (MEDIUM, round 5) — `collectKeys()` used to exempt a field
     * from the sibling-name-uniqueness guard whenever its final `name` was
     * `''`, assuming only accordion pseudo-fields ever have an empty name.
     * `wp.name` is now rejected outright (round 6), so this specific value
     * ('' via `wp: {name: ''}`) can no longer even be authored — asserting
     * the deny-list rejection here instead of the old collision message.
     */
    public function test_wp_name_override_with_empty_string_is_still_rejected_by_the_deny_list(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.name/');

        $this->generator->generate($this->tree([
            'field_one' => ['type' => 'text', 'label' => 'One', 'wp' => ['name' => '']],
            'field_two' => ['type' => 'text', 'label' => 'Two'],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 6, Defect 3 — round 5's fix re-keyed the sibling-name-
     * uniqueness exemption from "name === ''" to "type === 'accordion'"
     * (see `collectKeys()`), on the assumption that `type` is a safe,
     * un-overridable discriminator. It wasn't: `wp:` was (before this
     * round) a fully open escape-hatch object with NO key exclusions, so
     * an ORDINARY field could set `wp: {type: 'accordion', name: 'clash'}`
     * and silently re-escape the very guard round 5 just tightened — two
     * such fields alias the same ('clash') postmeta name with zero
     * diagnostic. Round 6 closes the whole class structurally: `wp.type`
     * (and `wp.name`) are rejected outright, so this bypass can no longer
     * be constructed at all — this regression test asserts the REJECTION
     * itself (the two fields never reach the name-collision check, they
     * never even reach field-building).
     */
    public function test_wp_type_accordion_impersonation_is_rejected_before_it_can_escape_the_name_guard(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        // The field carries BOTH a forbidden `wp.type` and a forbidden
        // `wp.name` — either is independently sufficient to reject it, and
        // this test only cares that rejection happens BEFORE the two
        // fields could ever reach the name-collision check, not which of
        // the two forbidden props the deny-list happens to report first.
        $this->expectExceptionMessageMatches('/wp\.(type|name)/');

        $this->generator->generate($this->tree([
            'field_one' => ['type' => 'text', 'label' => 'One', 'wp' => ['type' => 'accordion', 'name' => 'clash']],
            'field_two' => ['type' => 'text', 'label' => 'Two', 'wp' => ['type' => 'accordion', 'name' => 'clash']],
        ]), 'demo', 1700000000);
    }

    /**
     * Control case for the fix above — genuine accordion pseudo-fields
     * (identified by `type === 'accordion'`, which always carry canonical
     * `name: ''`) must still be exempt and coexist freely at the same
     * level; the fix must narrow the exemption's DISCRIMINATOR, not remove
     * the exemption. Genuine accordions are built exclusively by
     * `RootFieldGroupBuilder` from root `wp.accordions` — an entirely
     * separate mechanism from an ordinary field's own `wp:` overlay, so
     * this path is untouched by the round-6 deny-list.
     */
    public function test_two_genuine_accordions_still_coexist_without_collision(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                ['key' => 'field_demo_accordion_a', 'label' => 'A', 'open' => 0, 'before' => 'title'],
                ['key' => 'field_demo_accordion_b', 'label' => 'B', 'open' => 0],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame(['accordion', 'text', 'accordion'], array_column($group['fields'], 'type'));
    }

    /**
     * Round 6 — Defect 4: `wp.key` pointed at a bogus value (violating
     * ACF's `^field_` convention) or `null` used to pass end-to-end,
     * shipping a broken key into generated acf.json with no diagnostic.
     * The deny-list rejects `wp.key` regardless of its VALUE — the prop
     * itself is forbidden, not just malformed values of it — so this is
     * closed for free by the same mechanism as the identity-desync
     * defect. Message must name the sanctioned alternative (top-level
     * `key:`).
     */
    public function test_wp_key_override_is_rejected_naming_the_alternative(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.key/');
        $this->expectExceptionMessageMatches('/top-level `key:`/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'wp' => ['key' => 'bogus']],
        ]), 'demo', 1700000000);
    }

    /** Same as above, with `wp.key` pointed at `null` rather than a malformed string. */
    public function test_wp_key_override_with_null_value_is_still_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.key/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'wp' => ['key' => null]],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 6 — `wp.type` deny-list, exercised directly (not via the
     * accordion-impersonation regression above) — an ordinary field
     * cannot repoint its own ACF type through the `wp:` overlay at all,
     * message names the sanctioned alternative (top-level `type:`).
     */
    public function test_wp_type_override_is_rejected_naming_the_alternative(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.type/');
        $this->expectExceptionMessageMatches('/top-level `type:`/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'wp' => ['type' => 'textarea']],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 6 — the same three-prop deny-list applies to a flexible_content
     * LAYOUT's own `wp:` bag, not just an ordinary field's. A layout has
     * its own top-level `key:`/`name:` props (see
     * test_flexible_content_layout_display_is_reconstructed_when_non_default
     * for the sanctioned `wp.display`/`wp.location` use of layout `wp:`),
     * so the same two-independent-paths hazard applies symmetrically.
     */
    public function test_wp_key_override_on_a_layout_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.key/');
        $this->expectExceptionMessageMatches('/layout/i');

        $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'title' => ['label' => 'Title', 'wp' => ['key' => 'layout_bogus'], 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
            ]],
        ]), 'demo', 1700000000);
    }

    /** Same as above, for `wp.name` on a layout — the layout's own `name:` prop is the sanctioned path. */
    public function test_wp_name_override_on_a_layout_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.name/');

        $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'title' => ['label' => 'Title', 'wp' => ['name' => 'clash'], 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
            ]],
        ]), 'demo', 1700000000);
    }

    /**
     * Sanity control — genuine, sanctioned uses of a layout's `wp:` bag
     * (`display`/`location`, see test_flexible_content_layout_display_is_reconstructed_when_non_default
     * and test_flexible_content_layout_location_round_trips_when_non_default,
     * if present) must keep working unchanged — the deny-list only blocks
     * `key`/`name`/`type`, nothing else.
     */
    public function test_layout_wp_overlay_still_works_for_non_identity_props(): void
    {
        $group = $this->generator->generate($this->tree([
            'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                'title' => ['label' => 'Title', 'wp' => ['display' => 'table'], 'fields' => [
                    'c' => ['type' => 'text', 'label' => 'C'],
                ]],
            ]],
        ]), 'demo', 1700000000);

        self::assertSame('table', $group['fields'][0]['layouts'][0]['display']);
    }

    /**
     * Round 7, Finding [1] — ROOT `wp:` was never walked by
     * assertNoIdentityPropsInWpOverlay() (only `$definitionTree['fields']`
     * was), and RootFieldGroupBuilder::build() merges the root `wp:` bag
     * with HIGHEST precedence over the explicit `key` it just assigned —
     * so `wp: {key: 'bogus'}` at the ROOT silently repointed the whole
     * field group's key. Must now be rejected before generation.
     */
    public function test_root_wp_key_override_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.key/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['key' => 'bogus'],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 7, Finding [2] (most severe) — same root-`wp:` gap as above,
     * but with `fields` instead of `key`: `wp: {fields: []}` at the ROOT
     * silently overwrote the assembled `fields` list with an empty array
     * — a definition with real fields generated an acf.json with ZERO
     * fields (a broken, empty block in the editor, no error anywhere).
     */
    public function test_root_wp_fields_override_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.fields/');

        $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
            'subtitle' => ['type' => 'text', 'label' => 'Podnadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['fields' => []],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 7, Finding [3] — `wp.sub_fields` on an ordinary LEAF field
     * (not a container type) survives all the way to generated output.
     * `buildField()` merges `wpOverrides` onto `$field` BEFORE the
     * container branch would overwrite `sub_fields` — but that overwrite
     * only happens for `$isContainerField` (group/repeater). A leaf field
     * (e.g. `text`) never re-derives `sub_fields`, so a smuggled
     * `wp.sub_fields` array (carrying its own bogus key/name/type triple)
     * ends up verbatim in the generated field — a phantom sub-field ACF
     * never asked for.
     */
    public function test_wp_sub_fields_smuggled_into_a_leaf_field_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.sub_fields/');

        $this->generator->generate($this->tree([
            'title' => [
                'type' => 'text',
                'label' => 'Nadpis',
                'wp' => ['sub_fields' => [
                    ['key' => 'field_bogus_child', 'name' => 'bogus_child', 'type' => 'text', 'label' => 'Bogus'],
                ]],
            ],
        ]), 'demo', 1700000000);
    }

    /** Same smuggling hazard, but via `wp.layouts` on an ordinary (non-flexible_content) field. */
    public function test_wp_layouts_smuggled_into_a_leaf_field_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.layouts/');

        $this->generator->generate($this->tree([
            'title' => [
                'type' => 'text',
                'label' => 'Nadpis',
                'wp' => ['layouts' => [
                    ['key' => 'layout_bogus', 'name' => 'bogus', 'label' => 'Bogus', 'sub_fields' => []],
                ]],
            ],
        ]), 'demo', 1700000000);
    }

    /**
     * Round 7, Finding [4] — `wp.parent_repeater` on a repeater's CHILD
     * field is merged (via `wpOverrides`) AFTER `buildField()` derives and
     * assigns the real `parent_repeater` from nesting, silently
     * overwriting the correct, ACF-computed value with an arbitrary one.
     * `parent_repeater` has no legitimate authoring path — it is always
     * re-derived from nesting — so it is reserved outright.
     */
    public function test_wp_parent_repeater_override_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.parent_repeater/');

        $this->generator->generate($this->tree([
            'items' => ['type' => 'repeater', 'label' => 'Items', 'fields' => [
                'title' => [
                    'type' => 'text',
                    'label' => 'Title',
                    'wp' => ['parent_repeater' => 'field_bogus'],
                ],
            ]],
        ]), 'demo', 1700000000);
    }

    /** The same reserved set must be rejected at a nested sub_field two levels deep (group inside group). */
    public function test_reserved_wp_props_are_rejected_at_nested_depth_two(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/wp\.key/');

        $this->generator->generate($this->tree([
            'outer' => ['type' => 'group', 'label' => 'Outer', 'fields' => [
                'inner' => ['type' => 'group', 'label' => 'Inner', 'fields' => [
                    'leaf' => ['type' => 'text', 'label' => 'Leaf', 'wp' => ['key' => 'bogus']],
                ]],
            ]],
        ]), 'demo', 1700000000);
    }

    /** Same reserved-set enforcement, for the newly reserved structural/cross-reference props on a layout's own `wp:` bag. */
    public function test_reserved_structural_props_are_rejected_on_a_layout(): void
    {
        foreach (['fields', 'sub_fields', 'layouts', 'parent_repeater'] as $prop) {
            try {
                $this->generator->generate($this->tree([
                    'items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
                        'title' => ['label' => 'Title', 'wp' => [$prop => []], 'fields' => [
                            'c' => ['type' => 'text', 'label' => 'C'],
                        ]],
                    ]],
                ]), 'demo', 1700000000);
                self::fail("wp.{$prop} on a layout was expected to be rejected but generation succeeded.");
            } catch (\Parisek\DefinitionKit\Generator\GenerationValidationException $e) {
                self::assertStringContainsString("wp.{$prop}", $e->getMessage());
            }
        }
    }

    /**
     * Strengthened control (round 7) — the previous control test only
     * exercised benign PRESENTATION props (`toolbar`, `acf_type`), which
     * is exactly why the structural/cross-reference bypasses ([1]-[4])
     * went unnoticed for a whole round. Asserts BOTH directions on the
     * SAME field shape: genuine installed-base props still pass, and every
     * newly reserved prop is independently rejected.
     */
    public function test_legitimate_props_pass_while_every_newly_reserved_prop_is_rejected(): void
    {
        $group = $this->generator->generate($this->tree([
            'body' => [
                'type' => 'richtext',
                'label' => 'Text',
                'wp' => ['toolbar' => 'full', 'new_lines' => 'wpautop', 'collapsed' => '', 'ui' => 1],
            ],
        ]), 'demo', 1700000000);
        self::assertSame('full', $group['fields'][0]['toolbar']);
        self::assertSame('wpautop', $group['fields'][0]['new_lines']);

        foreach (['fields', 'sub_fields', 'layouts', 'parent_repeater'] as $prop) {
            try {
                $this->generator->generate($this->tree([
                    'body' => ['type' => 'richtext', 'label' => 'Text', 'wp' => [$prop => []]],
                ]), 'demo', 1700000000);
                self::fail("wp.{$prop} on a field was expected to be rejected but generation succeeded.");
            } catch (\Parisek\DefinitionKit\Generator\GenerationValidationException $e) {
                self::assertStringContainsString("wp.{$prop}", $e->getMessage());
            }
        }
    }

    /**
     * Round 7 — RootFieldGroupBuilder::buildAccordionPseudoField() overlaid
     * the captured accordion residual excluding ONLY key/label/open/before
     * — an accordion element carrying `type`/`name`/`fields`/`sub_fields`/
     * `layouts`/`parent_repeater` (via a hand-authored or corrupted
     * `wp.accordions` entry) could impersonate an arbitrary pseudo-field,
     * bypassing the accordion baseline's fixed `type: 'accordion'` /
     * `name: ''`. Must now be constrained to the same reserved set.
     */
    public function test_accordion_residual_cannot_inject_a_bogus_type_or_name(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                [
                    'key' => 'field_demo_accordion_a',
                    'label' => 'A',
                    'open' => 0,
                    'before' => 'title',
                    'type' => 'text',
                    'name' => 'bogus_child',
                ],
            ]],
        ]), 'demo', 1700000000);

        $accordion = $group['fields'][0];
        self::assertSame('accordion', $accordion['type'], 'accordion residual let `wp.accordions[].type` impersonate a different field shape');
        self::assertSame('', $accordion['name'], 'accordion residual let `wp.accordions[].name` smuggle a bogus field name');
    }

    /**
     * Genuine accordions must keep working end to end after the residual
     * exclusion is widened — the fix narrows what CAN be overlaid, it must
     * not break the legitimate `before`/`open`/non-default `instructions`
     * residual path itself. `wp.accordions` appears 38 times in the
     * committed installed base — this is the "still works" half of the
     * regression pair above.
     */
    public function test_accordion_with_legitimate_residual_props_still_works(): void
    {
        $group = $this->generator->generate($this->tree([
            'title' => ['type' => 'text', 'label' => 'Nadpis'],
        ], [
            'name' => 'Demo',
            'wp' => ['accordions' => [
                [
                    'key' => 'field_demo_accordion_a',
                    'label' => 'A',
                    'open' => 1,
                    'before' => 'title',
                    'instructions' => 'Some instructions',
                    'multi_expand' => 1,
                ],
            ]],
        ]), 'demo', 1700000000);

        $accordion = $group['fields'][0];
        self::assertSame('accordion', $accordion['type']);
        self::assertSame(1, $accordion['open']);
        self::assertSame('Some instructions', $accordion['instructions']);
        self::assertSame(1, $accordion['multi_expand']);
        self::assertSame('text', $group['fields'][1]['type']);
    }

    /**
     * `wp.conditional_logic` is a REAL fallback Migration\AcfJsonReader
     * emits (see VisibleWhenMapper::map()) whenever raw ACF
     * conditional_logic is too complex to reduce to the abstract
     * `visible_when` vocabulary (2+ AND conditions, 2+ OR groups, or an
     * unmapped operator) — it is not forbidden like the structural/
     * identity props above. Instead its references must RESOLVE: a
     * `conditional_logic` entry pointing at a key that exists nowhere in
     * the generated tree is a dangling reference ACF's editor would show
     * as broken with no diagnostic from this tool. This is the positive
     * (still works) half of that contract.
     */
    public function test_wp_conditional_logic_fallback_with_resolvable_reference_still_works(): void
    {
        $group = $this->generator->generate($this->tree([
            'toggle' => ['type' => 'boolean', 'label' => 'Toggle', 'key' => 'field_test_toggle_key'],
            'conditional_field' => [
                'type' => 'text',
                'label' => 'Conditional',
                'wp' => ['conditional_logic' => [[
                    ['field' => 'field_test_toggle_key', 'operator' => '==', 'value' => '1'],
                    ['field' => 'field_test_toggle_key', 'operator' => '!=', 'value' => '2'],
                ]]],
            ],
        ], ['name' => 'Test']), 'test', 1700000000);

        self::assertIsArray($group['fields'][1]['conditional_logic']);
    }

    /** Negative half — a `wp.conditional_logic` fallback referencing a key that doesn't exist anywhere must be rejected loudly. */
    public function test_wp_conditional_logic_fallback_with_dangling_reference_is_rejected(): void
    {
        $this->expectException(\Parisek\DefinitionKit\Generator\GenerationValidationException::class);
        $this->expectExceptionMessageMatches('/conditional_logic/');
        $this->expectExceptionMessageMatches('/field_does_not_exist/');

        $this->generator->generate($this->tree([
            'conditional_field' => [
                'type' => 'text',
                'label' => 'Conditional',
                'wp' => ['conditional_logic' => [[
                    ['field' => 'field_does_not_exist', 'operator' => '==', 'value' => '1'],
                ]]],
            ],
        ], ['name' => 'Test']), 'test', 1700000000);
    }
}
