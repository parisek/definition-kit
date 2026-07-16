<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\AcfJsonWriter;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Symfony\Component\Yaml\Yaml;

final class FieldsLintCliTest extends TestCase
{
    private string $bin;
    private string $root;

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../../bin/fields-lint';
        $this->root = sys_get_temp_dir() . '/fields-lint-cli-test-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function makeCleanComponent(string $slug): void
    {
        $dir = "{$this->root}/{$slug}";
        mkdir($dir, 0777, true);
        $tree = ['name' => ucfirst($slug), 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
        file_put_contents("{$dir}/{$slug}.yaml", Yaml::dump($tree, 10, 2));
        $fieldGroup = (new FieldsGenerator())->generate($tree, $slug, 1_700_000_000);
        (new AcfJsonWriter())->write($fieldGroup, "{$dir}/acf.json");
    }

    public function test_single_clean_component_exits_zero_and_prints_ok(): void
    {
        $this->makeCleanComponent('demo-card');
        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . escapeshellarg("{$this->root}/demo-card") . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   demo-card', implode("\n", $output));
    }

    public function test_single_drifted_component_exits_one_and_prints_drift(): void
    {
        $this->makeCleanComponent('demo-card');
        $raw = file_get_contents("{$this->root}/demo-card/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        $acf['fields'][0]['label'] = 'Hand-edited';
        file_put_contents("{$this->root}/demo-card/acf.json", json_encode($acf));

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . escapeshellarg("{$this->root}/demo-card") . ' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('DRIFT demo-card', implode("\n", $output));
    }

    public function test_batch_root_mode_skips_components_without_a_definition_yaml(): void
    {
        $this->makeCleanComponent('demo-card');
        mkdir("{$this->root}/legacy-not-yet-migrated", 0777, true);
        file_put_contents("{$this->root}/legacy-not-yet-migrated/acf.json", '{}');

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --root=' . escapeshellarg($this->root) . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   demo-card', $joined);
        self::assertStringContainsString('SKIP legacy-not-yet-migrated', $joined);
    }

    public function test_batch_root_mode_one_bad_component_does_not_abort_the_rest(): void
    {
        $this->makeCleanComponent('demo-card');
        mkdir("{$this->root}/broken", 0777, true);
        file_put_contents("{$this->root}/broken/broken.yaml", "name: Broken\n"); // missing required `fields`

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --root=' . escapeshellarg($this->root) . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('OK   demo-card', $joined);
        self::assertStringContainsString('FAIL broken', $joined);
    }
}
