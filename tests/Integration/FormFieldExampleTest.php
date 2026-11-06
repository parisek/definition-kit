<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #109. The README example "A form field" must stay valid. The test
 * reads the YAML snippet out of the README, builds a component from it and
 * runs the real CLIs, so a change to the schema or the generator that breaks
 * the example fails here.
 */
final class FormFieldExampleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dk-109-' . uniqid('', true) . '/contact';
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        rmdir(dirname($this->dir));
    }

    /** @return array{0: string, 1: int} */
    private function cli(string $bin, string ...$args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/../../bin/{$bin}");
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }

    /** The first ```yaml block under the "### A form field" heading of the README. */
    private function readmeSnippet(): string
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        self::assertIsString($readme);

        $found = preg_match('/^### A form field\R.*?^```yaml\R(.*?)^```/ms', $readme, $matches);
        self::assertSame(1, $found, 'README.md has no yaml block under "### A form field".');

        return $matches[1];
    }

    private function writeComponent(string $fieldsYaml): string
    {
        $indented = preg_replace('/^/m', '  ', rtrim($fieldsYaml)) ?? '';
        $path = "{$this->dir}/contact.yaml";
        file_put_contents($path, "name: Contact\ncategory: Content\nfields:\n{$indented}\n");

        return $path;
    }

    #[Test]
    public function the_readme_form_field_passes_validate(): void
    {
        $path = $this->writeComponent($this->readmeSnippet());

        [$out, $code] = $this->cli('fields-validate', $path);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("OK   {$path}", $out);
    }

    #[Test]
    public function the_readme_form_field_generates_a_post_object_for_wpforms(): void
    {
        $this->writeComponent($this->readmeSnippet());

        [$out, $code] = $this->cli('fields-generate', $this->dir);

        self::assertSame(0, $code, $out);
        $acf = json_decode((string) file_get_contents("{$this->dir}/acf.json"), true);
        self::assertIsArray($acf);

        $field = $acf['fields'][0] ?? null;
        self::assertIsArray($field);
        self::assertSame('form', $field['name']);
        self::assertSame('post_object', $field['type']);
        self::assertSame(['wpforms'], $field['post_type']);
        self::assertSame('object', $field['return_format']);
        self::assertSame(0, $field['allow_null']);
    }

    #[Test]
    public function the_contact_form_7_variant_in_the_comment_also_generates(): void
    {
        $this->writeComponent(str_replace('post:wpforms', 'post:wpcf7_contact_form', $this->readmeSnippet()));

        [$out, $code] = $this->cli('fields-generate', $this->dir);

        self::assertSame(0, $code, $out);
        $acf = json_decode((string) file_get_contents("{$this->dir}/acf.json"), true);
        self::assertIsArray($acf);
        self::assertSame(['wpcf7_contact_form'], $acf['fields'][0]['post_type'] ?? null);
    }
}
