<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FieldsMigrateDrupalCliTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal';
    private const BIN = __DIR__ . '/../../bin/fields-migrate';

    private function project(): string
    {
        $dir = sys_get_temp_dir() . '/fields-migrate-drupal-' . uniqid('', true);
        mkdir("{$dir}/component", 0777, true);
        copy(self::FIXTURES . '/definition-kit.yaml', "{$dir}/definition-kit.yaml");
        foreach (glob(self::FIXTURES . '/component/*', GLOB_ONLYDIR) ?: [] as $source) {
            $name = basename($source);
            mkdir("{$dir}/component/{$name}");
            copy("{$source}/{$name}.twig", "{$dir}/component/{$name}/{$name}.twig");
        }

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
    public function the_config_export_is_the_field_source_and_the_twig_the_metadata_source(): void
    {
        $root = $this->project();

        [$output, $code] = $this->runBin(
            '--drupal-config=' . self::FIXTURES . '/config',
            '--drupal-display=' . self::FIXTURES . '/ParagraphDisplay.php',
            '--default-category=Content',
            '--assume-role=parent',
            "--root={$root}",
        );

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('(fields from Drupal paragraph type card_list)', $output);
        self::assertStringContainsString('(fields from Drupal paragraph type promo; the twig fields: annotation was not used)', $output);
        foreach (['card-list', 'quote-image', 'stats', 'promo'] as $name) {
            self::assertSame(
                Yaml::parseFile(self::FIXTURES . "/expected/{$name}.yaml"),
                Yaml::parseFile("{$root}/{$name}/{$name}.yaml"),
                "{$name}.yaml differs from the golden file",
            );
        }
        // `content` aliases both `content` and `html` (drupal.bundle_aliases)
        // — its migrated output merges both bundles (gap 3), unlike the
        // single-bundle golden every other component here compares against.
        self::assertSame(
            Yaml::parseFile(self::FIXTURES . '/expected/content-merged.yaml'),
            Yaml::parseFile("{$root}/content/content.yaml"),
            'content.yaml differs from the merged-bundles golden file',
        );
    }

    #[Test]
    public function the_output_lints_clean_against_the_same_export(): void
    {
        $root = $this->project();
        $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "--root={$root}");

        exec(sprintf(
            'php %s --drupal-config=%s %s 2>&1',
            escapeshellarg(__DIR__ . '/../../bin/fields-lint-drupal'),
            escapeshellarg(self::FIXTURES . '/config'),
            escapeshellarg("{$root}/quote-image"),
        ), $lines, $code);

        self::assertSame(0, $code, implode("\n", $lines));
    }

    #[Test]
    public function a_bundle_found_by_convention_gets_kind_block(): void
    {
        $root = $this->project();

        $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "{$root}/stats");

        $tree = Yaml::parseFile("{$root}/stats/stats.yaml");
        self::assertIsArray($tree);
        self::assertSame(['name', 'category', 'kind', 'fields'], array_keys($tree));
        self::assertSame('block', $tree['kind']);
    }

    #[Test]
    public function a_linked_bundle_missing_from_the_export_gets_no_fields_and_no_kind(): void
    {
        $root = $this->project();

        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "{$root}/logo-list");

        self::assertSame(0, $code, $output);
        $tree = Yaml::parseFile("{$root}/logo-list/logo-list.yaml");
        self::assertIsArray($tree);
        self::assertArrayNotHasKey('kind', $tree);
        self::assertSame([], $tree['fields']);
    }

    #[Test]
    public function without_an_export_the_admin_link_makes_twig_fields_editor_authored(): void
    {
        $root = $this->project();

        [$output, $code] = $this->runBin("{$root}/promo");

        self::assertSame(0, $code, $output);
        $tree = Yaml::parseFile("{$root}/promo/promo.yaml");
        self::assertIsArray($tree);
        self::assertSame('block', $tree['kind']);
        self::assertSame(['type' => 'text', 'label' => 'Title'], $tree['fields']['title']);
        self::assertArrayNotHasKey('role', $tree['fields']['body']['fields']['content']);
    }

    #[Test]
    public function a_component_that_is_no_paragraph_keeps_the_twig_rules(): void
    {
        $root = $this->project();

        [$output] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "{$root}/header-note");

        self::assertStringContainsString("FAIL header-note: Field 'text' has ambiguous provenance", $output);
    }

    #[Test]
    public function default_category_fills_only_a_missing_category(): void
    {
        $root = $this->project();

        $this->runBin('--drupal-config=' . self::FIXTURES . '/config', '--default-category=Content', "{$root}/content");
        $this->runBin('--drupal-config=' . self::FIXTURES . '/config', '--default-category=Content', "{$root}/card-list");

        $content = Yaml::parseFile("{$root}/content/content.yaml");
        $cardList = Yaml::parseFile("{$root}/card-list/card-list.yaml");
        self::assertIsArray($content);
        self::assertIsArray($cardList);
        self::assertSame('Content', $content['category']);
        self::assertSame('Block', $cardList['category']);
    }

    #[Test]
    public function without_default_category_a_twig_with_no_category_still_fails(): void
    {
        $root = $this->project();

        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/config', "{$root}/content");

        self::assertSame(1, $code);
        self::assertStringContainsString('(category)', $output);
    }

    #[Test]
    public function dry_run_writes_nothing_and_still_names_the_source(): void
    {
        $root = $this->project();

        [$output, $code] = $this->runBin('--dry-run', '--drupal-config=' . self::FIXTURES . '/config', "{$root}/card-list");

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('OK   card-list (fields from Drupal paragraph type card_list)', $output);
        self::assertFileDoesNotExist("{$root}/card-list/card-list.yaml");
    }

    #[Test]
    public function display_evidence_needs_the_config_export(): void
    {
        [$output, $code] = $this->runBin('--drupal-display=' . self::FIXTURES . '/ParagraphDisplay.php', $this->project() . '/card-list');

        self::assertSame(2, $code);
        self::assertStringContainsString('--drupal-display needs --drupal-config', $output);
    }

    #[Test]
    public function a_missing_config_directory_is_refused(): void
    {
        [$output, $code] = $this->runBin('--drupal-config=' . self::FIXTURES . '/nope', $this->project() . '/card-list');

        self::assertSame(2, $code);
        self::assertStringContainsString('not found', $output);
    }
}
