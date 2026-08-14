<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Twig\Error\SyntaxError;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\Binary\EqualBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\ForNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\SetNode;

/**
 * Extracts the props a component's twig reads off `content` (issue #27,
 * phase 3), using Twig's own lexer and parser.
 *
 * ## Why a real parser
 *
 * Regex extraction was the cheap option and is the wrong one. This codebase
 * took three separate silent bugs in one day from regex-parsing YAML — a far
 * simpler grammar than Twig — and each shipped past review because the failing
 * shape is one nobody writes by hand. `twig/twig` is a runtime dependency
 * rather than a new one in practice: every project consuming this package
 * already runs Twig; it was merely undeclared here.
 *
 * A parser also turns the extractor's limits into statements instead of
 * discoveries. Everything it cannot resolve comes back as a note, and the
 * contract check treats a noted component as unanalysed rather than clean.
 *
 * ## What it resolves
 *
 * - `content.x`, `content.x.y` — direct reads, at any depth.
 * - `{% for item in content.items %}{{ item.value }}` — a loop over a content
 *   path binds its value variable, so `item.value` is recorded as `items.value`.
 *   Nested loops compose.
 * - `{% set alias = content.x %}{{ alias.y }}` — aliasing is the same rebinding
 *   as a loop, one step shorter, and missing it would be a silent false
 *   negative: the component would read a prop and look like it declared
 *   everything it reads.
 * - `{% include %}` / `{% embed %}` / `include()` **one level deep**, and only
 *   when the child inherits the caller's context. With `only`, the child sees
 *   nothing but what was handed to it: those handed expressions are reads of
 *   THIS component (and are collected), while the child's own reads belong to
 *   the child's contract. That is not an approximation — it is the semantics.
 *
 * ## What it refuses to guess
 *
 * - A second level of include nesting. A prop read two includes down is not
 *   knowable statically without evaluating the template.
 * - `attribute(content, key)` and any other non-constant accessor.
 * - A template that does not parse (a project tag this environment does not
 *   know), or an include naming a template the resolver cannot find.
 *
 * Each comes back as a note with a `kind`, never as an empty result.
 */
final class TwigPropExtractor
{
    use TwigWalkerSupport;

    public const ROOT = 'content';

    public const NOTE_DYNAMIC_ACCESS = 'unanalysable-dynamic-access';
    public const NOTE_NESTED_INCLUDE = 'unanalysed-nested-include';
    public const NOTE_UNRESOLVED_INCLUDE = 'unresolved-include';
    public const NOTE_PARSE_ERROR = 'unparsable-template';
    public const NOTE_UNANALYSED_MACRO = 'unanalysed-macro-handoff';

    /** @var \Closure(string): ?string */
    private \Closure $resolveTemplate;

    /**
     * @param (callable(string): ?string)|null $resolveTemplate template path as written in the
     *        twig => its source, or null when it cannot be found. Omitted means includes are
     *        never followed and every one of them is reported.
     */
    public function __construct(?callable $resolveTemplate = null)
    {
        $this->resolveTemplate = null === $resolveTemplate
            ? static fn (string $path): ?string => null
            : $resolveTemplate(...);
    }

    public function extractFile(string $twigPath): PropReads
    {
        $source = file_get_contents($twigPath);
        if (false === $source) {
            return new PropReads([], [[
                'kind' => self::NOTE_PARSE_ERROR,
                'detail' => sprintf('%s could not be read.', $twigPath),
            ]]);
        }

        return $this->extract($source, basename($twigPath));
    }

    public function extract(string $source, string $name = 'component.twig'): PropReads
    {
        $collector = new PropCollector();
        $this->collectFrom($source, $name, $collector, 0);

        $reads = array_values(array_unique($collector->reads));
        sort($reads);

        return new PropReads($reads, $collector->notes, $collector->comparisons);
    }

    private function collectFrom(string $source, string $name, PropCollector $collector, int $depth): void
    {
        $module = $this->parse($source, $name, $collector);
        if (null === $module) {
            return;
        }

        // An `{% embed %}` body is parsed as its own module hanging off the
        // caller's, so its blocks are unreachable from a plain body walk.
        $embedded = $module->getAttribute('embedded_templates');
        $modules = [$module];
        if (is_iterable($embedded)) {
            foreach ($embedded as $embeddedModule) {
                if ($embeddedModule instanceof ModuleNode) {
                    $modules[] = $embeddedModule;
                    $collector->embeddedModules[(int) $embeddedModule->getAttribute('index')] = $embeddedModule;
                }
            }
        }

        foreach ($modules as $each) {
            $this->walk($each->getNode('body'), $collector, [], $depth, $name);
            // An `{% embed %}`'s overridden blocks hang off the embedded
            // module's `blocks`, not its (empty) body.
            if ($each->hasNode('blocks')) {
                $this->walk($each->getNode('blocks'), $collector, [], $depth, $name);
            }
        }
    }

    private function parse(string $source, string $name, PropCollector $collector): ?ModuleNode
    {
        return $this->parseModule($source, $name, static function (SyntaxError $e, string $parsedName) use ($collector): void {
            $collector->note(self::NOTE_PARSE_ERROR, sprintf('%s: %s', $parsedName, $e->getRawMessage()));
        });
    }

    /**
     * @param array<string,string> $bindings variable name => the content path it stands for
     */
    private function walk(Node $node, PropCollector $collector, array $bindings, int $depth, string $origin): void
    {
        if ($node instanceof ForNode) {
            $this->walkFor($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof SetNode) {
            $this->walkSet($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof IncludeNode) {
            // EmbedNode extends IncludeNode; its own body was already picked
            // up as an embedded module by collectFrom().
            $this->walkInclude($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof FunctionExpression && 'include' === $node->getAttribute('name')) {
            $this->walkIncludeFunction($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof ImportNode) {
            $this->walkImport($node, $collector);

            return;
        }

        if ($node instanceof MacroReferenceExpression) {
            $this->walkMacroReference($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof GetAttrExpression) {
            $this->walkGetAttr($node, $collector, $bindings, $depth, $origin);

            return;
        }

        if ($node instanceof EqualBinary) {
            // NotEqualBinary is deliberately NOT handled here. "literal does
            // not match any declared layout" only means "always dead" for
            // `==` — for `!=` it would mean the opposite (always true), a
            // different diagnosis this walker does not attempt (Codex review,
            // issue #63 PR #64). Recording it identically to `==` produced a
            // wrong "can never be true" message on an always-true condition.
            $this->walkComparison($node, $collector, $bindings);
            // Falls through to the generic recursion below — the comparison
            // check is additive, not a substitute for the ordinary read
            // extraction both operands still need (e.g. the path itself, or
            // a nested expression on either side).
        }

        foreach ($node as $child) {
            $this->walk($child, $collector, $bindings, $depth, $origin);
        }
    }

    /**
     * `<path> == '<literal>'`, one side a content path, the other a string
     * constant — the one shape statically resolvable without evaluating the
     * template. Anything else (two dynamic values, a non-string constant, a
     * computed key, `!=`, `in`/`not in`) is left alone; this is not general
     * expression evaluation, only the narrow pattern flexible_content layout
     * discrimination is written in.
     *
     * @param array<string,string> $bindings
     */
    private function walkComparison(Node $node, PropCollector $collector, array $bindings): void
    {
        if (!$node->hasNode('left') || !$node->hasNode('right')) {
            return;
        }

        $left = $node->getNode('left');
        $right = $node->getNode('right');
        $resolvedBindings = $collector->bindings($bindings);

        if ($left instanceof GetAttrExpression && $right instanceof ConstantExpression) {
            $this->recordComparison($left, $right, $collector, $resolvedBindings);

            return;
        }

        if ($right instanceof GetAttrExpression && $left instanceof ConstantExpression) {
            $this->recordComparison($right, $left, $collector, $resolvedBindings);
        }
    }

    /** @param array<string,string> $bindings */
    private function recordComparison(
        GetAttrExpression $pathNode,
        ConstantExpression $literalNode,
        PropCollector $collector,
        array $bindings,
    ): void {
        $value = $literalNode->getAttribute('value');
        if (!is_string($value)) {
            return;
        }

        $path = $this->resolvePath($pathNode, $bindings);
        if (null !== $path) {
            $collector->compare($path, $value);
        }
    }

    /** @param array<string,string> $bindings */
    private function walkGetAttr(
        GetAttrExpression $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        $bindings = $collector->bindings($bindings);
        $path = $this->resolvePath($node, $bindings);

        if (null !== $path) {
            $collector->read($path);

            return;
        }

        if ($this->rootsInContent($node, $bindings) && !$node->getNode('attribute') instanceof ConstantExpression) {
            $collector->note(self::NOTE_DYNAMIC_ACCESS, sprintf(
                '%s reads a prop through a computed key. Which prop that is depends on runtime data, '
                . 'so no static reading of this template can name it.',
                $origin,
            ));
        }

        // Not a content read (a post object, a loop over something else) —
        // but its own sub-expressions still can be, e.g. the index in
        // `posts[content.offset]`.
        foreach ($node as $child) {
            $this->walk($child, $collector, $bindings, $depth, $origin);
        }
    }

    /** @param array<string,string> $bindings */
    private function walkFor(
        ForNode $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        $this->walk($node->getNode('seq'), $collector, $bindings, $depth, $origin);

        $bindings = $collector->bindings($bindings);
        $seqPath = $node->getNode('seq') instanceof GetAttrExpression
            ? $this->resolvePath($node->getNode('seq'), $bindings)
            : null;

        $inner = $bindings;
        $valueTarget = $node->getNode('value_target');
        if (null !== $seqPath && $valueTarget instanceof ContextVariable) {
            $inner[(string) $valueTarget->getAttribute('name')] = $seqPath;
        }

        $this->walk($node->getNode('body'), $collector, $inner, $depth, $origin);

        foreach (['else'] as $optional) {
            if ($node->hasNode($optional)) {
                $this->walk($node->getNode($optional), $collector, $bindings, $depth, $origin);
            }
        }
    }

    /**
     * `{% set alias = content.x %}` rebinds a content path under a new name,
     * exactly as a loop does. Untracked, every later `alias.y` would vanish —
     * and a read that vanishes is worse than one that is reported unresolved,
     * because it makes an incomplete definition look complete.
     *
     * @param array<string,string> $bindings
     */
    private function walkSet(
        SetNode $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        $names = iterator_to_array($node->getNode('names'));
        $values = $node->hasNode('values') ? iterator_to_array($node->getNode('values')) : [];

        $this->walk($node->getNode('values'), $collector, $bindings, $depth, $origin);
        $bindings = $collector->bindings($bindings);

        if ($node->getAttribute('capture')) {
            return;
        }

        foreach ($names as $index => $target) {
            $value = $values[$index] ?? null;
            if (!$target instanceof ContextVariable || !$value instanceof GetAttrExpression) {
                continue;
            }

            $path = $this->resolvePath($value, $bindings);
            if (null !== $path) {
                // Rebinding is scoped to the rest of the template in Twig, and
                // this walker has no statement-level cursor to model that. The
                // collector carries it forward instead: an alias declared late
                // and used early cannot happen in a template that renders.
                $collector->bind((string) $target->getAttribute('name'), $path);
            }
        }
    }

    /** @param array<string,string> $bindings */
    private function walkInclude(
        IncludeNode $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        if ($node->hasNode('variables')) {
            $this->walk($node->getNode('variables'), $collector, $bindings, $depth, $origin);
        }

        $template = $node instanceof EmbedNode
            ? $this->embeddedTemplateName($node, $collector)
            : $this->constantPath($node->getNode('expr'));

        if (null === $template && !$node instanceof EmbedNode) {
            // The expression naming the template is itself part of the
            // contract — `{% include "card-" ~ content.variant ~ ".twig" %}`
            // reads `variant` whether or not the file can be identified.
            $this->walk($node->getNode('expr'), $collector, $bindings, $depth, $origin);
        }

        $this->followInclude($template, (bool) $node->getAttribute('only'), $collector, $depth, $origin);
    }

    /** @param array<string,string> $bindings */
    private function walkIncludeFunction(
        FunctionExpression $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        $arguments = iterator_to_array($node->getNode('arguments'));

        foreach (array_slice($arguments, 1) as $argument) {
            $this->walk($argument, $collector, $bindings, $depth, $origin);
        }

        $only = false;
        if (isset($arguments[2]) && $arguments[2] instanceof ConstantExpression) {
            $only = !((bool) $arguments[2]->getAttribute('value'));
        }

        $template = isset($arguments[0]) ? $this->constantPath($arguments[0]) : null;

        $this->followInclude($template, $only, $collector, $depth, $origin);
    }

    private function followInclude(
        ?string $template,
        bool $only,
        PropCollector $collector,
        int $depth,
        string $origin,
    ): void {
        if ($only) {
            // The child sees only what was handed to it. Those expressions
            // were already collected as reads of THIS component; the child's
            // own reads are the child's contract. Nothing is missing here.
            return;
        }

        if ($depth >= 1) {
            $collector->note(self::NOTE_NESTED_INCLUDE, sprintf(
                '%s includes %s, which is a second level of nesting. Resolving it would mean '
                . 'evaluating the template rather than reading it.',
                $origin,
                $template ?? 'a template named by an expression',
            ));

            return;
        }

        if (null === $template) {
            $collector->note(self::NOTE_UNRESOLVED_INCLUDE, sprintf(
                '%s includes a template named by an expression, so which file it is depends on runtime data.',
                $origin,
            ));

            return;
        }

        $source = ($this->resolveTemplate)($template);
        if (null === $source) {
            $collector->note(self::NOTE_UNRESOLVED_INCLUDE, sprintf(
                '%s includes %s, which could not be found. Its reads are part of this component\'s '
                . 'contract (the include inherits this context) and are therefore missing from the result.',
                $origin,
                $template,
            ));

            return;
        }

        $this->collectFrom($source, $template, $collector, $depth + 1);
    }

    /**
     * `{% embed %}` parses its body into a separate module and replaces its
     * own `expr` with the placeholder `not_used`, so the embedded template's
     * path is not where an include keeps it — it is the `parent` of the module
     * Twig created, which the embed node points at only by index.
     */
    private function embeddedTemplateName(EmbedNode $node, PropCollector $collector): ?string
    {
        $module = $collector->embeddedModules[(int) $node->getAttribute('index')] ?? null;

        return null !== $module && $module->hasNode('parent')
            ? $this->constantPath($module->getNode('parent'))
            : null;
    }

    /**
     * `{% import "…" as parts %}` binds a NAME to a template; `{% from "…"
     * import a, b as c %}` binds each alias to the *specific* internal
     * `TemplateVariable` node Twig's parser created for this statement
     * (name left null — the compiler resolves every `macro_*` reference
     * against it by object identity, not positionally, see
     * `PropCollector::$macroFromImportsByRef`). Both are recorded so a
     * later `MacroReferenceExpression` can be resolved unambiguously.
     */
    private function walkImport(ImportNode $node, PropCollector $collector): void
    {
        $template = $this->constantPath($node->getNode('expr'));
        if (null === $template) {
            // A dynamically-named import (`{% import tpl_var as x %}`) is not
            // constant-resolvable. Any macro call through it will fail to
            // resolve too and fall back to a NOTE_UNANALYSED_MACRO note.
            return;
        }

        $varNode = $node->getNode('var');
        $nameNode = $varNode->hasNode('var') ? $varNode->getNode('var') : $varNode;

        if ('from' === $node->getNodeTag()) {
            $collector->macroFromImportsByRef[spl_object_id($nameNode)] = $template;

            return;
        }

        $name = (string) $nameNode->getAttribute('name');

        if ('' !== $name) {
            $collector->macroImports[$name] = $template;
        }
    }

    /**
     * A macro call is only interesting here for the arguments that hand over
     * the bare `content` object (or an alias standing for it) whole — every
     * other argument is walked normally, exactly like any other expression,
     * because either it is not content-rooted at all, or it is a sub-path
     * read that is already fully recorded at this call site (see class
     * docblock § scope note).
     *
     * Arguments compile as flattened (key, value) pairs, and the key node is
     * always a `LocalVariable` whose `name` attribute is either the true
     * *integer* argument position (not a running counter — Twig assigns it
     * from the call site's own encounter order, so it stays correct even
     * when named and positional arguments are interleaved) or the literal
     * parameter *name* Twig parsed off `label: 'x'` / `label = 'x'` named-
     * argument syntax. Named arguments must be mapped by that name, never by
     * position — mapping by position is exactly the bug this class exists to
     * fix (issue #55): `m.card(data: content, label: 'x')` binds `content`
     * to `card`'s FIRST parameter under positional counting, which is wrong
     * whenever the declared order differs from the call's.
     *
     * @param array<string,string> $bindings
     */
    private function walkMacroReference(
        MacroReferenceExpression $node,
        PropCollector $collector,
        array $bindings,
        int $depth,
        string $origin,
    ): void {
        $bindings = $collector->bindings($bindings);

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
            if ('' === $path) {
                $this->followMacro($shortName, $templateVarName, $templateRef, $target, $collector, $depth, $origin);
            } else {
                $this->walk($valueNode, $collector, $bindings, $depth, $origin);
            }
        }
    }

    /**
     * Which macro parameter a call-site argument targets: an integer
     * position, or (for a named argument) its literal name. Neither means the
     * key node wasn't the `LocalVariable` shape a call site's arguments are
     * always compiled as — followMacro() declines rather than guessing.
     *
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
     * Resolves a whole-object macro handoff (tier 2 of issue #55): finds the
     * macro's template via the recorded import bindings — a named import
     * resolved by alias, a `from` import resolved by the exact statement's
     * object identity (never a name/position guess across several `from`
     * imports of the same macro short name, see
     * `PropCollector::$macroFromImportsByRef`) — locates the `MacroNode` by
     * name, maps the receiving argument (by declared parameter name for a
     * named argument, by position otherwise) to its parameter name, and
     * walks the macro body with that parameter bound to the content root —
     * so `content.perex` becomes readable as `perex` inside the macro
     * exactly as it would be at the call site.
     *
     * Anything this cannot pin down statically — an unresolved template, a
     * macro name not found in it, an argument that cannot be mapped to a
     * declared parameter, a second level of nesting — falls back to tier 1:
     * a note, so the incompleteness is visible instead of silent. This never
     * guesses a candidate when the mapping is ambiguous or stale; it declines.
     *
     * @param array{index: ?int, name: ?string} $argumentTarget
     */
    private function followMacro(
        string $shortName,
        ?string $templateVarName,
        int $templateRef,
        array $argumentTarget,
        PropCollector $collector,
        int $depth,
        string $origin,
    ): void {
        $decline = function () use ($collector, $origin, $shortName): void {
            $collector->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands its whole content object to %s(), which could not be resolved statically '
                . '(unresolved import, macro not found, or an argument that cannot be mapped to a '
                . 'declared parameter). Its reads are part of this component\'s contract and are '
                . 'therefore missing from the result.',
                $origin,
                $shortName,
            ));
        };

        if ($depth >= 1) {
            $collector->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands its whole content object to %s(), one level inside an already-followed include/macro. '
                . 'Resolving a second level of nesting would mean evaluating the template rather than reading it.',
                $origin,
                $shortName,
            ));

            return;
        }

        $template = null !== $templateVarName
            ? ($collector->macroImports[$templateVarName] ?? null)
            : ($collector->macroFromImportsByRef[$templateRef] ?? null);

        if (null === $template) {
            $decline();

            return;
        }

        $source = ($this->resolveTemplate)($template);
        if (null === $source) {
            $decline();

            return;
        }

        $macroModule = $this->parse($source, $template, $collector);
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

        $this->walk($macroNode->getNode('body'), $collector, [$paramName => ''], $depth + 1, $template);
    }

    /**
     * The macro parameter an argument target names: a named argument binds
     * directly to the parameter of the same name (never by position — see
     * class docblock on `walkMacroReference()`); a positional argument binds
     * to the parameter declared at that index, or null past the macro's
     * arity.
     *
     * @param array{index: ?int, name: ?string} $argumentTarget
     */
    private function macroParamNameFor(Node $macroNode, array $argumentTarget): ?string
    {
        if (!$macroNode->hasNode('arguments')) {
            return null;
        }

        $names = [];
        foreach ($macroNode->getNode('arguments') as $position => $child) {
            // Same flattened-pairs shape as a call site: even slots are the
            // parameter names, odd slots are their default values.
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

    /**
     * The dotted path a `content.…` chain names, or null when the chain is
     * rooted elsewhere or goes through a non-constant accessor. An
     * empty-string binding means the variable stands for the whole `content`
     * object itself (a macro parameter that received the bare `content`
     * handoff, see walkMacroReference), not a sub-path of it — handled by
     * `resolveContentPath()` in the shared trait.
     *
     * @param array<string,string> $bindings
     */
    private function resolvePath(GetAttrExpression $node, array $bindings): ?string
    {
        return $this->resolveContentPath($node, $bindings);
    }

    /**
     * The content path a variable stands for when it is passed bare (not as
     * part of a `.` chain) — e.g. the `content` argument of a macro call, or
     * an alias bound to the whole object. Returns `''` for the object root
     * itself, a dotted sub-path, or null when the node isn't content-rooted
     * at all.
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

    /** @param array<string,string> $bindings */
    private function rootsInContent(GetAttrExpression $node, array $bindings): bool
    {
        $current = $node;
        while ($current instanceof GetAttrExpression) {
            $current = $current->getNode('node');
        }

        if (!$current instanceof ContextVariable) {
            return false;
        }

        $root = (string) $current->getAttribute('name');

        return self::ROOT === $root || isset($bindings[$root]);
    }
}
