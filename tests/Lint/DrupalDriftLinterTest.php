<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Lint\DrupalDriftLinter;
use Parisek\DefinitionKit\Lint\DrupalDriftResult;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DrupalDriftLinterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal';

    private function config(): DrupalConfig
    {
        return DrupalConfig::fromDirectory(self::FIXTURES . '/config');
    }

    private function settings(): DrupalSettings
    {
        return DrupalSettings::discoverFor(self::FIXTURES . '/component');
    }

    private function linter(?DrupalSettings $settings = null): DrupalDriftLinter
    {
        return new DrupalDriftLinter($this->config(), $settings ?? $this->settings());
    }

    /** @return array<string,mixed> */
    private function golden(string $component): array
    {
        $tree = Yaml::parseFile(self::FIXTURES . "/expected/{$component}.yaml");
        self::assertIsArray($tree);

        return $tree;
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<string>
     */
    private function findings(array $definition, string $component): array
    {
        $result = $this->linter()->lint($definition, $component);
        self::assertSame(DrupalDriftResult::DRIFT, $result->status, 'expected drift, got ' . $result->status);

        return $result->findings;
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function bundles(): iterable
    {
        foreach (['card_list', 'stats', 'quote_image', 'image_full', 'promo', 'mixed_section', 'from_library', 'teaser'] as $bundle) {
            yield "{$bundle} with evidence" => [$bundle, str_replace('_', '-', $bundle), true];
            yield "{$bundle} by convention" => [$bundle, str_replace('_', '-', $bundle), false];
        }
    }

    #[Test]
    #[DataProvider('bundles')]
    public function a_migrated_definition_is_clean_against_its_own_config(string $bundle, string $component, bool $evidence): void
    {
        // The migration's contract: lint(migrate(config)) is clean.
        $fields = (new DrupalParagraphReader(
            $this->config(),
            $this->settings(),
            $evidence ? DisplayEvidence::fromFile(self::FIXTURES . '/ParagraphDisplay.php') : null,
        ))->read($bundle);

        $result = $this->linter()->lint(['name' => 'X', 'category' => 'Block', 'fields' => $fields], $component);

        self::assertSame(DrupalDriftResult::OK, $result->status, implode("\n", $result->findings));
        self::assertSame([$bundle], $result->bundles);
    }

    #[Test]
    public function a_component_with_no_paragraph_bundle_is_skipped(): void
    {
        $result = $this->linter()->lint(['name' => 'Header', 'category' => 'Navigation', 'fields' => []], 'header');

        self::assertSame(DrupalDriftResult::SKIP, $result->status);
        self::assertStringContainsString('no paragraph bundle', (string) $result->reason);
    }

    #[Test]
    public function a_linked_bundle_missing_from_the_export_is_drift(): void
    {
        $findings = $this->findings(
            ['name' => 'Logo list', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/logo_list/fields', 'fields' => []],
            'logo-list',
        );

        self::assertSame(['logo_list: paragraph type does not exist in the config export (named by the drupal: link)'], $findings);
    }

    #[Test]
    public function every_aliased_bundle_is_compared(): void
    {
        $result = $this->linter()->lint($this->golden('content'), 'content');

        self::assertSame(['content', 'html'], $result->bundles);
        self::assertSame(['html: field_title (string): Drupal field has no definition field'], $result->findings);
    }

    #[Test]
    public function a_definition_field_with_no_drupal_field_is_reported(): void
    {
        $definition = $this->golden('card-list');
        $definition['fields']['subtitle'] = ['type' => 'text', 'label' => 'Subtitle'];

        self::assertSame(
            ['card_list: subtitle: no Drupal field field_subtitle (by convention; pin another with drupal.field)'],
            $this->findings($definition, 'card-list'),
        );
    }

    #[Test]
    public function a_drupal_field_with_no_definition_field_is_reported_at_its_nesting_level(): void
    {
        $definition = $this->golden('card-list');
        unset($definition['fields']['items']['fields']['phone']);

        self::assertSame(
            ['card_list_item: field_phone (telephone): Drupal field has no definition field under `items`'],
            $this->findings($definition, 'card-list'),
        );
    }

    #[Test]
    public function non_field_roles_are_not_drupal_fields(): void
    {
        $definition = $this->golden('card-list');
        $definition['fields']['container'] = ['role' => 'parent'];
        $definition['fields']['feed'] = ['role' => 'query', 'type' => 'list', 'fields' => ['title' => ['type' => 'text']]];

        self::assertSame(DrupalDriftResult::OK, $this->linter()->lint($definition, 'card-list')->status);
    }

    #[Test]
    public function a_storage_type_outside_the_kit_type_is_reported(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['quote']['type'] = 'number';

        self::assertSame(
            ['quote_image: quote (field_quote): storage type text_long; the definition type number expects integer|decimal|float'],
            $this->findings($definition, 'quote-image'),
        );
    }

    #[Test]
    public function a_storage_pin_accepts_only_that_type(): void
    {
        $definition = $this->golden('card-list');
        $definition['fields']['items']['fields']['phone']['drupal']['storage'] = 'email';

        self::assertSame(
            ['card_list_item: items.phone (field_phone): storage type telephone; the definition type text expects email'],
            $this->findings($definition, 'card-list'),
        );
    }

    #[Test]
    public function a_required_mismatch_is_reported(): void
    {
        $definition = $this->golden('quote-image');
        unset($definition['fields']['quote']['required']);

        self::assertSame(
            ['quote_image: quote (field_quote): required is on in Drupal, off in the definition'],
            $this->findings($definition, 'quote-image'),
        );
    }

    #[Test]
    public function one_value_against_several_is_reported_both_ways(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['image'] = ['type' => 'media', 'label' => 'Image', 'kind' => 'image', 'drupal' => ['field' => 'field_media', 'target_bundles' => ['image']]];
        $definition['fields']['signature']['multiple'] = true;

        self::assertSame([
            'quote_image: signature (field_signature): single-value in Drupal (cardinality 1); the definition expects several values',
            'quote_image: image (field_media): multi-value in Drupal (cardinality unlimited); the definition expects one value',
        ], $this->findings($definition, 'quote-image'));
    }

    #[Test]
    public function a_fixed_limit_must_match_max(): void
    {
        $definition = $this->golden('stats');
        unset($definition['fields']['items']['max']);

        self::assertSame(
            ['stats: items (field_paragraphs): Drupal allows at most 5 values; the definition sets no max'],
            $this->findings($definition, 'stats'),
        );
    }

    #[Test]
    public function media_target_bundles_are_compared(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['signature']['drupal']['target_bundles'] = ['image'];

        self::assertSame(
            ['quote_image: signature (field_signature): targets bundle(s) image, vector_image; the definition expects image'],
            $this->findings($definition, 'quote-image'),
        );
    }

    #[Test]
    public function a_list_expects_the_item_bundle_by_convention(): void
    {
        $definition = $this->golden('card-list');
        $definition['fields']['items']['drupal']['target_bundles'] = ['stats_item'];

        $findings = $this->findings($definition, 'card-list');

        self::assertSame(
            'card_list: items (field_paragraphs): targets paragraph type(s) card_list_item; the definition expects stats_item',
            $findings[0],
        );
    }

    #[Test]
    public function the_nested_fields_are_compared_with_the_nested_bundle(): void
    {
        $definition = $this->golden('promo');
        $definition['fields']['body']['fields']['content']['type'] = 'text';

        self::assertSame(
            ['promo_body: body.content (field_content): storage type text_long; the definition type text expects string|string_long|email|telephone'],
            $this->findings($definition, 'promo'),
        );
    }

    #[Test]
    public function flexible_content_layouts_must_match_the_target_bundles(): void
    {
        $fields = (new DrupalParagraphReader($this->config(), $this->settings()))->read('mixed_section');
        unset($fields['sections']['layouts']['quote_image']);

        self::assertSame(
            ['mixed_section: sections (field_sections): targets paragraph type(s) image_full, quote_image; the definition has layouts image_full'],
            $this->findings(['name' => 'X', 'category' => 'Block', 'fields' => $fields], 'mixed-section'),
        );
    }

    #[Test]
    public function an_object_without_a_pin_is_a_field_group_on_the_same_bundle(): void
    {
        $definition = $this->golden('quote-image');
        // author.name -> field_author_name moved to the root: same Drupal field, same result.
        $definition['fields']['author_name'] = ['type' => 'text', 'label' => 'Author name'];
        unset($definition['fields']['author']);

        self::assertSame(DrupalDriftResult::OK, $this->linter()->lint($definition, 'quote-image')->status);
    }

    #[Test]
    public function two_definition_fields_cannot_share_one_drupal_field(): void
    {
        $definition = $this->golden('quote-image');
        $definition['fields']['cta'] = ['type' => 'link', 'label' => 'CTA', 'drupal' => ['field' => 'field_link']];

        self::assertSame(
            ['quote_image: cta: Drupal field field_link is already mapped to `button`'],
            $this->findings($definition, 'quote-image'),
        );
    }

    #[Test]
    public function ignore_fields_only_silences_the_extra_field_report(): void
    {
        $definition = $this->golden('card-list');

        $strict = $this->linter(new DrupalSettings())->lint($definition, 'card-list');

        self::assertSame(['card_list: field_wrapper_id (string): Drupal field has no definition field'], $strict->findings);
    }

    #[Test]
    public function prefixed_naming_changes_the_convention(): void
    {
        $settings = new DrupalSettings(fieldNaming: DrupalSettings::NAMING_PREFIXED);
        $result = $this->linter($settings)->lint(['name' => 'T', 'category' => 'Block', 'fields' => ['title' => ['type' => 'text', 'label' => 'T', 'required' => true]]], 'teaser');

        self::assertSame([
            'teaser: title: no Drupal field field_teaser_title (by convention; pin another with drupal.field)',
            'teaser: field_title (string): Drupal field has no definition field',
        ], $result->findings);
    }

    #[Test]
    public function unclaimed_bundles_are_the_ones_no_lint_reached(): void
    {
        $linter = $this->linter();
        foreach (['card-list', 'quote-image', 'stats', 'promo', 'content'] as $component) {
            $linter->lint($this->golden($component), $component);
        }

        // card_list_item, stats_item and promo_body were reached by nesting;
        // html by alias; from_library is allowlisted.
        self::assertSame(['image_full', 'mixed_section', 'teaser'], $linter->unclaimedBundles());
    }
}
