<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Migration\AcfJsonReader;
use Parisek\DefinitionKit\Tests\Support\AcfJsonComparator;

final class GenerationRoundTripTest extends TestCase
{
    /** @return array{original: array<string,mixed>, regenerated: array<string,mixed>} */
    private function roundTrip(string $acfJsonPath, string $slug, ?string $twigPath = null): array
    {
        $acfJsonRaw = file_get_contents($acfJsonPath);
        self::assertIsString($acfJsonRaw);
        $original = json_decode($acfJsonRaw, true, flags: JSON_THROW_ON_ERROR);
        $twigSource = null;
        if (null !== $twigPath && is_file($twigPath)) {
            $twigSource = file_get_contents($twigPath);
            self::assertIsString($twigSource);
        }

        $tree = (new AcfJsonReader())->read($original, $slug, $twigSource);
        $regenerated = (new FieldsGenerator())->generate($tree, $slug, (int) $original['modified']);

        return ['original' => $original, 'regenerated' => $regenerated];
    }

    public function test_service_feature_round_trips_structurally_exact(): void
    {
        // Deliberately NOT passing the twig source here: this fixture's own
        // twig front-comment authors `name: Service - feature` while its
        // acf.json `title` is the un-spaced `Service-feature` (a pre-existing
        // authoring drift in this exact fixture's title, unrelated to
        // anything this generator computes). AcfJsonReader prefers the twig
        // name when given one (by design — see AGENTS.md), which would make
        // the regenerated root `title` diverge from the original acf.json's
        // `title` for this one fixture. The round-trip contract this test
        // proves is "acf.json -> semantic tree -> acf.json", which doesn't
        // need twig input at all; omitting it keeps the comparison honest
        // without weakening AcfJsonReader's twig-name-wins behavior itself.
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'service-feature');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);
        self::assertSame([], $diffs, implode("\n", $diffs));
    }

    public function test_service_feature_accordions_round_trip_exactly(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'service-feature');

        self::assertSame(
            array_column($result['original']['fields'], 'type'),
            array_column($result['regenerated']['fields'], 'type'),
        );
        self::assertSame(
            ['Hlavička', 'Feature karta', 'Vzhled'],
            array_values(array_filter(
                array_column($result['regenerated']['fields'], 'label'),
                static fn ($_, $i) => 'accordion' === $result['regenerated']['fields'][$i]['type'],
                ARRAY_FILTER_USE_BOTH,
            )),
        );
    }

    public function test_career_list_round_trips_structurally_exact(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/career-list';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'career-list');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);
        self::assertSame([], $diffs, implode("\n", $diffs));
    }

    public function test_heading_section_round_trips_structurally_exact_including_multi_field_accordion(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/heading-section';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'heading-section');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);
        self::assertSame([], $diffs, implode("\n", $diffs));

        // heading-section's first accordion precedes TWO leaf fields (a text
        // title, a wysiwyg body) before the next accordion — proves the
        // "before" pointer is captured per-accordion, not a naive
        // "one accordion per field" assumption, reproduces the real
        // multi-field grouping. (Originally this test used steps-slider,
        // but its accordions carry the minority wpml_cf_preferences=1
        // convention — not captured by wp.accordions, see Task 2's scope —
        // so it was swapped for heading-section, which has the identical
        // "accordion, leaf, leaf, accordion, ..." shape on the majority
        // wpml_cf_preferences=0 convention.)
        self::assertSame(
            ['accordion', 'text', 'wysiwyg', 'accordion', 'select'],
            array_column($result['regenerated']['fields'], 'type'),
        );
    }

    /**
     * Documented, bounded residual (see this plan's Global Constraints):
     * zig-zag predates the modern ACF-export convention for image
     * dimension/size sentinels (raw '' where this generator emits the
     * census-majority 0). Every OTHER prop — including its two
     * accordions — must still round-trip exactly; only this one axis
     * may differ, and it must actually manifest (a false pass here would
     * mean the census assumption drifted and a different legacy-
     * convention component should be swapped in).
     */
    public function test_zig_zag_legacy_image_sentinel_is_a_documented_bounded_residual(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/zig-zag';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'zig-zag');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);

        self::assertNotEmpty($diffs, 'Expected the documented legacy image-sentinel residual to manifest — '
            . 'if this now passes cleanly, zig-zag may have been re-exported on the modern convention; '
            . 'swap in a different legacy-convention corpus component to keep this residual covered.');

        foreach ($diffs as $diff) {
            self::assertMatchesRegularExpression(
                "/\\.(min_width|max_width|min_height|max_height|max_size)\\b.*expected \"\"?.*got 0/",
                $diff,
                "Unexpected non-residual round-trip diff (a real regression, not the documented residual): {$diff}",
            );
        }
    }

    /**
     * The ACF field-group's OWN root `description` (usually the baseline
     * '') must round-trip via `wp.description` when non-empty, and must
     * stay distinct from the twig front-comment `description` (component
     * doc metadata) — see AcfJsonReaderTest for the reader-only proof.
     * This fixture's own acf.json carries the baseline '', so the source
     * is decoded and its root `description` overridden in-memory to a
     * non-empty value before round-tripping — no new fixture file needed.
     */
    public function test_root_acf_group_description_round_trips_via_wp_description(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $raw = file_get_contents("{$fixtureDir}/acf.json");
        self::assertIsString($raw);
        $original = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $original['description'] = 'ACF group own description';
        $twigSource = "{#\nname: Demo\nusage: homepage\ndescription: Twig doc description\nfields:\n#}\n";

        $tree = (new AcfJsonReader())->read($original, 'service-feature', $twigSource);
        self::assertSame('Twig doc description', $tree['description']);
        self::assertSame('ACF group own description', $tree['wp']['description']);

        $regenerated = (new FieldsGenerator())->generate($tree, 'service-feature', (int) $original['modified']);

        self::assertSame('ACF group own description', $regenerated['description']);
    }

    /**
     * flexible_content round-trip proof #1 (eprukaz `split-content`):
     * 5 sibling layouts (title/image/cta/reference/contact) at a single
     * nesting level, real (non-sentinel) field-level min/max (2/2), and
     * NO wpml_cf_preferences on the flexible_content field itself — the
     * exact shape that motivated this generator's flexible_content
     * support (definition-kit issue #9).
     *
     * This fixture's own acf.json predates the `acfml_field_group_mode`
     * era and uses the legacy `hide_on_screen: []` / `show_in_rest: false`
     * shapes — the SAME already-documented, ACF-version-era root-level
     * residual class RootFieldGroupBuilder's own docblock calls out
     * (mirrors the zig-zag image-sentinel residual, just at the root
     * instead of a field). Filtered out explicitly below so the
     * assertion proves what it needs to: zero diffs anywhere touching
     * flexible_content/layouts itself.
     */
    public function test_split_content_flexible_content_round_trips_structurally_exact(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/split-content';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'split-content');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);
        $residual = ['.hide_on_screen: expected [], got ""', '.show_in_rest: expected false, got 0', '.acfml_field_group_mode: unexpected in actual'];
        $unexpected = array_values(array_diff($diffs, $residual));
        self::assertSame([], $unexpected, implode("\n", $unexpected));

        $items = $result['regenerated']['fields'][0];
        self::assertSame('items', $items['name']);
        self::assertSame('flexible_content', $items['type']);
        self::assertArrayNotHasKey('wpml_cf_preferences', $items);
        self::assertSame(
            ['title', 'image', 'cta', 'reference', 'contact'],
            array_column($items['layouts'], 'name'),
        );
    }

    /**
     * flexible_content round-trip proof #2 (eprukaz `box-price-reference`):
     * a flexible_content field nested TWO levels deep — inside the
     * `split_content` group — proving layout key/name derivation and the
     * recursion chain work when the flexible_content field itself isn't
     * top-level. Also exercises a top-level `group` containing nested
     * `repeater`s (including a doubly-nested repeater, `items` ->
     * `items`) alongside the flexible_content field, so this single
     * fixture covers group + repeater + flexible_content interacting in
     * one component.
     *
     * Same root-level legacy-export residual as split-content, PLUS two
     * pre-existing, out-of-scope residual classes this fixture happens to
     * expose that are unrelated to flexible_content and predate this PR:
     *  - every group/repeater field in this ONE real component lacks
     *    `wpml_cf_preferences` entirely (an older/no-WPML export) —
     *    clashing with FieldReconstructor's own explicitly tested,
     *    deliberate "container types always reconstruct wpml=3" contract
     *    (see FieldReconstructorTest::test_container_type_always_reconstructs_wpml_three_even_if_translatable_set).
     *    That contract predates this PR and isn't touched by it — fixing
     *    it would mean containers no longer always default to 3, which is
     *    a separate, deliberate design decision for a maintainer to make,
     *    not something to silently change alongside flexible_content
     *    support.
     *  - one `select` field's raw `default_value` is boolean `false`
     *    where the type baseline is `''` — the same ACF-version-era
     *    default_value serialization drift already documented for
     *    `true_false` fields in acf-defaults-baseline.yaml, just
     *    surfacing on `select` here.
     * None of these touch `layouts`/flexible_content — asserted below.
     */
    public function test_box_price_reference_nested_flexible_content_round_trips_structurally_exact(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/box-price-reference';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'box-price-reference');

        $diffs = AcfJsonComparator::diff($result['original'], $result['regenerated']);
        $unexpected = array_values(array_filter($diffs, static function (string $diff): bool {
            if (str_contains($diff, 'layouts')) {
                return true; // any layouts-path diff would be a real flexible_content regression
            }
            $residualPatterns = [
                '/^\.hide_on_screen: expected \[\], got ""$/',
                '/^\.show_in_rest: expected false, got 0$/',
                '/^\.acfml_field_group_mode: unexpected in actual$/',
                '/wpml_cf_preferences: unexpected in actual$/',
                '/\.default_value: expected false, got ""$/',
            ];
            foreach ($residualPatterns as $pattern) {
                if (1 === preg_match($pattern, $diff)) {
                    return false;
                }
            }
            return true;
        }));
        self::assertSame([], $unexpected, implode("\n", $unexpected));

        $splitContent = $result['regenerated']['fields'][1];
        self::assertSame('split_content', $splitContent['name']);
        self::assertSame('group', $splitContent['type']);

        $items = $splitContent['sub_fields'][0];
        self::assertSame('items', $items['name']);
        self::assertSame('flexible_content', $items['type']);
        self::assertSame(['image', 'reference'], array_column($items['layouts'], 'name'));

        // A flexible_content layout's own sub_fields carry NO parent_repeater
        // — unlike an ordinary repeater's direct children (proven separately
        // for the sibling `price_list` group's nested repeaters below).
        foreach ($items['layouts'] as $layout) {
            foreach ($layout['sub_fields'] as $subField) {
                self::assertArrayNotHasKey('parent_repeater', $subField);
            }
        }

        // Layout keys/names survive verbatim — the single most safety-
        // critical property (consuming Twig branches on acf_fc_layout).
        $imageLayout = $items['layouts'][0];
        self::assertSame('layout_box-price-reference_split_content_items_image', $imageLayout['key']);
        self::assertSame('image', $imageLayout['name']);
    }

    /**
     * `parent_repeater` container-gating proof (real corpus shape): the
     * REAL mairateam `reference-detail` component nests a repeater
     * directly inside another repeater (`items` -> `tags`, `items` ->
     * `stats`) — the exact repeater-inside-repeater shape this generator
     * needs to get right. Verified against the real ACF Pro plugin source
     * (`pro/fields/class-acf-field-repeater.php::load_field()`): a
     * repeater array_map()s `parent_repeater` onto every one of its OWN
     * direct sub_fields, whatever their type — so the nested `tags`/
     * `stats` repeaters themselves carry `parent_repeater` pointing at
     * `items`, and `tags`/`stats`'s own children carry `parent_repeater`
     * pointing at `tags`/`stats` (the nearest enclosing repeater, not
     * `items`). `group`'s `load_field()` has no equivalent propagation,
     * so a leaf nested through an intermediate group gets none at all
     * (see the `heading` group's children below).
     *
     * This fixture predates the ACF-export era that persists most other
     * baseline props to Local JSON at all (see README's "Known,
     * documented round-trip residuals") — `aria-label`/`conditional_logic`/
     * `wrapper`/`prepend`/`append`/`ajax`/`placeholder`/`rows_per_page`/
     * `create_options`/`save_options`/the image-dimension sentinels/root
     * `show_in_rest` are ALL entirely absent from the original, not just
     * differently-valued, so this test only pins down the `parent_repeater`
     * axis via structural assertions on the regenerated tree — a full
     * diff assertion would conflate that unrelated, already-documented
     * residual class with the one this test exists to prove.
     */
    public function test_reference_detail_nested_repeater_container_carries_parent_repeater(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/reference-detail';
        $result = $this->roundTrip("{$fixtureDir}/acf.json", 'reference-detail');

        $items = $result['regenerated']['fields'][1];
        self::assertSame('items', $items['name']);
        self::assertSame('repeater', $items['type']);
        self::assertArrayNotHasKey('parent_repeater', $items); // items is top-level, not nested under a repeater

        $byName = array_column($items['sub_fields'], null, 'name');

        // tags/stats: nested repeaters directly under `items` — the
        // container itself carries parent_repeater = items' key.
        foreach (['tags', 'stats'] as $nestedRepeaterName) {
            $nested = $byName[$nestedRepeaterName];
            self::assertSame('repeater', $nested['type']);
            self::assertSame($items['key'], $nested['parent_repeater']);
            // its own child ("label") carries parent_repeater = the NEAREST
            // enclosing repeater (itself), not the outer `items` repeater.
            self::assertSame($nested['key'], $nested['sub_fields'][0]['parent_repeater']);
        }

        // an ordinary leaf directly under `items` (not through any
        // container) still carries parent_repeater = items' key.
        self::assertSame($items['key'], $byName['tab_title']['parent_repeater']);

        // `heading` (top-level, index 0) is a group NOT nested under any
        // repeater — no parent_repeater anywhere in its subtree.
        $heading = $result['regenerated']['fields'][0];
        self::assertSame('heading', $heading['name']);
        self::assertArrayNotHasKey('parent_repeater', $heading);
        foreach ($heading['sub_fields'] as $headingChild) {
            self::assertArrayNotHasKey('parent_repeater', $headingChild);
        }
    }

    /**
     * Finding E(i) — a synthetic two-level fixture: a flexible_content
     * field whose layout itself contains ANOTHER flexible_content field.
     * An adversarial reviewer built this by hand and confirmed it works;
     * this pins the behaviour as a regression test rather than leaving it
     * as an unverified claim.
     */
    public function test_flexible_content_nested_inside_another_flexible_content_layout_round_trips(): void
    {
        $original = [
            'key' => 'group_nested_fc',
            'title' => 'Nested FC',
            'fields' => [[
                'key' => 'field_nested_outer', 'name' => 'outer', 'label' => 'Outer', 'type' => 'flexible_content',
                'layouts' => [[
                    'key' => 'layout_nested_outer_wrap', 'name' => 'wrap', 'label' => 'Wrap', 'display' => 'block',
                    'min' => '', 'max' => '', 'location' => null,
                    'sub_fields' => [[
                        'key' => 'field_nested_outer_wrap_inner', 'name' => 'inner', 'label' => 'Inner', 'type' => 'flexible_content',
                        'layouts' => [[
                            'key' => 'layout_nested_outer_wrap_inner_leaf', 'name' => 'leaf', 'label' => 'Leaf', 'display' => 'block',
                            'min' => '', 'max' => '', 'location' => null,
                            'sub_fields' => [[
                                'key' => 'field_nested_outer_wrap_inner_leaf_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text',
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        ];

        $tree = (new AcfJsonReader())->read($original, 'nested');
        $regenerated = (new FieldsGenerator())->generate($tree, 'nested', 1700000000);

        // Not a full structural-exact diff (this hand-typed minimal
        // fixture doesn't carry every real-ACF baseline prop) — the
        // regression this test pins is specifically that a nested
        // flexible_content-inside-a-flexible_content-layout round-trips
        // its own layouts/keys/names correctly, which the assertions
        // below verify directly against both original and regenerated.
        $outer = $regenerated['fields'][0];
        self::assertSame('outer', $outer['name']);
        self::assertSame('flexible_content', $outer['type']);
        self::assertSame('wrap', $outer['layouts'][0]['name']);

        $innerFcField = $outer['layouts'][0]['sub_fields'][0];
        self::assertSame('inner', $innerFcField['name']);
        self::assertSame('flexible_content', $innerFcField['type']);
        self::assertSame('leaf', $innerFcField['layouts'][0]['name']);
        self::assertSame('title', $innerFcField['layouts'][0]['sub_fields'][0]['name']);
        self::assertSame('field_nested_outer_wrap_inner_leaf_title', $innerFcField['layouts'][0]['sub_fields'][0]['key']);
    }

    /**
     * Finding E(ii) — layout-level non-sentinel `min`/`max` round-trip
     * through the FULL reader -> generator pipeline (each half is
     * unit-tested separately in AcfJsonReaderTest /
     * FieldsGeneratorTest already, but the combined pipeline wasn't
     * pinned end-to-end until this test).
     */
    public function test_flexible_content_layout_non_sentinel_min_max_round_trips_end_to_end(): void
    {
        $original = [
            'key' => 'group_layout_bounds',
            'title' => 'Layout Bounds',
            'fields' => [[
                'key' => 'field_bounds_items', 'name' => 'items', 'label' => 'Items', 'type' => 'flexible_content',
                'layouts' => [[
                    'key' => 'layout_bounds_items_title', 'name' => 'title', 'label' => 'Title', 'display' => 'block',
                    'min' => 1, 'max' => 3, 'location' => null,
                    'sub_fields' => [[
                        'key' => 'field_bounds_items_title_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text',
                    ]],
                ]],
            ]],
        ];

        $tree = (new AcfJsonReader())->read($original, 'bounds');
        self::assertSame(1, $tree['fields']['items']['layouts']['title']['min']);
        self::assertSame(3, $tree['fields']['items']['layouts']['title']['max']);

        $regenerated = (new FieldsGenerator())->generate($tree, 'bounds', 1700000000);
        $layout = $regenerated['fields'][0]['layouts'][0];
        self::assertSame(1, $layout['min']);
        self::assertSame(3, $layout['max']);
    }
}
