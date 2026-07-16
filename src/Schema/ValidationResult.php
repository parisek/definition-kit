<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Schema;

final class ValidationResult
{
    /** @param list<array{pointer:string,message:string}> $errors */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
    ) {
    }
}
