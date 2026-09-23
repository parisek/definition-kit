<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalBaseline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrupalBaselineTest extends TestCase
{
    private function projectBaseline(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/dk-drupal-baseline-' . uniqid('', true) . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    #[Test]
    public function the_shipped_baseline_has_every_section(): void
    {
        $baseline = DrupalBaseline::load();

        self::assertSame(['created' => true, 'status' => true], $baseline->section('form_display')['hidden']);
        self::assertSame('', $baseline->section('field_instance')['default_value_callback']);
        self::assertSame(['image'], $baseline->mediaBundles('image'));
        self::assertSame(['document'], $baseline->mediaBundles('file'));
        self::assertSame([], $baseline->widgetThirdPartySettings('media_library_widget'));
        self::assertFalse($baseline->section('field_config_cardinality')['cardinality_label_config']);
    }

    #[Test]
    public function a_project_baseline_merges_maps_and_replaces_lists(): void
    {
        $baseline = DrupalBaseline::load($this->projectBaseline(<<<'YAML'
            paragraphs_type:
              third_party_settings:
                paragraphs_library: {allow_library_conversion: true}
            media_bundles:
              image: [image, vector_image]
            widget_third_party_settings:
              media_library_widget:
                media_library_edit: {show_edit: '1', edit_form_mode: default}
            YAML));

        $type = $baseline->section('paragraphs_type');
        self::assertSame(['paragraphs_library' => ['allow_library_conversion' => true]], $type['third_party_settings']);
        self::assertNull($type['icon_uuid'], 'the shipped keys stay');
        self::assertSame(['image', 'vector_image'], $baseline->mediaBundles('image'));
        self::assertSame(['document'], $baseline->mediaBundles('file'));
        self::assertSame(
            ['media_library_edit' => ['show_edit' => '1', 'edit_form_mode' => 'default']],
            $baseline->widgetThirdPartySettings('media_library_widget'),
        );
    }

    #[Test]
    public function an_unknown_section_is_refused_by_name(): void
    {
        $path = $this->projectBaseline("paragraph_type:\n  status: true\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paragraph_type');

        DrupalBaseline::load($path);
    }

    #[Test]
    public function a_missing_project_baseline_is_an_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal baseline not found');

        DrupalBaseline::load('/nonexistent/baseline.yaml');
    }
}
