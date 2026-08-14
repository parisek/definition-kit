<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

/**
 * What a component's twig reads, plus everything the extractor could not
 * answer (issue #27, phase 3).
 *
 * The two halves are deliberately separate. `reads` is what the contract
 * check compares against a definition; `notes` is what the check must NOT
 * treat as "nothing found here". A static extractor over a dynamic template
 * language has real limits, and a limit reported is a limit; a limit swallowed
 * looks exactly like a clean component.
 */
final class PropReads
{
    /**
     * @param list<string> $reads dotted paths relative to `content`, e.g. `title`, `items.value`
     * @param list<array{kind: string, detail: string}> $notes what could not be resolved
     * @param list<array{path: string, literal: string}> $comparisons `<path> == '<literal>'`/`!=` reads
     *   against a string constant, statically resolvable without evaluating the template
     */
    public function __construct(
        public readonly array $reads,
        public readonly array $notes = [],
        public readonly array $comparisons = [],
    ) {
    }

    public function isFullyAnalysed(): bool
    {
        return [] === $this->notes;
    }

    /** @return list<string> */
    public function noteKinds(): array
    {
        return array_values(array_unique(array_column($this->notes, 'kind')));
    }
}
