<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Support\ArrayJsonModel;

final class ArrayJsonModelTest extends TestCase
{
    public function test_scalars_pass_through(): void
    {
        self::assertSame('x', ArrayJsonModel::toJsonModel('x'));
        self::assertSame(1, ArrayJsonModel::toJsonModel(1));
        self::assertTrue(ArrayJsonModel::toJsonModel(true));
        self::assertNull(ArrayJsonModel::toJsonModel(null));
    }

    public function test_list_array_stays_an_array(): void
    {
        $result = ArrayJsonModel::toJsonModel(['a', 'b', 'c']);
        self::assertIsArray($result);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_assoc_array_becomes_stdclass(): void
    {
        $result = ArrayJsonModel::toJsonModel(['type' => 'text', 'label' => 'Nadpis']);
        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame('text', $result->type);
        self::assertSame('Nadpis', $result->label);
    }

    public function test_empty_array_becomes_stdclass(): void
    {
        self::assertInstanceOf(\stdClass::class, ArrayJsonModel::toJsonModel([]));
    }

    public function test_recurses_into_nested_maps_and_lists(): void
    {
        $result = ArrayJsonModel::toJsonModel([
            'fields' => ['title' => ['type' => 'text', 'options' => ['a', 'b']]],
        ]);
        self::assertInstanceOf(\stdClass::class, $result->fields);
        self::assertSame('text', $result->fields->title->type);
        self::assertSame(['a', 'b'], $result->fields->title->options);
    }
}
