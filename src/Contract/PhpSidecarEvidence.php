<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

/**
 * Reads a component's `.php` sidecar for props it assigns onto the render
 * context, and what evidence there is for where each one came from
 * (issue #27, phase 4).
 *
 * Uses PHP's own lexer (`token_get_all`), not regexes — same doctrine as the
 * Twig extractor, and for the same reason: this codebase lost a day to three
 * silent regex-parsing bugs, each of which shipped because the failing shape is
 * one nobody writes by hand.
 *
 * A sidecar rarely queries inline. The shape it actually has is
 *
 *     $query = new WP_Query([...]);
 *     $content['items'] = $query->posts;
 *
 * so the statement doing the assigning carries no marker at all. Evidence is
 * therefore carried forward one hop: a variable assigned from a query is a
 * query source, and a statement reading one is query-backed. One hop, not a
 * general dataflow analysis — enough for the shape sidecars are written in, and
 * shallow enough to stay explainable.
 *
 * It classifies conservatively. Anything it cannot attribute is left
 * unclassified, because "this PHP file assigns it" says the value is computed
 * somewhere and nothing about where from. `computed` used to be the home for
 * that shrug and was removed in #27 precisely because it never described a
 * real case.
 */
final class PhpSidecarEvidence
{
    /** Assignment targets that mean "this goes into the component's render context". */
    private const CONTEXT_VARIABLES = ['$content', '$data', '$context'];

    private const QUERY_MARKERS = [
        'WP_Query', 'get_posts', 'get_children', 'get_terms', 'get_term',
        'wp_get_recent_posts', 'get_the_terms', 'get_post', 'get_pages',
    ];

    private const GLOBAL_MARKERS = ['option', 'formatFields', 'get_option', 'theme_option'];

    /**
     * prop name => 'query' | 'global' | null (assigned, but by nothing this can name)
     *
     * @return array<string,?string>
     */
    public function evidence(string $phpPath): array
    {
        $source = is_file($phpPath) ? file_get_contents($phpPath) : false;
        if (false === $source) {
            return [];
        }

        $found = [];
        /** @var array<string,string> $variableSources variable name => 'query'|'global' */
        $variableSources = [];

        foreach ($this->statements(token_get_all($source)) as $statement) {
            $classification = $this->classify($statement['text'], $variableSources);

            $prop = $statement['contextProp'];
            if (null !== $prop) {
                // A prop assigned twice keeps the evidence that names a
                // source: once query-sourced, always query-sourced.
                if (!array_key_exists($prop, $found) || (null === $found[$prop] && null !== $classification)) {
                    $found[$prop] = $classification;
                }

                continue;
            }

            if (null !== $statement['assignedVariable'] && null !== $classification) {
                $variableSources[$statement['assignedVariable']] = $classification;
            }
        }

        return $found;
    }

    /**
     * @param list<array{int,string,int}|string> $tokens
     * @return list<array{text: string, contextProp: ?string, assignedVariable: ?string}>
     */
    private function statements(array $tokens): array
    {
        $statements = [];
        $current = [];

        foreach ($tokens as $token) {
            if (';' === $token) {
                $statements[] = $this->describe($current);
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        if ([] !== $current) {
            $statements[] = $this->describe($current);
        }

        return $statements;
    }

    /**
     * @param list<array{int,string,int}|string> $tokens one statement
     * @return array{text: string, contextProp: ?string, assignedVariable: ?string}
     */
    private function describe(array $tokens): array
    {
        $text = '';
        foreach ($tokens as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        // Whitespace, the opening tag and comments all sit in front of the
        // first statement and would shift every offset below by one.
        $noise = [T_WHITESPACE, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML, T_COMMENT, T_DOC_COMMENT];
        $significant = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], $noise, true),
        ));

        $first = $significant[0] ?? null;
        if (!is_array($first) || T_VARIABLE !== $first[0]) {
            return ['text' => $text, 'contextProp' => null, 'assignedVariable' => null];
        }

        // `$content['x'] = …`
        if (
            in_array($first[1], self::CONTEXT_VARIABLES, true)
            && '[' === ($significant[1] ?? null)
            && is_array($significant[2] ?? null)
            && T_CONSTANT_ENCAPSED_STRING === $significant[2][0]
            && ']' === ($significant[3] ?? null)
            && '=' === ($significant[4] ?? null)
        ) {
            return [
                'text' => $text,
                'contextProp' => trim($significant[2][1], "'\""),
                'assignedVariable' => null,
            ];
        }

        // `$query = …`
        if ('=' === ($significant[1] ?? null)) {
            return ['text' => $text, 'contextProp' => null, 'assignedVariable' => $first[1]];
        }

        return ['text' => $text, 'contextProp' => null, 'assignedVariable' => null];
    }

    /** @param array<string,string> $variableSources */
    private function classify(string $text, array $variableSources): ?string
    {
        foreach (self::GLOBAL_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return 'global';
            }
        }

        foreach (self::QUERY_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return 'query';
            }
        }

        foreach ($variableSources as $variable => $source) {
            if (str_contains($text, $variable)) {
                return $source;
            }
        }

        return null;
    }
}
