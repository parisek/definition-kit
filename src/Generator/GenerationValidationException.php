<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

/** Thrown when a generated artifact fails output-schema validation — nothing is ever written on failure. */
final class GenerationValidationException extends \RuntimeException
{
}
