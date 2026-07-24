<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\MigrationCompletenessAuditor;

final class MigrationCompletenessAuditorTest extends TestCase
{
    private MigrationCompletenessAuditor $auditor;

    protected function setUp(): void
    {
        $this->auditor = new MigrationCompletenessAuditor();
    }

    public function test_passes_when_every_prop_matches_baseline_default(): void
    {
        $acf = [[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'instructions' => '', 'required' => 0, 'placeholder' => '', 'prepend' => '', 'append' => '',
            'allow_in_bindings' => 0, 'default_value' => '',
        ]];
        $def = ['title' => ['type' => 'text', 'label' => 'Nadpis']];
        self::assertSame([], $this->auditor->audit($acf, $def));
    }

    public function test_passes_when_a_deviation_is_captured_verbatim_in_wp(): void
    {
        $acf = [[
            'key' => 'field_demo_body', 'name' => 'body', 'label' => 'Text', 'type' => 'wysiwyg',
            'toolbar' => 'full',
        ]];
        $def = ['body' => ['type' => 'richtext', 'label' => 'Text', 'wp' => ['toolbar' => 'full']]];
        self::assertSame([], $this->auditor->audit($acf, $def));
    }

    public function test_fails_when_a_deviation_is_silently_dropped(): void
    {
        $acf = [[
            'key' => 'field_demo_body', 'name' => 'body', 'label' => 'Text', 'type' => 'wysiwyg',
            'toolbar' => 'full',
        ]];
        $def = ['body' => ['type' => 'richtext', 'label' => 'Text']]; // toolbar lost!
        $violations = $this->auditor->audit($acf, $def);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('toolbar', $violations[0]);
    }

    public function test_fails_when_a_field_is_missing_entirely(): void
    {
        $acf = [['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text']];
        $violations = $this->auditor->audit($acf, []);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('title', $violations[0]);
    }

    public function test_accordion_fields_are_skipped_not_flagged(): void
    {
        $acf = [['key' => 'field_demo_acc', 'name' => '', 'type' => 'accordion', 'label' => 'Section', 'open' => 1]];
        self::assertSame([], $this->auditor->audit($acf, []));
    }

    /**
     * Finding 3 (negative): a LIFTED prop (maxlength) that the migrated
     * output silently drops must fail the audit — proves the auditor
     * doesn't just trust the presence of the prop on the raw side, it
     * verifies the emitted output actually carries the reconstructible
     * value. Hand-tampered migrated field (not the real reader) so the
     * test isolates the auditor's own guarantee.
     */
    public function test_fails_when_a_lifted_maxlength_is_dropped_from_the_migrated_output(): void
    {
        $acf = [[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'maxlength' => 60,
        ]];
        $def = ['title' => ['type' => 'text', 'label' => 'Nadpis']]; // maxlength dropped!
        $violations = $this->auditor->audit($acf, $def);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('maxlength', $violations[0]);
    }

    /**
     * Finding 3 (negative): a leaf field with wpml_cf_preferences=2 (the
     * canonical "translatable" shape) whose migrated output omits
     * `translatable` must fail — proves the WPML lift is genuinely verified,
     * not blindly trusted. Hand-tampered migrated field, same isolation
     * rationale as above.
     */
    public function test_fails_when_translatable_is_dropped_for_a_canonical_wpml_leaf(): void
    {
        $acf = [[
            'key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text',
            'wpml_cf_preferences' => 2,
        ]];
        $def = ['title' => ['type' => 'text', 'label' => 'Nadpis']]; // translatable dropped!
        $violations = $this->auditor->audit($acf, $def);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('wpml_cf_preferences', $violations[0]);
    }

    public function test_recurses_into_sub_fields(): void
    {
        $acf = [[
            'key' => 'field_demo_grp', 'name' => 'grp', 'label' => 'Grp', 'type' => 'group',
            'sub_fields' => [[
                'key' => 'field_demo_grp_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg',
                'toolbar' => 'full',
            ]],
        ]];
        $defOk = ['grp' => ['type' => 'group', 'label' => 'Grp', 'fields' => [
            'body' => ['type' => 'richtext', 'label' => 'Body', 'wp' => ['toolbar' => 'full']],
        ]]];
        self::assertSame([], $this->auditor->audit($acf, $defOk));

        $defLossy = ['grp' => ['type' => 'group', 'label' => 'Grp', 'fields' => [
            'body' => ['type' => 'richtext', 'label' => 'Body'],
        ]]];
        self::assertNotEmpty($this->auditor->audit($acf, $defLossy));
    }

    public function test_recurses_into_flexible_content_layouts(): void
    {
        $acf = [[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Items', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_body', 'name' => 'body', 'label' => 'Body', 'display' => 'block',
                'sub_fields' => [[
                    'key' => 'field_demo_items_body_text', 'name' => 'text', 'label' => 'Text', 'type' => 'wysiwyg',
                    'toolbar' => 'full',
                ]],
                'min' => '', 'max' => '',
            ]],
        ]];
        $defOk = ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
            'body' => ['label' => 'Body', 'fields' => [
                'text' => ['type' => 'richtext', 'label' => 'Text', 'wp' => ['toolbar' => 'full']],
            ]],
        ]]];
        self::assertSame([], $this->auditor->audit($acf, $defOk));

        $defLossy = ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => [
            'body' => ['label' => 'Body', 'fields' => [
                'text' => ['type' => 'richtext', 'label' => 'Text'], // toolbar lost!
            ]],
        ]]];
        self::assertNotEmpty($this->auditor->audit($acf, $defLossy));
    }

    public function test_fails_when_a_layout_is_missing_from_the_migrated_definition(): void
    {
        $acf = [[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Items', 'type' => 'flexible_content',
            'layouts' => [[
                'key' => 'layout_demo_items_body', 'name' => 'body', 'label' => 'Body', 'display' => 'block',
                'sub_fields' => [[
                    'key' => 'field_demo_items_body_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text',
                ]],
            ]],
        ]];
        $def = ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => []]];
        $violations = $this->auditor->audit($acf, $def);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('layout missing', $violations[0]);
    }

    public function test_flexible_content_wpml_cf_preferences_is_never_flagged_as_unaccounted(): void
    {
        // Real value 1/2 on the FC field itself should not trip the
        // generic "neither baseline default, lifted key, nor in wp:"
        // check — it's left entirely to the generic wp: fallback.
        $acf = [[
            'key' => 'field_demo_items', 'name' => 'items', 'label' => 'Items', 'type' => 'flexible_content',
            'wpml_cf_preferences' => 1,
            'layouts' => [[
                'key' => 'layout_demo_items_body', 'name' => 'body', 'label' => 'Body', 'display' => 'block',
                'sub_fields' => [[
                    'key' => 'field_demo_items_body_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text',
                ]],
            ]],
        ]];
        $def = ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'wp' => ['wpml_cf_preferences' => 1], 'layouts' => [
            'body' => ['label' => 'Body', 'fields' => [
                'text' => ['type' => 'text', 'label' => 'Text'],
            ]],
        ]]];
        self::assertSame([], $this->auditor->audit($acf, $def));
    }
}
