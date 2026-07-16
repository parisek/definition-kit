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
}
