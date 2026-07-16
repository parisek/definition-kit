<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\FieldsYamlWriter;
use Parisek\DefinitionKit\Migration\MigrationValidationException;

final class FieldsYamlWriterTest extends TestCase
{
    public function test_writes_a_valid_tree_and_the_file_parses_back(): void
    {
        $tree = [
            'name' => 'Demo',
            'fields' => ['title' => ['type' => 'text', 'label' => 'Nadpis']],
        ];
        $out = sys_get_temp_dir() . '/writer-test-' . uniqid('', true) . '.fields.yaml';

        (new FieldsYamlWriter())->write($tree, $out);

        self::assertFileExists($out);
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($out);
        self::assertSame('Demo', $parsed['name']);
        self::assertSame('text', $parsed['fields']['title']['type']);
        @unlink($out);
    }

    public function test_invalid_tree_throws_and_writes_nothing(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text']]]; // missing required label
        $out = sys_get_temp_dir() . '/writer-test-' . uniqid('', true) . '.fields.yaml';

        $this->expectException(MigrationValidationException::class);
        try {
            (new FieldsYamlWriter())->write($tree, $out);
        } finally {
            self::assertFileDoesNotExist($out);
        }
    }

    public function test_write_is_atomic_no_leftover_tmp_file_on_success(): void
    {
        $tree = ['name' => 'Demo', 'fields' => ['title' => ['type' => 'text', 'label' => 'Nadpis']]];
        $out = sys_get_temp_dir() . '/writer-test-' . uniqid('', true) . '.fields.yaml';

        (new FieldsYamlWriter())->write($tree, $out);

        self::assertFileDoesNotExist($out . '.tmp');
        @unlink($out);
    }
}
