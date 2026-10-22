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
        self::assertSame(['integer', 'boolean'], [$pair->settings['storage']['first']['type'], $pair->settings['storage']['second']['type']]);
        self::assertFalse($pair->settings['second']['required']);

        // Each part gets the sub-widget its type and fixed list call for.
        $formWidgets = static fn (string $bundle, string $field): array => array_map(
            static fn (mixed $part): mixed => is_array($part) ? $part['type'] : null,
            (array) (Yaml::parseFile("{$target}/core.entity_form_display.paragraph.{$bundle}.default.yml")['content'][$field]['settings'] ?? []),
        );
        self::assertSame('select', $formWidgets('benefits', 'field_benefits')['first']);
        self::assertSame('textfield', $formWidgets('benefits', 'field_benefits')['second']);
        self::assertSame(['first' => 'number', 'second' => 'checkbox'], array_intersect_key($formWidgets('pair', 'field_pair'), ['first' => 1, 'second' => 1]));

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
    public function a_date_part_gets_a_datetime_sub_widget_and_a_datetime_subfield(): void
    {
        $definition = [
            'name' => 'Agenda',
            'category' => 'Block',
            'kind' => 'block',
            'drupal' => '/admin/structure/paragraphs_type/agenda/fields',
            'fields' => ['slots' => [
                'type' => 'list', 'role' => 'field', 'label' => 'Slots', 'drupal' => ['storage' => 'double_field'],
                'fields' => [
                    'first' => ['type' => 'date', 'role' => 'field', 'label' => 'Day'],
                    'second' => ['type' => 'richtext', 'role' => 'field', 'label' => 'Note'],
                ],
            ]],
        ];
        $target = $this->tempDir();
        $plan = $this->plan(['agenda' => $definition], $target, null);
        self::assertFalse($plan->refused());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));

        $storage = Yaml::parseFile("{$target}/field.storage.paragraph.field_slots.yml");
        $form = Yaml::parseFile("{$target}/core.entity_form_display.paragraph.agenda.default.yml");
        self::assertIsArray($storage);
        self::assertIsArray($form);
        self::assertSame(['datetime_iso8601', 'text'], [$storage['settings']['storage']['first']['type'], $storage['settings']['storage']['second']['type']]);
        self::assertSame(['datetime', 'textarea'], [$form['content']['field_slots']['settings']['first']['type'], $form['content']['field_slots']['settings']['second']['type']]);
    }

    #[Test]
    public function fields_that_share_a_double_field_storage_with_different_part_types_are_refused(): void
    {
        $make = static fn (string $second): array => [
            'name' => 'X', 'category' => 'Block', 'kind' => 'block',
            'fields' => ['pairs' => [
                'type' => 'list', 'role' => 'field', 'label' => 'Pairs', 'drupal' => ['field' => 'field_pairs', 'storage' => 'double_field'],
                'fields' => [
                    'first' => ['type' => 'text', 'role' => 'field', 'label' => 'A'],
                    'second' => ['type' => $second, 'role' => 'field', 'label' => 'B'],
                ],
            ]],
        ];
        $one = $make('text');
        $one['drupal'] = '/admin/structure/paragraphs_type/one/fields';
        $two = $make('boolean');
        $two['drupal'] = '/admin/structure/paragraphs_type/two/fields';

        $plan = $this->plan(['one' => $one, 'two' => $two], $this->tempDir(), null);

        self::assertTrue($plan->refused());
        $lines = implode("\n", array_map(static fn (PlanEntry $e): string => $e->line(), $plan->entries));
        self::assertStringContainsString('different double_field subfield types for part second', $lines);
    }

    #[Test]
    public function option_values_keyed_0_1_2_leave_the_part_a_plain_value(): void
    {
        $dir = $this->copyConfig();
        $file = "{$dir}/field.field.paragraph.benefits.field_benefits.yml";
        $yaml = (string) file_get_contents($file);
        $yaml = str_replace(['value: reward', 'value: map', 'value: graph'], ['value: \'0\'', 'value: \'1\'', 'value: \'2\''], $yaml);
        file_put_contents($file, $yaml);

        $fields = (new DrupalParagraphReader(DrupalConfig::fromDirectory($dir), new DrupalSettings()))->read('benefits');

        self::assertSame('text', $fields['benefits']['fields']['first']['type']);
        self::assertArrayNotHasKey('options', $fields['benefits']['fields']['first']);
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

    // ------------------------------------------------- subfield types, settings

    /**
     * A copy of the export where field_pair holds the given subfield storage
     * settings (`first` and `second` are merged over the fixture's).
     *
     * @param array<string,array<string,mixed>> $storage subfield => storage settings
     * @param array<string,array<string,mixed>> $instance subfield => instance settings
     */
    private function exportWithPair(array $storage, array $instance = []): string
    {
        $dir = $this->copyConfig();
        $file = "{$dir}/field.storage.paragraph.field_pair.yml";
        $data = Yaml::parseFile($file);
        self::assertIsArray($data);
        foreach ($storage as $part => $settings) {
            $data['settings']['storage'][$part] = $settings + $data['settings']['storage'][$part];
        }
        file_put_contents($file, \Parisek\DefinitionKit\Drupal\DrupalYaml::dump($data));
        $file = "{$dir}/field.field.paragraph.pair.field_pair.yml";
        $data = Yaml::parseFile($file);
        self::assertIsArray($data);
        foreach ($instance as $part => $settings) {
            $data['settings'][$part] = $settings + $data['settings'][$part];
        }
        file_put_contents($file, \Parisek\DefinitionKit\Drupal\DrupalYaml::dump($data));

        return $dir;
    }

    /** @return iterable<string, array{string, string}> */
    public static function subfieldTypes(): iterable
    {
        // [subfield storage type, the pin the migration writes, or '' for none]
        foreach ([
            'string' => '', 'email' => 'email', 'telephone' => 'telephone', 'uri' => 'uri', 'integer' => 'integer',
            'float' => '', 'numeric' => 'numeric', 'boolean' => '', 'datetime_iso8601' => '', 'text' => '',
        ] as $type => $pin) {
            yield $type => [$type, $pin];
        }
    }

    #[Test]
    #[DataProvider('subfieldTypes')]
    public function every_double_field_subfield_type_survives_migrate_lint_and_a_fresh_generate(string $type, string $pin): void
    {
        $dir = $this->exportWithPair(['first' => ['type' => $type], 'second' => ['type' => $type]]);
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('pair');

        foreach (['first', 'second'] as $part) {
            self::assertSame('' === $pin ? null : $pin, $fields['pair']['fields'][$part]['drupal']['storage'] ?? null, "{$part}: pin");
        }
        $definition = ['name' => 'Pair', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $fields];
        $result = (new DrupalDriftLinter($config, new DrupalSettings()))->lint($definition, 'pair');
        self::assertSame(DrupalDriftResult::OK, $result->status, implode("\n", $result->findings));

        $target = $this->tempDir();
        $plan = $this->plan(['pair' => $definition], $target, null);
        self::assertFalse($plan->refused());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $made = DrupalConfig::fromDirectory($target)->fields('pair')['field_pair'];
        self::assertSame([$type, $type], [$made->settings['storage']['first']['type'], $made->settings['storage']['second']['type']]);

        // Into its own export: no change.
        self::assertSame([], $this->plan(['pair' => $definition], $dir, $config)->changedNames());

        // Without its pin a part would be created as the canonical subfield type, so the lint calls it drift.
        if ('' !== $pin) {
            $unpinned = $definition;
            unset($unpinned['fields']['pair']['fields']['first']['drupal']['storage']);
            $drift = (new DrupalDriftLinter($config, new DrupalSettings()))->lint($unpinned, 'pair');
            self::assertSame(DrupalDriftResult::DRIFT, $drift->status, 'a missing pin on ' . $type);
            self::assertStringContainsString("first is a {$type} subfield in Drupal; the definition type", implode("\n", $drift->findings));
            self::assertStringContainsString("pin drupal.storage: {$type}", implode("\n", $drift->findings));
        }
    }

    #[Test]
    public function a_wrong_subfield_pin_is_drift_and_a_generate_refuses_to_change_the_subfield(): void
    {
        $dir = $this->exportWithPair(['first' => ['type' => 'integer']]);
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('pair');
        $fields['pair']['fields']['first']['drupal']['storage'] = 'numeric';
        $definition = ['name' => 'Pair', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $fields];

        $result = (new DrupalDriftLinter($config, new DrupalSettings()))->lint($definition, 'pair');
        self::assertSame(DrupalDriftResult::DRIFT, $result->status);
        self::assertStringContainsString('first is a integer subfield in Drupal; the definition pins numeric', implode("\n", $result->findings));
        self::assertTrue($this->plan(['pair' => $definition], $dir, $config)->refused());
    }

    #[Test]
    public function subfield_storage_settings_and_limits_go_through_migrate_lint_and_generate(): void
    {
        $dir = $this->exportWithPair(
            ['first' => ['type' => 'numeric', 'precision' => 12, 'scale' => 4], 'second' => ['type' => 'string', 'maxlength' => 100]],
            ['first' => ['min' => 1, 'max' => 9]],
        );
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('pair');

        self::assertSame(['precision' => 12, 'scale' => 4, 'min' => 1, 'max' => 9], $fields['pair']['fields']['first']['drupal']['settings']);
        self::assertSame(['maxlength' => 100], $fields['pair']['fields']['second']['drupal']['settings']);
        $definition = ['name' => 'Pair', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $fields];
        $linter = new DrupalDriftLinter($config, new DrupalSettings());
        self::assertSame(DrupalDriftResult::OK, $linter->lint($definition, 'pair')->status);

        // Drift: the definition forgets the scale, names an unknown key.
        $broken = $definition;
        $broken['fields']['pair']['fields']['first']['drupal']['settings']['scale'] = 2;
        $broken['fields']['pair']['fields']['second']['drupal']['settings']['colour'] = 'red';
        $findings = implode("\n", $linter->lint($broken, 'pair')->findings);
        self::assertStringContainsString('first: setting scale is 4 in Drupal; the definition expects 2', $findings);
        self::assertStringContainsString('second: drupal.settings.colour is not a setting of double_field', $findings);

        // A fresh generate rebuilds them; into the export the plan has no change.
        $target = $this->tempDir();
        $plan = $this->plan(['pair' => $definition], $target, null);
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $made = DrupalConfig::fromDirectory($target)->fields('pair')['field_pair'];
        self::assertSame(['numeric', 12, 4], [$made->settings['storage']['first']['type'], $made->settings['storage']['first']['precision'], $made->settings['storage']['first']['scale']]);
        self::assertSame(100, $made->settings['storage']['second']['maxlength']);
        self::assertSame([1, 9], [$made->settings['first']['min'], $made->settings['first']['max']]);
        self::assertSame([], $this->plan(['pair' => $definition], $dir, $config)->changedNames());

        // A precision change on existing data is refused, a limit change is an update.
        $change = $definition;
        $change['fields']['pair']['fields']['first']['drupal']['settings']['precision'] = 14;
        self::assertTrue($this->plan(['pair' => $change], $dir, $config)->refused());
        $limit = $definition;
        $limit['fields']['pair']['fields']['first']['drupal']['settings']['max'] = 20;
        $updated = $this->plan(['pair' => $limit], $dir, $config);
        self::assertFalse($updated->refused());
        self::assertSame(['field.field.paragraph.pair.field_pair'], $updated->changedNames());
    }

    #[Test]
    public function address_and_language_field_settings_go_through_migrate_lint_and_generate(): void
    {
        $dir = $this->copyConfig();
        $edit = static function (string $file, callable $change): void {
            $data = Yaml::parseFile($file);
            file_put_contents($file, \Parisek\DefinitionKit\Drupal\DrupalYaml::dump($change($data)));
        };
        $edit("{$dir}/field.field.paragraph.company.field_address.yml", static function (array $d): array {
            $d['settings']['available_countries'] = ['CZ' => 'CZ'];
            $d['settings']['langcode_override'] = 'cs';

            return $d;
        });
        $edit("{$dir}/field.storage.paragraph.field_langcode.yml", static function (array $d): array {
            $d['settings']['included_languages'] = ['cs' => 'cs', 'en' => 'en'];
            $d['settings']['groups'] = 'site';

            return $d;
        });
        $config = DrupalConfig::fromDirectory($dir);
        $settings = new DrupalSettings();
        $company = (new DrupalParagraphReader($config, $settings))->read('company');
        $content = (new DrupalParagraphReader($config, $settings))->read('content');

        self::assertSame(['available_countries', 'langcode_override', 'field_overrides'], array_keys($company['address']['drupal']['settings']));
        self::assertSame(['CZ' => 'CZ'], $company['address']['drupal']['settings']['available_countries']);
        self::assertSame('cs', $company['address']['drupal']['settings']['langcode_override']);
        self::assertSame(['language_range', 'included_languages', 'groups'], array_keys($content['advanced']['fields']['langcode']['drupal']['settings']));
        self::assertArrayNotHasKey('settings', $company['branches']['drupal'], 'defaults are not written');

        $definitions = [
            'company' => ['name' => 'Company', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/company/fields', 'fields' => $company],
            'content' => ['name' => 'Content', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/content/fields', 'fields' => $content],
        ];
        $linter = new DrupalDriftLinter($config, $settings);
        foreach ($definitions as $slug => $definition) {
            self::assertSame(DrupalDriftResult::OK, $linter->lint($definition, $slug)->status, $slug);
        }

        // Drift in both directions.
        $drift = $definitions['company'];
        unset($drift['fields']['address']['drupal']['settings']['available_countries']);
        self::assertStringContainsString('setting available_countries is {"CZ":"CZ"} in Drupal; the definition expects', implode("\n", $linter->lint($drift, 'company')->findings));
        $drift = $definitions['content'];
        $drift['fields']['advanced']['fields']['langcode']['drupal']['settings']['included_languages'] = ['de' => 'de'];
        self::assertStringContainsString('setting included_languages', implode("\n", $linter->lint($drift, 'content')->findings));
        $unknown = $definitions['company'];
        $unknown['fields']['branches']['drupal']['settings'] = ['colour' => 'red'];
        self::assertStringContainsString('drupal.settings.colour is not a setting of address', implode("\n", $linter->lint($unknown, 'company')->findings));

        // Into its own export: no change. From nothing: the settings are rebuilt.
        self::assertSame([], $this->plan($definitions, $dir, $config)->changedNames());
        $target = $this->tempDir();
        $plan = $this->plan($definitions, $target, null);
        self::assertFalse($plan->refused());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($target));
        $generated = DrupalConfig::fromDirectory($target);
        $address = $generated->fields('company')['field_address'];
        self::assertSame(['CZ' => 'CZ'], $address->settings['available_countries']);
        self::assertSame('cs', $address->settings['langcode_override']);
        self::assertSame('optional', $address->settings['field_overrides']['locality']['override']);
        $language = $generated->fields('content')['field_langcode'];
        self::assertSame(['cs' => 'cs', 'en' => 'en'], $language->settings['included_languages']);
        self::assertSame('site', $language->settings['groups']);
        foreach ($definitions as $slug => $definition) {
            self::assertSame(DrupalDriftResult::OK, (new DrupalDriftLinter($generated, $settings))->lint($definition, $slug)->status, $slug);
        }

        // The definition is the truth: a changed setting is an UPDATE of the key it owns.
        $changed = $definitions;
        $changed['company']['fields']['address']['drupal']['settings']['available_countries'] = ['CZ' => 'CZ', 'SK' => 'SK'];
        $changed['content']['fields']['advanced']['fields']['langcode']['drupal']['settings']['groups'] = 'all';
        $updated = $this->plan($changed, $dir, $config);
        self::assertFalse($updated->refused());
        self::assertEqualsCanonicalizing(['field.field.paragraph.company.field_address', 'field.storage.paragraph.field_langcode'], $updated->changedNames());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function applicableSettings(): iterable
    {
        yield 'string' => ['string', ['maxlength']];
        yield 'telephone' => ['telephone', ['maxlength']];
        yield 'email' => ['email', []];
        yield 'uri' => ['uri', []];
        yield 'text' => ['text', []];
        yield 'integer' => ['integer', ['min', 'max']];
        yield 'float' => ['float', ['min', 'max']];
        yield 'numeric' => ['numeric', ['precision', 'scale', 'min', 'max']];
        yield 'boolean' => ['boolean', ['on_label', 'off_label']];
        yield 'datetime_iso8601' => ['datetime_iso8601', ['datetime_type']];
    }

    /**
     * @param list<string> $applies the settings that mean something for this subfield type
     */
    #[Test]
    #[DataProvider('applicableSettings')]
    public function a_stale_setting_of_another_subfield_type_is_not_migrated_linted_or_generated(string $type, array $applies): void
    {
        // Drupal keeps the whole settings array when a subfield type changes.
        $stale = ['maxlength' => 100, 'precision' => 12, 'scale' => 4, 'datetime_type' => 'date'];
        $dir = $this->exportWithPair(
            ['first' => ['type' => $type] + $stale, 'second' => ['type' => 'boolean']],
            ['first' => ['min' => 1, 'max' => 9, 'on_label' => 'Yes', 'off_label' => 'No']],
        );
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('pair');

        $kept = array_keys($fields['pair']['fields']['first']['drupal']['settings'] ?? []);
        sort($kept);
        $expected = $applies;
        sort($expected);
        self::assertSame($expected, $kept, "settings migrated for a {$type} part");

        $definition = ['name' => 'Pair', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $fields];
        $result = (new DrupalDriftLinter($config, new DrupalSettings()))->lint($definition, 'pair');
        self::assertSame(DrupalDriftResult::OK, $result->status, implode("\n", $result->findings));

        // A definition without those settings (the author never set them) is not drift either, and generate leaves the export alone.
        $bare = $definition;
        foreach ($applies as $key) {
            unset($bare['fields']['pair']['fields']['first']['drupal']['settings'][$key]);
        }
        $bareDrift = implode("\n", (new DrupalDriftLinter($config, new DrupalSettings()))->lint($bare, 'pair')->findings);
        foreach (array_diff(['maxlength', 'precision', 'scale', 'datetime_type', 'min', 'max', 'on_label', 'off_label'], $applies) as $other) {
            self::assertStringNotContainsString("setting {$other} ", $bareDrift, "{$other} does not apply to {$type}");
        }
        $plan = $this->plan(['pair' => $definition], $dir, $config);
        self::assertFalse($plan->refused());
        self::assertSame([], $plan->changedNames());
    }

    #[Test]
    public function the_legacy_address_fields_setting_reads_as_hidden_overrides(): void
    {
        $dir = $this->copyConfig();
        $file = "{$dir}/field.field.paragraph.company.field_address.yml";
        $data = Yaml::parseFile($file);
        self::assertIsArray($data);
        // The module reads `fields` first and ignores field_overrides when it is set.
        $data['settings']['fields'] = ['locality', 'postalCode', 'countryCode'];
        file_put_contents($file, \Parisek\DefinitionKit\Drupal\DrupalYaml::dump($data));
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('company');

        $overrides = $fields['address']['drupal']['settings']['field_overrides'];
        self::assertSame(
            ['administrativeArea', 'dependentLocality', 'sortingCode', 'addressLine1', 'addressLine2', 'addressLine3', 'organization', 'givenName', 'additionalName', 'familyName'],
            array_keys($overrides),
        );
        self::assertSame(['override' => 'hidden'], $overrides['addressLine1'], 'field_overrides of the export is ignored, as in the module');
        self::assertArrayNotHasKey('fields', $fields['address']['drupal']['settings']);

        $definition = ['name' => 'Company', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/company/fields', 'fields' => $fields];
        $linter = new DrupalDriftLinter($config, new DrupalSettings());
        self::assertSame(DrupalDriftResult::OK, $linter->lint($definition, 'company')->status);
        self::assertSame([], $this->plan(['company' => $definition], $dir, $config)->changedNames(), 'the legacy form is equivalent');

        // Fresh generate writes the modern form.
        $target = $this->tempDir();
        (new DrupalConfigWriter())->write($this->plan(['company' => $definition], $target, null), new ConfigStore($target));
        $made = DrupalConfig::fromDirectory($target)->fields('company')['field_address'];
        self::assertSame([], $made->settings['fields']);
        self::assertSame($overrides, $made->settings['field_overrides']);
        self::assertSame(DrupalDriftResult::OK, (new DrupalDriftLinter(DrupalConfig::fromDirectory($target), new DrupalSettings()))->lint($definition, 'company')->status);

        // A changed definition updates the override and empties the legacy key, which would win otherwise.
        $changed = $definition;
        unset($changed['fields']['address']['drupal']['settings']['field_overrides']['addressLine1']);
        $plan = $this->plan(['company' => $changed], $dir, $config);
        self::assertSame(['field.field.paragraph.company.field_address'], $plan->changedNames());
        (new DrupalConfigWriter())->write($plan, new ConfigStore($dir));
        $after = DrupalConfig::fromDirectory($dir)->fields('company')['field_address'];
        self::assertSame([], $after->settings['fields']);
        self::assertArrayNotHasKey('addressLine1', $after->settings['field_overrides']);
        self::assertSame(DrupalDriftResult::OK, (new DrupalDriftLinter(DrupalConfig::fromDirectory($dir), new DrupalSettings()))->lint($changed, 'company')->status);
    }

    #[Test]
    public function boolean_part_labels_are_settings_of_a_boolean_part_only(): void
    {
        $dir = $this->exportWithPair([], ['first' => ['on_label' => 'Zapnuto', 'off_label' => 'Vypnuto'], 'second' => ['on_label' => 'Yes', 'off_label' => 'No']]);
        $config = DrupalConfig::fromDirectory($dir);
        $fields = (new DrupalParagraphReader($config, new DrupalSettings()))->read('pair');

        self::assertArrayNotHasKey('settings', $fields['pair']['fields']['first']['drupal'] ?? [], 'the labels of an integer part mean nothing');
        self::assertSame(['on_label' => 'Yes', 'off_label' => 'No'], $fields['pair']['fields']['second']['drupal']['settings']);
        $definition = ['name' => 'Pair', 'category' => 'Block', 'kind' => 'block', 'drupal' => '/admin/structure/paragraphs_type/pair/fields', 'fields' => $fields];
        $linter = new DrupalDriftLinter($config, new DrupalSettings());
        self::assertSame(DrupalDriftResult::OK, $linter->lint($definition, 'pair')->status);

        $drift = $definition;
        $drift['fields']['pair']['fields']['second']['drupal']['settings']['on_label'] = 'Active';
        self::assertStringContainsString('second: setting on_label is "Yes" in Drupal; the definition expects "Active"', implode("\n", $linter->lint($drift, 'pair')->findings));
        $misplaced = $definition;
        $misplaced['fields']['pair']['fields']['first']['drupal']['settings'] = ['on_label' => 'x'];
        self::assertStringContainsString('first: drupal.settings.on_label is not a setting of double_field', implode("\n", $linter->lint($misplaced, 'pair')->findings));

        $target = $this->tempDir();
        (new DrupalConfigWriter())->write($this->plan(['pair' => $definition], $target, null), new ConfigStore($target));
        $made = DrupalConfig::fromDirectory($target)->fields('pair')['field_pair'];
        self::assertSame(['Yes', 'No'], [$made->settings['second']['on_label'], $made->settings['second']['off_label']]);
        self::assertSame(['On', 'Off'], [$made->settings['first']['on_label'], $made->settings['first']['off_label']], 'the module default');
        self::assertSame([], $this->plan(['pair' => $definition], $dir, $config)->changedNames());

        $plan = $this->plan(['pair' => $drift], $dir, $config);
        self::assertSame(['field.field.paragraph.pair.field_pair'], $plan->changedNames());
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
