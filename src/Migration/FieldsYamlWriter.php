<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a migrated intermediate tree (AcfJsonReader::read()'s output
 * shape) to a `<name>.yaml` file, validating it against dávka 1's
 * schema first. No file is ever written for a tree that wouldn't validate.
 */
final class FieldsYamlWriter
{
    public function __construct(
        private readonly FieldsSchemaValidator $validator = new FieldsSchemaValidator(),
    ) {
    }

    /** @param array<string,mixed> $tree */
    public function write(array $tree, string $outPath): void
    {
        $jsonModel = ArrayJsonModel::toJsonModel($tree);
        $result = $this->validator->validateData($jsonModel);

        if (!$result->valid) {
            $messages = array_map(
                static fn (array $e): string => "{$e['pointer']}: {$e['message']}",
                $result->errors,
            );
            throw new MigrationValidationException(sprintf(
                "Migrated definition failed schema validation for '%s':\n%s",
                $outPath,
                implode("\n", $messages),
            ));
        }

        $yaml = Yaml::dump($tree, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $tmpPath = $outPath . '.tmp';
        if (false === file_put_contents($tmpPath, $yaml)) {
            throw new \RuntimeException("Cannot write temporary file: {$tmpPath}");
        }
        if (!rename($tmpPath, $outPath)) {
            throw new \RuntimeException("Cannot move {$tmpPath} to {$outPath}");
        }
    }
}
