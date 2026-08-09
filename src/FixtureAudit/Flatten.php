<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

/**
 * Flattens nested hashes into dot-path key sets, shared by the two halves of
 * `F` (design doc §4, "Supplied ≠ exercised"):
 *
 * - shape supplied  — the key exists in the hash, at any nesting depth
 * - branch exercised — the value is truthy by Twig's rules
 *
 * Arrays are flattened per element (design doc §4): a repeater's rows are
 * walked under the SAME path prefix, not an indexed one (`items.0.url` would
 * be a different key per row and would never accumulate coverage across
 * rows) — `items.url` is what a `{% for row in content.items %}{{ row.url }}`
 * read looks like in `T`, so `F` has to speak the same vocabulary to compare.
 *
 * FIX 1a (issue #521 follow-up review): keys with a leading underscore
 * (`_placeholderOpts` and everything nested under it) are package-internal
 * bookkeeping attached by `Placeholder::generate()` and consumed+unset
 * inside `Styleguide::resizerFilter()` (`vendor/parisek/styleguide`) — never
 * part of any `fields:` definition, never read by a template, never editor
 * content. `Flatten` is the single point every finding kind's `supplied` /
 * `exercised` / per-row-count sets are derived from (Auditor::computeFindings()
 * builds `dead-fixture-key`, `unexercised-branch`, `uniform-repeater-shape`
 * and the `undemonstrated-field` "supplied" check all from these), so pruning
 * the whole subtree HERE — rather than only where one finding kind happens to
 * consult `covered()` — is what guarantees it can never leak into any of
 * them, present or future.
 */
final class Flatten
{
    /**
     * @param array<string, mixed> $content
     * @return array{
     *   supplied: array<string, true>,
     *   exercised: array<string, true>,
     *   repeaters: array<string, array{rowCount: int, counts: array<string, int>, exercisedCounts: array<string, int>}>,
     * }
     */
    public static function content(array $content): array
    {
        $supplied = [];
        $exercised = [];
        $repeaters = [];
        self::walk($content, '', $supplied, $exercised, $repeaters);

        return ['supplied' => $supplied, 'exercised' => $exercised, 'repeaters' => $repeaters];
    }

    /**
     * @param array<string, true> $supplied
     * @param array<string, true> $exercised
     * @param array<string, array{rowCount: int, counts: array<string, int>, exercisedCounts: array<string, int>}> $repeaters
     *   design doc §4 "arrays are flattened per element": every repeater
     *   instance with 2+ rows gets an entry keyed by its own dot-path prefix,
     *   carrying the row total and, per sub-path, two separate per-row
     *   tallies: `counts` (how many rows SUPPLIED the key at all) and
     *   `exercisedCounts` (how many rows the value was truthy on). Rule #6
     *   (`uniform-repeater-shape`, §5) classifies against `exercisedCounts`,
     *   not `counts` — see that rule's own docblock in `Auditor.php` for why
     *   presence and truthiness must not be conflated for this axis. A
     *   single-row (or empty) repeater has nothing to classify against and is
     *   not recorded here at all.
     */
    private static function walk(mixed $value, string $prefix, array &$supplied, array &$exercised, array &$repeaters): void
    {
        if (is_array($value) && self::isList($value)) {
            // A list value: the key itself is supplied, "exercised" means a
            // non-empty list. Each element is flattened under the SAME
            // prefix so per-row fields accumulate rather than fork.
            if ('' !== $prefix) {
                $supplied[$prefix] = true;
                if ([] !== $value) {
                    $exercised[$prefix] = true;
                }
            }

            $rows = array_values(array_filter($value, static fn(mixed $row): bool => is_array($row)));
            /** @var array<string, int> $countPerPath how many rows SUPPLIED each sub-path */
            $countPerPath = [];
            /** @var array<string, int> $exercisedCountPerPath how many rows the sub-path was TRUTHY on */
            $exercisedCountPerPath = [];
            foreach ($rows as $row) {
                $rowSupplied = [];
                $rowExercised = [];
                $rowRepeaters = [];
                self::walk($row, $prefix, $rowSupplied, $rowExercised, $rowRepeaters);
                foreach ($rowSupplied as $path => $_) {
                    $countPerPath[$path] = ($countPerPath[$path] ?? 0) + 1;
                    $supplied[$path] = true;
                }
                foreach ($rowExercised as $path => $_) {
                    $exercisedCountPerPath[$path] = ($exercisedCountPerPath[$path] ?? 0) + 1;
                    $exercised[$path] = true;
                }
                // A repeater-of-repeaters nested inside a row is real
                // regardless of how many outer rows there are — propagate it
                // up unconditionally, merged by prefix.
                foreach ($rowRepeaters as $nestedPrefix => $nestedInfo) {
                    $repeaters[$nestedPrefix] = $nestedInfo;
                }
            }

            $rowCount = count($rows);
            if ($rowCount > 1 && '' !== $prefix) {
                $repeaters[$prefix] = ['rowCount' => $rowCount, 'counts' => $countPerPath, 'exercisedCounts' => $exercisedCountPerPath];
            }

            return;
        }

        if (is_array($value)) {
            // Associative hash: the key itself is supplied/exercised as a
            // whole (non-empty map counts as truthy, matching Twig), and
            // every child is flattened one level deeper.
            if ('' !== $prefix) {
                $supplied[$prefix] = true;
                if ([] !== $value) {
                    $exercised[$prefix] = true;
                }
            }
            foreach ($value as $key => $child) {
                // FIX 1a: package-internal keys (leading underscore) never
                // enter F at all — skip the whole subtree, not just the key
                // itself, so a nested key like `_placeholderOpts.seed` can't
                // surface either.
                if (is_string($key) && str_starts_with($key, '_')) {
                    continue;
                }
                // PHP array keys are always int|string — the check above is
                // structural documentation, not a real runtime narrowing.
                $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
                self::walk($child, $path, $supplied, $exercised, $repeaters);
            }

            return;
        }

        // Scalar or null leaf.
        if ('' === $prefix) {
            return;
        }
        $supplied[$prefix] = true;
        // Twig truthiness: null, '', 0, '0', false are all falsy.
        if (self::isTruthy($value)) {
            $exercised[$prefix] = true;
        }
    }

    private static function isTruthy(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }
        if (is_string($value)) {
            return '' !== $value && '0' !== $value;
        }

        return (bool) $value;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }
        $i = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }
}
