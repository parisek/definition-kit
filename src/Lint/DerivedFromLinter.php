<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/**
 * Checks that every `role: derived` field's `from:` names a sibling that
 * actually exists (issue #27).
 *
 * `from:` is the whole reason `derived` is a role rather than a shrug. A
 * value produced by the field-formatting layer is otherwise indistinguishable
 * from one nobody managed to classify, so the role is only worth having if
 * the origin it claims is checkable. A dangling `from:` is worse than no
 * annotation at all: it asserts a relationship that is not there and reads as
 * verified.
 *
 * Sibling means the same `fields:` map — the same level, in the same
 * container, in the same flexible-content layout. The field-formatting layer
 * derives across a row, not across the component: `article-video-grid.sources`
 * is built from `video` on the same repeater row. Allowing a `from:` to reach
 * up or down the tree would describe a mechanism that does not exist.
 *
 * The schema already enforces the other half of the pair (`from:` is required
 * by `role: derived` and forbidden without it), so this linter only ever sees
 * well-formed pairs and has one question left to answer.
 */
final class DerivedFromLinter
{
    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $fields = $definition['fields'] ?? [];

        return is_array($fields)
            ? $this->walk($fields, [], basename($definitionPath))
            : [];
    }

    /**
     * @param array<string,mixed> $fields name => field definition, one level
     * @param list<string> $pathChain
     * @return list<array{severity: string, message: string}>
     */
    private function walk(array $fields, array $pathChain, string $file): array
    {
        $findings = [];
        $siblings = array_map(strval(...), array_keys($fields));

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $chain = [...$pathChain, (string) $name];
            $from = $field['from'] ?? null;

            if (is_string($from) && !in_array($from, $siblings, true)) {
                $findings[] = [
                    'severity' => 'error',
                    'message' => sprintf(
                        '%s: `%s` is `role: derived` from `%s`, but no field named `%s` exists alongside '
                        . 'it (siblings: %s). A `from:` names the field the value is built out of, at the '
                        . 'same level — derivation happens across a row, not across the component.',
                        $file,
                        implode('.', $chain),
                        $from,
                        $from,
                        [] === $siblings ? 'none' : implode(', ', $siblings),
                    ),
                ];
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $findings = [...$findings, ...$this->walk($field['fields'], $chain, $file)];
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                        $findings = [
                            ...$findings,
                            ...$this->walk($layout['fields'], [...$chain, (string) $layoutName], $file),
                        ];
                    }
                }
            }
        }

        return $findings;
    }
}
