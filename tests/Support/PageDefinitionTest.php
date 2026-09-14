<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Support;

use Parisek\DefinitionKit\Support\PageDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageDefinitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/page-definition-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/page/home", 0777, true);
        mkdir("{$this->root}/component/home", 0777, true);
        mkdir("{$this->root}/page/_partials", 0777, true);
        mkdir("{$this->root}/page/group/nested", 0777, true);
        touch("{$this->root}/page/_partials/header.twig");
    }

    protected function tearDown(): void
    {
        unlink("{$this->root}/page/_partials/header.twig");
        foreach (["{$this->root}/page/_partials", "{$this->root}/page/group/nested", "{$this->root}/page/group", "{$this->root}/page/home", "{$this->root}/component/home", "{$this->root}/page", "{$this->root}/component", $this->root] as $dir) {
            rmdir($dir);
        }
    }

    #[Test]
    public function only_the_id_yaml_in_an_existing_page_directory_is_a_page(): void
    {
        self::assertTrue(PageDefinition::isPageYaml("{$this->root}/page/home/home.yaml"));
        self::assertFalse(PageDefinition::isPageYaml("{$this->root}/page/home/fixtures.yaml"));
        self::assertFalse(PageDefinition::isPageYaml("{$this->root}/component/home/home.yaml"));
        self::assertFalse(PageDefinition::isPageYaml("{$this->root}/page/hmoe/hmoe.yaml"));
        self::assertTrue(PageDefinition::isPageDirectory("{$this->root}/page/home/"));
        self::assertFalse(PageDefinition::isPageDirectory("{$this->root}/page/hmoe"));
        self::assertFalse(PageDefinition::isPageDirectory("{$this->root}/page"));
    }

    #[Test]
    public function a_page_nested_below_the_page_root_is_a_page(): void
    {
        self::assertTrue(PageDefinition::isPageDirectory("{$this->root}/page/group/nested"));
        self::assertTrue(PageDefinition::isPageYaml("{$this->root}/page/group/nested/nested.yaml"));
        self::assertTrue(PageDefinition::isPageYaml("{$this->root}/page/_partials/header.yaml"));
        self::assertFalse(PageDefinition::isPageYaml("{$this->root}/page/_partials/notes.yaml"));
    }

    #[Test]
    public function the_schema_header_climbs_one_level_per_directory_below_the_page_root(): void
    {
        self::assertSame(
            '# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json',
            PageDefinition::schemaHeaderFor("{$this->root}/page/home"),
        );
        self::assertSame(PageDefinition::SCHEMA_HEADER, PageDefinition::schemaHeaderFor("{$this->root}/page/home/"));
        self::assertSame(
            '# yaml-language-server: $schema=../../../../../vendor/parisek/definition-kit/schemas/page.schema.json',
            PageDefinition::schemaHeaderFor("{$this->root}/page/group/nested"),
        );
    }
}
