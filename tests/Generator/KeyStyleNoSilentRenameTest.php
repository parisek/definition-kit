<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Support\KeyStyle;
use PHPUnit\Framework\TestCase;

/**
 * The guarantee that matters most about `key_style`: it must never rename a
 * key behind anyone's back.
 *
 * Renaming an ACF key orphans stored content — block attributes bind
 * `_<field>` to the key string, so a renamed key leaves a block that still
 * registers, still renders, and has silently lost every authored value. There
 * is no error to notice. So the setting has to be a decision, taken once and
 * explicitly, and everything it does not decide must stay exactly as it was.
 *
 * Three properties, asserted here rather than argued in a docblock:
 *
 * 1. No config, no change — the default is the previous behaviour.
 * 2. A pinned `key:` outranks the style, always. Pins are how a project
 *    carries keys inherited from content that cannot move.
 * 3. Nested and layout keys follow the same style as the rest, so a component
 *    cannot end up half-renamed.
 */
final class KeyStyleNoSilentRenameTest extends TestCase
{
    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function generate(array $fields, string $slug, ?KeyStyle $style = null): array
    {
        $generator = null === $style ? new FieldsGenerator() : new FieldsGenerator(keyStyle: $style);
        $group = $generator->generate(['name' => 'Demo', 'fields' => $fields], $slug, 1700000000);

        self::assertNotNull($group);

        return $group;
    }

    /**
     * Property 1. The whole backward-compatibility claim in one assertion:
     * a caller that knows nothing about key_style gets byte-identical keys.
     */
    public function test_the_default_generator_emits_exactly_the_pre_setting_keys(): void
    {
        $group = $this->generate(['title' => ['type' => 'text', 'label' => 'Nadpis']], 'help-center-list');

        self::assertSame('group_help-center-list', $group['key']);
        self::assertSame('field_help-center-list_title', $group['fields'][0]['key']);
    }

    /**
     * Property 2. A pinned key is a promise to stored content. If a style
     * could override it, adopting `snake` on a project holding hyphenated
     * keys would orphan every value in one generate run.
     */
    public function test_a_pinned_key_outranks_the_style(): void
    {
        $group = $this->generate([
            'title' => ['type' => 'text', 'label' => 'Nadpis', 'key' => 'field_legacy-hand-written_title'],
        ], 'help-center-list', KeyStyle::Snake);

        self::assertSame('field_legacy-hand-written_title', $group['fields'][0]['key']);
    }

    public function test_a_pinned_group_key_outranks_the_style(): void
    {
        $group = (new FieldsGenerator(keyStyle: KeyStyle::Snake))->generate(
            ['name' => 'Demo', 'key' => 'group_help-center-list', 'fields' => [
                'title' => ['type' => 'text', 'label' => 'Nadpis'],
            ]],
            'help-center-list',
            1700000000,
        );

        self::assertNotNull($group);
        self::assertSame('group_help-center-list', $group['key']);
    }

    /**
     * Property 3, nested. A style that reached top-level fields but not
     * sub-fields would half-rename a component — the worst outcome available,
     * since the half that moved loses its content while the half that stayed
     * keeps working and hides the damage.
     */
    public function test_nested_sub_field_keys_follow_the_style(): void
    {
        $group = $this->generate([
            'numbers' => ['type' => 'repeater', 'label' => 'Čísla', 'fields' => [
                'value' => ['type' => 'text', 'label' => 'Hodnota'],
            ]],
        ], 'references-list', KeyStyle::Snake);

        $repeater = $group['fields'][0];

        self::assertSame('field_references_list_numbers', $repeater['key']);
        self::assertSame('field_references_list_numbers_value', $repeater['sub_fields'][0]['key']);
        self::assertSame('field_references_list_numbers', $repeater['sub_fields'][0]['parent_repeater']);
    }

    /**
     * Property 3, layouts. Flagged in review as a mutation the original
     * suite would not have caught: reverting the `layout_` derivation alone
     * left every other assertion green.
     */
    public function test_layout_keys_follow_the_style(): void
    {
        $group = $this->generate([
            'items' => ['type' => 'flexible_content', 'label' => 'Položky', 'layouts' => [
                'title' => ['label' => 'Nadpis', 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Nadpis'],
                ]],
            ]],
        ], 'article-list', KeyStyle::Snake);

        $items = $group['fields'][0];
        $layout = array_values($items['layouts'])[0];

        self::assertSame('field_article_list_items', $items['key']);
        self::assertSame('layout_article_list_items_title', $layout['key']);
        // The name chain carries the layout too, so a layout named `title`
        // holding a field named `title` doubles it. That is pre-existing
        // behaviour, asserted here only to pin the slug half of the string.
        self::assertSame('field_article_list_items_title_title', $layout['sub_fields'][0]['key']);
    }
}
