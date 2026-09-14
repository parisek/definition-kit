<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every `bin/` command meets a `page/<id>/<id>.yaml` without a stack trace.
 *
 * `fields-validate` checks it against page.schema.json. `fields-migrate`
 * distils the page's twig front-comment into it. The projection commands
 * (`fields-lint`, `fields-generate`, `fields-roles`) skip it: a page has no
 * acf.json, block.json or input contract.
 */
final class PageDefinitionCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/page-definition-cli-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/component/demo", 0777, true);
        mkdir("{$this->root}/page/home", 0777, true);
        file_put_contents("{$this->root}/component/demo/demo.twig", "<p>{{ content.title }}</p>\n");
        file_put_contents(
            "{$this->root}/component/demo/demo.yaml",
            "name: Demo\nkind: element\nfields:\n  title:\n    type: text\n    label: Title\n    acf: false\n    role: field\n",
        );
        file_put_contents("{$this->root}/page/home/home.twig", "<main>{{ component_demo({ title: 'Hi' }) }}</main>\n");
        file_put_contents("{$this->root}/page/home/styleguide.twig", "{{ page_home({}) }}\n");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/{,.}[!.,!..]*", GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function writePageYaml(string $yaml): string
    {
        $path = "{$this->root}/page/home/home.yaml";
        file_put_contents($path, $yaml);

        return $path;
    }

    /**
     * @param list<string> $args
     * @return array{0: string, 1: int}
     */
    private function runBin(string $bin, array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . "/bin/{$bin}");
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $output, $exitCode);
        $out = implode("\n", $output);
        self::assertStringNotContainsString('Stack trace', $out);
        self::assertStringNotContainsString('Uncaught', $out);

        return [$out, $exitCode];
    }

    #[Test]
    public function validate_accepts_a_valid_page(): void
    {
        $path = $this->writePageYaml("name: Home\nusage: [demo]\nweight: 1\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("OK   {$path}", $out);
    }

    #[Test]
    public function validate_refuses_fields_on_a_page_and_says_why(): void
    {
        $path = $this->writePageYaml("name: Home\nfields:\n  title:\n    type: text\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString("FAIL {$path}", $out);
        self::assertStringContainsString('`fields:`', $out);
        self::assertStringContainsString('page.schema.json', $out);
    }

    #[Test]
    public function validate_refuses_kind_on_a_page_and_says_why(): void
    {
        $path = $this->writePageYaml("name: Home\nkind: section\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('`kind:`', $out);
    }

    #[Test]
    public function validate_still_checks_components_against_the_component_schema(): void
    {
        [$out, $code] = $this->runBin('fields-validate', ["{$this->root}/component/demo/demo.yaml"]);
        self::assertSame(0, $code, $out);

        file_put_contents("{$this->root}/component/demo/demo.yaml", "name: Demo\n");
        [$out, $code] = $this->runBin('fields-validate', ["{$this->root}/component/demo/demo.yaml"]);
        self::assertSame(1, $code, $out);
    }

    #[Test]
    public function lint_skips_a_page(): void
    {
        $this->writePageYaml("name: Home\n");

        [$out, $code] = $this->runBin('fields-lint', ["{$this->root}/page/home"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP home', $out);

        [$out, $code] = $this->runBin('fields-lint', ['--contract-only', "--root={$this->root}/page"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP home', $out);
    }

    #[Test]
    public function generate_skips_a_page_and_writes_nothing(): void
    {
        $this->writePageYaml("name: Home\n");

        [$out, $code] = $this->runBin('fields-generate', ["{$this->root}/page/home"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP home', $out);

        [$out, $code] = $this->runBin('fields-generate', ["--root={$this->root}/page"]);
        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist("{$this->root}/page/home/acf.json");
        self::assertFileDoesNotExist("{$this->root}/page/home/block.json");
    }

    #[Test]
    public function a_missing_directory_under_page_is_not_skipped_as_a_page(): void
    {
        [$out, $code] = $this->runBin('fields-generate', ["{$this->root}/page/hmoe"]);

        self::assertSame(1, $code, $out);
        self::assertStringNotContainsString('SKIP', $out);
    }

    #[Test]
    public function roles_skips_a_page(): void
    {
        $this->writePageYaml("name: Home\n");

        [$out, $code] = $this->runBin('fields-roles', ['--write', "{$this->root}/page/home"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP home', $out);

        [$out, $code] = $this->runBin('fields-roles', ["--root={$this->root}/page"]);
        self::assertSame(0, $code, $out);
        self::assertSame("name: Home\n", file_get_contents("{$this->root}/page/home/home.yaml"));
    }

    #[Test]
    public function fixtures_audit_runs_with_a_page_yaml_present(): void
    {
        $this->writePageYaml("name: Home\nusage: [demo]\n");

        [$out, $code] = $this->runBin('fields-fixtures', [
            "--templates={$this->root}",
            "--static={$this->root}",
            "--config={$this->root}/no-such-styleguide.yaml",
        ]);

        self::assertNotSame(255, $code, $out);
        self::assertNotSame(2, $code, $out);
    }

    #[Test]
    public function migrate_distils_a_page_front_comment_into_page_yaml(): void
    {
        file_put_contents(
            "{$this->root}/page/home/home.twig",
            "{#\nname: \"Hlavní strana\"\nusage: page-header-image,gallery-slider\ndescription: \"\"\ncategory: \"Page\"\nweight: 1\nbody_class: \"bg-dark\"\n#}\n<main>{{ component_demo({ title: 'Hi' }) }}</main>\n",
        );

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/home"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK   home', $out);
        $yaml = (string) file_get_contents("{$this->root}/page/home/home.yaml");
        self::assertStringStartsWith(
            "# yaml-language-server: \$schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json\n",
            $yaml,
        );
        self::assertStringContainsString('name: \'Hlavní strana\'', $yaml);
        self::assertStringContainsString("usage:\n  - page-header-image\n  - gallery-slider\n", $yaml);
        self::assertStringContainsString('weight: 1', $yaml);
        self::assertStringContainsString('body_class: bg-dark', $yaml);
        self::assertStringNotContainsString('description', $yaml, 'an empty description carries nothing');
        self::assertStringNotContainsString('fields', $yaml);
        self::assertStringNotContainsString('kind', $yaml);
        self::assertSame(
            "<main>{{ component_demo({ title: 'Hi' }) }}</main>\n",
            file_get_contents("{$this->root}/page/home/home.twig"),
        );

        [$out, $code] = $this->runBin('fields-validate', ["{$this->root}/page/home/home.yaml"]);
        self::assertSame(0, $code, $out);
    }

    #[Test]
    public function migrate_reads_an_unquoted_numeric_usage_as_a_component_name(): void
    {
        mkdir("{$this->root}/page/404");
        file_put_contents("{$this->root}/page/404/404.twig", "{#\nname: \"404\"\nusage: 404\nweight: 110\n#}\n<main></main>\n");

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/404"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("usage:\n  - '404'\n", (string) file_get_contents("{$this->root}/page/404/404.yaml"));
    }

    #[Test]
    public function migrate_finds_the_metadata_comment_after_leading_twig(): void
    {
        file_put_contents("{$this->root}/page/home/home.twig", "{% extends 'base.twig' %}\n{# name: Home #}\n<main></main>\n");

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/home"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('name: Home', (string) file_get_contents("{$this->root}/page/home/home.yaml"));
        self::assertSame("{% extends 'base.twig' %}\n<main></main>\n", file_get_contents("{$this->root}/page/home/home.twig"));
    }

    #[Test]
    public function migrate_root_finds_a_page_nested_in_a_group_directory(): void
    {
        mkdir("{$this->root}/page/blog/post", 0777, true);
        mkdir("{$this->root}/page/_partials", 0777, true);
        file_put_contents("{$this->root}/page/blog/post/post.twig", "{# name: Post #}\n<main></main>\n");
        file_put_contents("{$this->root}/page/home/home.twig", "{# name: Home #}\n<main></main>\n");
        file_put_contents("{$this->root}/page/_partials/_partials.twig", "{# name: Partial #}\n");

        [$out, $code] = $this->runBin('fields-migrate', ["--root={$this->root}/page"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK   post', $out);
        self::assertFileExists("{$this->root}/page/blog/post/post.yaml");
        self::assertStringStartsWith(
            '# yaml-language-server: $schema=../../../../../vendor/parisek/definition-kit/schemas/page.schema.json',
            (string) file_get_contents("{$this->root}/page/blog/post/post.yaml"),
        );
        self::assertFileExists("{$this->root}/page/home/home.yaml");
        self::assertFileDoesNotExist("{$this->root}/page/_partials/_partials.yaml");
    }

    #[Test]
    public function migrate_root_survives_a_symlink_cycle_and_migrates_each_page_once(): void
    {
        mkdir("{$this->root}/page/blog/post", 0777, true);
        file_put_contents("{$this->root}/page/blog/post/post.twig", "{# name: Post #}\n<main></main>\n");
        file_put_contents("{$this->root}/page/home/home.twig", "{# name: Home #}\n<main></main>\n");
        symlink("{$this->root}/page/blog", "{$this->root}/page/blog/post/loop");
        symlink("{$this->root}/page/home", "{$this->root}/page/alias");

        [$out, $code] = $this->runBin('fields-migrate', ["--root={$this->root}/page"]);
        unlink("{$this->root}/page/blog/post/loop");
        unlink("{$this->root}/page/alias");

        self::assertSame(0, $code, $out);
        self::assertSame(1, substr_count($out, 'OK   post'), $out);
        self::assertSame(1, substr_count($out, 'OK   home'), $out);
    }

    #[Test]
    public function migrate_dry_run_touches_neither_file(): void
    {
        $twig = "{# name: Home #}\n<main></main>\n";
        file_put_contents("{$this->root}/page/home/home.twig", $twig);

        [$out, $code] = $this->runBin('fields-migrate', ['--dry-run', "--root={$this->root}/page"]);

        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist("{$this->root}/page/home/home.yaml");
        self::assertSame($twig, file_get_contents("{$this->root}/page/home/home.twig"));
    }

    #[Test]
    public function migrate_refuses_a_page_front_comment_with_a_component_only_key(): void
    {
        $twig = "{#\nname: Home\nfields:\n  title:\n    type: text\n#}\n<main></main>\n";
        file_put_contents("{$this->root}/page/home/home.twig", $twig);

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/home"]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('FAIL home', $out);
        self::assertStringContainsString('`fields:`', $out);
        self::assertFileDoesNotExist("{$this->root}/page/home/home.yaml");
        self::assertSame($twig, file_get_contents("{$this->root}/page/home/home.twig"));
    }

    #[Test]
    public function migrate_does_not_overwrite_an_existing_page_yaml(): void
    {
        $this->writePageYaml("name: Authored\n");
        $twig = "{# name: Home #}\n<main></main>\n";
        file_put_contents("{$this->root}/page/home/home.twig", $twig);

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/home"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP home', $out);
        self::assertSame("name: Authored\n", file_get_contents("{$this->root}/page/home/home.yaml"));
        self::assertSame($twig, file_get_contents("{$this->root}/page/home/home.twig"));
    }

    #[Test]
    public function migrate_fails_a_page_twig_without_a_front_comment(): void
    {
        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/page/home"]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('FAIL home', $out);
        self::assertFileDoesNotExist("{$this->root}/page/home/home.yaml");
    }
}
