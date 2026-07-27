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

    /** @var list<array{kind: string, detail: string}> */
    public array $notes = [];

    /** @var array<string,string> variable name => the content path it stands for */
    public array $aliases = [];

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
