<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Generator\RootFieldGroupBuilder;
use Parisek\DefinitionKit\Support\StructuralDiff;

/**
 * Captures the props a real ACF `message` field carries that the generator
 * cannot rebuild from its identity ({key, label, name, message}) — the
 * message analogue of AccordionResidualCapturer, following the exact same
 * self-diff shape (see that class's own docblock for the general pattern).
 *
 * Build the baseline pseudo-field the generator would produce
 * (Generator\RootFieldGroupBuilder::messageBaseline), then compare each prop
 * of the real message field against it. Any prop that deviates — a non-default
 * `new_lines`, an enabled `esc_html`, a non-zero `wpml_cf_preferences`, a set
 * `wrapper`, `allow_in_bindings`, … — is genuinely non-derivable and captured
 * verbatim, keyed by its real ACF prop name.
 *
 * Only the data-loss direction is captured — a prop the real field HAS whose
 * value the baseline didn't reproduce; guarded by iterating the real field's
 * own keys, so a prop the export omits is never invented. The identity
 * quadruple {key, label, name, message} is skipped (the baseline is built
 * from it, so it always matches) and is stored separately by the caller.
 */
final class MessageResidualCapturer
{
    private const IDENTITY = ['key', 'label', 'name', 'message'];

    public function __construct(private readonly RootFieldGroupBuilder $rootBuilder = new RootFieldGroupBuilder())
    {
    }

    /**
     * @param array<string,mixed> $realMessageField
     * @return array<string,mixed> the non-derivable residual, keyed by real ACF
     *                             prop name — empty when the field is fully
     *                             derivable from {key, label, name, message}
     */
    public function capture(array $realMessageField): array
    {
        $baseline = $this->rootBuilder->messageBaseline(
            (string) ($realMessageField['key'] ?? ''),
            (string) ($realMessageField['label'] ?? ''),
            (string) ($realMessageField['name'] ?? ''),
            (string) ($realMessageField['message'] ?? ''),
        );

        $residual = [];
        foreach ($realMessageField as $prop => $value) {
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
