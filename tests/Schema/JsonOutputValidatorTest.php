<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Schema\JsonOutputValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;

final class JsonOutputValidatorTest extends TestCase
{
    private JsonOutputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonOutputValidator(
            __DIR__ . '/../../schemas/acf-field-group.output.schema.json',
        );
    }

    public function test_valid_field_group_passes(): void
    {
        $result = $this->validator->validateData(ArrayJsonModel::toJsonModel([
            'key' => 'group_demo',
            'title' => 'Demo',
            'fields' => [
                ['key' => 'field_demo_title', 'name' => 'title', 'type' => 'text', 'label' => 'Nadpis'],
            ],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]],
            'menu_order' => 0,
            'modified' => 1700000000,
        ]));
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_missing_root_key_fails(): void
    {
        $result = $this->validator->validateData(ArrayJsonModel::toJsonModel([
            'title' => 'Demo',
            'fields' => [],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]],
            'menu_order' => 0,
            'modified' => 1700000000,
        ]));
        self::assertFalse($result->valid);
    }

    public function test_field_missing_key_fails(): void
    {
        $result = $this->validator->validateData(ArrayJsonModel::toJsonModel([
            'key' => 'group_demo',
            'title' => 'Demo',
            'fields' => [['name' => 'title', 'type' => 'text', 'label' => 'Nadpis']],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]],
            'menu_order' => 0,
            'modified' => 1700000000,
        ]));
        self::assertFalse($result->valid);
    }

    public function test_nested_sub_fields_are_validated_recursively(): void
    {
        $result = $this->validator->validateData(ArrayJsonModel::toJsonModel([
            'key' => 'group_demo',
            'title' => 'Demo',
            'fields' => [[
                'key' => 'field_demo_grp', 'name' => 'grp', 'type' => 'group', 'label' => 'G',
                'sub_fields' => [['name' => 'x', 'type' => 'text', 'label' => 'X']], // missing key!
            ]],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]],
            'menu_order' => 0,
            'modified' => 1700000000,
        ]));
        self::assertFalse($result->valid);
    }
}
