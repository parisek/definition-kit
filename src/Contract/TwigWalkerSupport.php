<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * The genuinely identical seam between `TwigPropExtractor` (every `content.*`
 * path a template READS) and `FixtureAudit\GateExtractor` (only the paths a
 * template GATES control flow on) — parsing a template into an AST with a
 * project-agnostic environment, resolving a constant string argument (an
 * include/import/embed target), and walking a `content.x.y` attribute chain
 * back to its dot path through a caller-supplied alias/loop-binding map.
 *
 * Extracted per the fields-fixtures-auditor review (MUST-FIX 1): the two
 * classes used to maintain two independently-written copies of this exact
 * logic, and `GateExtractor`'s copy had silently fallen behind — it had no
 * handling at all for `include()` function calls, `{% import %}`, or macro
 * references, so a truthiness gate reached only through one of those forms
 * went undetected (a false-clean audit) instead of failing loudly. Sharing
 * this seam means a future fix to constant-path or dot-path resolution lands
 * in both extractors at once instead of risking the same silent divergence
 * again.
 *
 * What deliberately stays OUT of this trait, because it differs by design
 * between the two callers, not by accident: `TwigPropExtractor` collects
 * every prop path a template reads (via its own `PropCollector` accumulator,
 * with alias/import/macro bookkeeping that must survive across an entire
 * extraction run); `GateExtractor` collects only paths that appear directly
 * in a boolean-context position (`{% if %}` / ternary test / `and`/`or`/`not`
 * operand) and threads a narrower, statement-sequence-scoped bindings map
 * (see its own class docblock). Depth guards, note vocabularies and the
 * decision of what counts as "followable" also stay with each caller — they
 * express different policies (what to declare as incomplete, and under what
 * note kind), not different bookkeeping mechanics.
 */
trait TwigWalkerSupport
{
    /**
     * Parses `$source` with a permissive, project-agnostic Twig environment —
     * unknown functions/filters/tests resolve to no-ops so a component twig
     * written against a consuming project's own Timber functions and theme
     * filters still parses; unknown TAGS still fail, because a tag can change
     * control flow and guessing past one would be a confidently wrong answer,
     * not a conservative one.
     *
     * @param null|\Closure(SyntaxError, string): void $onSyntaxError invoked
     *   (instead of throwing) when the source does not parse, so each caller
     *   can record the failure in its own note vocabulary
     */
    private function parseModule(string $source, string $name, ?\Closure $onSyntaxError = null): ?ModuleNode
    {
        $loader = new ArrayLoader([$name => $source]);
        $env = new Environment($loader, ['cache' => false]);
        $env->registerUndefinedFunctionCallback(
            static fn (string $callable): TwigFunction => new TwigFunction($callable, static fn (): string => ''),
        );
        $env->registerUndefinedFilterCallback(
            static fn (string $callable): TwigFilter => new TwigFilter($callable, static fn (): string => ''),
        );
        $env->registerUndefinedTestCallback(
            static fn (string $callable): TwigTest => new TwigTest($callable, static fn (): bool => true),
        );

        try {
            return $env->parse($env->tokenize($loader->getSourceContext($name)));
        } catch (SyntaxError $e) {
            if (null !== $onSyntaxError) {
                $onSyntaxError($e, $name);
            }

            return null;
        }
    }

    /**
     * The literal string an include/import/embed expression names, or null
     * when it is not a constant (a computed template name — neither
     * extractor guesses past one of those, see each class's own docblock).
     */
    private function constantTemplatePath(Node $node): ?string
    {
        if (!$node instanceof ConstantExpression) {
            return null;
        }

        $value = $node->getAttribute('value');

        return is_string($value) ? $value : null;
    }

    /**
     * The dotted `content.…` path a `GetAttrExpression` chain names, resolved
     * through `$bindings` (loop variables, `{% set %}` aliases) — or null when
     * the chain is rooted somewhere other than `content`/a bound alias, or
     * passes through a non-constant accessor (`content[key]` with a runtime
     * `key`, an array index that isn't a literal). An array index segment
     * (`content.items.0.title`) is dropped rather than kept as a path
     * component: the prop is the collection it indexes into, not a specific
     * row.
     *
     * Both callers define `const ROOT = 'content'` themselves (rather than
     * this trait hard-coding it) so the constant stays visible as each
     * class's own declared contract, not a hidden trait dependency.
     *
     * @param array<string,string> $bindings variable name => the content path
     *   it stands for
     */
    private function resolveContentPath(GetAttrExpression $node, array $bindings): ?string
    {
        $segments = [];
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            $attribute = $current->getNode('attribute');
            if (!$attribute instanceof ConstantExpression) {
                return null;
            }

            $value = $attribute->getAttribute('value');
            if (!is_string($value) && !is_int($value)) {
                return null;
            }

            if (is_string($value) && !is_numeric($value)) {
                array_unshift($segments, $value);
            }

            $current = $current->getNode('node');
        }

        if (!$current instanceof ContextVariable) {
            return null;
        }

        $root = (string) $current->getAttribute('name');

        /** @var string $rootConst */
        $rootConst = self::ROOT;

        if ($rootConst === $root) {
            return [] === $segments ? null : implode('.', $segments);
        }

        if (isset($bindings[$root])) {
            $prefix = $bindings[$root];

            return '' === $prefix ? implode('.', $segments) : implode('.', [$prefix, ...$segments]);
        }

        return null;
    }
}
