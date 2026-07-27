<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Baseline;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the framework props baseline (schemas/framework-props-baseline.yaml)
 * and answers "is this prop injected into every component by the render
 * pipeline?" (issue #27, phase 2).
 *
 * Modelled on TypeDefaults, and for the same reason: a fact that is true
 * across the whole corpus belongs in one derived-from-real-data file, not
 * repeated per component where it rots. The ACF baseline says "this value is
 * the type's default"; this one says "this input is `role: inherited`".
 *
 * Deliberately the ONLY blanket exemption the contract check honours. Every
 * other unaccounted prop is a finding, because every other suppression would
 * be an assertion nobody can check.
 *
 * ## Projects override it
 *
 * The shipped file states what parisek/timber-kit's render pipeline injects.
 * That is one framework's answer, and a project on a different one has a
 * different set: tailwind-base's skeleton treats `container` as a layout slot
 * every wrapper supplies, and its migration notes asked for it in the baseline
 * (docs/398-unresolved-roles.md). Hardcoding another project's convention into
 * a shipped file would make this table a negotiation; letting a project state
 * its own keeps it a fact about the framework in front of it.
 *
 * `discoverFor()` looks for `framework-props-baseline.yaml` next to the
 * components root, then one level up. A project file REPLACES the shipped one
 * rather than merging: a baseline is the list of props the check will never
 * report, and a half-inherited list is one nobody can read off the page.
 */
final class FrameworkProps
{
    /** @var array<string,list<string>> namespace => prop names */
    private array $baseline;

    /**
     * The baseline governing a components root: the project's own if it has
     * one, otherwise the shipped timber-kit table.
     *
     * @return array{props: self, path: ?string}
     */
    public static function discoverFor(string $componentsRoot): array
    {
        $componentsRoot = rtrim($componentsRoot, '/');

        foreach ([$componentsRoot, dirname($componentsRoot)] as $directory) {
            $candidate = $directory . '/framework-props-baseline.yaml';
            if (is_file($candidate)) {
                return ['props' => new self($candidate), 'path' => $candidate];
            }
        }

        return ['props' => new self(), 'path' => null];
    }

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/framework-props-baseline.yaml';
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed) || !isset($parsed['content']) || !is_array($parsed['content'])) {
            throw new \RuntimeException("Malformed framework props baseline (missing 'content' block): {$path}");
        }

        $this->baseline = [];
        foreach ($parsed as $namespace => $props) {
            if (!is_array($props)) {
                continue;
            }
            $this->baseline[(string) $namespace] = array_values(array_map(strval(...), $props));
        }
    }

    /**
     * @return list<string> the props injected under `content.*`
     */
    public function contentProps(): array
    {
        return $this->baseline['content'] ?? [];
    }

    /**
     * Is `content.<prop>` supplied by the framework for every component?
     *
     * Takes the bare prop name, not a dotted path: the check compares against
     * a component's own `fields:` map, whose keys are bare names too.
     */
    public function isFrameworkProp(string $prop): bool
    {
        return in_array($prop, $this->contentProps(), true);
    }
}
