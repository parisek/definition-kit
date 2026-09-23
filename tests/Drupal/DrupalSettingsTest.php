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
}
