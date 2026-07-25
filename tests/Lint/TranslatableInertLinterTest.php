<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\TranslatableInertLinter;

final class TranslatableInertLinterTest extends TestCase
{
    private TranslatableInertLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new TranslatableInertLinter();
    }

    public function test_leaf_field_with_translatable_produces_no_finding(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => ['title' => ['type' => 'text', 'label' => 'T', 'translatable' => true]],
        ]);
        self::assertSame([], $findings);
    }

    public function test_flexible_content_with_translatable_true_warns(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'items' => [
                    'type' => 'flexible_content',
                    'label' => 'Items',
                    'translatable' => true,
                    'layouts' => [],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('demo.yaml', $findings[0]['message']);
        self::assertStringContainsString('items', $findings[0]['message']);
        self::assertStringContainsString('flexible_content', $findings[0]['message']);
        self::assertStringContainsString('translatable', $findings[0]['message']);
    }

    public function test_flexible_content_with_translatable_false_still_warns(): void
    {
        // Declaring the property at all is the inert act, regardless of value.
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'translatable' => false, 'layouts' => []]],
        ]);
        self::assertCount(1, $findings);
    }

    public function test_flexible_content_without_translatable_produces_no_finding(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => ['items' => ['type' => 'flexible_content', 'label' => 'Items', 'layouts' => []]],
        ]);
        self::assertSame([], $findings);
    }

    public function test_repeater_with_translatable_warns_same_as_flexible_content(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'items' => [
                    'type' => 'repeater',
                    'label' => 'Items',
                    'translatable' => true,
                    'fields' => ['x' => ['type' => 'text', 'label' => 'X']],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('repeater', $findings[0]['message']);
    }

    public function test_group_with_translatable_warns_same_as_flexible_content(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'meta' => [
                    'type' => 'group',
                    'label' => 'Meta',
                    'translatable' => true,
                    'fields' => ['x' => ['type' => 'text', 'label' => 'X']],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('group', $findings[0]['message']);
    }

    public function test_nested_flexible_content_inside_group_is_found(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'wrapper' => [
                    'type' => 'group',
                    'label' => 'Wrapper',
                    'fields' => [
                        'items' => [
                            'type' => 'flexible_content',
                            'label' => 'Items',
                            'translatable' => true,
                            'layouts' => [],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('wrapper.items', $findings[0]['message']);
    }

    public function test_translatable_inside_a_layout_field_is_found(): void
    {
        $findings = $this->linter->lint('demo.yaml', [
            'fields' => [
                'items' => [
                    'type' => 'flexible_content',
                    'label' => 'Items',
                    'layouts' => [
                        'title' => [
                            'label' => 'Title layout',
                            'fields' => [
                                'nested' => [
                                    'type' => 'repeater',
                                    'label' => 'Nested',
                                    'translatable' => true,
                                    'fields' => ['x' => ['type' => 'text', 'label' => 'X']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('items.title.nested', $findings[0]['message']);
    }
}
