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

    /**
     * A validator for `page/<id>/<id>.yaml` or `doc/<id>/<id>.yaml`. Each is a
     * different type from a component, so it has its own schema file, not a
     * mode in this one.
     *
     * @param 'page'|'doc' $type
     */
    public static function forEntry(string $type): self
    {
        return new self(__DIR__ . "/../../schemas/{$type}.schema.json");
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
        $designErrors = self::designErrors($jsonData);

        if ($result->isValid()) {
            return new ValidationResult([] === $designErrors, $designErrors);
        }

        $error = $result->error();
        if (null === $error) {
            // isValid() is false but error() is null — treat as "no specific
            // violation to report" rather than crash; keeps the same
            // ValidationResult(false, [...]) contract callers rely on.
            return new ValidationResult(false, $designErrors);
        }

        $errors = [];
        $errorFormatter = new ErrorFormatter();
        // formatErrorMessage(), not $error->message(): the raw message keeps
        // opis's {placeholder} tokens, so a missing key read as "The required
        // properties ({missing}) are missing" and never named the key.
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
        return new ValidationResult(false, [...$errors, ...$designErrors]);
    }

    /**
     * The `design:` rules JSON Schema cannot state, or states without a
     * readable message.
     *
     * `uniqueItems` compares whole items, so a duplicate `part` needs this
     * check. The schema also refuses `select` together with `region`, but
     * opis reports that only as "The data must not match schema", so the
     * check names both keys. It runs on every document that has the key,
     * valid or not, and skips any value whose shape the schema already
     * refuses.
     *
     * @param object|array<mixed> $jsonData
     * @return list<array{pointer:string,message:string}>
     */
    private static function designErrors(object|array $jsonData): array
    {
        $design = is_object($jsonData) ? ($jsonData->design ?? null) : null;
        if (!is_array($design)) {
            return [];
        }

        $errors = [];
        $seen = [];
        foreach ($design as $index => $entry) {
            if (!is_object($entry)) {
                continue;
            }
            $part = $entry->part ?? null;
            if (is_string($part) && '' !== $part) {
                if (isset($seen[$part])) {
                    $errors[] = [
                        'pointer' => "/design/{$index}/part",
                        'message' => "Part '{$part}' is already declared at /design/{$seen[$part]}; each part is unique in a definition.",
                    ];
                } else {
                    $seen[$part] = $index;
                }
            }
            $sources = $entry->sources ?? null;
            if (!is_object($sources)) {
                continue;
            }
            foreach (get_object_vars($sources) as $sourceId => $viewports) {
                if (!is_object($viewports)) {
                    continue;
                }
                foreach (get_object_vars($viewports) as $viewportId => $reference) {
                    if (is_object($reference) && property_exists($reference, 'select') && property_exists($reference, 'region')) {
                        $errors[] = [
                            'pointer' => "/design/{$index}/sources/{$sourceId}/{$viewportId}",
                            'message' => "Part '" . (is_string($part) ? $part : '?') . "', source '{$sourceId}', viewport '{$viewportId}': `select` and `region` exclude each other. `select` picks an element of a render or a node tree, `region` an area of an image.",
                        ];
                    }
                }
            }
        }

        return $errors;
    }
}
