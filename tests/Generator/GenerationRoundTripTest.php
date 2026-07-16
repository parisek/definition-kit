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
}
