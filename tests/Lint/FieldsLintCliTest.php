<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Generator\AcfJsonWriter;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Symfony\Component\Yaml\Yaml;

final class FieldsLintCliTest extends TestCase
{
    private string $bin;
    private string $root;

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../../bin/fields-lint';
        $this->root = sys_get_temp_dir() . '/fields-lint-cli-test-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function makeCleanComponent(string $slug): void
    {
        $dir = "{$this->root}/{$slug}";
        mkdir($dir, 0777, true);
        $tree = ['name' => ucfirst($slug), 'category' => 'Content', 'fields' => ['title' => ['type' => 'text', 'label' => 'Title']]];
        file_put_contents("{$dir}/{$slug}.yaml", Yaml::dump($tree, 10, 2));
        $fieldGroup = (new FieldsGenerator())->generate($tree, $slug, 1_700_000_000);
        self::assertNotNull($fieldGroup);
        (new AcfJsonWriter())->write($fieldGroup, "{$dir}/acf.json");
    }

    public function test_single_clean_component_exits_zero_and_prints_ok(): void
    {
        $this->makeCleanComponent('demo-card');
        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . escapeshellarg("{$this->root}/demo-card") . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   demo-card', implode("\n", $output));
    }

    public function test_contract_only_skips_the_projection_check(): void
    {
        // A CMS-agnostic skeleton has no acf.json — tailwind-base generates
        // its projections downstream — so every component reports "acf.json
        // missing" and buries the input-contract half, which is perfectly
        // meaningful there.
        $dir = "{$this->root}/divider";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/divider.yaml", Yaml::dump([
            'name' => 'Divider',
            'category' => 'Content',
            'fields' => [
                // A projecting field, so the projection half has something to
                // miss. (A definition that projects nothing needs no acf.json
                // and passes drift on its own.)
                'title' => ['type' => 'text', 'label' => 'Title', 'role' => 'field'],
                'inner' => ['role' => 'parent'],
            ],
        ], 10, 2));
        file_put_contents("{$dir}/divider.twig", '{{ content.title }}{{ content.inner }}');

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --contract-only '
            . escapeshellarg($dir) . ' 2>&1', $output, $exitCode);

        $text = implode("\n", $output);
        self::assertSame(0, $exitCode, $text);
        self::assertStringNotContainsString('acf.json missing', $text);
        self::assertStringContainsString('OK        divider', $text);

        // Without the flag the same component fails on the projection half —
        // the flag suppresses a check, it does not change one.
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' '
            . escapeshellarg($dir) . ' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('acf.json missing', implode("\n", $output));
    }

    public function test_a_broken_forwarded_shape_exits_nonzero(): void
    {
        // The gate this feature exists for. A reference nobody can follow used
        // to be a note, and notes do not reach the exit code.
        $dir = "{$this->root}/header";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/header.yaml", Yaml::dump([
            'name' => 'Header',
            'category' => 'Content',
            'fields' => [
                'menu' => ['type' => 'repeater', 'label' => 'Menu', 'role' => 'parent', 'of' => 'component:nope#items'],
            ],
        ], 10, 2));
        // Reads nothing undeclared, so the ONLY thing wrong is the reference.
        file_put_contents("{$dir}/header.twig", '{{ content.menu|length }}');

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --contract-only '
            . escapeshellarg($dir) . ' 2>&1', $output, $exitCode);

        $text = implode("\n", $output);
        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('BROKEN REF header', $text);
        self::assertStringContainsString('broken `of:` target', $text);
    }

    public function test_single_drifted_component_exits_one_and_prints_drift(): void
    {
        $this->makeCleanComponent('demo-card');
        $raw = file_get_contents("{$this->root}/demo-card/acf.json");
        self::assertIsString($raw);
        $acf = json_decode($raw, true);
        $acf['fields'][0]['label'] = 'Hand-edited';
        file_put_contents("{$this->root}/demo-card/acf.json", json_encode($acf));

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . escapeshellarg("{$this->root}/demo-card") . ' 2>&1', $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('DRIFT demo-card', implode("\n", $output));
    }

    public function test_batch_root_mode_skips_components_without_a_definition_yaml(): void
    {
        $this->makeCleanComponent('demo-card');
        mkdir("{$this->root}/legacy-not-yet-migrated", 0777, true);
        file_put_contents("{$this->root}/legacy-not-yet-migrated/acf.json", '{}');

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --root=' . escapeshellarg($this->root) . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   demo-card', $joined);
        self::assertStringContainsString('SKIP legacy-not-yet-migrated', $joined);
        self::assertStringContainsString('2 component(s), 0 failed, 1 skipped', $joined);
    }

    public function test_batch_root_mode_one_bad_component_does_not_abort_the_rest(): void
    {
        $this->makeCleanComponent('demo-card');
        mkdir("{$this->root}/broken", 0777, true);
        file_put_contents("{$this->root}/broken/broken.yaml", "name: Broken\ncategory: Content\n"); // missing required `fields`

        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' --root=' . escapeshellarg($this->root) . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('OK   demo-card', $joined);
        self::assertStringContainsString('FAIL broken', $joined);
    }

    private function makeUnprojectedComponent(string $slug, ?string $kind): string
    {
        $dir = "{$this->root}/{$slug}";
        mkdir($dir, 0777, true);
        $tree = ['name' => ucfirst($slug), 'category' => 'Content'];
        if (null !== $kind) {
            $tree['kind'] = $kind;
        }
        $tree['fields'] = ['title' => ['type' => 'text', 'label' => 'Title', 'role' => 'field']];
        file_put_contents("{$dir}/{$slug}.yaml", Yaml::dump($tree, 10, 2));
        file_put_contents("{$dir}/{$slug}.twig", '{{ content.title }}');
        return $dir;
    }

    /** @return array{int, string} */
    private function runLint(string ...$args): array
    {
        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $exitCode);
        return [(int) $exitCode, implode("\n", $output)];
    }

    /** @return list<array{string}> */
    public static function nonBlockKindProvider(): array
    {
        return [['section'], ['element'], ['part'], ['utility']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonBlockKindProvider')]
    public function test_a_non_block_kind_without_acf_json_is_skipped(string $kind): void
    {
        $dir = $this->makeUnprojectedComponent('alert', $kind);

        [$exitCode, $text] = $this->runLint($dir);

        self::assertSame(0, $exitCode, $text);
        self::assertStringContainsString("SKIP alert: kind {$kind} has no CMS projection", $text);
        self::assertStringNotContainsString('acf.json missing', $text);
        self::assertStringContainsString('1 component(s), 0 failed, 1 skipped', $text);
    }

    public function test_a_block_without_acf_json_still_fails(): void
    {
        $dir = $this->makeUnprojectedComponent('hero', 'block');

        [$exitCode, $text] = $this->runLint($dir);

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('FAIL hero: acf.json missing', $text);
    }

    public function test_a_definition_without_kind_still_fails(): void
    {
        // No `kind` means the backfill has not reached the file, not "not a block".
        $dir = $this->makeUnprojectedComponent('legacy', null);

        [$exitCode, $text] = $this->runLint($dir);

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('FAIL legacy: acf.json missing', $text);
    }

    public function test_root_mode_counts_skips_apart_from_failures(): void
    {
        $this->makeCleanComponent('demo-card');
        $this->makeUnprojectedComponent('alert', 'element');
        $this->makeUnprojectedComponent('teaser', 'part');
        $this->makeUnprojectedComponent('hero', 'block');

        [$exitCode, $text] = $this->runLint('--root=' . $this->root);

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('SKIP alert: kind element has no CMS projection', $text);
        self::assertStringContainsString('SKIP teaser: kind part has no CMS projection', $text);
        self::assertStringContainsString('FAIL hero: acf.json missing', $text);
        self::assertStringContainsString('4 component(s), 1 failed, 2 skipped', $text);
    }

    public function test_root_mode_with_only_skips_exits_zero(): void
    {
        $this->makeCleanComponent('demo-card');
        $this->makeUnprojectedComponent('alert', 'element');

        [$exitCode, $text] = $this->runLint('--root=' . $this->root);

        self::assertSame(0, $exitCode, $text);
        self::assertStringContainsString('2 component(s), 0 failed, 1 skipped', $text);
    }

    public function test_a_non_block_kind_with_acf_json_is_still_drift_checked(): void
    {
        // A committed acf.json on a non-block kind is still compared, so a
        // hand edit or a stale definition stays visible. fields-generate no
        // longer rewrites that file (#72), so the fix line must not send the
        // user to a command that cannot change it.
        $this->makeCleanComponent('teaser');
        $yaml = "{$this->root}/teaser/teaser.yaml";
        $tree = Yaml::parseFile($yaml);
        self::assertIsArray($tree);
        file_put_contents($yaml, Yaml::dump(['kind' => 'part'] + $tree, 10, 2));

        [$exitCode, $text] = $this->runLint("{$this->root}/teaser");
        self::assertSame(0, $exitCode, $text);
        self::assertStringContainsString('OK   teaser', $text);

        $acf = json_decode((string) file_get_contents("{$this->root}/teaser/acf.json"), true);
        self::assertIsArray($acf);
        $acf['fields'][0]['label'] = 'Hand-edited';
        file_put_contents("{$this->root}/teaser/acf.json", json_encode($acf));

        [$exitCode, $text] = $this->runLint("{$this->root}/teaser");
        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('DRIFT teaser', $text);
        self::assertStringNotContainsString('fix: vendor/bin/fields-generate teaser', $text);
        self::assertStringContainsString('fields-generate does not write acf.json for kind part', $text);
    }

    public function test_a_block_with_drift_still_points_at_fields_generate(): void
    {
        $this->makeCleanComponent('hero');
        $acf = json_decode((string) file_get_contents("{$this->root}/hero/acf.json"), true);
        self::assertIsArray($acf);
        $acf['fields'][0]['label'] = 'Hand-edited';
        file_put_contents("{$this->root}/hero/acf.json", json_encode($acf));

        [$exitCode, $text] = $this->runLint("{$this->root}/hero");

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('fix: vendor/bin/fields-generate hero', $text);
    }

    public function test_a_non_block_kind_with_a_stale_block_json_still_fails(): void
    {
        $dir = $this->makeUnprojectedComponent('alert', 'element');
        file_put_contents("{$dir}/block.json", '{"name":"acf/alert"}');

        [$exitCode, $text] = $this->runLint($dir);

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('FAIL alert', $text);
        self::assertStringNotContainsString('SKIP alert', $text);
        // No acf.json either, and fields-generate will not write one for a
        // non-block kind (#72). The failure must name the stale block.json,
        // not send the user to a generator run that changes nothing.
        self::assertStringContainsString('block.json is present but the definition declares `kind: element`', $text);
        self::assertStringNotContainsString('run fields-generate', $text);
    }

    public function test_an_invalid_non_block_definition_is_not_skipped(): void
    {
        $dir = "{$this->root}/alert";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/alert.yaml", "name: Alert\ncategory: Content\nkind: element\nfields:\n  title:\n    type: nope\n    label: T\n");

        [$exitCode, $text] = $this->runLint($dir);

        self::assertSame(1, $exitCode, $text);
        self::assertStringContainsString('FAIL alert: invalid definition', $text);
    }

    public function test_contract_only_output_is_unchanged_for_a_non_block_kind(): void
    {
        $dir = $this->makeUnprojectedComponent('alert', 'element');

        [$exitCode, $text] = $this->runLint('--contract-only', $dir);

        self::assertSame(0, $exitCode, $text);
        self::assertStringNotContainsString('SKIP alert', $text);
        self::assertStringContainsString('1 component(s), 0 failed', $text);
        self::assertStringNotContainsString('skipped', $text);
    }
}
