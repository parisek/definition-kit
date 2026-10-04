<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Migration\WpmlTranslatableMapper;
use Parisek\DefinitionKit\Support\StructuralType;

/**
 * Warns when a LEAF field sets `wp.wpml_cf_preferences` by hand.
 *
 * Authors write `translatable: true` for "Translate". The generator maps it
 * to `wpml_cf_preferences: 2`. A leaf that carries `wp.wpml_cf_preferences`
 * overrides that mapping, and the override is not checked. A hand-written 3
 * is the common mistake: 3 is "Copy once" in WPML, not "Translate". The field
 * then copies its value to every language and never asks for a translation.
 * Nothing else reports it.
 *
 * Two messages:
 *  - 3 or 0: non-canonical for a leaf (WpmlTranslatableMapper::isCanonical()).
 *    The migration keeps such a value in `wp:` on purpose, so a real
 *    `acf.json` round-trips. A hand-written one is almost always the mistake.
 *  - 1 or 2: canonical, so the migration never writes it to `wp:`. A hand-
 *    written one is redundant. It is harmless when it agrees with
 *    `translatable`, so the message is softer, but it still names the
 *    abstract form. If it disagrees with `translatable`, `wp:` wins silently,
 *    which is another reason to prefer one source.
 *
 * Containers are skipped: their value is always 3 and the type implies it.
 * Both severities are WARNING. The generated JSON is valid, so nothing
 * should block `fields-generate`.
 */
final class LeafWpmlPreferenceLinter
{
    private const CONTAINER_TYPES = [StructuralType::OBJECT, StructuralType::LIST, 'flexible_content'];

    private readonly WpmlTranslatableMapper $mapper;

    public function __construct(?WpmlTranslatableMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new WpmlTranslatableMapper();
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $fields = (array) (StructuralType::normalize($definition)['fields'] ?? []);
        $findings = [];
        $this->walkFields($definitionPath, $fields, [], $findings);
        return $findings;
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $chain
     * @param list<array{severity: string, message: string}> $findings
     */
    private function walkFields(string $definitionPath, array $fields, array $chain, array &$findings): void
    {
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldChain = [...$chain, (string) $name];
            $type = (string) ($field['type'] ?? '');
            $wp = $field['wp'] ?? null;

            if (
                !in_array($type, self::CONTAINER_TYPES, true)
                && is_array($wp)
                && array_key_exists('wpml_cf_preferences', $wp)
            ) {
                $findings[] = [
                    'severity' => 'warning',
                    'message' => $this->message(
                        basename($definitionPath),
                        implode('.', $fieldChain),
                        $type,
                        $this->asPreference($wp['wpml_cf_preferences']),
                    ),
                ];
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->walkFields($definitionPath, $field['fields'], $fieldChain, $findings);
            }

            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layoutName => $layout) {
                    if (!is_array($layout) || !isset($layout['fields']) || !is_array($layout['fields'])) {
                        continue;
                    }
                    $this->walkFields(
                        $definitionPath,
                        $layout['fields'],
                        [...$fieldChain, (string) $layoutName],
                        $findings,
                    );
                }
            }
        }
    }

    /** An integer 0-3, or a string spelling one, is a preference. Anything else is null: no coercion. */
    private function asPreference(mixed $raw): ?int
    {
        if (is_int($raw) && $raw >= 0 && $raw <= 3) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[0-3]$/', $raw) === 1) {
            return (int) $raw;
        }
        return null;
    }

    private function message(string $file, string $path, string $type, ?int $value): string
    {
        if ($value === null) {
            return sprintf(
                "%s: field '%s' (type: %s): `wp.wpml_cf_preferences` is not a valid WPML preference. "
                . 'Use an integer from 0 to 3, or better `translatable: true` for Translate (2).',
                $file,
                $path,
                $type,
            );
        }

        if (!$this->mapper->isCanonical($type, $value)) {
            return sprintf(
                "%s: field '%s' (type: %s): `wp.wpml_cf_preferences: %d` on a leaf is non-canonical: "
                . '3 is Copy once and 0 is Ignore; Translate is 2. '
                . 'Use `translatable: true` for Translate, and omit it for Copy.',
                $file,
                $path,
                $type,
                $value,
            );
        }

        return sprintf(
            "%s: field '%s' (type: %s): `wp.wpml_cf_preferences: %d` is redundant. "
            . 'Use `translatable: true` for Translate (2), and omit it for Copy (1).',
            $file,
            $path,
            $type,
            $value,
        );
    }
}
