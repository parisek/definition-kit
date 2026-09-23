<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalTypeMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrupalTypeMapTest extends TestCase
{
    /**
     * The table as it stood in PHP before it moved to
     * schemas/drupal-type-map.yaml. The lint and the migration depend on it.
     */
    private const V016_ACCEPTED = [
        'text' => ['string', 'string_long', 'email', 'telephone'],
        'text_multiline' => ['string_long', 'text_long', 'text'],
        'richtext' => ['text_long', 'text', 'text_with_summary'],
        'number' => ['integer', 'decimal', 'float'],
        'boolean' => ['boolean'],
        'select' => ['list_string', 'list_integer', 'list_float'],
        'media' => ['entity_reference', 'image', 'file'],
        'link' => ['link'],
        'reference' => ['entity_reference'],
        'date' => ['datetime', 'daterange', 'timestamp'],
        'object' => ['entity_reference_revisions'],
        'list' => ['entity_reference_revisions'],
        'flexible_content' => ['entity_reference_revisions'],
    ];

    #[Test]
    public function the_yaml_map_accepts_what_the_v016_table_accepted(): void
    {
        $map = new DrupalTypeMap();

        self::assertSame(array_keys(self::V016_ACCEPTED), $map->kitTypeKeys());
        foreach (self::V016_ACCEPTED as $key => $types) {
            self::assertSame($types, $map->acceptedFor($key), $key);
        }
        self::assertSame('string_long', $map->canonical(['type' => 'text', 'multiline' => true]));
        self::assertSame('list_string', $map->canonical(['type' => 'select']));
        self::assertSame('entity_reference_revisions', $map->canonical(['type' => 'repeater']));
        self::assertSame(['email'], $map->accepted(['type' => 'text', 'drupal' => ['storage' => 'email']]));
        self::assertNull($map->canonical(['type' => 'unknown']));
    }

    #[Test]
    public function every_accepted_storage_type_can_be_created(): void
    {
        $map = new DrupalTypeMap();
        foreach ($map->kitTypeKeys() as $key) {
            foreach ($map->acceptedFor($key) as $storage) {
                $entry = $map->storageType($storage);
                self::assertNotSame('', $entry['widget']['type'], $storage);
                self::assertNotSame('', $entry['formatter']['type'], $storage);
            }
        }
    }

    #[Test]
    public function every_default_widget_and_formatter_names_its_module(): void
    {
        $map = new DrupalTypeMap();
        $source = \Symfony\Component\Yaml\Yaml::parseFile(DrupalTypeMap::DEFAULT_PATH);
        self::assertIsArray($source);
        foreach ($map->storageTypes() as $storage) {
            $widget = $map->widget($storage)['type'];
            $formatter = $map->formatter($storage)['type'];
            self::assertArrayHasKey($widget, $source['widget_modules'], "widget {$widget} of {$storage}");
            self::assertArrayHasKey($formatter, $source['formatter_modules'], "formatter {$formatter} of {$storage}");
        }
    }

    #[Test]
    public function a_media_reference_uses_the_media_library_widget(): void
    {
        $map = new DrupalTypeMap();

        self::assertSame('media_library_widget', $map->widget('entity_reference', 'media')['type']);
        self::assertSame('media_library', $map->widgetModule('media_library_widget'));
        self::assertSame('entity_reference_autocomplete', $map->widget('entity_reference', 'node')['type']);
        self::assertNull($map->widgetModule('entity_reference_autocomplete'), 'a core widget adds no module dependency');
        self::assertSame('entity_reference_entity_view', $map->formatter('entity_reference', 'media')['type']);
    }

    #[Test]
    public function modules_and_bundle_config_follow_the_target_type(): void
    {
        $map = new DrupalTypeMap();

        self::assertSame('text', $map->storageModule('text_long'));
        self::assertNull($map->storageModule('string'));
        self::assertSame('entity_reference_revisions', $map->storageModule('entity_reference_revisions'));
        self::assertSame('media', $map->targetTypeModule('media'));
        self::assertSame('paragraphs', $map->targetTypeModule('paragraph'));
        self::assertSame('media.type', $map->bundleConfigPrefix('media'));
        self::assertSame('paragraphs.paragraphs_type', $map->bundleConfigPrefix('paragraph'));
        self::assertNull($map->bundleConfigPrefix('paragraphs_library_item'));
    }

    /**
     * `of: entity:<target_type>[:<bundle>,…]` (ADR 0009): a Drupal entity
     * reference outside the post/taxonomy/media vocabulary, e.g. arkero's
     * `field_webform` (target type `webform`, no bundle restriction).
     */
    #[Test]
    public function of_entity_names_the_target_type_with_no_bundle_restriction(): void
    {
        $map = new DrupalTypeMap();
        $field = ['type' => 'reference', 'of' => 'entity:webform'];

        self::assertSame('webform', $map->expectedTargetType($field, 'entity_reference'));
        // [] and not null: the definition DOES say — "any bundle" — so the
        // lint compares it against the real export instead of skipping it.
        self::assertSame([], $map->expectedTargetBundles($field));
    }

    #[Test]
    public function of_entity_with_bundles_restricts_them(): void
    {
        $map = new DrupalTypeMap();
        $field = ['type' => 'reference', 'of' => 'entity:node:article,page'];

        self::assertSame('node', $map->expectedTargetType($field, 'entity_reference'));
        self::assertSame(['article', 'page'], $map->expectedTargetBundles($field));
    }

    #[Test]
    public function drupal_target_type_still_overrides_of(): void
    {
        $map = new DrupalTypeMap();
        $field = ['type' => 'reference', 'of' => 'entity:webform', 'drupal' => ['target_type' => 'block_content']];

        self::assertSame('block_content', $map->expectedTargetType($field, 'entity_reference'));
    }

    #[Test]
    public function an_unknown_storage_type_is_refused_by_name(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("no storage type 'geofield'");

        (new DrupalTypeMap())->storageType('geofield');
    }
}
