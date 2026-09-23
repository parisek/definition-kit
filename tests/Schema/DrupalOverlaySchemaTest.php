<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Schema\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The per-field `drupal:` escape hatch, and the root `drupal:` link it must
 * not be confused with.
 */
final class DrupalOverlaySchemaTest extends TestCase
{
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
    public function every_overlay_key_validates(): void
    {
        $result = $this->validate(['type' => 'reference', 'label' => 'T', 'drupal' => [
            'field' => 'field_reusable_paragraph',
            'storage' => 'entity_reference',
            'widget' => 'entity_reference_autocomplete',
            'formatter' => 'entity_reference_label',
            'target_type' => 'paragraphs_library_item',
            'target_bundles' => ['image', 'vector_image'],
        ]]);

        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    #[Test]
    public function the_overlay_is_closed(): void
    {
        $result = $this->validate(['type' => 'text', 'label' => 'T', 'drupal' => ['table' => 'x']]);

        self::assertFalse($result->valid);
    }

    #[Test]
    public function an_empty_overlay_is_refused(): void
    {
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T', 'drupal' => []])->valid);
    }

    #[Test]
    public function a_field_name_must_be_a_drupal_machine_name(): void
    {
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T', 'drupal' => ['field' => 'Field-Title']])->valid);
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T', 'drupal' => ['field' => str_repeat('a', 33)]])->valid);
    }

    #[Test]
    public function the_root_drupal_key_stays_the_admin_link_string(): void
    {
        // parisek/styleguide reads the root key as a string; an object there is refused.
        self::assertTrue($this->validate(['type' => 'text', 'label' => 'T'], ['drupal' => '/admin/structure/paragraphs_type/x/fields'])->valid);
        self::assertFalse($this->validate(['type' => 'text', 'label' => 'T'], ['drupal' => ['field' => 'field_x']])->valid);
    }

    #[Test]
    public function the_wordpress_projection_ignores_the_drupal_overlay(): void
    {
        $plain = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
        $withDrupal = $plain;
        $withDrupal['fields']['title']['drupal'] = ['field' => 'field_heading', 'storage' => 'string_long'];

        $generator = new FieldsGenerator();

        self::assertSame($generator->generate($plain, 'demo', 1), $generator->generate($withDrupal, 'demo', 1));
    }
}
