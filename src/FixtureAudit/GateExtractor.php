<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

use Parisek\DefinitionKit\Contract\TwigWalkerSupport;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\Binary\AndBinary;
use Twig\Node\Expression\Binary\ElvisBinary;
use Twig\Node\Expression\Binary\NullCoalesceBinary;
use Twig\Node\Expression\Binary\OrBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\Test\TrueTest;
use Twig\Node\Expression\Ternary\ConditionalTernary;
use Twig\Node\Expression\Unary\NotUnary;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\SetNode;

/**
 * Finds `content.*` paths a template actually GATES ON — as opposed to
 * merely reads (`TwigPropExtractor`'s job) or is handed as a fixture value
 * (`Flatten`'s job). This is the missing half of MUST-FIX 2: a path being
 * supplied-but-falsy is only an `unexercised-branch` finding when the
 * template's own control flow depends on that path's truthiness.
 *
 * Counter-examples that make a naive "falsy supplied value" check wrong
 * (see the fields-contract-audit design doc and the accompanying review):
 *
 * - `pagination.twig` reads `content.disabled` only via `{% if
 *   content.disabled %}` — `disabled: false` in a fixture is precisely the
 *   value that OPENS that branch, not a gap in coverage.
 * - `header.twig` reads `content.top` / `content.scrolled` only via the
 *   `|default` filter (`content.top|default('...')`) — `|default` consumes
 *   the value, it does not branch Twig's control flow on it, so a null
 *   there is not an unexercised branch.
 *
 * A path counts as gated only when it (or an alias/loop-binding of it)
 * appears DIRECTLY as:
 *
 * - the condition of `{% if %}` / `{% elseif %}`
 * - the test of a ternary (`content.x ? … : …`)
 * - an operand of `and` / `or` (recursively, since these nest)
 * - the operand of `not`
 *
 * Deliberately NOT gated: consumption via `|default(...)`, comparison with
 * `==`/`!=`/other binary operators, or plain interpolation (`{{ content.x }}`)
 * — those read the value without branching on its truthiness, so a fixture
 * supplying a falsy value there demonstrates the read just fine.
 *
 * Reuses the same bindings model as `TwigPropExtractor` (loop variables,
 * `{% set %}` aliases, one level of non-`only` include) so a path found
 * gated inside a loop or an aliased variable resolves back to the same dot
 * path `Flatten`/`Definition` use — without depending on that class's
 * private internals.
 *
 * State (`$gated`) is instance-scoped and reset at the top of `extract()`,
 * rather than threaded as a by-ref parameter through every walk method —
 * the same "mutable accumulator" shape `TwigPropExtractor` uses via its
 * separate `PropCollector` object, folded directly into this class since
 * there is only ever one kind of thing to accumulate here.
 */
final class GateExtractor
{
    use TwigWalkerSupport;

    public const ROOT = 'content';

    /**
     * MUST-FIX 1 (fields-fixtures-auditor review): this extractor used to
     * have no handling at all for `include()` function calls, `{% import %}`,
     * or a macro call handed the bare `content` object/sub-path — a gate
     * reached only through one of those forms went undetected, silently. It
     * now follows the resolvable ones (mirroring `TwigPropExtractor`'s own
     * handling, sharing the AST-walking primitives via `TwigWalkerSupport`)
     * and DECLARES the rest as incomplete via these notes, rather than
     * guessing or staying silent.
     */
    public const NOTE_UNRESOLVED_INCLUDE = 'unresolved-include';
    public const NOTE_NESTED_INCLUDE = 'unexplored-nested-include';
    public const NOTE_UNANALYSED_MACRO = 'unanalysed-macro-handoff';
    public const NOTE_PARSE_ERROR = 'unparsable-template';

    /** @var \Closure(string): ?string */
    private \Closure $resolveTemplate;

    /** @var array<string, true> reset at the top of each extract() call */
    private array $gated = [];

    /** @var list<array{kind: string, detail: string}> reset at the top of each extract() call */
    private array $notes = [];

    /**
     * `{% import "…" as x %}` bindings: bound name => template path.
     * Module-scoped, same rationale as `TwigPropExtractor::$macroImports`.
     *
     * @var array<string,string>
     */
    private array $macroImports = [];

    /**
     * `{% from "…" import a, b as c %}` bindings, keyed by object identity —
     * same reasoning as `PropCollector::$macroFromImportsByRef`.
     *
     * @var array<int,string>
     */
    private array $macroFromImportsByRef = [];

    /**
     * @param (callable(string): ?string)|null $resolveTemplate
     */
    public function __construct(?callable $resolveTemplate = null)
    {
        $this->resolveTemplate = null === $resolveTemplate
            ? static fn (string $path): ?string => null
            : $resolveTemplate(...);
    }

    /**
     * @return list<string> dot paths (relative to `content`) that the
     *   template gates control flow on
     */
    public function extractFile(string $twigPath): array
    {
        $source = file_get_contents($twigPath);
        if (false === $source) {
            return [];
        }

        return $this->extract($source, basename($twigPath));
    }

    /**
     * @return list<string>
     */
    public function extract(string $source, string $name = 'component.twig'): array
    {
        $this->gated = [];
        $this->notes = [];
        $this->macroImports = [];
        $this->macroFromImportsByRef = [];
        $this->collectFrom($source, $name, 0);

        $paths = array_keys($this->gated);
        sort($paths);

        return $paths;
    }

    /**
     * Gate analysis this extractor could NOT complete for the template last
     * passed to `extract()`/`extractFile()` — an include/import naming a
     * template the resolver could not find, a second level of nesting, or a
     * macro handed the bare `content` object/sub-path through an import this
     * extractor could not resolve. Populated alongside `$gated` by the same
     * `extract()` call — never guessed past, always declared (MUST-FIX 1).
     *
     * @return list<array{kind: string, detail: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function note(string $kind, string $detail): void
    {
        foreach ($this->notes as $existing) {
            if ($existing['kind'] === $kind && $existing['detail'] === $detail) {
                return;
            }
        }

        $this->notes[] = ['kind' => $kind, 'detail' => $detail];
    }

    private function collectFrom(string $source, string $name, int $depth): void
    {
        $module = $this->parse($source, $name);
        if (null === $module) {
            return;
        }
        $this->walk($module->getNode('body'), [], $depth, $name);
        if ($module->hasNode('blocks')) {
            $this->walk($module->getNode('blocks'), [], $depth, $name);
        }
        $embedded = $module->getAttribute('embedded_templates');
        if (is_iterable($embedded)) {
            foreach ($embedded as $embeddedModule) {
                if ($embeddedModule instanceof ModuleNode) {
                    $this->walk($embeddedModule->getNode('body'), [], $depth, $name);
                    if ($embeddedModule->hasNode('blocks')) {
                        $this->walk($embeddedModule->getNode('blocks'), [], $depth, $name);
                    }
                }
            }
        }
    }

    /**
     * Wraps `walk()` for a STATEMENT SEQUENCE (a template body, an
     * if-branch, a for-loop body, …) where a `{% set %}` earlier in the list
     * must be visible to an `{% if %}` later in the SAME list (MUST-FIX 6).
     * `walk()` itself returns the bindings a single node produced (only
     * `SetNode` ever changes them); this threads that return value from one
     * sibling to the next instead of re-using the same `$bindings` for all
     * of them, the way a single non-sequence-aware recursive call would.
     *
     * @param array<string,string> $bindings
     * @return array<string,string>
     */
    private function walkSequence(Node $node, array $bindings, int $depth, string $origin): array
    {
        foreach ($node as $child) {
            $bindings = $this->walk($child, $bindings, $depth, $origin);
        }

        return $bindings;
    }

    private function parse(string $source, string $name): ?ModuleNode
    {
        return $this->parseModule($source, $name, function (SyntaxError $e, string $parsedName): void {
            $this->note(self::NOTE_PARSE_ERROR, sprintf('%s: %s', $parsedName, $e->getRawMessage()));
        });
    }

    /**
     * @param array<string,string> $bindings
     * @return array<string,string> the bindings visible to whatever sibling
     *   statement comes AFTER `$node` in its enclosing sequence — unchanged
     *   for every node kind except `SetNode`, which is the only one that can
     *   introduce a new alias (MUST-FIX 6). Callers that don't care about
     *   sequencing (a single expression, a branch explored for its own
     *   gates only) are free to discard the return value.
     */
    private function walk(Node $node, array $bindings, int $depth, string $origin): array
    {
        if ($node instanceof ForNode) {
            $this->walkFor($node, $bindings, $depth, $origin);

            return $bindings;
        }

        if ($node instanceof SetNode) {
            return $this->walkSet($node, $bindings, $depth, $origin);
        }

        if ($node instanceof IncludeNode) {
            $this->walkInclude($node, $bindings, $depth, $origin);

            return $bindings;
        }

        if ($node instanceof FunctionExpression && 'include' === $node->getAttribute('name')) {
            $this->walkIncludeFunction($node, $bindings, $depth, $origin);

            return $bindings;
        }

        if ($node instanceof ImportNode) {
            $this->walkImport($node);

            return $bindings;
        }

        if ($node instanceof MacroReferenceExpression) {
            $this->walkMacroReference($node, $bindings, $depth, $origin);

            return $bindings;
        }

        if ($node instanceof IfNode) {
            $tests = $node->getNode('tests');
            $i = 0;
            foreach ($tests as $child) {
                if (0 === $i % 2) {
                    $this->markGate($child, $bindings);
                } else {
                    // A branch BODY is its own statement sequence — a `{%
                    // set %}` inside one branch must be visible to a later
                    // `{% if %}` in that SAME branch, but never leaks to a
                    // sibling branch or past the `{% endif %}` (conservative
                    // w.r.t. Twig's actual hoisting semantics, deliberately
                    // — see walkSequence()'s docblock).
                    $this->walkSequence($child, $bindings, $depth, $origin);
                }
                $i++;
            }
            if ($node->hasNode('else')) {
                $this->walkSequence($node->getNode('else'), $bindings, $depth, $origin);
            }

            return $bindings;
        }

        if ($node instanceof ConditionalTernary) {
            $this->markGate($node->getNode('test'), $bindings);
        }

        return $this->walkSequence($node, $bindings, $depth, $origin);
    }

    /**
     * Recursively marks the `content.*` path(s) a boolean-context expression
     * gates on. Only descends through `and`/`or`/`not`/the `is true` wrapper
     * every if-condition and ternary test carries — every other node shape
     * (filters like `|default`, comparisons like `==`, function calls,
     * `is defined`, …) is a deliberate stop: it consumes the value without
     * making Twig branch on its bare truthiness.
     *
     * @param array<string,string> $bindings
     */
    private function markGate(Node $expr, array $bindings): void
    {
        if ($expr instanceof TrueTest) {
            $this->markGate($expr->getNode('node'), $bindings);

            return;
        }
        if ($expr instanceof AndBinary || $expr instanceof OrBinary) {
            $this->markGate($expr->getNode('left'), $bindings);
            $this->markGate($expr->getNode('right'), $bindings);

            return;
        }
        if ($expr instanceof NotUnary) {
            $this->markGate($expr->getNode('node'), $bindings);

            return;
        }
        if ($expr instanceof ElvisBinary || $expr instanceof NullCoalesceBinary) {
            // `{% if content.x ?: y %}` (unusual but legal) gates on
            // whichever path `content.x` resolves to — same reasoning as
            // walkSet()'s alias binding for the same two node shapes.
            $this->markGate($expr->getNode('left'), $bindings);

            return;
        }
        if ($expr instanceof GetAttrExpression) {
            $path = $this->resolvePath($expr, $bindings);
            if (null !== $path) {
                $this->gated[$path] = true;
            }

            return;
        }
        if ($expr instanceof ContextVariable) {
            // MUST-FIX 6: `{% if flag %}` where `flag` is a bare `{% set %}`
            // alias (`{% set flag = content.toggle ?: … %}`) — not itself a
            // `content.x.y` attribute access, so `resolvePath()`'s
            // `GetAttrExpression`-only walk never sees it. Resolve straight
            // from the bindings map instead.
            $name = (string) $expr->getAttribute('name');
            if (isset($bindings[$name])) {
                $this->gated[$bindings[$name]] = true;
            }

            return;
        }
        // FilterExpression (|default), comparison binaries (==, !=, >, …),
        // TestExpression other than TrueTest (is defined, …), function
        // calls, literals — none of these branch Twig's control flow on the
        // BARE truthiness of a content path, so they are not followed.
    }

    /** @param array<string,string> $bindings */
    private function walkFor(ForNode $node, array $bindings, int $depth, string $origin): void
    {
        $this->walk($node->getNode('seq'), $bindings, $depth, $origin);

        $seqPath = $node->getNode('seq') instanceof GetAttrExpression
            ? $this->resolvePath($node->getNode('seq'), $bindings)
            : null;

        $inner = $bindings;
        $valueTarget = $node->getNode('value_target');
        if (null !== $seqPath && $valueTarget instanceof ContextVariable) {
            $inner[(string) $valueTarget->getAttribute('name')] = $seqPath;
        }

        $this->walkSequence($node->getNode('body'), $inner, $depth, $origin);

        if ($node->hasNode('else')) {
            $this->walkSequence($node->getNode('else'), $bindings, $depth, $origin);
        }
    }

    /**
     * MUST-FIX 6: binds a `{% set %}` target to the `content.*` path its
     * value stands for, so a LATER `{% if x %}` in the same statement
     * sequence resolves back to that path — same as `TwigPropExtractor`'s
     * own alias binding for a plain path (`{% set x = content.y %}`), plus
     * two shapes that class doesn't need to handle because they only matter
     * for GATING, not for "was this path read at all":
     *
     * - Elvis (`{% set x = content.y ?: fallback %}`) — compiles to
     *   `ElvisBinary` with `left` = the tested expression (`content.y`).
     * - Null-coalesce (`{% set x = content.y ?? fallback %}`) — compiles to
     *   `NullCoalesceBinary`, same `left`/`right` shape.
     *
     * In both cases the alias should resolve to the LEFT operand's path,
     * not the whole expression — `{% if x %}` after either form is, for
     * gating purposes, the same question as `{% if content.y %}` (modulo
     * the null-vs-falsy distinction Twig's own `??` already encodes, which
     * this lint-severity tool doesn't need to be that precise about).
     *
     * @param array<string,string> $bindings
     * @return array<string,string>
     */
    private function walkSet(SetNode $node, array $bindings, int $depth, string $origin): array
    {
        $bindings = $this->walk($node->getNode('values'), $bindings, $depth, $origin);

        if ($node->getAttribute('capture')) {
            return $bindings;
        }

        $names = iterator_to_array($node->getNode('names'));
        $values = $node->hasNode('values') ? iterator_to_array($node->getNode('values')) : [];

        foreach ($names as $index => $target) {
            if (!$target instanceof ContextVariable) {
                continue;
            }
            $value = $values[$index] ?? null;
            if (!$value instanceof Node) {
                continue;
            }

            $path = match (true) {
                $value instanceof GetAttrExpression => $this->resolvePath($value, $bindings),
                $value instanceof ElvisBinary, $value instanceof NullCoalesceBinary
                    => $value->getNode('left') instanceof GetAttrExpression
                        ? $this->resolvePath($value->getNode('left'), $bindings)
                        : null,
                default => null,
            };

            if (null !== $path) {
                // Rebinding is scoped to the rest of the sequence in Twig;
                // this walker has no statement-level cursor to model
                // anything finer, same trade-off TwigPropExtractor::
                // walkSet() documents for its own alias binding.
                $bindings[(string) $target->getAttribute('name')] = $path;
            }
        }

        return $bindings;
    }

    /** @param array<string,string> $bindings */
    private function walkInclude(IncludeNode $node, array $bindings, int $depth, string $origin): void
    {
        if ($node->hasNode('variables')) {
            $this->walk($node->getNode('variables'), $bindings, $depth, $origin);
        }

        if ($node->getAttribute('only')) {
            return;
        }

        $template = $this->constantPath($node->getNode('expr'));
        $this->followInclude($template, false, $depth, $origin);
    }

    /**
     * `include('_partial.twig', {…})` — the function-call form of include,
     * distinct from the `{% include %}` tag `walkInclude()` above handles.
     * Same MUST-FIX 1 gap this whole class had zero handling for before:
     * a gate reached only through this form went silently undetected.
     * Mirrors `TwigPropExtractor::walkIncludeFunction()`.
     *
     * @param array<string,string> $bindings
     */
    private function walkIncludeFunction(FunctionExpression $node, array $bindings, int $depth, string $origin): void
    {
        $arguments = iterator_to_array($node->getNode('arguments'));

        foreach (array_slice($arguments, 1) as $argument) {
            $this->walk($argument, $bindings, $depth, $origin);
        }

        $only = false;
        if (isset($arguments[2]) && $arguments[2] instanceof ConstantExpression) {
            $only = !((bool) $arguments[2]->getAttribute('value'));
        }

        $template = isset($arguments[0]) ? $this->constantPath($arguments[0]) : null;
        $this->followInclude($template, $only, $depth, $origin);
    }

    /**
     * Shared depth-guard + template-resolution + note-emission for both
     * include forms — an `only` include's child sees nothing but what was
     * already walked as part of the caller's own expressions, a second level
     * of nesting is declined rather than guessed (same as
     * `TwigPropExtractor::followInclude()`), and an unresolved/dynamic target
     * is DECLARED via a note instead of silently dropped (MUST-FIX 1).
     */
    private function followInclude(?string $template, bool $only, int $depth, string $origin): void
    {
        if ($only) {
            return;
        }

        if ($depth >= 1) {
            $this->note(self::NOTE_NESTED_INCLUDE, sprintf(
                '%s includes %s, which is a second level of nesting. A gate inside it (if any) '
                . 'could not be followed.',
                $origin,
                $template ?? 'a template named by an expression',
            ));

            return;
        }

        if (null === $template) {
            $this->note(self::NOTE_UNRESOLVED_INCLUDE, sprintf(
                '%s includes a template named by an expression, so which file it is (and any gate '
                . 'inside it) depends on runtime data.',
                $origin,
            ));

            return;
        }

        $source = ($this->resolveTemplate)($template);
        if (null === $source) {
            $this->note(self::NOTE_UNRESOLVED_INCLUDE, sprintf(
                '%s includes %s, which could not be found. A gate inside it (if any) is part of this '
                . "component's contract and could not be followed.",
                $origin,
                $template,
            ));

            return;
        }

        $this->collectFrom($source, $template, $depth + 1);
    }

    /**
     * `{% import "…" as x %}` / `{% from "…" import a, b as c %}` — same
     * binding shapes `TwigPropExtractor::walkImport()` records, needed here
     * so a later `MacroReferenceExpression` through the alias can be
     * resolved to the macro's own template and body.
     */
    private function walkImport(ImportNode $node): void
    {
        $template = $this->constantPath($node->getNode('expr'));
        if (null === $template) {
            return;
        }

        $varNode = $node->getNode('var');
        $nameNode = $varNode->hasNode('var') ? $varNode->getNode('var') : $varNode;

        if ('from' === $node->getNodeTag()) {
            $this->macroFromImportsByRef[spl_object_id($nameNode)] = $template;

            return;
        }

        $name = (string) $nameNode->getAttribute('name');
        if ('' !== $name) {
            $this->macroImports[$name] = $template;
        }
    }

    /**
     * A macro call is only interesting for gating when it hands the caller's
     * `content` object (or a resolvable sub-path/alias of it) over WHOLE —
     * `followMacro()` then walks the macro body for gates on that handed
     * path, exactly as an included template would be. Every other argument
     * is walked normally, to catch a gate embedded in the argument
     * expression itself (`m.card(featured: content.x ? 1 : 0)`).
     *
     * Mirrors `TwigPropExtractor::walkMacroReference()`'s argument-mapping
     * rules (named argument binds by declared parameter name, never by
     * position — issue #55) so the two extractors cannot silently diverge on
     * which macro parameter a given call-site argument targets.
     *
     * @param array<string,string> $bindings
     */
    private function walkMacroReference(MacroReferenceExpression $node, array $bindings, int $depth, string $origin): void
    {
        $macroName = (string) $node->getAttribute('name');
        $shortName = str_starts_with($macroName, 'macro_') ? substr($macroName, 6) : $macroName;

        $templateVar = $node->getNode('template');
        $templateVarName = $templateVar->hasAttribute('name') && null !== $templateVar->getAttribute('name')
            ? (string) $templateVar->getAttribute('name')
            : null;
        $templateRef = spl_object_id($templateVar);

        $pairs = iterator_to_array($node->getNode('arguments'));
        for ($i = 0, $count = count($pairs); $i < $count; $i += 2) {
            $keyNode = $pairs[$i] ?? null;
            $valueNode = $pairs[$i + 1] ?? null;
            if (null === $valueNode) {
                continue;
            }

            $target = $this->macroArgumentTarget($keyNode);
            $path = $this->bareContentPath($valueNode, $bindings);

            if (null === $path) {
                $this->walk($valueNode, $bindings, $depth, $origin);

                continue;
            }

            $this->followMacro($shortName, $templateVarName, $templateRef, $target, $path, $depth, $origin);
        }
    }

    /**
     * @return array{index: ?int, name: ?string}
     */
    private function macroArgumentTarget(?Node $keyNode): array
    {
        if ($keyNode instanceof LocalVariable) {
            $value = $keyNode->getAttribute('name');
            if (is_int($value)) {
                return ['index' => $value, 'name' => null];
            }
            if (is_string($value)) {
                return ['index' => null, 'name' => $value];
            }
        }

        return ['index' => null, 'name' => null];
    }

    /**
     * The content path (or `''` for the bare root itself) a macro-call
     * argument stands for when passed whole — same shape as
     * `TwigPropExtractor::bareContentPath()`.
     *
     * @param array<string,string> $bindings
     */
    private function bareContentPath(Node $node, array $bindings): ?string
    {
        if ($node instanceof ContextVariable) {
            $name = (string) $node->getAttribute('name');

            return self::ROOT === $name ? '' : ($bindings[$name] ?? null);
        }

        if ($node instanceof GetAttrExpression) {
            return $this->resolvePath($node, $bindings);
        }

        return null;
    }

    /**
     * Resolves the macro's own template + body via the import bindings
     * `walkImport()` recorded, and walks that body for gates with the
     * receiving parameter bound to `$path` — so `{% if data.flag %}` inside
     * the macro resolves back to the caller's own `content.…` path exactly
     * as an included template's gates do. Anything not staticaly resolvable
     * (unresolved import, macro not found, argument not mappable to a
     * declared parameter, a second level of nesting) is DECLARED via a note
     * instead of guessed past (MUST-FIX 1) — mirrors
     * `TwigPropExtractor::followMacro()`'s same decline conditions.
     *
     * @param array{index: ?int, name: ?string} $argumentTarget
     */
    private function followMacro(
        string $shortName,
        ?string $templateVarName,
        int $templateRef,
        array $argumentTarget,
        string $path,
        int $depth,
        string $origin,
    ): void {
        $decline = function () use ($origin, $shortName): void {
            $this->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands a content path to %s(), which could not be resolved statically (unresolved '
                . 'import, macro not found, or an argument that cannot be mapped to a declared '
                . 'parameter). A gate inside it (if any) could not be followed.',
                $origin,
                $shortName,
            ));
        };

        if ('' === $path) {
            // The bare `content` root handed whole — there is no single
            // `content.*` path a gate inside the macro on the received
            // parameter could resolve back to (the whole object's own
            // truthiness isn't a `content.x` finding candidate), so this is
            // declared as incomplete rather than recording a meaningless
            // empty-string "gate".
            $this->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands the whole content object to %s(). A gate inside it on the received parameter '
                . 'does not resolve to a single content.* path and could not be followed.',
                $origin,
                $shortName,
            ));

            return;
        }

        if ($depth >= 1) {
            $this->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands a content path to %s(), one level inside an already-followed include/macro. '
                . 'A gate inside it (if any) could not be followed.',
                $origin,
                $shortName,
            ));

            return;
        }

        $template = null !== $templateVarName
            ? ($this->macroImports[$templateVarName] ?? null)
            : ($this->macroFromImportsByRef[$templateRef] ?? null);

        if (null === $template) {
            $decline();

            return;
        }

        $source = ($this->resolveTemplate)($template);
        if (null === $source) {
            $decline();

            return;
        }

        $macroModule = $this->parse($source, $template);
        if (null === $macroModule || !$macroModule->hasNode('macros')) {
            $decline();

            return;
        }

        $macrosNode = $macroModule->getNode('macros');
        if (!$macrosNode->hasNode($shortName)) {
            $decline();

            return;
        }

        $macroNode = $macrosNode->getNode($shortName);
        $paramName = $this->macroParamNameFor($macroNode, $argumentTarget);
        if (null === $paramName) {
            $decline();

            return;
        }

        $this->walkSequence($macroNode->getNode('body'), [$paramName => $path], $depth + 1, $template);
    }

    /**
     * @param array{index: ?int, name: ?string} $argumentTarget
     */
    private function macroParamNameFor(Node $macroNode, array $argumentTarget): ?string
    {
        if (!$macroNode->hasNode('arguments')) {
            return null;
        }

        $names = [];
        foreach ($macroNode->getNode('arguments') as $position => $child) {
            if (0 === (int) $position % 2) {
                $names[] = $child instanceof LocalVariable ? (string) $child->getAttribute('name') : null;
            }
        }

        if (null !== $argumentTarget['name']) {
            return in_array($argumentTarget['name'], $names, true) ? $argumentTarget['name'] : null;
        }

        if (null !== $argumentTarget['index']) {
            return $names[$argumentTarget['index']] ?? null;
        }

        return null;
    }

    private function constantPath(Node $node): ?string
    {
        return $this->constantTemplatePath($node);
    }

    /** @param array<string,string> $bindings */
    private function resolvePath(GetAttrExpression $node, array $bindings): ?string
    {
        return $this->resolveContentPath($node, $bindings);
    }
}
