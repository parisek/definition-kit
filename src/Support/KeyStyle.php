<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * How a component slug is spelled inside a derived ACF key.
 *
 * A component directory named `article-list` can legitimately produce either
 * `field_article-list_title` or `field_article_list_title`. Both work — an ACF
 * key is an opaque identifier, and templates read fields by `name`, never by
 * key — so this is a project's spelling convention, not a correctness question.
 *
 * It needs to be a setting rather than a constant because projects already
 * disagree, and neither side can be migrated cheaply: renaming a key orphans
 * stored content (block attributes bind `_<field>` to the key string), so a
 * project's existing spelling is frozen wherever content exists. Before this
 * existed, a snake_case project had exactly one way to express its convention —
 * pin `key:` on every field of every multi-word component, forever. One
 * downstream measured 35 of 42 such components carrying pins that encoded no
 * design intent at all; they existed solely to override the derivation below.
 *
 * SLUG IS AND STAYS THE DEFAULT. Components whose committed keys already match
 * the derivation carry no `key:` precisely because they match it, so changing
 * the default would spuriously pin every one of them on the next migrate.
 *
 * Scope is deliberately narrow: this governs KEYS only. The Gutenberg block
 * name (`acf/<slug>`) and the field group's `location` param are the block's
 * real identity in WordPress, not a spelling choice, and stay verbatim under
 * every style.
 */
enum KeyStyle: string
{
    case Slug = 'slug';
    case Snake = 'snake';

    public const CONFIG_FILENAME = 'definition-kit.yaml';

    /**
     * The style governing a components root: the project's own if it declares
     * one, otherwise the backward-compatible default.
     *
     * Looks next to the components root and one directory up, matching
     * {@see \Parisek\DefinitionKit\Baseline\FrameworkProps::discoverFor()} —
     * a project that already keeps `framework-props-baseline.yaml` in one of
     * those two places should not have to learn a second location.
     */
    public static function discoverFor(string $componentsRoot): self
    {
        $componentsRoot = rtrim($componentsRoot, '/');

        foreach ([$componentsRoot, \dirname($componentsRoot)] as $directory) {
            $candidate = $directory . '/' . self::CONFIG_FILENAME;
            if (!is_file($candidate)) {
                continue;
            }

            $parsed = Yaml::parseFile($candidate);
            if (!\is_array($parsed) || !isset($parsed['key_style'])) {
                // A config file that exists but says nothing about keys is not
                // an error — it may carry other settings, or be a placeholder.
                return self::Slug;
            }

            $declared = $parsed['key_style'];
            $style = \is_string($declared) ? self::tryFrom($declared) : null;
            if (null === $style) {
                // Fail loudly. Silently falling back to the default would
                // rewrite every key in the project on the next generate, and
                // the drift-lint would report it as the project's own doing.
                $valid = implode('|', array_column(self::cases(), 'value'));
                $shown = \is_string($declared) ? $declared : get_debug_type($declared);

                throw new \RuntimeException(
                    "Invalid key_style '{$shown}' in {$candidate} — expected one of: {$valid}."
                );
            }

            return $style;
        }

        return self::Slug;
    }

    /**
     * The slug as it appears inside a derived key.
     *
     * Only hyphens are folded. Any other character a directory name may carry
     * is left alone: this exists to settle one specific disagreement, and a
     * broader "sanitise the slug" transform would silently rewrite keys nobody
     * asked about.
     */
    public function keySlug(string $componentSlug): string
    {
        return match ($this) {
            self::Slug => $componentSlug,
            self::Snake => str_replace('-', '_', $componentSlug),
        };
    }
}
