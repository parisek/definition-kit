<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Symfony\Component\Yaml\Yaml;

final class FieldsSchemaValidatorTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/../fixtures/' . $name;
    }

    public function test_valid_flat_document_passes(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-flat.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
        self::assertSame([], $result->errors);
    }

    public function test_valid_nested_document_with_roles_and_wp_hatch_passes(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-nested.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_empty_wp_object_validates(): void
    {
        // Symfony YAML parses `wp: {}` as PHP `[]`; without PARSE_OBJECT_FOR_MAP
        // it round-trips to JSON `[]` and fails the schema's `wp: {type:object}`.
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-empty-wp.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_validate_data_accepts_in_process_tree_with_empty_wp_object(): void
    {
        // validateData() must agree with validateFile() on `wp: {}` — both must
        // feed opis the same JSON-model shape (stdClass for maps, incl. empty ones).
        $tree = Yaml::parse(<<<YAML
        name: X
        fields:
          spacing:
            type: select
            label: L
            options: { a: A }
            wp: {}
        YAML, Yaml::PARSE_OBJECT_FOR_MAP);

        $result = (new FieldsSchemaValidator())->validateData($tree);
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_validate_data_rejects_bad_in_process_tree(): void
    {
        $tree = Yaml::parse(<<<YAML
        name: X
        fields:
          spacing:
            type: bogus
            label: L
            options: { a: A }
            wp: {}
        YAML, Yaml::PARSE_OBJECT_FOR_MAP);

        $result = (new FieldsSchemaValidator())->validateData($tree);
        self::assertFalse($result->valid);
    }

    public function test_document_with_two_broken_fields_reports_both_errors(): void
    {
        // Proves finding 1: the validator no longer stops at the first error.
        $yamlPath = tempnam(sys_get_temp_dir(), 'fields-two-errors-') . '.fields.yaml';
        file_put_contents($yamlPath, <<<YAML
        name: Demo Two Errors
        fields:
          headline:
            type: heading
            label: Headline
          orphan:
            type: text
        YAML);

        try {
            $result = (new FieldsSchemaValidator())->validateFile($yamlPath);
            self::assertFalse($result->valid);
            self::assertGreaterThanOrEqual(2, count($result->errors), print_r($result->errors, true));
            $pointers = array_column($result->errors, 'pointer');
            self::assertTrue(
                (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'headline')),
                'headline pointer expected: ' . implode(',', $pointers),
            );
            self::assertTrue(
                (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'orphan')),
                'orphan pointer expected: ' . implode(',', $pointers),
            );
        } finally {
            unlink($yamlPath);
        }
    }

    public function test_bad_type_enum_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-bad-type-enum.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'headline')),
            'headline pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_missing_label_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-missing-label.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'orphan')),
            'orphan pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_select_without_options_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-select-no-options.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'spacing')),
            'spacing pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_group_without_fields_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-group-no-fields.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'heading')),
            'heading pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_valid_flexible_content_document_passes(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-flexible-content.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_flexible_content_without_layouts_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-flexible-content-no-layouts.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'items')),
            'items pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_key_not_matching_field_prefix_fails_with_pointer_to_field(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-bad-key-pattern.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'logo')),
            'logo pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_unknown_field_property_fails_with_pointer_to_field(): void
    {
        // Proves additionalProperties:false on the field schema.
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-unknown-property.fields.yaml'));
        self::assertFalse($result->valid);
        $pointers = array_column($result->errors, 'pointer');
        self::assertTrue(
            (bool) array_filter($pointers, static fn (string $p): bool => str_contains($p, 'title')),
            'title pointer expected: ' . implode(',', $pointers),
        );
    }

    public function test_malformed_yaml_fails_cleanly_instead_of_throwing(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-malformed-yaml.fields.yaml'));
        self::assertFalse($result->valid);
        self::assertNotEmpty($result->errors);
    }

    public function test_missing_file_fails_cleanly_instead_of_throwing(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('does-not-exist.fields.yaml'));
        self::assertFalse($result->valid);
        self::assertNotEmpty($result->errors);
        self::assertStringContainsString('does-not-exist.fields.yaml', $result->errors[0]['message']);
    }

    public function test_authored_vocabulary_fixture_is_valid(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile(
            __DIR__ . '/../fixtures/valid-authored-vocabulary.fields.yaml',
        );
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_visible_when_rejects_two_simultaneous_operators(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile(
            __DIR__ . '/../fixtures/invalid-visible-when-two-operators.fields.yaml',
        );
        self::assertFalse($result->valid);
    }

    public function test_mcp_rejects_empty_array(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile(
            __DIR__ . '/../fixtures/invalid-mcp-empty-array.fields.yaml',
        );
        self::assertFalse($result->valid);
    }

    public function test_root_key_rejects_bad_pattern(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile(
            __DIR__ . '/../fixtures/invalid-root-key-pattern.fields.yaml',
        );
        self::assertFalse($result->valid);
    }

    public function test_root_metadata_fixture_with_all_optional_keys_is_valid(): void
    {
        // Proves the optional root component-metadata keys (usage/category/
        // render/web/asana/figma/drupal/description/weight/responsive) are
        // accepted together — regression guard for the dávka-1 fixtures
        // (which omit all of them) is the test below.
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-root-metadata.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
        self::assertSame([], $result->errors);
    }

    public function test_usage_accepts_a_native_yaml_list(): void
    {
        // `usage` is a multi-value key: the canonical migration output is a
        // native YAML sequence. The string form (valid-root-metadata) stays
        // valid too — proven by the all-optional-keys test above.
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('valid-usage-list.fields.yaml'));
        self::assertTrue($result->valid, print_r($result->errors, true));
        self::assertSame([], $result->errors);
    }

    public function test_usage_rejects_an_empty_list(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile($this->fixture('invalid-usage-empty-list.fields.yaml'));
        self::assertFalse($result->valid);
    }

    public function test_existing_dávka_1_fixtures_still_validate(): void
    {
        foreach (['valid-flat', 'valid-nested', 'valid-empty-wp'] as $name) {
            $result = (new FieldsSchemaValidator())->validateFile(
                __DIR__ . "/../fixtures/{$name}.fields.yaml",
            );
            self::assertTrue($result->valid, "{$name}: " . print_r($result->errors, true));
        }
    }
}
