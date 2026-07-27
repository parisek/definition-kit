<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

/**
 * Implements the two-axis model from issue #13: `role:` (axis B —
 * provenance, where the runtime value comes from) and `acf:` (axis A —
 * projection, whether the field has an ACF-backed representation in
 * acf.json). Earlier drafts of this feature hung projection off `role`
 * directly; a real component (`benefits-list.columns` — an ACF `select`
 * that is ALSO post-processed by PHP) falsified that: a field can be both
 * editor-backed and post-processed. The two questions are independent, so
 * they get independent keys.
 *
 * Issue #27 completed axis B into the six roles below and removed
 * `computed`, which the corpus never justified: every occurrence was
 * database-backed (`query`) or built by the field-formatting layer out of a
 * sibling (`derived`). `role: computed` is now rejected by name.
 *
 * This class runs once, at the very top of FieldsGenerator::generate(),
 * over the RAW (pre-reconstruction) `fields:` map — the same shape
 * FieldsGenerator itself recurses (`fields`/`layouts`/nested `fields`).
 * Its output contains ONLY the projecting subtree; every non-projecting
 * field (and, per rule 9, every container left with zero projecting
 * children) is dropped before key derivation, baseline merging, or any
 * other ACF-shape logic ever sees it — including
 * FieldsGenerator::assertConditionalLogicReferencesResolve(), which runs
 * on the FINAL built tree and therefore automatically validates
 * `conditional_logic` / `visible_when` references against the
 * post-filter key set (rule 8): a `visible_when` pointing at a stripped
 * field surfaces as "references key '<key>', which does not exist
 * anywhere in the generated tree" with no extra plumbing needed here.
 *
 * ## Axis A defaults (rule 1)
 *
 * | `role`      | `acf:` default | why                                |
 * | ----------- | -------------- | ---------------------------------- |
 * | `field`     | `true`         | the editor fills it — definitional  |
 * | `global`    | `false`        | the theme supplies it              |
 * | `query`     | `false`        | a query supplies it                |
 * | `parent`    | `false`        | the calling template passes it     |
 * | `inherited` | `false`        | the render pipeline injects it     |
 * | `derived`   | `false`        | the field-formatting layer builds it from a sibling |
 *
 * Only `field` projects, because only an editor-authored value needs an ACF
 * field behind it. `role` itself defaults to `field` when omitted, and an
 * explicit `acf:` always wins over the default regardless of role — that is
 * how a `role: query` repeater which still needs projecting children
 * declares itself. This makes the class a no-op for every pre-existing component:
 * no `role:`/`acf:` key anywhere means every field defaults to
 * `role: field` / `acf: true`, so nothing is ever stripped — the single
 * most important compatibility property of this feature (issue #13, "all
 * 40 existing ACF components regenerate byte-identically").
 *
 * ## Inheritance is axis-B-only (rule 5)
 *
 * A descendant inherits its ancestor's `role` when it declares none of its
 * own. Axis A is NOT inherited — each field's own `acf:` (explicit or
 * defaulted from ITS OWN role) stands alone. A non-projecting field nested
 * inside a projecting ancestor is legal (and doesn't disturb sibling key
 * derivation — keys derive from the name-chain, not position). The only
 * combination rejected outright is the incoherent one: a field that wants
 * to project (`acf: true`, whether explicit or defaulted) nested under an
 * ancestor that does not project — the ancestor will not exist in
 * acf.json to hold it. A `role: query` repeater that needs projecting
 * children must therefore carry `acf: true` on itself.
 *
 * ## Empty projecting containers (rule 9)
 *
 * A `group`/`repeater` that projects but ends up with zero projecting
 * children after filtering is dropped entirely — an ACF group/repeater
 * with no sub-fields is as meaningless as an empty top-level field group
 * (rule 3's root-level analogue). The same policy applies per-layout
 * inside a `flexible_content` field: an individual layout left with zero
 * projecting fields is dropped from `layouts`; if that empties `layouts`
 * altogether, the whole flexible_content field is dropped too.
 */
final class FieldProjectionFilter
{
    private const VALID_ROLES = ['field', 'query', 'global', 'parent', 'inherited', 'derived'];

    /** @var array<string,bool> role => default `acf:` value */
    private const ROLE_ACF_DEFAULTS = [
        'field' => true,
        'query' => false,
        'global' => false,
        'parent' => false,
        'inherited' => false,
        'derived' => false,
    ];

    /**
     * @param array<string,mixed> $fields name => field definition, one level
     * @param list<string> $pathChain
     * @return array<string,mixed> the same shape, containing only the projecting subtree
     */
    public function filterProjecting(
        array $fields,
        array $pathChain = [],
        string $inheritedRole = 'field',
        bool $ancestorProjects = true,
    ): array {
        $result = [];

        foreach ($fields as $name => $field) {
            $field = (array) $field;
            $chain = [...$pathChain, (string) $name];
            $role = $this->resolveRole($field, $inheritedRole, $chain);
            $projects = $this->resolveProjects($field, $role, $chain);

            if ($projects && !$ancestorProjects) {
                throw new GenerationValidationException(sprintf(
                    "Field '%s' projects into acf.json (`acf: true`, %s), but an ancestor does not. "
                    . 'A field cannot climb back out of a non-projecting ancestor — the ancestor has no '
                    . 'ACF representation for it to attach to. Set `acf: false` on this field (it will '
                    . 'then correctly be stripped along with its ancestor), or make the ancestor project '
                    . '(`acf: true`) if that is what is actually meant.',
                    implode('.', $chain),
                    array_key_exists('acf', $field) ? 'explicit' : "defaulted from role '{$role}'",
                ));
            }

            if (!$projects) {
                // Dropped from the OUTPUT entirely. Still recurse for
                // VALIDATION ONLY (rule 5's incoherent case can occur at any
                // depth under a non-projecting field) — discard the
                // (always-empty) return value.
                $this->recurseForValidationOnly($field, $chain, $role);
                continue;
            }

            $newField = $field;

            if (!empty($field['fields']) && is_array($field['fields'])) {
                $filteredChildren = $this->filterProjecting((array) $field['fields'], $chain, $role, true);
                $type = (string) ($field['type'] ?? '');
                if ([] === $filteredChildren && in_array($type, ['group', 'repeater'], true)) {
                    // Rule 9 — a projecting container with zero projecting
                    // children is dropped entirely, same as if it never
                    // projected in the first place.
                    continue;
                }
                $newField['fields'] = $filteredChildren;
            }

            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                $filteredLayouts = [];
                foreach ((array) $field['layouts'] as $layoutName => $layoutDef) {
                    $layoutDef = (array) $layoutDef;
                    $layoutChain = [...$chain, (string) $layoutName];
                    $filteredLayoutFields = $this->filterProjecting(
                        (array) ($layoutDef['fields'] ?? []),
                        $layoutChain,
                        $role,
                        true,
                    );
                    if ([] === $filteredLayoutFields) {
                        // Rule 9 — an individual layout left with zero
                        // projecting fields is dropped from `layouts`.
                        continue;
                    }
                    $layoutDef['fields'] = $filteredLayoutFields;
                    $filteredLayouts[$layoutName] = $layoutDef;
                }
                if ([] === $filteredLayouts) {
                    // Every layout emptied out — the whole flexible_content
                    // field is now as meaningless as an empty group.
                    continue;
                }
                $newField['layouts'] = $filteredLayouts;
            }

            $result[$name] = $newField;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<string> $chain
     */
    private function recurseForValidationOnly(array $field, array $chain, string $role): void
    {
        if (!empty($field['fields']) && is_array($field['fields'])) {
            $this->filterProjecting((array) $field['fields'], $chain, $role, false);
        }
        if (!empty($field['layouts']) && is_array($field['layouts'])) {
            foreach ((array) $field['layouts'] as $layoutName => $layoutDef) {
                $layoutDef = (array) $layoutDef;
                if (!empty($layoutDef['fields']) && is_array($layoutDef['fields'])) {
                    $this->filterProjecting(
                        (array) $layoutDef['fields'],
                        [...$chain, (string) $layoutName],
                        $role,
                        false,
                    );
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param list<string> $chain
     */
    private function resolveRole(array $field, string $inheritedRole, array $chain): string
    {
        $declared = $field['role'] ?? null;

        if (null === $declared) {
            return $inheritedRole;
        }

        if ('computed' === $declared) {
            throw new GenerationValidationException(sprintf(
                "Field '%s' sets `role: computed`, which was removed in issue #27. Every instance found "
                . 'across ~200 components turned out to be database-backed, so use `role: query` — and if '
                . 'the value is built by the field-formatting layer out of a sibling field instead, that is '
                . '`role: derived` with `from:` naming the sibling.',
                implode('.', $chain),
            ));
        }

        if (!is_string($declared) || !in_array($declared, self::VALID_ROLES, true)) {
            throw new GenerationValidationException(sprintf(
                "Field '%s' sets `role: %s`, which is not one of the supported values (%s).",
                implode('.', $chain),
                is_scalar($declared) ? (string) $declared : get_debug_type($declared),
                implode(', ', self::VALID_ROLES),
            ));
        }

        return $declared;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<string> $chain
     */
    private function resolveProjects(array $field, string $role, array $chain): bool
    {
        if (array_key_exists('acf', $field)) {
            $declared = $field['acf'];
            if (!is_bool($declared)) {
                throw new GenerationValidationException(sprintf(
                    "Field '%s' sets `acf: %s`, which must be a boolean (true/false).",
                    implode('.', $chain),
                    is_scalar($declared) ? (string) $declared : get_debug_type($declared),
                ));
            }
            return $declared;
        }

        return self::ROLE_ACF_DEFAULTS[$role] ?? true;
    }
}
