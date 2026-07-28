<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Symfony\Component\Yaml\Yaml;

/**
 * Resolves `of: component:<slug>[#<field>]` — a prop whose shape is another
 * component's input, not this one's (issue #27).
 *
 * `header.twig` hands `menu` straight to `header-menu` and never looks inside
 * it. A `repeater` had to enumerate `fields:`, so `header.yaml` transcribed the
 * child's item shape — and the transcript drifted: it was missing
 * `attributes.target` and the whole submenu group until review caught it
 * (tailwind-base docs/398-unresolved-roles.md). Four such pairs exist across
 * that repository's 25 components, the largest seven sub-fields deep.
 *
 * `of:` already means "what does this point at", with a `<kind>:<name>`
 * vocabulary (`post:article`, `term:category`, `geo`). A component is a fourth
 * kind of target, so this is a new value in an existing key rather than a new
 * key.
 *
 * ## Why this is cheap
 *
 * A forwarded prop is always non-projecting — nobody edits it, the parent
 * passes it — so `acf.json` generation never sees one. Only the contract check
 * has to understand the reference, which is why this is a resolver and a
 * linter rather than a change to the generator.
 */
final class ComponentShapeResolver
{
    public const PREFIX = 'component:';

    public function __construct(private readonly string $componentsRoot)
    {
    }

    /** The components root a definition lives under: `…/component/<slug>/<slug>.yaml`. */
    public static function forComponentDir(string $componentDir): self
    {
        return new self(dirname(rtrim($componentDir, '/')));
    }

    public static function isComponentTarget(mixed $of): bool
    {
        return is_string($of) && str_starts_with($of, self::PREFIX);
    }

    /**
     * The fields the target declares, or a message saying why it could not be
     * reached. A reference that cannot be resolved is worse than no reference:
     * it asserts a shape nobody can read.
     *
     * @return array{fields: array<string,mixed>|null, error: ?string}
     */
    public function resolve(string $of): array
    {
        if (!self::isComponentTarget($of)) {
            return ['fields' => null, 'error' => null];
        }

        $target = substr($of, strlen(self::PREFIX));
        $hasSuffix = str_contains($target, '#');
        [$slug, $fieldPath] = array_pad(explode('#', $target, 2), 2, null);
        $slug = (string) $slug;

        if ('' === $slug) {
            return ['fields' => null, 'error' => "`of: {$of}` names no component."];
        }

        if ($hasSuffix && '' === (string) $fieldPath) {
            // `component:header-menu#` is a half-finished edit, not a request
            // for the whole component. Treating it as one would resolve to a
            // different shape than the author was reaching for and say nothing.
            return ['fields' => null, 'error' => sprintf(
                '`of: %s` ends in `#` without naming a field. Drop the `#` to borrow the whole '
                . "component's input map, or name the field after it.",
                $of,
            )];
        }

        $path = "{$this->componentsRoot}/{$slug}/{$slug}.yaml";
        if (!is_file($path)) {
            return ['fields' => null, 'error' => sprintf(
                '`of: %s` points at a component with no definition at %s.',
                $of,
                $path,
            )];
        }

        /** @var array<string,mixed> $definition */
        $definition = Yaml::parseFile($path) ?? [];
        $fields = isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];

        if (null === $fieldPath) {
            // The whole component's input map — a prop that IS another
            // component's context, rather than one of its fields.
            if ([] === $fields) {
                return ['fields' => null, 'error' => sprintf(
                    '`of: %s` points at a component that declares no fields — there is no shape to borrow.',
                    $of,
                )];
            }

            return ['fields' => $fields, 'error' => null];
        }

        $level = $fields;
        $walked = [];
        foreach (explode('.', $fieldPath) as $segment) {
            $walked[] = $segment;
            $field = $level[$segment] ?? null;
            if (!is_array($field)) {
                return ['fields' => null, 'error' => sprintf(
                    '`of: %s` points at `%s`, which %s does not declare.',
                    $of,
                    implode('.', $walked),
                    "{$slug}.yaml",
                )];
            }

            $level = isset($field['fields']) && is_array($field['fields']) ? $field['fields'] : [];
        }

        if ([] === $level) {
            return ['fields' => null, 'error' => sprintf(
                '`of: %s` points at `%s`, which declares no fields of its own — there is no shape to borrow.',
                $of,
                $fieldPath,
            )];
        }

        return ['fields' => $level, 'error' => null];
    }
}
