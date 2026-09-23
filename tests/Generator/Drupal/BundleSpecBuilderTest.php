<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpec;
use Parisek\DefinitionKit\Generator\Drupal\BundleSpecBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BundleSpecBuilderTest extends TestCase
{
    private const LINK = '/admin/structure/paragraphs_type/teaser_box/fields';

    /**
     * @param array<string,mixed> $fields
     * @return list<BundleSpec>
     */
    private function build(array $fields, ?DrupalSettings $settings = null, string $link = self::LINK): array
    {
        return (new BundleSpecBuilder($settings ?? new DrupalSettings()))->build(
            ['name' => 'Teaser box', 'category' => 'Block', 'drupal' => $link, 'fields' => $fields],
            'teaser-box',
            null,
        );
    }

    #[Test]
    public function a_group_object_puts_its_children_on_the_same_bundle(): void
    {
        [$spec] = $this->build([
            'heading' => ['type' => 'object', 'label' => 'Heading', 'fields' => [
                'title' => ['type' => 'text', 'label' => 'Title'],
            ]],
        ]);

        self::assertSame('teaser_box', $spec->bundle);
        self::assertSame('field_title', $spec->fields[0]->machine);
        self::assertSame('heading.title', $spec->fields[0]->path);
        self::assertSame(['group_heading'], $spec->fields[0]->groups);
        self::assertSame(['label' => 'Heading', 'parent' => '', 'children' => ['field_title']], $spec->groups['group_heading']);
    }

    #[Test]
    public function a_list_targets_the_item_bundle_and_describes_it(): void
    {
        $specs = $this->build([
            'items' => ['type' => 'repeater', 'label' => 'Items', 'max' => 4, 'fields' => [
                'name' => ['type' => 'text', 'label' => 'Name'],
            ]],
        ]);

        self::assertSame(['teaser_box', 'teaser_box_item'], array_map(static fn (BundleSpec $s): string => $s->bundle, $specs));
        $items = $specs[0]->fields[0];
        self::assertSame('field_items', $items->machine);
        self::assertSame(['teaser_box_item'], $items->targetBundles);
        self::assertSame('paragraph', $items->targetType);
        self::assertSame(4, $items->cardinality);
        self::assertFalse($specs[1]->topLevel);
        self::assertSame('Teaser box item', $specs[1]->label);
    }

    #[Test]
    public function a_flexible_content_describes_one_bundle_per_layout(): void
    {
        $specs = $this->build([
            'sections' => ['type' => 'flexible_content', 'label' => 'Sections', 'layouts' => [
                'image_full' => ['label' => 'Image', 'fields' => ['caption' => ['type' => 'text', 'label' => 'Caption']]],
                'quote' => ['label' => 'Quote', 'fields' => ['quote' => ['type' => 'richtext', 'label' => 'Quote']]],
            ]],
        ]);

        self::assertSame(['image_full', 'quote'], $specs[0]->fields[0]->targetBundles);
        self::assertSame(-1, $specs[0]->fields[0]->cardinality);
        self::assertSame(['Image', 'Quote'], [$specs[1]->label, $specs[2]->label]);
    }

    #[Test]
    public function only_editor_authored_fields_become_drupal_fields(): void
    {
        [$spec] = $this->build([
            'title' => ['type' => 'text', 'label' => 'Title'],
            'url' => ['role' => 'derived', 'from' => 'title'],
            'site' => ['role' => 'global', 'type' => 'object', 'fields' => ['name' => ['type' => 'text', 'label' => 'Name']]],
        ]);

        self::assertSame(['field_title'], array_map(static fn ($f): string => $f->machine, $spec->fields));
    }

    #[Test]
    public function the_owned_keys_follow_the_definition(): void
    {
        [$spec] = $this->build([
            'gallery' => ['type' => 'media', 'label' => 'Gallery', 'kind' => 'gallery'],
            'side' => ['type' => 'select', 'label' => 'Side', 'options' => ['left' => 'Left', 'right' => 'Right']],
            'more' => ['type' => 'link', 'label' => 'More', 'shape' => 'url'],
            'phone' => ['type' => 'text', 'label' => 'Phone', 'drupal' => ['storage' => 'telephone']],
            'tags' => ['type' => 'reference', 'label' => 'Tags', 'of' => 'term:tags'],
        ]);
        [$gallery, $side, $more, $phone, $tags] = $spec->fields;

        self::assertSame(-1, $gallery->cardinality);
        self::assertSame('media', $gallery->targetType);
        self::assertNull($gallery->targetBundles, 'not said: the planner keeps or defaults it');
        self::assertSame(['left' => 'Left', 'right' => 'Right'], $side->allowedValues);
        self::assertTrue($more->linkUrlOnly);
        self::assertSame(['telephone'], $phone->accepted);
        self::assertTrue($phone->storagePinned);
        self::assertSame('taxonomy_term', $tags->targetType);
        self::assertSame(['tags'], $tags->targetBundles);
    }

    #[Test]
    public function an_alias_bundle_does_not_own_text_and_prefixed_naming_is_honoured(): void
    {
        $settings = new DrupalSettings(fieldNaming: DrupalSettings::NAMING_PREFIXED, bundleAliases: ['teaser_alt' => 'teaser-box']);
        $specs = $this->build(['title' => ['type' => 'text', 'label' => 'Title']], $settings);

        self::assertSame(['teaser_box', 'teaser_alt'], array_map(static fn (BundleSpec $s): string => $s->bundle, $specs));
        self::assertTrue($specs[0]->ownsText);
        self::assertFalse($specs[1]->ownsText);
        self::assertSame('field_teaser_box_title', $specs[0]->fields[0]->machine);
    }

    #[Test]
    public function no_link_and_no_existing_bundle_means_no_paragraph_type(): void
    {
        $specs = (new BundleSpecBuilder())->build(['name' => 'Stats', 'category' => 'Block', 'fields' => []], 'stats', null);
        self::assertSame([], $specs);

        $config = DrupalConfig::fromDirectory(__DIR__ . '/../../fixtures/drupal/config');
        $specs = (new BundleSpecBuilder())->build(['name' => 'Stats', 'category' => 'Block', 'fields' => []], 'stats', $config);
        self::assertSame('stats', $specs[0]->bundle, 'by convention, when the export has the bundle');
    }

    #[Test]
    public function a_machine_name_over_32_characters_is_an_error(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('at most 32 characters');

        $this->build(['a_very_long_field_name_indeed_here' => ['type' => 'text', 'label' => 'X']]);
    }

    #[Test]
    public function a_pinned_object_needs_its_target_bundle(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('name its paragraph type in drupal.target_bundles');

        $this->build(['body' => ['type' => 'object', 'label' => 'Body', 'drupal' => ['field' => 'field_body'], 'fields' => ['x' => ['type' => 'text', 'label' => 'X']]]]);
    }

    #[Test]
    public function a_bundle_that_nests_itself_is_an_error(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("nests paragraph type 'teaser_box' inside itself");

        $this->build(['items' => ['type' => 'list', 'label' => 'Items', 'drupal' => ['target_bundles' => ['teaser_box']], 'fields' => ['x' => ['type' => 'text', 'label' => 'X']]]]);
    }

    #[Test]
    public function two_fields_on_one_drupal_field_is_an_error(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('both map to Drupal field field_title');

        $this->build([
            'title' => ['type' => 'text', 'label' => 'Title'],
            'name' => ['type' => 'text', 'label' => 'Name', 'drupal' => ['field' => 'field_title']],
        ]);
    }
}
