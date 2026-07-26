<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Generator\BlockJsonWriter;
use Parisek\DefinitionKit\Generator\GenerationValidationException;

final class BlockJsonWriterTest extends TestCase
{
    public function test_writes_valid_block_json_to_disk(): void
    {
        $block = (new BlockJsonGenerator())->generate(['name' => 'Demo'], 'demo');
        $outPath = sys_get_temp_dir() . '/block-json-writer-' . uniqid('', true) . '.json';

        (new BlockJsonWriter())->write($block, $outPath);
        $raw = file_get_contents($outPath);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('acf/demo', $decoded['name']);
        @unlink($outPath);
    }

    public function test_throws_and_writes_nothing_for_an_invalid_block(): void
    {
        $block = (new BlockJsonGenerator())->generate(['name' => 'Demo'], 'demo');
        unset($block['name']); // now fails the ^acf/ pattern requirement (missing entirely)
        $outPath = sys_get_temp_dir() . '/block-json-writer-' . uniqid('', true) . '.json';

        try {
            (new BlockJsonWriter())->write($block, $outPath);
            self::fail('Expected GenerationValidationException');
        } catch (GenerationValidationException) {
            // expected
        }
        self::assertFileDoesNotExist($outPath);
    }

    /**
     * Regression for issue #16. PHP cannot tell an empty list from an empty
     * map — both are `[]` — so 0.4.0's blanket "encode every empty array as
     * an object" flipped `acf.postTypes` from `[]` to `{}` on the 18 real
     * components across the downstream fleet whose postTypes is empty.
     *
     * Both directions are pinned here deliberately: fixing one polarity by
     * breaking the other is exactly how the regression arose.
     */
    public function test_empty_postTypes_stays_an_array_and_empty_example_data_stays_an_object(): void
    {
        $block = (new BlockJsonGenerator())->generate(['name' => 'Demo'], 'demo');
        $block['acf']['postTypes'] = [];
        $block['example'] = ['viewportWidth' => 1280, 'attributes' => ['align' => 'full', 'data' => []]];

        $outPath = sys_get_temp_dir() . '/block-json-writer-' . uniqid('', true) . '.json';
        (new BlockJsonWriter())->write($block, $outPath);
        $raw = (string) file_get_contents($outPath);
        @unlink($outPath);

        self::assertStringContainsString('"postTypes": []', $raw, 'postTypes is a list; its empty value must stay []');
        self::assertStringContainsString('"data": {}', $raw, 'example.attributes.data is an object; its empty value must stay {}');
    }
}
