<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\FixtureAudit;

use Parisek\DefinitionKit\Contract\TwigPropExtractor;
use Parisek\Styleguide\Styleguide;

/**
 * Moved here from `portadesign/tailwind-base` (`static/tests/fields-contract/`,
 * issue #54, phase 2 of the migration plan in that repo's
 * `docs/superpowers/specs/2026-08-08-fields-contract-migration-plan.md`) —
 * "move the engine, not the evidence": this class and its collaborators
 * (`Flatten`, `Definition`, `Finding`, `GateExtractor`, `Recorder`) moved;
 * the project's own component fixtures, demo data and `unverified`
 * exemption lists did not and stay owned by each consuming project.
 *
 * This move does NOT commit to merging `definition-kit` and `styleguide`
 * into one package — `styleguide/src/FieldsNormalizer.php` already knows
 * this package's `role` vocabulary informally, and once that needs to be
 * formal, requiring `styleguide` here creates a dependency cycle whose only
 * resolution is a merge. That question is open on
 * portadesign/tailwind-base#528; the owner decided not to merge now, so this
 * package keeps `parisek/styleguide` as a one-way `require` dependency.
 *
 * Runtime fixture-coverage audit — the `F` axis of the field contract check
 * (issue #521). See `docs/superpowers/specs/2026-08-07-fields-contract-audit-design.md`
 * for the design; this class is the "thin script in the project" §7 asked for.
 *
 * ## Rendering harness — `Styleguide::renderObserved()` / `::inventory()`
 *
 * This class used to drive a hand-rolled copy of `static/index.php`'s
 * bootstrap sequence: build a Twig `Environment`, register our own
 * `component_*` wrapper on it BEFORE `Styleguide` ever saw it (relying on
 * `Styleguide::tryAddFunction()`'s duplicate-registration swallowing to let
 * ours win the name), and reach `Renderer::$currentKind`/`$currentSlug` via
 * `ReflectionProperty` because `Renderer` is `@internal` and exposes no
 * public "render this one fixture" entry point. That was a documented
 * workaround for a genuinely missing capability — recorded as the strongest
 * argument for the package to grow one.
 *
 * `parisek/styleguide` >= (parisek/styleguide#120) now ships that capability
 * directly on the package's one `@api`-covered class:
 *
 *   - `Styleguide::inventory()` enumerates every real `kind/slug[/variant]`
 *     fixture the project has, in stable order — replaces the `glob()` walk
 *     this class used to do itself (and the informal "styleguide.<variant>.twig"
 *     naming rule it had to restate to interpret the results).
 *   - `Styleguide::renderObserved(kind, slug, variant?)` renders exactly one
 *     fixture and returns `{html, calls, unobservable}` — `calls` is the
 *     trace this class used to build by hand; `unobservable` declares
 *     `{% include '@component/x/x.twig' %}` call sites the package cannot
 *     observe (`include` is a Twig tag, not a function it can wrap) instead
 *     of silently missing them.
 *
 * This class is constructed exactly like `static/index.php` (no `twig` key
 * — the package builds its own pristine environment, which is what already
 * carries `resizer`/`placeholder`/`merge_resizer`/typography/i18n and every
 * other project-facing helper `component_*` templates depend on), then
 * drives every fixture through `renderObserved()` in a loop. No custom Twig
 * function registration, no reflection into `@internal` classes, and no
 * filesystem globbing anywhere in this file.
 *
 * No HTTP context is required or touched; `static/index.php`'s router
 * behaviour is untouched (this class never calls `Styleguide::run()`).
 */
final class Auditor
{
    private const ROLES_EXEMPT_FROM_UNDEMONSTRATED = ['query', 'global', 'parent', 'inherited', 'derived'];

    /**
     * `role: query`/`global` extension (issue #521 follow-up round, "role:
     * query for backend-assembled shapes" — see design doc §5 "Role
     * modifiers"): a definition wider than the template, or a fixture key
     * wider than the template, or a repeater whose row-to-row shape is
     * perfectly uniform, is a FACT ABOUT THE BACKEND for these two roles —
     * the shape arrives whether the component wants it or not — not an
     * author's choice (role: field) or a fixture-authoring gap. `parent`
     * deliberately stays OUT of this set: `page/_partials/header.twig`
     * assembles/mutates parent data before calling `component_header(...)`,
     * so a stale key there is a real defect (design doc's `parent` carve-out
     * discussion, narrower than #3's).
     */
    private const ROLES_EXEMPT_FROM_DECLARATION_AXES = ['query', 'global'];

    public readonly Recorder $recorder;
    private readonly Styleguide $styleguide;
    private readonly string $templatesPath;
    /**
     * MUST-FIX 4 (fields-fixtures-auditor review): the directory
     * `relative()`/`toAbsolute()` express every finding's file path against.
     * Derived from the two independent roots the CLI actually exposes
     * (`--templates`, `--static`) rather than assumed to be
     * `dirname($templatesPath, 2)` — that assumption is only true for a
     * project shaped exactly like `tailwind-base` (`<root>/static/templates`
     * + `<root>/static`), and silently produces the wrong "relative" path
     * (or none at all) once a consumer's two roots diverge. See
     * `determineRoot()`.
     */
    private readonly string $rootPath;

    /** @var array<string, bool> component name => true when its own fixture render threw */
    private array $renderFailed = [];
    /** @var array<string, string> component name => the exception message when it failed */
    private array $renderFailedReason = [];
    /**
     * MUST-FIX 2 (fields-fixtures-auditor review): `$renderFailed`/
     * `$renderFailedReason` above are scoped to `'component'`-kind fixtures
     * only, because that is the only kind `renderStatuses()`/
     * `renderFailureReason()` report on. A `page`/`doc` fixture that throws
     * or 500s was previously dropped entirely — never recorded anywhere, so
     * the CLI exited 0 even though something broke. This tracks a failure
     * for EVERY kind `inventory()` returns, keyed by `"kind/slug"` (the same
     * keying `$unanalysable` already uses, for the same "component/404 and
     * page/404 are different fixtures" reason), independent of the
     * component-only bookkeeping above.
     *
     * @var array<string, string> "kind/slug" => failure reason
     */
    private array $anyRenderFailedReason = [];
    /** @var array<string, bool> component name => at least one styleguide*.twig file exists for it */
    private array $hasOwnFixture = [];
    /** @var array<string, bool> component name => its own fixture render actually invoked component_<name> */
    private array $calledBySelf = [];
    /**
     * component name => reasons at least one recorded fixture render could
     * only see PART of what actually rendered for it, because a
     * `{% include '@component/<name>/<name>.twig' %}` bypassed
     * `renderObserved()`'s recorder (see `Styleguide::renderObserved()`'s
     * `unobservable` field). Reported distinctly (`Auditor::unanalysable()`)
     * rather than either silently dropped or treated as a hard error — see
     * this class's `renderFixture()` for where entries are added.
     *
     * @var array<string, list<string>>
     */
    private array $unanalysable = [];

    /**
     * @param string $homeUrl `twig_context.homeUrl` seeded into every fixture
     *        render — see the docblock note below on why this must not be
     *        empty for an accurate audit. Defaults to the same value this
     *        class has always used (`tailwind-base`'s own styleguide route
     *        shape), now overridable rather than baked in (MUST-FIX 4): a
     *        consuming project whose styleguide entry mounts fixtures under a
     *        different route can pass its own.
     * @param string $frontPageUrl `twig_context.frontPageUrl`, same rationale.
     * @param string $templateUrl `twig_context.templateUrl` (the consumer
     *        asset base `Renderer` rebases `src:`/`url:` fields onto) — same
     *        rationale; empty by default (standalone-styleguide convention).
     */
    public function __construct(
        string $templatesPath,
        string $staticPath,
        string $configYaml,
        string $locale = 'cs',
        string $homeUrl = '/styleguide/render/',
        string $frontPageUrl = '/styleguide/render/page/homepage',
        string $templateUrl = '',
    ) {
        $this->templatesPath = rtrim($templatesPath, '/');
        $this->rootPath = self::determineRoot($this->templatesPath, rtrim($staticPath, '/'));
        $this->recorder = new Recorder();

        // Same construction shape as `static/index.php` — no `twig` config
        // key, so the package builds its own pristine environment (already
        // carrying `resizer`/`placeholder`/`merge_resizer`/typography/i18n
        // and every other helper real component templates depend on) and
        // wires its own recorder into `component_*`/`page_*` unconditionally.
        // See class docblock.
        //
        // `twig_context` mirrors `index.php`'s own values by default (now
        // overridable — MUST-FIX 4, see constructor docblock). MUST-FIX 1's
        // concern still applies: the real renderer never renders a fixture
        // with an empty context, it always seeds `homeUrl`/`frontPageUrl`/
        // `templateUrl`/`langcode`, and a fixture referencing any of those
        // globals (e.g. `{% if homeUrl is empty %}`-gated markup) must see
        // the same values under audit as it would under the real styleguide.
        $this->styleguide = new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => $staticPath,
            'config_yaml' => $configYaml,
            'default_locale' => $locale,
            'twig_context' => [
                'homeUrl' => $homeUrl,
                'frontPageUrl' => $frontPageUrl,
                'templateUrl' => $templateUrl,
                'langcode' => $locale,
            ],
        ]);
    }

    /**
     * The longest common ancestor directory of the CLI's two independent
     * roots (`--templates`, `--static`) — the natural "project root" when
     * both live under one repository, without assuming the specific
     * `<root>/static/templates` + `<root>/static` shape one particular
     * consumer (`tailwind-base`) happens to use. Falls back to
     * `dirname($templatesPath, 2)` — the historical, narrower assumption —
     * only when the two roots share no common ancestor at all (nothing
     * sound to relativize against instead).
     */
    private static function determineRoot(string $templatesPath, string $staticPath): string
    {
        $templateSegments = explode('/', trim($templatesPath, '/'));
        $staticSegments = explode('/', trim($staticPath, '/'));

        $common = [];
        foreach ($templateSegments as $i => $segment) {
            if (($staticSegments[$i] ?? null) !== $segment) {
                break;
            }
            $common[] = $segment;
        }

        if ([] === $common) {
            return dirname($templatesPath, 2);
        }

        return (str_starts_with($templatesPath, '/') ? '/' : '') . implode('/', $common);
    }

    /**
     * Render every fixture `Styleguide::inventory()` reports, populating
     * `$this->recorder` and the per-component render-status maps.
     */
    public function renderAllFixtures(): void
    {
        foreach ($this->styleguide->inventory() as $fixture) {
            if ('component' === $fixture['kind']) {
                $this->hasOwnFixture[$fixture['slug']] = true;
            }
            // 'doc' fixtures render their own layout and legitimately make
            // zero component_*/page_* calls (a changelog page, an icon
            // gallery specimen) — inventory() lists them because they are
            // real renderable fixtures, but there is no per-component F-axis
            // bookkeeping to do for them: they never populate
            // $hasOwnFixture/$calledBySelf (both scoped to 'component'
            // above), so a doc contributing zero calls never reads as
            // "rendered-not-called" or any other suspicious status — it
            // simply isn't tracked by those maps at all. Rendering them
            // anyway (rather than skipping 'doc' outright) still lets a
            // `{% include %}` bypass inside a doc page surface via
            // `unobservable`, same as for component/page fixtures.
            $this->renderFixture($fixture['kind'], $fixture['slug'], $fixture['variant']);
        }
    }

    /** @param 'component'|'page'|'doc' $kind */
    private function renderFixture(string $kind, string $slug, ?string $variant): void
    {
        $source = $this->fixtureSource($kind, $slug, $variant);
        $before = 'component' === $kind ? $this->recorder->callCount($slug) : 0;

        // `renderObserved()` does NOT throw for a genuine component/page
        // runtime failure — `Renderer::render()` (which it delegates to)
        // catches the \Throwable itself, calls `http_response_code(500)`,
        // and returns HTML with an inline error block instead (deliberately:
        // a health check or CI smoke test polling the HTTP render endpoint
        // must see 500, not a silently-caught 200 — see `Renderer::render()`'s
        // own docblock). `renderObserved()` only throws for a malformed call
        // (`InvalidArgumentException` on a bad `$kind`) or an unobservable
        // environment (`LogicException` — never reachable from this class,
        // which never passes a pre-built `twig` env, see class docblock) —
        // both genuinely exceptional, not a normal per-fixture outcome, so
        // still worth catching defensively.
        //
        // http_response_code() is reset before the call and re-read after:
        // it is the one signal `Renderer::render()`'s docblock documents as
        // stable for "did this render fail", and is preferred here over
        // pattern-matching the (@internal, unversioned) inline error markup
        // `errorMarkup()` emits.
        http_response_code(200);
        try {
            $result = $this->styleguide->renderObserved($kind, $slug, $variant);
        } catch (\Throwable $e) {
            if ('component' === $kind) {
                $this->renderFailed[$slug] = true;
                $this->renderFailedReason[$slug] = $e->getMessage();
            }
            $this->anyRenderFailedReason[$kind . '/' . $slug] = $e->getMessage();

            return;
        }

        if (500 === http_response_code()) {
            $reason = self::extractRenderErrorMessage($result['html'])
                ?? 'render failed (see rendered HTML — no structured error message available)';
            if ('component' === $kind) {
                $this->renderFailed[$slug] = true;
                $this->renderFailedReason[$slug] = $reason;
            }
            $this->anyRenderFailedReason[$kind . '/' . $slug] = $reason;

            // Transactional: a fixture that fails partway through still
            // returns whatever `calls`/`unobservable` it recorded BEFORE the
            // failure (e.g. `broken.twig` calls component_broken_child(...)
            // successfully, then divides by zero) — discarding the whole
            // result here, rather than recording it and rolling back
            // afterward, is what replaces the old Recorder::rollbackTo()
            // machinery (see Recorder's docblock): a partially rendered
            // fixture is not evidence any of those nested calls were
            // genuinely demonstrated, the same as a fixture that fails
            // immediately.
            return;
        }

        foreach ($result['calls'] as $call) {
            $this->recorder->record($call['component'], $call['arguments'], $source);
        }
        if ('component' === $kind && $this->recorder->callCount($slug) > $before) {
            $this->calledBySelf[$slug] = true;
        }

        foreach ($result['unobservable'] as $entry) {
            // Attribute the blind spot to the component/page the include
            // NAMED, when the package could resolve one — that fixture's own
            // F data is what's actually incomplete (this fixture supplied it
            // arguments the recorder never saw). A dynamic include target
            // (`component: null` — see `Styleguide::renderObserved()`'s
            // docblock) can't be attributed to a specific one, so it falls
            // back to the CURRENT fixture's own kind/slug instead.
            //
            // Keyed by `kind/slug`, not bare slug: `component/404` and
            // `page/404` are two entirely different fixtures sharing a name,
            // and a bare-slug key would silently merge an unobservable
            // include reached from one into the other's report.
            $targetKind = $entry['kind'] ?? $kind;
            $targetSlug = $entry['component'] ?? $slug;
            $reason = $entry['reason'] ?? sprintf('unobservable {%% include %%} of %s/%s', $targetKind, $targetSlug);
            $this->unanalysable[$targetKind . '/' . $targetSlug][] = sprintf('%s (from %s)', $reason, $entry['source']);
        }
    }

    /**
     * Relative-to-repo-root path of the `styleguide*.twig` file a given
     * fixture row renders from — mirrors the naming convention
     * `Styleguide::inventory()` already resolved when it produced this row
     * (`styleguide.twig` for the default variant, `styleguide.<variant>.twig`
     * for a named one), used only to label findings with the file an agent
     * should actually edit.
     */
    private function fixtureSource(string $kind, string $slug, ?string $variant): string
    {
        $file = $variant === null ? 'styleguide.twig' : sprintf('styleguide.%s.twig', $variant);

        return $this->relative($this->templatesPath . '/' . $kind . '/' . $slug . '/' . $file);
    }

    /**
     * Best-effort extraction of the underlying exception message from
     * `Renderer::errorMarkup()`'s inline error block (`@internal`, unversioned
     * markup — see `renderFixture()`'s docblock on why `http_response_code()`,
     * not this, is the primary failure signal). Returns `null` when the shape
     * doesn't match, in which case the caller falls back to a generic
     * message — a diagnostic nicety degrading gracefully, not a contract.
     */
    private static function extractRenderErrorMessage(string $html): ?string
    {
        if (preg_match('#<strong>Render error:</strong><br>(.*?)</div>#s', $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * `$this->rootPath` (MUST-FIX 4, see `determineRoot()`) is the root every
     * other repo-relative path in this tool (and in the rest of the
     * project's lint output) is expressed against.
     */
    private function relative(string $absolute): string
    {
        if (str_starts_with($absolute, $this->rootPath . '/')) {
            return substr($absolute, strlen($this->rootPath) + 1);
        }

        return $absolute;
    }

    /**
     * @return list<Finding>
     */
    public function computeFindings(): array
    {
        $findings = [];

        foreach ($this->styleguide->componentDirectories() as $entry) {
            $name = $entry['id'];
            if (!$entry['hasTemplate']) {
                // Not an auditable component — see renderStatuses()'s
                // `not-auditable` case for the full rationale. Nothing to
                // compare declared fields against without a template, so
                // every field would trivially read as "declared, never
                // read" — a category error, not a finding.
                continue;
            }

            $dir = $this->templatesPath . '/component/' . $name;
            $yaml = $dir . '/' . $name . '.yaml';
            if (!is_file($yaml)) {
                continue; // untyped — out of scope for this axis, same gate ContractLinter uses for T<->D
            }

            $twig = $dir . '/' . $name . '.twig';
            $definitionFull = Definition::flattenWithRequired($yaml);
            $definition = array_map(static fn(array $entry): string => $entry['role'], $definitionFull);
            $reads = is_file($twig)
                ? array_values(array_unique((new TwigPropExtractor(self::templateResolver($dir)))->extractFile($twig)->reads))
                : [];
            // MUST-FIX 2: `unexercised-branch` may only fire on a path the
            // template actually GATES CONTROL FLOW on ({% if %} / ternary /
            // and/or operand) — not on every falsy supplied value regardless
            // of how the template consumes it. See GateExtractor's docblock
            // for the counter-examples (`pagination.twig`'s `disabled: false`,
            // `header.twig`'s `|default`-consumed `top`/`scrolled`).
            $gates = is_file($twig)
                ? (new GateExtractor(self::templateResolver($dir)))->extractFile($twig)
                : [];

            $entries = $this->recorder->entriesFor($name);
            $supplied = [];
            $exercised = [];
            $sourceOf = [];
            /** @var list<array{prefix: string, rowCount: int, counts: array<string, int>, exercisedCounts: array<string, int>, source: string}> $repeaters */
            $repeaters = [];
            foreach ($entries as $entry) {
                $flat = Flatten::content($entry['content']);
                foreach ($flat['supplied'] as $path => $_) {
                    $supplied[$path] = true;
                    $sourceOf[$path] ??= $entry['source'];
                }
                foreach ($flat['exercised'] as $path => $_) {
                    $exercised[$path] = true;
                }
                foreach ($flat['repeaters'] as $prefix => $info) {
                    $repeaters[] = ['prefix' => $prefix, 'rowCount' => $info['rowCount'], 'counts' => $info['counts'], 'exercisedCounts' => $info['exercisedCounts'], 'source' => $entry['source']];
                }
            }

            foreach ($definition as $path => $role) {
                if (!self::covered($path, $reads) && !in_array($role, self::ROLES_EXEMPT_FROM_DECLARATION_AXES, true)) {
                    $findings[] = new Finding(
                        file: $this->relative($yaml),
                        line: self::yamlLine($yaml, $path),
                        severity: 'notice',
                        kind: 'dead-declaration',
                        path: $path,
                        detail: 'declared, never read by template',
                        component: $name,
                    );
                }
                if (
                    !in_array($role, self::ROLES_EXEMPT_FROM_UNDEMONSTRATED, true)
                    && !self::covered($path, array_keys($supplied))
                ) {
                    $findings[] = new Finding(
                        file: $this->relative($yaml),
                        line: self::yamlLine($yaml, $path),
                        severity: 'notice',
                        kind: 'undemonstrated-field',
                        path: $path,
                        detail: sprintf('role: %s, supplied by no fixture', $role),
                        component: $name,
                    );
                }
            }

            foreach (array_keys($supplied) as $path) {
                if (!self::covered($path, $reads)) {
                    if (in_array($definition[$path] ?? null, self::ROLES_EXEMPT_FROM_DECLARATION_AXES, true)) {
                        continue;
                    }
                    $source = $sourceOf[$path] ?? '(unknown fixture)';
                    $findings[] = new Finding(
                        file: $source,
                        line: self::textLine($this->toAbsolute($source), $path),
                        severity: 'notice',
                        kind: 'dead-fixture-key',
                        path: $path,
                        detail: sprintf('supplied for %s, never read by template', $name),
                        component: $name,
                    );
                    continue;
                }
                if (!isset($exercised[$path]) && self::gatedOn($path, $gates)) {
                    $source = $sourceOf[$path] ?? '(unknown fixture)';
                    $findings[] = new Finding(
                        file: $source,
                        line: self::textLine($this->toAbsolute($source), $path),
                        severity: 'notice',
                        kind: 'unexercised-branch',
                        path: $path,
                        detail: 'supplied as a falsy value, template gates on truthiness',
                        component: $name,
                    );
                }
            }

            // Rule #6, `uniform-repeater-shape` (design doc §5, "#6 in
            // depth"): the OLD build of this rule fired on raw row-to-row
            // UNEVENNESS, on the theory that unevenness is where a fixture
            // "accidentally demonstrates only one shape". A post-
            // implementation review of `header`'s `menu`/`below` fixture (5
            // rows, one with no `below` at all, four with a populated one)
            // found that theory backwards: that unevenness is exactly what
            // GOOD coverage of an optional field looks like — both the
            // submenu and no-submenu branches render — and the old rule
            // reported it as 9 defects anyway. The genuinely dangerous state
            // is the mirror image: an OPTIONAL field EXERCISED (truthy) on
            // every row, or on no row at all, means one of its two branches
            // never renders in any fixture. A FOURTH symptom, found later
            // from the same root, is that this must be judged by TRUTHINESS,
            // not mere presence: `header`'s menu-item fixture carried
            // `is_active` on every row with the value itself varying
            // (`true`/`false`), so counting presence called it uniform and
            // fired a false positive — the rule now tallies each row's
            // EXERCISED set (Flatten's `exercisedCounts`), not its supplied
            // set, so a key present everywhere but truthy on only some rows
            // is correctly read as varying, not uniform. `isDoctrinallyOptional()`
            // decides "optional"
            // the same way it always has — a field's own literal `required:`
            // flag (`picture.md`'s `image_mobile` convention: no `required:`
            // key, or an explicit `required: false`, is optional; `required:
            // true`, e.g. `breadcrumb.items.title`, is not, and is skipped
            // here because a required field is SUPPOSED to be on every row —
            // uniform presence there is not a finding). A path reachable only
            // via `of:` forwarding (e.g. header's `menu.below.description`)
            // has no local `required:` to consult, so `isDoctrinallyOptional()`
            // returns false and this rule stays silent on it — deliberately
            // the OPPOSITE conservatism direction from #4's `parent` carve-out,
            // because firing here on a required-vs-optional judgement with no
            // evidence would be a guess, not a finding.
            // Aggregate every repeater instance by its dot-path PREFIX before
            // classifying — the same nested component (e.g. `header-menu`,
            // rendered once per page fixture that includes the header) shows
            // up as a SEPARATE repeater instance per page. Without
            // aggregation, one real uniformity fact would be reported once
            // per page that happens to render it, ballooning the count with
            // duplicates instead of the one finding every other rule kind in
            // this file produces per component.
            /** @var array<string, array{rowCount: int, counts: array<string, int>, exercisedCounts: array<string, int>, source: string}> $byPrefix */
            $byPrefix = [];
            foreach ($repeaters as $repeater) {
                if ($repeater['rowCount'] <= 1) {
                    continue;
                }
                $agg = $byPrefix[$repeater['prefix']] ?? ['rowCount' => 0, 'counts' => [], 'exercisedCounts' => [], 'source' => $repeater['source']];
                $agg['rowCount'] += $repeater['rowCount'];
                foreach ($repeater['counts'] as $path => $count) {
                    $agg['counts'][$path] = ($agg['counts'][$path] ?? 0) + $count;
                }
                foreach ($repeater['exercisedCounts'] as $path => $count) {
                    $agg['exercisedCounts'][$path] = ($agg['exercisedCounts'][$path] ?? 0) + $count;
                }
                $byPrefix[$repeater['prefix']] = $agg;
            }

            foreach ($byPrefix as $prefix => $repeater) {
                $repeater['prefix'] = $prefix;
                // MUST-FIX (2026-08-08, issue #521 "#6 in depth" fourth
                // symptom): candidates are keyed by every path SUPPLIED by at
                // least one row (or declared but never supplied at all —
                // see below), but the value consulted for uniformity is the
                // path's EXERCISED count, not its supplied count. A boolean
                // field can be supplied on every row while being truthy on
                // only some of them (`header`'s `is_active`) — counting
                // presence there reports a false uniformity that counting
                // truthiness does not. "Absent on every row" reads as
                // "exercised on zero rows" under this axis, which covers a
                // key genuinely never supplied and a key supplied-but-always-
                // falsy identically — both leave the truthy branch equally
                // unexercised (design doc §5 "#6 in depth").
                $candidates = [];
                foreach (array_keys($repeater['counts']) as $path) {
                    $candidates[$path] = $repeater['exercisedCounts'][$path] ?? 0;
                }
                // Absence candidates: fields declared as DIRECT children of
                // this repeater's own local declaration that never appeared
                // in ANY row of this instance — not reachable via `covered()`
                // ancestor/descendant looseness (that would flag every leaf
                // under an absent container redundantly), just the immediate
                // child names `D` itself enumerates.
                foreach (array_keys($definitionFull) as $declared) {
                    if (!str_starts_with($declared, $repeater['prefix'] . '.')) {
                        continue;
                    }
                    $rest = substr($declared, strlen($repeater['prefix']) + 1);
                    if (str_contains($rest, '.') || isset($candidates[$declared])) {
                        continue; // nested deeper, or already supplied by at least one row
                    }
                    $candidates[$declared] = 0;
                }

                foreach ($candidates as $path => $count) {
                    if ($path === $prefix) {
                        // Flatten artifact, not a finding: a repeater's OWN
                        // bare prefix is recorded as exercised by every row
                        // whose hash is simply non-empty (the "flattened per
                        // element, same prefix" mechanism — see Flatten's
                        // docblock), so it trivially reaches count===rowCount
                        // for ANY repeater with only non-empty rows. That is
                        // a fact about how rows are represented, not evidence
                        // the repeater FIELD itself has an unexercised
                        // branch — its own presence/absence is `undemonstrated-
                        // field`'s and `dead-fixture-key`'s concern, not #6's.
                        continue;
                    }
                    if (0 !== $count && $count !== $repeater['rowCount']) {
                        // Varies row-to-row by TRUTHINESS (not by mere
                        // presence — see the MUST-FIX above `$candidates` is
                        // built from) — good coverage, not this rule's
                        // concern. A boolean field present on every row but
                        // truthy on only some of them lands here, not below.
                        continue;
                    }
                    if (!self::covered($path, $reads)) {
                        continue; // dead-fixture-key's finding, not this one
                    }
                    if (in_array($definition[$path] ?? null, self::ROLES_EXEMPT_FROM_DECLARATION_AXES, true)) {
                        continue;
                    }
                    if (!self::isDoctrinallyOptional($path, $definitionFull)) {
                        continue; // required (or unreachable) — no evidence this omission/uniformity is a gap
                    }
                    // MUST-FIX (2026-08-08): uniformity of SHAPE is not evidence
                    // of a dead BRANCH unless the template actually branches on
                    // this exact path — the same fact `unexercised-branch`
                    // (rule #5) already consults via `GateExtractor`. Without
                    // this check the rule fired on `image.src`/`width`/`height`/
                    // `type` on `picture` (structurally mandatory parts of an
                    // image source `picture.twig` never gates on — at most a
                    // missing `required: true`, not a dead branch) and on
                    // `categories.url` on `article-teaser` (whose own `<name>.
                    // yaml` documents the loop deliberately reads only `title` —
                    // there is no branch to be "unexercised"). Reuse the same
                    // `gatedOn()` the #5 loop above uses; do not run a second
                    // gate analysis.
                    if (!self::gatedOn($path, $gates)) {
                        continue; // uniform shape, but the template never branches on this path — nothing hidden
                    }
                    $source = $sourceOf[$path] ?? $repeater['source'];
                    $findings[] = new Finding(
                        file: $source,
                        line: self::textLine($this->toAbsolute($source), $path),
                        severity: 'notice',
                        kind: 'uniform-repeater-shape',
                        path: $path,
                        detail: 0 === $count
                            ? sprintf('never truthy on any row of %s, template gates on it — truthy branch unexercised', $name)
                            : sprintf('truthy on every row of %s, template gates on it — falsy/absent branch unexercised', $name),
                        component: $name,
                    );
                }
            }
        }

        usort($findings, static fn(Finding $a, Finding $b): int => [$a->file, $a->line, $a->path, $a->kind]
            <=> [$b->file, $b->line, $b->path, $b->kind]);

        return $findings;
    }

    private function toAbsolute(string $relative): string
    {
        if (str_starts_with($relative, '/')) {
            return $relative;
        }

        return $this->rootPath . '/' . $relative;
    }

    /**
     * @return array<string, string> component name => status
     *   ('called' | 'render-failed' | 'never-called' | 'rendered-not-called' | 'not-auditable')
     */
    public function renderStatuses(): array
    {
        $out = [];
        foreach ($this->styleguide->componentDirectories() as $entry) {
            $name = $entry['id'];
            // A directory without its own `<name>.twig` is not a component
            // to audit at all — there is nothing to compare a declaration or
            // a render trace against. Classified rather than silently
            // dropped (design intent: `lint-fixture-link-shape/` ships only
            // a `.yaml` on purpose — see commit 3526be6 — and a real project
            // has hit the same shape with a directory containing only
            // `js/`). Every OTHER status below still requires a template to
            // even be reachable, so this check must come first.
            if (!$entry['hasTemplate']) {
                $out[$name] = 'not-auditable';
                continue;
            }

            $out[$name] = match (true) {
                isset($this->renderFailed[$name]) => 'render-failed',
                // No own styleguide*.twig, but SOME fixture (its own parent's,
                // typically) recorded a call for it while rendering — a
                // `part`/`utility`-kind component nested inside its parent
                // (design doc §4's "per-component render status", the third
                // outcome) is genuinely exercised, just never in isolation.
                !isset($this->hasOwnFixture[$name]) && $this->recorder->wasCalled($name) => 'called',
                !isset($this->hasOwnFixture[$name]) => 'never-called',
                isset($this->calledBySelf[$name]) => 'called',
                default => 'rendered-not-called',
            };
        }

        return $out;
    }

    public function renderFailureReason(string $name): ?string
    {
        return $this->renderFailedReason[$name] ?? null;
    }

    /**
     * MUST-FIX 2: every render failure `renderAllFixtures()` observed, across
     * EVERY fixture kind (`component`, `page`, `doc`) — not just components.
     * The CLI consults this (in addition to the component-only statuses) to
     * compute its exit code, so a broken `page/`/`doc` fixture can no longer
     * render-fail silently while the process still exits 0.
     *
     * @return array<string, string> "kind/slug" => failure reason
     */
    public function anyRenderFailures(): array
    {
        return $this->anyRenderFailedReason;
    }

    /**
     * Components/fixtures whose F data is known to be incomplete because at
     * least one fixture render reached them through an unobservable
     * `{% include %}` (see `renderFixture()`). Reported distinctly rather
     * than silently folded into `renderStatuses()`'s OK/SKIP/ERROR
     * vocabulary, or treated as a fatal error for the whole run — one
     * component with a declared blind spot doesn't invalidate the findings
     * computed for every other, fully-observed component.
     *
     * This class used to also guard against a PARTIAL bypass of a
     * self-registered recording wrapper — some components correctly
     * instrumented, others silently not, because this project raced
     * `Styleguide::tryAddFunction()`'s duplicate-registration handling to
     * install its own `component_*` first. `Styleguide::renderObserved()`
     * (parisek/styleguide#120) makes that specific failure mode structurally
     * impossible: the package's own recorder is wired into `component_*`/
     * `page_*` themselves, so there is no registration race left to lose,
     * and a genuinely unobservable environment (a caller-supplied Twig env
     * with `component_*`/`page_*` already registered) makes
     * `renderObserved()` throw a `LogicException` on every single call
     * rather than silently return a partial trace — the opposite of a quiet
     * bypass. Keeping a sentinel for a failure mode the new API cannot
     * produce would only be able to misfire, so it was removed rather than
     * left as dead code (see `Recorder`'s docblock for the matching removal
     * on the recording side).
     *
     * @return array<string, list<string>>
     */
    public function unanalysable(): array
    {
        return $this->unanalysable;
    }

    /**
     * A path is "covered" by a set when it's an exact member, OR a deeper
     * member of the set exists under it (`x.y` covers `x`), OR it is itself
     * deeper than a member of the set (`x` covers `x.y` — a read/supply of
     * the whole container accounts for everything under it). Deliberately
     * loose in both directions: this is a notice-severity lint, and the cost
     * of a false negative here (a real dead declaration going unreported) is
     * lower than the cost of a false positive nagging about legitimate
     * partial reads.
     *
     * @param list<string> $set
     */
    private static function covered(string $path, array $set): bool
    {
        if (in_array($path, $set, true)) {
            return true;
        }
        foreach ($set as $other) {
            if (str_starts_with($other, $path . '.') || str_starts_with($path, $other . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * MUST-FIX 2: `covered()` above is deliberately loose in BOTH directions,
     * which is right for "was this declaration/fixture-key read at all"
     * (`x.y` supplied covers a declaration of `x`, and vice versa) but wrong
     * for "does the template gate control flow on THIS specific path" — a
     * gate on the ancestor `x` does NOT mean the template also branches on
     * `x.y`'s truthiness. `pagination.twig` gates on `content.items.previous`
     * (truthiness, `{% if content.items.previous and … %}`) AND separately
     * *compares* `content.items.previous.disabled != true` — a comparison,
     * which `GateExtractor` correctly does NOT record as a gate (see its
     * docblock). Reusing `covered()`'s ancestor-implies-descendant direction
     * here made the `items.previous` gate wrongly "cover" `items.previous.
     * disabled` too, producing a bogus `unexercised-branch` finding on a
     * path the template never actually branches on by itself.
     *
     * Only an exact match counts: `$path` is gated only when the extractor
     * recorded that literal path as something the template's control flow
     * depends on.
     *
     * @param list<string> $gates
     */
    private static function gatedOn(string $path, array $gates): bool
    {
        return in_array($path, $gates, true);
    }

    /**
     * Whether `$path` is a candidate `uniform-repeater-shape` finding because
     * its OWN declaration marks it non-mandatory — see that finding loop's
     * docblock above for the doctrinal reasoning (`picture.md`'s
     * `image_mobile` convention) and why this is deliberately narrower than
     * `covered()`: only the NEAREST declared ancestor's `required:` flag
     * decides (longest matching prefix present in `$definitionFull`), not
     * any ancestor loosely — a repeater marked `required: true` (e.g.
     * `project-slider.items`) must not make every field nested under it
     * "required" by association.
     *
     * A path with no declaration at all reachable by prefix match, OR
     * reachable only by stepping across an ancestor that itself `forwards`
     * (declares `of:`) — e.g. header's `menu.below.description`, where
     * `menu` is declared locally but `below`/`description` exist only
     * because `header-menu.yaml`'s shape is forwarded in, never expanded in
     * `header.yaml` itself — has no LOCAL evidence either way, so it is
     * treated as NOT optional — deliberately the opposite conservatism
     * direction from #4's `parent` carve-out (design doc § "Role
     * modifiers"): firing #6 on a required-vs-optional judgement with no
     * local evidence would be a guess, not a finding. Without the `forwards`
     * check, `menu`'s own missing `required:` key would wrongly "clear" every
     * field the forwarded component declares, including genuinely mandatory
     * ones, as a false-positive candidate.
     *
     * @param array<string, array{role: string, required: ?bool, forwards: bool}> $definitionFull
     */
    private static function isDoctrinallyOptional(string $path, array $definitionFull): bool
    {
        if (isset($definitionFull[$path])) {
            return true !== $definitionFull[$path]['required'];
        }

        $bestMatch = null;
        foreach (array_keys($definitionFull) as $declared) {
            if (
                str_starts_with($path, $declared . '.')
                && !$definitionFull[$declared]['forwards']
                && (null === $bestMatch || strlen($declared) > strlen($bestMatch))
            ) {
                $bestMatch = $declared;
            }
        }

        if (null === $bestMatch) {
            return false; // no LOCAL declaration reaches this path — unknown, stay conservative and fire
        }

        return true !== $definitionFull[$bestMatch]['required'];
    }

    /**
     * Resolves `{% include %}` paths the same way `ContractLinter`'s private
     * `templateResolver()` does — walking up from the component's own
     * directory. Small and generic enough (Twig include resolution, not
     * proprietary contract logic) that duplicating it here is preferable to
     * reaching into `ContractLinter`'s private internals.
     *
     * @return \Closure(string): ?string
     */
    private static function templateResolver(string $componentDir): \Closure
    {
        return static function (string $path) use ($componentDir): ?string {
            $candidates = [$componentDir];
            $dir = $componentDir;
            for ($i = 0; $i < 4; $i++) {
                $dir = dirname($dir);
                $candidates[] = $dir;
            }
            foreach ($candidates as $candidate) {
                $full = $candidate . '/' . ltrim($path, '/');
                if (is_file($full)) {
                    $source = file_get_contents($full);
                    if (false !== $source) {
                        return $source;
                    }
                }
            }

            return null;
        };
    }

    /**
     * Best-effort line locator for a YAML declaration — matches the LAST
     * dot-path segment as a mapping key (`  <segment>:`). Not AST-precise
     * (a repeated key name elsewhere in the file can shadow it), which is an
     * accepted trade-off for a lint-style locator whose job is to get an
     * agent close, not to be a source map.
     */
    private static function yamlLine(string $file, string $path): int
    {
        $segment = self::lastSegment($path);
        $found = self::grepLine($file, sprintf('/^\s*["\']?%s["\']?\s*:/', preg_quote($segment, '/')));

        return $found ?? 1;
    }

    private static function textLine(string $file, string $path): int
    {
        $segment = self::lastSegment($path);
        $found = self::grepLine($file, sprintf('/\b%s\b\s*:/', preg_quote($segment, '/')));

        return $found ?? 1;
    }

    private static function grepLine(string $file, string $pattern): ?int
    {
        if (!is_file($file)) {
            return null;
        }
        $lines = file($file) ?: [];
        foreach ($lines as $i => $line) {
            if (1 === preg_match($pattern, $line)) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function lastSegment(string $path): string
    {
        $parts = explode('.', $path);

        return (string) end($parts);
    }
}
