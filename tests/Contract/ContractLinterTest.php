<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Contract\ContractLinter;
use Parisek\DefinitionKit\Contract\ContractResult;
use Parisek\DefinitionKit\Contract\TwigPropExtractor;

final class ContractLinterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/contract-linter-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function component(string $name, string $yaml, string $twig): string
    {
        $dir = "{$this->root}/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$name}.yaml", $yaml);
        file_put_contents("{$dir}/{$name}.twig", $twig);

        return $dir;
    }

    private function lint(string $dir): ContractResult
    {
        return (new ContractLinter())->lint($dir);
    }

    public function testAFullyDeclaredComponentIsTypedAndClean(): void
    {
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{{ content.title }}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::TYPED, $result->status);
        self::assertSame([], $result->violations);
    }

    public function testAReadWithNoRoleBehindItIsAViolation(): void
    {
        // certificate-list.spacing — used by the twig, declared by nobody,
        // while every sibling component declares it. A real defect found in
        // the corpus while designing this.
        $dir = $this->component('certificate-list', <<<'YAML'
        name: Certificate list
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{{ content.title }}<div class="{{ content.spacing }}">');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::VIOLATIONS, $result->status);
        self::assertSame(['spacing'], $result->violations);
        self::assertTrue($result->isFailure());
    }

    public function testFrameworkInjectedPropsNeedNoDeclaration(): void
    {
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '<div id="{{ content.wrapper_id }}" class="{{ content.wrapper_classes }}">{{ content.title }}</div>');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testEveryRoleAccountsForAReadNotJustField(): void
    {
        $dir = $this->component('article-featured', <<<'YAML'
        name: Article featured
        fields:
          items: { type: repeater, label: Items, role: query, fields: { title: { type: text, label: T } } }
          contact_socials: { type: text, label: Socials, role: global }
          inner: { type: text, label: Inner, role: parent }
        YAML, '{% for item in content.items %}{{ item.title }}{% endfor %}'
            . '{{ content.contact_socials }}{{ content.inner }}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::TYPED, $result->status, implode(', ', $result->violations));
    }

    public function testADerivedPropIsAccountedForLikeAnyOther(): void
    {
        $dir = $this->component('article-video-grid', <<<'YAML'
        name: Article video grid
        fields:
          items:
            type: repeater
            label: Items
            role: field
            fields:
              video: { type: media, kind: file, label: Video }
              sources: { type: text, label: Sources, role: derived, from: video }
        YAML, '{% for item in content.items %}{{ item.sources }}{% endfor %}');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testATypoInAnEnumeratedRepeaterRowIsCaught(): void
    {
        $dir = $this->component('list', <<<'YAML'
        name: List
        fields:
          items:
            type: repeater
            label: Items
            role: field
            fields:
              value: { type: text, label: Value }
        YAML, '{% for item in content.items %}{{ item.valeu }}{% endfor %}');

        self::assertSame(['items.valeu'], $this->lint($dir)->violations);
    }

    public function testReadsPastADeclaredLeafAreReturnShapeNotSeparateProps(): void
    {
        // ACF's own return shapes — `link.url`, `image.src`. #26 measured this
        // as the largest false-positive category of the linter-with-a-
        // suppression-list approach.
        $dir = $this->component('cta', <<<'YAML'
        name: CTA
        fields:
          button: { type: link, shape: link, label: Button, role: field }
        YAML, '<a href="{{ content.button.url }}" target="{{ content.button.attributes.target }}">');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testReadsInsideANonFieldStructureAreNotEnumerated(): void
    {
        $dir = $this->component('article-featured', <<<'YAML'
        name: Article featured
        fields:
          items: { type: repeater, label: Items, role: query }
        YAML, '{% for item in content.items %}{{ item.title }}{{ item.author.name }}{% endfor %}');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testReadsInsideAnOpenMapAreNotEnumerated(): void
    {
        $dir = $this->component('picture', <<<'YAML'
        name: Picture
        fields:
          image: { type: media, kind: image, label: Image, role: field }
          link_attributes: { type: group, label: Link attributes, open: true, role: parent }
        YAML, '<a data-lg-group="{{ content.link_attributes["data-lg-group"] }}">'
            . '{{ content.link_attributes.target }}</a>');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testAProjectBaselineReplacesTheShippedOne(): void
    {
        // tailwind-base treats `container` as a layout slot every wrapper
        // supplies. That is a fact about its framework, not about this
        // package, so the project states it.
        file_put_contents("{$this->root}/framework-props-baseline.yaml", "content:\n  - container\n");

        $dir = $this->component('alert', <<<'YAML'
        name: Alert
        fields:
          text: { type: text, label: Text, role: field }
        YAML, '<div class="{{ content.container }}">{{ content.text }}</div>');

        self::assertSame(
            ContractResult::TYPED,
            ContractLinter::forComponentsRoot($this->root)->lint($dir)->status,
        );
        // …and without it, `container` is a finding, as the shipped baseline says.
        self::assertSame(['container'], $this->lint($dir)->violations);
    }

    public function testADefinitionWithARolelessFieldIsUntypedNotPassing(): void
    {
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis }
        YAML, '{{ content.title }}{{ content.undeclared }}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::UNTYPED, $result->status);
        self::assertFalse($result->isFailure());
        self::assertStringContainsString('title', (string) $result->reason);
    }

    public function testRolesAreInheritedByDescendantsRatherThanDemandedOnEachOne(): void
    {
        $dir = $this->component('list', <<<'YAML'
        name: List
        fields:
          items:
            type: repeater
            label: Items
            role: query
            fields:
              title: { type: text, label: T }
        YAML, '{% for item in content.items %}{{ item.title }}{% endfor %}');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testAnEmptyFieldsMapIsUntypedRatherThanVacuouslyClean(): void
    {
        // `fields: {}` is honest — fields-migrate writes it rather than guess —
        // but it records that nobody stated the contract, not that there is
        // none. A `divider` becomes typed by declaring `role: parent`.
        $dir = $this->component('divider', "name: Divider\nfields: {}\n", '{{ content.inner }}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::UNTYPED, $result->status);
        self::assertStringContainsString('role: parent', (string) $result->reason);
    }

    public function testAComponentThatGenuinelyHasNoInputsIsTypedNotUntyped(): void
    {
        // `fields: {}` plus a template reading nothing but framework props is
        // a complete contract, not an unstated one. Without this a purely
        // static component could never become typed — there would be nothing
        // it could declare to get there.
        $dir = $this->component('rule', "name: Rule\nfields: {}\n", '<hr id="{{ content.wrapper_id }}">');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testADividerThatDeclaresItsParentPropsBecomesTyped(): void
    {
        $dir = $this->component('divider', <<<'YAML'
        name: Divider
        fields:
          inner: { type: text, label: Inner, role: parent }
        YAML, '{{ content.inner }}');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testAnUnparsableTwigIsUnanalysedNotClean(): void
    {
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{% cache "k" %}{{ content.title }}{% endcache %}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::UNANALYSED, $result->status);
        self::assertSame([TwigPropExtractor::NOTE_PARSE_ERROR], array_column($result->notes, 'kind'));
    }

    public function testAPartialAnalysisStillReportsWhatItFoundAndSaysItWasPartial(): void
    {
        // A note can only hide reads, never invent them, so a violation found
        // under a partial analysis is still real — and the note rides along so
        // the result is not mistaken for complete.
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{{ content.title }}{{ content.spacing }}{{ attribute(content, key) }}');

        $result = $this->lint($dir);

        self::assertSame(['spacing'], $result->violations);
        self::assertSame([TwigPropExtractor::NOTE_DYNAMIC_ACCESS], array_column($result->notes, 'kind'));
    }

    public function testASiblingIncludeIsResolvedRelativeToTheComponentTree(): void
    {
        $this->component('icon', "name: Icon\nfields:\n  glyph: { type: text, label: G, role: field }\n", '{{ content.glyph }}');
        $dir = $this->component('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{{ content.title }}{% include "icon/icon.twig" %}');

        // `icon.twig` reads `content.glyph`, which hero does not declare — the
        // include inherits hero's context, so that read is hero's contract.
        self::assertSame(['glyph'], $this->lint($dir)->violations);
    }

    public function testAComponentWithNoDefinitionIsUntyped(): void
    {
        $dir = "{$this->root}/orphan";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/orphan.twig", '{{ content.title }}');

        self::assertSame(ContractResult::UNTYPED, $this->lint($dir)->status);
    }

    public function testAComponentWithNoTwigCannotBeChecked(): void
    {
        $dir = "{$this->root}/headless";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/headless.yaml", "name: Headless\nfields:\n  t: { type: text, label: T, role: field }\n");

        self::assertSame(ContractResult::UNANALYSED, $this->lint($dir)->status);
    }
}
