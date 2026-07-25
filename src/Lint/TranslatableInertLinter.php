<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/**
 * Warns when `translatable:` is declared on a container field type
 * (`group`, `repeater`, `flexible_content`) — the property is silently
 * inert there. Generator\FieldReconstructor::CONTAINER_ACF_TYPES always
 * reconstructs these types' `wpml_cf_preferences` to the container value
 * `3` regardless of `translatable`, because ACFML forces copy-once for
 * them at runtime (`field_should_be_set_to_copy_once()` in
 * acfml/classes/class-wpml-acf-field-settings.php) — honouring an
 * author's `translatable:` here would produce a JSON that lies about what
 * WPML actually does.
 *
 * A silently-ignored authored property is worse than a rejected one: the
 * author reasonably believes the flag does something. This linter
 * surfaces the inertness instead of dropping it without comment (issue
 * #11's secondary concern).
 *
 * Deliberately a WARNING, not an error — `translatable: false` on a
 * container is a no-op that happens to already match the enforced
 * default, and even `translatable: true` doesn't produce a wrong
 * `acf.json` (the container branch overrides it regardless); it just
 * doesn't do what the author probably expected. Nothing here should block
 * `fields-generate`/`fields-validate` from succeeding.
 */
final class TranslatableInertLinter
{
    private const CONTAINER_TYPES = ['group', 'repeater', 'flexible_content'];

    /**
     * @param array<string,mixed> $definition
     * @return list<array{severity: string, message: string}>
     */
    public function lint(string $definitionPath, array $definition): array
    {
        $fields = (array) ($definition['fields'] ?? []);
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

            if (in_array($type, self::CONTAINER_TYPES, true) && array_key_exists('translatable', $field)) {
                $findings[] = [
                    'severity' => 'warning',
                    'message' => sprintf(
                        "%s: field '%s' (type: %s) declares `translatable:` but it has no effect — "
                        . 'ACFML always forces `wpml_cf_preferences` to copy-once (3) for '
                        . 'group/repeater/flexible_content, overriding whatever `translatable:` says.',
                        basename($definitionPath),
                        implode('.', $fieldChain),
                        $type,
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
}
