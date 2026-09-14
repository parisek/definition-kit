<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every `bin/` command meets a `doc/<id>/<id>.yaml` without a stack trace.
 *
 * `fields-validate` checks it against doc.schema.json. `fields-migrate`
 * moves the doc's twig front-comment into it. The projection commands skip it.
 */
final class DocDefinitionCliTest extends TestCase
{
    private const TWIG_BODY = "{% set scale = [] %}\n<article class=\"prose\"></article>\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/doc-definition-cli-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/doc/typography", 0777, true);
        file_put_contents("{$this->root}/doc/typography/typography.twig", self::TWIG_BODY);
        file_put_contents("{$this->root}/doc/typography/styleguide.twig", "{{ include('@doc/typography/typography.twig') }}\n");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/{,.}[!.,!..]*", GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) && !is_link($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function writeDocYaml(string $yaml): string
    {
        $path = "{$this->root}/doc/typography/typography.yaml";
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
    public function validate_accepts_a_valid_doc(): void
    {
        $path = $this->writeDocYaml("name: Typografie\ndescription: Škála\nweight: 98\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("OK   {$path}", $out);
    }

    #[Test]
    public function validate_refuses_a_component_key_on_a_doc_and_names_it(): void
    {
        $path = $this->writeDocYaml("name: Typografie\nkind: part\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString("FAIL {$path}", $out);
        self::assertStringContainsString('`kind:`', $out);
        self::assertStringContainsString('doc.schema.json', $out);
    }

    #[Test]
    public function validate_refuses_responsive_on_a_doc_and_names_it(): void
    {
        $path = $this->writeDocYaml("name: Typografie\nresponsive: true\n");

        [$out, $code] = $this->runBin('fields-validate', [$path]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('`responsive:`', $out);
    }

    #[Test]
    public function lint_generate_and_roles_skip_a_doc(): void
    {
        $this->writeDocYaml("name: Typografie\n");

        [$out, $code] = $this->runBin('fields-lint', ["{$this->root}/doc/typography"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP typography', $out);

        [$out, $code] = $this->runBin('fields-lint', ['--contract-only', "--root={$this->root}/doc"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP typography', $out);

        [$out, $code] = $this->runBin('fields-generate', ["--root={$this->root}/doc"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP typography', $out);
        self::assertFileDoesNotExist("{$this->root}/doc/typography/acf.json");
        self::assertFileDoesNotExist("{$this->root}/doc/typography/block.json");

        [$out, $code] = $this->runBin('fields-roles', ['--write', "{$this->root}/doc/typography"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP typography', $out);
        self::assertSame("name: Typografie\n", file_get_contents("{$this->root}/doc/typography/typography.yaml"));
    }

    #[Test]
    public function fixtures_audit_runs_with_a_doc_yaml_present(): void
    {
        $this->writeDocYaml("name: Typografie\n");

        [$out, $code] = $this->runBin('fields-fixtures', [
            "--templates={$this->root}",
            "--static={$this->root}",
            "--config={$this->root}/no-such-styleguide.yaml",
        ]);

        self::assertNotSame(255, $code, $out);
        self::assertNotSame(2, $code, $out);
    }

    #[Test]
    public function migrate_moves_a_doc_front_comment_into_doc_yaml(): void
    {
        file_put_contents(
            "{$this->root}/doc/typography/typography.twig",
            "{#\nname: \"Typografie\"\ndescription: \"Škála a próza na jednom místě.\"\nweight: 98\n#}\n" . self::TWIG_BODY,
        );

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/doc/typography"]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK   typography', $out);
        $yaml = (string) file_get_contents("{$this->root}/doc/typography/typography.yaml");
        self::assertSame(
            "# yaml-language-server: \$schema=../../../../vendor/parisek/definition-kit/schemas/doc.schema.json\n"
            . "name: Typografie\ndescription: 'Škála a próza na jednom místě.'\nweight: 98\n",
            $yaml,
        );
        self::assertSame(self::TWIG_BODY, file_get_contents("{$this->root}/doc/typography/typography.twig"));

        [$out, $code] = $this->runBin('fields-validate', ["{$this->root}/doc/typography/typography.yaml"]);
        self::assertSame(0, $code, $out);
    }

    #[Test]
    public function migrate_refuses_a_doc_front_comment_with_a_refused_key(): void
    {
        $twig = "{#\nname: Typografie\nrender: bleed\n#}\n" . self::TWIG_BODY;
        file_put_contents("{$this->root}/doc/typography/typography.twig", $twig);

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/doc/typography"]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('FAIL typography', $out);
        self::assertStringContainsString('`render:`', $out);
        self::assertFileDoesNotExist("{$this->root}/doc/typography/typography.yaml");
        self::assertSame($twig, file_get_contents("{$this->root}/doc/typography/typography.twig"));
    }

    #[Test]
    public function migrate_root_finds_nested_docs_and_skips_partials_and_symlinks(): void
    {
        mkdir("{$this->root}/doc/guides/setup", 0777, true);
        mkdir("{$this->root}/doc/_partials", 0777, true);
        file_put_contents("{$this->root}/doc/typography/typography.twig", "{# name: Typografie #}\n" . self::TWIG_BODY);
        file_put_contents("{$this->root}/doc/guides/setup/setup.twig", "{# name: Setup #}\n<p></p>\n");
        file_put_contents("{$this->root}/doc/_partials/_partials.twig", "{# name: Partial #}\n");
        symlink("{$this->root}/doc/typography", "{$this->root}/doc/alias");

        [$out, $code] = $this->runBin('fields-migrate', ["--root={$this->root}/doc"]);

        self::assertSame(0, $code, $out);
        self::assertSame(1, substr_count($out, 'OK   typography'), $out);
        self::assertStringContainsString('OK   setup', $out);
        self::assertStringNotContainsString('alias', $out);
        self::assertStringStartsWith(
            '# yaml-language-server: $schema=../../../../../vendor/parisek/definition-kit/schemas/doc.schema.json',
            (string) file_get_contents("{$this->root}/doc/guides/setup/setup.yaml"),
        );
        self::assertFileDoesNotExist("{$this->root}/doc/_partials/_partials.yaml");
    }

    #[Test]
    public function migrate_dry_run_touches_neither_file(): void
    {
        $twig = "{# name: Typografie #}\n" . self::TWIG_BODY;
        file_put_contents("{$this->root}/doc/typography/typography.twig", $twig);

        [$out, $code] = $this->runBin('fields-migrate', ['--dry-run', "--root={$this->root}/doc"]);

        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist("{$this->root}/doc/typography/typography.yaml");
        self::assertSame($twig, file_get_contents("{$this->root}/doc/typography/typography.twig"));
    }

    #[Test]
    public function migrate_skips_an_existing_doc_yaml_unless_forced(): void
    {
        $this->writeDocYaml("name: Authored\n");
        file_put_contents("{$this->root}/doc/typography/typography.twig", "{# name: Typografie #}\n" . self::TWIG_BODY);

        [$out, $code] = $this->runBin('fields-migrate', ["{$this->root}/doc/typography"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('SKIP typography', $out);
        self::assertSame("name: Authored\n", file_get_contents("{$this->root}/doc/typography/typography.yaml"));

        [$out, $code] = $this->runBin('fields-migrate', ['--force', "{$this->root}/doc/typography"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('name: Typografie', (string) file_get_contents("{$this->root}/doc/typography/typography.yaml"));
        self::assertSame(self::TWIG_BODY, file_get_contents("{$this->root}/doc/typography/typography.twig"));
    }
}
