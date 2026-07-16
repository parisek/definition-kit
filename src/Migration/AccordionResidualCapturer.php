<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Generator\RootFieldGroupBuilder;
use Parisek\DefinitionKit\Support\StructuralDiff;

/**
 * Captures the props a real accordion pseudo-field carries that the generator
 * cannot rebuild from the accordion's identity triple ({key, label, open}) —
 * the accordion analogue of the block.json BlockResidualCapturer and the
 * acf.json type-defaults baseline.
 *
 * Self-diff: build the baseline pseudo-field the generator would produce
 * (Generator\RootFieldGroupBuilder::accordionBaseline), then compare each prop
 * of the real accordion against it. Any prop that deviates — accordion section
 * `instructions`, a non-zero `wpml_cf_preferences`, `multi_expand`, `required`,
 * a set `wrapper`, … — is genuinely non-derivable and captured verbatim, keyed
 * by its real ACF prop name. This generalises the former wpml-only special case
 * so no per-prop special case ever accumulates again.
 *
 * Only the data-loss direction is captured — a prop the real accordion HAS
 * whose value the baseline didn't reproduce; guarded by iterating the real
 * field's keys, so a prop the export omits is never invented. The identity
 * triple {key, label, open} is skipped (the baseline is built from it, so it
 * always matches) and is stored separately by the caller.
 */
final class AccordionResidualCapturer
{
    private const IDENTITY = ['key', 'label', 'open'];

    public function __construct(private readonly RootFieldGroupBuilder $rootBuilder = new RootFieldGroupBuilder())
    {
    }

    /**
     * @param array<string,mixed> $realAccordionField
     * @return array<string,mixed> the non-derivable residual, keyed by real ACF
     *                             prop name — empty when the accordion is fully
     *                             derivable from {key, label, open}
     */
    public function capture(array $realAccordionField): array
    {
        $baseline = $this->rootBuilder->accordionBaseline(
            (string) ($realAccordionField['key'] ?? ''),
            (string) ($realAccordionField['label'] ?? ''),
            (int) ($realAccordionField['open'] ?? 0),
        );

        $residual = [];
        foreach ($realAccordionField as $prop => $value) {
            if (in_array($prop, self::IDENTITY, true)) {
                continue;
            }
            if ([] !== StructuralDiff::diff($baseline[$prop] ?? null, $value)) {
                $residual[$prop] = $value;
            }
        }

        return $residual;
    }
}
