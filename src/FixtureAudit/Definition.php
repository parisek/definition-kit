<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

use Symfony\Component\Yaml\Yaml;

/**
 * Flattens a component's `<name>.yaml` `fields:` block into a dot-path => role
 * map. This is `D`, the definition axis — deliberately re-derived here rather
 * than pulled from `ComponentShapeResolver` (which resolves `of:` forwarding
 * for the T<->D check). Findings #2-#5 operate on what THIS component's own
 * yaml literally declares; forwarding is `ContractLinter`'s concern for
 * finding #1, not this axis's.
 *
 * Role inheritance mirrors `ContractLinter::fieldsWithoutARole()`: a
 * descendant without its own `role:` inherits the nearest ancestor's role
 * (e.g. every row of a `role: query` repeater is query-sourced too).
 */
final class Definition
{
    public const ROLE_FIELD = 'field';

    /**
     * @return array<string, string> dot-path => effective role
     */
    public static function flatten(string $yamlPath): array
    {
        return array_map(static fn(array $entry): string => $entry['role'], self::flattenWithRequired($yamlPath));
    }

    /**
     * FIX 1b: same walk as `flatten()`, plus each field's own literal
     * `required:` value (not inherited — a repeater marked `required: true`
     * says nothing about whether an individual sub-field is mandatory on
     * every row) and whether the field forwards its shape via `of:`. Used by
     * `Auditor::computeFindings()` to decide whether an
     * `uneven-repeater-coverage` finding should fire on a given path — see
     * that method's docblock for the reasoning.
     *
     * @return array<string, array{role: string, required: ?bool, forwards: bool}>
     *   dot-path => role + the field's own `required:` flag (`null` when the
     *   key is absent from the YAML, i.e. undeclared either way — this
     *   project's own convention treats that the same as `false`, see
     *   `picture.md`'s `image_mobile` convention and
     *   `Auditor::isDoctrinallyOptional()`) + whether this entry declares
     *   `of:` (a shape borrowed from elsewhere, never locally expanded here
     *   — `Auditor::isDoctrinallyOptional()` must not treat a path reachable
     *   only through a forward as "declared", since nothing about THIS
     *   yaml's own content actually said anything about it)
     */
    public static function flattenWithRequired(string $yamlPath): array
    {
        if (!is_file($yamlPath)) {
            return [];
        }

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($yamlPath) ?? [];
        $fields = isset($parsed['fields']) && is_array($parsed['fields']) ? $parsed['fields'] : [];

        $out = [];
        self::walk($fields, '', self::ROLE_FIELD, $out);

        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array{role: string, required: ?bool, forwards: bool}> $out
     */
    private static function walk(array $fields, string $prefix, string $inheritedRole, array &$out): void
    {
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            $path = '' === $prefix ? (string) $name : $prefix . '.' . $name;
            $role = is_string($field['role'] ?? null) ? $field['role'] : $inheritedRole;
            $required = is_bool($field['required'] ?? null) ? $field['required'] : null;
            $out[$path] = ['role' => $role, 'required' => $required, 'forwards' => isset($field['of'])];

            if (isset($field['fields']) && is_array($field['fields'])) {
                self::walk($field['fields'], $path, $role, $out);
            }
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                        self::walk($layout['fields'], $path . '.' . $layoutName, $role, $out);
                    }
                }
            }
        }
    }
}
