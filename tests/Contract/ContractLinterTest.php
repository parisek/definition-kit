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

    public function testADeclaredShapeIsCheckedWhateverTheRole(): void
    {
        // An author who enumerates the shape of a `parent` prop is claiming
        // it, and the claim was being ignored: 18 such declarations across two
        // real projects — every menu shape in mairateam among them — were
        // written and then never checked.
        $dir = $this->component('header-menu', <<<'YAML'
        name: Header menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
        YAML, '{% for item in content.items %}{{ item.titel }}{% endfor %}');

        self::assertSame(['items.titel'], $this->lint($dir)->violations);
    }

    public function testASelfReferencingForwardExpressesRecursion(): void
    {
        // The schema cannot say "and so on"; `of:` pointing at its own
        // component can. Depth is bounded by the read path, so the walk
        // terminates on its own rather than needing a limit.
        $dir = $this->component('menu', <<<'YAML'
        name: Menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
              below: { type: repeater, role: parent, of: component:menu#items }
        YAML, '{{ content.items.below.below.below.title }}{{ content.items.below.below.titel }}');

        // Four levels deep is fine; a typo at any depth is not.
        self::assertSame(['items.below.below.titel'], $this->lint($dir)->violations);
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

    public function testAForwardedPropTakesItsShapeFromTheComponentItGoesTo(): void
    {
        $this->component('header-menu', <<<'YAML'
        name: Header menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
        YAML, '{% for item in content.items %}{{ item.title }}{% endfor %}');

        $dir = $this->component('header', <<<'YAML'
        name: Header
        fields:
          menu: { type: repeater, label: Menu, role: parent, of: component:header-menu#items }
        YAML, '{% for item in content.menu %}{{ item.title }}{% endfor %}');

        self::assertSame(ContractResult::TYPED, $this->lint($dir)->status);
    }

    public function testAForwardedPropIsCheckedAgainstTheTargetsFields(): void
    {
        // The point of the reference over a transcript: the parent cannot
        // read a field the child does not have, and nobody had to keep two
        // copies in step to find that out.
        $this->component('header-menu', <<<'YAML'
        name: Header menu
        fields:
          items:
            type: repeater
            label: Items
            role: parent
            fields:
              title: { type: text, label: Title }
        YAML, '{% for item in content.items %}{{ item.title }}{% endfor %}');

        $dir = $this->component('header', <<<'YAML'
        name: Header
        fields:
          menu: { type: repeater, label: Menu, role: parent, of: component:header-menu#items }
        YAML, '{% for item in content.menu %}{{ item.icon }}{% endfor %}');

        self::assertSame(['menu.icon'], $this->lint($dir)->violations);
    }

    public function testADanglingForwardIsReportedRatherThanSilentlyOpaque(): void
    {
        // An unreachable shape has to be treated as opaque — there is nothing
        // to check reads against. Doing that silently is how a component reads
        // fifty undeclared props and still reports clean, so it comes back as
        // a note. (`fields-validate` reports the dangling target itself.)
        $dir = $this->component('header', <<<'YAML'
        name: Header
        fields:
          menu: { type: repeater, label: Menu, role: parent, of: component:nope#items }
        YAML, '{% for item in content.menu %}{{ item.title }}{% endfor %}');

        $result = $this->lint($dir);

        self::assertSame([], $result->violations);
        self::assertSame([ContractLinter::NOTE_UNRESOLVED_FORWARD], $result->noteKinds());
        // …and it fails the run. A note that is a defect rather than a caveat
        // has to reach the exit code, or the gate passes on a definition whose
        // shape nobody can read.
        self::assertTrue($result->isFailure());
    }

    public function testABrokenForwardIsFoundWhateverTheTwigDoes(): void
    {
        // Resolving forwards only where a read reached them meant a dangling
        // reference went unreported whenever the twig happened not to read
        // through it. The defect is in the definition, so it is found by
        // reading the definition — each of these used to pass.
        $cases = [
            'never read at all' => ['{{ content.title }}', ContractResult::TYPED],
            'read as a whole' => ['{{ content.menu|length }}', ContractResult::TYPED],
            'template does not parse' => ['{% cache "k" %}{% endcache %}', ContractResult::UNANALYSED],
        ];

        foreach ($cases as $label => [$twig, $expectedStatus]) {
            $dir = $this->component('header-' . md5($label), <<<'YAML'
            name: Header
            fields:
              title: { type: text, label: Title, role: field }
              menu: { type: repeater, label: Menu, role: parent, of: component:nope#items }
            YAML, $twig);

            $result = $this->lint($dir);

            self::assertSame($expectedStatus, $result->status, $label);
            self::assertTrue($result->isFailure(), $label);
            self::assertContains(ContractResult::NOTE_UNRESOLVED_FORWARD, $result->noteKinds(), $label);
        }
    }

    public function testABrokenForwardIsFoundOnAnUntypedComponentToo(): void
    {
        $dir = $this->component('header', <<<'YAML'
        name: Header
        fields:
          title: { type: text, label: Title }
          menu: { type: repeater, label: Menu, role: parent, of: component:nope#items }
        YAML, '{{ content.title }}');

        $result = $this->lint($dir);

        self::assertSame(ContractResult::UNTYPED, $result->status);
        self::assertTrue($result->isFailure());
    }

    public function testABrokenForwardIsFoundWithNoTwigAtAll(): void
    {
        $dir = "{$this->root}/headless";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/headless.yaml", "name: Headless\nfields:\n"
            . "  menu: { type: repeater, label: Menu, role: parent, of: component:nope#items }\n");

        $result = $this->lint($dir);

        self::assertSame(ContractResult::UNANALYSED, $result->status);
        self::assertTrue($result->isFailure());
    }

    public function testANestedBrokenForwardIsFoundToo(): void
    {
        $dir = $this->component('header', <<<'YAML'
        name: Header
        fields:
          inner:
            type: group
            label: Inner
            role: parent
            fields:
              menu: { type: repeater, label: Menu, role: parent, of: component:nope#items }
        YAML, '{{ content.inner }}');

        $result = $this->lint($dir);

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('inner.menu', $result->notes[0]['detail']);
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

    // -- issue #56: namespace-aliased template paths -------------------------

    /**
     * Builds `<root>/static/templates/component/<name>/…` — the
     * `STATIC_PATH/templates` shape `deriveNamespaceMap()` recognises — and
     * writes the component's yaml/twig into it. Returns the component dir.
     */
    private function namespacedComponent(string $name, string $yaml, string $twig): string
    {
        $dir = "{$this->root}/static/templates/component/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$name}.yaml", $yaml);
        file_put_contents("{$dir}/{$name}.twig", $twig);

        return $dir;
    }

    public function testANamespacedMacroImportIsFollowedAndItsReadsSurface(): void
    {
        $partsDir = "{$this->root}/static/templates/macro";
        mkdir($partsDir, 0777, true);
        file_put_contents(
            "{$partsDir}/parts.twig",
            '{% macro split(content) %}{{ content.title }}{{ content.ratings }}{% endmacro %}',
        );

        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@macro/parts.twig" as parts %}{{ parts.split(content) }}');

        // Before #56 the resolver could never open `@macro/parts.twig`, so
        // this always fell back to the tier-1 note with no reads at all —
        // `ratings` (and even `title`) stayed invisible.
        $result = $this->lint($dir);
        self::assertSame(['ratings'], $result->violations);
    }

    public function testAnUnknownNamespaceDeclinesRatherThanGuesses(): void
    {
        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@vendor/parts.twig" as parts %}{{ parts.split(content) }}');

        // `@vendor` names nothing in the derived map. A literal directory
        // named `@vendor` two levels up happens to exist and would satisfy
        // the pre-fix un-namespaced walk (it joins the path as written,
        // `@` and all, against each ancestor) — proving decline is a real
        // choice here, not just an absence of coincidence. It would attribute
        // `ratings` to a file the template never named.
        $vendorDir = "{$this->root}/static/templates/component/@vendor";
        mkdir($vendorDir, 0777, true);
        file_put_contents("{$vendorDir}/parts.twig", '{% macro split(content) %}{{ content.ratings }}{% endmacro %}');

        $result = $this->lint($dir);
        self::assertSame([], $result->violations);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $result->noteKinds());
    }

    public function testAnExplicitNamespaceMapOverridesTheDerivedOne(): void
    {
        // A layout the derivation cannot recognise on its own: the macro
        // lives outside `static/templates/macro` entirely.
        $exoticDir = "{$this->root}/vendor-macros";
        mkdir($exoticDir, 0777, true);
        file_put_contents(
            "{$exoticDir}/parts.twig",
            '{% macro split(content) %}{{ content.ratings }}{% endmacro %}',
        );

        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@macro/parts.twig" as parts %}{{ parts.split(content) }}');

        $linter = new ContractLinter(namespaces: ['macro' => $exoticDir]);
        $result = $linter->lint($dir);

        self::assertSame(['ratings'], $result->violations);
    }

    public function testAnUnNamespacedRelativeIncludeStillResolvesTheOldWay(): void
    {
        // Same shape as testASiblingIncludeIsResolvedRelativeToTheComponentTree
        // but rooted under `static/templates/component/`, so a namespace map
        // is derivable — proving its presence doesn't break the un-namespaced
        // walk that path never touches.
        $this->namespacedComponent('icon', "name: Icon\nfields:\n  glyph: { type: text, label: G, role: field }\n", '{{ content.glyph }}');
        $dir = $this->namespacedComponent('hero', <<<'YAML'
        name: Hero
        fields:
          title: { type: text, label: Nadpis, role: field }
        YAML, '{{ content.title }}{% include "icon/icon.twig" %}');

        self::assertSame(['glyph'], $this->lint($dir)->violations);
    }

    public function testANamespacedPathTraversalCannotEscapeItsNamespaceDirectory(): void
    {
        // The namespace directory must exist for the namespace to be in the
        // derived map at all — an absent directory declines for an
        // unrelated reason (unknown namespace) and would make this test
        // pass without the traversal guard doing anything.
        $macroDir = "{$this->root}/static/templates/macro";
        mkdir($macroDir, 0777, true);

        // Lives inside $this->root but outside the macro namespace's own
        // directory — exactly what the containment check must keep
        // `@macro/…` from reaching, `..` segments or not.
        file_put_contents("{$this->root}/secret.twig", '{% macro split(content) %}{{ content.secret }}{% endmacro %}');

        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@macro/../../../secret.twig" as parts %}{{ parts.split(content) }}');

        $result = $this->lint($dir);

        // Resolution declines exactly as it does for an unknown namespace —
        // the include is unanalysed, `secret` never surfaces as a violation.
        self::assertSame([], $result->violations);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $result->noteKinds());
    }

    public function testANamespacedSymlinkCannotEscapeItsNamespaceDirectory(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is unavailable on this platform.');
        }

        $macroDir = "{$this->root}/static/templates/macro";
        mkdir($macroDir, 0777, true);

        // Outside the macro namespace directory, no `..` involved — reached
        // only via a symlink planted inside it.
        $secretDir = "{$this->root}/secret-target";
        mkdir($secretDir, 0777, true);
        file_put_contents("{$secretDir}/parts.twig", '{% macro split(content) %}{{ content.secret }}{% endmacro %}');

        $linked = symlink($secretDir, "{$macroDir}/linked");
        self::assertTrue($linked, 'test setup requires a working symlink()');

        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@macro/linked/parts.twig" as parts %}{{ parts.split(content) }}');

        $result = $this->lint($dir);

        self::assertSame([], $result->violations);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $result->noteKinds());
    }

    public function testANamespaceWithAnEmptyRemainderDeclinesWithoutError(): void
    {
        $macroDir = "{$this->root}/static/templates/macro";
        mkdir($macroDir, 0777, true);

        $dir = $this->namespacedComponent('page-header', <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML, '{% import "@macro" as parts %}{{ parts.split(content) }}');

        $result = $this->lint($dir);

        self::assertSame([], $result->violations);
        self::assertSame([TwigPropExtractor::NOTE_UNANALYSED_MACRO], $result->noteKinds());
    }

    public function testAnExplicitNamespaceOverrideKeyIsAcceptedWithOrWithoutTheAtSign(): void
    {
        // Two callers, two spellings — the documented `@namespace` form and
        // the bare form the internal lookup actually uses (issue: the
        // docblock and the code disagreed on which one was correct). Both
        // must override the derived `macro` entry the same way.
        $exoticDir = "{$this->root}/vendor-macros";
        mkdir($exoticDir, 0777, true);
        file_put_contents(
            "{$exoticDir}/parts.twig",
            '{% macro split(content) %}{{ content.ratings }}{% endmacro %}',
        );

        $yaml = <<<'YAML'
        name: Page Header
        fields:
          title: { type: text, label: Title, role: field }
        YAML;
        $twig = '{% import "@macro/parts.twig" as parts %}{{ parts.split(content) }}';

        $bareKeyDir = $this->namespacedComponent('page-header-bare', $yaml, $twig);
        $atKeyDir = $this->namespacedComponent('page-header-at', $yaml, $twig);

        $bareResult = (new ContractLinter(namespaces: ['macro' => $exoticDir]))->lint($bareKeyDir);
        $atResult = (new ContractLinter(namespaces: ['@macro' => $exoticDir]))->lint($atKeyDir);

        self::assertSame(['ratings'], $bareResult->violations);
        self::assertSame(['ratings'], $atResult->violations);
    }
}
