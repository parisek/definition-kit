<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\EntryDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntryDefinitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/entry-definition-' . bin2hex(random_bytes(4));
        foreach (['page/home', 'component/home', 'page/_partials', 'page/group/nested', 'doc/typography', 'doc/group/nested', 'doc/typography/component/inner'] as $dir) {
            mkdir("{$this->root}/{$dir}", 0777, true);
        }
        touch("{$this->root}/page/_partials/header.twig");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/{,.}[!.,!..]*", GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    #[Test]
    public function only_the_id_yaml_in_an_existing_page_directory_is_a_page(): void
    {
        self::assertSame('page', EntryDefinition::typeOfYaml("{$this->root}/page/home/home.yaml"));
        self::assertNull(EntryDefinition::typeOfYaml("{$this->root}/page/home/fixtures.yaml"));
        self::assertNull(EntryDefinition::typeOfYaml("{$this->root}/component/home/home.yaml"));
        self::assertNull(EntryDefinition::typeOfYaml("{$this->root}/page/hmoe/hmoe.yaml"));
        self::assertSame('page', EntryDefinition::typeOfDirectory("{$this->root}/page/home/"));
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/page/hmoe"));
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/page"));
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/component/home"));
    }

    #[Test]
    public function a_page_nested_below_the_page_root_is_a_page(): void
    {
        self::assertSame('page', EntryDefinition::typeOfDirectory("{$this->root}/page/group/nested"));
        self::assertSame('page', EntryDefinition::typeOfYaml("{$this->root}/page/group/nested/nested.yaml"));
        self::assertSame('page', EntryDefinition::typeOfYaml("{$this->root}/page/_partials/header.yaml"));
        self::assertNull(EntryDefinition::typeOfYaml("{$this->root}/page/_partials/notes.yaml"));
    }

    #[Test]
    public function a_directory_below_a_doc_root_is_a_doc(): void
    {
        self::assertSame('doc', EntryDefinition::typeOfDirectory("{$this->root}/doc/typography"));
        self::assertSame('doc', EntryDefinition::typeOfDirectory("{$this->root}/doc/group/nested"));
        self::assertSame('doc', EntryDefinition::typeOfYaml("{$this->root}/doc/typography/typography.yaml"));
        self::assertNull(EntryDefinition::typeOfYaml("{$this->root}/doc/typography/styleguide.data.yaml"));
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/doc"));
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/doc/missing"));
    }

    #[Test]
    public function the_nearest_typed_ancestor_decides(): void
    {
        self::assertNull(EntryDefinition::typeOfDirectory("{$this->root}/doc/typography/component/inner"));
    }

    #[Test]
    public function the_schema_header_climbs_one_level_per_directory_below_the_entry_root(): void
    {
        self::assertSame(
            '# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json',
            EntryDefinition::schemaHeaderFor("{$this->root}/page/home"),
        );
        self::assertSame(
            '# yaml-language-server: $schema=../../../../../vendor/parisek/definition-kit/schemas/page.schema.json',
            EntryDefinition::schemaHeaderFor("{$this->root}/page/group/nested"),
        );
        self::assertSame(
            '# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/doc.schema.json',
            EntryDefinition::schemaHeaderFor("{$this->root}/doc/typography/"),
        );
        self::assertSame(
            '# yaml-language-server: $schema=../../../../../vendor/parisek/definition-kit/schemas/doc.schema.json',
            EntryDefinition::schemaHeaderFor("{$this->root}/doc/group/nested"),
        );
    }

    #[Test]
    public function refused_keys_are_named_with_the_schema(): void
    {
        self::assertSame(
            ['`fields:` is a component key; a page does not carry it (page.schema.json)'],
            EntryDefinition::refusedKeyMessages(['name' => 'x', 'fields' => [], 'usage' => 'a'], 'page'),
        );
        $doc = EntryDefinition::refusedKeyMessages(['name' => 'x', 'kind' => 'part', 'render' => 'bleed', 'responsive' => true], 'doc');
        self::assertCount(3, $doc);
        self::assertStringContainsString('`kind:`', $doc[0]);
        self::assertStringContainsString('doc.schema.json', $doc[0]);
        self::assertStringContainsString('`render:`', $doc[1]);
        self::assertStringContainsString('`responsive:`', $doc[2]);
    }
}
