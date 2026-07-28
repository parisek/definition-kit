<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Contract\ComponentShapeResolver;

final class ComponentShapeResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shape-resolver-' . uniqid('', true);
        mkdir($this->root, 0777, true);

        $this->component('header-menu', <<<'YAML'
        name: Header menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
              url: { type: link, shape: url, label: Url }
        YAML);
        $this->component('divider', "name: Divider\nfields: {}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->root}/*") ?: [] as $dir) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
        rmdir($this->root);
    }

    private function component(string $name, string $yaml): void
    {
        mkdir("{$this->root}/{$name}", 0777, true);
        file_put_contents("{$this->root}/{$name}/{$name}.yaml", $yaml);
    }

    /** @return array{fields: array<string,mixed>|null, error: ?string} */
    private function resolve(string $of): array
    {
        return (new ComponentShapeResolver($this->root))->resolve($of);
    }

    public function testAFieldTargetResolvesToThatFieldsOwnFields(): void
    {
        $result = $this->resolve('component:header-menu#items');

        self::assertNull($result['error']);
        self::assertSame(['title', 'url'], array_keys((array) $result['fields']));
    }

    public function testAComponentTargetWithNoFieldResolvesToItsWholeInputMap(): void
    {
        $result = $this->resolve('component:header-menu');

        self::assertNull($result['error']);
        self::assertSame(['items'], array_keys((array) $result['fields']));
    }

    public function testAMissingComponentIsAnErrorNamingThePathItLookedAt(): void
    {
        $result = $this->resolve('component:header-mneu#items');

        self::assertNull($result['fields']);
        self::assertStringContainsString('header-mneu', (string) $result['error']);
    }

    public function testAMissingFieldIsAnErrorNamingTheComponentThatLacksIt(): void
    {
        // The likely failure in practice: this is what a rename looks like.
        $result = $this->resolve('component:header-menu#itms');

        self::assertNull($result['fields']);
        self::assertStringContainsString('itms', (string) $result['error']);
        self::assertStringContainsString('header-menu.yaml', (string) $result['error']);
    }

    public function testAFieldTargetOnAComponentWithNoFieldsIsAnError(): void
    {
        $result = $this->resolve('component:divider#anything');

        self::assertNull($result['fields']);
        self::assertNotNull($result['error']);
    }

    public function testAWholeComponentTargetWithNothingToBorrowIsAnError(): void
    {
        // `divider` declares nothing, so `of: component:divider` promises a
        // shape that is not there. Returning an empty map instead would make
        // every read of the borrowing prop a violation with no clue why — and
        // the field-path branch already rejected this while the
        // whole-component branch did not.
        $result = $this->resolve('component:divider');

        self::assertNull($result['fields']);
        self::assertNotNull($result['error']);
    }

    public function testATrailingHashIsAHalfFinishedEditNotAWholeComponentTarget(): void
    {
        // `component:header-menu#` resolving to the whole component would hand
        // back a different shape than the author was reaching for, silently.
        $result = $this->resolve('component:header-menu#');

        self::assertNull($result['fields']);
        self::assertStringContainsString('#', (string) $result['error']);
    }

    public function testNonComponentTargetsAreNotThisResolversBusiness(): void
    {
        // `post:article`, `term:category`, `geo` — the reference targets that
        // already existed. They resolve elsewhere, and silence here is right.
        $result = $this->resolve('post:article');

        self::assertNull($result['fields']);
        self::assertNull($result['error']);
    }

    public function testATargetIsParsedOnceAndAnsweredFromThereAfter(): void
    {
        $resolver = new ComponentShapeResolver($this->root);

        self::assertSame(['title', 'url'], array_keys((array) $resolver->resolve('component:header-menu#items')['fields']));

        // Deleting the file it read proves the second answer came from the
        // cache — a `--root` sweep asks for the same target once per
        // reference, and re-parsing each time is the cost this avoids.
        unlink("{$this->root}/header-menu/header-menu.yaml");

        self::assertSame(['title', 'url'], array_keys((array) $resolver->resolve('component:header-menu#items')['fields']));
        // …and a resolver that did not see it is not fooled: the cache is per
        // instance, so it cannot outlive the files it describes.
        self::assertNotNull((new ComponentShapeResolver($this->root))->resolve('component:header-menu#items')['error']);
    }

    public function testForComponentDirDerivesTheComponentsRoot(): void
    {
        $resolver = ComponentShapeResolver::forComponentDir("{$this->root}/header/");

        self::assertSame(['title', 'url'], array_keys((array) $resolver->resolve('component:header-menu#items')['fields']));
    }
}
