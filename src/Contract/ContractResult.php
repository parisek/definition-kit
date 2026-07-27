<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

/**
 * The outcome of checking one component's input contract (issue #27, phase 5).
 *
 * Four states, and the distinction between the last two is the whole point:
 *
 * - `typed`     — every declared field carries a role, and every prop the twig
 *                 reads is accounted for. This one really passed.
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

    public function isFailure(): bool
    {
        return self::VIOLATIONS === $this->status;
    }
}
