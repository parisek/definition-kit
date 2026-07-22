<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\KindLinter;

final class KindLinterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kind-linter-test-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
        touch("{$this->dir}/button.yaml");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    /** Directory with a definition file but no block.json alongside it. */
    private function fixtureDir(): string
    {
        return $this->dir;
    }

    /** Directory with a definition file AND a sibling block.json. */
    private function fixtureDirWithBlockJson(): string
    {
        touch("{$this->dir}/hero.yaml");
        file_put_contents("{$this->dir}/block.json", '{}');

        return $this->dir;
    }

    public function testMissingKindIsAWarningNotAnError(): void
    {
        $findings = (new KindLinter())->lint('/x/button/button.yaml', ['name' => 'Button']);
        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertStringContainsString('declares no `kind`', $findings[0]['message']);
    }

    public function testKindBlockWithoutBlockJsonIsAnError(): void
    {
        $dir = $this->fixtureDir(); // contains button.yaml, no block.json
        $findings = (new KindLinter())->lint("{$dir}/button.yaml", ['kind' => 'block']);
        $this->assertSame('error', $findings[0]['severity']);
        $this->assertStringContainsString('block.json', $findings[0]['message']);
    }

    public function testBlockJsonWithoutKindBlockIsAnError(): void
    {
        $dir = $this->fixtureDirWithBlockJson();
        $findings = (new KindLinter())->lint("{$dir}/hero.yaml", ['kind' => 'element']);
        $this->assertSame('error', $findings[0]['severity']);
    }

    public function testIntentKindsAreNeverSecondGuessed(): void
    {
        // part/element/section/utility cannot be derived — the linter must not try.
        $dir = $this->fixtureDir();
        foreach (['section', 'element', 'part', 'utility'] as $kind) {
            $this->assertSame([], (new KindLinter())->lint("{$dir}/button.yaml", ['kind' => $kind]));
        }
    }
}
