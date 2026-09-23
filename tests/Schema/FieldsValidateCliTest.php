<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Round 8 — CLI-level tests for `bin/fields-validate`:
 *
 * 1. Exit-code contract (a bare re-verification: at the time these tests were
 *    written the exit code already propagated correctly for schema-level
 *    failures; kept here as a permanent regression guard rather than a
 *    one-off manual repro, and extended below to the NEW conditional_logic
 *    checks this round adds).
 * 2. `fields-validate` / `fields-generate` parity on `wp.conditional_logic`:
 *    both a malformed shape and a dangling reference must now FAIL in
 *    `fields-validate`, matching what `fields-generate` already rejected via
 *    `FieldsGenerator::generate()`. Reference resolution needs the fully
 *    assembled field tree (every key computed exactly the way
 *    `FieldsGenerator::deriveOrPinKey()` computes it — the same reason the
 *    dangling-reference check inside `FieldsGenerator` itself cannot run
 *    before the tree is built), so `fields-validate` gets it by calling
 *    `FieldsGenerator::generate()` in-memory (no files written) after schema
 *    validation passes — see `bin/fields-validate`'s own comment for why
 *    this is possible without an architectural change.
 */
final class FieldsValidateCliTest extends TestCase
{
    private string $bin;
    private string $root;

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../../bin/fields-validate';
        $this->root = sys_get_temp_dir() . '/fields-validate-cli-test-' . uniqid('', true);
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

    private function writeYaml(string $slug, string $yaml): string
    {
        $path = "{$this->root}/{$slug}.yaml";
        file_put_contents($path, $yaml);
        return $path;
    }

    /** @return array{0: string, 1: int|null} */
    private function runCli(string $path): array
    {
        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        return [implode("\n", $output), $exitCode];
    }

    public function test_valid_definition_exits_zero(): void
    {
        $path = $this->writeYaml('demo', "name: Demo\ncategory: Content\nkind: element\nfields:\n  title:\n    type: text\n    label: Title\n");

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   ', $output);
    }

    /**
     * ADR 0009: a `reference` field naming a Drupal-only entity type
     * (`of: entity:webform`, arkero's `field_webform`) must validate cleanly.
     * `fields-validate` runs the WP reverse mapper unconditionally, even on a
     * Drupal-only project, as a key-resolution check.
     */
    public function test_entity_of_target_validates_ok(): void
    {
        $path = $this->writeYaml('demo', "name: Demo\ncategory: Content\nkind: element\nfields:\n  webform:\n    type: reference\n    label: Webform\n    of: 'entity:webform'\n");

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('OK   ', $output);
    }

    /**
     * Before ADR 0009, a `reference` field with a missing/unsupported `of:`
     * crashed fields-validate with an uncaught DomainException from
     * AbstractTypeReverseMapper::reference() instead of reporting a clean
     * FAIL — the exact crash `--drupal-config` migration of arkero's
     * `field_webform` triggered (an empty `of:`).
     */
    public function test_reference_with_unsupported_of_fails_cleanly_instead_of_crashing(): void
    {
        $path = $this->writeYaml('demo', "name: Demo\ncategory: Content\nkind: element\nfields:\n  broken:\n    type: reference\n    label: Broken\n");

        [$output, $exitCode] = $this->runCli($path);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL ', $output);
        self::assertStringNotContainsString('Fatal error', $output);
        self::assertStringNotContainsString('Uncaught', $output);
    }

    public function test_schema_invalid_definition_exits_nonzero(): void
    {
        $path = $this->writeYaml('demo', "name: Demo\ncategory: Content\nkind: element\nfields:\n  title:\n    type: not_a_real_type\n    label: Title\n");

        [$output, $exitCode] = $this->runCli($path);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL ', $output);
    }

    /** Fix 2 — shape check parity: a scalar `wp.conditional_logic` must FAIL fields-validate, same as fields-generate. */
    public function test_conditional_logic_malformed_shape_is_rejected(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          toggle:
            type: boolean
            label: Toggle
          conditional_field:
            type: text
            label: Conditional
            wp:
              conditional_logic: bogus
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL ', $output);
        self::assertStringContainsString('conditional_logic', $output);
    }

    /** Fix 2 — reference-resolution parity: a dangling `wp.conditional_logic` reference must FAIL fields-validate, same as fields-generate. */
    public function test_conditional_logic_dangling_reference_is_rejected(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          conditional_field:
            type: text
            label: Conditional
            wp:
              conditional_logic:
                -
                  - field: field_test_neexistuje
                    operator: "=="
                    value: "1"
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL ', $output);
        self::assertStringContainsString('conditional_logic', $output);
        self::assertStringContainsString('field_test_neexistuje', $output);
    }

    /** A resolvable `wp.conditional_logic` reference must still pass — the new check must not be over-eager. */
    public function test_conditional_logic_resolvable_reference_still_passes(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          toggle:
            type: boolean
            label: Toggle
            key: field_demo_toggle_key
          conditional_field:
            type: text
            label: Conditional
            wp:
              conditional_logic:
                -
                  - field: field_demo_toggle_key
                    operator: "=="
                    value: "1"
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   ', $output);
    }

    /**
     * Issue #11 secondary concern — `translatable:` on a `flexible_content`
     * field is inert (ACFML always forces copy-once). Must WARN, not fail,
     * and must not be silently dropped.
     */
    public function test_translatable_on_flexible_content_warns_but_still_exits_zero(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          items:
            type: flexible_content
            label: Items
            translatable: true
            layouts:
              title:
                label: Title layout
                fields:
                  title:
                    type: text
                    label: Title
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK   ', $output);
        self::assertStringContainsString('[warning]', $output);
        self::assertStringContainsString('translatable', $output);
        self::assertStringContainsString('items', $output);
    }

    /** Same inert-translatable warning fires identically for repeater/group — not special-cased to flexible_content. */
    public function test_translatable_on_repeater_warns_same_as_flexible_content(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          items:
            type: repeater
            label: Items
            translatable: true
            fields:
              title:
                type: text
                label: Title
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[warning]', $output);
        self::assertStringContainsString('repeater', $output);
    }

    /** No `translatable:` declared at all — no inert-property warning. */
    public function test_flexible_content_without_translatable_has_no_inert_warning(): void
    {
        $path = $this->writeYaml('demo', <<<YAML
        name: Demo
        category: Content
        kind: element
        fields:
          items:
            type: flexible_content
            label: Items
            layouts:
              title:
                label: Title layout
                fields:
                  title:
                    type: text
                    label: Title
        YAML);

        [$output, $exitCode] = $this->runCli($path);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('translatable', $output);
    }
}
