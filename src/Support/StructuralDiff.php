<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

/**
 * Recursive, order-independent structural diff, extracted verbatim (same
 * algorithm, same two documented ACF-serialization quirks) from what was
 * originally tests/Support/AcfJsonComparator (dávka 3's round-trip proof
 * helper) so it can be depended on from production code — specifically
 * Lint\DriftLinter, which must run from a downstream project's CI via
 * bin/fields-lint without pulling in autoload-dev. tests/Support/
 * AcfJsonComparator now wraps this class (formatEntry() reproduces its
 * exact legacy string shape) so every pre-existing comparator/round-trip
 * test stays green unchanged.
 *
 * Equality is STRICT (`===`, after a numeric-string/int normalization —
 * see `looseEquals()`) everywhere except two narrow, documented ACF
 * serialization quirks. This is deliberately NOT a blanket bool-vs-
 * empty-string/null/array normalization — see the dávka-3 README's
 * "Fixed during the dávka-3 review round" note for the exact bug that
 * over-loose comparison hid. Lint\DriftAllowlist adds a THIRD, separate,
 * narrower layer on top of this for the five documented round-trip
 * residuals — it does not modify this class.
 */
final class StructuralDiff
{
    /**
     * @return list<array{kind: 'value'|'missing'|'unexpected', path: string, prop: string, expected: mixed, actual: mixed}>
     */
    public static function diff(mixed $expected, mixed $actual, string $path = ''): array
    {
        if (is_array($expected) && is_array($actual) && array_is_list($expected) && array_is_list($actual)) {
            if (count($expected) !== count($actual)) {
                return [[
                    'kind' => 'value',
                    'path' => $path,
                    'prop' => self::lastPathSegment($path),
                    'expected' => count($expected) . ' items',
                    'actual' => count($actual) . ' items',
                ]];
            }
            $diffs = [];
            foreach ($expected as $i => $item) {
                $diffs = [...$diffs, ...self::diff($item, $actual[$i], "{$path}[{$i}]")];
            }
            return $diffs;
        }

        if (is_array($expected) && is_array($actual)) {
            $diffs = [];
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                if (!array_key_exists($key, $actual)) {
                    $diffs[] = ['kind' => 'missing', 'path' => "{$path}.{$key}", 'prop' => (string) $key, 'expected' => $expected[$key], 'actual' => null];
                    continue;
                }
                if (!array_key_exists($key, $expected)) {
                    $diffs[] = ['kind' => 'unexpected', 'path' => "{$path}.{$key}", 'prop' => (string) $key, 'expected' => null, 'actual' => $actual[$key]];
                    continue;
                }
                $diffs = [...$diffs, ...self::diff($expected[$key], $actual[$key], "{$path}.{$key}")];
            }
            return $diffs;
        }

        $prop = self::lastPathSegment($path);
        if (self::looseEquals($prop, $expected, $actual)) {
            return [];
        }

        return [['kind' => 'value', 'path' => $path, 'prop' => $prop, 'expected' => $expected, 'actual' => $actual]];
    }

    /** @param array{kind: string, path: string, prop: string, expected: mixed, actual: mixed} $entry */
    public static function formatEntry(array $entry): string
    {
        return match ($entry['kind']) {
            'missing' => "{$entry['path']}: missing from actual",
            'unexpected' => "{$entry['path']}: unexpected in actual",
            default => sprintf(
                '%s: expected %s, got %s',
                $entry['path'],
                json_encode($entry['expected'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($entry['actual'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
        };
    }

    /** Same two quirks as before extraction — see class docblock. Not touched by Lint\DriftAllowlist. */
    private static function looseEquals(string $prop, mixed $a, mixed $b): bool
    {
        if ('conditional_logic' === $prop) {
            $emptyIsh = static fn (mixed $v): bool => false === $v || 0 === $v || [] === $v;
            if ($emptyIsh($a) && $emptyIsh($b)) {
                return true;
            }
        }

        if (self::BOUNDED_NUMERIC_PROPS[$prop] ?? false) {
            $numeric = static fn (mixed $v): bool => is_int($v) || (is_string($v) && is_numeric($v));
            if ($numeric($a) && $numeric($b)) {
                return (string) (0 + $a) === (string) (0 + $b);
            }
        }

        return $a === $b;
    }

    private const BOUNDED_NUMERIC_PROPS = [
        'min' => true, 'max' => true, 'step' => true, 'maxlength' => true,
        'min_width' => true, 'max_width' => true, 'min_height' => true, 'max_height' => true,
        'max_size' => true, 'rows_per_page' => true, 'menu_order' => true, 'wpml_cf_preferences' => true,
    ];

    private static function lastPathSegment(string $path): string
    {
        $key = strrchr($path, '.');
        return false !== $key ? substr($key, 1) : $path;
    }
}
