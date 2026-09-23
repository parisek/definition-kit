<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

/**
 * One paragraph type as a definition describes it.
 *
 * `ownsText`: the instance labels and descriptions come from this
 * definition (the bundle it names, and its nested bundles). An alias bundle
 * gets the structure only (ADR 0002).
 * `topLevel`: an editor adds this bundle to a page, so a new one joins the
 * `drupal.host_fields`. A nested bundle does not.
 */
final class BundleSpec
{
    /**
     * @param list<FieldSpec> $fields in definition order
     * @param array<string,array{label: string, parent: string, children: list<string>}> $groups field_group groups for a new form display
     */
    public function __construct(
        public readonly string $bundle,
        public readonly string $component,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $ownsText,
        public readonly bool $topLevel,
        public readonly array $fields,
        public readonly array $groups,
    ) {
    }
}
