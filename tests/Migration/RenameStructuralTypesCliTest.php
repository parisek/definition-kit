<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class RenameStructuralTypesCliTest extends TestCase
{
    private const SOURCE = "# keep me\nname: Demo\nfields:\n  items:\n    type: repeater  # rows\n    fields:\n      group:\n        type: group\n        fields:\n          t:\n            type: text\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rename-structural-types-cli-' . uniqid('', true);
        foreach (['alpha', 'beta'] as $name) {
            mkdir("{$this->root}/{$name}", 0777, true);
        }
        file_put_contents("{$this->root}/alpha/alpha.yaml", self::SOURCE);
        file_put_contents("{$this->root}/beta/beta.yaml", "name: Beta\nfields:\n  t:\n    type: text\n");
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->root}/*/*") ?: [] as $file) {
            unlink($file);
        }
        foreach (glob("{$this->root}/*") ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->root);
    }

    /** @return array{int, string} */
    private function runCli(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/fields-migrate');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        [$exit, $output] = $this->runCli('--rename-structural-types', "--root={$this->root}");

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('WOULD alpha: 2 type(s) renamed', $output);
        self::assertStringContainsString('OK   beta: nothing to rename', $output);
        self::assertStringContainsString('re-run with --write', $output);
        self::assertSame(self::SOURCE, file_get_contents("{$this->root}/alpha/alpha.yaml"));
    }

    public function test_write_rewrites_the_file_and_a_second_run_is_a_no_op(): void
    {
        [$exit, $output] = $this->runCli('--rename-structural-types', '--write', "{$this->root}/alpha");

        self::assertSame(0, $exit, $output);
        self::assertSame(
            str_replace(['type: repeater', 'type: group'], ['type: list', 'type: object'], self::SOURCE),
            file_get_contents("{$this->root}/alpha/alpha.yaml"),
        );

        [$exit, $output] = $this->runCli('--rename-structural-types', '--write', "{$this->root}/alpha");
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('nothing to rename', $output);
    }

    public function test_a_derivation_flag_is_refused(): void
    {
        [$exit, $output] = $this->runCli('--rename-structural-types', '--force', "{$this->root}/alpha");

        self::assertSame(2, $exit);
        self::assertStringContainsString('unknown option with --rename-structural-types: --force', $output);
    }

    public function test_a_directory_without_a_definition_fails(): void
    {
        mkdir("{$this->root}/gamma");
        [$exit, $output] = $this->runCli('--rename-structural-types', "{$this->root}/gamma");

        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL gamma: no gamma.yaml', $output);
    }
}
