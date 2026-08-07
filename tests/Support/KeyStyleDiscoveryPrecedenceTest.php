<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\KeyStyle;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for two discovery defects found in adversarial review of the
 * key_style PR (Codex, 2026-08-07). Both were silent: neither would have
 * produced an error, only keys spelled the way the project did not ask for.
 */
final class KeyStyleDiscoveryPrecedenceTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
    }

    private function writeConfig(string $directory, string $contents): void
    {
        $path = $directory . '/' . KeyStyle::CONFIG_FILENAME;
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;
    }

    private function makeRoot(): string
    {
        $root = sys_get_temp_dir() . '/dk-keystyle-prec-' . bin2hex(random_bytes(6)) . '/components';
        mkdir($root, 0777, true);

        return $root;
    }

    /**
     * The original loop returned the default as soon as ANY config file was
     * found, even one that said nothing about keys. A stray or unrelated
     * `definition-kit.yaml` beside the components root therefore masked a
     * real declaration one directory up — and the only symptom would have
     * been the drift-lint reporting every key in the project as changed.
     */
    public function test_a_nearer_config_without_the_key_does_not_mask_the_parent(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig(\dirname($root), "key_style: snake\n");
        $this->writeConfig($root, "something_else: true\n");

        self::assertSame(KeyStyle::Snake, KeyStyle::discoverFor($root));
    }

    /**
     * The mirror case, so the assertion above cannot pass by simply always
     * preferring the parent.
     */
    public function test_a_nearer_config_that_does_declare_the_key_still_wins(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig(\dirname($root), "key_style: snake\n");
        $this->writeConfig($root, "key_style: slug\n");

        self::assertSame(KeyStyle::Slug, KeyStyle::discoverFor($root));
    }

    /**
     * `isset()` reports an explicit null as absent, so `key_style:` with no
     * value silently selected the default. It is a typo, and the whole point
     * of the throw is that a wrong style is otherwise invisible.
     */
    public function test_an_explicit_null_is_a_typo_not_an_omission(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "key_style:\n");

        $this->expectException(\RuntimeException::class);

        KeyStyle::discoverFor($root);
    }

    public function test_an_empty_config_file_is_the_default(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, '');

        self::assertSame(KeyStyle::Slug, KeyStyle::discoverFor($root));
    }
}
