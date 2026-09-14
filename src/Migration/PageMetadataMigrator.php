<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;
use Parisek\DefinitionKit\Support\PageDefinition;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Moves a styleguide page's twig front-comment into `page/<id>/<id>.yaml`.
 *
 * A page has no acf.json, so nothing is derived: the comment IS the
 * definition. It is parsed as YAML, the same way parisek/styleguide parses
 * it, then normalised the way AcfJsonReader normalises a component's root
 * metadata (a comma-separated `usage` becomes a list, an empty string is
 * dropped). The result must validate against page.schema.json before
 * anything is returned, so a caller never writes a YAML the styleguide would
 * read and the schema would refuse.
 */
final class PageMetadataMigrator
{
    private readonly FieldsSchemaValidator $validator;

    public function __construct(?FieldsSchemaValidator $validator = null)
    {
        $this->validator = $validator ?? FieldsSchemaValidator::forPage();
    }

    /**
     * @return array{yaml: string, twig: string} the page YAML (header included)
     *                                           and the twig without its front-comment
     *
     * @throws MigrationValidationException when there is no front-comment, it is
     *                                      not YAML, or it does not validate
     */
    public function migrate(string $twigSource, string $schemaHeader = PageDefinition::SCHEMA_HEADER): array
    {
        // The FIRST comment anywhere, as ComponentParser::parseTwigComment()
        // finds it: a page may open with `{% extends %}` before its metadata.
        if (!preg_match('/\{#(.*?)#\}[ \t]*\r?\n?/s', $twigSource, $m, PREG_OFFSET_CAPTURE)) {
            throw new MigrationValidationException('no front-comment to migrate');
        }

        try {
            $parsed = Yaml::parse(str_replace("\t", "    ", trim($m[1][0])));
        } catch (ParseException $e) {
            throw new MigrationValidationException('front-comment is not YAML: ' . $e->getMessage());
        }
        if (!is_array($parsed) || !isset($parsed['name'])) {
            throw new MigrationValidationException('front-comment has no `name:`');
        }

        $refused = PageDefinition::componentOnlyKeyMessages($parsed);
        if ([] !== $refused) {
            throw new MigrationValidationException(implode('; ', $refused));
        }

        $tree = [];
        foreach ($parsed as $key => $value) {
            if ('' === $value) {
                continue;
            }
            // An unquoted `usage: 404` or `name: 404` parses as an integer;
            // the styleguide reads it as the string it was meant to be.
            if (in_array($key, ['name', 'usage'], true) && (is_int($value) || is_float($value))) {
                $value = (string) $value;
            }
            if ('usage' === $key && is_string($value)) {
                $value = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => '' !== $v));
                if ([] === $value) {
                    continue;
                }
            }
            $tree[$key] = $value;
        }

        $result = $this->validator->validateData(ArrayJsonModel::toJsonModel($tree));
        if (!$result->valid) {
            throw new MigrationValidationException(implode('; ', array_map(
                static fn (array $e): string => "{$e['pointer']}: {$e['message']}",
                $result->errors,
            )));
        }

        return [
            'yaml' => $schemaHeader . "\n" . Yaml::dump($tree, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
            'twig' => substr($twigSource, 0, $m[0][1]) . substr($twigSource, $m[0][1] + strlen($m[0][0])),
        ];
    }
}
