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

    /**
     * Issue #18. A Gutenberg block with no editor fields has a block.json and
     * no acf.json — `divider`, `form-configurator` in the reference project.
     * Requiring acf.json locked out exactly the components 0.4.0 made
     * expressible, leaving hand-transcription of the block residual as the
     * only way to adopt one.
     */
    public function test_migrates_a_block_only_component_with_no_acf_json(): void
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true) . '/divider';
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/block.json", json_encode([
            'apiVersion' => 3,
            'name' => 'acf/divider',
            'title' => 'Divider',
            'acf' => ['mode' => 'preview', 'postTypes' => ['page']],
        ], JSON_PRETTY_PRINT));
        file_put_contents("{$dir}/divider.twig", "{#\nname: Divider\nkind: block\n#}\n");

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   divider', $output);
        self::assertFileExists("{$dir}/divider.yaml");

        $yaml = (string) file_get_contents("{$dir}/divider.yaml");
        // The block residual must be captured, not silently dropped — that
        // loss is what made hand-authoring necessary in the first place.
        self::assertStringContainsString('postTypes', $yaml);
        // And no ACF fields are invented: provenance for a non-ACF input
        // cannot be inferred from any file, so an honest empty map it is.
        self::assertStringContainsString('fields:', $yaml);
        self::assertFileDoesNotExist("{$dir}/acf.json");
    }

    public function test_adopts_a_component_that_has_only_a_twig(): void
    {
        // `button`, `picture`, `header`, `breadcrumb` — rendered by a caller,
        // never by the editor, so no ACF group and no block.json will ever
        // exist for them. 17 of mairateam's 69 components are this shape,
        // including the two most-called in the whole theme.
        $dir = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true) . '/button';
        mkdir($dir, 0777, true);
        file_put_contents(
            "{$dir}/button.twig",
            "{#\nname: \"Button\"\ncategory: \"Basic\"\ndescription: \"A button\"\n#}\n<a>{{ content.label }}</a>\n",
        );

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   button', $output);

        $yaml = (string) file_get_contents("{$dir}/button.yaml");
        // The twig front-comment carries the metadata, so the definition is
        // worth having even with no fields in it.
        self::assertStringContainsString('name: Button', $yaml);
        self::assertStringContainsString('category: Basic', $yaml);
        // And still no invented fields: `fields-roles` fills those from
        // evidence, this step does not guess.
        self::assertStringContainsString('fields: {  }', $yaml);
    }

    public function test_fails_only_when_there_is_nothing_on_disk_to_describe(): void
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true) . '/empty';
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/notes.md", "not a component\n");

        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL', $output);
        self::assertStringContainsString('no acf.json, block.json or empty.twig', $output);
    }

    public function test_root_sweep_covers_every_shape_the_single_form_accepts(): void
    {
        // The sweep used to migrate only acf.json components, silently passing
        // over the block-only ones the CLI had accepted one at a time since
        // 0.4.2.
        $root = sys_get_temp_dir() . '/fields-migrate-cli-' . uniqid('', true);
        foreach (['hero', 'divider', 'button'] as $name) {
            mkdir("{$root}/{$name}", 0777, true);
            file_put_contents("{$root}/{$name}/{$name}.twig", "{#\nname: \"{$name}\"\n#}\n");
        }
        file_put_contents("{$root}/hero/acf.json", json_encode([
            'key' => 'group_hero',
            'title' => 'Hero',
            'fields' => [['key' => 'field_hero_title', 'name' => 'title', 'label' => 'T', 'type' => 'text']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents("{$root}/divider/block.json", json_encode(['title' => 'Divider'], JSON_THROW_ON_ERROR));

        $output = shell_exec(sprintf('php %s --root=%s 2>&1', escapeshellarg($this->binPath), escapeshellarg($root)));

        self::assertIsString($output);
        foreach (['hero', 'divider', 'button'] as $name) {
            self::assertFileExists("{$root}/{$name}/{$name}.yaml", "{$name} was not migrated");
        }
    }
}
