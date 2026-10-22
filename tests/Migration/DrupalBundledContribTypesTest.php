<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigPlanner;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigWriter;
use Parisek\DefinitionKit\Generator\Drupal\PlanEntry;
use Parisek\DefinitionKit\Lint\DrupalDriftLinter;
use Parisek\DefinitionKit\Lint\DrupalDriftResult;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * geofield, office_hours, color_field_type and video_embed_field are in the
 * bundled type map. The config below has the shape of real exports: a
 * geofield storage with no settings, an office_hours storage saved by an
 * older module version, a color field with `opacity: 0` and a video field
 * with `0` for each unchecked provider.
 */
final class DrupalBundledContribTypesTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/dk-bundled-' . uniqid('', true);
        mkdir($dir);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /**
     * @param array<string,mixed> $storageSettings
     * @param array<string,mixed> $instanceSettings
     */
    private function field(string $dir, string $bundle, string $name, string $type, string $module, array $storageSettings, array $instanceSettings, int $cardinality, bool $required = false): void
    {
        $put = static fn (string $file, array $data) => file_put_contents("{$dir}/{$file}", Yaml::dump($data, 6, 2));
        if (!is_file("{$dir}/paragraphs.paragraphs_type.{$bundle}.yml")) {
            $put("paragraphs.paragraphs_type.{$bundle}.yml", ['id' => $bundle, 'label' => ucfirst($bundle)]);
        }
        $put("field.storage.paragraph.{$name}.yml", [
            'langcode' => 'en', 'status' => true,
            'dependencies' => ['module' => [$module, 'paragraphs']],
            'id' => "paragraph.{$name}", 'field_name' => $name, 'entity_type' => 'paragraph',
            'type' => $type, 'settings' => $storageSettings, 'module' => $module, 'locked' => false,
            'cardinality' => $cardinality, 'translatable' => true, 'indexes' => [],
            'persist_with_no_fields' => false, 'custom_storage' => false,
        ]);
        $put("field.field.paragraph.{$bundle}.{$name}.yml", [
            'langcode' => 'en', 'status' => true,
            'dependencies' => ['config' => ["field.storage.paragraph.{$name}", "paragraphs.paragraphs_type.{$bundle}"], 'module' => [$module]],
            'id' => "paragraph.{$bundle}.{$name}", 'field_name' => $name, 'entity_type' => 'paragraph', 'bundle' => $bundle,
            'label' => ucfirst($bundle), 'description' => '', 'required' => $required, 'translatable' => false,
            'default_value' => [], 'default_value_callback' => '', 'settings' => $instanceSettings, 'field_type' => $type,
        ]);
    }

    private function export(): string
    {
        $dir = $this->tempDir();
        $this->field($dir, 'color', 'field_color', 'color_field_type', 'color_field', ['format' => '#HEXHEX'], ['opacity' => 0], 1, true);
        $this->field($dir, 'video', 'field_video', 'video_embed_field', 'video_embed_field', [], ['allowed_providers' => ['youtube' => '0', 'vimeo' => '0']], 1);
        $this->field($dir, 'video_picky', 'field_video', 'video_embed_field', 'video_embed_field', [], ['allowed_providers' => ['youtube' => 'youtube', 'vimeo' => '0']], 1);
        $this->field($dir, 'hours', 'field_hours', 'office_hours', 'office_hours', [
            'time_format' => 'G', 'increment' => 30, 'limit_start' => '', 'limit_end' => '', 'valhrs' => false, 'cardinality_per_day' => 2,
        ], [], -1);
        $this->field($dir, 'geo', 'field_geo', 'geofield', 'geofield', [], [], 1);

        return $dir;
    }

    /** @return array<string,array<string,mixed>> */
    private function read(string $dir, string $bundle): array
    {
        return (new DrupalParagraphReader(DrupalConfig::fromDirectory($dir), new DrupalSettings()))->read($bundle);
    }

    /** @param array<string,array<string,mixed>> $fields */
    private function lint(string $dir, string $bundle, array $fields): DrupalDriftResult
    {
        $definition = ['name' => 'Probe', 'category' => 'Block', 'kind' => 'block', 'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields", 'fields' => $fields];

        return (new DrupalDriftLinter(DrupalConfig::fromDirectory($dir), new DrupalSettings()))->lint($definition, $bundle);
    }

    #[Test]
    public function the_migration_picks_the_kit_type_and_pins_the_storage(): void
    {
        $dir = $this->export();

        self::assertSame(
            ['type' => 'text', 'role' => 'field', 'label' => 'Color', 'required' => true, 'drupal' => ['storage' => 'color_field_type', 'settings' => ['opacity' => false]]],
            $this->read($dir, 'color')['color'],
        );
        self::assertSame(
            ['type' => 'text', 'role' => 'field', 'label' => 'Video', 'drupal' => ['storage' => 'video_embed_field']],
            $this->read($dir, 'video')['video'],
        );
        self::assertSame(
            ['type' => 'list', 'role' => 'field', 'label' => 'Hours', 'open' => true, 'drupal' => ['storage' => 'office_hours']],
            $this->read($dir, 'hours')['hours'],
        );
        self::assertSame(
            ['type' => 'object', 'role' => 'field', 'label' => 'Geo', 'open' => true, 'drupal' => ['field' => 'field_geo', 'storage' => 'geofield']],
            $this->read($dir, 'geo')['geo'],
        );
    }

    #[Test]
    public function an_unchecked_provider_is_not_a_setting_and_a_checked_one_is(): void
    {
        $dir = $this->export();

        // `{youtube: '0', vimeo: '0'}` is "any provider": the default.
        self::assertArrayNotHasKey('settings', $this->read($dir, 'video')['video']['drupal']);
        self::assertSame(['allowed_providers' => ['youtube' => 'youtube']], $this->read($dir, 'video_picky')['video']['drupal']['settings']);
    }

    #[Test]
    public function lint_and_generate_agree_with_the_export_they_were_migrated_from(): void
    {
        $dir = $this->export();
        foreach (['color', 'video', 'video_picky', 'hours', 'geo'] as $bundle) {
            $fields = $this->read($dir, $bundle);
            $result = $this->lint($dir, $bundle, $fields);
            self::assertSame(DrupalDriftResult::OK, $result->status, "{$bundle}: " . implode("\n", $result->findings));

            $settings = new DrupalSettings();
            $definition = ['name' => 'Probe', 'category' => 'Block', 'kind' => 'block', 'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields", 'fields' => $fields];
            $specs = (new BundleSpecBuilder($settings))->build($definition, $bundle, DrupalConfig::fromDirectory($dir));
            $plan = (new DrupalConfigPlanner(new ConfigStore($dir), $settings))->plan($specs);
            $lines = implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries));
            self::assertFalse($plan->refused(), $lines);
            self::assertSame([], array_values(array_filter($plan->changedNames(), static fn (string $n): bool => str_starts_with($n, 'field.'))), "{$bundle}: {$lines}");
        }
    }

    #[Test]
    public function the_lint_compares_the_settings_that_matter(): void
    {
        $dir = $this->export();

        $color = $this->read($dir, 'color');
        $color['color']['drupal']['settings']['opacity'] = true;
        $this->assertDrift($this->lint($dir, 'color', $color), 'opacity');

        $color = $this->read($dir, 'color');
        unset($color['color']['drupal']['settings']);
        $this->assertDrift($this->lint($dir, 'color', $color), 'opacity');

        $video = $this->read($dir, 'video');
        $video['video']['drupal']['settings'] = ['allowed_providers' => ['vimeo' => 'vimeo']];
        $this->assertDrift($this->lint($dir, 'video', $video), 'allowed_providers');

        // The list form of a checkboxes setting reads the same as the map.
        $picky = $this->read($dir, 'video_picky');
        $picky['video']['drupal']['settings'] = ['allowed_providers' => ['youtube']];
        self::assertSame(DrupalDriftResult::OK, $this->lint($dir, 'video_picky', $picky)->status);

        $hours = $this->read($dir, 'hours');
        $hours['hours']['drupal']['settings'] = ['exceptions' => true];
        $this->assertDrift($this->lint($dir, 'hours', $hours), 'exceptions');

        $geo = $this->read($dir, 'geo');
        $geo['geo']['drupal']['settings'] = ['backend' => 'geofield_backend_postgis'];
        $this->assertDrift($this->lint($dir, 'geo', $geo), 'backend');
    }

    private function assertDrift(DrupalDriftResult $result, string $setting): void
    {
        self::assertSame(DrupalDriftResult::DRIFT, $result->status);
        self::assertStringContainsString($setting, implode("\n", $result->findings));
    }

    #[Test]
    public function a_new_field_gets_the_module_of_its_storage_type_and_the_module_defaults(): void
    {
        $source = $this->export();
        $target = $this->tempDir();
        $settings = new DrupalSettings();
        $specs = [];
        foreach (['color', 'video', 'hours', 'geo'] as $bundle) {
            $definition = ['name' => 'Probe', 'category' => 'Block', 'kind' => 'block', 'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields", 'fields' => $this->read($source, $bundle)];
            $specs = [...$specs, ...(new BundleSpecBuilder($settings))->build($definition, $bundle, DrupalConfig::fromDirectory($source))];
        }
        $plan = (new DrupalConfigPlanner(new ConfigStore($target), $settings))->plan($specs);
        self::assertFalse($plan->refused());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));

        $read = static fn (string $file): array => (array) Yaml::parseFile("{$target}/{$file}.yml");
        // The module is the one that provides the type, not the type id.
        $color = $read('field.storage.paragraph.field_color');
        self::assertSame(['color_field_type', 'color_field', ['format' => '#HEXHEX']], [$color['type'], $color['module'], $color['settings']]);
        self::assertContains('color_field', $color['dependencies']['module']);
        self::assertNotContains('color_field_type', $color['dependencies']['module']);
        self::assertSame(['opacity' => false], $read('field.field.paragraph.color.field_color')['settings']);
        self::assertSame('color_field_widget_default', $read('core.entity_form_display.paragraph.color.default')['content']['field_color']['type']);
        self::assertContains('color_field', $read('core.entity_form_display.paragraph.color.default')['dependencies']['module']);
        self::assertSame('color_field_formatter_text', $read('core.entity_view_display.paragraph.color.default')['content']['field_color']['type']);

        $hours = $read('field.storage.paragraph.field_hours');
        self::assertSame(-1, $hours['cardinality']);
        self::assertEqualsCanonicalizing(['exceptions' => false, 'seasons' => false, 'all_day' => false, 'comment' => 1, 'cardinality_per_day' => 2], array_intersect_key($hours['settings'], array_flip(['exceptions', 'seasons', 'all_day', 'comment', 'cardinality_per_day'])));
        self::assertSame('office_hours_exceptions', $read('core.entity_form_display.paragraph.hours.default')['content']['field_hours']['type']);

        self::assertSame(['backend' => 'geofield_backend_default'], $read('field.storage.paragraph.field_geo')['settings']);
        self::assertSame('geofield_latlon', $read('core.entity_form_display.paragraph.geo.default')['content']['field_geo']['type']);

        $video = $read('field.storage.paragraph.field_video');
        self::assertSame('video_embed_field', $video['module']);
        self::assertSame(['allowed_providers' => []], $read('field.field.paragraph.video.field_video')['settings']);
        self::assertSame(
            ['responsive' => true, 'width' => 854, 'height' => 480, 'autoplay' => true],
            $read('core.entity_view_display.paragraph.video.default')['content']['field_video']['settings'],
        );
    }
}
