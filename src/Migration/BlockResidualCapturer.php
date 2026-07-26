<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Support\StructuralDiff;

/**
 * Captures the block.json props a component's real `block.json` carries that
 * the `BlockJsonGenerator` cannot derive from the definition tree — the block
 * analogue of the acf.json type-defaults baseline (migrate drops what generate
 * re-adds; capture what generate can't reproduce).
 *
 * Self-diff: run the generator on the very tree being migrated, then compare —
 * per top-level config section — against the real block.json. The non-derivable
 * surface is exactly three sections: `acf` (holds `postTypes`), `supports`,
 * `attributes`. `name`/`title`/`description`/`icon`/`example`/`apiVersion`
 * stay fully derived and are never captured.
 *
 * Direction matters. Only the DATA-LOSS direction is captured — a section the
 * real block.json HAS whose value the generator did not reproduce. The opposite
 * direction (generator emits a section the real block.json omits, e.g.
 * `attributes: null` on a project whose exports drop the key) is a baseline-add,
 * NOT data loss; it is deliberately left alone and resolves when a project
 * commits the regenerated canonical block.json at adoption time. That is why the
 * capture is guarded by `array_key_exists` on the REAL block.json: a section the
 * real file doesn't carry is never captured.
 *
 * Section granularity (not per-leaf) is deliberate: a differing section is
 * stored whole and replayed whole, so there is no need for a per-leaf removal
 * sentinel (which `null` — itself a legitimate value — could not express
 * unambiguously).
 */
final class BlockResidualCapturer
{
    private const CONFIG_SECTIONS = ['acf', 'supports', 'attributes'];

    public function __construct(private readonly BlockJsonGenerator $generator = new BlockJsonGenerator())
    {
    }

    /**
     * @param array<string,mixed> $definitionTree
     * @param array<string,mixed> $realBlockJson
     * @return array<string,mixed> the `wp.block` section delta — empty when the
     *                             whole block.json is derivable
     */
    public function capture(array $definitionTree, string $componentSlug, array $realBlockJson): array
    {
        $derived = $this->generator->generate($definitionTree, $componentSlug, $realBlockJson);

        $wpBlock = [];
        foreach (self::CONFIG_SECTIONS as $section) {
            if (!array_key_exists($section, $realBlockJson)) {
                continue;
            }
            if ([] !== StructuralDiff::diff($derived[$section] ?? null, $realBlockJson[$section])) {
                $wpBlock[$section] = $realBlockJson[$section];
            }
        }

        // `example` needs a different comparison from the three sections
        // above. BlockJsonGenerator PRESERVES an existing block.json's
        // example verbatim (issue #13), and it is handed the real file on
        // line 52 -- so $derived['example'] always equals the real one and a
        // diff against it can never fire. The question that matters is what
        // a FROM-SCRATCH generation would produce, which is what happens
        // when a project regenerates before block.json exists.
        //
        // That case is real: `form-configurator` needs `example: null` (its
        // Vue app has no styleguide-shaped preview, so Gutenberg should show
        // the icon rather than a broken placeholder), and a from-scratch
        // generation derives a non-null example instead. Without capturing
        // it, adopting that component silently produces the wrong preview
        // the first time the file is not already on disk.
        // Narrow on purpose: ONLY a null example is captured. A non-null one
        // is sample preview data owned by the sync-gutenberg-block-examples
        // skill and living in block.json; capturing it here would duplicate
        // that ownership and change the migration output of all 40 existing
        // components (measured — it captures on every one of them).
        //
        // `null` is different in kind. It is not data the generator failed to
        // reproduce, it is a decision -- "this block has no meaningful
        // preview" -- and nothing else on disk records it. A from-scratch
        // generation derives a non-null example instead, so without this the
        // decision is lost the first time block.json is not already there.
        if (array_key_exists('example', $realBlockJson) && null === $realBlockJson['example']) {
            $wpBlock['example'] = null;
        }

        return $wpBlock;
    }
}
