<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

/**
 * The outcome of checking one component's input contract (issue #27, phase 5).
 *
 * Four states, and the distinction between the last two is the whole point:
 *
 * - `typed`     — every declared field carries a role, and every prop the twig
 *                 reads is accounted for. It still fails the run if it carries
 *                 a broken `of:` target: nothing was read through the dangling
 *                 reference, but the definition asserts a shape that is not
 *                 there, and that is a defect wherever it sits.
 * - `violations`— typed, and reads something no role accounts for.
 * - `untyped`   — the definition has not been through the vocabulary yet. NOT
 *                 a pass. Reported as untyped so a fleet-wide run says how much
 *                 is actually covered.
 * - `unanalysed`— the twig could not be read statically. Also not a pass.
 */
final class ContractResult
{
    public const TYPED = 'typed';
    public const VIOLATIONS = 'violations';
    public const UNTYPED = 'untyped';
    public const UNANALYSED = 'unanalysed';

    /**
     * An `of:` target that does not resolve. It lives here rather than on the
     * linter because `isFailure()` has to know it, and a value object reaching
     * into its producer for a constant is a cycle waiting to be tripped over.
     */
    public const NOTE_UNRESOLVED_FORWARD = 'unresolved-forwarded-shape';

    /**
     * @param list<string> $violations props read but accounted for by nothing
     * @param list<array{kind: string, detail: string}> $notes limits the extractor hit
     */
    public function __construct(
        public readonly string $component,
        public readonly string $status,
        public readonly array $violations = [],
        public readonly array $notes = [],
        public readonly ?string $reason = null,
    ) {
    }

    /** @return list<string> */
    public function noteKinds(): array
    {
        return array_values(array_unique(array_column($this->notes, 'kind')));
    }

    /**
     * A definition asserting a shape that is not there is a defect, not a
     * caveat: the check reads the target's fields, so an unreachable target
     * leaves the prop with no declared shape at all. Reporting it as a note
     * and exiting zero is how a broken reference survives CI.
     */
    public function isFailure(): bool
    {
        return self::VIOLATIONS === $this->status
            || in_array(self::NOTE_UNRESOLVED_FORWARD, $this->noteKinds(), true);
    }
}
