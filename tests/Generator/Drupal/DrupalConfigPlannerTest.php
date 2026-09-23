<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigPlanner;
use Parisek\DefinitionKit\Generator\Drupal\Plan;
use Parisek\DefinitionKit\Generator\Drupal\PlanEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The four plan classes of ADR 0002 against the fixture export: REUSE,
 * CREATE, UPDATE, REFUSE, and the line between narrowing an instance's
 * cardinality and changing its storage.
 */
final class DrupalConfigPlannerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/drupal';

    /**
     * @param array<string,array<string,mixed>> $definitions component slug => definition
     * @param array<string,mixed> $settings the drupal: section, over the fixture's
     */
    private function plan(array $definitions, array $settings = [], string $configDir = self::FIXTURES . '/config'): Plan
    {
        $base = Yaml::parseFile(self::FIXTURES . '/definition-kit.yaml');
        self::assertIsArray($base);
        $drupal = DrupalSettings::fromArray(array_replace((array) $base['drupal'], $settings));
        $store = new ConfigStore($configDir);
        $config = [] === $store->names('paragraphs.paragraphs_type.') ? null : DrupalConfig::fromDirectory($configDir);
        $builder = new BundleSpecBuilder($drupal);
        $specs = [];
        foreach ($definitions as $slug => $definition) {
            $specs = [...$specs, ...$builder->build($definition, $slug, $config)];
        }

        return (new DrupalConfigPlanner($store, $drupal))->plan($specs);
    }

    /** @return array<string,mixed> */
    private function golden(string $component): array
    {
        $tree = Yaml::parseFile(self::FIXTURES . "/expected/{$component}.yaml");
        self::assertIsArray($tree);

        return $tree;
    }

    private static function action(Plan $plan, string $name): string
    {
        $entry = $plan->entry($name);
        self::assertNotNull($entry, "no plan entry for {$name}");

        return $entry->action;
    }

    /** @return array<string,mixed> */
    private static function data(Plan $plan, string $name): array
    {
        $entry = $plan->entry($name);
        self::assertNotNull($entry, "no plan entry for {$name}");
        self::assertNotNull($entry->data, "{$name} is {$entry->action}, not written");

        return $entry->data;
    }

    private static function emptyDir(): string
    {
        $dir = sys_get_temp_dir() . '/dk-drupal-plan-' . uniqid('', true);
        mkdir($dir);

        return $dir;
    }

    // ------------------------------------------------------------------ REUSE

    #[Test]
    public function an_unchanged_definition_reuses_every_entity(): void
    {
        $plan = $this->plan(['quote-image' => $this->golden('quote-image'), 'card-list' => $this->golden('card-list')]);

        self::assertSame([], $plan->changedNames(), implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries)));
        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.storage.paragraph.field_media'));
        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.field.paragraph.card_list_item.field_title'));
        self::assertFalse($plan->refused());
    }

    // ----------------------------------------------------------------- CREATE

    #[Test]
    public function a_new_component_creates_its_bundle_and_reuses_shared_storage(): void
    {
        $plan = $this->plan(['faq' => [
            'name' => 'FAQ',
            'category' => 'Block',
            'drupal' => '/admin/structure/paragraphs_type/faq/fields',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Title', 'required' => true],
                'answer' => ['type' => 'richtext', 'label' => 'Answer'],
            ],
        ]]);

        self::assertSame(PlanEntry::CREATE, self::action($plan, 'paragraphs.paragraphs_type.faq'));
        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.storage.paragraph.field_title'));
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'field.storage.paragraph.field_answer'));
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'field.field.paragraph.faq.field_title'));
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'core.entity_form_display.paragraph.faq.default'));
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'core.entity_view_display.paragraph.faq.default'));

        $type = self::data($plan, 'paragraphs.paragraphs_type.faq');
        self::assertSame('FAQ', $type['label']);
        self::assertArrayNotHasKey('uuid', $type, 'a new file has no uuid');

        $storage = self::data($plan, 'field.storage.paragraph.field_answer');
        self::assertSame('text_long', $storage['type']);
        self::assertSame('text', $storage['module']);
        self::assertSame(['module' => ['paragraphs', 'text']], $storage['dependencies']);

        $instance = self::data($plan, 'field.field.paragraph.faq.field_title');
        self::assertTrue($instance['required']);
        self::assertSame('string', $instance['field_type']);
        self::assertSame(
            ['config' => ['field.storage.paragraph.field_title', 'paragraphs.paragraphs_type.faq']],
            $instance['dependencies'],
        );

        $form = self::data($plan, 'core.entity_form_display.paragraph.faq.default');
        self::assertSame(['field_answer', 'field_title'], array_keys($form['content']));
        self::assertSame('string_textfield', $form['content']['field_title']['type']);
        self::assertSame(0, $form['content']['field_title']['weight']);
        self::assertSame(['text'], $form['dependencies']['module']);
    }

    #[Test]
    public function a_new_bundle_in_an_empty_export_creates_everything(): void
    {
        $plan = $this->plan(['quote-image' => $this->golden('quote-image')], configDir: self::emptyDir());

        self::assertSame([], $plan->withAction(PlanEntry::REUSE));
        self::assertSame([], $plan->withAction(PlanEntry::UPDATE));
        $media = self::data($plan, 'field.field.paragraph.quote_image.field_media');
        self::assertSame(['image' => 'image'], $media['settings']['handler_settings']['target_bundles']);
        self::assertSame(-1, self::data($plan, 'field.storage.paragraph.field_media')['cardinality']);
        self::assertSame(
            [['value' => 'left', 'label' => 'Left'], ['value' => 'right', 'label' => 'Right']],
            self::data($plan, 'field.storage.paragraph.field_image_side')['settings']['allowed_values'],
        );
    }

    #[Test]
    public function a_new_field_on_an_existing_bundle_joins_the_form_after_the_last_weight(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['caption'] = ['type' => 'text', 'label' => 'Caption'];
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertSame(PlanEntry::CREATE, self::action($plan, 'field.storage.paragraph.field_caption'));
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'field.field.paragraph.quote_image.field_caption'));
        self::assertSame(PlanEntry::UPDATE, self::action($plan, 'core.entity_form_display.paragraph.quote_image.default'));
        $form = self::data($plan, 'core.entity_form_display.paragraph.quote_image.default');
        self::assertSame(7, $form['content']['field_caption']['weight'], 'max weight 6 + 1');
        self::assertSame(6, $form['content']['field_wrapper_id']['weight'], 'existing entries keep their weight');
        self::assertContains('field.field.paragraph.quote_image.field_caption', $form['dependencies']['config']);
        self::assertSame(['adds field_caption'], $plan->entry('core.entity_form_display.paragraph.quote_image.default')?->reasons);
    }

    #[Test]
    public function a_new_field_inside_an_existing_group_joins_that_group(): void
    {
        $definition = $this->golden('card-list');
        $definition['fields']['heading']['fields']['subtitle'] = ['type' => 'text', 'label' => 'Subtitle'];
        $plan = $this->plan(['card-list' => $definition]);

        $form = self::data($plan, 'core.entity_form_display.paragraph.card_list.default');
        self::assertSame(['field_title', 'field_subtitle'], $form['third_party_settings']['field_group']['group_heading']['children']);
    }

    // ----------------------------------------------------------------- UPDATE

    #[Test]
    public function an_owned_key_change_is_an_update_of_that_key_only(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['quote']['label'] = 'Citation';
        $definition['fields']['quote']['required'] = false;
        $definition['fields']['button']['shape'] = 'url';
        $plan = $this->plan(['quote-image' => $definition]);

        $entry = $plan->entry('field.field.paragraph.quote_image.field_quote');
        self::assertNotNull($entry);
        self::assertSame(PlanEntry::UPDATE, $entry->action);
        self::assertSame(['label', 'required'], $entry->reasons);
        self::assertSame('Citation', self::data($plan, 'field.field.paragraph.quote_image.field_quote')['label']);

        self::assertSame(['link title'], $plan->entry('field.field.paragraph.quote_image.field_link')?->reasons);
        self::assertSame(0, self::data($plan, 'field.field.paragraph.quote_image.field_link')['settings']['title']);
        self::assertSame(17, self::data($plan, 'field.field.paragraph.quote_image.field_link')['settings']['link_type'], 'not owned, kept');
        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.storage.paragraph.field_quote'));
    }

    #[Test]
    public function an_added_option_updates_the_storage(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['image_side']['options']['center'] = 'Center';
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertSame(['allowed_values'], $plan->entry('field.storage.paragraph.field_image_side')?->reasons);
        self::assertSame(
            ['left', 'right', 'center'],
            array_column(self::data($plan, 'field.storage.paragraph.field_image_side')['settings']['allowed_values'], 'value'),
        );
    }

    #[Test]
    public function new_reference_targets_update_the_instance_and_its_dependencies(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['signature']['drupal']['target_bundles'] = ['vector_image'];
        $plan = $this->plan(['quote-image' => $definition]);

        $data = self::data($plan, 'field.field.paragraph.quote_image.field_signature');
        self::assertSame(['vector_image' => 'vector_image'], $data['settings']['handler_settings']['target_bundles']);
        self::assertContains('media.type.vector_image', $data['dependencies']['config']);
        self::assertSame(['target_bundles'], $plan->entry('field.field.paragraph.quote_image.field_signature')?->reasons);
    }

    #[Test]
    public function an_alias_bundle_takes_structure_but_not_labels(): void
    {
        // `content` renders both the content and the html bundle; html's
        // field_content is labelled HTML, the definition says Text.
        $plan = $this->plan(['content' => $this->golden('content')]);

        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.field.paragraph.html.field_content'));

        $definition = $this->golden('content');
        $definition['fields']['html']['required'] = true;
        $plan = $this->plan(['content' => $definition]);
        self::assertSame(['required'], $plan->entry('field.field.paragraph.html.field_content')?->reasons);
    }

    #[Test]
    public function translatable_is_owned_only_when_the_definition_sets_it(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['quote']['translatable'] = false;
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertSame(['translatable'], $plan->entry('field.field.paragraph.quote_image.field_quote')?->reasons);
        self::assertFalse(self::data($plan, 'field.field.paragraph.quote_image.field_quote')['translatable']);
    }

    // ------------------------------------------------------ narrowing vs storage

    #[Test]
    public function narrowing_an_unlimited_storage_is_an_instance_update_with_field_config_cardinality(): void
    {
        // field_media storage is unlimited; quote_image's instance has no
        // override, so it is unlimited too. The definition now wants one.
        $definition = $this->golden('quote-image');
        $definition['fields']['image']['kind'] = 'image';
        unset($definition['fields']['image']['multiple']);
        $plan = $this->plan(['quote-image' => $definition], ['field_config_cardinality' => true]);

        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.storage.paragraph.field_media'), 'the storage stays unlimited');
        $entry = $plan->entry('field.field.paragraph.quote_image.field_media');
        self::assertNotNull($entry);
        self::assertSame(['cardinality unlimited -> 1'], $entry->reasons);
        $data = self::data($plan, 'field.field.paragraph.quote_image.field_media');
        self::assertSame('1', $data['third_party_settings']['field_config_cardinality']['cardinality_config']);
        self::assertSame(['field_config_cardinality'], $data['dependencies']['module']);
        self::assertSame(['dependencies', 'third_party_settings', 'id'], array_slice(array_keys($data), 2, 3), 'third_party_settings sits where Drupal puts it');
    }

    #[Test]
    public function narrowing_without_field_config_cardinality_is_refused(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['image']['kind'] = 'image';
        unset($definition['fields']['image']['multiple']);
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertSame(PlanEntry::REFUSE, self::action($plan, 'field.field.paragraph.quote_image.field_media'));
        self::assertStringContainsString('turn on drupal.field_config_cardinality', $plan->entry('field.field.paragraph.quote_image.field_media')?->reasons[0] ?? '');
        self::assertSame(PlanEntry::REUSE, self::action($plan, 'field.storage.paragraph.field_media'));
    }

    #[Test]
    public function widening_an_existing_override_within_the_storage_is_an_update(): void
    {
        // stats.field_paragraphs narrows the unlimited storage to 5.
        $definition = $this->golden('stats');
        $definition['fields']['items']['max'] = 8;
        $plan = $this->plan(['stats' => $definition], ['field_config_cardinality' => true]);

        self::assertSame(['cardinality 5 -> 8'], $plan->entry('field.field.paragraph.stats.field_paragraphs')?->reasons);
        self::assertSame('8', self::data($plan, 'field.field.paragraph.stats.field_paragraphs')['third_party_settings']['field_config_cardinality']['cardinality_config']);
    }

    #[Test]
    public function more_values_than_the_storage_holds_is_a_refused_storage_change(): void
    {
        // field_title storage holds one value; no instance setting can raise that.
        $definition = $this->golden('card-list');
        $definition['fields']['heading']['fields']['title']['multiple'] = true;
        $plan = $this->plan(['card-list' => $definition], ['field_config_cardinality' => true]);

        self::assertSame(PlanEntry::REFUSE, self::action($plan, 'field.storage.paragraph.field_title'));
        $reason = $plan->entry('field.storage.paragraph.field_title')?->reasons[0] ?? '';
        self::assertStringContainsString('cardinality 1; `heading.title` on card_list needs unlimited', $reason);
        self::assertStringContainsString('needs a data migration', $reason);
    }

    // ----------------------------------------------------------------- REFUSE

    #[Test]
    public function a_storage_type_change_is_refused(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['quote'] = ['type' => 'text', 'label' => 'Quote', 'required' => true];
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertTrue($plan->refused());
        self::assertStringContainsString(
            'storage type text_long; `quote` on quote_image needs string|string_long|email|telephone',
            $plan->entry('field.storage.paragraph.field_quote')?->reasons[0] ?? '',
        );
        self::assertNull($plan->entry('field.field.paragraph.quote_image.field_quote'), 'no instance plan behind a refused storage');
    }

    #[Test]
    public function a_dropped_option_is_refused(): void
    {
        $definition = $this->golden('quote-image');
        unset($definition['fields']['image_side']['options']['right']);
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertSame(PlanEntry::REFUSE, self::action($plan, 'field.storage.paragraph.field_image_side'));
        self::assertStringContainsString('drops allowed value(s) right', $plan->entry('field.storage.paragraph.field_image_side')?->reasons[0] ?? '');
    }

    #[Test]
    public function a_different_target_entity_type_is_refused(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['signature'] = ['type' => 'reference', 'label' => 'Signature', 'of' => 'term:tags'];
        $plan = $this->plan(['quote-image' => $definition]);

        self::assertStringContainsString('references media; `signature` on quote_image needs taxonomy_term', $plan->entry('field.storage.paragraph.field_signature')?->reasons[0] ?? '');
    }

    #[Test]
    public function new_shared_storage_with_different_cardinalities_needs_field_config_cardinality(): void
    {
        $definitions = [
            'one' => ['name' => 'One', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/one/fields', 'fields' => ['tags' => ['type' => 'text', 'label' => 'Tags']]],
            'two' => ['name' => 'Two', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/two/fields', 'fields' => ['tags' => ['type' => 'text', 'label' => 'Tags', 'multiple' => true, 'max' => 3]]],
        ];

        $refused = $this->plan($definitions, configDir: self::emptyDir());
        self::assertStringContainsString('different cardinalities', $refused->entry('field.storage.paragraph.field_tags')?->reasons[0] ?? '');

        $narrowed = $this->plan($definitions, ['field_config_cardinality' => true], self::emptyDir());
        self::assertSame(3, self::data($narrowed, 'field.storage.paragraph.field_tags')['cardinality']);
        self::assertSame('1', self::data($narrowed, 'field.field.paragraph.one.field_tags')['third_party_settings']['field_config_cardinality']['cardinality_config']);
        self::assertArrayNotHasKey('third_party_settings', self::data($narrowed, 'field.field.paragraph.two.field_tags'));
    }

    #[Test]
    public function one_bundle_described_twice_differently_is_refused(): void
    {
        $plan = $this->plan([
            'a' => ['name' => 'A', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/shared/fields', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]],
            'b' => ['name' => 'B', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/shared/fields', 'fields' => ['title' => ['type' => 'text', 'label' => 'Name']]],
        ], configDir: self::emptyDir());

        self::assertStringContainsString('a and b describe this paragraph type with different fields', $plan->entry('paragraphs.paragraphs_type.shared')?->reasons[0] ?? '');
    }

    // ------------------------------------------------------------ host fields

    #[Test]
    public function a_new_top_level_bundle_joins_the_host_fields_and_a_nested_one_does_not(): void
    {
        $config = self::emptyDir();
        foreach (glob(self::FIXTURES . '/config/*.yml') ?: [] as $file) {
            copy($file, $config . '/' . basename($file));
        }
        file_put_contents("{$config}/field.field.node.page.field_paragraphs.yml", <<<'YAML'
            langcode: en
            status: true
            dependencies:
              config:
                - field.storage.node.field_paragraphs
                - node.type.page
                - paragraphs.paragraphs_type.stats
            id: node.page.field_paragraphs
            field_name: field_paragraphs
            entity_type: node
            bundle: page
            label: Blocks
            settings:
              handler: 'default:paragraph'
              handler_settings:
                target_bundles:
                  stats: stats
                negate: 0
                target_bundles_drag_drop:
                  stats:
                    weight: 3
                    enabled: true
            field_type: entity_reference_revisions
            YAML);

        $plan = $this->plan(['faq' => [
            'name' => 'FAQ',
            'category' => 'Block',
            'drupal' => '/admin/structure/paragraphs_type/faq/fields',
            'fields' => ['items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['question' => ['type' => 'text', 'label' => 'Question']]]],
        ]], ['host_fields' => ['field.field.node.page.field_paragraphs']], $config);

        self::assertSame(['adds target faq'], $plan->entry('field.field.node.page.field_paragraphs')?->reasons);
        $host = self::data($plan, 'field.field.node.page.field_paragraphs');
        self::assertSame(['stats' => 'stats', 'faq' => 'faq'], $host['settings']['handler_settings']['target_bundles']);
        self::assertSame(['weight' => 4, 'enabled' => true], $host['settings']['handler_settings']['target_bundles_drag_drop']['faq']);
        self::assertContains('paragraphs.paragraphs_type.faq', $host['dependencies']['config']);
        self::assertNotContains('paragraphs.paragraphs_type.faq_item', $host['dependencies']['config']);
        self::assertSame(PlanEntry::CREATE, self::action($plan, 'paragraphs.paragraphs_type.faq_item'));
    }

    #[Test]
    public function a_host_field_missing_from_the_export_is_refused(): void
    {
        $plan = $this->plan(['faq' => [
            'name' => 'FAQ',
            'category' => 'Block',
            'drupal' => '/admin/structure/paragraphs_type/faq/fields',
            'fields' => ['title' => ['type' => 'text', 'label' => 'Title']],
        ]], ['host_fields' => ['field.field.node.page.field_paragraphs']]);

        self::assertSame(PlanEntry::REFUSE, self::action($plan, 'field.field.node.page.field_paragraphs'));
    }

    #[Test]
    public function an_existing_bundle_never_touches_the_host_fields(): void
    {
        $plan = $this->plan(['quote-image' => $this->golden('quote-image')], ['host_fields' => ['field.field.node.page.field_paragraphs']]);

        self::assertNull($plan->entry('field.field.node.page.field_paragraphs'));
    }
}
