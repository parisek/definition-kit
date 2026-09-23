<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalYaml;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigPlanner;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigWriter;
use Parisek\DefinitionKit\Generator\Drupal\Plan;
use Parisek\DefinitionKit\Generator\Drupal\PlanEntry;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Merge, not overwrite (ADR 0002), against an export shaped like the first
 * Drupal consumer: uuids, a `_core` hash, a Czech site, content translation,
 * field_config_cardinality, view displays that hide every field behind one
 * extra field, contrib third-party settings on bundles and widgets.
 */
final class DrupalConfigMergeTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/drupal-merge';

    private string $config;

    protected function setUp(): void
    {
        $this->config = sys_get_temp_dir() . '/dk-drupal-merge-' . uniqid('', true);
        mkdir($this->config);
        foreach (glob(self::FIXTURES . '/config/*.yml') ?: [] as $file) {
            copy($file, $this->config . '/' . basename($file));
        }
    }

    private function settings(): DrupalSettings
    {
        return DrupalSettings::discoverFor(self::FIXTURES . '/component');
    }

    /** @return array<string,mixed> the definition the migration writes for a bundle */
    private function migrated(string $bundle, string $name): array
    {
        $fields = (new DrupalParagraphReader(DrupalConfig::fromDirectory($this->config), $this->settings()))->read($bundle);

        return ['name' => $name, 'category' => 'Block', 'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields", 'fields' => $fields];
    }

    /** @param array<string,array<string,mixed>> $definitions */
    private function plan(array $definitions): Plan
    {
        $store = new ConfigStore($this->config);
        $builder = new BundleSpecBuilder($this->settings());
        $config = DrupalConfig::fromDirectory($this->config);
        $specs = [];
        foreach ($definitions as $slug => $definition) {
            $specs = [...$specs, ...$builder->build($definition, $slug, $config)];
        }

        return (new DrupalConfigPlanner($store, $this->settings()))->plan($specs);
    }

    /** @param array<string,array<string,mixed>> $definitions */
    private function generate(array $definitions): Plan
    {
        $plan = $this->plan($definitions);
        self::assertFalse($plan->refused(), implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->withAction(PlanEntry::REFUSE))));
        (new DrupalConfigWriter())->write($plan, new ConfigStore($this->config));

        return $plan;
    }

    private function raw(string $name): string
    {
        return (string) file_get_contents("{$this->config}/{$name}.yml");
    }

    /** @return array<string,mixed> */
    private function read(string $name): array
    {
        $data = Yaml::parseFile("{$this->config}/{$name}.yml");
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $files = [];
        foreach (glob("{$this->config}/*.yml") ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }

    #[Test]
    public function the_migrated_definitions_plan_no_change(): void
    {
        $before = $this->snapshot();
        $plan = $this->generate(['quote-image' => $this->migrated('quote_image', 'Quote with Image'), 'numbers' => $this->migrated('numbers', 'Numbers')]);

        self::assertSame([], $plan->changedNames(), implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries)));
        self::assertSame($before, $this->snapshot(), 'nothing is rewritten');
    }

    #[Test]
    public function an_update_changes_only_the_owned_key_and_keeps_the_file_byte_for_byte_otherwise(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['quote']['label'] = 'Citation';
        $before = $this->raw('field.field.paragraph.quote_image.field_quote');

        $plan = $this->generate(['quote-image' => $definition]);

        self::assertSame(['field.field.paragraph.quote_image.field_quote'], $plan->changedNames());
        self::assertSame(
            str_replace("label: Quote\n", "label: Citation\n", $before),
            $this->raw('field.field.paragraph.quote_image.field_quote'),
        );
        // uuid, the field_config_cardinality block and allowed_formats stay.
        $after = $this->read('field.field.paragraph.quote_image.field_quote');
        self::assertSame(['basic'], $after['settings']['allowed_formats']);
        self::assertSame('1', $after['third_party_settings']['field_config_cardinality']['cardinality_config']);
    }

    #[Test]
    public function a_storage_keeps_its_core_hash_and_uuid(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['quote']['required'] = false;
        $this->generate(['quote-image' => $definition]);

        $storage = $this->read('field.storage.paragraph.field_quote');
        self::assertArrayHasKey('_core', $storage);
        self::assertStringStartsWith('00000000-0000-4000-8000-', (string) $storage['uuid']);
        self::assertFalse($this->read('field.field.paragraph.quote_image.field_quote')['required']);
    }

    #[Test]
    public function the_form_display_keeps_weights_widget_settings_and_unknown_third_party_settings(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['caption'] = ['type' => 'richtext', 'label' => 'Caption'];
        $before = $this->read('core.entity_form_display.paragraph.quote_image.default');

        $this->generate(['quote-image' => $definition]);
        $after = $this->read('core.entity_form_display.paragraph.quote_image.default');

        foreach ($before['content'] as $name => $entry) {
            self::assertSame($entry, $after['content'][$name], "{$name} is untouched");
        }
        self::assertSame($before['third_party_settings'], $after['third_party_settings'], 'group_advanced stays');
        self::assertSame($before['uuid'], $after['uuid']);
        self::assertSame(14, $after['content']['field_caption']['weight'], 'after the last weight (13)');
        self::assertSame('text_textarea', $after['content']['field_caption']['type']);
        self::assertSame(['allowed_formats' => ['hide_help' => '1', 'hide_guidelines' => '1']], $after['content']['field_caption']['third_party_settings'], 'from the project baseline');
        self::assertContains('field.field.paragraph.quote_image.field_caption', $after['dependencies']['config']);
    }

    #[Test]
    public function a_new_field_on_a_hidden_view_display_goes_to_hidden(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['caption'] = ['type' => 'richtext', 'label' => 'Caption'];
        $before = $this->read('core.entity_view_display.paragraph.quote_image.default');

        $this->generate(['quote-image' => $definition]);
        $after = $this->read('core.entity_view_display.paragraph.quote_image.default');

        self::assertSame($before['content'], $after['content'], 'only the extra field renders');
        self::assertTrue($after['hidden']['field_caption']);
        self::assertContains('field.field.paragraph.quote_image.field_caption', $after['dependencies']['config']);
    }

    #[Test]
    public function new_files_follow_the_project_settings_and_carry_no_uuid(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['caption'] = ['type' => 'richtext', 'label' => 'Caption'];
        $this->generate(['quote-image' => $definition]);

        $storage = $this->read('field.storage.paragraph.field_caption');
        $instance = $this->read('field.field.paragraph.quote_image.field_caption');
        self::assertArrayNotHasKey('uuid', $storage);
        self::assertArrayNotHasKey('uuid', $instance);
        self::assertSame('cs', $storage['langcode']);
        self::assertSame(['basic'], $instance['settings']['allowed_formats']);
        self::assertContains('filter.format.basic', $instance['dependencies']['config']);
        self::assertSame(DrupalYaml::dump($instance), $this->raw('field.field.paragraph.quote_image.field_caption'), 'written the way drush config:export writes');
    }

    #[Test]
    public function a_new_bundle_gets_translation_contrib_settings_and_a_host(): void
    {
        $this->generate(['faq' => [
            'name' => 'FAQ',
            'category' => 'Block',
            'drupal' => '/admin/structure/paragraphs_type/faq/fields',
            'fields' => [
                'heading' => ['type' => 'object', 'label' => 'Heading', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]],
                'image' => ['type' => 'media', 'label' => 'Image', 'kind' => 'image', 'drupal' => ['field' => 'field_media']],
            ],
        ]]);

        $type = $this->read('paragraphs.paragraphs_type.faq');
        self::assertSame(['paragraphs_ee', 'paragraphs_library'], $type['dependencies']['module']);
        self::assertTrue($type['third_party_settings']['paragraphs_library']['allow_library_conversion']);

        $language = $this->read('language.content_settings.paragraph.faq');
        self::assertTrue($language['third_party_settings']['content_translation']['enabled']);
        self::assertSame(['content_translation'], $language['dependencies']['module']);

        $form = $this->read('core.entity_form_display.paragraph.faq.default');
        self::assertArrayHasKey('translation', $form['content']);
        self::assertSame(['field_title'], $form['third_party_settings']['field_group']['group_heading']['children']);
        self::assertSame(['media_library_edit' => ['show_edit' => '1', 'edit_form_mode' => 'default']], $form['content']['field_media']['third_party_settings']);
        self::assertSame(['field_group', 'media_library', 'media_library_edit'], $form['dependencies']['module']);

        $view = $this->read('core.entity_view_display.paragraph.faq.default');
        self::assertSame(['extra_field_default_paragraph_display'], array_keys($view['content']));
        self::assertSame(['field_media' => true, 'field_title' => true], $view['hidden']);

        // field_media storage is unlimited; the new instance narrows it.
        $media = $this->read('field.field.paragraph.faq.field_media');
        self::assertSame('1', $media['third_party_settings']['field_config_cardinality']['cardinality_config']);

        $host = $this->read('field.field.node.page.field_paragraphs');
        self::assertSame('faq', $host['settings']['handler_settings']['target_bundles']['faq']);
        self::assertSame(['weight' => -38, 'enabled' => true], $host['settings']['handler_settings']['target_bundles_drag_drop']['faq']);
        self::assertSame('00000000-0000-4000-8000-000000000900', $host['uuid']);
    }

    #[Test]
    public function a_dropped_field_is_never_deleted(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        unset($definition['fields']['signature']);
        $before = $this->snapshot();

        $plan = $this->generate(['quote-image' => $definition]);

        self::assertSame([], $plan->changedNames());
        self::assertNull($plan->entry('field.field.paragraph.quote_image.field_signature'));
        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function a_second_run_after_a_write_changes_nothing(): void
    {
        $definition = $this->migrated('numbers', 'Numbers');
        $definition['fields']['paragraphs']['fields']['caption'] = ['type' => 'text', 'label' => 'Caption', 'required' => true];
        $first = $this->generate(['numbers' => $definition]);
        self::assertNotSame([], $first->changedNames());

        $second = $this->plan(['numbers' => $definition]);

        self::assertSame([], $second->changedNames(), implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $second->entries)));
    }

    #[Test]
    public function the_names_file_lists_what_the_deploy_step_must_import(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['quote']['label'] = 'Citation';
        $definition['fields']['caption'] = ['type' => 'text', 'label' => 'Caption'];
        $plan = $this->plan(['quote-image' => $definition]);
        $names = sys_get_temp_dir() . '/dk-names-' . uniqid('', true) . '.txt';

        (new DrupalConfigWriter())->writeNames($plan, $names);

        self::assertSame(
            "field.storage.paragraph.field_caption\n"
            . "field.field.paragraph.quote_image.field_quote\n"
            . "field.field.paragraph.quote_image.field_caption\n"
            . "core.entity_form_display.paragraph.quote_image.default\n"
            . "core.entity_view_display.paragraph.quote_image.default\n",
            (string) file_get_contents($names),
        );
    }

    #[Test]
    public function a_refused_plan_is_never_written(): void
    {
        $definition = $this->migrated('quote_image', 'Quote with Image');
        $definition['fields']['quote']['type'] = 'number';
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertTrue($plan->refused());
        $this->expectException(\LogicException::class);
        (new DrupalConfigWriter())->write($plan, new ConfigStore($this->config));
    }
}
