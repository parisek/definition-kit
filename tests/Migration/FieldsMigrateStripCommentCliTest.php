<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * `fields-migrate --strip-comment` (#84): a component migrated before
 * component migration retired the front-comment the way page/doc migration
 * always did carries its metadata twice — the comment on the twig, the same
 * data in name.yaml. This mode removes the comment, but only once the yaml
 * already says everything the comment says.
 */
final class FieldsMigrateStripCommentCliTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = __DIR__ . '/../../bin/fields-migrate';
    }

    private function makeDir(string $name, string $twig, string $yaml): string
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-strip-' . uniqid('', true) . "/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$name}.twig", $twig);
        file_put_contents("{$dir}/{$name}.yaml", $yaml);

        return $dir;
    }

    public function test_dry_run_reports_would_strip_and_writes_nothing(): void
    {
        $dir = $this->makeDir(
            'gallery-slider',
            "{#\nname: \"Gallery Slider\"\nusage: career\ncategory: \"Block\"\n#}\n<div></div>\n",
            "name: 'Gallery Slider'\nusage:\n  - career\ncategory: Block\nfields: {  }\n",
        );

        $output = shell_exec(sprintf('php %s --strip-comment %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('WOULD gallery-slider', $output);
        self::assertStringContainsString('nothing written', $output);
        self::assertStringContainsString('{#', (string) file_get_contents("{$dir}/gallery-slider.twig"));
    }

    public function test_write_strips_a_matching_comment(): void
    {
        $dir = $this->makeDir(
            'gallery-slider',
            "{#\nname: \"Gallery Slider\"\nusage: career\ncategory: \"Block\"\n#}\n<div></div>\n",
            "name: 'Gallery Slider'\nusage:\n  - career\ncategory: Block\nfields: {  }\n",
        );

        $output = shell_exec(sprintf('php %s --strip-comment --write %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   gallery-slider', $output);
        $twig = (string) file_get_contents("{$dir}/gallery-slider.twig");
        self::assertStringNotContainsString('{#', $twig);
        self::assertSame("<div></div>\n", $twig);
    }

    public function test_refuses_when_yaml_disagrees_with_the_comment(): void
    {
        $dir = $this->makeDir(
            'bad',
            "{#\nname: \"Bad\"\ncategory: \"Block\"\n#}\n<div></div>\n",
            "name: 'Bad'\ncategory: Content\nfields: {  }\n",
        );

        $output = shell_exec(sprintf('php %s --strip-comment --write %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL bad', $output);
        self::assertStringContainsString("category: comment='Block' yaml='Content'", $output);
        self::assertStringContainsString('{#', (string) file_get_contents("{$dir}/bad.twig"));
    }

    public function test_refuses_when_yaml_is_missing_a_key_the_comment_has(): void
    {
        $dir = $this->makeDir(
            'missing-key',
            "{#\nname: \"Missing Key\"\nasana: https://app.asana.com/0/1/2\n#}\n<div></div>\n",
            "name: 'Missing Key'\ncategory: Content\nfields: {  }\n",
        );

        $output = shell_exec(sprintf('php %s --strip-comment --write %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL missing-key', $output);
        self::assertStringContainsString('asana:', $output);
        self::assertStringContainsString('{#', (string) file_get_contents("{$dir}/missing-key.twig"));
    }

    public function test_root_sweep_reports_mixed_results(): void
    {
        $root = sys_get_temp_dir() . '/fields-migrate-strip-' . uniqid('', true);
        mkdir("{$root}/good", 0777, true);
        file_put_contents("{$root}/good/good.twig", "{#\nname: \"Good\"\n#}\n<div></div>\n");
        file_put_contents("{$root}/good/good.yaml", "name: 'Good'\ncategory: Content\nfields: {  }\n");

        mkdir("{$root}/bad", 0777, true);
        file_put_contents("{$root}/bad/bad.twig", "{#\nname: \"Bad\"\ncategory: \"Block\"\n#}\n<div></div>\n");
        file_put_contents("{$root}/bad/bad.yaml", "name: 'Bad'\ncategory: Content\nfields: {  }\n");

        $output = shell_exec(sprintf('php %s --strip-comment --write --root=%s 2>&1', escapeshellarg($this->binPath), escapeshellarg($root)));

        self::assertIsString($output);
        self::assertStringContainsString('OK   good', $output);
        self::assertStringContainsString('FAIL bad', $output);
        self::assertStringContainsString('1 failed', $output);
        self::assertStringNotContainsString('{#', (string) file_get_contents("{$root}/good/good.twig"));
        self::assertStringContainsString('{#', (string) file_get_contents("{$root}/bad/bad.twig"));
    }

    public function test_no_front_comment_left_is_ok_and_not_counted_as_stripped(): void
    {
        $dir = $this->makeDir('plain', "<div></div>\n", "name: 'Plain'\ncategory: Content\nfields: {  }\n");

        $output = shell_exec(sprintf('php %s --strip-comment %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('no front-comment left to strip', $output);
        self::assertStringContainsString('0 to strip', $output);
    }

    public function test_missing_yaml_or_twig_fails_for_a_single_target(): void
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-strip-' . uniqid('', true) . '/lonely';
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/lonely.twig", "{#\nname: \"Lonely\"\n#}\n<div></div>\n");

        $output = shell_exec(sprintf('php %s --strip-comment %s 2>&1', escapeshellarg($this->binPath), escapeshellarg($dir)));

        self::assertIsString($output);
        self::assertStringContainsString('FAIL lonely', $output);
        self::assertStringContainsString('needs both', $output);
    }

    public function test_missing_root_or_single_argument_prints_usage(): void
    {
        $output = shell_exec(sprintf('php %s --strip-comment 2>&1; echo "exit=$?"', escapeshellarg($this->binPath)));

        self::assertIsString($output);
        self::assertStringContainsString('usage: fields-migrate --strip-comment', $output);
        self::assertStringContainsString('exit=2', $output);
    }
}
