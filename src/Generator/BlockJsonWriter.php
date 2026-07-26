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

        // PHP cannot tell an empty list from an empty map — both are `[]` —
        // so the emptiness has to be resolved per KEY, never per value shape.
        // ArrayJsonModel::toJsonModel() resolves every empty array to an
        // object, which is right for validation (it satisfies the object
        // schemas) but wrong for writing: `acf.postTypes` is a genuine list
        // and `[]` is its correct empty value. Encoding $jsonModel here
        // flipped it to `{}` in 0.4.0 — see issue #16.
        //
        // So: encode the raw $block, and convert to stdClass only the keys
        // block.json actually defines as objects. The list is explicit
        // because the alternative — inferring from the value — is exactly
        // the ambiguity that caused the regression.
        $json = json_encode(
            self::withObjectValuedKeys($block),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
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

    /**
     * block.json keys whose empty value is a JSON object, not an array.
     * Everything not listed keeps PHP's natural `[]` encoding — notably
     * `acf.postTypes`, `keywords` and `supports.align`, which are lists.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private static function withObjectValuedKeys(array $block): array
    {
        // `example.attributes.data` — a component with no fields still needs
        // `"data": {}` so the inserter preview reads it as an (empty) prop bag.
        if (isset($block['example']['attributes']) && is_array($block['example']['attributes'])
            && array_key_exists('data', $block['example']['attributes'])
            && [] === $block['example']['attributes']['data']
        ) {
            $block['example']['attributes']['data'] = new \stdClass();
        }

        return $block;
    }
}
