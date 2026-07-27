<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\DerivedFromLinter;

final class DerivedFromLinterTest extends TestCase
{
    /**
     * @param array<string,mixed> $fields
     * @return list<array{severity: string, message: string}>
     */
    private function lint(array $fields): array
    {
        return (new DerivedFromLinter())->lint('/x/demo/demo.yaml', ['name' => 'Demo', 'fields' => $fields]);
    }

    public function testFromNamingAnExistingSiblingIsClean(): void
    {
        self::assertSame([], $this->lint([
            'video' => ['type' => 'media', 'label' => 'Video'],
            'sources' => ['type' => 'text', 'label' => 'Sources', 'role' => 'derived', 'from' => 'video'],
        ]));
    }

    public function testDanglingFromIsAnErrorNamingTheMissingSibling(): void
    {
        $findings = $this->lint([
            'poster' => ['type' => 'media', 'label' => 'Poster'],
            'sources' => ['type' => 'text', 'label' => 'Sources', 'role' => 'derived', 'from' => 'video'],
        ]);

        self::assertCount(1, $findings);
        self::assertSame('error', $findings[0]['severity']);
        self::assertStringContainsString('sources', $findings[0]['message']);
        self::assertStringContainsString('video', $findings[0]['message']);
        // The siblings that DO exist are listed — the usual cause is a typo,
        // and the fix is then visible without opening the file.
        self::assertStringContainsString('poster', $findings[0]['message']);
    }

    public function testFromResolvesWithinTheSameRowNotAcrossTheComponent(): void
    {
        // `video` exists at the root, but the derivation is declared inside a
        // repeater — the field-formatting layer builds a row's value out of
        // that row, so this is dangling.
        $findings = $this->lint([
            'video' => ['type' => 'media', 'label' => 'Video'],
            'items' => [
                'type' => 'repeater',
                'label' => 'Items',
                'fields' => [
                    'sources' => ['type' => 'text', 'label' => 'Sources', 'role' => 'derived', 'from' => 'video'],
                ],
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('items.sources', $findings[0]['message']);
    }

    public function testNestedAndLayoutLevelDerivationsAreBothChecked(): void
    {
        $findings = $this->lint([
            'items' => [
                'type' => 'repeater',
                'label' => 'Items',
                'fields' => [
                    'video' => ['type' => 'media', 'label' => 'Video'],
                    'sources' => ['type' => 'text', 'label' => 'Sources', 'role' => 'derived', 'from' => 'video'],
                ],
            ],
            'blocks' => [
                'type' => 'flexible_content',
                'label' => 'Blocks',
                'layouts' => [
                    'clip' => [
                        'label' => 'Clip',
                        'fields' => [
                            'sources' => ['type' => 'text', 'label' => 'S', 'role' => 'derived', 'from' => 'video'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('blocks.clip.sources', $findings[0]['message']);
    }

    public function testADefinitionWithNoDerivationsIsClean(): void
    {
        self::assertSame([], $this->lint([
            'title' => ['type' => 'text', 'label' => 'Title'],
        ]));
    }
}
