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
    }

    protected function tearDown(): void
    {
        foreach (["{$this->root}/page/home", "{$this->root}/component/home", "{$this->root}/page", "{$this->root}/component", $this->root] as $dir) {
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
    }
}
