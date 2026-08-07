<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\KeyStyle;
use PHPUnit\Framework\TestCase;

final class KeyStyleTest extends TestCase
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
        $root = sys_get_temp_dir() . '/dk-keystyle-' . bin2hex(random_bytes(6)) . '/components';
        mkdir($root, 0777, true);

        return $root;
    }

    public function test_slug_leaves_the_component_slug_alone(): void
    {
        self::assertSame('article-list', KeyStyle::Slug->keySlug('article-list'));
    }

    public function test_snake_folds_hyphens(): void
    {
        self::assertSame('article_list', KeyStyle::Snake->keySlug('article-list'));
    }

    public function test_snake_leaves_an_already_snake_slug_untouched(): void
    {
        self::assertSame('article_list', KeyStyle::Snake->keySlug('article_list'));
    }

    /**
     * Only hyphens fold. A broader "sanitise the slug" transform would
     * silently rewrite keys nobody asked about — the setting exists to settle
     * one specific disagreement, not to normalise directory names in general.
     */
    public function test_snake_does_not_touch_other_characters(): void
    {
        self::assertSame('page_header_1', KeyStyle::Snake->keySlug('page-header-1'));
        self::assertSame('Foo.Bar', KeyStyle::Snake->keySlug('Foo.Bar'));
    }

    public function test_default_is_slug_when_no_config_exists(): void
    {
        self::assertSame(KeyStyle::Slug, KeyStyle::discoverFor($this->makeRoot()));
    }

    public function test_config_next_to_the_components_root_is_read(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "key_style: snake\n");

        self::assertSame(KeyStyle::Snake, KeyStyle::discoverFor($root));
    }

    /**
     * One directory up is where a theme would realistically keep it — the
     * same two locations FrameworkProps::discoverFor() already searches, so a
     * project does not have to learn a second convention.
     */
    public function test_config_one_directory_up_is_read(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig(\dirname($root), "key_style: snake\n");

        self::assertSame(KeyStyle::Snake, KeyStyle::discoverFor($root));
    }

    public function test_the_nearer_config_wins(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig(\dirname($root), "key_style: snake\n");
        $this->writeConfig($root, "key_style: slug\n");

        self::assertSame(KeyStyle::Slug, KeyStyle::discoverFor($root));
    }

    public function test_a_trailing_slash_on_the_root_does_not_break_discovery(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "key_style: snake\n");

        self::assertSame(KeyStyle::Snake, KeyStyle::discoverFor($root . '/'));
    }

    /**
     * A config file carrying other settings, or a placeholder, is not an
     * error — it simply says nothing about keys.
     */
    public function test_config_without_the_key_is_the_default(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "something_else: true\n");

        self::assertSame(KeyStyle::Slug, KeyStyle::discoverFor($root));
    }

    /**
     * Loud, not silent. Falling back to the default here would rewrite every
     * key in the project on the next generate, and the drift-lint would
     * report it as the project's own doing rather than as a typo.
     */
    public function test_an_unknown_style_throws_and_names_the_file(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "key_style: kebab\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/kebab.*' . preg_quote(KeyStyle::CONFIG_FILENAME, '/') . '/s');

        KeyStyle::discoverFor($root);
    }

    public function test_a_non_string_style_throws_rather_than_coercing(): void
    {
        $root = $this->makeRoot();
        $this->writeConfig($root, "key_style: [snake]\n");

        $this->expectException(\RuntimeException::class);

        KeyStyle::discoverFor($root);
    }
}
