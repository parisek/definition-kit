<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Removes the first `{# ... #}` block from a twig source.
 *
 * Shared by page/doc migration (EntryMetadataMigrator) and component
 * migration (bin/fields-migrate, ComponentCommentMatcher): both retire a
 * front-comment once its content is safely recorded elsewhere, and both need
 * the exact same "first comment block, trailing newline included" match so a
 * stripped twig looks the same regardless of which caller stripped it.
 */
final class TwigFrontCommentStripper
{
    public function hasFrontComment(string $twigSource): bool
    {
        return 1 === preg_match('/\{#(.*?)#\}/s', $twigSource);
    }

    /** Returns $twigSource unchanged when there is no front-comment to strip. */
    public function strip(string $twigSource): string
    {
        if (!preg_match('/\{#(.*?)#\}[ \t]*\r?\n?/s', $twigSource, $m, PREG_OFFSET_CAPTURE)) {
            return $twigSource;
        }

        return substr($twigSource, 0, $m[0][1]) . substr($twigSource, $m[0][1] + strlen($m[0][0]));
    }
}
