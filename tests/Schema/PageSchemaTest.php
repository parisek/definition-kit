<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `schemas/page.schema.json` — the `page/<id>/<id>.yaml` contract.
 *
 * A page is a different type from a component, so it has its own schema file
 * instead of a mode inside component.fields.schema.json.
 */
final class PageSchemaTest extends TestCase
{
    private static function validate(string $yaml): \Parisek\DefinitionKit\Schema\ValidationResult
    {
        return FieldsSchemaValidator::forPage()->validateData(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP));
    }

    /** @return array<string, mixed> */
    private static function schema(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function a_full_page_definition_is_valid(): void
    {
        $result = self::validate(<<<'YAML'
            name: "Hlavní strana"
            usage: [page-header-image, gallery-slider]
            category: Page
            render: bleed
            web: https://example.com
            asana: https://app.asana.com/0/1
            figma: https://figma.com/design/x
            drupal: https://example.com/node/1
            description: "Homepage"
            dev: "Header overlaps the hero."
            weight: 1
            responsive: false
            body_class: "bg-secondary-500"
            variants:
              dark: "Dark"
              light: { title: Light, description: "Light variant" }
            YAML);

        self::assertTrue($result->valid, json_encode($result->errors, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function name_alone_is_enough(): void
    {
        self::assertTrue(self::validate('name: "404"')->valid);
    }

    #[Test]
    public function name_is_required(): void
    {
        self::assertFalse(self::validate('weight: 1')->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function componentOnlyKeys(): iterable
    {
        yield 'fields' => ["name: Home\nfields:\n  title:\n    type: text\n"];
        yield 'kind' => ["name: Home\nkind: section\n"];
        yield 'wp' => ["name: Home\nwp: { block: {} }\n"];
        yield 'key' => ["name: Home\nkey: group_home\n"];
        yield 'mcp' => ["name: Home\nmcp: \"first block\"\n"];
    }

    #[Test]
    #[DataProvider('componentOnlyKeys')]
    public function a_component_only_key_is_refused(string $yaml): void
    {
        $result = self::validate($yaml);

        self::assertFalse($result->valid);
    }

    #[Test]
    public function the_component_schema_still_requires_fields(): void
    {
        $result = (new FieldsSchemaValidator())->validateData(Yaml::parse('name: Home', Yaml::PARSE_OBJECT_FOR_MAP));

        self::assertFalse($result->valid);
    }

    /**
     * The shared shapes are copied, not referenced. This keeps the copies one
     * truth: a change to a component key's shape must reach the page schema too.
     */
    #[Test]
    public function shared_keys_have_the_same_shape_as_in_the_component_schema(): void
    {
        $component = self::schema('component.fields.schema.json');
        $page = self::schema('page.schema.json');

        foreach (['name', 'category', 'web', 'asana', 'figma', 'drupal', 'weight', 'responsive'] as $key) {
            self::assertSame($component['properties'][$key], $page['properties'][$key], "`{$key}` drifted between the two schemas");
        }
        foreach (['usage', 'render', 'description', 'dev'] as $key) {
            $c = $component['properties'][$key];
            $p = $page['properties'][$key];
            unset($c['description'], $p['description']);
            self::assertSame($c, $p, "`{$key}` drifted between the two schemas");
        }
        self::assertSame($component['$defs']['multivalueAnnotation'], $page['$defs']['multivalueAnnotation']);
        self::assertSame(ComponentParser::RENDER_MODES, $page['properties']['render']['enum']);
    }
}
