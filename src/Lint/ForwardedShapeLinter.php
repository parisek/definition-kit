<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Contract\ComponentShapeResolver;

/**
 * Checks that every `of: component:<slug>[#<field>]` resolves (issue #27).
 *
 * Same doctrine as `from:` on `role: derived`: a reference that cannot be
 * followed is worse than no reference, because it asserts a shape nobody can
 * read and looks verified while doing it. Here the failure is louder — the
 * contract check reads the target's fields, so a dangling target means a prop
 * with no declared shape at all, silently.
 *
 * The two failure modes it names apart: the component has no definition, and
 * the component has one but does not declare the field. The second is the
 * likely one in practice, because it is what a rename looks like.
 */
final class ForwardedShapeLinter
{
    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $resolved = realpath($definitionPath);
        $componentDir = dirname(false !== $resolved ? $resolved : $definitionPath);

        return $this->walk(
            $fields,
            [],
            ComponentShapeResolver::forComponentDir($componentDir),
            basename($definitionPath),
        );
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $chain
     * @return list<array{severity: string, message: string}>
     */
    private function walk(array $fields, array $chain, ComponentShapeResolver $shapes, string $file): array
    {
        $findings = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $path = [...$chain, (string) $name];
            $of = $field['of'] ?? null;

            if (ComponentShapeResolver::isComponentTarget($of)) {
                $result = $shapes->resolve((string) $of);
                if (null !== $result['error']) {
                    $findings[] = [
                        'severity' => 'error',
                        'message' => sprintf('%s: `%s` — %s', $file, implode('.', $path), $result['error']),
                    ];
                }
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $findings = [...$findings, ...$this->walk($field['fields'], $path, $shapes, $file)];
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                        $findings = [
                            ...$findings,
                            ...$this->walk($layout['fields'], [...$path, (string) $layoutName], $shapes, $file),
                        ];
                    }
                }
            }
        }

        return $findings;
    }
}
