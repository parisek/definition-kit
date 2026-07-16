<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates a `<name>.yaml` component definition document against the
 * canonical JSON Schema.
 *
 * WHY opis + a PHP wrapper: the same document must be validated both from the
 * CLI (author-time) and in-process by later dávky (migration writer, drift-lint)
 * on a tree they build in memory — so validation is exposed as validateData()
 * on a JSON-model tree (stdClass for maps, array for sequences), with
 * validateFile() a thin YAML-loading front door feeding it the same shape.
 */
final class FieldsSchemaValidator
{
    private readonly string $schemaJson;

    public function __construct(?string $schemaPath = null)
    {
        $schemaPath ??= __DIR__ . '/../../schemas/component.fields.schema.json';
        $contents = file_get_contents($schemaPath);
        if (false === $contents) {
            throw new \RuntimeException("Cannot read schema: {$schemaPath}");
        }
        $this->schemaJson = $contents;
    }

    public function validateFile(string $yamlPath): ValidationResult
    {
        if (!is_file($yamlPath) || !is_readable($yamlPath)) {
            return new ValidationResult(false, [['pointer' => '', 'message' => "Cannot read file: {$yamlPath}"]]);
        }

        try {
            // PARSE_OBJECT_FOR_MAP preserves the map-vs-list distinction from the
            // YAML source (mappings, incl. empty `{}`, become stdClass; only real
            // sequences stay arrays) so it can be fed straight to opis without a
            // json_decode(json_encode(...)) round-trip that would collapse `{}` to `[]`.
            $data = Yaml::parseFile($yamlPath, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $e) {
            return new ValidationResult(false, [['pointer' => '', 'message' => $e->getMessage()]]);
        }

        if (!is_object($data) && !is_array($data)) {
            return new ValidationResult(false, [['pointer' => '', 'message' => 'Document is not a YAML mapping.']]);
        }

        return $this->validate($data);
    }

    /**
     * Accepts a JSON-model tree — `stdClass` for mappings, array for sequences
     * (e.g. from `json_decode($json)` or `Yaml::parse($s, Yaml::PARSE_OBJECT_FOR_MAP)`).
     * In-process callers (later dávky) must represent object maps as `stdClass`,
     * mirroring how `validateFile` parses.
     *
     * @param object|array<mixed> $jsonData
     */
    public function validateData(object|array $jsonData): ValidationResult
    {
        return $this->validate($jsonData);
    }

    /** @param object|array<mixed> $jsonData */
    private function validate(object|array $jsonData): ValidationResult
    {
        $validator = new Validator();
        // Default opis behaviour stops at the first error; collect every
        // violation in the document instead so authors see all of them at once.
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
        $formatted = (new ErrorFormatter())->formatKeyed(
            $error,
            static fn ($error) => $error->message(),
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
