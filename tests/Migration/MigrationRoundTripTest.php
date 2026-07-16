<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\AcfJsonReader;
use Parisek\DefinitionKit\Migration\FieldsYamlWriter;
use Parisek\DefinitionKit\Migration\MigrationCompletenessAuditor;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Symfony\Component\Yaml\Yaml;

final class MigrationRoundTripTest extends TestCase
{
    /** @return array<string,mixed> */
    private function migrate(string $fixtureDir, string $slug, ?string $twigFile = null): array
    {
        $raw = file_get_contents("{$fixtureDir}/acf.json");
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $twigSource = null;
        if (null !== $twigFile && is_file($twigFile)) {
            $twigSource = file_get_contents($twigFile);
            self::assertIsString($twigSource);
        }

        return (new AcfJsonReader())->read($decoded, $slug, $twigSource);
    }

    public function test_service_feature_migrates_to_the_hand_reviewed_golden_fixture(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $tree = $this->migrate($fixtureDir, 'service-feature', "{$fixtureDir}/service-feature.twig");

        $outPath = sys_get_temp_dir() . '/service-feature-roundtrip-' . uniqid('', true) . '.fields.yaml';
        (new FieldsYamlWriter())->write($tree, $outPath);

        try {
            self::assertSame(
                file_get_contents("{$fixtureDir}/expected.fields.yaml"),
                file_get_contents($outPath),
                'Migrated YAML no longer matches the hand-reviewed golden fixture — '
                . 'if this is an intentional algorithm change, re-review and re-commit expected.fields.yaml by hand.',
            );
        } finally {
            @unlink($outPath);
        }
    }

    public function test_service_feature_output_validates_against_the_schema(): void
    {
        $result = (new FieldsSchemaValidator())->validateFile(
            __DIR__ . '/../fixtures/migration/service-feature/expected.fields.yaml',
        );
        self::assertTrue($result->valid, print_r($result->errors, true));
    }

    public function test_no_accordion_names_survive_in_the_golden_fixture(): void
    {
        $parsed = Yaml::parseFile(__DIR__ . '/../fixtures/migration/service-feature/expected.fields.yaml');
        self::assertArrayNotHasKey('header_accordion', $parsed['fields']);
        self::assertArrayNotHasKey('content_accordion', $parsed['fields']);
        self::assertArrayNotHasKey('appearance_accordion', $parsed['fields']);
    }

    public function test_no_mcp_or_dev_keys_are_emitted(): void
    {
        $parsed = Yaml::parseFile(__DIR__ . '/../fixtures/migration/service-feature/expected.fields.yaml');
        $walk = static function (array $fields) use (&$walk): void {
            foreach ($fields as $field) {
                self::assertArrayNotHasKey('mcp', $field);
                self::assertArrayNotHasKey('dev', $field);
                if (!empty($field['fields'])) {
                    $walk($field['fields']);
                }
            }
        };
        $walk($parsed['fields']);
    }

    /**
     * The completeness audit — see Task 9's docblock for why this, not a
     * full byte-identical acf.json reconstruction, is this dávka's
     * round-trip proof. Runs the same auditor against all three fixtures
     * (one real corpus component, two synthetic corpus-sample components
     * exercising the remaining ACF types) so the proof isn't limited to a
     * single golden fixture.
     */
    public function test_completeness_audit_passes_for_service_feature(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $raw = file_get_contents("{$fixtureDir}/acf.json");
        self::assertIsString($raw);
        $acfJson = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $tree = $this->migrate($fixtureDir, 'service-feature', "{$fixtureDir}/service-feature.twig");

        $violations = (new MigrationCompletenessAuditor())->audit($acfJson['fields'], $tree['fields']);
        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_completeness_audit_passes_for_newsletter_signup_corpus_sample(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/newsletter-signup';
        $raw = file_get_contents("{$fixtureDir}/acf.json");
        self::assertIsString($raw);
        $acfJson = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $tree = $this->migrate($fixtureDir, 'newsletter-signup');

        $violations = (new MigrationCompletenessAuditor())->audit($acfJson['fields'], $tree['fields']);
        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_completeness_audit_passes_for_store_locator_corpus_sample(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/corpus-sample/store-locator';
        $raw = file_get_contents("{$fixtureDir}/acf.json");
        self::assertIsString($raw);
        $acfJson = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $tree = $this->migrate($fixtureDir, 'store-locator');

        $violations = (new MigrationCompletenessAuditor())->audit($acfJson['fields'], $tree['fields']);
        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_newsletter_signup_and_store_locator_outputs_validate(): void
    {
        foreach (['newsletter-signup', 'store-locator'] as $name) {
            $fixtureDir = __DIR__ . "/../fixtures/migration/corpus-sample/{$name}";
            $tree = $this->migrate($fixtureDir, $name);
            $outPath = sys_get_temp_dir() . "/{$name}-roundtrip-" . uniqid('', true) . '.fields.yaml';
            (new FieldsYamlWriter())->write($tree, $outPath); // throws MigrationValidationException on failure
            @unlink($outPath);
        }
        // Reaching here means both wrote successfully (write() throws on failure).
        self::addToAssertionCount(1);
    }

    public function test_service_feature_accordions_are_captured_in_root_wp(): void
    {
        $fixtureDir = __DIR__ . '/../fixtures/migration/service-feature';
        $tree = $this->migrate($fixtureDir, 'service-feature', "{$fixtureDir}/service-feature.twig");

        self::assertCount(3, $tree['wp']['accordions']);
        self::assertSame('field_service-feature_header_accordion', $tree['wp']['accordions'][0]['key']);
        self::assertSame('heading', $tree['wp']['accordions'][0]['before']);
        self::assertSame(1, $tree['wp']['accordions'][1]['open']);
        self::assertSame('spacing', $tree['wp']['accordions'][2]['before']);
    }
}
