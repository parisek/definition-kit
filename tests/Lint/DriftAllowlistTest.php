<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\DriftAllowlist;

final class DriftAllowlistTest extends TestCase
{
    private DriftAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new DriftAllowlist();
    }

    public function test_legacy_image_dimension_sentinel_is_allowed_for_image_field(): void
    {
        $generated = ['fields' => [['type' => 'image', 'name' => 'photo', 'min_width' => 0]]];
        $diffs = [[
            'kind' => 'value', 'path' => '.fields[0].min_width', 'prop' => 'min_width',
            'expected' => 0, 'actual' => '',
        ]];
        self::assertSame([], $this->allowlist->filter('zig-zag', $diffs, $generated));
    }

    public function test_same_sentinel_is_not_allowed_for_a_non_image_field_type(): void
    {
        $generated = ['fields' => [['type' => 'gallery', 'name' => 'photos', 'min_width' => 0]]];
        $diffs = [[
            'kind' => 'value', 'path' => '.fields[0].min_width', 'prop' => 'min_width',
            'expected' => 0, 'actual' => '',
        ]];
        self::assertCount(1, $this->allowlist->filter('some-component', $diffs, $generated));
    }

    public function test_legacy_root_hide_on_screen_shapes_are_allowed(): void
    {
        $generated = ['hide_on_screen' => ''];
        foreach ([[], null, false] as $legacyShape) {
            $diffs = [['kind' => 'value', 'path' => '.hide_on_screen', 'prop' => 'hide_on_screen', 'expected' => '', 'actual' => $legacyShape]];
            self::assertSame([], $this->allowlist->filter('any-component', $diffs, $generated));
        }
    }

    public function test_hide_on_screen_rule_is_root_only_not_nested(): void
    {
        $generated = ['fields' => [['hide_on_screen' => '']]];
        $diffs = [['kind' => 'value', 'path' => '.fields[0].hide_on_screen', 'prop' => 'hide_on_screen', 'expected' => '', 'actual' => []]];
        self::assertCount(1, $this->allowlist->filter('any-component', $diffs, $generated));
    }

    public function test_select_default_value_tie_is_allowed_for_select_field(): void
    {
        $generated = ['fields' => [['type' => 'select', 'name' => 'variant', 'default_value' => '']]];
        $diffs = [['kind' => 'value', 'path' => '.fields[0].default_value', 'prop' => 'default_value', 'expected' => '', 'actual' => false]];
        self::assertSame([], $this->allowlist->filter('heading-graphs', $diffs, $generated));
    }

    public function test_default_value_wrong_for_a_different_value_still_fails(): void
    {
        $generated = ['fields' => [['type' => 'select', 'name' => 'variant', 'default_value' => '']]];
        $diffs = [['kind' => 'value', 'path' => '.fields[0].default_value', 'prop' => 'default_value', 'expected' => '', 'actual' => 'wrong-choice']];
        self::assertCount(1, $this->allowlist->filter('some-component', $diffs, $generated));
    }

    public function test_true_false_default_value_is_never_allowlisted(): void
    {
        $generated = ['fields' => [['type' => 'true_false', 'name' => 'enabled', 'default_value' => 0]]];
        $diffs = [['kind' => 'value', 'path' => '.fields[0].default_value', 'prop' => 'default_value', 'expected' => 0, 'actual' => false]];
        self::assertCount(1, $this->allowlist->filter('some-component', $diffs, $generated));
    }

    public function test_legacy_minimal_export_missing_props_allowed_only_for_listed_components(): void
    {
        $generated = ['fields' => [['type' => 'text', 'name' => 'title', 'wrapper' => ['width' => '', 'class' => '', 'id' => '']]]];
        $diffs = [['kind' => 'missing', 'path' => '.fields[0].wrapper', 'prop' => 'wrapper', 'expected' => ['width' => '', 'class' => '', 'id' => ''], 'actual' => null]];

        self::assertSame([], $this->allowlist->filter('reference-detail', $diffs, $generated));
        self::assertCount(1, $this->allowlist->filter('a-modern-component', $diffs, $generated));
    }

    public function test_legacy_minimal_export_missing_prop_is_not_allowlisted_when_expected_is_non_default(): void
    {
        // Same field/prop/component as the benign case above, but the
        // definition now authors a real (non-default) wrapper class —
        // the missing prop can no longer be excused as a benign legacy
        // omission because what's missing is authored content.
        $generated = ['fields' => [['type' => 'text', 'name' => 'title', 'wrapper' => ['width' => '', 'class' => 'lg:col-span-2', 'id' => '']]]];
        $diffs = [['kind' => 'missing', 'path' => '.fields[0].wrapper', 'prop' => 'wrapper', 'expected' => ['width' => '', 'class' => 'lg:col-span-2', 'id' => ''], 'actual' => null]];

        self::assertCount(1, $this->allowlist->filter('reference-detail', $diffs, $generated));
    }

    public function test_legacy_minimal_export_missing_root_hide_on_screen_uses_the_root_baseline(): void
    {
        $generated = ['hide_on_screen' => ''];
        $diffs = [['kind' => 'missing', 'path' => '.hide_on_screen', 'prop' => 'hide_on_screen', 'expected' => '', 'actual' => null]];

        self::assertSame([], $this->allowlist->filter('reference-detail', $diffs, $generated));
    }

    public function test_legacy_minimal_export_missing_prop_with_no_baseline_entry_and_authored_value_fails(): void
    {
        // conditional_logic has no TypeDefaults entry at all (deliberately
        // lifted to visible_when elsewhere) — a genuinely authored, non-empty
        // conditional_logic missing from a legacy export must still fail.
        $generated = ['fields' => [['type' => 'text', 'name' => 'title', 'conditional_logic' => [[['field' => 'field_x', 'operator' => '==', 'value' => '1']]]]]];
        $diffs = [['kind' => 'missing', 'path' => '.fields[0].conditional_logic', 'prop' => 'conditional_logic', 'expected' => [[['field' => 'field_x', 'operator' => '==', 'value' => '1']]], 'actual' => null]];

        self::assertCount(1, $this->allowlist->filter('reference-detail', $diffs, $generated));
    }

    public function test_a_prop_not_covered_by_any_rule_always_fails(): void
    {
        $generated = ['fields' => [['type' => 'text', 'name' => 'title', 'label' => 'Title']]];
        $diffs = [['kind' => 'value', 'path' => '.fields[0].label', 'prop' => 'label', 'expected' => 'Title', 'actual' => 'Nadpis']];
        self::assertSame($diffs, $this->allowlist->filter('any-component', $diffs, $generated));
    }
}
