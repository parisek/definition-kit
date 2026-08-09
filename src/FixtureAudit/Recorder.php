<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

/**
 * Passive store for every `component_*`/`page_*` call the fixture-coverage
 * audit (issue #521, design doc `docs/superpowers/specs/2026-08-07-fields-contract-audit-design.md`)
 * observed — the runtime half of `F`.
 *
 * Historically this class WAS the recording mechanism: a `component_*` Twig
 * function this project registered itself, ahead of `Styleguide`'s own,
 * relying on `Styleguide::tryAddFunction()`'s duplicate-registration
 * swallowing to "win" the name (parisek/styleguide#120's PR description
 * covers why that shape does not scale to a package). `Auditor` now sources
 * every call from `Styleguide::renderObserved()` — the package's own
 * recorder, wired unconditionally into `component_*`/`page_*` themselves —
 * and only hands the already-recorded `(component, arguments, source)`
 * triples to this class for storage. No snapshot/rollback machinery is
 * needed here any more either — but for a different reason than "the
 * underlying render throws on failure": it doesn't. `Renderer::render()`
 * (what `renderObserved()` delegates to) catches a component/page render
 * failure itself and returns error markup instead of throwing, so
 * `renderObserved()` can return a NON-empty, PARTIAL `calls` array for a
 * fixture that failed partway through (e.g. two nested calls succeeded
 * before a division-by-zero). `Auditor::renderFixture()` detects that
 * outcome (via `http_response_code()`, the signal `Renderer::render()`
 * documents as stable for this) and simply never calls `record()` for that
 * fixture's result at all — discarding a local array is enough, there is
 * nothing shared to roll back the way the old design's single long-lived
 * recorder needed.
 */
final class Recorder
{
    /** @var array<string, list<array<string, mixed>>> component name (kebab-case) => every content hash it was called with */
    private array $calls = [];

    /** @var array<string, list<string>> component name => the fixture file that made each parallel call in $calls */
    private array $sources = [];

    /**
     * @param array<string, mixed> $content
     */
    public function record(string $component, array $content, string $source): void
    {
        $this->calls[$component][] = $content;
        $this->sources[$component][] = $source;
    }

    public function wasCalled(string $component): bool
    {
        return [] !== ($this->calls[$component] ?? []);
    }

    /**
     * Sum of every call recorded, across every component, for the whole run —
     * a sanity check against an empty/near-empty result (e.g. `--templates`
     * pointed at a tree with no real components).
     */
    public function totalCalls(): int
    {
        $total = 0;
        foreach ($this->calls as $calls) {
            $total += count($calls);
        }

        return $total;
    }

    /**
     * @return list<array{content: array<string, mixed>, source: string}>
     */
    public function entriesFor(string $component): array
    {
        $out = [];
        foreach ($this->calls[$component] ?? [] as $i => $content) {
            $out[] = ['content' => $content, 'source' => $this->sources[$component][$i] ?? '(unknown fixture)'];
        }

        return $out;
    }

    /**
     * Number of calls recorded for `$component` so far — used to detect
     * "did THIS fixture render actually invoke the component it is a
     * fixture for" (`Auditor::renderFixture()`'s before/after comparison).
     */
    public function callCount(string $component): int
    {
        return count($this->calls[$component] ?? []);
    }
}
