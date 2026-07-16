<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Baseline\TypeDefaults;
use Symfony\Component\Yaml\Yaml;

/**
 * Filters Support\StructuralDiff output down to genuine drift, dropping
 * entries that match one of the narrow, README-documented,
 * corpus-census-derived residuals (schemas/drift-lint-allowlist.yaml).
 * Each rule matches on an exact (prop, expected, actual) triple plus a
 * scope — never a bare prop name — so a NEW, different-valued diff on
 * the same prop still surfaces as real drift. See that YAML file's own
 * header comment for the doctrine this class encodes.
 *
 * `kind: missing` rules (e.g. legacy-minimal-export-prop-set) additionally
 * require the diff's EXPECTED value — what the definition generates —
 * to equal the ACF baseline default for that prop at that field's type
 * (Baseline\TypeDefaults, the same baseline Generator\* uses). A legacy
 * export omitting a prop the generator would fill with its own default
 * is benign; a legacy export omitting a prop the author has since given
 * a real, non-default value is genuine drift and must NOT be excused by
 * scope/name alone — otherwise the allowlist can hide authored content
 * that never made it into the committed acf.json.
 */
final class DriftAllowlist
{
    /** @var list<array<string,mixed>> */
    private array $rules;

    public function __construct(
        ?string $path = null,
        private readonly TypeDefaults $typeDefaults = new TypeDefaults(),
    ) {
        $path ??= __DIR__ . '/../../schemas/drift-lint-allowlist.yaml';
        $parsed = Yaml::parseFile($path);
        /** @var list<array<string,mixed>> $rules */
        $rules = is_array($parsed) ? (array) ($parsed['rules'] ?? []) : [];
        $this->rules = $rules;
    }

    /**
     * @param list<array{kind:string,path:string,prop:string,expected:mixed,actual:mixed}> $diffs
     * @param array<string,mixed> $generated
     * @return list<array{kind:string,path:string,prop:string,expected:mixed,actual:mixed}>
     */
    public function filter(string $componentSlug, array $diffs, array $generated): array
    {
        return array_values(array_filter(
            $diffs,
            fn (array $diff): bool => !$this->isAllowed($componentSlug, $diff, $generated),
        ));
    }

    /**
     * @param array{kind:string,path:string,prop:string,expected:mixed,actual:mixed} $diff
     * @param array<string,mixed> $generated
     */
    private function isAllowed(string $componentSlug, array $diff, array $generated): bool
    {
        foreach ($this->rules as $rule) {
            if ($this->ruleMatches($rule, $componentSlug, $diff, $generated)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array{kind:string,path:string,prop:string,expected:mixed,actual:mixed} $diff
     * @param array<string,mixed> $generated
     */
    private function ruleMatches(array $rule, string $componentSlug, array $diff, array $generated): bool
    {
        if (($rule['kind'] ?? null) !== $diff['kind']) {
            return false;
        }
        if (!in_array($diff['prop'], (array) ($rule['props'] ?? []), true)) {
            return false;
        }
        if (($rule['root_only'] ?? false) && !$this->isRootLevelPath($diff['path'])) {
            return false;
        }
        if (isset($rule['components']) && !in_array($componentSlug, (array) $rule['components'], true)) {
            return false;
        }
        if (isset($rule['when_type'])) {
            $type = $this->fieldTypeAt($diff['path'], $generated);
            if ($rule['when_type'] !== $type) {
                return false;
            }
        }

        return match ($diff['kind']) {
            'missing' => $this->missingIsBenign($diff, $generated),
            'value' => $this->valueMatches($rule['expected'] ?? null, $diff['expected'])
                && $this->valueMatches($rule['actual'] ?? null, $diff['actual']),
            default => false,
        };
    }

    /**
     * A `missing` diff (prop absent from the committed acf.json) is only
     * benign when the generator's own EXPECTED value for that prop is the
     * type's baseline default — i.e. the legacy export simply omitted a
     * prop the generator would have filled in identically anyway. If the
     * definition now authors that prop to a real, non-default value, the
     * missing prop is genuine drift and must fail, not be scope-matched
     * away by name alone.
     *
     * `parent_repeater` is a deliberate, narrow exception: it is pure
     * ACF-computed structural metadata (the enclosing repeater's own
     * key, re-derived from nesting — see Generator\FieldsGenerator and
     * Migration\AcfJsonReader::ALWAYS_DROPPED), never authored content,
     * so it has no "default value" to compare against at all — its
     * expected value legitimately varies per call site (the repeater's
     * key string) and can never appear in a type baseline.
     *
     * @param array{kind:string,path:string,prop:string,expected:mixed,actual:mixed} $diff
     * @param array<string,mixed> $generated
     */
    private function missingIsBenign(array $diff, array $generated): bool
    {
        if ('parent_repeater' === $diff['prop']) {
            return true;
        }

        $type = $this->isRootLevelPath($diff['path'])
            ? 'root'
            : $this->fieldTypeAt($diff['path'], $generated);

        if (null === $type) {
            // No type context recoverable at all — nothing to check the
            // expected value against, so it can't be proven benign.
            return $this->isEmptyish($diff['expected']);
        }

        $defaults = $this->typeDefaults->forType($type);
        if (!array_key_exists($diff['prop'], $defaults)) {
            // No baseline default exists for this prop at this type at all
            // (e.g. conditional_logic, deliberately excluded from the
            // baseline because it's semantically lifted to visible_when
            // elsewhere). Only an empty/falsy expected — i.e. "nothing was
            // authored" — is benign; a real, non-empty value has no
            // baseline to prove itself benign against, so it fails.
            return $this->isEmptyish($diff['expected']);
        }

        return $this->typeDefaults->isDefault($type, $diff['prop'], $diff['expected']);
    }

    /** `null`/`''`/`[]`/`false`/`0` — "nothing was authored" shapes, mirroring Support\StructuralDiff's own conditional_logic emptyIsh check. */
    private function isEmptyish(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value || false === $value || 0 === $value;
    }

    /**
     * A rule's `expected`/`actual` may be a single scalar (must match
     * exactly) or a list of alternatives (the README's multi-shape
     * legacy residuals, e.g. hide_on_screen's `[]`/`null`/`false`).
     */
    private function valueMatches(mixed $ruleValue, mixed $diffValue): bool
    {
        if (is_array($ruleValue) && array_is_list($ruleValue)) {
            foreach ($ruleValue as $alternative) {
                if ($alternative === $diffValue) {
                    return true;
                }
            }
            return false;
        }
        return $ruleValue === $diffValue;
    }

    /** `.hide_on_screen` is root-level; `.fields[0].hide_on_screen` or `.fields[0].sub_fields[0].x` are not. */
    private function isRootLevelPath(string $path): bool
    {
        return 1 === preg_match('/^\.[^.\[]+$/', $path);
    }

    /**
     * Walks `$generated` using the diff's own path (minus its trailing
     * `.prop` segment) to find the containing field's own `type` — the
     * type context `when_type` rules need but StructuralDiff's flat
     * diff entries don't carry on their own.
     *
     * @param array<string,mixed> $generated
     */
    private function fieldTypeAt(string $path, array $generated): ?string
    {
        $segments = $this->pathSegments($path);
        array_pop($segments); // drop the trailing prop name itself

        $node = $generated;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? (($node['type'] ?? null) ?: null) : null;
    }

    /** @return list<int|string> */
    private function pathSegments(string $path): array
    {
        $segments = [];
        foreach (explode('.', trim($path, '.')) as $part) {
            if ('' === $part) {
                continue;
            }
            if (preg_match('/^([^\[]+)((?:\[\d+\])*)$/', $part, $m)) {
                $segments[] = $m[1];
                foreach (explode('[', trim($m[2], ']')) as $index) {
                    if ('' !== $index) {
                        $segments[] = (int) $index;
                    }
                }
            } else {
                $segments[] = $part;
            }
        }
        return $segments;
    }
}
