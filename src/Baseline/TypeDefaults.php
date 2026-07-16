<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Baseline;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the shared ACF type-defaults baseline (schemas/acf-defaults-baseline.yaml)
 * and answers "does this raw ACF prop value equal the type's standard default?".
 * Consumed symmetrically by the migration (AcfJsonReader — drops matching props)
 * and, in dávka 3, the generator (re-adds them). See ADR 0006.
 */
final class TypeDefaults
{
    /** @var array<string,array<string,mixed>> */
    private array $baseline;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/acf-defaults-baseline.yaml';
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed) || !isset($parsed['common']) || !is_array($parsed['common'])) {
            throw new \RuntimeException("Malformed baseline file (missing 'common' block): {$path}");
        }
        $this->baseline = $parsed;
    }

    /** @return array<string,mixed> merged common + type-specific defaults */
    public function forType(string $acfType): array
    {
        $typeBlock = $this->baseline[$acfType] ?? [];
        return array_merge($this->baseline['common'], $typeBlock);
    }

    public function isDefault(string $acfType, string $prop, mixed $value): bool
    {
        $defaults = $this->forType($acfType);
        if (!array_key_exists($prop, $defaults)) {
            return false;
        }
        return $this->valuesEqual($value, $defaults[$prop]);
    }

    /**
     * @param array<string,mixed> $acfField
     * @return array<string,mixed> $acfField minus every prop equal to the type's default
     */
    public function stripDefaults(string $acfType, array $acfField): array
    {
        $remaining = $acfField;
        foreach ($this->forType($acfType) as $prop => $default) {
            if (array_key_exists($prop, $remaining) && $this->valuesEqual($remaining[$prop], $default)) {
                unset($remaining[$prop]);
            }
        }
        return $remaining;
    }

    /**
     * ACF stores booleans inconsistently (`0`/`1` ints, `false`/`true` bools,
     * even `"0"`/`"1"` strings depending on the field/version) — loose-compare
     * on the bool axis specifically, strict elsewhere.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (int) (bool) $a === (int) (bool) $b;
        }
        if (is_array($a) && is_array($b)) {
            return $a == $b;
        }
        if (is_array($a) || is_array($b)) {
            // One side is an array-typed baseline default (e.g. post_object's
            // `taxonomy: []`) and the raw ACF value is a scalar (real corpus
            // data has `taxonomy: ''` on some post_object fields) — never
            // equal, and casting an array to string would both warn and
            // collapse to the meaningless literal "Array".
            return false;
        }
        return $a === $b || (string) $a === (string) $b;
    }
}
