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
 * ## Styleguide fixtures are not call sites
 *
 * `styleguide.*.twig` renders a component with sample data, and in a styleguide
 * repository that is the ONLY data source — every prop of every component is
 * "passed by a template" there, including the ones an editor authors. Counting
 * fixtures would therefore label a whole skeleton `parent`.
 *
 * Measured on mairateam: 191 props are evidenced only by fixtures against 58 by
 * production templates. That project has an `acf.json` per component, and
 * `field` outranks a call site, so nothing broke; a CMS-agnostic skeleton has no
 * acf.json to outrank anything, and every one of those 191 would have been
 * mislabelled. tailwind-base's own migration notes reached the same conclusion
 * from the other direction (docs/398-unresolved-roles.md).
 *
 * The rule call sites serve is not "somebody passes it". It is what the prop
 * IS: content an editor would author is `field` even when only a fixture
 * supplies it here, and a prop that exists so a parent can wire a child into
 * its composition is `parent`. Call-site evidence decides ambiguous cases; it
 * does not decide the rule.
 *
 * A component nobody renders has no call sites, and that is information too:
 * it means `parent` cannot be proposed for it, not that the prop is something
 * else.
 */
final class CallSiteIndex
{
    private const COMPONENT_FUNCTION_PREFIX = 'component_';

    /** Fixtures render a component with sample data; they are not composition. */
    private const FIXTURE_PREFIX = 'styleguide';

    /** @var array<string,list<string>> component name => props passed to it by callers */
    private array $passed = [];

    /**
     * The same, from `styleguide.*.twig` only. Not evidence — but knowing a
     * prop is fixture-only is worth more to whoever reviews a blank than
     * silence is: it says "somebody decided what this looks like, go read that
     * fixture", and it is the exact question `field` vs `parent` turns on.
     *
     * @var array<string,list<string>>
     */
    private array $passedByFixtures = [];

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

            $this->isFixture = str_starts_with(basename($path), self::FIXTURE_PREFIX);
            $this->walk($module);
        }

        $this->isFixture = false;
    }

    private bool $isFixture = false;

    /** @return list<string> */
    public function propsPassedTo(string $component): array
    {
        return $this->passed[$component] ?? [];
    }

    /** Passed only by a `styleguide.*.twig` fixture — a hint, never evidence. */
    public function isPassedOnlyByFixtureTo(string $component, string $prop): bool
    {
        return in_array($prop, $this->passedByFixtures[$component] ?? [], true)
            && !$this->isPassedTo($component, $prop);
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

            if ($this->isFixture) {
                $this->passedByFixtures[$component] ??= [];
                if (!in_array($name, $this->passedByFixtures[$component], true)) {
                    $this->passedByFixtures[$component][] = $name;
                }

                continue;
            }

            $this->passed[$component] ??= [];
            if (!in_array($name, $this->passed[$component], true)) {
                $this->passed[$component][] = $name;
            }
        }
    }
}
