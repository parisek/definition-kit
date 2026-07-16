<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class FieldsMigrateCliTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = __DIR__ . '/../../bin/fields-migrate';
    }

    /** @param array<string,mixed> $acf */
    private function makeComponentDir(string $name, array $acf, ?string $twig = null): string
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true) . "/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/acf.json", json_encode($acf, JSON_PRETTY_PRINT));
        if (null !== $twig) {
            file_put_contents("{$dir}/{$name}.twig", $twig);
        }
        return $dir;
    }

    public function test_single_component_writes_fields_yaml(): void
    {
        $dir = $this->makeComponentDir('demo', [
            'key' => 'group_demo', 'title' => 'Demo',
            'fields' => [['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text']],
        ]);

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   demo', $output);
        self::assertFileExists("{$dir}/demo.yaml");
    }

    public function test_dry_run_writes_nothing(): void
    {
        $dir = $this->makeComponentDir('demo', [
            'key' => 'group_demo', 'title' => 'Demo',
            'fields' => [['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text']],
        ]);

        shell_exec(sprintf('php %s --root=%s --dry-run 2>&1', escapeshellarg($this->binPath), escapeshellarg(dirname($dir))));

        self::assertFileDoesNotExist("{$dir}/demo.yaml");
    }

    public function test_batch_continues_past_one_failing_component(): void
    {
        $root = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true);
        mkdir($root, 0777, true);
        $good = "{$root}/good";
        $bad = "{$root}/bad";
        mkdir($good);
        mkdir($bad);
        file_put_contents("{$good}/acf.json", json_encode([
            'key' => 'group_good', 'title' => 'Good',
            'fields' => [['key' => 'field_good_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text']],
        ]));
        file_put_contents("{$bad}/acf.json", json_encode([
            'key' => 'group_bad', 'title' => 'Bad',
            'fields' => [['key' => 'field_bad_x', 'name' => 'x', 'label' => 'X', 'type' => 'unsupported_acf_type']],
        ]));

        $output = shell_exec(sprintf('php %s --root=%s --dry-run 2>&1', escapeshellarg($this->binPath), escapeshellarg($root)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   good', $output);
        self::assertStringContainsString('FAIL bad', $output);
        self::assertStringContainsString('1 failed', $output);
    }

    public function test_uses_twig_front_comment_for_root_name_when_present(): void
    {
        $dir = $this->makeComponentDir(
            'demo',
            [
                'key' => 'group_demo', 'title' => 'Demo',
                'fields' => [['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Nadpis', 'type' => 'text']],
            ],
            "{#\nname: Demo (from twig)\nfields:\n#}\n<div></div>",
        );

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        $written = file_get_contents("{$dir}/demo.yaml");
        self::assertIsString($written);
        self::assertStringContainsString('Demo (from twig)', $written);
    }
}
