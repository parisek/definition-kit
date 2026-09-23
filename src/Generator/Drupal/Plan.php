<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

/**
 * The generator's plan for a config export (ADR 0002): one entry per config
 * entity it looked at, in the order it looked at them. A plan with a REFUSE
 * entry is not written at all.
 */
final class Plan
{
    /** @param list<PlanEntry> $entries */
    public function __construct(public readonly array $entries)
    {
    }

    public function refused(): bool
    {
        return [] !== $this->withAction(PlanEntry::REFUSE);
    }

    /** @return list<PlanEntry> */
    public function withAction(string $action): array
    {
        return array_values(array_filter($this->entries, static fn (PlanEntry $e): bool => $e->action === $action));
    }

    public function entry(string $name): ?PlanEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Config names the plan creates or changes, for `--names-out`.
     *
     * @return list<string>
     */
    public function changedNames(): array
    {
        return array_values(array_map(
            static fn (PlanEntry $e): string => $e->name,
            array_filter($this->entries, static fn (PlanEntry $e): bool => $e->writes()),
        ));
    }

    public function summary(): string
    {
        return sprintf(
            '%d create, %d update, %d reuse, %d refuse',
            count($this->withAction(PlanEntry::CREATE)),
            count($this->withAction(PlanEntry::UPDATE)),
            count($this->withAction(PlanEntry::REUSE)),
            count($this->withAction(PlanEntry::REFUSE)),
        );
    }
}
