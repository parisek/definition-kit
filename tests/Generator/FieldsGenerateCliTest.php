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
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

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
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");
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
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        $raw = file_get_contents("{$dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        self::assertIsInt($acf['modified']);
        self::assertGreaterThan(0, $acf['modified']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

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
        file_put_contents("{$good}/good.yaml", "name: Good\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");
        // A field with `type: media` but no `kind` — AbstractTypeReverseMapper
        // throws DomainException; the validator lets it through since `kind`
        // is only required-by-convention for media, not schema-enforced.
        file_put_contents("{$bad}/bad.yaml", "name: Bad\ncategory: Content\nfields:\n  photo:\n    type: media\n    label: Foto\n");

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
            "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
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
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: not_a_real_type\n    label: Nadpis\n");

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL demo', $output);
        self::assertFileDoesNotExist("{$dir}/acf.json");
    }

    public function test_unknown_option_is_refused_without_writing_anything(): void
    {
        // The dangerous shape: an option that reads like a read-only check.
        // Before the guard it fell through to the positional argument, left
        // $dryRun false, and the command rewrote acf.json/block.json — the
        // caller believed nothing had been touched.
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        $output = shell_exec(sprintf(
            'php %s --check %s 2>&1; echo "exit=$?"',
            escapeshellarg($this->binPath),
            escapeshellarg($dir),
        ));

        self::assertIsString($output);
        self::assertStringContainsString('unknown option: --check', $output);
        self::assertStringContainsString('exit=2', $output);
        self::assertFileDoesNotExist("{$dir}/acf.json");
        self::assertFileDoesNotExist("{$dir}/block.json");
    }

    /** @return list<array{string}> */
    public static function nonBlockKindProvider(): array
    {
        return [['section'], ['element'], ['part'], ['utility']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonBlockKindProvider')]
    public function test_a_non_block_kind_gets_neither_acf_json_nor_block_json(string $kind): void
    {
        // Only `kind: block` registers a Gutenberg block (ADR 0012). The root
        // location of a generated field group is always `block == acf/<slug>`,
        // so an acf.json for any other kind attaches to a block that does not
        // exist: an orphan group that shows in ACF's list and never in the editor.
        $dir = $this->makeComponentDir(
            'demo',
            "name: Demo\ncategory: Content\nkind: {$kind}\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
        );

        exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)), $lines, $exitCode);
        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("SKIP demo: kind {$kind} has no CMS projection", $output);
        self::assertStringContainsString('1 component(s), 0 failed, 1 skipped', $output);
        self::assertFileDoesNotExist("{$dir}/acf.json");
        self::assertFileDoesNotExist("{$dir}/block.json");
    }

    public function test_kind_block_still_gets_both_files(): void
    {
        $dir = $this->makeComponentDir(
            'demo',
            "name: Demo\ncategory: Content\nkind: block\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
        );

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   demo', $output);
        self::assertStringContainsString("1 component(s), 0 failed\n", $output);
        self::assertStringNotContainsString('skipped', $output);
        self::assertFileExists("{$dir}/acf.json");
        self::assertFileExists("{$dir}/block.json");
    }

    public function test_a_non_block_kind_leaves_already_committed_projections_alone(): void
    {
        // Rule 4 semantics: generation stops WRITING, it never deletes — and it
        // does not refresh either. Before #72 a committed acf.json on a non-block
        // kind was rewritten on every run. Now both files stay byte-identical.
        //
        // The run itself must be asserted to have SUCCEEDED. Without that, a
        // regression that aborts the CLI before it ever reaches the gate would
        // also leave the files untouched and this test would still pass.
        $dir = $this->makeComponentDir(
            'demo',
            "name: Demo\ncategory: Content\nkind: part\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
            ['name' => 'acf/demo', 'sentinel' => true],
        );
        $acfBytes = "{\n    \"key\": \"group_demo\",\n    \"title\": \"Stale title\",\n    \"modified\": 1700000000\n}\n";
        file_put_contents("{$dir}/acf.json", $acfBytes);
        $blockBytes = file_get_contents("{$dir}/block.json");

        exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)), $lines, $exitCode);
        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('SKIP demo: kind part has no CMS projection', $output);
        self::assertSame($acfBytes, file_get_contents("{$dir}/acf.json"));
        self::assertSame($blockBytes, file_get_contents("{$dir}/block.json"));
    }

    public function test_the_gate_also_holds_in_dry_run_and_batch_mode(): void
    {
        // --dry-run and --root= take a different path through the temp-file
        // plumbing than the single-dir write, so the gate is asserted on both
        // rather than assumed to carry over.
        $dir = $this->makeComponentDir(
            'demo',
            "name: Demo\ncategory: Content\nkind: utility\nfields:\n  title:\n    type: text\n    label: Nadpis\n",
        );

        $dryRun = shell_exec(sprintf(
            'php %s --dry-run %s 2>&1',
            escapeshellarg($this->binPath),
            escapeshellarg($dir),
        ));

        self::assertIsString($dryRun);
        self::assertStringContainsString('SKIP demo: kind utility has no CMS projection', $dryRun);
        self::assertFileDoesNotExist("{$dir}/block.json");
        self::assertFileDoesNotExist("{$dir}/acf.json");

        foreach (['--root=%s --dry-run', '--root=%s'] as $pattern) {
            exec(sprintf('php %s ' . $pattern . ' 2>&1', escapeshellarg($this->binPath), escapeshellarg(dirname($dir))), $lines, $exitCode);
            $batch = implode("\n", $lines);
            $lines = [];

            self::assertSame(0, $exitCode, $batch);
            self::assertStringContainsString('SKIP demo: kind utility has no CMS projection', $batch);
            self::assertStringContainsString('1 component(s), 0 failed, 1 skipped', $batch);
            self::assertFileDoesNotExist("{$dir}/acf.json");
            self::assertFileDoesNotExist("{$dir}/block.json");
        }
    }

    public function test_a_mixed_tree_generates_then_lints_clean(): void
    {
        // #72: generate and lint must agree on whether a non-block component
        // owns an acf.json. Before, `fields-generate --root` created the orphan
        // and the next `fields-lint --root` compared it instead of skipping.
        $root = sys_get_temp_dir() . '/fields-generate-cli-' . uniqid('', true);
        $defs = [
            'hero' => "kind: block\n",
            'legacy' => '',
            'alert' => "kind: element\n",
            'teaser' => "kind: part\n",
        ];
        foreach ($defs as $name => $kindLine) {
            mkdir("{$root}/{$name}", 0777, true);
            file_put_contents(
                "{$root}/{$name}/{$name}.yaml",
                "name: " . ucfirst($name) . "\ncategory: Content\n{$kindLine}fields:\n  title:\n    type: text\n    label: Nadpis\n",
            );
        }

        exec(sprintf('php %s --root=%s 2>&1', escapeshellarg($this->binPath), escapeshellarg($root)), $genLines, $genExit);
        $generated = implode("\n", $genLines);
        self::assertSame(0, $genExit, $generated);
        self::assertStringContainsString('4 component(s), 0 failed, 2 skipped', $generated);
        self::assertFileExists("{$root}/hero/acf.json");
        self::assertFileExists("{$root}/legacy/acf.json");
        self::assertFileDoesNotExist("{$root}/alert/acf.json");
        self::assertFileDoesNotExist("{$root}/teaser/acf.json");

        $lintBin = __DIR__ . '/../../bin/fields-lint';
        exec(sprintf('php %s --root=%s 2>&1', escapeshellarg($lintBin), escapeshellarg($root)), $lintLines, $lintExit);
        $linted = implode("\n", $lintLines);
        self::assertSame(0, $lintExit, $linted);
        self::assertStringContainsString('OK   hero', $linted);
        self::assertStringContainsString('OK   legacy', $linted);
        self::assertStringContainsString('SKIP alert: kind element has no CMS projection', $linted);
        self::assertStringContainsString('SKIP teaser: kind part has no CMS projection', $linted);
        self::assertStringContainsString('4 component(s), 0 failed, 2 skipped', $linted);
    }

    public function test_a_definition_with_no_kind_keeps_getting_a_block_json(): void
    {
        // Absence of `kind` means "the backfill has not reached this file", not
        // "not a block" — inferring from silence would strip block.json from
        // every un-migrated component in a downstream repo.
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertFileExists("{$dir}/acf.json");
        self::assertFileExists("{$dir}/block.json");
    }

    public function test_known_options_are_not_caught_by_the_unknown_option_guard(): void
    {
        // The guard keys off a leading '-', so it must not swallow the real
        // flags sitting next to it.
        $dir = $this->makeComponentDir('demo', "name: Demo\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Nadpis\n");

        $dryRunOutput = shell_exec(sprintf(
            'php %s --dry-run %s 2>&1',
            escapeshellarg($this->binPath),
            escapeshellarg($dir),
        ));

        self::assertIsString($dryRunOutput);
        self::assertStringContainsString('OK   demo', $dryRunOutput);
        self::assertFileDoesNotExist("{$dir}/acf.json");

        $rootOutput = shell_exec(sprintf(
            'php %s --root=%s 2>&1',
            escapeshellarg($this->binPath),
            escapeshellarg(dirname($dir)),
        ));

        self::assertIsString($rootOutput);
        self::assertStringContainsString('OK   demo', $rootOutput);
        self::assertFileExists("{$dir}/acf.json");
    }
}
