<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Schema\ValidationResult;
use Symfony\Component\Yaml\Yaml;

/**
 * Component-level metadata keys (`kind`, `render`, …) — as distinct from
 * FieldsSchemaValidatorTest, which covers the `fields:` map itself.
 */
final class ComponentDefinitionSchemaTest extends TestCase
{
    /**
     * Builds a minimal valid definition (one field, so `fieldMap`'s
     * `minProperties: 1` is satisfied) merged with the given component-level
     * overrides, then validates it via the in-process `validateData()` path.
     *
     * @param array<string,mixed> $overrides
     */
    private function validateDefinition(array $overrides): ValidationResult
    {
        $definition = array_merge(
            [
                'name' => 'X',
                'fields' => [
                    'title' => [
                        'type' => 'text',
                        'label' => 'Title',
                    ],
                ],
            ],
            $overrides,
        );

        $tree = Yaml::parse(Yaml::dump($definition), Yaml::PARSE_OBJECT_FOR_MAP);

        return (new FieldsSchemaValidator())->validateData($tree);
    }

    public function testKindAcceptsEveryDeclaredValue(): void
    {
        foreach (['block', 'section', 'element', 'part', 'utility'] as $kind) {
            $result = $this->validateDefinition(['kind' => $kind]);
            self::assertTrue($result->valid, "kind: {$kind} must validate: " . print_r($result->errors, true));
        }
    }

    public function testKindRejectsAnUnknownValue(): void
    {
        $result = $this->validateDefinition(['kind' => 'widget']);
        self::assertFalse($result->valid, 'kind is a closed enum — "widget" must fail');
    }

    public function testDefinitionWithoutKindStillValidates(): void
    {
        // Backfill has not run yet; the schema must not break existing definitions.
        $result = $this->validateDefinition([]);
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function testRenderAcceptsEveryPackageMode(): void
    {
        foreach (['inset', 'bleed', 'chrome', 'overlay'] as $mode) {
            $result = $this->validateDefinition(['render' => $mode]);
            self::assertTrue($result->valid, "render: {$mode} must validate: " . print_r($result->errors, true));
        }
    }

    public function testRenderRejectsAValueThePackageWouldSilentlyDefault(): void
    {
        $result = $this->validateDefinition(['render' => 'inline']);
        self::assertFalse($result->valid, 'an unknown render mode must fail loudly, not default silently');
    }

    public function testRootAcceptsTheAgentAndDeveloperAnnotations(): void
    {
        // The triad already existed at field level; before #141 the root had
        // only `description:`, and additionalProperties:false made `mcp:` a
        // hard validation error rather than an ignored key.
        $result = $this->validateDefinition([
            'description' => 'A large opening block.',
            'mcp' => ['At most once per page, always first.', 'Use section-intro for mid-page sections.'],
            'dev' => 'Shares its spacing scale with hero-about.',
        ]);

        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function testRootAnnotationsAcceptASingleStringToo(): void
    {
        // multivalueAnnotation is string-or-list; the field level already
        // relies on that and the root must not diverge.
        $result = $this->validateDefinition(['mcp' => 'At most once per page.']);

        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function testRootAnnotationsRejectAnEmptyEntry(): void
    {
        // An empty line would reach the agent as a blank instruction.
        $result = $this->validateDefinition(['mcp' => ['']]);

        self::assertFalse($result->valid, 'a blank annotation line must fail rather than reach the catalog');
    }

    public function testLayoutAcceptsTheWholeTriad(): void
    {
        // Choosing between sibling layouts is the same decision as choosing
        // between components, one level down — and had no annotation at all.
        $result = $this->validateDefinition([
            'fields' => [
                'sections' => [
                    'type' => 'flexible_content',
                    'label' => 'Sections',
                    'layouts' => [
                        'quote' => [
                            'label' => 'Quote',
                            'description' => 'A pulled quote.',
                            'mcp' => 'Use for a single short attributed quote; for several, use the testimonial-grid layout.',
                            'dev' => 'Renders through the shared blockquote partial.',
                            'fields' => [
                                'text' => ['type' => 'richtext', 'label' => 'Text'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function testAnnotationsAcceptAnExplicitNullMeaningDeliberatelyNone(): void
    {
        // A bare `mcp:` in YAML parses as null. Absence cannot express
        // "decided, none needed" — without this a lint reporting un-annotated
        // components has no way to ever stop reporting the ones somebody
        // already ruled on.
        foreach (['mcp', 'dev'] as $key) {
            $result = $this->validateDefinition([$key => null]);
            self::assertTrue($result->valid, "{$key}: null must validate: " . print_r($result->errors, true));
        }
    }

    public function testAnnotationsStillRejectAnEmptyStringAndAnEmptyList(): void
    {
        // Deliberately narrower than "any falsy value": these two are almost
        // always a half-finished edit, and allowing them would give one
        // decision three spellings.
        self::assertFalse($this->validateDefinition(['mcp' => ''])->valid, 'an empty string is not a decision');
        self::assertFalse($this->validateDefinition(['mcp' => []])->valid, 'an empty list is not a decision');
    }

    public function testAFieldLevelAnnotationAcceptsNullToo(): void
    {
        // The three states must read the same at every level.
        $result = $this->validateDefinition([
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Title', 'mcp' => null],
            ],
        ]);

        self::assertTrue($result->valid, print_r($result->errors, true));
    }
}
