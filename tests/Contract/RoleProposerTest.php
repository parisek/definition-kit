<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Contract\CallSiteIndex;
use Parisek\DefinitionKit\Contract\RoleProposer;

final class RoleProposerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/role-proposer-' . uniqid('', true);
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

    /** @param array<string,string> $files basename => contents */
    private function component(string $name, array $files): string
    {
        $dir = "{$this->root}/{$name}";
        mkdir($dir, 0777, true);
        foreach ($files as $basename => $contents) {
            file_put_contents("{$dir}/{$basename}", $contents);
        }

        return $dir;
    }

    /** @param list<array{name: string, type: string}> $fields */
    private function acfJson(array $fields): string
    {
        return json_encode(['fields' => $fields], JSON_THROW_ON_ERROR);
    }

    private function callSites(): CallSiteIndex
    {
        // Every twig in every component directory, styleguide fixtures
        // included — same set `bin/fields-roles` builds.
        return new CallSiteIndex(glob("{$this->root}/*/*.twig") ?: []);
    }

    public function testAFieldWithAnAcfFieldBehindItIsProposedAsField(): void
    {
        $dir = $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields:\n  title: { type: text, label: T }\n",
            'hero.twig' => '{{ content.title }}',
            'acf.json' => $this->acfJson([['name' => 'title', 'type' => 'text']]),
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame(['title' => 'field'], $proposal->roles);
        self::assertSame([], $proposal->unresolved);
    }

    public function testBeingInTheYamlIsNotEvidenceOfAnAcfField(): void
    {
        // The distinction `role:` exists to record. A hand-authored
        // `type: text` looks exactly like a `parent` prop, so with no acf.json
        // to point at, the proposer leaves it for a human.
        $dir = $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields:\n  title: { type: text, label: T }\n",
            'hero.twig' => '{{ content.title }}',
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame([], $proposal->roles);
        self::assertSame(['title'], $proposal->unresolved);
    }

    public function testASidecarBackedByAQueryProposesQuery(): void
    {
        $dir = $this->component('article-featured', [
            'article-featured.yaml' => "name: Article featured\nfields:\n  title: { type: text, label: T }\n",
            'article-featured.twig' => '{% for item in content.items %}{{ item.title }}{% endfor %}',
            'article-featured.php' => "<?php\n\$query = new WP_Query(['post_type' => 'post']);\n"
                . "\$content['items'] = \$query->posts;\n",
            'acf.json' => $this->acfJson([['name' => 'title', 'type' => 'text']]),
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame('query', $proposal->roles['items']);
    }

    public function testAnOptionsBackedAssignmentProposesGlobal(): void
    {
        $dir = $this->component('contact-details', [
            'contact-details.yaml' => "name: Contact details\nfields:\n  title: { type: text, label: T }\n",
            'contact-details.twig' => '{{ content.contact_socials }}',
            'contact-details.php' => "<?php\n\$content['contact_socials'] = Helpers::formatFields('option');\n",
            'acf.json' => $this->acfJson([['name' => 'title', 'type' => 'text']]),
        ]);

        self::assertSame('global', (new RoleProposer())->propose($dir)->roles['contact_socials']);
    }

    public function testAnAssignmentWithNoRecognisableSourceIsLeftBlank(): void
    {
        // "This PHP assigns it" says the value is computed somewhere and
        // nothing about where from. `computed` was the home for that shrug and
        // was removed in #27 for exactly this reason.
        $dir = $this->component('widget', [
            'widget.yaml' => "name: Widget\nfields: {}\n",
            'widget.twig' => '{{ content.total }}',
            'widget.php' => "<?php\n\$content['total'] = 1 + 2;\n",
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame([], $proposal->roles);
        self::assertSame(['total'], $proposal->unresolved);
    }

    public function testAPropHandedInByACallSiteProposesParent(): void
    {
        $this->component('divider', [
            'divider.yaml' => "name: Divider\nfields: {}\n",
            'divider.twig' => '{{ content.inner }}',
        ]);
        $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => '{% include "divider/divider.twig" with { inner: "x" } %}',
        ]);

        $proposal = (new RoleProposer())->propose("{$this->root}/divider", $this->callSites());

        self::assertSame(['inner' => 'parent'], $proposal->roles);
    }

    public function testAComponentFunctionCallIsACallSite(): void
    {
        // timber-kit's `component_*` Twig function. A run over a real
        // 69-component project found 304 of these and zero includes between
        // components, so `role: parent` was proposable exactly nowhere until
        // this shape was recognised.
        $this->component('button', [
            'button.yaml' => "name: Button\nfields: {}\n",
            'button.twig' => '{{ content.label }}',
        ]);
        $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => "{{ component_button({ label: 'Více' }) }}",
        ]);

        $proposal = (new RoleProposer())->propose("{$this->root}/button", $this->callSites());

        self::assertSame(['label' => 'parent'], $proposal->roles);
    }

    public function testAComponentFunctionUnderscoreIsTheComponentsHyphen(): void
    {
        $this->component('page-header-default', [
            'page-header-default.yaml' => "name: PHD\nfields: {}\n",
            'page-header-default.twig' => '{{ content.claim }}',
        ]);
        $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => "{{ component_page_header_default({ claim: 'x' }) }}",
        ]);

        $proposal = (new RoleProposer())->propose("{$this->root}/page-header-default", $this->callSites());

        self::assertSame(['claim' => 'parent'], $proposal->roles);
    }

    public function testAnAcfFieldStillWinsOverACallSite(): void
    {
        // A styleguide fixture passes `title` to a component whose `title` is a
        // genuine ACF field. Consulting call sites first would relabel real
        // `field` props as `parent` across a whole project.
        $this->component('promo', [
            'promo.yaml' => "name: Promo\nfields:\n  title: { type: text, label: T }\n",
            'promo.twig' => '{{ content.title }}',
            'styleguide.primary.twig' => "{{ component_promo({ title: 'Demo' }) }}",
            'acf.json' => $this->acfJson([['name' => 'title', 'type' => 'text']]),
        ]);

        $proposal = (new RoleProposer())->propose("{$this->root}/promo", $this->callSites());

        self::assertSame(['title' => 'field'], $proposal->roles);
    }

    public function testAnIncludeWithoutExplicitVariablesIsNotEvidence(): void
    {
        // A bare include hands over the whole context, which says nothing
        // about any particular prop. Counting it would let every component
        // claim every prop as `parent`.
        $this->component('divider', [
            'divider.yaml' => "name: Divider\nfields: {}\n",
            'divider.twig' => '{{ content.inner }}',
        ]);
        $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => '{% include "divider/divider.twig" %}',
        ]);

        $proposal = (new RoleProposer())->propose("{$this->root}/divider", $this->callSites());

        self::assertSame([], $proposal->roles);
        self::assertSame(['inner'], $proposal->unresolved);
    }

    public function testAFrameworkInjectedPropIsOmittedRatherThanDeclared(): void
    {
        $dir = $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => '<div id="{{ content.wrapper_id }}">',
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame(['wrapper_id'], $proposal->baselineProps);
        self::assertSame([], $proposal->roles);
        self::assertSame([], $proposal->unresolved);
    }

    public function testAFrameworkDerivedPropProposesDerivedWithItsOrigin(): void
    {
        $dir = $this->component('article-video-grid', [
            'article-video-grid.yaml' => "name: Video grid\nfields:\n"
                . "  video: { type: media, kind: file, label: Video }\n",
            'article-video-grid.twig' => '{{ content.sources }}',
            'acf.json' => $this->acfJson([['name' => 'video', 'type' => 'file']]),
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame('derived', $proposal->roles['sources']);
        self::assertSame('video', $proposal->derivedFrom['sources']);
    }

    public function testASidecarLiftingASiblingProposesDerivedFromIt(): void
    {
        // reference-slider: the component's own PHP takes `heading.title` up to
        // the root. #27 removed `computed` on the grounds that no non-query
        // case existed; the mairateam run found this one, and `derived`
        // describes it with an origin a linter can verify.
        $dir = $this->component('reference-slider', [
            'reference-slider.yaml' => "name: Reference slider\nfields:\n"
                . "  heading: { type: group, label: H, fields: { title: { type: text, label: T } } }\n",
            'reference-slider.twig' => '{{ content.title }}',
            'reference-slider.php' => "<?php\n\$content['title'] = wp_kses_post(\$content['heading']['title']);\n",
            'acf.json' => $this->acfJson([['name' => 'heading', 'type' => 'group']]),
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame('derived', $proposal->roles['title']);
        self::assertSame('heading', $proposal->derivedFrom['title']);
    }

    public function testALiftOutOfAnUndeclaredSiblingProposesNothing(): void
    {
        // The origin has to exist, or the `from:` written into the definition
        // would dangle — the assertion fields-validate rejects.
        $dir = $this->component('widget', [
            'widget.yaml' => "name: Widget\nfields: {}\n",
            'widget.twig' => '{{ content.title }}',
            'widget.php' => "<?php\n\$content['title'] = \$content['heading']['title'];\n",
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertSame([], $proposal->roles);
        self::assertSame(['title'], $proposal->unresolved);
    }

    public function testDerivedIsNotProposedWithoutTheSiblingItWouldName(): void
    {
        // Proposing `derived` here would write a `from:` that dangles —
        // precisely the assertion the linter rejects.
        $dir = $this->component('gallery', [
            'gallery.yaml' => "name: Gallery\nfields:\n  title: { type: text, label: T }\n",
            'gallery.twig' => '{{ content.sources }}',
            'acf.json' => $this->acfJson([['name' => 'title', 'type' => 'text']]),
        ]);

        $proposal = (new RoleProposer())->propose($dir);

        self::assertArrayNotHasKey('sources', $proposal->roles);
        self::assertContains('sources', $proposal->unresolved);
    }

    public function testTheAppliedDefinitionIsSchemaValidAndReRunsToANoOp(): void
    {
        $this->component('divider', [
            'divider.yaml' => "name: Divider\nfields: {}\n",
            'divider.twig' => '{{ content.inner }}',
        ]);
        $this->component('hero', [
            'hero.yaml' => "name: Hero\nfields: {}\n",
            'hero.twig' => '{% include "divider/divider.twig" with { inner: "x" } %}',
        ]);

        $dir = "{$this->root}/divider";
        $proposal = (new RoleProposer())->propose($dir, $this->callSites());

        // A non-projecting prop gets no `type`/`label` — there is no ACF field
        // to describe, and an invented editor label is noise to delete.
        $definition = (array) $proposal->definition;
        self::assertIsArray($definition['fields']);
        self::assertSame(['role' => 'parent'], $definition['fields']['inner']);

        (new \Parisek\DefinitionKit\Migration\FieldsYamlWriter())->write($definition, "{$dir}/divider.yaml");

        $second = (new RoleProposer())->propose($dir, $this->callSites());
        self::assertSame([], $second->roles, 'a second run must be a no-op');
        self::assertSame([], $second->unresolved);
    }

    public function testAComponentWithNoDefinitionIsSkippedNotGuessedAt(): void
    {
        $dir = "{$this->root}/orphan";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/orphan.twig", '{{ content.title }}');

        self::assertNotNull((new RoleProposer())->propose($dir)->skipped);
    }
}
