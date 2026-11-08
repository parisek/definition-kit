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
 * Issue #111. The `design:` key links parts of a component or page to
 * elements of a design source, per viewport. Each case runs against both
 * schemas, because the key has one shape on both.
 */
final class DesignKeySchemaTest extends TestCase
{
    private const COMPONENT_HEAD = "name: Example card\ncategory: Content\nfields:\n  title:\n    type: text\n    label: Title\n";

    private const PAGE_HEAD = "name: Example page\n";

    /** @return iterable<string, array{string}> */
    public static function types(): iterable
    {
        yield 'component' => ['component'];
        yield 'page' => ['page'];
    }

    private static function validate(string $type, string $designYaml): ValidationResult
    {
        $head = 'component' === $type ? self::COMPONENT_HEAD : self::PAGE_HEAD;
        $tree = Yaml::parse($head . $designYaml, Yaml::PARSE_OBJECT_FOR_MAP);
        $validator = 'component' === $type ? new FieldsSchemaValidator() : FieldsSchemaValidator::forEntry('page');

        return $validator->validateData($tree);
    }

    /** @return list<string> */
    private static function messages(ValidationResult $result): array
    {
        return array_map(static fn (array $e): string => "{$e['pointer']}: {$e['message']}", $result->errors);
    }

    /** The first ```yaml block under the "## Design references" heading of the README. */
    private static function readmeSnippet(): string
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $found = preg_match('/^## Design references\R.*?^```yaml\R(.*?)^```/ms', $readme, $matches);
        self::assertSame(1, $found, 'README.md has no yaml block under "## Design references".');

        return $matches[1];
    }

    #[Test]
    #[DataProvider('types')]
    public function the_readme_example_is_valid(string $type): void
    {
        $result = self::validate($type, self::readmeSnippet());

        self::assertTrue($result->valid, implode("\n", self::messages($result)));
    }

    #[Test]
    #[DataProvider('types')]
    public function a_minimal_entry_is_valid(string $type): void
    {
        $result = self::validate($type, "design:\n  - part: root\n    sources: { figma: { desktop-1280: { ref: '123:456' } } }\n");

        self::assertTrue($result->valid, implode("\n", self::messages($result)));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidEntries(): iterable
    {
        $refused = [
            'an empty list' => ["design: []\n", '/design'],
            'an unknown key in an entry' => ["design:\n  - part: root\n    colour: red\n    sources: { a: { v: { ref: x } } }\n", '/design/0'],
            'a missing part' => ["design:\n  - sources: { a: { v: { ref: x } } }\n", '/design/0'],
            'a missing sources' => ["design:\n  - part: root\n", '/design/0'],
            'an empty sources map' => ["design:\n  - part: root\n    sources: {}\n", '/design/0/sources'],
            'an empty viewport map' => ["design:\n  - part: root\n    sources: { a: {} }\n", '/design/0/sources/a'],
            'a source entry without ref' => ["design:\n  - part: root\n    sources: { a: { v: { select: '.x' } } }\n", '/design/0/sources/a/v'],
            'an unknown key in a source entry' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x, crop: true } } }\n", '/design/0/sources/a/v'],
            'a non-boolean repeat' => ["design:\n  - part: root\n    repeat: yes-please\n    sources: { a: { v: { ref: x } } }\n", '/design/0/repeat'],
            'a region without height' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x.png, region: { x: 0, y: 0, width: 390 } } } }\n", '/design/0/sources/a/v/region'],
            'a region with a string coordinate' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x.png, region: { x: '0', y: 0, width: 390, height: 640 } } } }\n", '/design/0/sources/a/v/region/x'],
            'a zero scale' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x.png, scale: 0 } } }\n", '/design/0/sources/a/v/scale'],
            'a negative scale' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x.png, scale: -2 } } }\n", '/design/0/sources/a/v/scale'],
            'a string scale' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x.png, scale: '2' } } }\n", '/design/0/sources/a/v/scale'],
            'a reveal shorthand step' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x, reveal: [{ hover: 'a.card' }] } } }\n", '/design/0/sources/a/v/reveal/0'],
            'a reveal step with an unknown action' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x, reveal: [{ action: scroll, selector: 'a.card' }] } } }\n", '/design/0/sources/a/v/reveal/0/action'],
            'a reveal step without selector' => ["design:\n  - part: root\n    sources: { a: { v: { ref: x, reveal: [{ action: click }] } } }\n", '/design/0/sources/a/v/reveal/0'],
        ];
        foreach (['component', 'page'] as $type) {
            foreach ($refused as $label => [$yaml, $pointer]) {
                yield "{$type}: {$label}" => [$type, $yaml, $pointer];
            }
        }
    }

    #[Test]
    #[DataProvider('invalidEntries')]
    public function an_invalid_entry_is_refused_at_its_pointer(string $type, string $yaml, string $pointer): void
    {
        $result = self::validate($type, $yaml);

        self::assertFalse($result->valid);
        self::assertContains($pointer, array_column($result->errors, 'pointer'), implode("\n", self::messages($result)));
    }

    #[Test]
    #[DataProvider('types')]
    public function a_duplicate_part_is_refused_and_named(string $type): void
    {
        $result = self::validate($type, <<<'YAML'
            design:
              - part: title
                sources: { a: { v: { ref: x } } }
              - part: root
                sources: { a: { v: { ref: x } } }
              - part: title
                sources: { b: { v: { ref: y } } }
            YAML);

        self::assertFalse($result->valid);
        self::assertSame(
            ["/design/2/part: Part 'title' is already declared at /design/0; each part is unique in a definition."],
            self::messages($result),
        );
    }

    #[Test]
    #[DataProvider('types')]
    public function select_with_region_is_refused_with_a_readable_message(string $type): void
    {
        $result = self::validate($type, "design:\n  - part: root\n    sources: { shots: { mobile-390: { ref: x.png, select: '.x', region: { x: 0, y: 0, width: 390, height: 640 } } } }\n");

        self::assertFalse($result->valid);
        $named = array_filter(
            self::messages($result),
            static fn (string $m): bool => str_contains($m, "Part 'root', source 'shots', viewport 'mobile-390'")
                && str_contains($m, '`select` and `region` exclude each other'),
        );
        self::assertCount(1, $named, implode("\n", self::messages($result)));
    }

    #[Test]
    #[DataProvider('types')]
    public function source_and_viewport_ids_are_free_strings(string $type): void
    {
        $result = self::validate($type, "design:\n  - part: root\n    sources: { 'Legacy Site_2': { 'tablet@768': { ref: /integrations/example/ } } }\n");

        self::assertTrue($result->valid, implode("\n", self::messages($result)));
    }

    #[Test]
    #[DataProvider('types')]
    public function the_reveal_step_is_the_test_kit_settle_reveal_shape(string $type): void
    {
        $result = self::validate($type, <<<'YAML'
            design:
              - part: root
                sources:
                  canvas:
                    desktop-1280:
                      ref: Example.dc.html
                      state: open
                      reveal:
                        - { action: click, selector: 'button.example-toggle' }
                        - { action: hover, selector: 'a.card' }
                        - { action: focus, selector: 'input[type=search]' }
            YAML);

        self::assertTrue($result->valid, implode("\n", self::messages($result)));
    }
}
