<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Same opis/json-schema validation plumbing as FieldsSchemaValidator,
 * generalized to an explicit, caller-supplied schema path — this dávka
 * validates two different generated artifacts (acf.json, block.json)
 * against two different light output schemas, so a single hardcoded
 * fallback path (FieldsSchemaValidator's own shape) doesn't fit; the
 * mechanism is still shared, not reimplemented.
 */
final class JsonOutputValidator
{
    private readonly string $schemaJson;

    public function __construct(string $schemaPath)
    {
        $contents = file_get_contents($schemaPath);
        if (false === $contents) {
            throw new \RuntimeException("Cannot read schema: {$schemaPath}");
        }
        $this->schemaJson = $contents;
    }

    /** @param object|array<mixed> $jsonData */
    public function validateData(object|array $jsonData): ValidationResult
    {
        $validator = new Validator();
        $validator->setMaxErrors(100);
        $resolver = $validator->resolver();
        if (null === $resolver) {
            throw new \RuntimeException('Opis Validator has no schema resolver — cannot register schema.');
        }
        $resolver->registerRaw($this->schemaJson);
        $result = $validator->validate($jsonData, json_decode($this->schemaJson)->{'$id'});

        if ($result->isValid()) {
            return new ValidationResult(true, []);
        }

        $error = $result->error();
        if (null === $error) {
            // isValid() is false but error() is null — treat as "no specific
            // violation to report" rather than crash; keeps the same
            // ValidationResult(false, [...]) contract callers rely on.
            return new ValidationResult(false, []);
        }

        $errors = [];
        $errorFormatter = new ErrorFormatter();
        // formatErrorMessage() (not $error->message()) is required to get an
        // interpolated string — opis's raw message() carries un-substituted
        // {placeholder} tokens (e.g. "required properties ({missing}) are
        // missing"); formatErrorMessage() fills them in from $error->args().
        $formatted = $errorFormatter->formatKeyed(
            $error,
            static fn ($error) => $errorFormatter->formatErrorMessage($error),
            static fn ($error) => '/' . implode('/', $error->data()->fullPath()),
        );
        foreach ($formatted as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ['pointer' => $pointer, 'message' => $message];
            }
        }
        return new ValidationResult(false, $errors);
    }
}
