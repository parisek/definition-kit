<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Schema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `third_party_settings`: data that a project owns, keyed by owner. The kit
 * checks the shape and never reads the content.
 */
final class ThirdPartySettingsSchemaTest extends TestCase
{
    private const DRUPAL_FIXTURES = __DIR__ . '/../fixtures/drupal-merge';

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $root
     */
    private function validate(array $field, array $root = []): ValidationResult
    {
        $definition = array_merge(['name' => 'X', 'category' => 'Block', 'fields' => ['title' => $field]], $root);

        return (new FieldsSchemaValidator())->validateData(Yaml::parse(Yaml::dump($definition, 10), Yaml::PARSE_OBJECT_FOR_MAP));
    }

    #[Test]
    public function an_owner_map_validates_on_the_root_and_on_a_field(): void
    {
        $settings = ['my_theme' => ['sites' => ['site-a'], 'nested' => ['any' => ['shape' => 1]]], 'other_tool' => []];

        self::assertTrue($this->validate(['type' => 'text', 'label' => 'T'], ['third_party_settings' => $settings])->valid);
        self::assertTrue($this->validate(['type' => 'text', 'label' => 'T', 'third_party_settings' => $settings])->valid);
    }

    #[Test]
    public function an_empty_map_in_a_file_validates(): void
    {
        $file = sys_get_temp_dir() . '/dk-tps-' . uniqid('', true) . '.yaml';
        file_put_contents($file, "name: X\ncategory: Block\nthird_party_settings: {}\nfields:\n  title:\n    type: text\n    label: T\n    third_party_settings: {}\n");

        try {
            self::assertTrue((new FieldsSchemaValidator())->validateFile($file)->valid);
        } finally {
            unlink($file);
        }
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function badShapes(): iterable
    {
        yield 'owner key with a capital' => [['MyTheme' => ['a' => 1]]];
        yield 'owner key with a hyphen' => [['my-theme' => ['a' => 1]]];
        yield 'owner key that starts with a digit' => [['1theme' => ['a' => 1]]];
        yield 'owner value is a scalar' => [['my_theme' => 'brands']];
        yield 'owner value is a list' => [['my_theme' => ['site-a', 'site-b']]];
        yield 'the whole value is a list' => [['sites']];
        yield 'the whole value is a string' => ['sites'];
    }

    #[Test]
    #[DataProvider('badShapes')]
    public function a_bad_shape_is_refused(mixed $value): void
    {
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T'], ['third_party_settings' => $value])->valid);
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T', 'third_party_settings' => $value])->valid);
    }

    #[Test]
    public function the_schema_stays_closed_for_other_keys(): void
    {
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T'], ['my_theme' => ['a' => 1]])->valid);
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T', 'my_theme' => ['a' => 1]])->valid);
    }

    #[Test]
    public function the_acf_projection_ignores_the_key(): void
    {
        $plain = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
        $with = $plain;
        $with['third_party_settings'] = ['my_theme' => ['sites' => ['site-a']]];
        $with['fields']['title']['third_party_settings'] = ['my_theme' => ['hint' => 'x']];

        $generator = new FieldsGenerator();

        self::assertSame($generator->generate($plain, 'demo', 1), $generator->generate($with, 'demo', 1));
    }

    #[Test]
    public function the_block_json_projection_ignores_the_key(): void
    {
        $plain = ['name' => 'Demo', 'category' => 'Block', 'kind' => 'block', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
        $with = $plain;
        $with['third_party_settings'] = ['my_theme' => ['sites' => ['site-a']]];

        $generator = new BlockJsonGenerator();

        self::assertSame($generator->generate($plain, 'demo'), $generator->generate($with, 'demo'));
    }

    #[Test]
    public function the_drupal_projection_ignores_the_key(): void
    {
        $settings = DrupalSettings::discoverFor(self::DRUPAL_FIXTURES . '/component');
        $config = DrupalConfig::fromDirectory(self::DRUPAL_FIXTURES . '/config');
        $plain = [
            'name' => 'Quote with Image',
            'category' => 'Block',
            'drupal' => '/admin/structure/paragraphs_type/quote_image/fields',
            'fields' => ['title' => ['type' => 'text', 'label' => 'Title']],
        ];
        $with = $plain;
        $with['third_party_settings'] = ['my_theme' => ['sites' => ['site-a']]];
        $with['fields']['title']['third_party_settings'] = ['my_theme' => ['hint' => 'x']];

        $builder = new BundleSpecBuilder($settings);

        self::assertEquals($builder->build($plain, 'quote-image', $config), $builder->build($with, 'quote-image', $config));
    }
}
