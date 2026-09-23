<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrupalConfigTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/drupal/config';

    #[Test]
    public function it_reads_every_paragraph_type(): void
    {
        $config = DrupalConfig::fromDirectory(self::DIR);

        self::assertContains('card_list', $config->bundles());
        self::assertContains('card_list_item', $config->bundles());
        self::assertTrue($config->hasBundle('teaser'));
        self::assertFalse($config->hasBundle('logo_list'));
        self::assertSame('Quote with image', $config->bundleLabel('quote_image'));
    }

    #[Test]
    public function fields_come_in_form_display_order(): void
    {
        $fields = DrupalConfig::fromDirectory(self::DIR)->fields('card_list_item');

        self::assertSame(['field_title', 'field_image', 'field_perex', 'field_phone', 'field_email'], array_keys($fields));
        self::assertSame('telephone', $fields['field_phone']->type);
        self::assertTrue($fields['field_title']->required);
        self::assertTrue($fields['field_title']->onForm);
    }

    #[Test]
    public function the_instance_cardinality_override_wins_over_the_storage(): void
    {
        $config = DrupalConfig::fromDirectory(self::DIR);

        // field_paragraphs storage is unlimited; the stats instance limits it to 5.
        self::assertSame(5, $config->fields('stats')['field_paragraphs']->cardinality);
        self::assertSame(DrupalField::UNLIMITED, $config->fields('card_list')['field_paragraphs']->cardinality);
        // field_media storage is unlimited; image_full limits it to one.
        self::assertSame(1, $config->fields('image_full')['field_media']->cardinality);
        self::assertTrue($config->fields('quote_image')['field_media']->isMultiple());
    }

    #[Test]
    public function reference_targets_and_allowed_values_are_read(): void
    {
        $config = DrupalConfig::fromDirectory(self::DIR);

        $signature = $config->fields('quote_image')['field_signature'];
        self::assertSame('media', $signature->targetType());
        self::assertSame(['image', 'vector_image'], $signature->targetBundles());

        self::assertSame(['card_list_item'], $config->fields('card_list')['field_paragraphs']->targetBundles());
        self::assertSame([], $config->fields('from_library')['field_reusable_paragraph']->targetBundles());
        self::assertSame(['light' => 'Light', 'dark' => 'Dark'], $config->fields('stats_item')['field_theme']->allowedValues());
    }

    #[Test]
    public function field_groups_are_read_from_the_form_display(): void
    {
        $groups = DrupalConfig::fromDirectory(self::DIR)->groups('card_list');

        self::assertSame(['field_title'], $groups['group_heading']['children']);
        self::assertSame('Advanced', $groups['group_advanced']['label']);
    }

    #[Test]
    public function a_bundle_without_a_form_display_has_unknown_form_presence(): void
    {
        $field = DrupalConfig::fromDirectory(self::DIR)->fields('teaser')['field_title'];

        self::assertNull($field->onForm);
    }

    #[Test]
    public function a_missing_directory_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        DrupalConfig::fromDirectory(self::DIR . '/nope');
    }

    #[Test]
    public function a_directory_without_paragraph_types_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config export');

        DrupalConfig::fromDirectory(__DIR__);
    }
}
