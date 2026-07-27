<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

/**
 * What `fields-roles` proposes for one component, and what it refuses to guess
 * (issue #27, phase 4).
 *
 * The unresolved list is the visible output, not a footnote. A bootstrap run
 * over a real project leaves roughly one prop in five for a human, and that
 * ratio is a designed property rather than a shortfall: the tool writes what it
 * can evidence and stops.
 */
final class RoleProposal
{
    /**
     * @param array<string,string> $roles dotted prop path => proposed role
     * @param array<string,string> $derivedFrom dotted prop path => the sibling it comes from
     * @param list<string> $unresolved props read with no evidence for any role
     * @param list<string> $baselineProps props omitted because the framework injects them
     * @param array<string,mixed>|null $definition the definition with the proposals applied
     */
    public function __construct(
        public readonly string $component,
        public readonly array $roles = [],
        public readonly array $derivedFrom = [],
        public readonly array $unresolved = [],
        public readonly array $baselineProps = [],
        public readonly ?array $definition = null,
        public readonly ?string $skipped = null,
    ) {
    }

    public function proposedCount(): int
    {
        return count($this->roles);
    }

    public function totalConsidered(): int
    {
        return count($this->roles) + count($this->unresolved);
    }

    public function hasChanges(): bool
    {
        return [] !== $this->roles;
    }
}
