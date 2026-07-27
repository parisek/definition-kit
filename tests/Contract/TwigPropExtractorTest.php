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
}
