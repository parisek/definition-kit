<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Migration\AcfJsonReader;
use Parisek\DefinitionKit\Support\KeyStyle;
use PHPUnit\Framework\TestCase;

/**
 * `key_style` across the generate/migrate pair.
 *
 * The round trip is the contract this package lives by, and it is the half a
 * key-style setting is most likely to break: migrate omits `key:` from the
 * authored definition exactly when the committed key matches the derivation.
 * If the two sides disagree about what the derivation is, a snake_case project
 * migrates to definitions that pin every single key — the boilerplate the
 * setting exists to remove, reintroduced by the fix itself.
 */
final class KeyStyleProjectionTest extends TestCase
{
    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function tree(array $fields): array
    {
        return ['name' => 'Article list', 'fields' => $fields];
    }

    /**
     * @return array<string,mixed>
     */
    private function generate(KeyStyle $style, string $slug): array
    {
        $group = (new FieldsGenerator(keyStyle: $style))->generate(
            $this->tree(['title' => ['type' => 'text', 'label' => 'Nadpis', 'role' => 'field']]),
            $slug,
            1700000000,
        );

        self::assertNotNull($group);

        return $group;
    }

    public function test_slug_style_keeps_hyphens_in_field_and_group_keys(): void
    {
        $group = $this->generate(KeyStyle::Slug, 'article-list');

        self::assertSame('group_article-list', $group['key']);
        self::assertSame('field_article-list_title', $group['fields'][0]['key']);
    }

    public function test_snake_style_folds_hyphens_in_field_and_group_keys(): void
    {
        $group = $this->generate(KeyStyle::Snake, 'article-list');

        self::assertSame('group_article_list', $group['key']);
        self::assertSame('field_article_list_title', $group['fields'][0]['key']);
    }

    public function test_slug_remains_the_default(): void
    {
        $group = (new FieldsGenerator())->generate(
            $this->tree(['title' => ['type' => 'text', 'label' => 'Nadpis', 'role' => 'field']]),
            'article-list',
            1700000000,
        );

        self::assertNotNull($group);
        self::assertSame('group_article-list', $group['key']);
    }

    /**
     * The `location` param names the Gutenberg block WordPress actually
     * registers, so it is identity rather than spelling. Folding it would
     * point the field group at a block that does not exist — the fields would
     * simply stop appearing in the editor, with no error anywhere.
     */
    public function test_the_block_location_stays_verbatim_under_snake(): void
    {
        $group = $this->generate(KeyStyle::Snake, 'article-list');

        self::assertSame('acf/article-list', $group['location'][0][0]['value']);
    }

    /**
     * A style must reach the root builder too. With the old
     * `new RootFieldGroupBuilder()` default it did not, and the result was a
     * file whose group key and field keys disagreed — valid JSON, accepted by
     * ACF, and flagged by nothing.
     */
    public function test_group_and_field_keys_never_disagree_about_the_style(): void
    {
        foreach ([KeyStyle::Slug, KeyStyle::Snake] as $style) {
            $group = $this->generate($style, 'article-list');
            $slugInGroupKey = substr((string) $group['key'], \strlen('group_'));

            self::assertStringStartsWith(
                'field_' . $slugInGroupKey . '_',
                (string) $group['fields'][0]['key'],
                "group and field keys disagree under {$style->value}",
            );
        }
    }

    /**
     * The round trip, both directions, per style. A key matching the style's
     * own derivation must NOT come back pinned.
     */
    public function test_migrate_does_not_pin_a_key_that_matches_the_declared_style(): void
    {
        $acf = [
            'key' => 'group_article_list',
            'title' => 'Article list',
            'fields' => [[
                'key' => 'field_article_list_title',
                'label' => 'Nadpis',
                'name' => 'title',
                'type' => 'text',
            ]],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/article-list']]],
        ];

        $tree = (new AcfJsonReader(keyStyle: KeyStyle::Snake))->read($acf, 'article-list');

        self::assertArrayNotHasKey('key', $tree, 'group key was pinned despite matching the snake derivation');
        self::assertArrayNotHasKey('key', $tree['fields']['title'], 'field key was pinned despite matching');
    }

    /**
     * The mirror case, which is what makes the assertion above mean something:
     * a key that does NOT match the declared style still has to be preserved
     * verbatim, or migrating a mixed project would silently rename fields.
     */
    public function test_migrate_still_pins_a_key_that_does_not_match_the_declared_style(): void
    {
        $acf = [
            'key' => 'group_article-list',
            'title' => 'Article list',
            'fields' => [[
                'key' => 'field_article-list_title',
                'label' => 'Nadpis',
                'name' => 'title',
                'type' => 'text',
            ]],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/article-list']]],
        ];

        $tree = (new AcfJsonReader(keyStyle: KeyStyle::Snake))->read($acf, 'article-list');

        self::assertSame('group_article-list', $tree['key']);
        self::assertSame('field_article-list_title', $tree['fields']['title']['key']);
    }

    /**
     * generate(migrate(acf.json)) == acf.json, under a non-default style.
     */
    public function test_round_trip_under_snake(): void
    {
        $acf = [
            'key' => 'group_article_list',
            'title' => 'Article list',
            'fields' => [[
                'key' => 'field_article_list_title',
                'label' => 'Nadpis',
                'name' => 'title',
                'type' => 'text',
            ]],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/article-list']]],
        ];

        $tree = (new AcfJsonReader(keyStyle: KeyStyle::Snake))->read($acf, 'article-list');
        $regenerated = (new FieldsGenerator(keyStyle: KeyStyle::Snake))->generate($tree, 'article-list', 1700000000);

        self::assertNotNull($regenerated);
        self::assertSame($acf['key'], $regenerated['key']);
        self::assertSame($acf['fields'][0]['key'], $regenerated['fields'][0]['key']);
        self::assertSame($acf['location'], $regenerated['location']);
    }
}
