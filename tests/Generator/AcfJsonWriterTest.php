<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\AcfJsonWriter;
use Parisek\DefinitionKit\Generator\GenerationValidationException;

final class AcfJsonWriterTest extends TestCase
{
    /** @return array<string,mixed> */
    private function validFieldGroup(): array
    {
        return [
            'key' => 'group_demo',
            'title' => 'Demo',
            'fields' => [
                ['key' => 'field_demo_title', 'name' => 'title', 'type' => 'text', 'label' => 'Nadpis'],
            ],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/demo']]],
            'menu_order' => 0,
            'modified' => 1700000000,
        ];
    }

    public function test_writes_valid_field_group_to_disk(): void
    {
        $outPath = sys_get_temp_dir() . '/acf-json-writer-' . uniqid('', true) . '.json';
        (new AcfJsonWriter())->write($this->validFieldGroup(), $outPath);

        $raw = file_get_contents($outPath);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('group_demo', $decoded['key']);
        @unlink($outPath);
    }

    public function test_throws_and_writes_nothing_for_an_invalid_field_group(): void
    {
        $outPath = sys_get_temp_dir() . '/acf-json-writer-' . uniqid('', true) . '.json';
        $invalid = $this->validFieldGroup();
        unset($invalid['key']);

        try {
            (new AcfJsonWriter())->write($invalid, $outPath);
            self::fail('Expected GenerationValidationException');
        } catch (GenerationValidationException $e) {
            self::assertStringContainsString('key', $e->getMessage());
        }
        self::assertFileDoesNotExist($outPath);
    }

    public function test_output_is_pretty_printed_and_unicode_unescaped(): void
    {
        $outPath = sys_get_temp_dir() . '/acf-json-writer-' . uniqid('', true) . '.json';
        $group = $this->validFieldGroup();
        $group['title'] = 'Nadpis se špičkami';
        (new AcfJsonWriter())->write($group, $outPath);

        $raw = file_get_contents($outPath);
        self::assertIsString($raw);
        self::assertStringContainsString('špičkami', $raw); // not \u-escaped
        self::assertStringContainsString("\n    ", $raw); // pretty-printed, 4-space indent
        @unlink($outPath);
    }
}
