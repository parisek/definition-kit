<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Schema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Metadata format constraints that the tailwind-base skeleton once enforced in
 * a twig-cs-fixer rule (ComponentMetadataRule). The schema now carries them, so
 * the rule can retire (tailwind-base ADR-0017).
 *
 * - `category`: required and non-empty on a component; optional on a page.
 * - `asana`: absolute http(s) URL on asana.com or a subdomain, case-insensitive.
 *   The rule only searched for the substring; the schema checks the host.
 * - `web`, `drupal`: site-relative path, starts with `/` and not with `//`.
 */
final class MetadataFormatSchemaTest extends TestCase
{
    private static function validate(?string $type, string $yaml): ValidationResult
    {
        $validator = 'page' === $type ? FieldsSchemaValidator::forEntry('page') : new FieldsSchemaValidator();

        return $validator->validateData(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP));
    }

    private static function assertFailsOn(ValidationResult $result, string $key): void
    {
        self::assertFalse($result->valid);
        $hit = array_filter(
            $result->errors,
            static fn (array $e): bool => '/' . $key === $e['pointer'] || str_contains($e['message'], $key),
        );
        self::assertNotEmpty($hit, "no error names '{$key}': " . json_encode($result->errors, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_component_without_category_fails_naming_the_key(): void
    {
        self::assertFailsOn(self::validate(null, "name: Example\nfields: {}\n"), 'category');
    }

    #[Test]
    public function a_component_with_empty_category_fails(): void
    {
        self::assertFailsOn(self::validate(null, "name: Example\ncategory: ''\nfields: {}\n"), 'category');
    }

    #[Test]
    public function a_page_without_category_stays_valid(): void
    {
        self::assertTrue(self::validate('page', 'name: Home')->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function validAsana(): iterable
    {
        yield 'https task' => ['https://app.asana.com/1/1/task/2'];
        yield 'http' => ['http://asana.com/0/1'];
        yield 'uppercase scheme and host' => ['HTTPS://APP.ASANA.COM/0/1'];
        yield 'query string' => ['https://app.asana.com/0/1?focus=true'];
        yield 'bare host' => ['https://asana.com'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAsana(): iterable
    {
        yield 'no scheme' => ['app.asana.com/0/1'];
        yield 'other scheme' => ['ftp://app.asana.com/0/1'];
        yield 'other domain' => ['https://example.com/task/1'];
        yield 'asana.com only in the path' => ['https://example.com/asana.com/task'];
        yield 'asana.com as a host suffix' => ['https://notasana.com/task'];
        yield 'asana.com as userinfo' => ['https://asana.com@example.com/task'];
        yield 'asana.com as a host prefix' => ['https://asana.com.example.com/task'];
        yield 'relative path' => ['/0/1'];
        yield 'empty' => [''];
    }

    /** @return iterable<string, array{string}> */
    public static function validPath(): iterable
    {
        yield 'root' => ['/'];
        yield 'nested' => ['/admin/structure/paragraphs_type/accordion'];
        yield 'query' => ['/faq?x=1'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPath(): iterable
    {
        yield 'absolute URL' => ['https://example.com/faq'];
        yield 'protocol-relative' => ['//example.com/faq'];
        yield 'no leading slash' => ['faq'];
        yield 'empty' => [''];
    }

    /** @return iterable<string, array{?string, string}> */
    public static function entryTypes(): iterable
    {
        yield 'component' => [null, "name: Example\ncategory: Content\nfields: {}\n"];
        yield 'page' => ['page', "name: Home\n"];
    }

    /** @return iterable<string, array{?string, string, string, string, bool}> */
    public static function formatCases(): iterable
    {
        foreach (self::entryTypes() as $typeLabel => [$type, $base]) {
            foreach (self::validAsana() as $label => [$value]) {
                yield "{$typeLabel} asana ok: {$label}" => [$type, $base, 'asana', $value, true];
            }
            foreach (self::invalidAsana() as $label => [$value]) {
                yield "{$typeLabel} asana bad: {$label}" => [$type, $base, 'asana', $value, false];
            }
            foreach (['web', 'drupal'] as $key) {
                foreach (self::validPath() as $label => [$value]) {
                    yield "{$typeLabel} {$key} ok: {$label}" => [$type, $base, $key, $value, true];
                }
                foreach (self::invalidPath() as $label => [$value]) {
                    yield "{$typeLabel} {$key} bad: {$label}" => [$type, $base, $key, $value, false];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('formatCases')]
    public function metadata_formats_are_enforced(?string $type, string $base, string $key, string $value, bool $valid): void
    {
        $result = self::validate($type, $base . $key . ': ' . json_encode($value, JSON_THROW_ON_ERROR) . "\n");

        if ($valid) {
            self::assertTrue($result->valid, json_encode($result->errors, JSON_THROW_ON_ERROR));
        } else {
            self::assertFailsOn($result, $key);
        }
    }
}
