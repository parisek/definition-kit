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
        $unresolved = [];
        $baselineProps = [];

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
                continue;
            }

            $roles[$fieldName] = $role;
        }

        // 2. Props the twig reads that the definition does not declare at all.
        foreach ($this->topLevelReads($reads) as $prop) {
            if (isset($fields[$prop])) {
                continue;
            }

            if ($this->frameworkProps->isFrameworkProp($prop)) {
                $baselineProps[] = $prop;
                continue;
            }

            $role = $this->roleForUndeclaredProp(
                $prop,
                $fields,
                $sidecar,
                $sidecarDerivedFrom,
                $callSites,
                $name,
                $derivedFrom,
            );
            if (null === $role) {
                $unresolved[] = $prop;
                continue;
            }

            $roles[$prop] = $role;
        }

        sort($unresolved);

        return new RoleProposal(
            $name,
            $roles,
            $derivedFrom,
            array_values(array_unique($unresolved)),
            $baselineProps,
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
     * @return list<string>
     */
    private function topLevelReads(PropReads $reads): array
    {
        $roots = [];
        foreach ($reads->reads as $read) {
            $root = explode('.', $read)[0];
            if (!in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
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

        foreach ($roles as $prop => $role) {
            $existing = isset($fields[$prop]) && is_array($fields[$prop]) ? $fields[$prop] : null;

            if (null === $existing) {
                // A prop the twig reads and the definition never had. It has no
                // ACF field behind it (that is why it was missing), so it gets
                // no `type`/`label` either — inventing an editor label for a
                // value no editor ever sees would be noise the author then has
                // to delete.
                $fields[$prop] = ['role' => $role];
            } else {
                $fields[$prop] = ['role' => $role, ...$existing];
            }

            if ('derived' === $role && isset($derivedFrom[$prop])) {
                $fields[$prop]['from'] = $derivedFrom[$prop];
            }
        }

        $definition['fields'] = $fields;

        return $definition;
    }
}
