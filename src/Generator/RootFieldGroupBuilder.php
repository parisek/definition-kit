<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

/**
 * Builds the root ACF field-group object (the props sitting alongside
 * `fields` in acf.json — key/title/location/menu_order/position/etc.)
 * and interleaves accordion pseudo-fields (captured verbatim by
 * Migration\AcfJsonReader into root `wp.accordions` — see Task 2) back
 * into the assembled top-level `fields` list. `modified` is the one
 * genuinely per-generation value; it is always injected, never read
 * from a clock inside this class, so callers stay deterministic and
 * testable.
 */
final class RootFieldGroupBuilder
{
    /**
     * Corpus-census majority values (see this plan's Task 6 docblock for
     * the exact counts) for props Migration\AcfJsonReader never captures
     * because they're constant or near-constant across all 49 mairateam
     * components. `hide_on_screen`/`show_in_rest` have the same
     * ACF-version-era drift documented for image dimension sentinels —
     * the minority convention is a deliberately-tolerated round-trip
     * residual, not a bug.
     */
    private const ROOT_DEFAULTS = [
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
        'acfml_field_group_mode' => 'advanced',
    ];

    /**
     * @param array<string,mixed> $definitionTree
     * @param list<array<string,mixed>> $orderedRawFields
     * @return array<string,mixed>
     */
    public function build(array $definitionTree, array $orderedRawFields, string $componentSlug, int $modifiedAt): array
    {
        $rootWp = (array) ($definitionTree['wp'] ?? []);
        /** @var list<array<string,mixed>> $accordions */
        $accordions = (array) ($rootWp['accordions'] ?? []);
        // `accordions` is replayed into `fields` below; `block` is block.json-only
        // config (Generator\BlockJsonGenerator consumes it). Neither is an acf.json
        // field-group prop, so both are stripped before the rest of the root `wp:`
        // bag (e.g. the group's own `description`) merges into the group object.
        unset($rootWp['accordions'], $rootWp['block']);

        $fieldNames = array_keys((array) ($definitionTree['fields'] ?? []));
        $fields = $this->interleaveAccordions($fieldNames, $orderedRawFields, $accordions);

        return array_merge(
            self::ROOT_DEFAULTS,
            [
                'key' => (string) ($definitionTree['key'] ?? ('group_' . $componentSlug)),
                'title' => (string) ($definitionTree['name'] ?? ''),
                'fields' => $fields,
                'location' => [[['param' => 'block', 'operator' => '==', 'value' => "acf/{$componentSlug}"]]],
            ],
            $rootWp,
            ['modified' => $modifiedAt],
        );
    }

    /**
     * @param list<string> $fieldNames
     * @param list<array<string,mixed>> $orderedRawFields
     * @param list<array<string,mixed>> $accordions
     * @return list<array<string,mixed>>
     */
    private function interleaveAccordions(array $fieldNames, array $orderedRawFields, array $accordions): array
    {
        if ([] === $accordions) {
            return $orderedRawFields;
        }

        $byBefore = [];
        $trailing = [];
        foreach ($accordions as $accordion) {
            $pseudo = $this->buildAccordionPseudoField($accordion);
            $before = $accordion['before'] ?? null;
            if (null === $before) {
                $trailing[] = $pseudo;
            } else {
                $byBefore[(string) $before][] = $pseudo;
            }
        }

        $result = [];
        foreach ($fieldNames as $i => $name) {
            foreach ($byBefore[$name] ?? [] as $pseudo) {
                $result[] = $pseudo;
            }
            $result[] = $orderedRawFields[$i];
        }
        foreach ($trailing as $pseudo) {
            $result[] = $pseudo;
        }

        return $result;
    }

    /**
     * The fixed accordion pseudo-field the generator rebuilds from an
     * accordion's identity triple. Public so Migration\AccordionResidualCapturer
     * can self-diff a real accordion against it — the accordion analogue of the
     * block.json BlockResidualCapturer and the acf.json type-defaults baseline.
     *
     * @return array<string,mixed>
     */
    public function accordionBaseline(string $key, string $label, int $open): array
    {
        return [
            'key' => $key,
            'allow_in_bindings' => 0,
            'label' => $label,
            'name' => '',
            'aria-label' => '',
            'type' => 'accordion',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
            'wpml_cf_preferences' => 0,
            'open' => $open,
            'multi_expand' => 0,
            'endpoint' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $accordion
     * @return array<string,mixed>
     */
    private function buildAccordionPseudoField(array $accordion): array
    {
        $pseudo = $this->accordionBaseline(
            (string) $accordion['key'],
            (string) $accordion['label'],
            (int) $accordion['open'],
        );
        // Overlay the captured non-derivable residual verbatim — instructions,
        // non-zero wpml_cf_preferences, multi_expand, … : any real ACF prop the
        // migration self-diff found deviating from the baseline. Meta keys are
        // consumed above (key/label/open) or used only for positioning
        // (before), never overlaid. Reassigning an existing key keeps its
        // position, so key order is unchanged.
        foreach ($accordion as $prop => $value) {
            if (!in_array($prop, ['key', 'label', 'open', 'before'], true)) {
                $pseudo[$prop] = $value;
            }
        }
        return $pseudo;
    }
}
