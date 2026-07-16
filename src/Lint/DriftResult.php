<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/** The per-component outcome of Lint\DriftLinter::lint() — see that class's docblock for the three states. */
final class DriftResult
{
    private function __construct(
        public readonly string $component,
        public readonly bool $clean,
        /** @var list<string> */
        public readonly array $acfDrift,
        /** @var list<string> */
        public readonly array $blockDrift,
        public readonly ?string $error,
    ) {
    }

    public static function clean(string $component): self
    {
        return new self($component, true, [], [], null);
    }

    /**
     * @param list<string> $acfDrift
     * @param list<string> $blockDrift
     */
    public static function drift(string $component, array $acfDrift, array $blockDrift): self
    {
        return new self($component, false, $acfDrift, $blockDrift, null);
    }

    public static function error(string $component, string $message): self
    {
        return new self($component, false, [], [], $message);
    }
}
