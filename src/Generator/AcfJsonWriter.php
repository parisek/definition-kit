<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Schema\JsonOutputValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;

/**
 * Writes a generated ACF field-group array (FieldsGenerator::generate()'s
 * output) to disk as acf.json, validating against the light structural
 * output schema first — mirrors Migration\FieldsYamlWriter's doctrine:
 * no file is ever written for a tree that wouldn't validate.
 */
final class AcfJsonWriter
{
    private readonly JsonOutputValidator $validator;

    public function __construct(?JsonOutputValidator $validator = null)
    {
        $this->validator = $validator ?? new JsonOutputValidator(
            __DIR__ . '/../../schemas/acf-field-group.output.schema.json',
        );
    }

    /**
     * @param array<string,mixed> $fieldGroup
     * @throws GenerationValidationException
     */
    public function write(array $fieldGroup, string $outPath): void
    {
        $jsonModel = ArrayJsonModel::toJsonModel($fieldGroup);
        $result = $this->validator->validateData($jsonModel);

        if (!$result->valid) {
            $messages = array_map(
                static fn (array $e): string => "{$e['pointer']}: {$e['message']}",
                $result->errors,
            );
            throw new GenerationValidationException(sprintf(
                "Generated acf.json failed structural output validation for '%s':\n%s",
                $outPath,
                implode("\n", $messages),
            ));
        }

        $json = json_encode($fieldGroup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException("Cannot JSON-encode generated field group for '{$outPath}'");
        }

        $tmpPath = $outPath . '.tmp';
        if (false === file_put_contents($tmpPath, $json . "\n")) {
            throw new \RuntimeException("Cannot write temporary file: {$tmpPath}");
        }
        if (!rename($tmpPath, $outPath)) {
            throw new \RuntimeException("Cannot move {$tmpPath} to {$outPath}");
        }
    }
}
