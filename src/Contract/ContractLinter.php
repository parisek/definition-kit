<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Parisek\DefinitionKit\Baseline\FrameworkProps;
use Symfony\Component\Yaml\Yaml;

/**
 * Compares what a component's twig reads against what its definition declares
 * (issue #27, phase 5).
 *
 * The check itself is small, because by this point the data carries the
 * suppression: a prop the twig reads that no role accounts for, and that the
 * framework baseline does not supply, is a defect. #26 planned this as a
 * linter with a maintained exception table; declaring the other five
 * provenances instead removed the need for one.
 *
 * ## The adoption gate is per component, not per project
 *
 * A component is **typed** when it declares at least one field and every
 * declared field carries a role — explicitly, or inherited from an ancestor
 * that does. That is an author saying "I have been through this definition
 * with the vocabulary in hand". Only a typed component is checked for real.
 *
 * Everything else is reported as **untyped**, never as passing. That
 * distinction is what makes incremental adoption safe: a project-level gate
 * would leave the check untrustworthy until every component was done, so
 * nobody would enable it, so it would never get done. Here the answer for any
 * individual component is binary from the first one.
 *
 * A component with `fields: {}` is untyped rather than vacuously typed. The
 * empty map is honest — `fields-migrate` writes it rather than guess — but it
 * records that nobody has stated the contract yet, not that there is none. A
 * `divider` becomes typed by declaring `inner: {role: parent}`, which is
 * exactly the fact that was missing.
 *
 * ## Where it stops looking
 *
 * A violation is only ever reported for a prop whose FIRST unknown segment
 * sits at a level the definition actually enumerates. Reads that go past a
 * declared leaf (`cta.url`, `image.src`) are ACF return-shape enrichment, not
 * separate props — #26 measured that category as its largest false-positive
 * source. Reads that go past a `query`/`parent`/`global`/`derived` field are
 * inside a structure the definition never claimed to enumerate.
 */
final class ContractLinter
{
    /** @deprecated Use ContractResult::NOTE_UNRESOLVED_FORWARD; kept so callers do not break. */
    public const NOTE_UNRESOLVED_FORWARD = ContractResult::NOTE_UNRESOLVED_FORWARD;

    /** @var array<string,ComponentShapeResolver> components root => resolver */
    private array $shapes = [];

    /**
     * @param array<string,string> $namespaces explicit `namespace => absolute
     *   directory` overrides, for a project whose layout does not match the
     *   `<templates>/component` convention `deriveNamespaceMap()` assumes —
     *   the same escape hatch `parisek/styleguide`'s own `namespaces` config
     *   provides its Twig loader (issue #56). Wins over the derived entry for
     *   any key it also names; every other conventional namespace is still
     *   derived. Keys are matched with or without a leading `@` (`macro` and
     *   `@macro` both resolve `@macro/…` includes) — a template always
     *   writes the `@`-prefixed form, and accepting either spelling here
     *   avoids a silent no-op override when a caller mirrors that spelling
     *   into this array instead of the bare form the internal lookup uses.
     */
    public function __construct(
        private readonly FrameworkProps $frameworkProps = new FrameworkProps(),
        private readonly array $namespaces = [],
    ) {
    }

    /**
     * A linter honouring the baseline that governs this components root — the
     * project's own `framework-props-baseline.yaml` when it has one.
     *
     * @param array<string,string> $namespaces see the constructor
     */
    public static function forComponentsRoot(string $componentsRoot, array $namespaces = []): self
    {
        return new self(FrameworkProps::discoverFor($componentsRoot)['props'], $namespaces);
    }

    public function lint(string $componentDir): ContractResult
    {
        $componentDir = rtrim($componentDir, '/');
        $shapes = $this->shapesFor($componentDir);
        $name = basename($componentDir);
        $yamlPath = "{$componentDir}/{$name}.yaml";
        $twigPath = "{$componentDir}/{$name}.twig";

        if (!is_file($yamlPath)) {
            return new ContractResult($name, ContractResult::UNTYPED, reason: "no {$name}.yaml");
        }
        if (!is_file($twigPath)) {
            return new ContractResult(
                $name,
                ContractResult::UNANALYSED,
                notes: $this->resolveForwards($this->fieldsOf($yamlPath), [], $this->shapesFor($componentDir)),
                reason: "no {$name}.twig to read",
            );
        }

        /** @var array<string,mixed> $definition */
        $definition = Yaml::parseFile($yamlPath) ?? [];
        $fields = isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];

        // Every `of:` target is resolved here, before anything is read.
        // Resolving them lazily as reads reached them meant a dangling
        // reference went unreported whenever the twig happened not to read
        // through it — a prop read as a whole, an untyped component, a missing
        // template. The defect is in the definition, so it is found by reading
        // the definition.
        $forwardNotes = $this->resolveForwards($fields, [], $shapes);

        $untypedReason = $this->untypedReason($fields);
        if (null !== $untypedReason && [] !== $fields) {
            return new ContractResult($name, ContractResult::UNTYPED, notes: $forwardNotes, reason: $untypedReason);
        }

        $reads = (new TwigPropExtractor($this->templateResolver($componentDir)))->extractFile($twigPath);

        foreach ($reads->notes as $note) {
            if (TwigPropExtractor::NOTE_PARSE_ERROR === $note['kind']) {
                return new ContractResult(
                    $name,
                    ContractResult::UNANALYSED,
                    notes: [...$reads->notes, ...$forwardNotes],
                    reason: $note['detail'],
                );
            }
        }

        $violations = [];
        $discriminatorNotes = [];
        foreach ($reads->reads as $read) {
            $outcome = $this->isAccountedFor($read, $fields, $shapes);
            if (null !== $outcome['note']) {
                $discriminatorNotes[] = $outcome['note'];
            }
            if (!$outcome['accounted']) {
                $violations[] = $read;
            }
        }

        $discriminatorNotes = [
            ...$discriminatorNotes,
            ...$this->deadLayoutLiteralNotes($reads->comparisons, $fields, $shapes),
        ];

        $notes = [...$reads->notes, ...$forwardNotes, ...$discriminatorNotes];

        if ([] === $fields) {
            // `fields: {}` and a template that reads nothing but framework
            // props is a complete contract, not an unstated one — a component
            // genuinely has no inputs. It is only untyped when the twig reads
            // something the empty map does not account for, which is what
            // "nobody has stated this yet" actually looks like.
            return [] === $violations
                ? new ContractResult($name, ContractResult::TYPED, notes: $notes)
                : new ContractResult($name, ContractResult::UNTYPED, notes: $notes, reason: (string) $untypedReason);
        }

        // The remaining notes can only HIDE reads, never invent them — an
        // include this walker did not follow adds reads, and a read it could
        // not name is a read it did not record. So the violations found are
        // real even when the analysis was partial, and the notes ride along so
        // a clean result is not mistaken for a complete one.
        return new ContractResult(
            $name,
            [] === $violations ? ContractResult::TYPED : ContractResult::VIOLATIONS,
            $violations,
            $notes,
        );
    }

    /**
     * One resolver per components root, so a `--root` sweep parses each
     * forwarded-to definition once rather than once per reference to it.
     * Instance-scoped rather than static: a cache that outlives the files it
     * describes answers from a stale parse, and a linter doing that is worse
     * than a slow one.
     */
    private function shapesFor(string $componentDir): ComponentShapeResolver
    {
        $root = dirname($componentDir);

        return $this->shapes[$root] ??= new ComponentShapeResolver($root);
    }

    /**
     * Every `of:` target the definition carries, resolved.
     *
     * @param array<string,mixed> $fields
     * @param list<string> $chain
     * @return list<array{kind: string, detail: string}>
     */
    private function resolveForwards(array $fields, array $chain, ComponentShapeResolver $shapes): array
    {
        $notes = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $path = [...$chain, (string) $name];

            if (ComponentShapeResolver::isComponentTarget($field['of'] ?? null)) {
                $resolved = $shapes->resolve((string) $field['of']);
                if (null !== $resolved['error']) {
                    $notes[] = [
                        'kind' => ContractResult::NOTE_UNRESOLVED_FORWARD,
                        'detail' => sprintf('%s: %s', implode('.', $path), $resolved['error']),
                    ];
                }
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $notes = [...$notes, ...$this->resolveForwards($field['fields'], $path, $shapes)];
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                        $notes = [
                            ...$notes,
                            ...$this->resolveForwards($layout['fields'], [...$path, (string) $layoutName], $shapes),
                        ];
                    }
                }
            }
        }

        return $notes;
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldsOf(string $yamlPath): array
    {
        /** @var array<string,mixed> $definition */
        $definition = Yaml::parseFile($yamlPath) ?? [];

        return isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];
    }

    /**
     * Why this component is not checkable yet, or null when it is.
     *
     * @param array<string,mixed> $fields
     */
    private function untypedReason(array $fields): ?string
    {
        if ([] === $fields) {
            return 'declares no fields — `fields: {}` records that the contract has not been '
                . 'stated yet, not that there is none. A component whose only inputs come from its '
                . 'caller declares them with `role: parent`.';
        }

        $unroled = $this->fieldsWithoutARole($fields, [], false);
        if ([] === $unroled) {
            return null;
        }

        return sprintf(
            '%d field(s) carry no role (%s). The check runs once every declared field says where '
            . 'its value comes from.',
            count($unroled),
            implode(', ', array_slice($unroled, 0, 5)) . (count($unroled) > 5 ? ', …' : ''),
        );
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $chain
     * @return list<string>
     */
    private function fieldsWithoutARole(array $fields, array $chain, bool $ancestorHasRole): array
    {
        $missing = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $path = [...$chain, (string) $name];
            // Role inheritance is a designed feature of the vocabulary — a
            // `role: query` repeater's rows are query-sourced too. Demanding
            // the key on every descendant would be demanding noise.
            $hasRole = $ancestorHasRole || isset($field['role']);

            if (!$hasRole) {
                $missing[] = implode('.', $path);
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $missing = [...$missing, ...$this->fieldsWithoutARole($field['fields'], $path, $hasRole)];
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                        $missing = [
                            ...$missing,
                            ...$this->fieldsWithoutARole($layout['fields'], [...$path, (string) $layoutName], $hasRole),
                        ];
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $fields
     */
    /**
     * @param array<string,mixed> $fields
     * @return array{accounted: bool, note: ?array{kind: string, detail: string}}
     */
    private function isAccountedFor(string $read, array $fields, ComponentShapeResolver $shapes): array
    {
        $segments = explode('.', $read);

        if ($this->frameworkProps->isFrameworkProp($segments[0])) {
            return ['accounted' => true, 'note' => null];
        }

        $level = $fields;
        // The field whose children $level currently enumerates — null at the
        // root. Needed only to answer "is `acf_fc_layout` legal here", which
        // depends on the immediately enclosing field's own declared type,
        // not on anything $level itself carries (a merged flexible_content
        // `layouts:` never lists `acf_fc_layout` — it is ACF's discriminator,
        // not an author-declared row field, so it can never appear as a key
        // there; see issue #63).
        //
        // Known, deliberately unfixed limitation (Codex review, PR #64): when
        // two layouts of the SAME flexible_content field declare a
        // same-named sub-field with different `type:`s (one flexible_content,
        // one not), $parentField below is whichever layout's declaration
        // childrenOf() merged in last — pre-existing "last write wins"
        // ambiguity in the layout merge, not new to this check. A dangling
        // `acf_fc_layout` read reached only through the OTHER layout's
        // shape of that field can go unreported. Fixing it needs the merge
        // itself to carry per-layout provenance, which is a larger change to
        // childrenOf() than this bugfix's scope; the shape is narrow enough
        // (two layouts, same field name, different container types) that no
        // real component has hit it yet.
        $parentField = null;

        foreach ($segments as $index => $segment) {
            // Only special-cased as the FINAL segment. `acf_fc_layout.typo`
            // or `acf_fc_layout['x']` is not a bare discriminator read — it
            // has no ACF meaning under either branch below, and treating it
            // as one would accept a malformed read the pre-fix code caught
            // (Codex review, issue #63 PR #64).
            $isLastSegment = $index === array_key_last($segments);
            if ('acf_fc_layout' === $segment && $isLastSegment && is_array($parentField)) {
                if ('flexible_content' === ($parentField['type'] ?? null)) {
                    return ['accounted' => true, 'note' => null];
                }

                // The enclosing field is declared but is not
                // flexible_content: acf_fc_layout does not exist there, the
                // comparison it feeds is always false, and the branch it
                // guards can never render. Distinct from — and more useful
                // than — "no role accounts for this prop", because that
                // message asks the author to declare something that cannot
                // be declared.
                $parentPath = implode('.', array_slice($segments, 0, $index));

                // Reported via the note, not the generic violation list — the
                // plain "no role accounts for this prop" message asks the
                // author to declare `acf_fc_layout`, which is impossible.
                // `accounted: true` here keeps it out of that list;
                // isFailure() still fails the component off the note kind.
                return [
                    'accounted' => true,
                    'note' => [
                        'kind' => ContractResult::NOTE_IMPOSSIBLE_DISCRIMINATOR,
                        'detail' => sprintf(
                            "reads `content.%s`, but `%s` is `%s`, not `flexible_content` — "
                            . 'acf_fc_layout only exists on flexible_content rows, so this '
                            . 'comparison is always false and the branch it guards can never render',
                            $read,
                            $parentPath,
                            $parentField['type'] ?? 'unknown',
                        ),
                    ],
                ];
            }

            $field = $level[$segment] ?? null;

            if (!is_array($field)) {
                // Unknown at a level the definition enumerates. At the root
                // that is an undeclared input; deeper, it is an undeclared row
                // field of an enumerated repeater — both are real.
                return ['accounted' => false, 'note' => null];
            }

            $isLast = $index === array_key_last($segments);
            if ($isLast) {
                return ['accounted' => true, 'note' => null];
            }

            $children = $this->childrenOf($field, $shapes);
            if (null === $children) {
                // A declared leaf, or a field whose structure the definition
                // never claimed to enumerate. Everything below it belongs to
                // that value, not to the component's contract.
                return ['accounted' => true, 'note' => null];
            }

            $level = $children;
            $parentField = $field;
        }

        return ['accounted' => true, 'note' => null];
    }

    /**
     * Part 3 of issue #63: `<field>.acf_fc_layout == '<literal>'` where
     * `<field>` is `type: flexible_content` and `<literal>` names none of its
     * declared `layouts:` keys. The branch can never be taken — the
     * definition already lists every layout that can occur.
     *
     * Deliberately narrow: only fires when the path resolves to a declared
     * flexible_content field's own `acf_fc_layout` and the comparator's
     * `TwigPropExtractor` could statically resolve the literal. Anything the
     * extractor did not capture (computed comparisons, a level the
     * definition does not enumerate) is silently out of scope rather than
     * guessed at.
     *
     * @param list<array{path: string, literal: string}> $comparisons
     * @param array<string,mixed> $fields
     * @return list<array{kind: string, detail: string}>
     */
    private function deadLayoutLiteralNotes(array $comparisons, array $fields, ComponentShapeResolver $shapes): array
    {
        $notes = [];

        foreach ($comparisons as $comparison) {
            $segments = explode('.', $comparison['path']);
            if ('acf_fc_layout' !== end($segments)) {
                continue;
            }

            $fieldPath = array_slice($segments, 0, -1);
            $field = $this->fieldAt($fieldPath, $fields, $shapes);

            if (null === $field || 'flexible_content' !== ($field['type'] ?? null)) {
                // Not a flexible_content field — either unresolvable (nothing
                // to check) or already reported by isAccountedFor() above as
                // an impossible discriminator, which is the more relevant
                // finding for that shape.
                continue;
            }

            $layouts = is_array($field['layouts'] ?? null) ? $field['layouts'] : [];
            if (array_key_exists($comparison['literal'], $layouts)) {
                continue;
            }

            $notes[] = [
                'kind' => ContractResult::NOTE_DEAD_LAYOUT_LITERAL,
                'detail' => sprintf(
                    "content.%s == '%s' can never be true — declared layouts for `%s` are: %s",
                    $comparison['path'],
                    $comparison['literal'],
                    implode('.', $fieldPath),
                    [] === $layouts ? '(none)' : implode(', ', array_keys($layouts)),
                ),
            ];
        }

        return $notes;
    }

    /**
     * Walks a dotted field path against a definition's `fields:` map,
     * following flexible_content `layouts:` the same way `childrenOf()`
     * does for repeaters/groups — but returning the field itself rather than
     * its children, since the caller needs the field's own `type`/`layouts`.
     *
     * @param list<string> $path
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|null
     */
    private function fieldAt(array $path, array $fields, ComponentShapeResolver $shapes): ?array
    {
        $level = $fields;
        $field = null;

        foreach ($path as $index => $segment) {
            $field = $level[$segment] ?? null;
            if (!is_array($field)) {
                return null;
            }

            if ($index === array_key_last($path)) {
                return $field;
            }

            // Delegates to childrenOf() rather than re-walking `fields:` /
            // `layouts:` here — it already follows `of:` forwards through
            // ComponentShapeResolver, which a hand-rolled duplicate here
            // previously did not (issue #63 PR #64 review): a dead layout
            // literal underneath a forwarded shape went unchecked.
            $children = $this->childrenOf($field, $shapes);
            if (null === $children) {
                return null;
            }

            $level = $children;
        }

        return $field;
    }

    /**
     * The fields a container enumerates, or null when the value below this
     * point is not the definition's to describe.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    private function childrenOf(array $field, ComponentShapeResolver $shapes): ?array
    {
        if (ComponentShapeResolver::isComponentTarget($field['of'] ?? null)) {
            // The shape lives in the component this prop is forwarded to, so
            // the check reads it from there. This is what makes the reference
            // worth having over a transcript: adding a field to the child now
            // reaches every parent that forwards to it. An unreachable target
            // leaves everything below it opaque; resolveForwards() has already
            // reported it.
            return $shapes->resolve((string) $field['of'])['fields'];
        }

        if (true === ($field['open'] ?? false)) {
            // An open map's keys are not knowable in advance. What is inside
            // belongs to whoever fills it, not to this component's contract.
            return null;
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            // Declared children are checked whatever the role. An author who
            // enumerates the shape of a `parent` or `query` prop is claiming
            // it, and a claim nobody verifies is the thing this check exists
            // to remove — 18 such declarations across two real projects were
            // being ignored, including every menu shape in this theme.
            return $field['fields'];
        }

        if ('field' !== ($field['role'] ?? 'field')) {
            // Nothing is declared, and the role says the value comes from
            // somewhere this definition does not describe: a query row, a
            // value handed in by a caller, a derived structure. Its shape is
            // that source's business.
            return null;
        }

        if (isset($field['layouts']) && is_array($field['layouts'])) {
            // A flexible_content read names a layout's field; which layout is
            // a runtime fact, so any layout declaring the name accounts for it.
            $merged = [];
            foreach ($field['layouts'] as $layout) {
                if (is_array($layout) && isset($layout['fields']) && is_array($layout['fields'])) {
                    $merged = [...$merged, ...$layout['fields']];
                }
            }

            return $merged;
        }

        return null;
    }

    /**
     * Resolves an include/import path as written in a component twig.
     *
     * Two shapes reach this closure: an un-namespaced relative path
     * (`component/button/button.twig`), for which walking up from the
     * component's own directory finds the file without any configuration;
     * and a namespace-aliased path (`@macro/parts/parts.twig`), which is the
     * only form real projects write and — before issue #56 — this resolver
     * could never open, silently reducing macro-following (issue #55) to
     * dead code outside this package's own test fixtures.
     *
     * @return \Closure(string): ?string
     */
    private function templateResolver(string $componentDir): \Closure
    {
        $explicitNamespaces = [];
        foreach ($this->namespaces as $key => $namespaceDir) {
            // Accept both `macro` and `@macro` as the key spelling (see the
            // constructor docblock) — the internal lookup below always
            // strips the `@` off the path it is resolving, so a caller who
            // mirrored the `@`-prefixed spelling into this array would
            // otherwise silently fail to override anything.
            $explicitNamespaces[ltrim((string) $key, '@')] = $namespaceDir;
        }

        $namespaceMap = [...$this->deriveNamespaceMap($componentDir), ...$explicitNamespaces];

        return static function (string $path) use ($componentDir, $namespaceMap): ?string {
            if (str_starts_with($path, '@')) {
                $slash = strpos($path, '/');
                $namespace = false !== $slash ? substr($path, 1, $slash - 1) : substr($path, 1);
                $rest = false !== $slash ? substr($path, $slash + 1) : '';

                // An unknown namespace declines rather than guesses — the
                // same rule this code already applies to an ambiguous macro
                // binding (#55). Falling back to the un-namespaced walk below
                // would risk reading `@foo/button/button.twig` as a relative
                // path that happens to exist by coincidence a few directories
                // up, attributing reads to a file the template never named.
                if (!isset($namespaceMap[$namespace])) {
                    return null;
                }

                return self::readWithinRoot($namespaceMap[$namespace], $rest);
            }

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
     * Reads `$root/$rest`, refusing to return anything that resolves outside
     * `$root` once symlinks and `..` segments are canonicalised.
     *
     * A namespace maps to an absolute directory the project configured (or
     * `deriveNamespaceMap()` inferred); the `$rest` of the include path is
     * attacker-reachable in the sense that it is copied verbatim out of a
     * twig source file the linter is asked to analyse. Two escapes are
     * possible and `realpath()` is the only thing that closes both at once:
     * a `..`-laden `$rest` (`@macro/../../../../etc/hosts`) walks the joined
     * path outside `$root` with no symlink involved; a symlinked directory
     * sitting inside `$root` (a `component`/`static`/`icons` dir that is
     * itself a link, or one further down the resolved path) walks outside it
     * with no `..` involved. `is_file()`/`file_get_contents()` on the raw
     * concatenation check neither.
     *
     * `realpath()` returns `false` for a path that does not exist, which
     * also covers the case where `$root` itself does not exist — so this
     * doubles as the existence check `is_file()` used to provide.
     */
    private static function readWithinRoot(string $root, string $rest): ?string
    {
        $realRoot = realpath($root);
        if (false === $realRoot) {
            return null;
        }

        $full = rtrim($root, '/') . '/' . ltrim($rest, '/');
        $realFull = realpath($full);
        if (false === $realFull) {
            return null;
        }

        if ($realFull !== $realRoot && !str_starts_with($realFull, $realRoot . DIRECTORY_SEPARATOR)) {
            // Resolved outside the namespace's own directory — decline
            // exactly as an unknown namespace does, rather than surface a
            // read the template's namespace never authorised.
            return null;
        }

        if (!is_file($realFull)) {
            return null;
        }

        $source = file_get_contents($realFull);

        return false !== $source ? $source : null;
    }

    /**
     * The conventional `@namespace => directory` map `parisek/styleguide`'s
     * `Styleguide::registerConventionalNamespaces()` wires onto its Twig
     * loader, derived from the components root this linter already knows —
     * without asking a project to restate a mapping it never configured.
     *
     * Only derived when `$componentDir`'s parent is itself named `component`,
     * matching the `<templates>/component/<slug>` shape every convention
     * below assumes. A components root laid out any other way gets an empty
     * map here — every namespace then declines in `templateResolver()` —
     * rather than a guess built on a shape that does not hold; the
     * constructor's `$namespaces` parameter is the escape hatch for that
     * project.
     *
     * `@icons`/`@images` are similarly conditional: they live under
     * `static_path`, a sibling of `templates/` — derivable only when the
     * templates root is itself named `templates` (`STATIC_PATH/templates`,
     * the convention every shipped project follows). Off that convention,
     * this method cannot locate `static_path` with any confidence and omits
     * both rather than guess; they remain reachable via `$namespaces`.
     *
     * @return array<string,string>
     */
    private function deriveNamespaceMap(string $componentDir): array
    {
        $componentsRoot = rtrim(dirname(rtrim($componentDir, '/')), '/');

        if ('component' !== basename($componentsRoot)) {
            return [];
        }

        $templatesRoot = dirname($componentsRoot);

        $map = [
            'component' => $componentsRoot,
            'macro' => $templatesRoot . '/macro',
            'page' => $templatesRoot . '/page',
            'doc' => $templatesRoot . '/doc',
            'static' => $templatesRoot,
        ];

        if ('templates' === basename($templatesRoot)) {
            $staticRoot = dirname($templatesRoot);
            $map['icons'] = $staticRoot . '/images/icons';
            $map['images'] = $staticRoot . '/images';
        }

        return $map;
    }
}
