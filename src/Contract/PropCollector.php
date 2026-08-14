<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Twig\Node\ModuleNode;

/**
 * Mutable accumulator for one extraction run (issue #27, phase 3).
 *
 * Reads and notes accumulate across a template and every one-level include it
 * pulls in. `{% set %}` aliases live here rather than in the walker's scoped
 * bindings because Twig's `set` is scoped to the rest of the template, not to
 * a block — an alias declared once is in force for everything the walker
 * visits afterwards.
 *
 * @internal to TwigPropExtractor
 */
final class PropCollector
{
    /** @var list<string> */
    public array $reads = [];

    /**
     * `<path> == '<literal>'` comparisons against a string constant, keyed
     * by nothing — order of encounter, duplicates kept out by the caller
     * (`!=` is deliberately not captured — see `TwigPropExtractor::walk()`).
     * Feeds the flexible_content layout-literal check: the
     * definition already lists the valid `layouts:` keys, so a literal that
     * matches none of them is dead code the parser can name without
     * evaluating the template.
     *
     * @var list<array{path: string, literal: string}>
     */
    public array $comparisons = [];

    /** @var list<array{kind: string, detail: string}> */
    public array $notes = [];

    /** @var array<string,string> variable name => the content path it stands for */
    public array $aliases = [];

    /**
     * `{% import "…" as x %}` bindings: the bound name => the template path
     * it names. Module-scoped, same rationale as $aliases.
     *
     * @var array<string,string>
     */
    public array $macroImports = [];

    /**
     * `{% from "…" import a, b as c %}` bindings, keyed by the object
     * identity (`spl_object_id`) of the per-statement internal
     * `TemplateVariable` node Twig's own parser threads onto the compiled
     * `MacroReferenceExpression`'s `template` slot for that alias
     * (`FromTokenParser` creates one `TemplateVariable` per `from`
     * statement and `FunctionExpressionParser` reuses that exact object
     * for every call through the alias).
     *
     * Twig resolves a `from`-imported macro call to a *specific* `from`
     * statement this way — by object identity, not by name or encounter
     * order — which is the only sound way to tell apart two `from`
     * imports of the same macro short name (e.g. `card` from two
     * different templates, one aliased). Trying candidates in encounter
     * order, as this used to, can silently follow the wrong template.
     *
     * @var array<int,string>
     */
    public array $macroFromImportsByRef = [];

    /**
     * Embedded modules by index, so an `{% embed %}` can be paired with the
     * module Twig parsed its body into. The embed node carries the `only`
     * flag; the path of the template being embedded is on the module.
     *
     * @var array<int,ModuleNode>
     */
    public array $embeddedModules = [];

    public function read(string $path): void
    {
        $this->reads[] = $path;
    }

    public function compare(string $path, string $literal): void
    {
        foreach ($this->comparisons as $existing) {
            if ($existing['path'] === $path && $existing['literal'] === $literal) {
                return;
            }
        }

        $this->comparisons[] = ['path' => $path, 'literal' => $literal];
    }

    public function note(string $kind, string $detail): void
    {
        foreach ($this->notes as $existing) {
            if ($existing['kind'] === $kind && $existing['detail'] === $detail) {
                return;
            }
        }

        $this->notes[] = ['kind' => $kind, 'detail' => $detail];
    }

    public function bind(string $name, string $path): void
    {
        $this->aliases[$name] = $path;
    }

    /**
     * @param array<string,string> $scoped loop bindings, which win over aliases
     * @return array<string,string>
     */
    public function bindings(array $scoped): array
    {
        return [...$this->aliases, ...$scoped];
    }
}
