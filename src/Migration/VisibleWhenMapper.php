<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Maps ACF's `conditional_logic` (OR-of-AND-groups keyed by field `key`) to
 * the design's `visible_when: { field, equals|not_equals|empty|not_empty|contains }`.
 * Only the single-condition case has an abstract slot; anything more complex
 * (2+ AND conditions, 2+ OR groups) or an unmapped operator falls back to
 * `wp.conditional_logic` verbatim — never silently dropped or half-mapped.
 */
final class VisibleWhenMapper
{
    private const OPERATOR_TO_SLOT = [
        '==' => 'equals',
        '!=' => 'not_equals',
        '==empty' => 'empty',
        '!=empty' => 'not_empty',
        '==contains' => 'contains',
    ];

    /**
     * @param array<string,string> $keyNameMap field key => field name
     * @return array{visible_when: ?array<string,mixed>, fallback: mixed}
     */
    public function map(mixed $conditionalLogic, array $keyNameMap): array
    {
        if (false === $conditionalLogic || 0 === $conditionalLogic || empty($conditionalLogic)) {
            return ['visible_when' => null, 'fallback' => null];
        }

        if (!is_array($conditionalLogic) || 1 !== count($conditionalLogic) || 1 !== count($conditionalLogic[0] ?? [])) {
            return ['visible_when' => null, 'fallback' => $conditionalLogic];
        }

        $cond = $conditionalLogic[0][0];
        $operator = $cond['operator'] ?? null;
        $refKey = $cond['field'] ?? null;

        if (null === $refKey || !is_string($operator) || !isset(self::OPERATOR_TO_SLOT[$operator])) {
            return ['visible_when' => null, 'fallback' => $conditionalLogic];
        }

        $slot = self::OPERATOR_TO_SLOT[$operator];
        $refName = $keyNameMap[$refKey] ?? $refKey;

        $visibleWhen = ['field' => $refName];
        $visibleWhen[$slot] = in_array($slot, ['empty', 'not_empty'], true) ? true : $cond['value'];

        return ['visible_when' => $visibleWhen, 'fallback' => null];
    }

    /**
     * Inverse of map(): rebuilds ACF's conditional_logic shape from a
     * semantic visible_when. The single-condition abstract vocabulary
     * (field/equals/not_equals/empty/not_empty/contains) always reverses
     * cleanly — there is no "fallback" case on this direction, since a
     * visible_when that made it into the semantic layer at all necessarily
     * came from one of the five mapped operators.
     *
     * @param array{field: string, equals?: mixed, not_equals?: mixed, empty?: true, not_empty?: true, contains?: mixed} $visibleWhen
     * @param array<string,string> $nameKeyMap field name => field key (inverse of map()'s $keyNameMap)
     * @return list<list<array{field: string, operator: string, value: mixed}>>
     */
    public function toConditionalLogic(array $visibleWhen, array $nameKeyMap): array
    {
        $refName = $visibleWhen['field'];
        $refKey = $nameKeyMap[$refName] ?? $refName;

        foreach (self::OPERATOR_TO_SLOT as $operator => $slot) {
            if (!array_key_exists($slot, $visibleWhen)) {
                continue;
            }
            $value = in_array($slot, ['empty', 'not_empty'], true) ? '' : $visibleWhen[$slot];
            return [[['field' => $refKey, 'operator' => $operator, 'value' => $value]]];
        }

        throw new \DomainException(sprintf(
            "visible_when for field '%s' matches none of the five known slots "
            . '(equals/not_equals/empty/not_empty/contains) — this should be unreachable '
            . 'for a document that already passed schema validation.',
            $refName,
        ));
    }
}
