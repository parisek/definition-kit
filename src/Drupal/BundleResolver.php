<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

/**
 * Which paragraph bundle(s) a component describes.
 *
 * Order:
 *   1. The root `drupal:` admin link, when it names a paragraph type:
 *      `/admin/structure/paragraphs_type/<bundle>/fields` (site-relative or
 *      absolute). The link is authored per component, so it is the strongest
 *      signal, and a bundle it names must exist.
 *   2. Otherwise the naming convention: the component directory in
 *      snake_case (`contact-list` -> `contact_list`). A convention match
 *      counts only when the config export has that bundle; most components
 *      (header, button, ...) are not paragraphs at all.
 *   3. Plus every bundle that `drupal.bundle_aliases` in definition-kit.yaml
 *      maps to this component (many bundles can render one component).
 */
final class BundleResolver
{
    private const LINK_PATTERN = '#/admin/structure/paragraphs_type/([a-z0-9_]+)(?:[/?\#]|$)#';

    public const SOURCE_LINK = 'link';
    public const SOURCE_CONVENTION = 'convention';
    public const SOURCE_ALIAS = 'alias';

    public function __construct(private readonly DrupalSettings $settings = new DrupalSettings())
    {
    }

    public static function bundleFromLink(string $link): ?string
    {
        return 1 === preg_match(self::LINK_PATTERN, $link, $m) ? $m[1] : null;
    }

    public static function conventionalBundle(string $componentSlug): string
    {
        return str_replace('-', '_', $componentSlug);
    }

    /**
     * @return array<string,string> bundle => source (link|convention|alias), primary bundle first
     */
    public function resolve(string $componentSlug, ?string $drupalLink, ?DrupalConfig $config): array
    {
        $bundles = [];
        $linked = null !== $drupalLink ? self::bundleFromLink($drupalLink) : null;
        if (null !== $linked) {
            $bundles[$linked] = self::SOURCE_LINK;
        } else {
            $conventional = self::conventionalBundle($componentSlug);
            $aliasedElsewhere = isset($this->settings->bundleAliases[$conventional])
                && $this->settings->bundleAliases[$conventional] !== $componentSlug;
            if (null !== $config && $config->hasBundle($conventional) && !$aliasedElsewhere) {
                $bundles[$conventional] = self::SOURCE_CONVENTION;
            }
        }
        foreach ($this->settings->bundleAliases as $bundle => $component) {
            if ($component === $componentSlug && !isset($bundles[$bundle])) {
                $bundles[$bundle] = self::SOURCE_ALIAS;
            }
        }

        return $bundles;
    }
}
