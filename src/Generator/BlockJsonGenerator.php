<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator;

/**
 * Generates block.json from a definition tree's root metadata. A census
 * over all 49 mairateam block.json files proves almost every prop is
 * fixed boilerplate (apiVersion/category/description/icon/acf are
 * byte-identical across the whole corpus); the one genuine axis of
 * variation (`supports.align` + `attributes.align`) derives cleanly from
 * the `render:` root metadata dávka 2's TwigMetadataReader already
 * captures. `example.attributes.data` is explicitly out of scope — owned
 * by the sync-gutenberg-block-examples skill — so an existing block.json's
 * `example` is preserved verbatim; only a first-time generation emits the
 * empty placeholder.
 */
final class BlockJsonGenerator
{
    private const ACF_BLOCK = [
        'mode' => 'preview',
        'renderCallback' => 'Parisek\\TimberKit\\BlockRenderer::render',
        'postTypes' => [],
    ];

    public function __construct(private readonly ?string $iconPath = null)
    {
    }

    /**
     * @param array<string,mixed> $definitionTree
     * @param array<string,mixed>|null $existingBlockJson
     * @return array<string,mixed>
     */
    public function generate(array $definitionTree, string $componentSlug, ?array $existingBlockJson = null): array
    {
        $isBleed = 'bleed' === ($definitionTree['render'] ?? '');

        $supports = ['mode' => false, 'spacing' => false, 'anchor' => true, 'className' => true];
        $attributes = null;
        $example = ['viewportWidth' => 1280, 'attributes' => ['mode' => 'preview', 'data' => []]];

        if ($isBleed) {
            $supports = ['align' => ['full'], ...$supports];
            $attributes = ['align' => ['type' => 'string', 'default' => 'full']];
            $example['attributes'] = ['align' => 'full', ...$example['attributes']];
        }

        if (null !== $existingBlockJson && isset($existingBlockJson['example'])) {
            $example = $existingBlockJson['example'];
        }

        $block = [
            'apiVersion' => 3,
            'name' => "acf/{$componentSlug}",
            'title' => (string) ($definitionTree['name'] ?? ''),
            'description' => null,
            'category' => 'theme',
            'icon' => $this->loadIcon(),
            'keywords' => null,
            'supports' => $supports,
            'attributes' => $attributes,
            'example' => $example,
            'acf' => self::ACF_BLOCK,
        ];

        // Overlay the non-derivable block config captured at migration time
        // (Migration\BlockResidualCapturer → root `wp.block`). Each captured
        // section replaces the derived one verbatim; reassigning an existing
        // key preserves its position, so key order is unchanged. `wp.block`
        // absent → byte-identical to the pure derivation above (no-regression
        // for the reference set). Only these three sections are ever captured;
        // `array_key_exists` honours a captured `null`.
        $wpBlock = (array) (($definitionTree['wp'] ?? [])['block'] ?? []);
        foreach (['acf', 'supports', 'attributes'] as $section) {
            if (array_key_exists($section, $wpBlock)) {
                $block[$section] = $wpBlock[$section];
            }
        }

        // acf-json-schema's block schema requires `attributes` to be an
        // object when present, and real ACF exports simply omit the key on
        // non-bleed blocks — so a null (derived or captured) never reaches
        // the output.
        if (null === $block['attributes']) {
            unset($block['attributes']);
        }

        return $block;
    }

    private function loadIcon(): string
    {
        $path = $this->iconPath ?? __DIR__ . '/../../schemas/block-icon.svg';
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException("Cannot read shared block icon: {$path}");
        }
        return $contents;
    }
}
