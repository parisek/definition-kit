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
     * `<field>.acf_fc_layout` read where `<field>` is declared but is not
     * `type: flexible_content` (issue #63). ACF only puts the discriminator
     * on flexible_content rows, so the comparison this key feeds is always
     * false and the branch it guards can never render — a real defect, and a
     * different one from "no role accounts for this prop": the prop cannot
     * be declared into existence, because the type it is read from does not
     * carry it.
     */
    public const NOTE_IMPOSSIBLE_DISCRIMINATOR = 'impossible-acf-fc-layout-read';

    /**
     * `<field>.acf_fc_layout == '<literal>'` where `<field>` is
     * `type: flexible_content` and `<literal>` matches none of its declared
     * `layouts:` keys (issue #63, part 3). The branch this comparison guards
     * can never be taken — the definition already lists every layout that
     * can occur, so a literal outside that set is dead code the checker can
     * name without evaluating the template.
     */
    public const NOTE_DEAD_LAYOUT_LITERAL = 'dead-layout-literal';

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
        if (self::VIOLATIONS === $this->status) {
            return true;
        }

        $kinds = $this->noteKinds();

        return in_array(self::NOTE_UNRESOLVED_FORWARD, $kinds, true)
            || in_array(self::NOTE_IMPOSSIBLE_DISCRIMINATOR, $kinds, true)
            || in_array(self::NOTE_DEAD_LAYOUT_LITERAL, $kinds, true);
    }
}
