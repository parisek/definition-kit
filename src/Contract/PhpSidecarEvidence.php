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
 * A sidecar also lifts: `$content['title'] = wp_kses_post($content['heading']['title'])`
 * takes a nested field up to the root. That is `role: derived` with
 * `from: heading` — the value is built out of a declared sibling, and the
 * statement names the sibling itself, so the claim is evidenced rather than
 * guessed.
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
        // Timber's own accessors — `Timber::get_posts()` is what a timber-kit
        // sidecar actually calls, and matching only the bare WordPress
        // functions left every real query-backed prop unclassified.
        'get_categories', 'get_category_link',
    ];

    // `option` covers `get_option()`, `get_field(…, 'option')` and
    // `Helpers::formatFields('option')` in one token. `formatFields` on its own
    // deliberately does NOT appear here: it is timber-kit's field formatter,
    // called on a post far more often than on options, and listing it made
    // every query-built row in a real sidecar come back as `global`.
    private const GLOBAL_MARKERS = ['option'];

    /**
     * prop name => 'query' | 'global' | 'derived' | null (assigned, but by
     * nothing this can name), plus the sibling each `derived` came from.
     *
     * @return array{roles: array<string,?string>, derivedFrom: array<string,string>}
     */
    public function evidence(string $phpPath): array
    {
        $source = is_file($phpPath) ? file_get_contents($phpPath) : false;
        if (false === $source) {
            return ['roles' => [], 'derivedFrom' => []];
        }

        $found = [];
        $derivedFrom = [];
        /** @var array<string,string> $variableSources variable name => 'query'|'global' */
        $variableSources = [];

        foreach ($this->statements(token_get_all($source)) as $statement) {
            $classification = $this->classify($statement['text'], $variableSources);

            // `foreach ($post_query as $post)` carries the evidence across the
            // loop: rows of a query result are query-sourced, and a sidecar
            // that builds `$content['items']` inside such a loop is the shape
            // real ones are written in.
            if (null !== $statement['loopTarget'] && null !== $classification) {
                $variableSources[$statement['loopTarget']] = $classification;
            }

            $prop = $statement['contextProp'];
            if (null !== $prop) {
                if (null === $classification) {
                    $sibling = $this->liftedSibling($statement['contextReads'], $prop);
                    if (null !== $sibling) {
                        $classification = 'derived';
                        $derivedFrom[$prop] = $sibling;
                    }
                }

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

        return ['roles' => $found, 'derivedFrom' => $derivedFrom];
    }

    /**
     * The sibling prop a lifting assignment reads — `heading` in
     * `$content['title'] = wp_kses_post($content['heading']['title'])`.
     *
     * Exactly one other context prop must appear. A statement combining two
     * siblings has no single origin, and `from:` names one; the honest answer
     * there is no proposal at all.
     *
     * @param list<string> $contextReads
     */
    private function liftedSibling(array $contextReads, string $prop): ?string
    {
        $others = array_values(array_unique(array_filter(
            $contextReads,
            static fn (string $name): bool => $name !== $prop,
        )));

        return 1 === count($others) ? $others[0] : null;
    }

    /**
     * Statements, split on `;` and on block braces.
     *
     * Braces matter because a `foreach (…) {` header ends in no semicolon: glued
     * to the statement after it, the loop's own evidence would be attributed to
     * whatever the body happened to assign first.
     *
     * @param list<array{int,string,int}|string> $tokens
     * @return list<array{text: string, contextProp: ?string, assignedVariable: ?string, loopTarget: ?string, contextReads: list<string>}>
     */
    private function statements(array $tokens): array
    {
        $statements = [];
        $current = [];

        foreach ($tokens as $token) {
            if (';' === $token || '{' === $token || '}' === $token) {
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
     * @return array{text: string, contextProp: ?string, assignedVariable: ?string, loopTarget: ?string, contextReads: list<string>}
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

        $contextReads = $this->contextSubscripts($significant);
        $first = $significant[0] ?? null;

        if (is_array($first) && T_FOREACH === $first[0]) {
            return [
                'text' => $text,
                'contextProp' => null,
                'assignedVariable' => null,
                'loopTarget' => $this->loopValueTarget($significant),
                'contextReads' => $contextReads,
            ];
        }

        if (!is_array($first) || T_VARIABLE !== $first[0]) {
            return [
                'text' => $text,
                'contextProp' => null,
                'assignedVariable' => null,
                'loopTarget' => null,
                'contextReads' => $contextReads,
            ];
        }

        // `$content['x'] = …`, and equally `$content['x'][] = …` (an append)
        // and `$content['x']['y'] = …` (a nested write). All three assign to
        // the prop `x`; only the first was recognised until a real sidecar
        // built its list with `$content['categories'][] = …` and the prop
        // vanished from the evidence entirely.
        if (
            in_array($first[1], self::CONTEXT_VARIABLES, true)
            && '[' === ($significant[1] ?? null)
            && is_array($significant[2] ?? null)
            && T_CONSTANT_ENCAPSED_STRING === $significant[2][0]
            && ']' === ($significant[3] ?? null)
            && $this->assignsAfterSubscripts($significant, 4)
        ) {
            return [
                'text' => $text,
                'contextProp' => trim($significant[2][1], "'\""),
                'assignedVariable' => null,
                'loopTarget' => null,
                'contextReads' => $contextReads,
            ];
        }

        // `$query = …`
        if ('=' === ($significant[1] ?? null)) {
            return [
                'text' => $text,
                'contextProp' => null,
                'assignedVariable' => $first[1],
                'loopTarget' => null,
                'contextReads' => $contextReads,
            ];
        }

        return [
            'text' => $text,
            'contextProp' => null,
            'assignedVariable' => null,
            'loopTarget' => null,
            'contextReads' => $contextReads,
        ];
    }

    /**
     * Every `$content['name']` the statement mentions, in order — read the same
     * way the assignment target is, through the lexer rather than by matching
     * text. Includes the assignment target itself; the caller filters it out.
     *
     * @param list<array{int,string,int}|string> $significant
     * @return list<string>
     */
    private function contextSubscripts(array $significant): array
    {
        $names = [];

        foreach ($significant as $index => $token) {
            if (!is_array($token) || T_VARIABLE !== $token[0]) {
                continue;
            }
            if (!in_array($token[1], self::CONTEXT_VARIABLES, true)) {
                continue;
            }
            if ('[' !== ($significant[$index + 1] ?? null)) {
                continue;
            }

            $subscript = $significant[$index + 2] ?? null;
            if (is_array($subscript) && T_CONSTANT_ENCAPSED_STRING === $subscript[0]) {
                $names[] = trim($subscript[1], "'\"");
            }
        }

        return $names;
    }

    /**
     * The value variable a `foreach` binds — `$post` in
     * `foreach ($query as $post)`, and in `foreach ($x as $k => $v)` the `$v`.
     *
     * @param list<array{int,string,int}|string> $significant
     */
    private function loopValueTarget(array $significant): ?string
    {
        $variables = [];
        $sawAs = false;

        foreach ($significant as $token) {
            if (is_array($token) && T_AS === $token[0]) {
                $sawAs = true;
                continue;
            }
            if ($sawAs && is_array($token) && T_VARIABLE === $token[0]) {
                $variables[] = $token[1];
            }
        }

        return [] === $variables ? null : (string) end($variables);
    }

    /**
     * Skips any further `[…]` subscripts and answers whether an `=` follows.
     *
     * @param list<array{int,string,int}|string> $significant
     */
    private function assignsAfterSubscripts(array $significant, int $cursor): bool
    {
        while ('[' === ($significant[$cursor] ?? null)) {
            $depth = 0;
            while (isset($significant[$cursor])) {
                if ('[' === $significant[$cursor]) {
                    $depth++;
                } elseif (']' === $significant[$cursor]) {
                    $depth--;
                    if (0 === $depth) {
                        $cursor++;
                        break;
                    }
                }
                $cursor++;
            }
        }

        return '=' === ($significant[$cursor] ?? null);
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
