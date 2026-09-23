<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DrupalParagraphReaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal';

    private function reader(bool $evidence = true, ?DrupalSettings $settings = null): DrupalParagraphReader
    {
        return new DrupalParagraphReader(
            DrupalConfig::fromDirectory(self::FIXTURES . '/config'),
            $settings ?? DrupalSettings::discoverFor(self::FIXTURES . '/component'),
            $evidence ? DisplayEvidence::fromFile(self::FIXTURES . '/ParagraphDisplay.php') : null,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function golden(): iterable
    {
        yield 'list of item paragraphs, renamed props' => ['card_list', 'card-list'];
        yield 'nested prop object, gallery, link' => ['quote_image', 'quote-image'];
        yield 'instance cardinality limit' => ['stats', 'stats'];
        yield 'object pinned to a single nested paragraph' => ['promo', 'promo'];
        yield 'aliased bundle field renamed by evidence' => ['content', 'content'];
    }

    #[Test]
    #[DataProvider('golden')]
    public function it_matches_the_golden_definition(string $bundle, string $component): void
    {
        $expected = Yaml::parseFile(self::FIXTURES . "/expected/{$component}.yaml");
        self::assertIsArray($expected);

        self::assertSame($expected['fields'], $this->reader()->read($bundle));
    }

    #[Test]
    #[DataProvider('golden')]
    public function its_output_is_a_valid_definition(string $bundle, string $component): void
    {
        self::assertFileExists(self::FIXTURES . "/expected/{$component}.yaml");
        $tree = ['name' => 'X', 'category' => 'Block', 'kind' => 'block', 'fields' => $this->reader()->read($bundle)];

        $result = (new FieldsSchemaValidator())->validateData(ArrayJsonModel::toJsonModel($tree));

        self::assertTrue($result->valid, json_encode($result->errors, JSON_PRETTY_PRINT) ?: '');
    }

    #[Test]
    public function without_evidence_a_field_group_becomes_an_object_and_names_follow_the_convention(): void
    {
        $fields = $this->reader(evidence: false)->read('card_list');

        self::assertSame(['heading', 'paragraphs'], array_keys($fields));
        self::assertSame('object', $fields['heading']['type']);
        self::assertSame('Heading', $fields['heading']['label']);
        self::assertSame(['title'], array_keys($fields['heading']['fields']));
        self::assertSame('list', $fields['paragraphs']['type']);
        self::assertArrayNotHasKey('drupal', $fields['paragraphs'], 'field_paragraphs -> card_list_item is the convention');
        self::assertSame(['title', 'image', 'perex', 'phone', 'email'], array_keys($fields['paragraphs']['fields']));
    }

    #[Test]
    public function ignored_framework_fields_are_left_out(): void
    {
        $fields = $this->reader(evidence: false, settings: new DrupalSettings())->read('card_list');

        self::assertArrayHasKey('advanced', $fields, 'group_advanced holds field_wrapper_id');
        self::assertArrayNotHasKey('advanced', $this->reader(evidence: false)->read('card_list'));
    }

    #[Test]
    public function several_target_bundles_become_layouts(): void
    {
        $fields = $this->reader()->read('mixed_section');

        self::assertSame('flexible_content', $fields['sections']['type']);
        self::assertSame(['image_full', 'quote_image'], array_keys($fields['sections']['layouts']));
        self::assertSame('Image full', $fields['sections']['layouts']['image_full']['label']);
        self::assertTrue($fields['sections']['layouts']['image_full']['fields']['media']['required']);
    }

    #[Test]
    public function a_reference_with_no_kit_target_pins_the_entity_type(): void
    {
        $fields = $this->reader()->read('from_library');

        self::assertSame(
            ['type' => 'reference', 'role' => 'field', 'label' => 'Reusable paragraph', 'required' => true, 'drupal' => ['target_type' => 'paragraphs_library_item']],
            $fields['reusable_paragraph'],
        );
    }

    #[Test]
    public function prefixed_naming_pins_shared_storage(): void
    {
        $fields = $this->reader(evidence: false, settings: new DrupalSettings(fieldNaming: DrupalSettings::NAMING_PREFIXED))->read('teaser');

        self::assertSame(['field' => 'field_title'], $fields['title']['drupal']);
    }

    #[Test]
    public function an_unknown_bundle_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('logo_list');

        $this->reader()->read('logo_list');
    }
}
