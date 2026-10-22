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
     * schemas/drupal-type-map.yaml, plus the contrib types appended after the
     * core ones (the first entry of each list stays the canonical type). The
     * lint and the migration depend on it.
     */
    private const V016_ACCEPTED = [
        'text' => ['string', 'string_long', 'email', 'telephone', 'language_field'],
        'text_multiline' => ['string_long', 'text_long', 'text'],
        'richtext' => ['text_long', 'text', 'text_with_summary'],
        'number' => ['integer', 'decimal', 'float'],
        'boolean' => ['boolean'],
        'select' => ['list_string', 'list_integer', 'list_float'],
        'media' => ['entity_reference', 'image', 'file'],
        'link' => ['link'],
        'reference' => ['entity_reference', 'webform'],
        'date' => ['datetime', 'daterange', 'timestamp'],
        'object' => ['entity_reference_revisions', 'double_field', 'address'],
        'list' => ['entity_reference_revisions', 'double_field', 'address'],
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

    // ----------------------------------------------------------- the overlay

    private const OVERLAY = __DIR__ . '/../fixtures/drupal-contrib/overlay/type-map.yaml';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function overlayFile(string $yaml): string
    {
        $file = sys_get_temp_dir() . '/dk-type-map-' . uniqid('', true) . '.yaml';
        file_put_contents($file, $yaml);
        $this->files[] = $file;

        return $file;
    }

    #[Test]
    public function the_bundled_contrib_types_are_mapped(): void
    {
        $map = new DrupalTypeMap();

        foreach (['double_field', 'address', 'webform', 'language_field'] as $storage) {
            self::assertTrue($map->hasStorageType($storage), $storage);
            self::assertNotSame([], $map->kitKeysFor($storage), $storage);
        }
        self::assertSame(['text'], $map->kitKeysFor('language_field'));
        self::assertSame(['reference'], $map->kitKeysFor('webform'));
        self::assertSame(['object', 'list'], $map->kitKeysFor('double_field'));
        self::assertSame(['object', 'list'], $map->kitKeysFor('address'));
        self::assertSame(['first', 'second'], $map->parts('double_field'));
        self::assertSame([], $map->parts('address'));
        self::assertTrue($map->isReference('webform'));
        self::assertFalse($map->isReference('image'));
        self::assertSame('webform', $map->expectedTargetType(['type' => 'reference', 'of' => 'entity:webform'], 'webform'));
        self::assertSame('string', $map->partStorageType('double_field', 'text'));
        self::assertSame('float', $map->partStorageType('double_field', 'number'));
        self::assertSame('datetime_iso8601', $map->partStorageType('double_field', 'date'));
        self::assertNull($map->partStorageType('double_field', 'link'));
        self::assertSame(['number', 'select'], $map->partKitTypes('double_field', 'integer'));
        self::assertSame('languagefield', $map->storageModule('language_field'));
        self::assertSame('double_field', $map->widgetModule('double_field'));
        self::assertSame('webform', $map->targetTypeModule('webform'));
    }

    #[Test]
    public function a_pinned_list_or_object_of_a_contrib_type_nests_no_paragraphs(): void
    {
        $map = new DrupalTypeMap();

        self::assertTrue($map->nestsParagraphs(['type' => 'list']));
        self::assertTrue($map->nestsParagraphs(['type' => 'repeater']));
        self::assertTrue($map->nestsParagraphs(['type' => 'flexible_content']));
        self::assertFalse($map->nestsParagraphs(['type' => 'list', 'drupal' => ['storage' => 'double_field']]));
        self::assertFalse($map->nestsParagraphs(['type' => 'object', 'drupal' => ['storage' => 'address']]));
        self::assertFalse($map->nestsParagraphs(['type' => 'text']));
    }

    #[Test]
    public function an_overlay_adds_a_storage_type_and_appends_to_a_kit_type(): void
    {
        $map = new DrupalTypeMap(null, self::OVERLAY);

        self::assertSame(['string', 'string_long', 'email', 'telephone', 'language_field', 'color_field_type'], $map->acceptedFor('text'));
        self::assertSame('string', $map->canonical(['type' => 'text']), 'the bundled canonical type stays first');
        self::assertSame(['text'], $map->kitKeysFor('color_field_type'));
        self::assertSame('color_field', $map->storageModule('color_field_type'));
        self::assertSame('color_field_widget_default', $map->widget('color_field_type')['type']);
        self::assertSame('color_field', $map->widgetModule('color_field_widget_default'));
        self::assertSame('color_field', $map->formatterModule('color_field_formatter_text'));
        // The rest of the bundled map is intact.
        self::assertSame($this->bundled()->acceptedFor('number'), $map->acceptedFor('number'));
        self::assertSame($this->bundled()->storageType('string'), $map->storageType('string'));
    }

    #[Test]
    public function an_overlay_entry_replaces_the_bundled_entry_of_the_same_name(): void
    {
        $file = $this->overlayFile(<<<'YAML'
            storage_types:
              language_field:
                module: languagefield
                storage_settings: {maxlength: 5}
                instance_settings: {}
                widget: {type: languagefield_autocomplete, settings: {}}
                formatter: {type: languagefield_default, label: hidden, settings: {}}
            YAML);
        $map = new DrupalTypeMap(null, $file);

        self::assertSame(['maxlength' => 5], $map->storageType('language_field')['storage_settings']);
        self::assertSame('languagefield_autocomplete', $map->widget('language_field')['type']);
        self::assertSame('hidden', $map->formatter('language_field')['label']);
        self::assertSame(12, $this->bundled()->storageType('language_field')['storage_settings']['maxlength'], 'the bundled map is not changed');
        self::assertSame(['string', 'string_long', 'email', 'telephone', 'language_field'], $map->acceptedFor('text'), 'kit_types untouched without an overlay entry');
    }

    #[Test]
    public function the_overlay_of_the_settings_is_loaded_relative_to_definition_kit_yaml(): void
    {
        $dir = sys_get_temp_dir() . '/dk-overlay-settings-' . uniqid('', true);
        mkdir("{$dir}/sub", 0777, true);
        copy(self::OVERLAY, "{$dir}/sub/types.yaml");
        file_put_contents("{$dir}/definition-kit.yaml", "drupal:\n  type_map: sub/types.yaml\n");
        $this->files[] = "{$dir}/sub/types.yaml";
        $this->files[] = "{$dir}/definition-kit.yaml";

        $settings = \Parisek\DefinitionKit\Drupal\DrupalSettings::discoverFor($dir);
        $map = DrupalTypeMap::forSettings($settings);

        self::assertSame("{$dir}/sub/types.yaml", $settings->typeMap);
        self::assertTrue($map->hasStorageType('color_field_type'));
        self::assertFalse(DrupalTypeMap::forSettings(new \Parisek\DefinitionKit\Drupal\DrupalSettings())->hasStorageType('color_field_type'));
        @rmdir("{$dir}/sub");
        @rmdir($dir);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedOverlays(): iterable
    {
        yield 'not YAML' => ["storage_types: [unclosed\n", 'Malformed Drupal type map overlay (drupal.type_map)'];
        yield 'a list, not a map' => ["- storage_types\n", 'the file must be a map of sections'];
        yield 'a scalar' => ["just text\n", 'the file must be a map of sections'];
        yield 'an unknown section' => ["storage_type:\n  x: {module: m}\n", 'unknown section(s) storage_type'];
        yield 'a section that is not a map' => ["storage_types: nope\n", 'storage_types must be a map'];
        yield 'a storage type without a module' => [
            "storage_types:\n  foo:\n    widget: {type: w, settings: {}}\n    formatter: {type: f, label: above, settings: {}}\n",
            'storage_types.foo needs a `module`',
        ];
        yield 'a storage type without a widget' => [
            "storage_types:\n  foo:\n    module: m\n    formatter: {type: f, label: above, settings: {}}\n",
            'storage_types.foo needs a widget with a `type`',
        ];
        yield 'a kit type list of numbers' => ["kit_types:\n  text: [1, 2]\n", 'kit_types.text must be a list of storage type names'];
        yield 'a kit type naming a storage type nobody defines' => ["kit_types:\n  text: [ghost_field]\n", "kit_types.text names storage type 'ghost_field'"];
        yield 'parts that are not a list' => [
            "storage_types:\n  foo:\n    module: m\n    parts: first\n    widget: {type: w, settings: {}}\n    formatter: {type: f, label: above, settings: {}}\n",
            'storage_types.foo.parts must be a list of names',
        ];
        yield 'a widget module that is not a string' => ["widget_modules:\n  w: [a]\n", 'widget_modules.w must name a module'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedOverlays')]
    public function a_malformed_overlay_is_refused_with_its_path_and_reason(string $yaml, string $reason): void
    {
        $file = $this->overlayFile($yaml);

        try {
            new DrupalTypeMap(null, $file);
            self::fail('The malformed overlay was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($file, $e->getMessage());
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    #[Test]
    public function a_missing_overlay_file_is_refused_by_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal type map overlay (drupal.type_map) not found: /nowhere/types.yaml');

        new DrupalTypeMap(null, '/nowhere/types.yaml');
    }

    #[Test]
    public function an_unknown_storage_type_names_the_overlay_as_the_fix(): void
    {
        try {
            (new DrupalTypeMap())->storageType('color_field_type');
            self::fail('An unknown storage type was accepted.');
        } catch (\DomainException $e) {
            self::assertStringContainsString("no storage type 'color_field_type'", $e->getMessage());
            self::assertStringContainsString('drupal.type_map', $e->getMessage());
        }
    }

    private function bundled(): DrupalTypeMap
    {
        return new DrupalTypeMap();
    }
}
