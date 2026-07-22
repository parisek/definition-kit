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
}
