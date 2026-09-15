<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator;

use Parisek\DefinitionKit\Generator\AcfJsonWriter;
use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Generator\BlockJsonWriter;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Lint\DriftLinter;
use Parisek\DefinitionKit\Migration\StructuralTypeRenamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `group`/`repeater` and `object`/`list` name the same shapes (#79), so the
 * generated acf.json and block.json must be byte-identical whichever name a
 * definition uses.
 */
final class StructuralTypeAliasEquivalenceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/structural-alias-test-' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        $base = __DIR__ . '/../fixtures/drift-lint';
        return [
            'service-feature' => ["{$base}/clean/service-feature/service-feature.yaml"],
            'reference-detail' => ["{$base}/legacy-minimal-export/reference-detail/reference-detail.yaml"],
            'zig-zag' => ["{$base}/legacy-residual/zig-zag/zig-zag.yaml"],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_fixture_generates_identical_bytes_under_either_name(string $yamlPath): void
    {
        $source = (string) file_get_contents($yamlPath);
        $renamed = (new StructuralTypeRenamer())->rename($source);
        self::assertGreaterThan(0, $renamed['renamed'], 'fixture must exercise an alias');

        $slug = basename($yamlPath, '.yaml');
        self::assertSame(
            $this->generateBytes((array) Yaml::parse($source), $slug, 'alias'),
            $this->generateBytes((array) Yaml::parse($renamed['source']), $slug, 'canonical'),
        );
    }

    public function test_in_memory_tree_with_layouts_and_non_projecting_containers_is_identical(): void
    {
        $tree = static fn (string $object, string $list): array => [
            'name' => 'Demo',
            'category' => 'Content',
            'kind' => 'block',
            'fields' => [
                'heading' => ['type' => $object, 'label' => 'Heading', 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Title'],
                ]],
                'rows' => ['type' => $list, 'label' => 'Rows', 'min' => 1, 'max' => 4, 'add_label' => 'Add row', 'fields' => [
                    'link' => ['type' => $object, 'label' => 'Link', 'fields' => ['url' => ['type' => 'link', 'label' => 'Url']]],
                ]],
                'passed' => ['type' => $list, 'role' => 'parent', 'fields' => ['t' => ['type' => 'text']]],
                'empty_after_filter' => ['type' => $object, 'label' => 'Empty', 'fields' => [
                    'q' => ['type' => 'text', 'role' => 'query'],
                ]],
                'sections' => ['type' => 'flexible_content', 'label' => 'Sections', 'layouts' => [
                    'hero' => ['label' => 'Hero', 'fields' => [
                        'cards' => ['type' => $list, 'label' => 'Cards', 'fields' => ['t' => ['type' => 'text', 'label' => 'T']]],
                    ]],
                ]],
            ],
        ];

        self::assertSame(
            $this->generateBytes($tree('group', 'repeater'), 'demo', 'alias'),
            $this->generateBytes($tree('object', 'list'), 'demo', 'canonical'),
        );
    }

    public function test_committed_projection_stays_clean_after_renaming_its_definition(): void
    {
        $source = __DIR__ . '/../fixtures/drift-lint/clean/service-feature';
        $dir = "{$this->tmp}/service-feature";
        mkdir($dir);
        foreach (['acf.json', 'block.json'] as $file) {
            copy("{$source}/{$file}", "{$dir}/{$file}");
        }
        $renamed = (new StructuralTypeRenamer())->rename((string) file_get_contents("{$source}/service-feature.yaml"));
        file_put_contents("{$dir}/service-feature.yaml", $renamed['source']);

        $result = (new DriftLinter())->lint($dir);

        self::assertNull($result->error);
        self::assertTrue($result->clean, implode("\n", [...$result->acfDrift, ...$result->blockDrift]));
    }

    /** @param array<mixed> $tree */
    private function generateBytes(array $tree, string $slug, string $label): string
    {
        $fieldGroup = (new FieldsGenerator())->generate($tree, $slug, 1_700_000_000);
        self::assertNotNull($fieldGroup);
        (new AcfJsonWriter())->write($fieldGroup, "{$this->tmp}/{$label}-acf.json");
        (new BlockJsonWriter())->write((new BlockJsonGenerator())->generate($tree, $slug), "{$this->tmp}/{$label}-block.json");

        return file_get_contents("{$this->tmp}/{$label}-acf.json") . "\n---\n" . file_get_contents("{$this->tmp}/{$label}-block.json");
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }
}
