<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\StructuralType;
use PHPUnit\Framework\TestCase;

final class StructuralTypeTest extends TestCase
{
    public function test_canonical_resolves_aliases_and_passes_other_types_through(): void
    {
        self::assertSame('object', StructuralType::canonical('group'));
        self::assertSame('list', StructuralType::canonical('repeater'));
        self::assertSame('object', StructuralType::canonical('object'));
        self::assertSame('list', StructuralType::canonical('list'));
        self::assertSame('flexible_content', StructuralType::canonical('flexible_content'));
        self::assertSame('text', StructuralType::canonical('text'));
    }

    public function test_normalize_walks_nested_fields_and_layouts(): void
    {
        $tree = [
            'name' => 'Demo',
            'fields' => [
                'group' => ['type' => 'text', 'label' => 'A field named group'],
                'items' => ['type' => 'repeater', 'fields' => [
                    'link' => ['type' => 'group', 'fields' => ['url' => ['type' => 'link']]],
                ]],
                'sections' => ['type' => 'flexible_content', 'layouts' => [
                    'hero' => ['label' => 'Hero', 'fields' => ['cards' => ['type' => 'repeater', 'fields' => ['t' => ['type' => 'text']]]]],
                ]],
                'marker' => ['type' => 'text', 'wp' => ['acf_type' => 'repeater']],
            ],
        ];

        $normalized = StructuralType::normalize($tree);

        self::assertSame('text', $normalized['fields']['group']['type']);
        self::assertSame('list', $normalized['fields']['items']['type']);
        self::assertSame('object', $normalized['fields']['items']['fields']['link']['type']);
        self::assertSame('list', $normalized['fields']['sections']['layouts']['hero']['fields']['cards']['type']);
        self::assertSame('repeater', $normalized['fields']['marker']['wp']['acf_type']);
        self::assertSame($normalized, StructuralType::normalize($normalized), 'normalize is idempotent');
    }

    public function test_parse_file_returns_canonical_types(): void
    {
        $parsed = StructuralType::parseFile(__DIR__ . '/../fixtures/valid-nested.fields.yaml');
        self::assertIsArray($parsed);
        $types = [];
        array_walk_recursive($parsed, static function (mixed $value, int|string $key) use (&$types): void {
            if ('type' === $key) {
                $types[] = $value;
            }
        });
        self::assertNotContains('group', $types);
        self::assertNotContains('repeater', $types);
    }
}
