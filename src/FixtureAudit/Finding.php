<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

/**
 * One reported line — findings #2-#5 of the fixture-coverage audit (design
 * doc §5/§6). Finding #1 (`undeclared-prop`) stays owned by `ContractLinter`
 * and never produces one of these.
 */
final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $severity,
        public readonly string $kind,
        public readonly string $path,
        public readonly string $detail,
        public readonly string $component,
    ) {
    }
}
