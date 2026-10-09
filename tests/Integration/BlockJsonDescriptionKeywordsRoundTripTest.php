<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #51. A block.json that carries `description` and `keywords` must
 * survive migrate -> generate, and lint must report no drift. Runs the real
 * CLIs, so it guards the whole chain and not one class.
 */
final class BlockJsonDescriptionKeywordsRoundTripTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dk-51-' . uniqid('', true);
        mkdir("{$this->root}/article-blockquote", 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->root}/article-blockquote/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir("{$this->root}/article-blockquote");
        rmdir($this->root);
    }

    private function cli(string $bin, string ...$args): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/../../bin/{$bin}");
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        return (string) shell_exec($command . ' 2>&1');
    }

    public function test_description_and_keywords_round_trip_and_lint_is_clean(): void
    {
        $dir = "{$this->root}/article-blockquote";
        $description = 'Tip, upozornění nebo citace uvnitř textu článku.';
        $keywords = ['tip', 'upozornění', 'citace', 'callout', 'blockquote'];

        file_put_contents("{$dir}/acf.json", json_encode([
            'key' => 'group_article_blockquote',
            'title' => 'Article blockquote',
            'fields' => [['key' => 'field_article_blockquote_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text']],
        ], JSON_PRETTY_PRINT));
        $twig = "{#\nname: Article blockquote\ncategory: Content\nkind: block\n#}\n<div></div>\n";
        file_put_contents("{$dir}/article-blockquote.twig", $twig);

        // Start from the generator's own block.json and add the editor metadata.
        $this->cli('fields-migrate', $dir);
        $this->cli('fields-generate', $dir);
        $block = json_decode((string) file_get_contents("{$dir}/block.json"), true);
        self::assertIsArray($block);
        $block['description'] = $description;
        $block['keywords'] = $keywords;
        file_put_contents("{$dir}/block.json", json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        // Migration strips the twig front-comment; restore it for the real run.
        unlink("{$dir}/article-blockquote.yaml");
        file_put_contents("{$dir}/article-blockquote.twig", $twig);

        $this->cli('fields-migrate', $dir);
        // Regenerate from the definition alone: no block.json to preserve from.
        unlink("{$dir}/block.json");
        $this->cli('fields-generate', $dir);

        $regenerated = json_decode((string) file_get_contents("{$dir}/block.json"), true);
        self::assertIsArray($regenerated);
        self::assertSame($description, $regenerated['description']);
        self::assertSame($keywords, $regenerated['keywords']);

        $lint = $this->cli('fields-lint', $dir);
        self::assertStringContainsString('OK   article-blockquote', $lint);
        self::assertStringNotContainsString('DRIFT', $lint);
    }

    public function test_fields_validate_warns_about_a_wp_block_key_the_generator_ignores(): void
    {
        $yaml = "{$this->root}/article-blockquote/article-blockquote.yaml";
        file_put_contents($yaml, "name: Article blockquote\ncategory: Content\nkind: block\n"
            . "wp:\n  block:\n    description: Tip\n    icon: x\n"
            . "fields:\n  text:\n    type: text\n    label: Text\n    role: field\n");

        $output = $this->cli('fields-validate', $yaml);

        self::assertStringContainsString('`wp.block.icon` is ignored', $output);
        self::assertStringNotContainsString('`wp.block.description`', $output);
    }
}
