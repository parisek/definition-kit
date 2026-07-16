<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\AcfJsonWriter;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Generator\BlockJsonWriter;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Lint\DriftLinter;
use Symfony\Component\Yaml\Yaml;

final class DriftLinterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/drift-linter-test-' . uniqid('', true) . '/demo-card';
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
        rmdir(dirname($this->dir));
    }

    /** @param array<string,mixed> $tree */
    private function writeDefinition(array $tree): void
    {
        file_put_contents("{$this->dir}/demo-card.yaml", Yaml::dump($tree, 10, 2));
    }

    /** @param array<string,mixed> $tree */
    private function generateCleanAcfAndBlock(array $tree, int $modifiedAt): void
    {
        $fieldGroup = (new FieldsGenerator())->generate($tree, 'demo-card', $modifiedAt);
        (new AcfJsonWriter())->write($fieldGroup, "{$this->dir}/acf.json");
        $block = (new BlockJsonGenerator())->generate($tree, 'demo-card');
        (new BlockJsonWriter())->write($block, "{$this->dir}/block.json");
    }

    /** @return array<string,mixed> */
    private function minimalTree(): array
    {
        return ['name' => 'Demo card', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
    }

    public function test_component_whose_acf_json_equals_generate_of_definition_is_clean(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $result = (new DriftLinter())->lint($this->dir);

        self::assertTrue($result->clean, implode("\n", [...$result->acfDrift, ...$result->blockDrift]));
        self::assertNull($result->error);
    }

    public function test_hand_mutated_acf_json_label_is_reported_as_drift(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $raw = file_get_contents("{$this->dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        $acf['fields'][0]['label'] = 'Hand-edited label';
        file_put_contents("{$this->dir}/acf.json", json_encode($acf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
        self::assertNotEmpty($result->acfDrift);
        self::assertStringContainsString('label', implode(' ', $result->acfDrift));
    }

    public function test_stray_field_added_directly_to_acf_json_is_reported_as_drift(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $raw = file_get_contents("{$this->dir}/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        $acf['fields'][] = ['key' => 'field_demo-card_stray', 'label' => 'Stray', 'name' => 'stray', 'type' => 'text'];
        file_put_contents("{$this->dir}/acf.json", json_encode($acf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
    }

    public function test_committed_modified_timestamp_never_causes_drift_on_its_own(): void
    {
        // The generator would normally stamp `modified` with the CURRENT
        // time; DriftLinter must inject the committed file's own value
        // instead, so simply re-linting later (no content change) stays clean.
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $result = (new DriftLinter())->lint($this->dir);
        self::assertTrue($result->clean);
    }

    public function test_hand_edited_block_json_example_attributes_data_never_causes_drift(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $raw = file_get_contents("{$this->dir}/block.json");
        self::assertIsString($raw);
        $block = json_decode($raw, true);
        $block['example']['attributes']['data'] = ['title' => 'Whatever the inserter-preview skill authored'];
        file_put_contents("{$this->dir}/block.json", json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = (new DriftLinter())->lint($this->dir);

        self::assertTrue($result->clean, implode("\n", $result->blockDrift));
    }

    public function test_hand_edited_block_json_non_example_prop_is_reported_as_drift(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $this->generateCleanAcfAndBlock($tree, 1_700_000_000);

        $raw = file_get_contents("{$this->dir}/block.json");
        self::assertIsString($raw);
        $block = json_decode($raw, true);
        $block['category'] = 'widgets';
        file_put_contents("{$this->dir}/block.json", json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
        self::assertNotEmpty($result->blockDrift);
    }

    public function test_missing_block_json_is_not_a_failure_acf_only_component(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $fieldGroup = (new FieldsGenerator())->generate($tree, 'demo-card', 1_700_000_000);
        (new AcfJsonWriter())->write($fieldGroup, "{$this->dir}/acf.json");
        // no block.json written at all

        $result = (new DriftLinter())->lint($this->dir);

        self::assertTrue($result->clean);
        self::assertSame([], $result->blockDrift);
    }

    public function test_missing_acf_json_with_a_definition_present_is_an_error(): void
    {
        $this->writeDefinition($this->minimalTree());

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
        self::assertStringContainsString('acf.json missing', (string) $result->error);
    }

    public function test_invalid_definition_is_reported_as_an_error_not_a_crash(): void
    {
        file_put_contents("{$this->dir}/demo-card.yaml", "name: Demo card\n"); // missing required `fields`
        file_put_contents("{$this->dir}/acf.json", '{}');

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
        self::assertStringContainsString('invalid definition', (string) $result->error);
    }

    public function test_real_corpus_clean_service_feature_fixture_is_clean(): void
    {
        $dir = __DIR__ . '/../fixtures/drift-lint/clean/service-feature';
        $result = (new DriftLinter())->lint($dir);
        self::assertTrue($result->clean, implode("\n", [...$result->acfDrift, ...$result->blockDrift]));
    }

    public function test_real_corpus_hand_mutated_service_feature_reports_the_wpml_preference_drift(): void
    {
        $dir = __DIR__ . '/../fixtures/drift-lint/hand-mutated/service-feature';
        $result = (new DriftLinter())->lint($dir);
        self::assertFalse($result->clean);
        self::assertStringContainsString('wpml_cf_preferences', implode(' ', $result->acfDrift));
    }

    public function test_real_corpus_zig_zag_legacy_image_sentinel_is_allowlisted_to_clean(): void
    {
        $dir = __DIR__ . '/../fixtures/drift-lint/legacy-residual/zig-zag';
        $result = (new DriftLinter())->lint($dir);
        self::assertTrue($result->clean, 'Expected the documented legacy image-sentinel residual to be allowlisted: '
            . implode("\n", $result->acfDrift));
    }

    public function test_real_corpus_reference_detail_legacy_minimal_export_is_allowlisted_to_clean(): void
    {
        $dir = __DIR__ . '/../fixtures/drift-lint/legacy-minimal-export/reference-detail';
        $result = (new DriftLinter())->lint($dir);
        self::assertTrue($result->clean, 'Expected the legacy-minimal-export prop set to be allowlisted for reference-detail: '
            . implode("\n", $result->acfDrift));
    }

    public function test_legacy_minimal_export_missing_prop_with_authored_non_default_value_is_real_drift(): void
    {
        // Same shape as the benign legacy-minimal-export residual above, EXCEPT
        // the definition now authors a non-default `placeholder` on a select
        // field — the committed acf.json (still a legacy-minimal export) never
        // picked that up. That must surface as real drift: the allowlist's
        // legacy-minimal-export rule may only excuse a missing prop whose
        // generated/expected value equals the type's baseline default, never
        // a missing prop carrying real authored content.
        $tree = [
            'name' => 'Reference - detail',
            'key' => 'group_reference_detail',
            'fields' => [
                'spacing' => [
                    'type' => 'select',
                    'label' => 'Spacing',
                    'placeholder' => 'Search',
                    'options' => ['default' => 'Standardni'],
                    'key' => 'field_reference_detail_spacing',
                ],
            ],
        ];
        $this->dir = sys_get_temp_dir() . '/drift-linter-test-' . uniqid('', true) . '/reference-detail';
        mkdir($this->dir, 0777, true);
        file_put_contents("{$this->dir}/reference-detail.yaml", Yaml::dump($tree, 10, 2));

        // Legacy-minimal export: no `placeholder` key at all on the field —
        // the exact shape the legacy-minimal-export allowlist rule targets.
        $acf = [
            'key' => 'group_reference_detail',
            'title' => 'Reference - detail',
            'fields' => [[
                'key' => 'field_reference_detail_spacing',
                'label' => 'Spacing',
                'name' => 'spacing',
                'type' => 'select',
                'instructions' => '',
                'required' => 0,
                'choices' => ['default' => 'Standardni'],
                'default_value' => '',
                'allow_null' => 0,
                'multiple' => 0,
                'ui' => 0,
                'return_format' => 'value',
                // no `placeholder` key — legacy minimal export omission
            ]],
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/reference-detail']]],
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => 0,
            'modified' => 1_700_000_000,
        ];
        file_put_contents("{$this->dir}/acf.json", json_encode($acf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean, 'A missing prop carrying an authored non-default value must NOT be allowlisted away as benign legacy omission');
        self::assertStringContainsString('placeholder', implode(' ', $result->acfDrift));
    }

    public function test_malformed_block_json_is_reported_as_drift_not_silent_clean(): void
    {
        $tree = $this->minimalTree();
        $this->writeDefinition($tree);
        $fieldGroup = (new FieldsGenerator())->generate($tree, 'demo-card', 1_700_000_000);
        (new AcfJsonWriter())->write($fieldGroup, "{$this->dir}/acf.json");
        // Present but malformed block.json — a bare JSON string, not an object.
        file_put_contents("{$this->dir}/block.json", '"not an object"');

        $result = (new DriftLinter())->lint($this->dir);

        self::assertFalse($result->clean);
        self::assertNotNull($result->error);
        self::assertStringContainsString('block.json', (string) $result->error);
    }
}
