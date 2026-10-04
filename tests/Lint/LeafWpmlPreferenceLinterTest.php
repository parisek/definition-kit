<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use Parisek\DefinitionKit\Lint\LeafWpmlPreferenceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeafWpmlPreferenceLinterTest extends TestCase
{
    private LeafWpmlPreferenceLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new LeafWpmlPreferenceLinter();
    }

    /** @return list<array{severity: string, message: string}> */
    private function lintLeaf(mixed $wpml, string $type = 'text'): array
    {
        return $this->linter->lint('demo.yaml', [
            'fields' => ['title' => ['type' => $type, 'wp' => ['wpml_cf_preferences' => $wpml]]],
        ]);
    }

    /** @return array<string, array{int}> */
    public static function nonCanonical(): array
    {
        return ['copy once' => [3], 'ignore' => [0]];
    }

    #[DataProvider('nonCanonical')]
    public function test_leaf_with_non_canonical_value_warns(int $value): void
    {
        $findings = $this->lintLeaf($value);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('demo.yaml', $findings[0]['message']);
        self::assertStringContainsString('title', $findings[0]['message']);
        self::assertStringContainsString("wp.wpml_cf_preferences: {$value}", $findings[0]['message']);
        self::assertStringContainsString('non-canonical', $findings[0]['message']);
        self::assertStringContainsString('Translate is 2', $findings[0]['message']);
        self::assertStringContainsString('translatable: true', $findings[0]['message']);
    }

    public function test_leaf_with_value_two_gets_the_redundant_message(): void
    {
        $findings = $this->lintLeaf(2);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('redundant', $findings[0]['message']);
        self::assertStringContainsString('translatable: true', $findings[0]['message']);
        self::assertStringNotContainsString('non-canonical', $findings[0]['message']);
    }

    public function test_leaf_with_value_one_gets_the_redundant_message(): void
    {
        $findings = $this->lintLeaf(1);
        self::assertCount(1, $findings);
        self::assertStringContainsString('redundant', $findings[0]['message']);
        self::assertStringContainsString('omit', $findings[0]['message']);
    }

    public function test_numeric_string_is_read_as_its_integer(): void
    {
        self::assertStringContainsString('non-canonical', $this->lintLeaf('3')[0]['message']);
    }

    /** @return array<string, array{mixed}> */
    public static function malformed(): array
    {
        return ['float' => [2.5], 'bool' => [true], 'word' => ['translate'], 'out of range' => [7]];
    }

    #[DataProvider('malformed')]
    public function test_a_value_that_is_not_an_integer_zero_to_three_is_reported_not_coerced(mixed $value): void
    {
        $findings = $this->lintLeaf($value);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('not a valid', $findings[0]['message']);
    }

    public function test_leaf_with_translatable_and_no_wp_is_clean(): void
    {
        self::assertSame([], $this->linter->lint('demo.yaml', [
            'fields' => ['title' => ['type' => 'text', 'translatable' => true]],
        ]));
    }

    public function test_leaf_with_other_wp_keys_only_is_clean(): void
    {
        self::assertSame([], $this->linter->lint('demo.yaml', [
            'fields' => ['title' => ['type' => 'text', 'wp' => ['key' => 'field_x']]],
        ]));
    }

    /** @return array<string, array{string}> */
    public static function containers(): array
    {
        return [
            'object' => ['object'], 'list' => ['list'], 'group' => ['group'],
            'repeater' => ['repeater'], 'flexible_content' => ['flexible_content'],
        ];
    }

    #[DataProvider('containers')]
    public function test_container_with_wp_three_is_clean(string $type): void
    {
        self::assertSame([], $this->linter->lint('demo.yaml', [
            'fields' => ['box' => ['type' => $type, 'wp' => ['wpml_cf_preferences' => 3], 'fields' => [], 'layouts' => []]],
        ]));
    }

    public function test_leaf_nested_in_a_row_is_found(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'rows' => [
                    'type' => 'repeater',
                    'fields' => ['label' => ['type' => 'textarea', 'wp' => ['wpml_cf_preferences' => 3]]],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('rows.label', $findings[0]['message']);
    }

    public function test_leaf_nested_in_a_group_is_found(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'meta' => [
                    'type' => 'group',
                    'fields' => ['text' => ['type' => 'richtext', 'wp' => ['wpml_cf_preferences' => 0]]],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('meta.text', $findings[0]['message']);
    }

    public function test_leaf_nested_in_a_layout_is_found(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'blocks' => [
                    'type' => 'flexible_content',
                    'layouts' => [
                        'intro' => [
                            'fields' => ['text' => ['type' => 'text', 'wp' => ['wpml_cf_preferences' => 3]]],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('blocks.intro.text', $findings[0]['message']);
    }

    public function test_positive_control_a_clean_leaf_changes_to_a_finding(): void
    {
        $clean = ['fields' => ['t' => ['type' => 'text']]];
        self::assertSame([], $this->linter->lint('demo.yaml', $clean));
        $clean['fields']['t']['wp'] = ['wpml_cf_preferences' => 3];
        self::assertNotSame([], $this->linter->lint('demo.yaml', $clean));
    }
}
