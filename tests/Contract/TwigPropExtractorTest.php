<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Contract\PropReads;
use Parisek\DefinitionKit\Contract\TwigPropExtractor;

/**
 * The extractor is the piece of #27 that can fail quietly: a read it misses
 * makes an incomplete definition look complete, which is worse than no check
 * at all. Every limit it has is therefore pinned here as a REPORTED limit,
 * not merely an absent read.
 */
final class TwigPropExtractorTest extends TestCase
{
    /** @param array<string,string> $templates */
    private function extract(string $source, array $templates = []): PropReads
    {
        return (new TwigPropExtractor(
            static fn (string $path): ?string => $templates[$path] ?? null,
        ))->extract($source);
    }

    public function testPlainContentReadsAreCollectedAtAnyDepth(): void
    {
        $reads = $this->extract('{{ content.title }} {{ content.heading.subtitle }}');

        self::assertSame(['heading.subtitle', 'title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testALoopOverAContentPathRebindsItsValueVariable(): void
    {
        $reads = $this->extract(
            '{% for item in content.items %}{{ item.value }}{{ item.link.url }}{% endfor %}'
        );

        self::assertSame(['items', 'items.link.url', 'items.value'], $reads->reads);
    }

    public function testNestedLoopsCompose(): void
    {
        $reads = $this->extract(<<<'TWIG'
        {% for row in content.rows %}
          {% for cell in row.cells %}{{ cell.label }}{% endfor %}
        {% endfor %}
        TWIG);

        self::assertContains('rows.cells.label', $reads->reads);
    }

    public function testALoopOverSomethingElseDoesNotInventContentReads(): void
    {
        $reads = $this->extract('{% for post in posts %}{{ post.title }}{% endfor %}');

        self::assertSame([], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testSetAliasesAreFollowed(): void
    {
        // The silent-false-negative case: without alias tracking this reports
        // `heading` and nothing else, and a definition missing `heading.title`
        // passes the check.
        $reads = $this->extract('{% set h = content.heading %}{{ h.title }}');

        self::assertContains('heading.title', $reads->reads);
    }

    public function testAPropMentionedOnlyInACommentIsNotARead(): void
    {
        $reads = $this->extract('{# {{ content.legacy_title }} #}{{ content.title }}');

        self::assertSame(['title'], $reads->reads);
    }

    public function testOneLevelIncludeIsResolved(): void
    {
        $reads = $this->extract(
            '{{ content.title }}{% include "partial.twig" %}',
            ['partial.twig' => '{{ content.subtitle }}'],
        );

        self::assertSame(['subtitle', 'title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testTheIncludeFunctionFormIsResolvedToo(): void
    {
        $reads = $this->extract(
            '{{ include("partial.twig") }}',
            ['partial.twig' => '{{ content.subtitle }}'],
        );

        self::assertSame(['subtitle'], $reads->reads);
    }

    public function testASecondLevelOfNestingIsReportedRatherThanGuessed(): void
    {
        $reads = $this->extract(
            '{% include "a.twig" %}',
            [
                'a.twig' => '{{ content.one }}{% include "b.twig" %}',
                'b.twig' => '{{ content.two }}',
            ],
        );

        self::assertSame(['one'], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_NESTED_INCLUDE], $reads->noteKinds());
        self::assertStringContainsString('b.twig', $reads->notes[0]['detail']);
    }

    public function testAnIncludeWithOnlyStopsAtTheBoundaryAndIsNotAGap(): void
    {
        // `only` hands the child a fresh context. What was handed over is a
        // read of THIS component; what the child does with it is the child's
        // contract. Nothing is unresolved, so nothing is reported.
        $reads = $this->extract(
            '{% include "button.twig" with { label: content.cta_label } only %}',
            ['button.twig' => '{{ content.inner }}'],
        );

        self::assertSame(['cta_label'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testAnEmbedBodyIsWalkedAndItsParentResolvedOneLevel(): void
    {
        $reads = $this->extract(
            '{% embed "card.twig" %}{% block body %}{{ content.excerpt }}{% endblock %}{% endembed %}',
            ['card.twig' => '{{ content.card_title }}'],
        );

        self::assertContains('excerpt', $reads->reads);
        self::assertContains('card_title', $reads->reads);
    }

    public function testAnIncludeThatCannotBeFoundIsReported(): void
    {
        $reads = $this->extract('{% include "missing.twig" %}');

        self::assertSame([TwigPropExtractor::NOTE_UNRESOLVED_INCLUDE], $reads->noteKinds());
    }

    public function testAnIncludeNamedByAnExpressionIsReported(): void
    {
        $reads = $this->extract('{% include "partials/" ~ content.variant ~ ".twig" %}');

        // The expression naming the template is itself a read.
        self::assertSame(['variant'], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNRESOLVED_INCLUDE], $reads->noteKinds());
    }

    public function testDynamicAccessIsReportedAsUnanalysable(): void
    {
        $reads = $this->extract('{{ attribute(content, key) }}');

        self::assertSame([], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_DYNAMIC_ACCESS], $reads->noteKinds());
    }

    public function testDynamicAccessOnALoopBindingIsAlsoReported(): void
    {
        $reads = $this->extract('{% for item in content.items %}{{ attribute(item, key) }}{% endfor %}');

        self::assertSame([TwigPropExtractor::NOTE_DYNAMIC_ACCESS], $reads->noteKinds());
    }

    public function testAnUnknownTagIsReportedRatherThanCrashing(): void
    {
        // A project tag this environment does not know. A tag can change
        // control flow, so parsing past one would give a confidently wrong
        // answer — the component is reported unanalysable instead.
        $reads = $this->extract('{% cache "key" %}{{ content.title }}{% endcache %}');

        self::assertSame([TwigPropExtractor::NOTE_PARSE_ERROR], $reads->noteKinds());
    }

    public function testUnknownFunctionsFiltersAndTestsDoNotStopTheParse(): void
    {
        // Timber's own vocabulary. The extractor needs the tree, not the
        // behaviour — these must not be mistaken for the unknown-TAG case.
        $reads = $this->extract(
            '{{ fn("x") }}{{ content.image|resize(100)|apply_filters }}'
            . '{% if content.post is timber_post %}{{ content.post.title }}{% endif %}',
        );

        self::assertSame(['image', 'post', 'post.title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testNumericIndexesAreNotPropNames(): void
    {
        $reads = $this->extract('{{ content.items[0].title }}');

        self::assertSame(['items.title'], $reads->reads);
    }

    public function testFrameworkInjectedPropsAreReadsLikeAnyOther(): void
    {
        // The extractor does not know about the baseline — suppression is the
        // check's job, and keeping it there is what makes the baseline the
        // single place that decides.
        $reads = $this->extract('<div id="{{ content.wrapper_id }}" class="{{ content.wrapper_classes }}">');

        self::assertSame(['wrapper_classes', 'wrapper_id'], $reads->reads);
    }

    public function testExtractFileReadsFromDisk(): void
    {
        $path = sys_get_temp_dir() . '/prop-extractor-' . uniqid('', true) . '.twig';
        file_put_contents($path, '{{ content.title }}');

        try {
            self::assertSame(['title'], (new TwigPropExtractor())->extractFile($path)->reads);
        } finally {
            unlink($path);
        }
    }

    // -- issue #55: whole-object macro handoff -------------------------------

    public function testBareContentIntoAResolvableMacroIsFollowed(): void
    {
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ content.title }}{{ parts.split(content) }}',
            ['parts.twig' => '{% macro split(content) %}{{ content.perex }}{{ content.image }}{% endmacro %}'],
        );

        self::assertSame(['image', 'perex', 'title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testFromImportIsFollowedToo(): void
    {
        $reads = $this->extract(
            '{% from "parts.twig" import split %}{{ split(content) }}',
            ['parts.twig' => '{% macro split(content) %}{{ content.perex }}{% endmacro %}'],
        );

        self::assertSame(['perex'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testBareContentIntoAnUnresolvableMacroIsNoted(): void
    {
        // No `resolveTemplate` mapping for parts.twig — the import can't be
        // followed, so the handoff must be visible as a note rather than
        // silently vanishing.
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ content.title }}{{ parts.split(content) }}',
        );

        self::assertSame(['title'], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $reads->noteKinds());
        self::assertFalse($reads->isFullyAnalysed());
    }

    public function testAMacroNameNotFoundInTheImportedTemplateIsNoted(): void
    {
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ parts.missing(content) }}',
            ['parts.twig' => '{% macro split(content) %}{{ content.perex }}{% endmacro %}'],
        );

        self::assertSame([], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $reads->noteKinds());
    }

    public function testASubPathArgumentIsUnchangedBehaviour(): void
    {
        // parts.heading_title(content.title) is already correct today: the
        // read is recorded at the call site, and the macro body only ever
        // sees a scalar, so it must not be walked at all.
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ parts.heading_title(content.title) }}',
            ['parts.twig' => '{% macro heading_title(title) %}{{ title }}{% endmacro %}'],
        );

        self::assertSame(['title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testAMacroThatItselfCallsAnotherMacroHitsTheDepthGuard(): void
    {
        // Mirrors the real page-header-* components: the top-level macro is
        // followed (tier 2), but its own whole-object handoff to a second
        // macro is a second level of nesting and falls back to tier 1,
        // exactly like NOTE_NESTED_INCLUDE does for includes.
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ parts.split(content) }}',
            ['parts.twig' => <<<'TWIG'
            {% macro split(content) %}
                {{ content.perex }}
                {% import "buttons.twig" as buttons %}
                {{ buttons.render(content) }}
            {% endmacro %}
            TWIG,
                'buttons.twig' => '{% macro render(content) %}{{ content.primary_button }}{% endmacro %}',
            ],
        );

        self::assertSame(['perex'], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $reads->noteKinds());
        self::assertFalse($reads->isFullyAnalysed());
    }

    public function testTheRealPageHeaderComponentReportsTheMacroReadsOrNotesTheGap(): void
    {
        // Regression pin for issue #55 itself: a bare `content` handed to a
        // macro import must never leave `isFullyAnalysed()` true while
        // hiding reads — either the extra reads show up, or a note does.
        $reads = $this->extract(
            '{% import "parts.twig" as parts %}{{ content.title }}{{ parts.page_header_split(content, "lg:w-7/12", "text", true) }}',
            ['parts.twig' => <<<'TWIG'
            {% macro page_header_split(content, text_width, buttons_position, side_slots) %}
                {{ content.perex }}
                {% if content.image %}{{ content.image }}{% endif %}
            {% endmacro %}
            TWIG],
        );

        self::assertContains('title', $reads->reads);
        self::assertSame(['image', 'perex', 'title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    // -- review findings on #57: named arguments, ambiguous/stale bindings --

    public function testANamedArgumentBindsToItsOwnParameterNotItsPosition(): void
    {
        // The exact reproduction from the #57 review: `data` is declared
        // SECOND but passed by name, so a positional-counting resolver binds
        // it to `label` (the FIRST parameter) instead — the same silent
        // incompleteness issue #55 itself was about. This must report
        // `data`'s actual reads, never an empty, "fully analysed" result.
        $reads = $this->extract(
            "{% import 'm.twig' as m %}{{ m.card(data: content, label: 'x') }}",
            ['m.twig' => '{% macro card(label, data) %}{{ data.title }}{{ data.perex }}{% endmacro %}'],
        );

        self::assertSame(['perex', 'title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testANamedArgumentAfterPositionalOnesStillResolves(): void
    {
        $reads = $this->extract(
            "{% import 'm.twig' as m %}{{ m.card('x', data: content) }}",
            ['m.twig' => '{% macro card(label, data) %}{{ data.title }}{% endmacro %}'],
        );

        self::assertSame(['title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testANamedArgumentNamingAnUndeclaredParameterIsNoted(): void
    {
        // `misspelled` is not one of `card`'s parameters — the macro-call
        // shape itself would be a Twig runtime error, but the extractor
        // must decline rather than guess a parameter to bind it to.
        $reads = $this->extract(
            "{% import 'm.twig' as m %}{{ m.card(misspelled: content) }}",
            ['m.twig' => '{% macro card(label, data) %}{{ data.title }}{% endmacro %}'],
        );

        self::assertSame([], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $reads->noteKinds());
    }

    public function testContentInANonFirstPositionalArgumentIsFollowed(): void
    {
        $reads = $this->extract(
            '{% import "m.twig" as m %}{{ m.card("x", content) }}',
            ['m.twig' => '{% macro card(label, data) %}{{ data.title }}{% endmacro %}'],
        );

        self::assertSame(['title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testAnArgumentPastTheMacroArityIsNoted(): void
    {
        $reads = $this->extract(
            '{% import "m.twig" as m %}{{ m.card("x", "y", content) }}',
            ['m.twig' => '{% macro card(label, data) %}{{ data.title }}{% endmacro %}'],
        );

        self::assertSame([], $reads->reads);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $reads->noteKinds());
    }

    public function testAMacroParameterWithADefaultValueIsStillAValidTarget(): void
    {
        $reads = $this->extract(
            '{% import "m.twig" as m %}{{ m.card(content) }}',
            ['m.twig' => "{% macro card(data = {}) %}{{ data.title }}{% endmacro %}"],
        );

        self::assertSame(['title'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testTwoFromImportsOfTheSameMacroNameResolveToTheirOwnTemplate(): void
    {
        // The exact reproduction from the #57 review: two `{% from %}`
        // imports rename the same macro short name from two DIFFERENT
        // templates. Before the fix, the extractor tried encounter-order
        // candidates and silently invented a read from the wrong template —
        // worse than a missed read, because nothing marks it as wrong.
        $reads = $this->extract(
            "{% from 'a.twig' import foo as one %}{% from 'b.twig' import foo as two %}{{ two(content) }}",
            [
                'a.twig' => '{% macro foo(content) %}{{ content.from_a }}{% endmacro %}',
                'b.twig' => '{% macro foo(content) %}{{ content.from_b }}{% endmacro %}',
            ],
        );

        self::assertSame(['from_b'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testBothAliasesOfAColliddingFromImportResolveIndependently(): void
    {
        $reads = $this->extract(
            "{% from 'a.twig' import foo as one %}{% from 'b.twig' import foo as two %}{{ one(content) }}{{ two(content) }}",
            [
                'a.twig' => '{% macro foo(content) %}{{ content.from_a }}{% endmacro %}',
                'b.twig' => '{% macro foo(content) %}{{ content.from_b }}{% endmacro %}',
            ],
        );

        self::assertSame(['from_a', 'from_b'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testSetRebindingAnImportedMacroNamespaceDoesNotChangeWhichTemplateItFollows(): void
    {
        // `{% set parts = other %}` reassigns the ordinary context variable
        // `parts`, which is NOT what a `parts.foo()` dot-call resolves
        // through — Twig's parser binds a macro dot-call to its import
        // statically, by object identity, at parse time, entirely separate
        // from the `$context`-scoped variable `{% set %}` writes to. Verified
        // against real Twig (see #57 review finding 3 investigation): calling
        // `parts.foo()` after `{% set parts = other %}` still runs `a.twig`'s
        // `foo`, never `other`'s. This pins that the extractor matches real
        // Twig semantics — there is nothing to invalidate on `{% set %}`.
        $reads = $this->extract(
            "{% import 'a.twig' as parts %}{% import 'b.twig' as other %}"
            . "{% set parts = other %}{{ parts.foo(content) }}",
            [
                'a.twig' => '{% macro foo(content) %}{{ content.from_a }}{% endmacro %}',
                'b.twig' => '{% macro foo(content) %}{{ content.from_b }}{% endmacro %}',
            ],
        );

        self::assertSame(['from_a'], $reads->reads);
        self::assertTrue($reads->isFullyAnalysed());
    }

    public function testSelfReferentialMacroHandoffIsNotedRatherThanEvaluated(): void
    {
        // `_self` calling another macro within the same module is still a
        // whole-object handoff; nothing here should recurse or infinite-loop.
        $reads = $this->extract(
            '{% macro outer(content) %}{{ _self.inner(content) }}{% endmacro %}'
            . '{% macro inner(content) %}{{ content.title }}{% endmacro %}'
            . '{{ _self.outer(content) }}',
        );

        self::assertTrue(
            [] === $reads->reads || in_array('title', $reads->reads, true),
        );
        self::assertFalse($reads->isFullyAnalysed());
    }
}
