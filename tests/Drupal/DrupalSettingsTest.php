<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrupalSettingsTest extends TestCase
{
    private function projectWith(string $yaml): string
    {
        $dir = sys_get_temp_dir() . '/dk-drupal-settings-' . uniqid('', true);
        mkdir("{$dir}/component", 0777, true);
        file_put_contents("{$dir}/definition-kit.yaml", $yaml);

        return "{$dir}/component";
    }

    #[Test]
    public function it_is_discovered_one_level_above_the_components_root(): void
    {
        $settings = DrupalSettings::discoverFor(__DIR__ . '/../fixtures/drupal/component');

        self::assertSame(['html' => 'content'], $settings->bundleAliases);
        self::assertSame(['from_library'], $settings->bundlesWithoutComponent);
        self::assertTrue($settings->ignores('field_wrapper_id'));
        self::assertSame(DrupalSettings::NAMING_GENERIC, $settings->fieldNaming);
    }

    #[Test]
    public function no_file_means_the_defaults(): void
    {
        $settings = DrupalSettings::discoverFor(sys_get_temp_dir() . '/dk-none-' . uniqid('', true) . '/component');

        self::assertSame([], $settings->ignoreFields);
        self::assertNull($settings->path);
    }

    #[Test]
    public function a_nearer_file_without_a_drupal_section_does_not_mask_one_above(): void
    {
        $root = $this->projectWith("drupal:\n  ignore_fields: [field_spacing]\n");
        file_put_contents("{$root}/definition-kit.yaml", "key_style: snake\n");

        self::assertTrue(DrupalSettings::discoverFor($root)->ignores('field_spacing'));
    }

    #[Test]
    public function an_unknown_key_throws_and_names_the_file(): void
    {
        $root = $this->projectWith("drupal:\n  ignore_field: [field_spacing]\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ignore_field');

        DrupalSettings::discoverFor($root);
    }

    #[Test]
    public function an_unknown_field_naming_throws(): void
    {
        $root = $this->projectWith("drupal:\n  field_naming: bundle\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('generic|prefixed');

        DrupalSettings::discoverFor($root);
    }

    #[Test]
    public function generic_naming_shares_one_storage_per_leaf(): void
    {
        $settings = new DrupalSettings();

        self::assertSame('field_title', $settings->conventionalFieldName('title', 'card_list'));
        self::assertSame('title', $settings->leafName('field_title', 'card_list'));
    }

    #[Test]
    public function prefixed_naming_puts_the_bundle_in_the_field_name(): void
    {
        $settings = new DrupalSettings(fieldNaming: DrupalSettings::NAMING_PREFIXED);

        self::assertSame('field_card_list_title', $settings->conventionalFieldName('title', 'card_list'));
        self::assertSame('title', $settings->leafName('field_card_list_title', 'card_list'));
        // A shared field on a prefixed site still gets a readable leaf.
        self::assertSame('wrapper_id', $settings->leafName('field_wrapper_id', 'card_list'));
    }

    #[Test]
    public function the_generator_keys_parse_with_their_types(): void
    {
        $root = $this->projectWith(<<<'YAML'
            drupal:
              langcode: cs
              text_format: basic
              media_bundles:
                image: [image, vector_image]
              host_fields:
                - field.field.node.page.field_paragraphs
              translation: true
              view_display: hidden
              field_config_cardinality: true
              baseline: drupal-baseline.yaml
            YAML);
        $settings = DrupalSettings::discoverFor($root);

        self::assertSame('cs', $settings->langcode);
        self::assertSame('basic', $settings->textFormat);
        self::assertSame(['image' => ['image', 'vector_image']], $settings->mediaBundles);
        self::assertSame(['field.field.node.page.field_paragraphs'], $settings->hostFields);
        self::assertTrue($settings->translation);
        self::assertSame(DrupalSettings::VIEW_HIDDEN, $settings->viewDisplay);
        self::assertTrue($settings->fieldConfigCardinality);
        self::assertSame(\dirname($root) . '/drupal-baseline.yaml', $settings->baseline, 'relative to definition-kit.yaml');
    }

    #[Test]
    public function the_generator_keys_default_to_plain_drupal(): void
    {
        $settings = new DrupalSettings();

        self::assertSame('en', $settings->langcode);
        self::assertNull($settings->textFormat);
        self::assertFalse($settings->translation);
        self::assertSame(DrupalSettings::VIEW_CONTENT, $settings->viewDisplay);
        self::assertFalse($settings->fieldConfigCardinality);
        self::assertNull($settings->baseline);
    }

    #[Test]
    public function a_wrong_view_display_names_the_choices(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected content|hidden');

        DrupalSettings::fromArray(['view_display' => 'none']);
    }

    #[Test]
    public function a_host_field_must_be_a_field_config_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('field.field.<entity>.<bundle>.<field>');

        DrupalSettings::fromArray(['host_fields' => ['node.page.field_paragraphs']]);
    }

    #[Test]
    public function a_non_boolean_translation_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('drupal.translation must be true or false');

        DrupalSettings::fromArray(['translation' => 'yes']);
    }
}
