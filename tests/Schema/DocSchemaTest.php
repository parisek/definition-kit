<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Schema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `schemas/doc.schema.json` — the `doc/<id>/<id>.yaml` contract.
 *
 * A doc is prose, not a widget. It carries only the keys parisek/styleguide
 * gives an effect for a doc.
 */
final class DocSchemaTest extends TestCase
{
    private static function validate(string $yaml): ValidationResult
    {
        return FieldsSchemaValidator::forEntry('doc')->validateData(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP));
    }

    /** @return array<string, mixed> */
    private static function schema(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function a_full_doc_definition_is_valid(): void
    {
        $result = self::validate(<<<'YAML'
            name: "Typografie"
            description: "Škála a próza na jednom místě."
            dev: "Sizes are measured, never recomputed."
            weight: 98
            body_class: "bg-white"
            variants:
              inverted: "Inverted"
              light: { title: Light, description: "Light variant" }
            YAML);

        self::assertTrue($result->valid, json_encode($result->errors, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function name_alone_is_enough(): void
    {
        self::assertTrue(self::validate('name: Typografie')->valid);
    }

    #[Test]
    public function name_is_required(): void
    {
        self::assertFalse(self::validate('weight: 1')->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedKeys(): iterable
    {
        yield 'fields' => ["name: Doc\nfields:\n  title:\n    type: text\n"];
        yield 'kind' => ["name: Doc\nkind: part\n"];
        yield 'wp' => ["name: Doc\nwp: { block: {} }\n"];
        yield 'key' => ["name: Doc\nkey: group_doc\n"];
        yield 'mcp' => ["name: Doc\nmcp: \"first block\"\n"];
        yield 'render' => ["name: Doc\nrender: bleed\n"];
        yield 'responsive' => ["name: Doc\nresponsive: false\n"];
        yield 'usage' => ["name: Doc\nusage: [content]\n"];
        yield 'category' => ["name: Doc\ncategory: Docs\n"];
        yield 'web' => ["name: Doc\nweb: https://example.com\n"];
        yield 'asana' => ["name: Doc\nasana: https://app.asana.com/0/1\n"];
        yield 'figma' => ["name: Doc\nfigma: https://figma.com/design/x\n"];
        yield 'drupal' => ["name: Doc\ndrupal: https://example.com/node/1\n"];
    }

    #[Test]
    #[DataProvider('refusedKeys')]
    public function a_key_without_effect_for_a_doc_is_refused(string $yaml): void
    {
        self::assertFalse(self::validate($yaml)->valid);
    }

    #[Test]
    public function the_page_schema_still_allows_render_and_usage(): void
    {
        $result = FieldsSchemaValidator::forEntry('page')->validateData(
            Yaml::parse("name: Home\nrender: bleed\nusage: [demo]\nresponsive: false\n", Yaml::PARSE_OBJECT_FOR_MAP),
        );

        self::assertTrue($result->valid);
    }

    #[Test]
    public function shared_keys_have_the_same_shape_as_in_the_page_schema(): void
    {
        $page = self::schema('page.schema.json');
        $doc = self::schema('doc.schema.json');

        foreach (['name', 'weight', 'variants'] as $key) {
            self::assertSame($page['properties'][$key], $doc['properties'][$key], "`{$key}` drifted between the two schemas");
        }
        foreach (['description', 'dev', 'body_class'] as $key) {
            $p = $page['properties'][$key];
            $d = $doc['properties'][$key];
            unset($p['description'], $d['description']);
            self::assertSame($p, $d, "`{$key}` drifted between the two schemas");
        }
        self::assertSame($page['$defs']['multivalueAnnotation'], $doc['$defs']['multivalueAnnotation']);
    }
}
