<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\VisibleWhenMapper;

final class VisibleWhenMapperTest extends TestCase
{
    private VisibleWhenMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new VisibleWhenMapper();
    }

    public function test_false_conditional_logic_yields_nothing(): void
    {
        $result = $this->mapper->map(false, []);
        self::assertNull($result['visible_when']);
        self::assertNull($result['fallback']);
    }

    public function test_zero_conditional_logic_yields_nothing(): void
    {
        $result = $this->mapper->map(0, []);
        self::assertNull($result['visible_when']);
        self::assertNull($result['fallback']);
    }

    public function test_equals_operator_maps_to_equals_key(): void
    {
        $cl = [[['field' => 'field_x_title', 'operator' => '==', 'value' => 'foo']]];
        $result = $this->mapper->map($cl, ['field_x_title' => 'title']);
        self::assertSame(['field' => 'title', 'equals' => 'foo'], $result['visible_when']);
        self::assertNull($result['fallback']);
    }

    public function test_not_equals_operator(): void
    {
        $cl = [[['field' => 'field_x_title', 'operator' => '!=', 'value' => 'foo']]];
        $result = $this->mapper->map($cl, ['field_x_title' => 'title']);
        self::assertSame(['field' => 'title', 'not_equals' => 'foo'], $result['visible_when']);
    }

    public function test_empty_operator_maps_to_true_flag(): void
    {
        $cl = [[['field' => 'field_x_title', 'operator' => '==empty']]];
        $result = $this->mapper->map($cl, ['field_x_title' => 'title']);
        self::assertSame(['field' => 'title', 'empty' => true], $result['visible_when']);
    }

    public function test_not_empty_operator_maps_to_true_flag(): void
    {
        $cl = [[['field' => 'field_x_title', 'operator' => '!=empty']]];
        $result = $this->mapper->map($cl, ['field_x_title' => 'title']);
        self::assertSame(['field' => 'title', 'not_empty' => true], $result['visible_when']);
    }

    public function test_contains_operator(): void
    {
        $cl = [[['field' => 'field_x_tags', 'operator' => '==contains', 'value' => 'featured']]];
        $result = $this->mapper->map($cl, ['field_x_tags' => 'tags']);
        self::assertSame(['field' => 'tags', 'contains' => 'featured'], $result['visible_when']);
    }

    public function test_unresolvable_field_key_falls_back_to_the_key_itself(): void
    {
        $cl = [[['field' => 'field_unknown', 'operator' => '==', 'value' => 'foo']]];
        $result = $this->mapper->map($cl, []);
        self::assertSame(['field' => 'field_unknown', 'equals' => 'foo'], $result['visible_when']);
    }

    public function test_multiple_and_conditions_fall_back_verbatim(): void
    {
        $cl = [[
            ['field' => 'field_x', 'operator' => '==', 'value' => 'a'],
            ['field' => 'field_y', 'operator' => '==', 'value' => 'b'],
        ]];
        $result = $this->mapper->map($cl, []);
        self::assertNull($result['visible_when']);
        self::assertSame($cl, $result['fallback']);
    }

    public function test_multiple_or_groups_fall_back_verbatim(): void
    {
        $cl = [
            [['field' => 'field_x', 'operator' => '==', 'value' => 'a']],
            [['field' => 'field_y', 'operator' => '==', 'value' => 'b']],
        ];
        $result = $this->mapper->map($cl, []);
        self::assertNull($result['visible_when']);
        self::assertSame($cl, $result['fallback']);
    }

    public function test_unmapped_operator_falls_back_verbatim(): void
    {
        $cl = [[['field' => 'field_x', 'operator' => '==pattern', 'value' => '/foo/']]];
        $result = $this->mapper->map($cl, []);
        self::assertNull($result['visible_when']);
        self::assertSame($cl, $result['fallback']);
    }

    public function test_to_conditional_logic_reverses_equals(): void
    {
        $result = (new VisibleWhenMapper())->toConditionalLogic(
            ['field' => 'title', 'equals' => 'foo'],
            ['title' => 'field_demo_title'],
        );
        self::assertSame(
            [[['field' => 'field_demo_title', 'operator' => '==', 'value' => 'foo']]],
            $result,
        );
    }

    public function test_to_conditional_logic_reverses_not_empty_with_empty_string_value(): void
    {
        $result = (new VisibleWhenMapper())->toConditionalLogic(
            ['field' => 'title', 'not_empty' => true],
            ['title' => 'field_demo_title'],
        );
        self::assertSame('!=empty', $result[0][0]['operator']);
        self::assertSame('field_demo_title', $result[0][0]['field']);
        self::assertSame('', $result[0][0]['value']);
    }

    public function test_to_conditional_logic_reverses_all_five_operators(): void
    {
        $mapper = new VisibleWhenMapper();
        $map = ['f' => 'field_demo_f'];

        $result = $mapper->toConditionalLogic(['field' => 'f', 'equals' => 'x'], $map);
        self::assertSame('==', $result[0][0]['operator'], 'slot equals');

        $result = $mapper->toConditionalLogic(['field' => 'f', 'not_equals' => 'x'], $map);
        self::assertSame('!=', $result[0][0]['operator'], 'slot not_equals');

        $result = $mapper->toConditionalLogic(['field' => 'f', 'empty' => true], $map);
        self::assertSame('==empty', $result[0][0]['operator'], 'slot empty');

        $result = $mapper->toConditionalLogic(['field' => 'f', 'not_empty' => true], $map);
        self::assertSame('!=empty', $result[0][0]['operator'], 'slot not_empty');

        $result = $mapper->toConditionalLogic(['field' => 'f', 'contains' => 'x'], $map);
        self::assertSame('==contains', $result[0][0]['operator'], 'slot contains');
    }

    public function test_to_conditional_logic_falls_back_to_field_name_when_not_in_map(): void
    {
        $result = (new VisibleWhenMapper())->toConditionalLogic(['field' => 'unmapped', 'equals' => 'x'], []);
        self::assertSame('unmapped', $result[0][0]['field']);
    }

    public function test_map_then_to_conditional_logic_round_trips(): void
    {
        $mapper = new VisibleWhenMapper();
        $raw = [[['field' => 'field_demo_title', 'operator' => '==', 'value' => 'foo']]];
        $mapped = $mapper->map($raw, ['field_demo_title' => 'title']);
        self::assertIsArray($mapped['visible_when']);
        /** @var array{field: string, equals?: mixed, not_equals?: mixed, empty?: true, not_empty?: true, contains?: mixed} $visibleWhen */
        $visibleWhen = $mapped['visible_when'];
        $rebuilt = $mapper->toConditionalLogic($visibleWhen, ['title' => 'field_demo_title']);
        self::assertSame($raw, $rebuilt);
    }
}
