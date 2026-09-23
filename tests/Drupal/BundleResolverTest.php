<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\BundleResolver;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BundleResolverTest extends TestCase
{
    private function config(): DrupalConfig
    {
        return DrupalConfig::fromDirectory(__DIR__ . '/../fixtures/drupal/config');
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function links(): iterable
    {
        yield 'site-relative fields page' => ['/admin/structure/paragraphs_type/card_list/fields', 'card_list'];
        yield 'bare type page' => ['/admin/structure/paragraphs_type/card_list', 'card_list'];
        yield 'absolute url' => ['https://example.test/admin/structure/paragraphs_type/stats/form-display', 'stats'];
        yield 'query string' => ['/admin/structure/paragraphs_type/stats?destination=x', 'stats'];
        yield 'custom block type is not a paragraph' => ['/admin/structure/block/block-content/manage/stats', null];
        yield 'node type is not a paragraph' => ['/admin/structure/types/manage/page', null];
    }

    #[Test]
    #[DataProvider('links')]
    public function it_reads_the_bundle_from_the_admin_link(string $link, ?string $bundle): void
    {
        self::assertSame($bundle, BundleResolver::bundleFromLink($link));
    }

    #[Test]
    public function the_admin_link_wins_and_need_not_exist(): void
    {
        $bundles = (new BundleResolver())->resolve('logo-list', '/admin/structure/paragraphs_type/logo_list/fields', $this->config());

        self::assertSame(['logo_list' => BundleResolver::SOURCE_LINK], $bundles);
    }

    #[Test]
    public function without_a_link_the_snake_case_convention_counts_only_when_the_bundle_exists(): void
    {
        $resolver = new BundleResolver();

        self::assertSame(['card_list' => BundleResolver::SOURCE_CONVENTION], $resolver->resolve('card-list', null, $this->config()));
        self::assertSame([], $resolver->resolve('header', null, $this->config()));
        self::assertSame([], $resolver->resolve('card-list', null, null));
    }

    #[Test]
    public function a_link_to_another_admin_page_falls_back_to_the_convention(): void
    {
        $bundles = (new BundleResolver())->resolve('stats', '/admin/structure/block/block-content/manage/stats', $this->config());

        self::assertSame(['stats' => BundleResolver::SOURCE_CONVENTION], $bundles);
    }

    #[Test]
    public function aliases_add_bundles_and_keep_the_primary_first(): void
    {
        $resolver = new BundleResolver(new DrupalSettings(bundleAliases: ['html' => 'content']));

        self::assertSame(
            ['content' => BundleResolver::SOURCE_LINK, 'html' => BundleResolver::SOURCE_ALIAS],
            $resolver->resolve('content', '/admin/structure/paragraphs_type/content/fields', $this->config()),
        );
    }

    #[Test]
    public function a_bundle_aliased_to_another_component_is_not_taken_by_convention(): void
    {
        $resolver = new BundleResolver(new DrupalSettings(bundleAliases: ['html' => 'content']));

        self::assertSame([], $resolver->resolve('html', null, $this->config()));
    }
}
