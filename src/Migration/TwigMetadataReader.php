<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Extracts ONLY root component metadata (name/usage/category/render/web/
 * asana/figma/drupal/description/weight/responsive) from a twig file's
 * leading `{# ... #}` front-comment block, stopping at the `fields:` line.
 * Every value is handed back as a raw string — type coercion for the
 * non-string metadata keys (`weight`: integer, `responsive`: boolean) is
 * AcfJsonReader's job, not this reader's. Never reads anything under
 * `fields:` — field-level twig annotation content (description/mcp/etc.)
 * is out of scope for this dávka; see AGENTS.md `theme/twig.md` for that
 * annotation's own doctrine.
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
}
