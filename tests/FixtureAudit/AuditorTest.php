<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\FixtureAudit;

use PHPUnit\Framework\TestCase;

/**
 * Permanent calibration tests for the fixture-coverage audit, ported from
 * `portadesign/tailwind-base`'s `static/tests/fields-contract.spec.js`
 * (issue #54, phase 2 of the migration — see `Auditor`'s class docblock).
 *
 * These assert CLASSIFICATION, not just "something was reported". Each case
 * below is a hard case named in the original design doc (referenced from
 * `Auditor`'s own docblock):
 *
 *   - a null-valued key is "shape supplied" but NOT "branch exercised"
 *   - a component with no styleguide*.twig at all is never-rendered/skip
 *   - nested component_* calls are NOT truncated by the recorder
 *   - a render failure is distinguished from every other outcome, not
 *     silently absorbed
 *   - the collector is deterministic: same input, byte-identical output
 *
 * The original was a Node runner shelling out to the real PHP CLI, matching
 * that project's precedent for its own lint fixtures (twig-cs-fixer/eslint/
 * stylelint). This package has no such precedent and is PHP-only, so this
 * port keeps the same "shell out to the real CLI, assert on its output"
 * shape but as a PHPUnit test calling `bin/fields-fixtures` via `proc_open`.
 */
final class AuditorTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/fields-fixtures';
    private const FIXTURES = __DIR__ . '/fixtures';

    /**
     * Runs the auditor against the small fixture tree under
     * tests/FixtureAudit/fixtures/ — isolates each calibration case from
     * unrelated real-component findings and keeps the test suite fast.
     *
     * @return array{output: string, status: int}
     */
    private static function runAuditor(bool $json = false): array
    {
        $cmd = [
            PHP_BINARY,
            self::BIN,
            '--templates=' . self::FIXTURES,
            '--static=' . self::FIXTURES,
            '--config=' . self::FIXTURES . '/no-such-styleguide.yaml',
        ];
        if ($json) {
            $cmd[] = '--json';
        }

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['output' => $stdout, 'status' => $status];
    }

    public function testNullValuedKeyShapeSuppliedBranchNotExercised(): void
    {
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression(
            '/nullkey\/styleguide\.twig:\d+\n\s+notice\s+unexercised-branch\s+flag\s/',
            $output,
            'a null-valued `flag` must be reported as unexercised-branch (supplied, not truthy)',
        );
        self::assertDoesNotMatchRegularExpression('/dead-declaration\s+flag\b/', $output);
        self::assertDoesNotMatchRegularExpression('/dead-fixture-key\s+flag\b/', $output);
    }

    public function testComponentWithNoStyleguideTwigIsNeverRenderedSkip(): void
    {
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression('/component\/nofixture\n\s+skip\s+never-rendered/', $output);
        self::assertMatchesRegularExpression('/^nofixture\s+SKIP$/m', $output);
    }

    public function testNestedComponentCallsAreNotTruncatedByTheRecorder(): void
    {
        $output = self::runAuditor()['output'];
        // `parent`'s own styleguide.twig supplies `items: [{label: 'nested'}]`;
        // parent.twig only calls component_child(...) INSIDE a conditional on
        // its OWN content, one level removed from parent's own fixture. This
        // fails two different ways if either defect comes back: recorder
        // truncation, or a broken context-merge on the recorder delegate.
        self::assertDoesNotMatchRegularExpression(
            '/child\/child\.yaml:\d+\n\s+notice\s+undemonstrated-field\s+label\b/',
            $output,
        );
        self::assertMatchesRegularExpression('/^child\s+OK$/m', $output);
    }

    public function testRenderFailureIsDistinguishedNotSilentlyAbsorbed(): void
    {
        $result = self::runAuditor();
        self::assertMatchesRegularExpression('/component\/broken\n\s+error\s+render-failed/', $result['output']);
        self::assertMatchesRegularExpression('/^broken\s+ERROR$/m', $result['output']);
        self::assertNotSame(0, $result['status'], 'a render failure must produce a non-zero exit code');
    }

    public function testFailedFixtureDoesNotLeaveItsOwnPartialCallsTransactional(): void
    {
        // `broken.twig` calls component_broken_child(...) successfully before
        // it throws. Without transactional rollback, that call stays recorded
        // despite the fixture failing, and `broken-child` (no fixture of its
        // own) would read back as "called" instead of never-rendered.
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression('/component\/broken-child\n\s+skip\s+never-rendered/', $output);
        self::assertMatchesRegularExpression('/^broken-child\s+SKIP$/m', $output);
    }

    public function testRoleInheritedFieldIsExemptFromUndemonstratedField(): void
    {
        // `inherited-prop.yaml` declares `wrapper_id` with `role: inherited`
        // (framework-injected, never authored per-component) — must NOT be
        // reported as undemonstrated-field even though never supplied.
        $output = self::runAuditor()['output'];
        self::assertDoesNotMatchRegularExpression('/undemonstrated-field\s+wrapper_id\b/', $output);
        self::assertMatchesRegularExpression('/^inherited-prop\s+OK/m', $output);
    }

    public function testFlexibleContentLayoutsResolveDistinctPathPerLayout(): void
    {
        // `flexible.yaml` declares two ACF flexible-content layouts (`text`,
        // `image`); Definition::flatten()'s `layouts:` branch must resolve
        // each under its OWN prefix rather than collapsing them together.
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression(
            '/flexible\.yaml:\d+\n\s+notice\s+dead-declaration\s+blocks\.image\.src\b/',
            $output,
        );
        self::assertDoesNotMatchRegularExpression('/dead-declaration\s+blocks\.text\.body\b/', $output);
    }

    public function testNestedIncludeFailureIsARealRenderFailureNotASoftMiss(): void
    {
        // `bad-include.twig` includes a template that genuinely does not
        // exist — the failure happens INSIDE render(), not at load() time,
        // and must not be misreported as "component doesn't exist" (a soft
        // miss with no finding).
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression('/component\/bad-include\n\s+error\s+render-failed/', $output);
        self::assertMatchesRegularExpression('/^bad-include\s+ERROR$/m', $output);
    }

    public function testTopLevelFixtureRenderIsSeededWithRealRendererGlobals(): void
    {
        // `homeurl-check.twig` throws (division by zero) only when `homeUrl`
        // is missing/empty from the render context.
        $output = self::runAuditor()['output'];
        self::assertDoesNotMatchRegularExpression('/component\/homeurl-check\n\s+error\s+render-failed/', $output);
        self::assertMatchesRegularExpression('/^homeurl-check\s+OK$/m', $output);
    }

    public function testGateOnAnAncestorPathDoesNotOvermatchADescendantPath(): void
    {
        // `gate-parent.twig`: `content.items.previous` is a truthiness gate;
        // `.disabled != true` is a COMPARISON, correctly excluded from the
        // gate list. A bidirectional prefix match used to let the ancestor
        // gate wrongly "cover" the descendant comparison path too.
        $output = self::runAuditor()['output'];
        self::assertDoesNotMatchRegularExpression('/unexercised-branch\s+items\.previous\.disabled\b/', $output);
        self::assertMatchesRegularExpression('/^gate-parent\s+OK$/m', $output);
    }

    public function testSetElvisAliasResolvesGatingBackToTheAliasedContentPath(): void
    {
        // `gate-alias.twig`: `{% set flag = content.toggle ?: content.fallback %}{% if flag %}`.
        // Without alias binding + Elvis handling, `content.toggle` is never
        // recognised as gated.
        $output = self::runAuditor()['output'];
        self::assertMatchesRegularExpression(
            '/gate-alias\/styleguide\.twig:\d+\n\s+notice\s+unexercised-branch\s+toggle\s/',
            $output,
        );
        self::assertMatchesRegularExpression('/^gate-alias\s+OK\s+1 notice$/m', $output);
    }

    public function testUnobservableIncludeIsReportedDistinctlyNotSilentlyDropped(): void
    {
        // `unobservable-include/styleguide.twig` demonstrates `component/child`
        // via a raw `{% include %}` instead of `component_child(...)` —
        // `include` is a Twig TAG, not a function `renderObserved()` can wrap,
        // so this call reaches `unobservable`, not `calls`.
        $output = self::runAuditor()['output'];

        self::assertMatchesRegularExpression(
            '/component\/child\n\s+notice\s+unanalysable\s+unobservable \{% include %\} of component\/child/',
            $output,
        );

        // Not the same thing as a render failure or invariant violation —
        // must not turn `child` itself into an ERROR/SKIP line, and every
        // other, fully-observed component must still be analysed normally.
        self::assertMatchesRegularExpression('/^child\s+OK$/m', $output);
        self::assertMatchesRegularExpression('/^gate-parent\s+OK$/m', $output);
        self::assertMatchesRegularExpression('/^nullkey\s+OK\s+1 notice$/m', $output);

        // `unobservable-include` itself never calls component_unobservable_include
        // — it only demonstrates `child` via the raw include.
        self::assertMatchesRegularExpression('/component\/unobservable-include\n\s+skip\s+rendered-not-called/', $output);
    }

    public function testUniformRepeaterShapeFiresOnUniformityNotVariance(): void
    {
        // `uniform-repeater.yaml` declares a repeater with six row fields —
        // see the fixture's own yaml for the full shape. This is the
        // regression guard for four separate calibration corrections; see
        // the ported comment history in tailwind-base's original spec for
        // full detail on each.
        $output = self::runAuditor()['output'];

        // Package-internal keys must never surface as a finding of any kind.
        self::assertDoesNotMatchRegularExpression('/_placeholderOpts/', $output);

        // `required: true` must still be excluded.
        self::assertDoesNotMatchRegularExpression('/uniform-repeater-shape\s+items\.title\b/', $output);

        // THE INVERSION: present in some rows but not all must NOT fire —
        // that unevenness is good coverage, not a defect.
        self::assertDoesNotMatchRegularExpression('/uniform-repeater-shape\s+items\.varying\b/', $output);

        // THE CONJUNCTION DEFECT: supplied uniformly but never gated must
        // NOT fire — there is no branch to be "unexercised".
        self::assertDoesNotMatchRegularExpression('/uniform-repeater-shape\s+items\.ungated_uniform\b/', $output);

        // THE SUPPLIED-VS-EXERCISED DEFECT: present on every row but truthy
        // on only some must NOT fire.
        self::assertDoesNotMatchRegularExpression('/uniform-repeater-shape\s+items\.present_varying_truthiness\b/', $output);

        // Truthy on EVERY row, AND gated on, must fire.
        self::assertMatchesRegularExpression(
            '/notice\s+uniform-repeater-shape\s+items\.always_present\.src\s+truthy on every row/',
            $output,
        );

        // Declared but supplied by NO row must also fire, attributed to the
        // repeater instance's fixture source.
        self::assertMatchesRegularExpression('/uniform-repeater\/styleguide\.twig:\d+/', $output);
        self::assertMatchesRegularExpression(
            '/notice\s+uniform-repeater-shape\s+items\.never_present\s+never truthy on any row/',
            $output,
        );

        self::assertMatchesRegularExpression('/^uniform-repeater\s+OK\s+\d+ notices?$/m', $output);
    }

    public function testDirectoryWithNoTwigIsNotAuditableNotAuditedAsComponent(): void
    {
        // `definition-only/` ships a `.yaml` but no `.twig`; `js-working-dir/js/`
        // ships only a working directory. Neither is a component to compare
        // declared fields against — both must be EXCLUDED from
        // computeFindings() while still being CLASSIFIED, not silently
        // dropped.
        $output = self::runAuditor()['output'];

        self::assertMatchesRegularExpression('/component\/definition-only\n\s+skip\s+not-auditable\s+no definition-only\.twig/', $output);
        self::assertMatchesRegularExpression('/component\/js-working-dir\n\s+skip\s+not-auditable\s+no js-working-dir\.twig/', $output);

        self::assertDoesNotMatchRegularExpression('/dead-declaration\s+title\b/', $output);
        self::assertDoesNotMatchRegularExpression('/undemonstrated-field\s+title\b/', $output);

        self::assertDoesNotMatchRegularExpression('/^definition-only\s+(OK|SKIP|ERROR)/m', $output);
        self::assertDoesNotMatchRegularExpression('/^js-working-dir\s+(OK|SKIP|ERROR)/m', $output);
        self::assertMatchesRegularExpression('/not auditable \(excluded\)/', $output);
    }

    public function testDeterminismTwoRunsProduceStructurallyIdenticalFindings(): void
    {
        $first = json_decode(self::runAuditor(json: true)['output'], true);
        $second = json_decode(self::runAuditor(json: true)['output'], true);

        // Structural equality on the captured findings/statuses themselves —
        // not on a formatted text report, which could stay byte-identical
        // across two runs while masking a change in the underlying data.
        self::assertSame($first, $second);
        self::assertNotEmpty($first['findings'] ?? [], 'the fixture tree must produce at least one finding to compare');
        self::assertNotEmpty($first['statuses'] ?? [], 'the fixture tree must produce at least one status to compare');
    }

    public function testNonComponentRenderFailureIsReportedAndFlipsExitCode(): void
    {
        // MUST-FIX 2 (fields-fixtures-auditor review): `page/broken-page`
        // throws (division by zero) and makes zero `page_*(...)` calls of
        // its own. Before the fix, `Auditor::renderFixture()` only recorded
        // a failure when `$kind === 'component'` — `renderStatuses()` and
        // the CLI's exit-code computation both only ever consulted that
        // component-scoped bookkeeping — so this fixture's failure was
        // dropped: never printed, never able to flip the exit code.
        $result = self::runAuditor();
        self::assertMatchesRegularExpression('/page\/broken-page\n\s+error\s+render-failed/', $result['output']);
        self::assertNotSame(0, $result['status'], 'a non-component render failure must produce a non-zero exit code');
    }

    public function testNonComponentRenderFailureAloneStillFlipsExitCodeEvenWithoutAComponentFailure(): void
    {
        // Sharper isolation of the MUST-FIX 2 regression: rather than relying
        // on `component/broken`/`component/bad-include` (real ERROR-status
        // components already in this fixture tree) to keep the exit code
        // non-zero, run against a config whose --templates root ONLY
        // contains the failing page and a single well-behaved component —
        // no component-kind failure exists to accidentally mask a
        // regression where the page-failure-to-exit-code wiring breaks again.
        $isolatedRoot = sys_get_temp_dir() . '/fields-fixtures-isolated-' . bin2hex(random_bytes(4));
        mkdir($isolatedRoot . '/page/broken-page', 0777, true);
        mkdir($isolatedRoot . '/component/ok', 0777, true);
        file_put_contents($isolatedRoot . '/page/broken-page/broken-page.twig', "{# name: \"broken-page\" #}\n");
        file_put_contents($isolatedRoot . '/page/broken-page/styleguide.twig', "{{ 1 / 0 }}\n");
        file_put_contents($isolatedRoot . '/component/ok/ok.twig', "{# name: \"ok\" #}\nfine\n");
        file_put_contents($isolatedRoot . '/component/ok/styleguide.twig', "{{ component_ok({}) }}\n");

        $cmd = [
            PHP_BINARY,
            self::BIN,
            '--templates=' . $isolatedRoot,
            '--static=' . $isolatedRoot,
            '--config=' . $isolatedRoot . '/no-such-styleguide.yaml',
        ];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        array_map('unlink', glob($isolatedRoot . '/*/*/*') ?: []);
        array_map('rmdir', glob($isolatedRoot . '/*/*') ?: []);
        array_map('rmdir', glob($isolatedRoot . '/*') ?: []);
        rmdir($isolatedRoot);

        self::assertMatchesRegularExpression('/page\/broken-page\n\s+error\s+render-failed/', $stdout);
        self::assertMatchesRegularExpression('/^ok\s+OK$/m', $stdout, 'the well-behaved component must still be reported normally');
        self::assertNotSame(0, $status, 'a page-only render failure, with zero component-kind failures, must still produce a non-zero exit code');
    }

    public function testMalformedConfigExitsCleanlyWithExitCodeTwoInsteadOfAnUncaughtStackTrace(): void
    {
        // MUST-FIX 3 (fields-fixtures-auditor review): `new Auditor(...)`
        // parses `--config` immediately (constructing `Styleguide` parses
        // the YAML in its constructor) — before the fix, nothing in
        // `bin/fields-fixtures` caught that, so a malformed config produced
        // an uncaught `Symfony\Component\Yaml\Exception\ParseException` and
        // a raw stack trace instead of the same `exit(2)`-shaped failure
        // every other bad-invocation path in this script (and its sibling
        // `bin/*` commands) already uses.
        $badConfig = sys_get_temp_dir() . '/fields-fixtures-bad-config-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($badConfig, "not: [valid: yaml: structure");

        $cmd = [
            PHP_BINARY,
            self::BIN,
            '--templates=' . self::FIXTURES,
            '--static=' . self::FIXTURES,
            '--config=' . $badConfig,
        ];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        unlink($badConfig);

        self::assertSame(2, $status, 'a malformed config must exit 2, the same convention every arg-error path in this script uses');
        self::assertStringContainsString('fields-fixtures:', $stderr, 'the failure must be reported on STDERR with the tool\'s own prefix, not a raw stack trace');
        self::assertStringNotContainsString('#0 ', $stderr, 'no raw PHP stack trace should reach the user for a normal setup failure');
        self::assertSame('', $stdout, 'nothing should be printed to STDOUT on a setup failure');
    }

    public function testFindingFilePathsAreRelativeToTheCommonAncestorOfDivergentTemplatesAndStaticRoots(): void
    {
        // MUST-FIX 4 (fields-fixtures-auditor review): `Auditor::relative()`
        // used to assume `--templates` always sits at `<root>/static/
        // templates` (`dirname($templatesPath, 2)`) — true for
        // tailwind-base's own layout, but the CLI exposes `--templates` and
        // `--static` as two INDEPENDENT roots, and that assumption silently
        // breaks (returns the finding's raw ABSOLUTE path instead of a
        // repo-relative one) once a consumer's two roots diverge from that
        // exact shape. Here `--templates` sits only ONE level under the
        // shared root (`<root>/templates`, not `<root>/static/templates`),
        // so the old `dirname(…, 2)` assumption lands one directory OUTSIDE
        // the temp tree entirely and cannot match any finding path's prefix.
        $root = sys_get_temp_dir() . '/fields-fixtures-divergent-roots-' . bin2hex(random_bytes(4));
        $templatesPath = $root . '/templates';
        $staticPath = $root . '/static';
        mkdir($templatesPath . '/component/leaf', 0777, true);
        mkdir($staticPath, 0777, true);
        file_put_contents($templatesPath . '/component/leaf/leaf.yaml', "name: \"leaf\"\nfields:\n  unread:\n    role: field\n");
        file_put_contents($templatesPath . '/component/leaf/leaf.twig', "{# name: \"leaf\" #}\nnothing reads content here\n");
        file_put_contents($templatesPath . '/component/leaf/styleguide.twig', "{{ component_leaf({ unread: 'x' }) }}\n");

        $auditor = new \Parisek\DefinitionKit\FixtureAudit\Auditor($templatesPath, $staticPath, $staticPath . '/no-such-styleguide.yaml');
        $auditor->renderAllFixtures();
        $findings = $auditor->computeFindings();

        array_map('unlink', glob($templatesPath . '/component/leaf/*') ?: []);
        rmdir($templatesPath . '/component/leaf');
        rmdir($templatesPath . '/component');
        rmdir($templatesPath);
        rmdir($staticPath);
        rmdir($root);

        $deadDeclaration = null;
        foreach ($findings as $finding) {
            if ('dead-declaration' === $finding->kind && 'unread' === $finding->path) {
                $deadDeclaration = $finding;
            }
        }
        self::assertNotNull($deadDeclaration, 'expected a dead-declaration finding on the unread field');
        self::assertFalse(
            str_starts_with((string) $deadDeclaration->file, '/'),
            "finding file path must be relative to the common ancestor of --templates/--static, not absolute — got: {$deadDeclaration->file}",
        );
        self::assertSame('templates/component/leaf/leaf.yaml', $deadDeclaration->file);
    }

    public function testSummaryInvariantNoticeCountMatchesEveryPrintedNoticeLine(): void
    {
        // Regression guard for an accounting leak: a `notice`-severity line
        // (structural finding OR `unanalysable`) printed to the human report
        // but the bottom-line tally only walked componentDirectories(), so
        // any finding attached to a fixture kind outside that set (pages,
        // docs) — or even an `unanalysable` notice on a real component — was
        // silently missing from "N notices".
        $output = self::runAuditor()['output'];

        preg_match_all('/^\s+notice\s+\S/m', $output, $noticeLines);
        $printedNoticeLines = $noticeLines[0];

        self::assertMatchesRegularExpression('/\d+ components:.*?(\d+) notices/s', $output, 'expected a summary line reporting a notice count');
        preg_match('/\d+ components:.*?(\d+) notices/s', $output, $summaryMatch);
        if (!isset($summaryMatch[1])) {
            self::fail('expected a captured notice count in the summary line');
        }
        $claimedNotices = (int) $summaryMatch[1];

        self::assertSame(
            count($printedNoticeLines),
            $claimedNotices,
            "summary claims {$claimedNotices} notices but " . count($printedNoticeLines) . ' notice lines were printed',
        );

        // Confirm the guard fixtures are actually exercising both leak sites.
        self::assertMatchesRegularExpression('/^page\/leaky-page$/m', $output);
        self::assertMatchesRegularExpression('/^component\/child$/m', $output);
    }
}
