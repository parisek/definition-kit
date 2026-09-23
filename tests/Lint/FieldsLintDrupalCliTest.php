<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldsLintDrupalCliTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal';
    private const BIN = __DIR__ . '/../../bin/fields-lint-drupal';

    /**
     * A components root with the golden definitions next to their twigs, and
     * the fixture definition-kit.yaml one level up.
     */
    private function project(): string
    {
        $dir = sys_get_temp_dir() . '/fields-lint-drupal-' . uniqid('', true);
        mkdir("{$dir}/component", 0777, true);
        copy(self::FIXTURES . '/definition-kit.yaml', "{$dir}/definition-kit.yaml");
        foreach (glob(self::FIXTURES . '/component/*', GLOB_ONLYDIR) ?: [] as $source) {
            $name = basename($source);
            mkdir("{$dir}/component/{$name}");
            foreach (glob("{$source}/*") ?: [] as $file) {
                copy($file, "{$dir}/component/{$name}/" . basename($file));
            }
            if (is_file(self::FIXTURES . "/expected/{$name}.yaml")) {
                copy(self::FIXTURES . "/expected/{$name}.yaml", "{$dir}/component/{$name}/{$name}.yaml");
            }
        }
        file_put_contents(
            "{$dir}/component/logo-list/logo-list.yaml",
            "name: 'Logo List'\ncategory: Block\ndrupal: /admin/structure/paragraphs_type/logo_list/fields\nfields: {}\n",
        );

        return "{$dir}/component";
    }

    /** @return array{string, int} */
    private function runBin(string ...$args): array
    {
        $cmd = 'php ' . escapeshellarg(self::BIN) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $lines, $code);

        return [implode("\n", $lines), $code];
    }

    #[Test]
    public function a_root_run_reports_each_component_and_the_unclaimed_bundles(): void
    {
        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', '--root=' . $this->project());

        self::assertSame(1, $code);
        self::assertStringContainsString('OK   card-list (card_list)', $output);
        self::assertStringContainsString("DRIFT content (content, html)\n    html: field_title (string): Drupal field has no definition field", $output);
        self::assertStringContainsString('DRIFT logo-list (logo_list)', $output);
        self::assertStringContainsString('SKIP header-note: no header-note.yaml yet', $output);
        self::assertStringContainsString("UNCLAIMED paragraph types (no component describes them):\n    image_full\n    mixed_section\n    teaser", $output);
        self::assertStringContainsString('7 component(s), 5 failed, 1 skipped', $output);
    }

    #[Test]
    public function a_single_clean_component_exits_zero_and_reports_no_unclaimed_bundles(): void
    {
        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', $this->project() . '/card-list');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('OK   card-list (card_list)', $output);
        self::assertStringNotContainsString('UNCLAIMED', $output);
        self::assertStringContainsString('1 component(s), 0 failed', $output);
    }

    #[Test]
    public function an_invalid_definition_fails_with_the_schema_error(): void
    {
        $root = $this->project();
        file_put_contents("{$root}/card-list/card-list.yaml", "name: X\ncategory: Block\nfields:\n  a:\n    type: text\n    label: A\n    drupal:\n      table: nope\n");

        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "{$root}/card-list");

        self::assertSame(1, $code);
        self::assertStringContainsString('FAIL card-list: card-list.yaml is not a valid definition', $output);
    }

    #[Test]
    public function the_config_directory_is_required(): void
    {
        [$output, $code] = $this->runBin('--root=' . $this->project());

        self::assertSame(2, $code);
        self::assertStringContainsString('usage: fields-lint-drupal', $output);
    }

    #[Test]
    public function a_directory_that_is_no_config_export_is_refused(): void
    {
        [$output, $code] = $this->runBin('--drupal-config=' . __DIR__, '--root=' . $this->project());

        self::assertSame(2, $code);
        self::assertStringContainsString('config export', $output);
    }

    #[Test]
    public function an_unknown_option_is_refused(): void
    {
        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', '--drupal-configs=x', '--root=' . $this->project());

        self::assertSame(2, $code);
        self::assertStringContainsString('unknown option: --drupal-configs=x', $output);
    }
}
