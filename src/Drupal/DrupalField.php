<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

/**
 * One field instance on one paragraph bundle, as the Drupal config export
 * describes it: `field.field.paragraph.<bundle>.<name>.yml` merged over its
 * `field.storage.paragraph.<name>.yml`.
 *
 * Only what the drift-lint compares is kept: field set, storage type,
 * cardinality, required flag and reference targets. The rest (uuid, _core,
 * dependencies, widget settings, weights) is config noise for this purpose.
 */
final class DrupalField
{
    public const UNLIMITED = -1;

    /**
     * @param array<string,mixed> $settings storage settings with the instance settings merged over them
     * @param bool|null $onForm true when the default form display shows the field, false when it hides it,
     *                          null when the bundle has no exported form display
     */
    public function __construct(
        public readonly string $bundle,
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $required,
        public readonly int $cardinality,
        public readonly array $settings,
        public readonly ?bool $onForm = null,
        public readonly ?int $formWeight = null,
        public readonly ?string $widget = null,
    ) {
    }

    public function isMultiple(): bool
    {
        return 1 !== $this->cardinality;
    }

    public function targetType(): ?string
    {
        $type = $this->settings['target_type'] ?? null;

        return is_string($type) && '' !== $type ? $type : null;
    }

    /**
     * The bundles an entity reference may point at. An empty list means "any
     * bundle" (Drupal stores `target_bundles: null` for that).
     *
     * @return list<string>
     */
    public function targetBundles(): array
    {
        $handler = $this->settings['handler_settings'] ?? null;
        if (!is_array($handler) || !is_array($handler['target_bundles'] ?? null)) {
            return [];
        }
        $bundles = array_map('strval', array_keys($handler['target_bundles']));
        // `negate: 1` (entity_reference_revisions) inverts the list: every
        // bundle EXCEPT these. The lint cannot compare an exclusion list with
        // an expected bundle, so it is treated as "any".
        if (!empty($handler['negate'])) {
            return [];
        }
        sort($bundles);

        return $bundles;
    }

    /**
     * Allowed values of a list_* field, value => label. Drupal 10 exports a
     * list of `{value, label}` rows; older exports a value => label map. PHP
     * turns a numeric value into an int key (list_integer: 0, 1, 2).
     *
     * @return array<int|string,string>
     */
    public function allowedValues(): array
    {
        $raw = $this->settings['allowed_values'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $key => $row) {
            if (is_array($row) && array_key_exists('value', $row)) {
                $values[(string) $row['value']] = (string) ($row['label'] ?? $row['value']);
            } elseif (is_scalar($row)) {
                $values[(string) $key] = (string) $row;
            }
        }

        return $values;
    }
}
