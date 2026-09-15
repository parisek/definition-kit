<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Extracts ONLY root component metadata (name/usage/category/kind/render/web/
 * asana/figma/drupal/description/weight/responsive) from a twig file's
 * leading `{# ... #}` front-comment block, stopping at the `fields:` line.
 * Every value is handed back as a raw string — type coercion for the
 * non-string metadata keys (`weight`: integer, `responsive`: boolean) is
 * AcfJsonReader's job, not this reader's.
 *
 * `read()` never reads anything under `fields:` — that annotation shape
 * (tab-indented, nested) is a different animal and has its own reader,
 * `readFields()` below, used only when a component has no acf.json to
 * derive from (see AGENTS.md `theme/twig.md` for the annotation's own
 * authoring doctrine).
 */
final class TwigMetadataReader
{
    /** @return array<string,string> */
    public function read(string $twigSource): array
    {
        if (!preg_match('/^(?:\xEF\xBB\xBF)?\s*\{#(.*?)#\}/s', $twigSource, $m)) {
            return [];
        }

        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^fields:\s*$/', $line)) {
                break;
            }
            if (!preg_match('/^([a-zA-Z_0-9]+):[ \t]?(.*)$/', $line, $mm)) {
                continue;
            }
            $val = trim($mm[2]);
            if ('' === $val) {
                continue;
            }
            if (strlen($val) >= 2 && '"' === $val[0] && '"' === $val[-1]) {
                $val = substr($val, 1, -1);
            }
            $meta[trim($mm[1])] = $val;
        }
        return $meta;
    }

    /**
     * Extracts the `fields:` sub-tree of the front-comment (the twig
     * component authoring convention documented by tailwind-base's
     * `update-fields` skill), as a raw nested array: field name => raw
     * annotation (`title`/`type`/`required`/`description`/`options`/
     * `choices`/`fields`, tab-indented YAML). Returns `[]` when there is no
     * front-comment, no top-level `fields:` line, or the block fails to
     * parse as YAML — a malformed annotation is reported by the caller
     * (which knows the component name), not swallowed here.
     *
     * @return array<string,array<string,mixed>>
     *
     * @throws MigrationValidationException when a `fields:` line exists but
     *                                      the block beneath it is not valid YAML
     */
    public function readFields(string $twigSource): array
    {
        if (!preg_match('/^(?:\xEF\xBB\xBF)?\s*\{#(.*?)#\}/s', $twigSource, $m)) {
            return [];
        }

        $comment = $m[1];
        // Matches the whole rest of the `fields:` line too (not just a bare
        // `fields:` with nothing after it) — a malformed one-liner like
        // `fields: not-a-map` must still be found and reach the YAML-shape
        // check below, rather than being invisible to this reader entirely
        // and read back as "no annotation at all" (Codex review round 2,
        // finding 4).
        if (!preg_match('/^fields:.*$/m', $comment, $fieldsMatch, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // `fields:` is tab-indented in the twig authoring convention (see
        // update-fields SKILL.md § Output Format); Symfony's YAML parser
        // rejects tabs outright, so they're normalised to spaces first —
        // the same trick EntryMetadataMigrator uses for the same reason.
        $block = str_replace("\t", '    ', substr($comment, $fieldsMatch[0][1]));

        try {
            $parsed = Yaml::parse($block);
        } catch (ParseException $e) {
            throw new MigrationValidationException('fields: block is not valid YAML: ' . $e->getMessage());
        }

        if (!is_array($parsed) || !array_key_exists('fields', $parsed)) {
            return [];
        }

        // Codex review round 2, finding 4: `fields: null` (a bare key) is
        // the common "explicitly no fields" shape and is treated as empty,
        // same as an absent `fields:` line. But `fields: some text` or a
        // scalar of any other kind is a malformed annotation, not an empty
        // one — silently returning `[]` for it hid the mistake behind the
        // exact same output as "nothing to migrate".
        if (null === $parsed['fields']) {
            return [];
        }
        if (!is_array($parsed['fields'])) {
            throw new MigrationValidationException(sprintf(
                'fields: is present but is not a map (got %s) — expected field name => annotation pairs.',
                get_debug_type($parsed['fields']),
            ));
        }

        /** @var array<string,array<string,mixed>> $fields */
        $fields = $parsed['fields'];
        return $fields;
    }
}
