<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Which props each component is handed by the templates that render it
 * (issue #27, phase 4) — the evidence behind a proposed `role: parent`.
 *
 * Two call shapes, because real projects use both:
 *
 * - `{% include "button/button.twig" with { label: … } %}`
 * - `{{ component_button({ label: … }) }}` — parisek/timber-kit's `component_*`
 *   Twig function (`StarterBase::twig_component_template()`), which resolves
 *   `@component/<slug>/<slug>.twig` and merges its array argument into
 *   `context.content`. `_` in the function name is the component's `-`.
 *
 * The second shape was invisible to the first version of this class, and a run
 * over a real 69-component project found 304 of those calls and zero includes
 * between components — so `role: parent` was proposable exactly nowhere. A
 * convention that carries every call site in a project is not a detail.
 *
 * Only explicit keys count, in both shapes. A bare include passes the caller's
 * whole context, which says nothing about any particular prop; taking it as
 * evidence would let every component claim every prop.
 *
 * A component nobody renders has no call sites, and that is information too:
 * it means `parent` cannot be proposed for it, not that the prop is something
 * else.
 */
final class CallSiteIndex
{
    private const COMPONENT_FUNCTION_PREFIX = 'component_';

    /** @var array<string,list<string>> component name => props passed to it by callers */
    private array $passed = [];

    /**
     * @param list<string> $twigPaths every component template in the project
     */
    public function __construct(array $twigPaths)
    {
        foreach ($twigPaths as $path) {
            $source = file_get_contents($path);
            if (false === $source) {
                continue;
            }

            $module = $this->parse($source, basename($path));
            if (null === $module) {
                continue;
            }

            $this->walk($module);
        }
    }

    /** @return list<string> */
    public function propsPassedTo(string $component): array
    {
        return $this->passed[$component] ?? [];
    }

    public function isPassedTo(string $component, string $prop): bool
    {
        return in_array($prop, $this->propsPassedTo($component), true);
    }

    private function parse(string $source, string $name): ?ModuleNode
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
        } catch (SyntaxError) {
            // A template this environment cannot parse contributes no call
            // sites. It is not silence about the component it includes — the
            // proposer leaves anything it has no evidence for blank anyway.
            return null;
        }
    }

    private function walk(Node $node): void
    {
        // An `{% embed %}`'s own expr is a placeholder — its parent path is
        // only reachable through the module Twig parsed the body into, which
        // this index does not track. Embeds therefore contribute no call
        // sites, stated here rather than silently recorded against the wrong
        // component name.
        if ($node instanceof IncludeNode && !$node instanceof EmbedNode) {
            $target = $this->componentOf($node->getNode('expr'));

            if (null !== $target && $node->hasNode('variables')) {
                $this->record($target, $node->getNode('variables'));
            }
        }

        if ($node instanceof FunctionExpression) {
            $function = (string) $node->getAttribute('name');
            $arguments = iterator_to_array($node->getNode('arguments'));

            if ('include' === $function) {
                $target = isset($arguments[0]) ? $this->componentOf($arguments[0]) : null;
                if (null !== $target && isset($arguments[1])) {
                    $this->record($target, $arguments[1]);
                }
            }

            if (str_starts_with($function, self::COMPONENT_FUNCTION_PREFIX)) {
                // `component_page_header_default({ … })` renders
                // `page-header-default`: the function name is the slug with
                // `-` spelled `_`, per StarterBase's own normalisation.
                $slug = str_replace('_', '-', substr($function, strlen(self::COMPONENT_FUNCTION_PREFIX)));
                if ('' !== $slug && isset($arguments[0])) {
                    $this->record($slug, $arguments[0]);
                }
            }
        }

        foreach ($node as $child) {
            $this->walk($child);
        }

        if ($node instanceof ModuleNode) {
            $embedded = $node->getAttribute('embedded_templates');
            if (is_iterable($embedded)) {
                foreach ($embedded as $embeddedModule) {
                    if ($embeddedModule instanceof ModuleNode) {
                        $this->walk($embeddedModule);
                    }
                }
            }
        }
    }

    private function componentOf(Node $expr): ?string
    {
        if (!$expr instanceof ConstantExpression) {
            return null;
        }

        $path = $expr->getAttribute('value');
        if (!is_string($path)) {
            return null;
        }

        return basename($path, '.twig');
    }

    private function record(string $component, Node $variables): void
    {
        if ($variables instanceof FilterExpression) {
            // `component_promo(styleguide_data()|merge({ background: 'primary' }))`
            // — the literal keys are in the filter's arguments, and the piped
            // value is whatever the fixture loaded. Take the literal half; the
            // other half names no props statically.
            foreach ($variables->getNode('arguments') as $argument) {
                $this->record($component, $argument);
            }

            return;
        }

        if (!$variables instanceof ArrayExpression) {
            return;
        }

        $entries = iterator_to_array($variables);
        // A Twig ArrayExpression is a flat key, value, key, value list.
        for ($i = 0; $i < count($entries); $i += 2) {
            $key = $entries[$i] ?? null;
            if (!$key instanceof ConstantExpression) {
                continue;
            }

            $name = $key->getAttribute('value');
            if (!is_string($name)) {
                continue;
            }

            $this->passed[$component] ??= [];
            if (!in_array($name, $this->passed[$component], true)) {
                $this->passed[$component][] = $name;
            }
        }
    }
}
