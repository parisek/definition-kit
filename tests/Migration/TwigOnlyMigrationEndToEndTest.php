<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Codex review round 1, finding 3: exercises the twig-only migration path
 * through the real CLIs (fields-migrate -> fields-validate -> fields-generate),
 * not just AcfJsonReader in isolation — so a bug in the CLI glue (atomic
 * write, --force guard, the "no acf/block/twig at all" refusal) or in
 * FieldsGenerate's own kind-based projection skip would show up here too.
 */
final class TwigOnlyMigrationEndToEndTest extends TestCase
{
    private string $migrateBin;
    private string $validateBin;
    private string $generateBin;

    protected function setUp(): void
    {
        $this->migrateBin = __DIR__ . '/../../bin/fields-migrate';
        $this->validateBin = __DIR__ . '/../../bin/fields-validate';
        $this->generateBin = __DIR__ . '/../../bin/fields-generate';
    }

    private function makeDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/twig-only-e2e-' . uniqid('', true) . "/{$name}";
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function runCli(string $bin, string $arg): string
    {
        $output = shell_exec(sprintf('php %s %s 2>&1', escapeshellarg($bin), escapeshellarg($arg)));
        self::assertIsString($output);
        return $output;
    }

    /** fields-migrate specifically, with an --assume-role flag before the positional arg. */
    private function migrate(string $dir, string $assumeRole = 'parent'): string
    {
        $output = shell_exec(sprintf(
            'php %s --assume-role=%s %s 2>&1',
            escapeshellarg($this->migrateBin),
            escapeshellarg($assumeRole),
            escapeshellarg($dir),
        ));
        self::assertIsString($output);
        return $output;
    }

    public function test_twig_only_element_migrates_validates_and_generates_no_projection(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tplaceholder_text:\n\t\ttitle: Placeholder\n\t\ttype: text\n\t\tplaceholder: \"Search…\"\n\tmode:\n\t\ttitle: Mode\n\t\ttype: select\n\t\toptions: compact, expanded\n#}\n<div></div>\n");

        $migrateOut = $this->migrate($dir);
        self::assertStringContainsString('OK   search-box', $migrateOut);
        self::assertFileExists("{$dir}/search-box.yaml");

        $yaml = file_get_contents("{$dir}/search-box.yaml");
        self::assertIsString($yaml);
        self::assertStringContainsString('placeholder: Search', $yaml, 'placeholder must survive the migration (Codex finding 1)');

        $validateOut = $this->runCli($this->validateBin, "{$dir}/search-box.yaml");
        self::assertStringContainsString('OK', $validateOut);
        self::assertStringNotContainsString('error', strtolower($validateOut));

        // An `element` has no CMS projection (#72) — fields-generate must
        // SKIP it and write neither acf.json nor block.json, exactly as it
        // does for a hand-authored definition of the same kind.
        $generateOut = $this->runCli($this->generateBin, $dir);
        self::assertStringContainsString('SKIP search-box', $generateOut);
        self::assertFileDoesNotExist("{$dir}/acf.json");
        self::assertFileDoesNotExist("{$dir}/block.json");
    }

    public function test_nested_repeater_of_groups_round_trips(): void
    {
        // Codex review round 7: this test used to only migrate + validate +
        // substring-search the YAML, without ever invoking fields-generate
        // or structurally checking the nested shape — a regression at the
        // migrate -> generate boundary for nested group/repeater would not
        // have been caught. Now parses the YAML structurally and runs
        // fields-generate too (a `utility` component, like `element`, has
        // no CMS projection — see FieldsGenerator's kind-based skip).
        $dir = $this->makeDir('link-list');
        file_put_contents("{$dir}/link-list.twig", "{#\nname: Link List\nkind: utility\ncategory: Basic\nfields:\n\titems:\n\t\ttitle: Items\n\t\ttype: repeater\n\t\tfields:\n\t\t\tlink:\n\t\t\t\ttitle: Link\n\t\t\t\ttype: group\n\t\t\t\tfields:\n\t\t\t\t\turl:\n\t\t\t\t\t\ttitle: Url\n\t\t\t\t\t\ttype: url\n\t\t\t\t\ttitle:\n\t\t\t\t\t\ttitle: Title\n\t\t\t\t\t\ttype: text\n#}\n<div></div>\n");

        $this->migrate($dir);
        self::assertFileExists("{$dir}/link-list.yaml");

        $validateOut = $this->runCli($this->validateBin, "{$dir}/link-list.yaml");
        self::assertStringContainsString('OK', $validateOut);

        $parsed = \Symfony\Component\Yaml\Yaml::parseFile("{$dir}/link-list.yaml");
        self::assertSame('repeater', $parsed['fields']['items']['type']);
        self::assertSame('parent', $parsed['fields']['items']['role']);
        self::assertSame('group', $parsed['fields']['items']['fields']['link']['type']);
        self::assertSame('parent', $parsed['fields']['items']['fields']['link']['role']);
        self::assertSame('link', $parsed['fields']['items']['fields']['link']['fields']['url']['type']);
        self::assertSame('url', $parsed['fields']['items']['fields']['link']['fields']['url']['shape']);
        self::assertSame('parent', $parsed['fields']['items']['fields']['link']['fields']['url']['role']);
        self::assertSame('text', $parsed['fields']['items']['fields']['link']['fields']['title']['type']);

        $generateOut = $this->runCli($this->generateBin, $dir);
        self::assertStringContainsString('SKIP link-list', $generateOut);
        self::assertFileDoesNotExist("{$dir}/acf.json");
        self::assertFileDoesNotExist("{$dir}/block.json");
    }

    public function test_unmapped_annotation_prop_fails_the_migrate_cli_and_writes_nothing(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tterm:\n\t\ttitle: Term\n\t\ttype: text\n\t\tcms_type: string\n#}\n<div></div>\n");

        $output = $this->runCli($this->migrateBin, $dir);

        self::assertStringContainsString('FAIL search-box', $output);
        self::assertStringContainsString('cms_type', $output);
        self::assertFileDoesNotExist("{$dir}/search-box.yaml");
    }

    public function test_select_with_no_options_fails_the_migrate_cli_and_writes_nothing(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tmode:\n\t\ttitle: Mode\n\t\ttype: select\n#}\n<div></div>\n");

        // --assume-role: this test targets the select-options validation, not
        // provenance — since round 6, role is resolved before a select's own
        // options are validated, so an ambiguous-provenance field would fail
        // on that first instead.
        $output = $this->migrate($dir);

        self::assertStringContainsString('FAIL search-box', $output);
        self::assertStringContainsString('select', $output);
        self::assertFileDoesNotExist("{$dir}/search-box.yaml");
    }

    public function test_a_kind_less_component_with_no_acf_json_still_migrates_and_generates_block_json(): void
    {
        // A twig with no `kind:` at all is the pre-#72 default, so
        // fields-generate does not SKIP it on kind alone. But every field
        // this migration derives from a twig annotation is `role: parent`
        // (never ACF-backed — see TwigFieldTypeMapper's class doc header),
        // so there is nothing to project into acf.json; only block.json is
        // written. This is FieldsGenerator's own existing role-based
        // projection rule, not something the twig-only fallback changes.
        $dir = $this->makeDir('legacy-card');
        file_put_contents("{$dir}/legacy-card.twig", "{#\nname: Legacy Card\ncategory: Basic\nfields:\n\ttitle:\n\t\ttitle: Title\n\t\ttype: text\n\t\trequired: 1\n#}\n<div></div>\n");

        $this->migrate($dir);
        self::assertFileExists("{$dir}/legacy-card.yaml");

        $generateOut = $this->runCli($this->generateBin, $dir);
        self::assertStringContainsString('OK   legacy-card', $generateOut);
        self::assertFileExists("{$dir}/block.json");
        self::assertFileDoesNotExist("{$dir}/acf.json");
    }

    // --- Codex review round 5, finding 1: --assume-role, end-to-end -----

    public function test_without_assume_role_a_twig_only_component_is_refused(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tmode:\n\t\ttitle: Mode\n\t\ttype: text\n#}\n<div></div>\n");

        $output = $this->runCli($this->migrateBin, $dir);

        self::assertStringContainsString('FAIL search-box', $output);
        self::assertStringContainsString('ambiguous provenance', $output);
        self::assertFileDoesNotExist("{$dir}/search-box.yaml");
    }

    public function test_assume_role_query_is_applied_top_level_and_nested(): void
    {
        $dir = $this->makeDir('pagination-like');
        file_put_contents("{$dir}/pagination-like.twig", "{#\nname: Pagination Like\nkind: utility\ncategory: Basic\nfields:\n\titems:\n\t\ttitle: Items\n\t\ttype: repeater\n\t\tfields:\n\t\t\turl:\n\t\t\t\ttitle: Url\n\t\t\t\ttype: url\n#}\n<div></div>\n");

        $output = $this->migrate($dir, 'query');

        self::assertStringContainsString('OK   pagination-like', $output);
        $yaml = file_get_contents("{$dir}/pagination-like.yaml");
        self::assertIsString($yaml);
        self::assertSame(2, substr_count($yaml, 'role: query'), 'both items (repeater) and its nested url must be role: query');
    }

    public function test_explicit_twig_role_overrides_assume_role_end_to_end(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tmode:\n\t\ttitle: Mode\n\t\ttype: text\n\t\trole: global\n#}\n<div></div>\n");

        $output = $this->migrate($dir, 'parent');

        self::assertStringContainsString('OK   search-box', $output);
        $yaml = file_get_contents("{$dir}/search-box.yaml");
        self::assertIsString($yaml);
        self::assertStringContainsString('role: global', $yaml);
        self::assertStringNotContainsString('role: parent', $yaml);
    }

    public function test_invalid_assume_role_value_is_refused_at_the_cli(): void
    {
        $dir = $this->makeDir('search-box');
        file_put_contents("{$dir}/search-box.twig", "{#\nname: Search Box\nkind: element\ncategory: Basic\nfields:\n\tmode:\n\t\ttitle: Mode\n\t\ttype: text\n#}\n<div></div>\n");

        $output = $this->migrate($dir, 'field');

        self::assertStringContainsString('invalid --assume-role', $output);
        self::assertFileDoesNotExist("{$dir}/search-box.yaml");
    }

    public function test_assume_role_is_ignored_for_an_acf_json_backed_component_end_to_end(): void
    {
        $dir = $this->makeDir('demo');
        file_put_contents("{$dir}/acf.json", json_encode([
            'key' => 'group_demo',
            'title' => 'Demo',
            'fields' => [
                ['key' => 'field_demo_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
            ],
        ], JSON_PRETTY_PRINT));
        file_put_contents("{$dir}/demo.twig", "{#\nname: Demo\ncategory: Basic\n#}\n<div></div>\n");

        $output = $this->migrate($dir, 'global');

        self::assertStringContainsString('OK   demo', $output);
        $yaml = file_get_contents("{$dir}/demo.yaml");
        self::assertIsString($yaml);
        self::assertStringNotContainsString('role: global', $yaml);
    }
}
