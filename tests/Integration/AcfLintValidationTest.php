<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\AcfJsonSchema\Lint\AcfLinter;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Migration\AcfJsonReader;

/**
 * The end-to-end wiring the PR review demanded: definition-kit had NO
 * validation of its own generated output against `parisek/acf-json-schema`,
 * the ecosystem's own canonical ACF-shape validator. That gap is exactly
 * why findings C (layout display/location hardcoded) and D (schema looser
 * than the artifact it produces) shipped unnoticed — this generator's own
 * light structural output schema (`AcfJsonWriter`'s
 * `acf-field-group.output.schema.json`) never checked the result against
 * real ACF semantics.
 *
 * For every real-world migration fixture in this repo: read -> generate ->
 * lint the regenerated acf.json with `acf-lint`'s own validator class. A
 * projection that fails the ecosystem validator must fail this test (and,
 * wired the same way in CI, the build).
 */
final class AcfLintValidationTest extends TestCase
{
    /**
     * Finding 5 (round 3, MODERATE) — round 2 skipped `store-locator` here,
     * framing it as "two first-party packages disagreeing" (definition-kit
     * regenerating `wpml_cf_preferences: 2` for a `gallery` field vs.
     * `parisek/acf-json-schema` requiring `const 1`). Investigated on the
     * merits instead of accepting that framing:
     *
     *  - `parisek/acf-json-schema`'s `field-gallery.schema.json` /
     *    `field-image.schema.json` both hardcode `const: 1` — deliberate,
     *    matching this project's OWN documented doctrine
     *    (`.claude/rules/wordpress/gutenberg.md` § ACF Field Type Mapping:
     *    media fields are non-translatable, `wpml_cf_preferences: 1`).
     *  - definition-kit's `WpmlTranslatableMapper` treats every non-container
     *    leaf type uniformly (`isCanonical()` accepts 1 OR 2 for ANY leaf),
     *    with no per-type awareness that media types are non-translatable-
     *    only — so it faithfully round-trips whatever raw value the source
     *    JSON had, including an anomalous `2` on a `gallery` field.
     *  - The `store-locator` fixture's `photos` gallery field ("Galerie",
     *    no locale-specific instructions, no per-language distinguishing
     *    context) is an ordinary photo gallery with no plausible reason to
     *    be marked "Translate" in WPML — it is legacy real-world ACF
     *    Admin-authored data that itself violates the project's own
     *    doctrine, not a case the doctrine failed to anticipate.
     *
     * Conclusion: the schema is right, definition-kit's round-trip is
     * (correctly) faithful to a flawed input, and the fixture's data was
     * simply wrong. Fixed the COPIED test fixture in this repo (not the
     * live eprukaz/mairateam site — this repo's fixture is a static
     * snapshot) from `wpml_cf_preferences: 2` to `1` on the `photos`
     * field, and dropped the skip entirely — acf-lint now passes for every
     * fixture with no exceptions. `parisek/acf-json-schema` was NOT
     * touched; no draft PR was needed because the schema was correct.
     */
    private AcfLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new AcfLinter(__DIR__ . '/../../vendor/parisek/acf-json-schema/schemas');
    }

    /**
     * @return list<string>
     */
    private static function migrationFixtureAcfJsonPaths(): array
    {
        $root = __DIR__ . '/../fixtures/migration';
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && 'acf.json' === $file->getFilename()) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        return $paths;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function migrationFixtureProvider(): array
    {
        $cases = [];
        foreach (self::migrationFixtureAcfJsonPaths() as $path) {
            $slug = basename(dirname($path));
            $cases[$slug] = [$path, $slug];
        }
        return $cases;
    }

    #[DataProvider('migrationFixtureProvider')]
    public function test_regenerated_acf_json_passes_acf_lint(string $acfJsonPath, string $slug): void
    {
        $original = json_decode((string) file_get_contents($acfJsonPath), true, flags: JSON_THROW_ON_ERROR);

        $twigPath = dirname($acfJsonPath) . "/{$slug}.twig";
        $twigSource = is_file($twigPath) ? file_get_contents($twigPath) : null;

        $tree = (new AcfJsonReader())->read($original, $slug, $twigSource ?: null);
        $regenerated = (new FieldsGenerator())->generate($tree, $slug, (int) $original['modified']);

        // Finding 6 (round 3, LOW) — acf-lint dispatches by filename for
        // block.json; anything else with fields+location is treated as
        // acf.json shape regardless of the actual basename (confirmed via
        // AcfLinter::dispatch()). It still needs a FILE named exactly
        // `acf.json` though, so a unique per-test DIRECTORY (not just a
        // unique filename) is required — the previous implementation
        // renamed into the shared `sys_get_temp_dir()` root as a fixed
        // `acf.json`, which collides the moment two data-provider cases
        // run concurrently (parallel test runners, e.g. paratest).
        $tmpDir = sys_get_temp_dir() . '/dk-acf-lint-' . $slug . '-' . bin2hex(random_bytes(8));
        mkdir($tmpDir);
        $renamed = "{$tmpDir}/acf.json";
        file_put_contents(
            $renamed,
            json_encode($regenerated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );

        try {
            $result = $this->linter->lintFile($renamed, fix: false);

            self::assertTrue(
                $result->skipped === false,
                "acf-lint skipped '{$slug}' — dispatch() didn't recognize the regenerated shape as ACF",
            );
            self::assertTrue(
                $result->valid,
                "acf-lint found violations in regenerated acf.json for '{$slug}': "
                . json_encode($result->errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        } finally {
            @unlink($renamed);
            @rmdir($tmpDir);
        }
    }
}
