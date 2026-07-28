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

    public function __construct(
        private readonly FrameworkProps $frameworkProps = new FrameworkProps(),
    ) {
    }

    /**
     * A linter honouring the baseline that governs this components root — the
     * project's own `framework-props-baseline.yaml` when it has one.
     */
    public static function forComponentsRoot(string $componentsRoot): self
    {
        return new self(FrameworkProps::discoverFor($componentsRoot)['props']);
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
        foreach ($reads->reads as $read) {
            if (!$this->isAccountedFor($read, $fields, $shapes)) {
                $violations[] = $read;
            }
        }

        $notes = [...$reads->notes, ...$forwardNotes];

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
    private function isAccountedFor(string $read, array $fields, ComponentShapeResolver $shapes): bool
    {
        $segments = explode('.', $read);

        if ($this->frameworkProps->isFrameworkProp($segments[0])) {
            return true;
        }

        $level = $fields;

        foreach ($segments as $index => $segment) {
            $field = $level[$segment] ?? null;

            if (!is_array($field)) {
                // Unknown at a level the definition enumerates. At the root
                // that is an undeclared input; deeper, it is an undeclared row
                // field of an enumerated repeater — both are real.
                return false;
            }

            $isLast = $index === array_key_last($segments);
            if ($isLast) {
                return true;
            }

            $children = $this->childrenOf($field, $shapes);
            if (null === $children) {
                // A declared leaf, or a field whose structure the definition
                // never claimed to enumerate. Everything below it belongs to
                // that value, not to the component's contract.
                return true;
            }

            $level = $children;
        }

        return true;
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
     * Resolves an include path as written in a component twig.
     *
     * Templates are included by a path relative to the theme's template root
     * (`component/button/button.twig`), which this package has no configuration
     * for. Walking up from the component's own directory finds it without one,
     * and stops well before it could wander outside the theme.
     *
     * @return \Closure(string): ?string
     */
    private function templateResolver(string $componentDir): \Closure
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
}
