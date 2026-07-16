<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Schema\JsonOutputValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;

/** Writes a generated block.json array to disk — same validate-before-write doctrine as AcfJsonWriter. */
final class BlockJsonWriter
{
    private readonly JsonOutputValidator $validator;

    public function __construct(?JsonOutputValidator $validator = null)
    {
        $this->validator = $validator ?? new JsonOutputValidator(
            __DIR__ . '/../../schemas/block.output.schema.json',
        );
    }

    /**
     * @param array<string,mixed> $block
     * @throws GenerationValidationException
     */
    public function write(array $block, string $outPath): void
    {
        $jsonModel = ArrayJsonModel::toJsonModel($block);
        $result = $this->validator->validateData($jsonModel);

        if (!$result->valid) {
            $messages = array_map(
                static fn (array $e): string => "{$e['pointer']}: {$e['message']}",
                $result->errors,
            );
            throw new GenerationValidationException(sprintf(
                "Generated block.json failed structural output validation for '%s':\n%s",
                $outPath,
                implode("\n", $messages),
            ));
        }

        $json = json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException("Cannot JSON-encode generated block for '{$outPath}'");
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
