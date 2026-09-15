<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

use Parisek\DefinitionKit\Support\KeyStyle;

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

    public function __construct(
        private readonly KeyStyle $keyStyle = KeyStyle::Slug,
    ) {
    }

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
        /** @var list<array<string,mixed>> $messages */
        $messages = (array) ($rootWp['messages'] ?? []);
        // `accordions`/`messages` are replayed into `fields` below; `block` is
        // block.json-only config (Generator\BlockJsonGenerator consumes it).
        // None of these three is an acf.json field-group prop, so all are
        // stripped before the rest of the root `wp:` bag (e.g. the group's
        // own `description`) merges into the group object.
        unset($rootWp['accordions'], $rootWp['messages'], $rootWp['block']);

        $fieldNames = array_keys((array) ($definitionTree['fields'] ?? []));
        $fields = $this->interleavePseudoFields($fieldNames, $orderedRawFields, $accordions, $messages);

        return array_merge(
            self::ROOT_DEFAULTS,
            [
                'key' => (string) ($definitionTree['key'] ?? ('group_' . $this->keyStyle->keySlug($componentSlug))),
                'title' => (string) ($definitionTree['name'] ?? ''),
                'fields' => $fields,
                // NOT key-styled, deliberately. This names the Gutenberg block
                // WordPress actually registers (`acf/<slug>`, from block.json),
                // so it is the block's identity rather than a spelling
                // convention. Folding hyphens here would point the group at a
                // block that does not exist and the fields would stop appearing.
                'location' => [[['param' => 'block', 'operator' => '==', 'value' => "acf/{$componentSlug}"]]],
            ],
            $rootWp,
            ['modified' => $modifiedAt],
        );
    }

    /**
     * Interleaves BOTH pseudo-field kinds (accordion + message) back into the
     * real field list, anchored on the real field name each one precedes
     * (`before`). Each kind is built into its own pseudo-field array first,
     * then grouped by anchor in a single combined pass.
     *
     * `seq` — Migration\AcfJsonReader::flushPendingPseudo()'s own per-anchor
     * position, present ONLY on an anchor that mixes both kinds — is what
     * lets a mixed anchor come back out in its true authored order (real
     * corpus shape: umbili's image-promo opens with a message immediately
     * followed by an accordion, both anchored on the first real field).
     * Bucketing accordions and messages separately would otherwise always
     * emit one kind before the other, regardless of which one was actually
     * authored first. An anchor with NO `seq` (the overwhelming fleet
     * majority — accordions with no messages, or vice versa) keeps the
     * fixed "messages ahead of accordions" fallback order below; this is
     * what keeps `wp.accordions` byte-unchanged for every definition that
     * has no messages at all.
     *
     * @param list<string> $fieldNames
     * @param list<array<string,mixed>> $orderedRawFields
     * @param list<array<string,mixed>> $accordions
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function interleavePseudoFields(
        array $fieldNames,
        array $orderedRawFields,
        array $accordions,
        array $messages,
    ): array {
        if ([] === $accordions && [] === $messages) {
            return $orderedRawFields;
        }

        $byBefore = [];
        $trailing = [];
        // Messages queued ahead of accordions — the fixed fallback order for
        // an anchor with no `seq` (see docblock above).
        foreach ($messages as $message) {
            $this->bucketPseudo(
                $this->buildMessagePseudoField($message),
                $message['seq'] ?? null,
                $message['before'] ?? null,
                $byBefore,
                $trailing,
            );
        }
        foreach ($accordions as $accordion) {
            $this->bucketPseudo(
                $this->buildAccordionPseudoField($accordion),
                $accordion['seq'] ?? null,
                $accordion['before'] ?? null,
                $byBefore,
                $trailing,
            );
        }
        $sortMixedBucket = static function (array &$bucket): void {
            // A bucket where NO entry carries `seq` is left exactly as
            // queued above (messages-then-accordions). A bucket where every
            // entry carries one (flushPendingPseudo() sets it on ALL
            // entries at a mixed anchor, never just some) is re-sorted by
            // it, reproducing the true authored order.
            if ([] === $bucket || null === $bucket[0]['seq']) {
                return;
            }
            usort($bucket, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        };
        foreach ($byBefore as &$bucket) {
            $sortMixedBucket($bucket);
        }
        unset($bucket);
        $sortMixedBucket($trailing);

        $result = [];
        foreach ($fieldNames as $i => $name) {
            foreach ($byBefore[$name] ?? [] as $entry) {
                $result[] = $entry['pseudo'];
            }
            $result[] = $orderedRawFields[$i];
        }
        foreach ($trailing as $entry) {
            $result[] = $entry['pseudo'];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $pseudo
     * @param array<string,list<array{seq: int|null, pseudo: array<string,mixed>}>> $byBefore
     * @param list<array{seq: int|null, pseudo: array<string,mixed>}> $trailing
     */
    private function bucketPseudo(array $pseudo, ?int $seq, mixed $before, array &$byBefore, array &$trailing): void
    {
        $entry = ['seq' => $seq, 'pseudo' => $pseudo];
        if (null === $before) {
            $trailing[] = $entry;
        } else {
            $byBefore[(string) $before][] = $entry;
        }
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
     * Round 7 — the residual overlay used to exclude ONLY `key`/`label`/
     * `open`/`before` (the props this method itself consumes for
     * positioning/baseline construction). An accordion element sourced
     * from `wp.accordions` — hand-authored or produced by a future
     * migration bug — could therefore carry `type`/`name`/`fields`/
     * `sub_fields`/`layouts`/`parent_repeater` and have them overlaid
     * verbatim onto the pseudo-field, impersonating an arbitrary field
     * shape (e.g. `type: text, name: bogus_child` smuggling a real field
     * in through the accordion channel) despite `accordionBaseline()`
     * fixing `type: 'accordion'` / `name: ''` immediately above. Mirrors
     * {@see FieldsGenerator::RESERVED_WP_PROPS} — same reserved set, same
     * "identity/structure has exactly one source" rule, applied to this
     * accordion-residual code path instead of an ordinary field's `wp:`.
     */
    private const ACCORDION_RESIDUAL_EXCLUDED_PROPS = [
        'key', 'label', 'open', 'before', 'seq',
        'type', 'name', 'fields', 'sub_fields', 'layouts', 'parent_repeater',
    ];

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
        // migration self-diff found deviating from the baseline. Meta/reserved
        // keys are consumed above (key/label/open), used only for positioning
        // (before), or structurally fixed by accordionBaseline() and must have
        // exactly one source (type/name/fields/sub_fields/layouts/
        // parent_repeater — see ACCORDION_RESIDUAL_EXCLUDED_PROPS) — none of
        // those are ever overlaid. Reassigning an existing key keeps its
        // position, so key order is unchanged.
        foreach ($accordion as $prop => $value) {
            if (!in_array($prop, self::ACCORDION_RESIDUAL_EXCLUDED_PROPS, true)) {
                $pseudo[$prop] = $value;
            }
        }
        return $pseudo;
    }

    /**
     * The fixed `message` pseudo-field the generator rebuilds from a
     * message's identity — ACF's own defaults for the type (a message holds
     * no value, so there is no abstract `type: text`-shaped home for it;
     * see Migration\AcfJsonReader's own message docblock for why it is
     * captured instead of mapped, mirroring accordion). Public so
     * Migration\MessageResidualCapturer can self-diff a real message field
     * against it — the message analogue of `accordionBaseline()`.
     *
     * @return array<string,mixed>
     */
    public function messageBaseline(string $key, string $label, string $name, string $message): array
    {
        return [
            'key' => $key,
            'allow_in_bindings' => 0,
            'label' => $label,
            'name' => $name,
            'aria-label' => '',
            'type' => 'message',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
            'message' => $message,
            'new_lines' => 'wpautop',
            'esc_html' => 0,
        ];
    }

    /**
     * Same exclusion shape as ACCORDION_RESIDUAL_EXCLUDED_PROPS — the props
     * this method itself consumes for identity ({key, label, name, message})
     * or positioning (`before`), plus the structural/type-identity props a
     * message-shaped `wp:` overlay must never be allowed to smuggle in.
     */
    private const MESSAGE_RESIDUAL_EXCLUDED_PROPS = [
        'key', 'label', 'name', 'message', 'before', 'seq',
        'type', 'fields', 'sub_fields', 'layouts', 'parent_repeater',
    ];

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private function buildMessagePseudoField(array $message): array
    {
        $pseudo = $this->messageBaseline(
            (string) $message['key'],
            (string) $message['label'],
            (string) $message['name'],
            (string) $message['message'],
        );
        // Overlay the captured non-derivable residual verbatim, exactly
        // mirroring buildAccordionPseudoField() above.
        foreach ($message as $prop => $value) {
            if (!in_array($prop, self::MESSAGE_RESIDUAL_EXCLUDED_PROPS, true)) {
                $pseudo[$prop] = $value;
            }
        }
        return $pseudo;
    }
}
