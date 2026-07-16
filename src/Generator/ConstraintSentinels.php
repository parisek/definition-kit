<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the per-(ACF field type, constraint prop) "no constraint
 * authored" empty-sentinel table (schemas/constraint-sentinels.yaml).
 * Deliberately separate from Baseline\TypeDefaults — see that table's
 * header comment and this class's own schema file for why constraint
 * props can't live in the shared type-defaults baseline. Consumed by
 * Generator\FieldsGenerator to fill in a raw ACF value for any
 * constraint prop the semantic layer leaves unset.
 */
final class ConstraintSentinels
{
    /** @var array<string,array<string,mixed>> */
    private array $table;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/constraint-sentinels.yaml';
        $parsed = Yaml::parseFile($path);
        $this->table = is_array($parsed) ? $parsed : [];
    }

    /** @return array<string,mixed> prop => sentinel value for every constraint prop this ACF type carries */
    public function forType(string $acfType): array
    {
        return $this->table[$acfType] ?? [];
    }
}
