<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalField;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function a_stale_cardinality_override_does_not_beat_the_storage(): void
    {
        $config = DrupalConfig::fromDirectory(__DIR__ . '/../fixtures/drupal-cardinality/config');

        // The module offers "unlimited" only on an unlimited storage, and a limit only up to the storage's.
        // A storage of 1 with an override of -1 (seen on a real site) is a leftover: the storage rules.
        self::assertSame(1, $config->fields('stale_link')['field_link']->cardinality);
        self::assertFalse($config->fields('stale_link')['field_link']->isMultiple());
        self::assertSame(3, $config->fields('stale_link')['field_items']->cardinality);
        self::assertSame(3, $config->fields('too_wide')['field_items']->cardinality);
        // A real narrowing still wins.
        self::assertSame(1, $config->fields('valid_narrow')['field_media']->cardinality);
        self::assertSame(2, $config->fields('valid_narrow')['field_items']->cardinality);
    }

    #[Test]
    #[DataProvider('overrides')]
    public function it_applies_an_override_only_inside_what_the_storage_allows(int $storage, mixed $override, int $expected): void
    {
        $instance = ['third_party_settings' => ['field_config_cardinality' => ['cardinality_config' => $override]]];

        self::assertSame($expected, DrupalConfig::effectiveCardinality(['cardinality' => $storage], $instance));
    }

    /** @return iterable<string,array{int,mixed,int}> */
    public static function overrides(): iterable
    {
        yield 'unlimited storage, limit' => [-1, '5', 5];
        yield 'unlimited storage, unlimited' => [-1, '-1', -1];
        yield 'limited storage, narrower' => [4, '2', 2];
        yield 'limited storage, same' => [4, '4', 4];
        yield 'limited storage, wider' => [4, '9', 4];
        yield 'limited storage, unlimited' => [4, '-1', 4];
        yield 'single storage, unlimited' => [1, '-1', 1];
        yield 'single storage, wider' => [1, '3', 1];
        yield 'zero is no override' => [-1, '0', -1];
        yield 'below -1 is no override' => [3, '-2', 3];
        yield 'empty is no override' => [3, '', 3];
        yield 'not a number is no override' => [3, 'many', 3];
        yield 'no override' => [3, null, 3];
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
