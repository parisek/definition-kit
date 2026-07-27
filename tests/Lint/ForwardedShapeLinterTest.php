<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\ForwardedShapeLinter;

final class ForwardedShapeLinterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/forwarded-shape-' . uniqid('', true);
        mkdir("{$this->root}/header-menu", 0777, true);
        mkdir("{$this->root}/header", 0777, true);
        file_put_contents("{$this->root}/header-menu/header-menu.yaml", <<<'YAML'
        name: Header menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
        YAML);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->root}/*") ?: [] as $dir) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
        rmdir($this->root);
    }

    /**
     * @param array<string,mixed> $fields
     * @return list<array{severity: string, message: string}>
     */
    private function lint(array $fields): array
    {
        return (new ForwardedShapeLinter())->lint(
            "{$this->root}/header/header.yaml",
            ['name' => 'Header', 'fields' => $fields],
        );
    }

    public function testAResolvableTargetIsClean(): void
    {
        self::assertSame([], $this->lint([
            'menu' => ['type' => 'repeater', 'label' => 'Menu', 'role' => 'parent', 'of' => 'component:header-menu#items'],
        ]));
    }

    public function testARenamedTargetFieldIsAnError(): void
    {
        $findings = $this->lint([
            'menu' => ['type' => 'repeater', 'label' => 'Menu', 'role' => 'parent', 'of' => 'component:header-menu#itms'],
        ]);

        self::assertCount(1, $findings);
        self::assertSame('error', $findings[0]['severity']);
        self::assertStringContainsString('menu', $findings[0]['message']);
        self::assertStringContainsString('itms', $findings[0]['message']);
    }

    public function testAMissingComponentIsAnError(): void
    {
        $findings = $this->lint([
            'menu' => ['type' => 'repeater', 'label' => 'Menu', 'role' => 'parent', 'of' => 'component:nope#items'],
        ]);

        self::assertCount(1, $findings);
    }

    public function testTheReferenceTargetsThatAlreadyExistedAreLeftAlone(): void
    {
        self::assertSame([], $this->lint([
            'related' => ['type' => 'reference', 'label' => 'Related', 'of' => 'post:article'],
        ]));
    }

    public function testNestedForwardsAreCheckedToo(): void
    {
        $findings = $this->lint([
            'inner' => [
                'type' => 'group',
                'label' => 'Inner',
                'role' => 'parent',
                'fields' => [
                    'menu' => ['type' => 'repeater', 'label' => 'M', 'role' => 'parent', 'of' => 'component:nope#items'],
                ],
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('inner.menu', $findings[0]['message']);
    }
}
