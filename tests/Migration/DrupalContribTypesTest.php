<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigPlanner;
use Parisek\DefinitionKit\Generator\Drupal\DrupalConfigWriter;
use Parisek\DefinitionKit\Generator\Drupal\PlanEntry;
use Parisek\DefinitionKit\Lint\DrupalDriftLinter;
use Parisek\DefinitionKit\Lint\DrupalDriftResult;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\ArrayJsonModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The contrib field types on paragraphs (#106): double_field, address,
 * webform and language_field. Each one is migrated from the export, linted
 * against it and generated back into it, and the three agree.
 */
final class DrupalContribTypesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/drupal-contrib';

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function config(): DrupalConfig
    {
        return DrupalConfig::fromDirectory(self::FIXTURES . '/config');
    }

    /** @return array<string,array<string,mixed>> */
    private function read(string $bundle): array
    {
        return (new DrupalParagraphReader($this->config(), new DrupalSettings()))->read($bundle);
    }

    /** @return iterable<string, array{string}> */
    public static function bundles(): iterable
    {
        yield 'double_field: list, a select subfield' => ['benefits'];
        yield 'double_field: list with a cardinality limit' => ['portfolio'];
        yield 'double_field: one value is an object' => ['pair'];
        yield 'address: one and several' => ['company'];
        yield 'webform' => ['contact_form'];
        yield 'webform: required, same storage' => ['workshop_form'];
        yield 'language_field behind a field_group' => ['content'];
        yield 'language_field, same storage' => ['category'];
    }

    #[Test]
    #[DataProvider('bundles')]
    public function migrate_matches_the_golden_definition(string $bundle): void
    {
        $expected = Yaml::parseFile(self::FIXTURES . '/expected/' . str_replace('_', '-', $bundle) . '.yaml');
        self::assertIsArray($expected);

        self::assertSame($expected['fields'], $this->read($bundle));
    }

    #[Test]
    #[DataProvider('bundles')]
    public function the_migrated_definition_is_valid(string $bundle): void
    {
        $tree = ['name' => 'X', 'category' => 'Block', 'kind' => 'block', 'fields' => $this->read($bundle)];

        $result = (new FieldsSchemaValidator())->validateData(ArrayJsonModel::toJsonModel($tree));

        self::assertTrue($result->valid, json_encode($result->errors, JSON_PRETTY_PRINT) ?: '');
    }

    #[Test]
    #[DataProvider('bundles')]
    public function lint_of_the_migrated_definition_is_clean(string $bundle): void
    {
        $result = $this->lint($bundle, $this->read($bundle));

        self::assertSame(DrupalDriftResult::OK, $result->status, implode("\n", $result->findings));
    }

    /** @param array<string,mixed> $fields */
    private function lint(string $bundle, array $fields): DrupalDriftResult
    {
        return (new DrupalDriftLinter($this->config(), new DrupalSettings()))
            ->lint(['name' => 'X', 'category' => 'Block', 'fields' => $fields], str_replace('_', '-', $bundle));
    }

    #[Test]
    public function each_type_gets_the_kit_type_the_pr_documents(): void
    {
        $benefits = $this->read('benefits')['benefits'];
        self::assertSame('list', $benefits['type']);
        self::assertSame('double_field', $benefits['drupal']['storage']);
        self::assertSame(['first', 'second'], array_keys($benefits['fields']));
        self::assertSame('select', $benefits['fields']['first']['type']);
        self::assertSame(['reward' => 'Reward', 'map' => 'Map', 'graph' => 'Graph'], $benefits['fields']['first']['options']);
        self::assertArrayNotHasKey('max', $benefits, 'unlimited');
        self::assertSame(4, $this->read('portfolio')['portfolio']['max']);

        $pair = $this->read('pair')['pair'];
        self::assertSame('object', $pair['type'], 'cardinality 1');
        self::assertSame('field_pair', $pair['drupal']['field'], 'an object is a field_group unless pinned');
        self::assertSame(['number', 'boolean'], [$pair['fields']['first']['type'], $pair['fields']['second']['type']]);

        $company = $this->read('company');
        self::assertSame(['type' => 'object', 'open' => true, 'storage' => 'address'], [
            'type' => $company['address']['type'],
            'open' => $company['address']['open'],
            'storage' => $company['address']['drupal']['storage'],
        ]);
        self::assertSame('list', $company['branches']['type']);

        $webform = $this->read('contact_form')['webform'];
        self::assertSame(['reference', 'entity:webform', 'webform'], [$webform['type'], $webform['of'], $webform['drupal']['storage']]);

        $langcode = $this->read('category')['langcode'];
        self::assertSame(['text', true, 'language_field'], [$langcode['type'], $langcode['multiple'], $langcode['drupal']['storage']]);
    }

    /** @return iterable<string, array{string, callable(array<string,mixed>): array<string,mixed>, string}> */
    public static function drift(): iterable
    {
        yield 'wrong storage pin' => [
            'category',
            static function (array $f): array {
                $f['langcode']['drupal']['storage'] = 'string';

                return $f;
            },
            'storage type language_field',
        ];
        yield 'a webform reference pinned to a core entity_reference' => [
            'contact_form',
            static function (array $f): array {
                $f['webform']['drupal']['storage'] = 'entity_reference';

                return $f;
            },
            'storage type webform; the definition type reference expects entity_reference',
        ];
        yield 'another target type' => [
            'contact_form',
            static function (array $f): array {
                $f['webform']['of'] = 'entity:node';

                return $f;
            },
            'references webform entities; the definition expects node',
        ];
        yield 'a part missing' => [
            'portfolio',
            static function (array $f): array {
                unset($f['portfolio']['fields']['second']);

                return $f;
            },
            'double_field stores the values first, second; the definition declares first',
        ];
        yield 'a part renamed' => [
            'portfolio',
            static function (array $f): array {
                $f['portfolio']['fields'] = ['title' => $f['portfolio']['fields']['first'], 'second' => $f['portfolio']['fields']['second']];

                return $f;
            },
            'the definition declares title, second',
        ];
        yield 'a part of the wrong type' => [
            'pair',
            static function (array $f): array {
                $f['pair']['fields']['second']['type'] = 'text';

                return $f;
            },
            'second is a boolean subfield in Drupal; the definition type text expects boolean',
        ];
        yield 'several values expected of a single double_field' => [
            'pair',
            static function (array $f): array {
                $f['pair']['type'] = 'list';
                unset($f['pair']['drupal']['field']);

                return $f;
            },
            'single-value in Drupal (cardinality 1); the definition expects several values',
        ];
        yield 'cardinality limit' => [
            'portfolio',
            static function (array $f): array {
                $f['portfolio']['max'] = 9;

                return $f;
            },
            'Drupal allows at most 4 values; the definition sets max: 9',
        ];
        yield 'single language' => [
            'category',
            static function (array $f): array {
                unset($f['langcode']['multiple']);

                return $f;
            },
            'multi-value in Drupal',
        ];
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $break
     */
    #[Test]
    #[DataProvider('drift')]
    public function lint_reports_drift_on_a_contrib_field(string $bundle, callable $break, string $expected): void
    {
        $result = $this->lint($bundle, $break($this->read($bundle)));

        self::assertSame(DrupalDriftResult::DRIFT, $result->status);
        self::assertStringContainsString($expected, implode("\n", $result->findings));
    }

    #[Test]
    public function a_subfield_storage_type_the_part_cannot_hold_is_drift(): void
    {
        $dir = $this->copyConfig();
        $storage = "{$dir}/field.storage.paragraph.field_pair.yml";
        file_put_contents($storage, str_replace('type: boolean', 'type: email', (string) file_get_contents($storage)));
        $fields = $this->read('pair');

        $result = (new DrupalDriftLinter(DrupalConfig::fromDirectory($dir), new DrupalSettings()))
            ->lint(['name' => 'X', 'category' => 'Block', 'fields' => $fields], 'pair');

        self::assertSame(DrupalDriftResult::DRIFT, $result->status);
        self::assertStringContainsString('second is a email subfield in Drupal; the definition type boolean expects text', implode("\n", $result->findings));
    }

    // ---------------------------------------------------------------- generate

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        $definitions = [];
        foreach (array_map(static fn (array $b): string => $b[0], iterator_to_array(self::bundles(), false)) as $bundle) {
            $definitions[str_replace('_', '-', $bundle)] = [
                'name' => $this->config()->bundleLabel($bundle),
                'category' => 'Block',
                'kind' => 'block',
                'drupal' => "/admin/structure/paragraphs_type/{$bundle}/fields",
                'fields' => $this->read($bundle),
            ];
        }

        return $definitions;
    }

    /** @param array<string,array<string,mixed>> $definitions */
    private function plan(array $definitions, string $configDir, ?DrupalConfig $resolver): \Parisek\DefinitionKit\Generator\Drupal\Plan
    {
        $settings = new DrupalSettings();
        $builder = new BundleSpecBuilder($settings);
        $specs = [];
        foreach ($definitions as $slug => $definition) {
            $specs = [...$specs, ...$builder->build($definition, $slug, $resolver)];
        }

        return (new DrupalConfigPlanner(new ConfigStore($configDir), $settings))->plan($specs);
    }

    #[Test]
    public function generate_into_its_own_export_plans_no_change(): void
    {
        $plan = $this->plan($this->definitions(), self::FIXTURES . '/config', $this->config());

        $lines = implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries));
        self::assertFalse($plan->refused(), $lines);
        self::assertSame([], $plan->withAction(PlanEntry::CREATE), $lines);
        self::assertSame([], $plan->withAction(PlanEntry::UPDATE), $lines);
        self::assertSame([], $plan->changedNames());
    }

    #[Test]
    public function generate_from_nothing_rebuilds_every_contrib_field(): void
    {
        $definitions = $this->definitions();
        $target = $this->tempDir();

        $plan = $this->plan($definitions, $target, null);
        self::assertFalse($plan->refused(), implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries)));
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $generated = DrupalConfig::fromDirectory($target);
        $source = $this->config();

        foreach ($source->bundles() as $bundle) {
            foreach ($source->fields($bundle) as $name => $field) {
                $made = $generated->fields($bundle)[$name] ?? null;
                self::assertNotNull($made, "{$bundle}.{$name} was not generated");
                self::assertSame($field->type, $made->type, "{$bundle}.{$name}: storage type");
                self::assertSame($field->cardinality, $made->cardinality, "{$bundle}.{$name}: cardinality");
                self::assertSame($field->required, $made->required, "{$bundle}.{$name}: required");
                self::assertSame($field->label, $made->label, "{$bundle}.{$name}: label");
                self::assertSame($field->targetType(), $made->targetType(), "{$bundle}.{$name}: target type");
            }
        }

        // double_field: the subfield storage types, labels, required flags and options come from the parts.
        $benefits = $generated->fields('benefits')['field_benefits'];
        self::assertSame('string', $benefits->settings['storage']['first']['type']);
        self::assertSame('Icon', $benefits->settings['first']['label']);
        self::assertTrue($benefits->settings['first']['list']);
        self::assertSame(['reward' => 'Reward', 'map' => 'Map', 'graph' => 'Graph'], \Parisek\DefinitionKit\Drupal\DrupalField::parseAllowedValues($benefits->settings['first']['allowed_values']));
        self::assertFalse($benefits->settings['second']['list']);
        $pair = $generated->fields('pair')['field_pair'];
        self::assertSame(['float', 'boolean'], [$pair->settings['storage']['first']['type'], $pair->settings['storage']['second']['type']]);
        self::assertFalse($pair->settings['second']['required']);

        // webform: the instance points at the webform entity type, any bundle.
        $webform = $generated->fields('contact_form')['field_webform'];
        self::assertSame('webform', $webform->targetType());
        self::assertSame('default:webform', $webform->settings['handler']);
        self::assertSame([], $webform->targetBundles());

        // The module of each field type is a dependency of its storage.
        $storage = Yaml::parseFile("{$target}/field.storage.paragraph.field_langcode.yml");
        self::assertIsArray($storage);
        self::assertSame('languagefield', $storage['module']);
        self::assertContains('languagefield', $storage['dependencies']['module']);
        $form = Yaml::parseFile("{$target}/core.entity_form_display.paragraph.benefits.default.yml");
        self::assertIsArray($form);
        self::assertSame('double_field', $form['content']['field_benefits']['type']);
        self::assertContains('double_field', $form['dependencies']['module']);

        // Lint-clean, and a second plan changes nothing.
        $linter = new DrupalDriftLinter($generated, new DrupalSettings());
        foreach ($definitions as $slug => $definition) {
            $result = $linter->lint($definition, $slug);
            self::assertSame(DrupalDriftResult::OK, $result->status, "{$slug}: " . implode("\n", $result->findings));
        }
        self::assertSame([], $this->plan($definitions, $target, $generated)->changedNames());
    }

    #[Test]
    public function generate_refuses_a_part_the_existing_subfield_cannot_hold(): void
    {
        $dir = $this->copyConfig();
        $definitions = $this->definitions();
        $definitions['pair']['fields']['pair']['fields']['second']['type'] = 'text';

        $plan = $this->plan(['pair' => $definitions['pair']], $dir, DrupalConfig::fromDirectory($dir));

        self::assertTrue($plan->refused());
        self::assertSame([], $plan->changedNames());
    }

    #[Test]
    public function a_double_field_object_creates_no_nested_paragraph_type(): void
    {
        $specs = (new BundleSpecBuilder(new DrupalSettings()))->build(
            ['name' => 'Pair', 'category' => 'Block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $this->read('pair')],
            'pair',
            $this->config(),
        );

        self::assertCount(1, $specs, 'a double_field object creates no nested paragraph type');
        self::assertSame(['double_field'], $specs[0]->fields[0]->accepted);
    }

    // --------------------------------------------------------------------- cli

    #[Test]
    public function the_cli_migrates_and_lints_a_contrib_export(): void
    {
        $project = $this->tempDir();
        mkdir("{$project}/component/benefits", 0777, true);
        file_put_contents("{$project}/component/benefits/benefits.twig", "{#\nname: \"Benefits\"\ncategory: \"Block\"\n#}\n<ul></ul>\n");
        copy(self::FIXTURES . '/definition-kit.yaml', "{$project}/definition-kit.yaml");

        exec(sprintf(
            'php %s --drupal-config=%s --root=%s 2>&1',
            escapeshellarg(__DIR__ . '/../../bin/fields-migrate'),
            escapeshellarg(self::FIXTURES . '/config'),
            escapeshellarg("{$project}/component"),
        ), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        $written = Yaml::parseFile("{$project}/component/benefits/benefits.yaml");
        self::assertIsArray($written);
        self::assertSame($this->read('benefits'), $written['fields']);

        exec(sprintf(
            'php %s --drupal-config=%s %s 2>&1',
            escapeshellarg(__DIR__ . '/../../bin/fields-lint-drupal'),
            escapeshellarg(self::FIXTURES . '/config'),
            escapeshellarg("{$project}/component/benefits"),
        ), $lint, $lintCode);
        self::assertSame(0, $lintCode, implode("\n", $lint));
    }

    // ----------------------------------------------------------------- overlay

    private const OVERLAY = self::FIXTURES . '/overlay/type-map.yaml';

    /** A copy of the export plus a `banner` paragraph type with a Color Field value (not a bundled type). */
    private function exportWithColorField(): string
    {
        $dir = $this->copyConfig();
        file_put_contents("{$dir}/paragraphs.paragraphs_type.banner.yml", "langcode: en\nstatus: true\ndependencies: {  }\nid: banner\nlabel: Banner\nicon_uuid: null\nicon_default: null\ndescription: ''\nbehavior_plugins: {  }\n");
        file_put_contents("{$dir}/field.storage.paragraph.field_color.yml", <<<'YAML'
            langcode: en
            status: true
            dependencies:
              module:
                - color_field
                - paragraphs
            id: paragraph.field_color
            field_name: field_color
            entity_type: paragraph
            type: color_field_type
            settings:
              format: '#HEXHEX'
              opacity: false
            module: color_field
            locked: false
            cardinality: 1
            translatable: true
            indexes: {  }
            persist_with_no_fields: false
            custom_storage: false
            YAML);
        file_put_contents("{$dir}/field.field.paragraph.banner.field_color.yml", <<<'YAML'
            langcode: en
            status: true
            dependencies:
              config:
                - field.storage.paragraph.field_color
                - paragraphs.paragraphs_type.banner
              module:
                - color_field
            id: paragraph.banner.field_color
            field_name: field_color
            entity_type: paragraph
            bundle: banner
            label: Color
            description: ''
            required: true
            translatable: true
            default_value: {  }
            default_value_callback: ''
            settings:
              opacity: false
            field_type: color_field_type
            YAML);

        return $dir;
    }

    #[Test]
    public function an_unknown_storage_type_stops_the_migration_and_names_the_overlay_as_the_fix(): void
    {
        $reader = new DrupalParagraphReader(DrupalConfig::fromDirectory($this->exportWithColorField()), new DrupalSettings());

        try {
            $reader->read('banner');
            self::fail('The unknown storage type was accepted.');
        } catch (\DomainException $e) {
            self::assertStringContainsString("storage type 'color_field_type', which the kit has no type for", $e->getMessage());
            self::assertStringContainsString('drupal.type_map', $e->getMessage());
            self::assertStringContainsString('drupal.ignore_fields', $e->getMessage());
        }
    }

    #[Test]
    public function an_overlay_maps_an_unknown_type_through_migrate_lint_and_generate(): void
    {
        $dir = $this->exportWithColorField();
        $config = DrupalConfig::fromDirectory($dir);
        $settings = new DrupalSettings(typeMap: self::OVERLAY);
        $types = \Parisek\DefinitionKit\Drupal\DrupalTypeMap::forSettings($settings);

        // migrate
        $fields = (new DrupalParagraphReader($config, $settings))->read('banner');
        self::assertSame(
            ['color' => ['type' => 'text', 'role' => 'field', 'label' => 'Color', 'required' => true, 'drupal' => ['storage' => 'color_field_type']]],
            $fields,
        );

        // lint
        $definition = ['name' => 'Banner', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/banner/fields', 'fields' => $fields];
        $result = (new DrupalDriftLinter($config, $settings))->lint($definition, 'banner');
        self::assertSame(DrupalDriftResult::OK, $result->status, implode("\n", $result->findings));

        // generate: into the export (no change), and from nothing
        $builder = new BundleSpecBuilder($settings);
        $specs = $builder->build($definition, 'banner', $config);
        $merge = (new DrupalConfigPlanner(new ConfigStore($dir), $settings))->plan($specs);
        self::assertSame([], $merge->changedNames());

        $target = $this->tempDir();
        $plan = (new DrupalConfigPlanner(new ConfigStore($target), $settings, null, $types))->plan($specs);
        self::assertFalse($plan->refused());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $storage = Yaml::parseFile("{$target}/field.storage.paragraph.field_color.yml");
        self::assertIsArray($storage);
        self::assertSame(['color_field_type', 'color_field', ['format' => '#HEXHEX', 'opacity' => false]], [$storage['type'], $storage['module'], $storage['settings']]);
        self::assertContains('color_field', $storage['dependencies']['module']);
        $form = Yaml::parseFile("{$target}/core.entity_form_display.paragraph.banner.default.yml");
        self::assertIsArray($form);
        self::assertSame('color_field_widget_default', $form['content']['field_color']['type']);
        self::assertContains('color_field', $form['dependencies']['module']);
    }

    #[Test]
    public function the_clis_read_the_overlay_of_definition_kit_yaml_and_stop_on_a_malformed_one(): void
    {
        $project = $this->tempDir();
        mkdir("{$project}/component/banner", 0777, true);
        file_put_contents("{$project}/component/banner/banner.twig", "{#\nname: \"Banner\"\ncategory: \"Block\"\n#}\n<p></p>\n");
        copy(self::OVERLAY, "{$project}/drupal-types.yaml");
        file_put_contents("{$project}/definition-kit.yaml", "drupal:\n  type_map: drupal-types.yaml\n");
        $export = $this->exportWithColorField();
        $migrate = 'php ' . escapeshellarg(__DIR__ . '/../../bin/fields-migrate') . ' --drupal-config=' . escapeshellarg($export) . ' --root=' . escapeshellarg("{$project}/component") . ' 2>&1';
        $lint = 'php ' . escapeshellarg(__DIR__ . '/../../bin/fields-lint-drupal') . ' --drupal-config=' . escapeshellarg($export) . ' ' . escapeshellarg("{$project}/component/banner") . ' 2>&1';

        exec($migrate, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $written = Yaml::parseFile("{$project}/component/banner/banner.yaml");
        self::assertIsArray($written);
        self::assertSame('color_field_type', $written['fields']['color']['drupal']['storage']);

        exec($lint, $lintOut, $lintCode);
        self::assertSame(0, $lintCode, implode("\n", $lintOut));

        // Without the overlay the same run fails and says what to do.
        unlink("{$project}/component/banner/banner.yaml");
        file_put_contents("{$project}/definition-kit.yaml", "drupal: {}\n");
        exec($migrate, $failed, $failedCode);
        self::assertNotSame(0, $failedCode);
        self::assertStringContainsString('drupal.type_map', implode("\n", $failed));

        // A malformed overlay stops every command with exit 2 and its path.
        file_put_contents("{$project}/drupal-types.yaml", "storage_types:\n  broken:\n    widget: {type: w}\n");
        file_put_contents("{$project}/definition-kit.yaml", "drupal:\n  type_map: drupal-types.yaml\n");
        $generate = 'php ' . escapeshellarg(__DIR__ . '/../../bin/fields-generate') . ' --target=drupal --dry-run --drupal-config=' . escapeshellarg($export) . ' --root=' . escapeshellarg("{$project}/component") . ' 2>&1';
        foreach ([$migrate, $lint, $generate] as $command) {
            $output = [];
            exec($command, $output, $exit);
            self::assertSame(2, $exit, $command . "\n" . implode("\n", $output));
            self::assertStringContainsString("{$project}/drupal-types.yaml", implode("\n", $output));
            self::assertStringContainsString('storage_types.broken needs a `module`', implode("\n", $output));
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/dk-contrib-' . uniqid('', true);
        mkdir($dir);
        $this->cleanup[] = $dir;

        return $dir;
    }

    private function copyConfig(): string
    {
        $dir = $this->tempDir();
        foreach (glob(self::FIXTURES . '/config/*.yml') ?: [] as $file) {
            copy($file, "{$dir}/" . basename($file));
        }

        return $dir;
    }
}
