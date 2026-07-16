<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Mcp;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the type-default AI-guidance library (schemas/mcp-defaults.yaml).
 * NOT consumed by Migration\AcfJsonReader — mcp is authored-only per ADR
 * 0006, this library exists for later MCP tooling / dávka 3's generator to
 * compose with a field's own authored `mcp:` (additive).
 */
final class McpDefaultsLibrary
{
    /** @var array<string,mixed> */
    private array $library;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../schemas/mcp-defaults.yaml';
        $parsed = Yaml::parseFile($path);
        $this->library = is_array($parsed) ? $parsed : [];
    }

    /**
     * @return list<string> the type's (or type+kind's) default hints, or [] when none apply
     */
    public function hintsFor(string $abstractType, ?string $kind = null): array
    {
        $byType = $this->library[$abstractType] ?? null;
        if (!is_array($byType)) {
            return [];
        }
        if (array_is_list($byType)) {
            return null === $kind ? $byType : [];
        }
        if (null !== $kind && isset($byType[$kind]) && is_array($byType[$kind])) {
            return array_values($byType[$kind]);
        }
        return [];
    }
}
