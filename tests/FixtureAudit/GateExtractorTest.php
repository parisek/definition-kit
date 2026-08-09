<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\FixtureAudit;

use Parisek\DefinitionKit\FixtureAudit\GateExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for `GateExtractor`'s AST-walking surface — the CLI
 * fixture-tree tests in `AuditorTest` exercise it end-to-end, but a bug
 * confined to one node-kind branch (an `include()` function call, an
 * imported macro reference) is easier to pin down with an inline Twig
 * source string than by threading it through a full fixture render.
 *
 * MUST-FIX 1 (fields-fixtures-auditor review): this extractor used to have
 * NO handling at all for `include()` function calls, `{% import %}`, or a
 * macro call handed the bare `content` object/sub-path — a gate reached only
 * through one of those forms went undetected, silently (a false-clean
 * audit). These tests are the regression guard for that gap being closed.
 */
final class GateExtractorTest extends TestCase
{
    public function testGateInsideAnIncludeFunctionCallIsFollowed(): void
    {
        $partial = '{% if content.flag %}shown{% endif %}';
        $extractor = new GateExtractor(static fn (string $path): ?string => '_partial.twig' === $path ? $partial : null);

        $gates = $extractor->extract("{{ include('_partial.twig') }}");

        self::assertSame(['flag'], $gates);
    }

    public function testGateInsideAnUnresolvableIncludeFunctionCallIsDeclaredNotSilentlyDropped(): void
    {
        $extractor = new GateExtractor(static fn (string $path): ?string => null);

        $gates = $extractor->extract("{{ include('missing.twig') }}");

        self::assertSame([], $gates, 'an unresolvable include must not fabricate a gate');
        $notes = $extractor->notes();
        self::assertNotEmpty($notes, 'an unresolvable include must be declared as incomplete, not silently dropped');
        self::assertSame(GateExtractor::NOTE_UNRESOLVED_INCLUDE, $notes[0]['kind']);
    }

    public function testGateInsideAMacroHandedAResolvableContentSubPathIsFollowed(): void
    {
        $macros = <<<'TWIG'
            {% macro card(data) %}
                {% if data.flag %}shown{% endif %}
            {% endmacro %}
            TWIG;
        $extractor = new GateExtractor(static fn (string $path): ?string => 'macros.twig' === $path ? $macros : null);

        $gates = $extractor->extract("{% import 'macros.twig' as m %}{{ m.card(content.section) }}");

        self::assertSame(['section.flag'], $gates);
    }

    public function testGateInsideAMacroHandedTheWholeContentObjectIsDeclaredNotSilentlyDropped(): void
    {
        $macros = <<<'TWIG'
            {% macro card(data) %}
                {% if data.flag %}shown{% endif %}
            {% endmacro %}
            TWIG;
        $extractor = new GateExtractor(static fn (string $path): ?string => 'macros.twig' === $path ? $macros : null);

        $gates = $extractor->extract("{% import 'macros.twig' as m %}{{ m.card(content) }}");

        self::assertSame([], $gates, 'a whole-object handoff has no single content.* path to record a gate against');
        $notes = $extractor->notes();
        self::assertNotEmpty($notes, 'a whole-object macro handoff must be declared as incomplete, not silently dropped');
        self::assertSame(GateExtractor::NOTE_UNANALYSED_MACRO, $notes[0]['kind']);
    }

    public function testGateInsideAnUnresolvedMacroImportIsDeclaredNotSilentlyDropped(): void
    {
        $extractor = new GateExtractor(static fn (string $path): ?string => null);

        $gates = $extractor->extract("{% import 'macros.twig' as m %}{{ m.card(content.section) }}");

        self::assertSame([], $gates);
        $notes = $extractor->notes();
        self::assertNotEmpty($notes);
        self::assertSame(GateExtractor::NOTE_UNANALYSED_MACRO, $notes[0]['kind']);
    }

    public function testOrdinaryDirectGateStillWorks(): void
    {
        $extractor = new GateExtractor();

        $gates = $extractor->extract('{% if content.flag %}shown{% endif %}');

        self::assertSame(['flag'], $gates);
    }
}
