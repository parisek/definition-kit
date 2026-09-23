<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldsGenerateDrupalCliTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal';
    private const BIN = __DIR__ . '/../../bin/fields-generate';

    /**
     * A project: the golden definitions under component/, the fixture
     * definition-kit.yaml, and a copy of the fixture export under config/.
     *
     * @return array{root: string, config: string}
     */
    private function project(bool $withConfig = true): array
    {
        $dir = sys_get_temp_dir() . '/fields-generate-drupal-' . uniqid('', true);
        mkdir("{$dir}/component", 0777, true);
        mkdir("{$dir}/config");
        copy(self::FIXTURES . '/definition-kit.yaml', "{$dir}/definition-kit.yaml");
        foreach (glob(self::FIXTURES . '/expected/*.yaml') ?: [] as $golden) {
            $name = basename($golden, '.yaml');
            mkdir("{$dir}/component/{$name}");
            copy($golden, "{$dir}/component/{$name}/{$name}.yaml");
        }
        if ($withConfig) {
            foreach (glob(self::FIXTURES . '/config/*.yml') ?: [] as $file) {
                copy($file, "{$dir}/config/" . basename($file));
            }
        }

        return ['root' => "{$dir}/component", 'config' => "{$dir}/config"];
    }

    /** @return array{string, int} */
    private function runBin(string ...$args): array
    {
        $cmd = 'php ' . escapeshellarg(self::BIN) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $lines, $code);

        return [implode("\n", $lines), $code];
    }

    /** @return array<string,string> file name => content */
    private static function snapshot(string $dir): array
    {
        $files = [];
        foreach (glob("{$dir}/*.yml") ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }

    #[Test]
    public function a_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $project = $this->project();
        $definition = "{$project['root']}/quote-image/quote-image.yaml";
        file_put_contents($definition, str_replace("label: Quote\n", "label: Citation\n", (string) file_get_contents($definition)));
        $before = self::snapshot($project['config']);

        [$output, $code] = $this->runBin('--target=drupal', "--drupal-config={$project['config']}", "--root={$project['root']}", '--dry-run');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('OK   quote-image (quote_image)', $output);
        self::assertStringContainsString('OK   content (content, html)', $output);
        self::assertStringContainsString('UPDATE field.field.paragraph.quote_image.field_quote (label)', $output);
        self::assertStringContainsString('REUSE  field.storage.paragraph.field_quote', $output);
        self::assertStringContainsString('0 create, 1 update,', $output);
        self::assertStringContainsString('dry run: nothing written', $output);
        self::assertSame($before, self::snapshot($project['config']));
    }

    #[Test]
    public function a_refused_plan_exits_1_and_writes_nothing(): void
    {
        $project = $this->project();
        $definition = "{$project['root']}/quote-image/quote-image.yaml";
        file_put_contents($definition, str_replace("type: richtext\n    label: Quote", "type: text\n    label: Quote", (string) file_get_contents($definition)));
        $before = self::snapshot($project['config']);

        [$output, $code] = $this->runBin('--target=drupal', "--drupal-config={$project['config']}", "--root={$project['root']}");

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('REFUSE field.storage.paragraph.field_quote: storage type text_long;', $output);
        self::assertStringContainsString('nothing written: the plan refuses 1 config entit(y/ies)', $output);
        self::assertSame($before, self::snapshot($project['config']));
    }

    #[Test]
    public function a_single_component_plans_only_its_bundles(): void
    {
        $project = $this->project();

        [$output, $code] = $this->runBin('--target=drupal', "--drupal-config={$project['config']}", "{$project['root']}/promo", '--dry-run');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('OK   promo (promo)', $output);
        self::assertStringNotContainsString('quote_image', $output);
    }

    #[Test]
    public function the_drupal_options_need_the_drupal_target(): void
    {
        $project = $this->project();

        [$output, $code] = $this->runBin("--drupal-config={$project['config']}", "--root={$project['root']}");
        self::assertSame(2, $code);
        self::assertStringContainsString('--drupal-config and --names-out need --target=drupal', $output);

        [$output, $code] = $this->runBin('--target=drupal', "--root={$project['root']}");
        self::assertSame(2, $code);
        self::assertStringContainsString('usage:', $output);

        [$output, $code] = $this->runBin('--target=joomla', "--root={$project['root']}");
        self::assertSame(2, $code);
        self::assertStringContainsString('unknown target: joomla', $output);
    }

    #[Test]
    public function a_missing_config_directory_is_a_usage_error(): void
    {
        $project = $this->project();

        [$output, $code] = $this->runBin('--target=drupal', '--drupal-config=/nonexistent/config', "--root={$project['root']}");

        self::assertSame(2, $code);
        self::assertStringContainsString('Drupal config directory not found', $output);
    }
}
