<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;

final class FieldsGenerateCliTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = __DIR__ . '/../../bin/fields-generate';
    }

    /** @param array<string,mixed>|null $existingBlockJson */
    private function makeComponentDir(string $name, string $yaml, ?array $existingBlockJson = null): string
    {
        $dir = sys_get_temp_dir() . '/fields-generate-cli-' . uniqid('', true) . "/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$name}.yaml", $yaml);
        if (null !== $existingBlockJson) {
            file_put_contents("{$dir}/block.json", json_encode($existingBlockJson, JSON_PRETTY_PRINT));
        }
        return $dir;
    }

    public function test_single_component_writes_acf_json_and_block_json(): void
    {
        $dir = $this->makeComponentDir('demo', "name: Demo\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   demo', $output);
        self::assertFileExists("{$dir}/acf.json");
        self::assertFileExists("{$dir}/block.json");

        $raw = file_get_contents("{$dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        self::assertSame('group_demo', $acf['key']);
    }

    public function test_regeneration_preserves_the_existing_acf_json_modified_timestamp(): void
    {
        // Idempotence: regenerating over an existing acf.json must keep its
        // `modified`, not stamp the current time — otherwise every run churns
        // the field on every component (git noise for a committed artifact).
        $dir = $this->makeComponentDir('demo', "name: Demo\nfields:\n  title:\n    type: text\n    label: Nadpis\n");
        $pinned = 1700000000;
        file_put_contents("{$dir}/acf.json", json_encode(['key' => 'group_demo', 'modified' => $pinned], JSON_PRETTY_PRINT));

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        $raw = file_get_contents("{$dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        self::assertSame($pinned, $acf['modified']);
    }

    public function test_brand_new_component_gets_a_nonzero_modified(): void
    {
        // No existing acf.json → fall back to the current time (just assert it's
        // a plausible non-zero timestamp; the exact value is non-deterministic).
        $dir = $this->makeComponentDir('demo', "name: Demo\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        $raw = file_get_contents("{$dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        self::assertIsInt($acf['modified']);
        self::assertGreaterThan(0, $acf['modified']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $dir = $this->makeComponentDir('demo', "name: Demo\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        shell_exec(sprintf('php %s --root=%s --dry-run 2>&1', escapeshellarg($this->binPath), escapeshellarg(dirname($dir))));

        self::assertFileDoesNotExist("{$dir}/acf.json");
        self::assertFileDoesNotExist("{$dir}/block.json");
    }

    public function test_batch_continues_past_one_failing_component(): void
    {
        $root = sys_get_temp_dir() . '/fields-generate-cli-' . uniqid('', true);
        mkdir($root, 0777, true);
        $good = "{$root}/good";
        $bad = "{$root}/bad";
        mkdir($good);
        mkdir($bad);
        file_put_contents("{$good}/good.yaml", "name: Good\nfields:\n  title:\n    type: text\n    label: Nadpis\n");
        // A field with `type: media` but no `kind` — AbstractTypeReverseMapper
        // throws DomainException; the validator lets it through since `kind`
        // is only required-by-convention for media, not schema-enforced.
        file_put_contents("{$bad}/bad.yaml", "name: Bad\nfields:\n  photo:\n    type: media\n    label: Foto\n");

        $output = shell_exec(sprintf('php %s --root=%s --dry-run 2>&1', escapeshellarg($this->binPath), escapeshellarg($root)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   good', $output);
        self::assertStringContainsString('FAIL bad', $output);
        self::assertStringContainsString('1 failed', $output);
    }

    public function test_existing_block_json_example_is_preserved(): void
    {
        $dir = $this->makeComponentDir(
            'demo',
            "name: Demo\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
            ['example' => ['viewportWidth' => 1280, 'attributes' => ['data' => ['title' => 'Real content']]]],
        );

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        $raw = file_get_contents("{$dir}/block.json");
        self::assertIsString($raw);
        $block = json_decode($raw, true);
        self::assertSame('Real content', $block['example']['attributes']['data']['title']);
    }

    public function test_invalid_definition_fails_fast_before_writing_anything(): void
    {
        $dir = $this->makeComponentDir('demo', "name: Demo\nfields:\n  title:\n    type: not_a_real_type\n    label: Nadpis\n");

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL demo', $output);
        self::assertFileDoesNotExist("{$dir}/acf.json");
    }
}
