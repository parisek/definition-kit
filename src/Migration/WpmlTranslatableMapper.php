<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Defines the single, shared bijection between ACF's `wpml_cf_preferences`
 * (0-3) and the migrated field's `translatable` flag — consumed by both
 * AcfJsonReader (forward: lift into `translatable`) and
 * MigrationCompletenessAuditor (verify: raw value reconstructs from
 * `translatable` + field type). Keeping the rule in one place guarantees
 * reader and auditor can never drift apart.
 *
 * The canonical shape per field kind:
 *   - leaf field:      `2` <-> translatable:true ; `1` <-> translatable absent/false
 *   - container (group/repeater): `3`, implied by type — no `translatable` emitted
 *   - accordion:        `0`, dropped (accordions never reach this mapper — the
 *                        pseudo-field is filtered out before readField()/audit())
 *
 * A value outside this shape for the field's kind (e.g. a leaf carrying `3`
 * or `0`, or a container carrying `2`) is ANOMALOUS — it must NOT be
 * consumed/lifted; it stays verbatim under `wp.wpml_cf_preferences` so it
 * round-trips losslessly instead of being silently coerced or dropped.
 */
final class WpmlTranslatableMapper
{
    private const CONTAINER_TYPES = ['group', 'repeater'];

    public function isCanonical(string $acfType, int $wpml): bool
    {
        if (in_array($acfType, self::CONTAINER_TYPES, true)) {
            return 3 === $wpml;
        }
        return in_array($wpml, [1, 2], true);
    }

    /** Only meaningful when {@see isCanonical()} is true for the same args. */
    public function translatable(string $acfType, int $wpml): bool
    {
        return 2 === $wpml && !in_array($acfType, self::CONTAINER_TYPES, true);
    }

    /**
     * Inverse of translatable(): the canonical wpml_cf_preferences value for
     * this field's kind + translatable flag. Containers always reverse to 3
     * regardless of $translatable.
     */
    public function toWpmlPreference(string $acfType, bool $translatable): int
    {
        if (in_array($acfType, self::CONTAINER_TYPES, true)) {
            return 3;
        }
        return $translatable ? 2 : 1;
    }
}
