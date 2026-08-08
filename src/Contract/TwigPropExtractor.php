<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\EmbedNode;
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
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

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

        return new PropReads($reads, $collector->notes);
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
        $loader = new ArrayLoader([$name => $source]);
        $env = new Environment($loader, ['cache' => false]);

        // A component twig is written against a project's own Twig
        // environment — Timber's functions, a theme's filters. This one knows
        // none of them, and an unknown callable is a parse error in Twig. The
        // extractor does not care what any of them DO; it needs the tree.
        // Unknown TAGS still fail, and are reported: a tag can change control
        // flow, so guessing past one would produce a confidently wrong answer.
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
            $collector->note(self::NOTE_PARSE_ERROR, sprintf('%s: %s', $name, $e->getRawMessage()));

            return null;
        }
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

        foreach ($node as $child) {
            $this->walk($child, $collector, $bindings, $depth, $origin);
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
     * import a, b as c %}` binds macro names directly without a template
     * variable (Twig's parser leaves that variable's name null and resolves
     * every `macro_*` reference in the module against it positionally). Both
     * are recorded so a later `MacroReferenceExpression` can be resolved.
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

        if ('from' === $node->getNodeTag()) {
            $collector->macroFromImports[] = $template;

            return;
        }

        $varNode = $node->getNode('var');
        $nameNode = $varNode->hasNode('var') ? $varNode->getNode('var') : $varNode;
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
        $templateVarName = $templateVar->hasAttribute('name') ? $templateVar->getAttribute('name') : null;

        $argumentIndex = 0;
        foreach ($node->getNode('arguments') as $position => $child) {
            // Arguments compile as flattened (positional-key, value) pairs —
            // only the odd slots are the actual expressions.
            if (0 === (int) $position % 2) {
                continue;
            }

            $path = $this->bareContentPath($child, $bindings);
            if ('' === $path) {
                $this->followMacro($shortName, $templateVarName, $argumentIndex, $collector, $depth, $origin);
            } else {
                $this->walk($child, $collector, $bindings, $depth, $origin);
            }

            ++$argumentIndex;
        }
    }

    /**
     * Resolves a whole-object macro handoff (tier 2 of issue #55): finds the
     * macro's template via the recorded import bindings, locates the
     * `MacroNode` by name, maps the receiving argument position to its
     * parameter name, and walks the macro body with that parameter bound to
     * the content root — so `content.perex` becomes readable as `perex`
     * inside the macro exactly as it would be at the call site.
     *
     * Anything this cannot pin down statically — an unresolved template, a
     * macro name not found in it, an argument position past the macro's
     * declared parameters, a second level of nesting — falls back to tier 1:
     * a note, so the incompleteness is visible instead of silent.
     */
    private function followMacro(
        string $shortName,
        ?string $templateVarName,
        int $argumentIndex,
        PropCollector $collector,
        int $depth,
        string $origin,
    ): void {
        if ($depth >= 1) {
            $collector->note(self::NOTE_UNANALYSED_MACRO, sprintf(
                '%s hands its whole content object to %s(), one level inside an already-followed include/macro. '
                . 'Resolving a second level of nesting would mean evaluating the template rather than reading it.',
                $origin,
                $shortName,
            ));

            return;
        }

        $candidates = null !== $templateVarName
            ? (isset($collector->macroImports[$templateVarName]) ? [$collector->macroImports[$templateVarName]] : [])
            : $collector->macroFromImports;

        foreach ($candidates as $template) {
            $source = ($this->resolveTemplate)($template);
            if (null === $source) {
                continue;
            }

            $macroModule = $this->parse($source, $template, $collector);
            if (null === $macroModule || !$macroModule->hasNode('macros')) {
                continue;
            }

            $macrosNode = $macroModule->getNode('macros');
            if (!$macrosNode->hasNode($shortName)) {
                continue;
            }

            $macroNode = $macrosNode->getNode($shortName);
            $paramName = $this->macroParamNameAt($macroNode, $argumentIndex);
            if (null === $paramName) {
                continue;
            }

            $this->walk($macroNode->getNode('body'), $collector, [$paramName => ''], $depth + 1, $template);

            return;
        }

        $collector->note(self::NOTE_UNANALYSED_MACRO, sprintf(
            '%s hands its whole content object to %s(), which could not be resolved statically '
            . '(unresolved import, macro not found, or an unrecognised argument shape). Its reads are part '
            . 'of this component\'s contract and are therefore missing from the result.',
            $origin,
            $shortName,
        ));
    }

    /** The name of the macro's Nth declared parameter, or null past its arity. */
    private function macroParamNameAt(Node $macroNode, int $argumentIndex): ?string
    {
        if (!$macroNode->hasNode('arguments')) {
            return null;
        }

        $names = [];
        foreach ($macroNode->getNode('arguments') as $position => $child) {
            // Same flattened-pairs shape as a call site: even slots are the
            // parameter names, odd slots are their default values.
            if (0 === (int) $position % 2) {
                $names[] = $child;
            }
        }

        $nameNode = $names[$argumentIndex] ?? null;

        return $nameNode instanceof LocalVariable ? (string) $nameNode->getAttribute('name') : null;
    }

    private function constantPath(Node $node): ?string
    {
        if (!$node instanceof ConstantExpression) {
            return null;
        }

        $value = $node->getAttribute('value');

        return is_string($value) ? $value : null;
    }

    /**
     * The dotted path a `content.…` chain names, or null when the chain is
     * rooted elsewhere or goes through a non-constant accessor.
     *
     * @param array<string,string> $bindings
     */
    private function resolvePath(GetAttrExpression $node, array $bindings): ?string
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

            // `content.items[0].title` — an array index is not a prop name.
            // The prop is the one it indexes into, and `title` is a field of
            // its rows, so the index is dropped and the chain closes up.
            if (is_string($value) && !is_numeric($value)) {
                array_unshift($segments, $value);
            }

            $current = $current->getNode('node');
        }

        if (!$current instanceof ContextVariable) {
            return null;
        }

        $root = (string) $current->getAttribute('name');

        if (self::ROOT === $root) {
            return [] === $segments ? null : implode('.', $segments);
        }

        if (isset($bindings[$root])) {
            // An empty-string binding means the variable stands for the
            // whole `content` object itself (a macro parameter that
            // received the bare `content` handoff, see walkMacroReference),
            // not a sub-path of it — so it contributes no prefix segment.
            $prefix = $bindings[$root];

            return '' === $prefix ? implode('.', $segments) : implode('.', [$prefix, ...$segments]);
        }

        return null;
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
