<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalField;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigPlanner;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigWriter;
use Parisek\DefinitionKit\Generator\Drupal\Plan;
use Parisek\DefinitionKit\Generator\Drupal\PlanEntry;
use Parisek\DefinitionKit\Lint\DrupalDriftLinter;
use Parisek\DefinitionKit\Lint\DrupalDriftResult;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract of ADR 0002, in both directions:
 *
 *   merge:  generate(migrate(export)) against the same export plans no
 *           CREATE and no UPDATE, and names nothing for the deploy step;
 *   create: generate(migrate(export)) into an empty directory rebuilds every
 *           owned key of every field instance, with the effective
 *           per-instance cardinality, and the result is lint-clean.
 */
final class DrupalGenerateRoundTripTest extends TestCase
{
    private const DRUPAL = __DIR__ . '/../../fixtures/drupal';
    private const MERGE = __DIR__ . '/../../fixtures/drupal-merge';

    /** @return iterable<string, array{string, string, list<string>, bool}> */
    public static function exports(): iterable
    {
        // `contact` is ADR 0009's fixture: an unrestricted entity_reference
        // to the `webform` config entity (`of: entity:webform`), the arkero
        // shape the DomainException crash and the target_bundles null/[]
        // mismatch both came from.
        $bundles = ['card_list', 'stats', 'quote_image', 'image_full', 'promo', 'mixed_section', 'teaser', 'content', 'contact'];
        yield 'fixture export, by convention' => [self::DRUPAL, '/config', $bundles, false];
        yield 'fixture export, with display evidence' => [self::DRUPAL, '/config', $bundles, true];
        yield 'real-shaped export' => [self::MERGE, '/config', ['quote_image', 'numbers'], false];
    }

    private static function settings(string $project, bool $fieldConfigCardinality = false): DrupalSettings
    {
        $settings = DrupalSettings::discoverFor("{$project}/component");
        if (!$fieldConfigCardinality || $settings->fieldConfigCardinality) {
            return $settings;
        }

        return new DrupalSettings(
            fieldNaming: $settings->fieldNaming,
            bundleAliases: $settings->bundleAliases,
            bundlesWithoutComponent: $settings->bundlesWithoutComponent,
            ignoreFields: $settings->ignoreFields,
            path: $settings->path,
            langcode: $settings->langcode,
            textFormat: $settings->textFormat,
            mediaBundles: $settings->mediaBundles,
            hostFields: $settings->hostFields,
            translation: $settings->translation,
            viewDisplay: $settings->viewDisplay,
            fieldConfigCardinality: true,
            baseline: $settings->baseline,
        );
    }

    /**
     * migrate(export): one definition per bundle, named after its component.
     *
     * @param list<string> $bundles
     * @return array<string,array<string,mixed>> component slug => definition
     */
    private static function migrate(DrupalConfig $config, DrupalSettings $settings, array $bundles, bool $evidence): array
    {
        $reader = new DrupalParagraphReader(
            $config,
            $settings,
            $evidence ? DisplayEvidence::fromFile(self::DRUPAL . '/ParagraphDisplay.php') : null,
        );
        $definitions = [];
        foreach ($bundles as $bundle) {
            $definitions[str_replace('_', '-', $bundle)] = [
                'name' => $config->bundleLabel($bundle),
                'category' => 'Block',
                'kind' => 'block',
                'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields",
                'fields' => $reader->read($bundle),
            ];
        }

        return $definitions;
    }

    /** @param array<string,array<string,mixed>> $definitions */
    private static function plan(array $definitions, string $configDir, DrupalSettings $settings, ?DrupalConfig $resolverConfig): Plan
    {
        $builder = new BundleSpecBuilder($settings);
        $specs = [];
        foreach ($definitions as $slug => $definition) {
            $specs = [...$specs, ...$builder->build($definition, $slug, $resolverConfig)];
        }

        return (new DrupalConfigPlanner(new ConfigStore($configDir), $settings))->plan($specs);
    }

    private static function lines(Plan $plan): string
    {
        return implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries));
    }

    /**
     * @param list<string> $bundles
     */
    #[Test]
    #[DataProvider('exports')]
    public function merged_into_its_own_export_the_migration_plans_no_change(string $project, string $configDir, array $bundles, bool $evidence): void
    {
        $settings = self::settings($project);
        $config = DrupalConfig::fromDirectory($project . $configDir);

        $plan = self::plan(self::migrate($config, $settings, $bundles, $evidence), $project . $configDir, $settings, $config);

        self::assertFalse($plan->refused(), self::lines($plan));
        self::assertSame([], $plan->withAction(PlanEntry::CREATE), self::lines($plan));
        self::assertSame([], $plan->withAction(PlanEntry::UPDATE), self::lines($plan));
        self::assertSame([], $plan->changedNames());
        self::assertNotSame([], $plan->withAction(PlanEntry::REUSE));
    }

    /**
     * @param list<string> $bundles
     */
    #[Test]
    #[DataProvider('exports')]
    public function created_from_nothing_the_owned_keys_match_the_source_export(string $project, string $configDir, array $bundles, bool $evidence): void
    {
        // Shared storage with different per-bundle limits (stats narrows
        // field_paragraphs to 5) needs field_config_cardinality to rebuild.
        $settings = self::settings($project, fieldConfigCardinality: true);
        $source = DrupalConfig::fromDirectory($project . $configDir);
        $definitions = self::migrate($source, $settings, $bundles, $evidence);
        $target = sys_get_temp_dir() . '/dk-drupal-roundtrip-' . uniqid('', true);
        mkdir($target);
        if ([] !== $settings->hostFields) {
            foreach ($settings->hostFields as $host) {
                copy("{$project}{$configDir}/{$host}.yml", "{$target}/{$host}.yml");
            }
        }

        $plan = self::plan($definitions, $target, $settings, null);
        self::assertFalse($plan->refused(), self::lines($plan));
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $generated = DrupalConfig::fromDirectory($target);

        $aliases = array_keys($settings->bundleAliases);
        foreach ($generated->bundles() as $bundle) {
            self::assertTrue($source->hasBundle($bundle), "generated an unknown bundle {$bundle}");
            $expected = array_filter($source->fields($bundle), static fn (DrupalField $f): bool => !$settings->ignores($f->name));
            $actual = $generated->fields($bundle);
            if (!in_array($bundle, $aliases, true)) {
                self::assertEqualsCanonicalizing(array_keys($expected), array_keys($actual), "{$bundle}: field set");
            }
            foreach ($actual as $name => $field) {
                self::assertArrayHasKey($name, $expected, "{$bundle}: generated a field the source has not");
                self::assertSame(self::owned($expected[$name], in_array($bundle, $aliases, true)), self::owned($field, in_array($bundle, $aliases, true)), "{$bundle}.{$name}");
            }
        }

        // And the generator's output is lint-clean against the definitions.
        $linter = new DrupalDriftLinter($generated, $settings);
        foreach ($definitions as $slug => $definition) {
            $result = $linter->lint($definition, $slug);
            self::assertSame(DrupalDriftResult::OK, $result->status, "{$slug}: " . implode("\n", $result->findings));
        }

        // A second plan against what was written changes nothing.
        $again = self::plan($definitions, $target, $settings, $generated);
        self::assertSame([], $again->changedNames(), self::lines($again));
    }

    /**
     * The keys ADR 0002 lets the definition own, as the lint's view of a
     * field shows them.
     *
     * @return array<string,mixed>
     */
    private static function owned(DrupalField $field, bool $alias): array
    {
        $owned = [
            'type' => $field->type,
            'cardinality' => $field->cardinality,
            'required' => $field->required,
            'target_type' => $field->targetType(),
            'target_bundles' => $field->targetBundles(),
            'allowed_values' => $field->allowedValues(),
            'link_url_only' => 'link' === $field->type ? 0 === (int) ($field->settings['title'] ?? 1) : null,
        ];
        if (!$alias) {
            $owned['label'] = $field->label;
            $owned['description'] = $field->description;
        }

        return $owned;
    }
}
