<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Contract;

use Parisek\DefinitionKit\Baseline\DerivedProps;
use Parisek\DefinitionKit\Baseline\FrameworkProps;
use Symfony\Component\Yaml\Yaml;

/**
 * Proposes a role per prop from evidence (issue #27, phase 4).
 *
 * Roles for 200 components cannot be hand-written, and a tool that guesses at
 * them is worse than no tool: a wrong `role:` is a false claim about where a
 * value comes from, and the contract check would then be verifying fiction.
 * So this proposes only what it can point at, and leaves the rest blank and
 * listed — the same discipline `fields-migrate` already applies when it writes
 * `fields: {}` rather than invent a field.
 *
 * | evidence                                              | proposed        |
 * | ----------------------------------------------------- | --------------- |
 * | already declared, with an ACF field behind it          | `field`         |
 * | assigned by the component's own `.php`, query involved | `query`         |
 * | assigned from options / `formatFields('option')`       | `global`        |
 * | lifted by the sidecar out of a declared sibling         | `derived` + `from:` |
 * | in the derived-props table, with the sibling present   | `derived` + `from:` |
 * | in the framework baseline                              | omitted entirely |
 * | read, and handed in by a call site                     | `parent`        |
 * | anything else                                          | **left blank**  |
 *
 * Only top-level reads are proposed for. A read inside a declared repeater is
 * governed by that repeater's own role through inheritance, and one inside an
 * undeclared prop cannot be described before its root is.
 */
final class RoleProposer
{
    public function __construct(
        private readonly FrameworkProps $frameworkProps = new FrameworkProps(),
        private readonly DerivedProps $derivedProps = new DerivedProps(),
        private readonly PhpSidecarEvidence $sidecarEvidence = new PhpSidecarEvidence(),
    ) {
    }

    /** Proposing and checking must agree on what the framework supplies. */
    public static function forComponentsRoot(string $componentsRoot): self
    {
        return new self(FrameworkProps::discoverFor($componentsRoot)['props']);
    }

    public function propose(string $componentDir, ?CallSiteIndex $callSites = null): RoleProposal
    {
        $componentDir = rtrim($componentDir, '/');
        $name = basename($componentDir);
        $yamlPath = "{$componentDir}/{$name}.yaml";
        $twigPath = "{$componentDir}/{$name}.twig";

        if (!is_file($yamlPath)) {
            return new RoleProposal($name, skipped: "no {$name}.yaml — run fields-migrate first");
        }
        if (!is_file($twigPath)) {
            return new RoleProposal($name, skipped: "no {$name}.twig to read");
        }

        /** @var array<string,mixed> $definition */
        $definition = Yaml::parseFile($yamlPath) ?? [];
        $fields = isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];

        $acfNames = $this->acfBackedNames("{$componentDir}/acf.json");
        $sidecarEvidence = $this->sidecarEvidence->evidence("{$componentDir}/{$name}.php");
        $sidecar = $sidecarEvidence['roles'];
        $sidecarDerivedFrom = $sidecarEvidence['derivedFrom'];
        $reads = (new TwigPropExtractor())->extractFile($twigPath);

        $roles = [];
        $derivedFrom = [];
        $nestedDerivedFrom = [];
        $unresolved = [];
        $baselineProps = [];
        $hints = [];

        // 1. Fields already declared but carrying no role.
        foreach ($fields as $fieldName => $field) {
            $fieldName = (string) $fieldName;
            if (!is_array($field) || isset($field['role'])) {
                continue;
            }

            $role = $this->roleForDeclaredField(
                $fieldName,
                $field,
                $fields,
                $acfNames,
                $sidecar,
                $sidecarDerivedFrom,
                $callSites,
                $name,
                $derivedFrom,
            );
            if (null === $role) {
                $unresolved[] = $fieldName;
                $this->hint($hints, $fieldName, $fieldName, $callSites, $name);
                continue;
            }

            $roles[$fieldName] = $role;
        }

        // 2. Props the twig reads that the definition does not declare at all,
        //    at whatever depth the definition stops describing them.
        foreach ($this->undeclaredReads($reads, $fields, $baselineProps) as $path => $siblings) {
            $path = (string) $path;
            $segments = explode('.', $path);
            $prop = end($segments);
            $isRoot = 1 === count($segments);

            $role = $this->roleForUndeclaredProp(
                $prop,
                $siblings,
                // A sidecar assigns onto `$content`, and a caller hands props to
                // the component, not to a row of one of its repeaters. Neither
                // is evidence about anything nested.
                $isRoot ? $sidecar : [],
                $isRoot ? $sidecarDerivedFrom : [],
                $isRoot ? $callSites : null,
                $name,
                $nestedDerivedFrom,
            );

            if (null === $role) {
                $unresolved[] = $path;
                $this->hint($hints, $path, $prop, $isRoot ? $callSites : null, $name);
                continue;
            }

            $roles[$path] = $role;
            if (isset($nestedDerivedFrom[$prop])) {
                $derivedFrom[$path] = $nestedDerivedFrom[$prop];
                unset($nestedDerivedFrom[$prop]);
            }
        }

        sort($unresolved);

        return new RoleProposal(
            $name,
            $roles,
            $derivedFrom,
            array_values(array_unique($unresolved)),
            $baselineProps,
            $hints,
            $this->apply($definition, $roles, $derivedFrom),
        );
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $siblings
     * @param list<string> $acfNames
     * @param array<string,?string> $sidecar
     * @param array<string,string> $sidecarDerivedFrom
     * @param array<string,string> $derivedFrom
     */
    private function roleForDeclaredField(
        string $name,
        array $field,
        array $siblings,
        array $acfNames,
        array $sidecar,
        array $sidecarDerivedFrom,
        ?CallSiteIndex $callSites,
        string $component,
        array &$derivedFrom,
    ): ?string {
        if (in_array($name, $acfNames, true)) {
            return 'field';
        }

        $fromSidecar = $this->fromSidecar($name, $sidecar, $sidecarDerivedFrom, $siblings, $derivedFrom);
        if (null !== $fromSidecar) {
            return $fromSidecar;
        }

        $origin = $this->derivedProps->originOf($name, $siblings);
        if (null !== $origin) {
            $derivedFrom[$name] = $origin;

            return 'derived';
        }

        // Evidence order matters here: an ACF field wins over a call site.
        // Callers hand values to editor-backed props all the time (a styleguide
        // fixture passes `title` to a component whose `title` is a real ACF
        // field), so consulting call sites first would relabel genuine `field`
        // props as `parent` across a whole project.
        if (null !== $callSites && $callSites->isPassedTo($component, $name)) {
            return 'parent';
        }

        // Declared, but nothing on disk says where the value comes from. The
        // definition being ACF-shaped is not evidence of an ACF field: a
        // hand-authored `type: text` is what a `parent` prop looks like too.
        return null;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,?string> $sidecar
     * @param array<string,string> $sidecarDerivedFrom
     * @param array<string,string> $derivedFrom
     */
    private function roleForUndeclaredProp(
        string $prop,
        array $fields,
        array $sidecar,
        array $sidecarDerivedFrom,
        ?CallSiteIndex $callSites,
        string $component,
        array &$derivedFrom,
    ): ?string {
        $fromSidecar = $this->fromSidecar($prop, $sidecar, $sidecarDerivedFrom, $fields, $derivedFrom);
        if (null !== $fromSidecar) {
            return $fromSidecar;
        }

        $origin = $this->derivedProps->originOf($prop, $fields);
        if (null !== $origin) {
            $derivedFrom[$prop] = $origin;

            return 'derived';
        }

        if (null !== $callSites && $callSites->isPassedTo($component, $prop)) {
            return 'parent';
        }

        return null;
    }

    /**
     * What the sidecar says, including a lift out of a declared sibling.
     *
     * A `derived` proposal is dropped when the sibling it names is not
     * declared: `from:` must resolve, and writing one that dangles is the
     * assertion `fields-validate` exists to reject.
     *
     * @param array<string,?string> $sidecar
     * @param array<string,string> $sidecarDerivedFrom
     * @param array<string,mixed> $siblings
     * @param array<string,string> $derivedFrom
     */
    private function fromSidecar(
        string $prop,
        array $sidecar,
        array $sidecarDerivedFrom,
        array $siblings,
        array &$derivedFrom,
    ): ?string {
        $role = $sidecar[$prop] ?? null;

        if ('derived' === $role) {
            $origin = $sidecarDerivedFrom[$prop] ?? null;
            if (null === $origin || !isset($siblings[$origin])) {
                return null;
            }

            $derivedFrom[$prop] = $origin;

            return 'derived';
        }

        return $role;
    }

    /**
     * What is known about a prop no role could be proposed for.
     *
     * A fixture passing it is not evidence — in a styleguide repository every
     * prop has a fixture — but it is exactly the question `field` vs `parent`
     * turns on, and pointing at the fixture beats leaving a bare blank.
     *
     * @param array<string,string> $hints
     */
    private function hint(
        array &$hints,
        string $path,
        string $prop,
        ?CallSiteIndex $callSites,
        string $component,
    ): void {
        if (null !== $callSites && $callSites->isPassedOnlyByFixtureTo($component, $prop)) {
            $hints[$path] = 'passed by a styleguide fixture only — content an editor authors is `field`, '
                . 'wiring a parent supplies is `parent`';
        }
    }

    /**
     * The reads the definition does not account for, each mapped to the
     * `fields:` map it would be declared in.
     *
     * Descends exactly as far as the definition itself does. A read stops being
     * describable at the first segment that is missing from a level the
     * definition enumerates — everything below that belongs to a prop that does
     * not exist yet, and everything past a declared leaf or a non-`field` role
     * is that value's own shape rather than the component's contract.
     *
     * Nesting matters more than it looks: `article-video-grid` reads
     * `items.sources`, the framework enrichment `role: derived` was invented
     * for, and a top-level-only proposer flags it instead of proposing it.
     *
     * @param array<string,mixed> $fields
     * @param list<string> $baselineProps collected as a side effect
     * @return array<string,array<string,mixed>> dotted path => the sibling map it belongs to
     */
    private function undeclaredReads(PropReads $reads, array $fields, array &$baselineProps): array
    {
        $candidates = [];

        foreach ($reads->reads as $read) {
            $segments = explode('.', $read);

            if ($this->frameworkProps->isFrameworkProp($segments[0])) {
                if (!in_array($segments[0], $baselineProps, true)) {
                    $baselineProps[] = $segments[0];
                }

                continue;
            }

            $level = $fields;
            $walked = [];

            foreach ($segments as $segment) {
                $walked[] = $segment;
                $field = $level[$segment] ?? null;

                if (!is_array($field)) {
                    $candidates[implode('.', $walked)] = $level;
                    break;
                }

                $children = $this->enumeratedChildren($field);
                if (null === $children) {
                    break;
                }

                $level = $children;
            }
        }

        ksort($candidates);

        return $candidates;
    }

    /**
     * The fields a container enumerates, or null when what is below is not the
     * definition's to describe. Mirrors ContractLinter's rule, so the proposer
     * and the check agree on where a contract stops.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    private function enumeratedChildren(array $field): ?array
    {
        if (true === ($field['open'] ?? false)) {
            // An open map's keys are not knowable in advance. What is inside
            // belongs to whoever fills it, not to this component's contract.
            return null;
        }

        if ('field' !== ($field['role'] ?? 'field')) {
            return null;
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            return $field['fields'];
        }

        if (isset($field['layouts']) && is_array($field['layouts'])) {
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
     * Field names with an actual ACF field behind them. Read from the sibling
     * acf.json rather than assumed from the definition: "it is in the YAML" is
     * not evidence of an editor-authored value, which is the whole distinction
     * `role:` exists to record.
     *
     * @return list<string>
     */
    private function acfBackedNames(string $acfJsonPath): array
    {
        if (!is_file($acfJsonPath)) {
            return [];
        }

        $raw = file_get_contents($acfJsonPath);
        if (false === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $names = [];
        $collect = static function (mixed $node) use (&$collect, &$names): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['name']) && is_string($node['name'])) {
                $names[] = $node['name'];
            }
            foreach ($node as $child) {
                $collect($child);
            }
        };
        $collect($decoded['fields'] ?? $decoded);

        return array_values(array_unique($names));
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<string,string> $roles
     * @param array<string,string> $derivedFrom
     * @return array<string,mixed>
     */
    private function apply(array $definition, array $roles, array $derivedFrom): array
    {
        $fields = isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];

        foreach ($roles as $path => $role) {
            $from = 'derived' === $role ? ($derivedFrom[$path] ?? null) : null;
            $fields = $this->applyOne($fields, explode('.', (string) $path), $role, $from);
        }

        $definition['fields'] = $fields;

        return $definition;
    }

    /**
     * Writes one role at the depth its path names.
     *
     * The walk stops wherever the definition stops enumerating, which is the
     * same place `undeclaredReads()` found the prop — so a path always lands
     * either on an existing field or in the map that field would live in.
     *
     * @param array<string,mixed> $fields
     * @param list<string> $segments
     * @return array<string,mixed>
     */
    private function applyOne(array $fields, array $segments, string $role, ?string $from): array
    {
        $name = array_shift($segments);
        if (null === $name) {
            return $fields;
        }

        if ([] !== $segments) {
            $child = isset($fields[$name]) && is_array($fields[$name]) ? $fields[$name] : null;
            if (null === $child) {
                return $fields;
            }

            if (isset($child['fields']) && is_array($child['fields'])) {
                $child['fields'] = $this->applyOne($child['fields'], $segments, $role, $from);
                $fields[$name] = $child;

                return $fields;
            }

            if (isset($child['layouts']) && is_array($child['layouts'])) {
                // A flexible_content read names a layout's field without saying
                // which layout. Writing the role into every layout that already
                // declares the sibling would be a guess about shape; writing it
                // into the one layout that does is not.
                foreach ($child['layouts'] as $layoutName => $layout) {
                    if (!is_array($layout) || !isset($layout['fields']) || !is_array($layout['fields'])) {
                        continue;
                    }
                    if (null !== $from && !isset($layout['fields'][$from])) {
                        continue;
                    }
                    $layout['fields'] = $this->applyOne($layout['fields'], $segments, $role, $from);
                    $child['layouts'][$layoutName] = $layout;
                }
                $fields[$name] = $child;
            }

            return $fields;
        }

        $existing = isset($fields[$name]) && is_array($fields[$name]) ? $fields[$name] : null;

        // A prop the twig reads and the definition never had. It has no ACF
        // field behind it (that is why it was missing), so it gets no
        // `type`/`label` either — inventing an editor label for a value no
        // editor ever sees is noise the author then has to delete.
        $fields[$name] = null === $existing ? ['role' => $role] : ['role' => $role, ...$existing];

        if (null !== $from) {
            $fields[$name]['from'] = $from;
        }

        return $fields;
    }
}
