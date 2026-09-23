<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/** The per-component outcome of {@see DrupalDriftLinter::lint()}. */
final class DrupalDriftResult
{
    public const OK = 'ok';
    public const DRIFT = 'drift';
    public const SKIP = 'skip';
    public const FAIL = 'fail';

    /**
     * @param list<string> $bundles the paragraph bundles compared, primary first
     * @param list<string> $findings one line per mismatch, `<bundle>: <field path>: <what differs>`
     */
    private function __construct(
        public readonly string $component,
        public readonly string $status,
        public readonly array $bundles,
        public readonly array $findings,
        public readonly ?string $reason,
    ) {
    }

    /**
     * @param list<string> $bundles
     * @param list<string> $findings
     */
    public static function compared(string $component, array $bundles, array $findings): self
    {
        return new self($component, [] === $findings ? self::OK : self::DRIFT, $bundles, $findings, null);
    }

    public static function skip(string $component, string $reason): self
    {
        return new self($component, self::SKIP, [], [], $reason);
    }

    public static function fail(string $component, string $reason): self
    {
        return new self($component, self::FAIL, [], [], $reason);
    }
}
