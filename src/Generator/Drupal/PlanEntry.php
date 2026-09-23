<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

/**
 * One config entity in a {@see Plan}: what the generator does with it and
 * why. `data` is the whole entity as it will be written, for CREATE and
 * UPDATE.
 */
final class PlanEntry
{
    public const REUSE = 'REUSE';
    public const CREATE = 'CREATE';
    public const UPDATE = 'UPDATE';
    public const REFUSE = 'REFUSE';

    /**
     * @param list<string> $reasons what changed (UPDATE) or why not (REFUSE)
     * @param array<string,mixed>|null $data
     */
    public function __construct(
        public readonly string $action,
        public readonly string $name,
        public readonly array $reasons = [],
        public readonly ?array $data = null,
    ) {
    }

    public function writes(): bool
    {
        return self::CREATE === $this->action || self::UPDATE === $this->action;
    }

    public function line(): string
    {
        $line = str_pad($this->action, 6) . ' ' . $this->name;
        if ([] !== $this->reasons) {
            $line .= self::REFUSE === $this->action
                ? ': ' . implode('; ', $this->reasons)
                : ' (' . implode(', ', $this->reasons) . ')';
        }

        return $line;
    }
}
