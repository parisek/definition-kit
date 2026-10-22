<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Migration\DrupalParagraphReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The display class names a template prop for one Drupal field, and that
 * name is the natural prop name of another field of the same bundle. Both
 * shapes come from real sites: a `$content['video']` array literal fed by
 * the `media` and `gallery` fields while a `video` field has no evidence,
 * and `'date' => getDateField($item, 'datetime')` on a bundle that also has
 * a `date` field.
 */
final class DrupalEvidenceCollisionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/evidence-collision-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function a_field_without_evidence_does_not_take_the_prop_name_an_evidence_path_uses(): void
    {
        $this->bundle('video', ['field_video', 'field_media', 'field_gallery']);
        $tree = $this->read('video', <<<'PHP'
            <?php
            if ($bundle === 'video') {
              $video = $this->getMediaField($entity, 'video');
              $content['video'] = [
                'id' => $video,
                'posterUrl' => $this->getMediaField($entity, 'media'),
                'videoPoster' => $this->getMediaField($entity, 'gallery'),
              ];
            }
            PHP);

        // The evidence wins its path; the other field gets a free name and a pin.
        self::assertSame('field_media', $tree['video']['fields']['posterUrl']['drupal']['field']);
        self::assertSame('field_gallery', $tree['video']['fields']['videoPoster']['drupal']['field']);
        self::assertSame('field_video', $tree['video_field']['drupal']['field']);
        self::assertSame(['video', 'video_field'], array_keys($tree));
    }

    #[Test]
    public function the_result_does_not_depend_on_the_order_of_the_fields(): void
    {
        $this->bundle('video', ['field_gallery', 'field_media', 'field_video']);
        $tree = $this->read('video', <<<'PHP'
            <?php
            if ($bundle === 'video') {
              $content['video'] = [
                'posterUrl' => $this->getMediaField($entity, 'media'),
                'videoPoster' => $this->getMediaField($entity, 'gallery'),
              ];
            }
            PHP);

        self::assertSame('field_video', $tree['video_field']['drupal']['field']);
        self::assertArrayHasKey('posterUrl', $tree['video']['fields']);
    }

    #[Test]
    public function a_list_item_field_without_evidence_keeps_out_of_the_way_of_a_mapped_item_prop(): void
    {
        // `field_a_title` sorts before `field_title`: the mapped field is placed first.
        $this->bundle('train_table', ['field_paragraphs'], 'train_table_item');
        $this->bundle('train_table_item', ['field_a_title', 'field_title']);
        $item = $this->read('train_table', $this->itemSource('title', 'a_title'))['items']['fields'];

        self::assertSame('field_a_title', $item['title']['drupal']['field']);
        self::assertSame('field_title', $item['title_field']['drupal']['field']);
    }

    #[Test]
    public function a_mapped_item_prop_wins_even_when_the_field_that_names_it_comes_first(): void
    {
        // `field_date` sorts before `field_datetime`: without evidence it would take `date` first.
        $this->bundle('train_table', ['field_paragraphs'], 'train_table_item');
        $this->bundle('train_table_item', ['field_date', 'field_datetime']);
        $item = $this->read('train_table', $this->itemSource('date', 'datetime'))['items']['fields'];

        self::assertSame('field_datetime', $item['date']['drupal']['field']);
        self::assertSame('field_date', $item['date_field']['drupal']['field']);
    }

    #[Test]
    public function a_new_name_does_not_take_the_natural_name_of_another_field(): void
    {
        // `field_video` is claimed by evidence; `video_field` is the natural name of `field_video_field`.
        foreach ([['field_video', 'field_video_field'], ['field_video_field', 'field_video']] as $order) {
            $tree = $this->readVideo([...$order, 'field_media']);

            self::assertSame('field_video_field', $tree['video_field']['label'] ?? null, implode(',', $order));
            self::assertSame('field_video', $tree['video_field_2']['drupal']['field'] ?? null, implode(',', $order));
            self::assertArrayHasKey('posterUrl', $tree['video']['fields']);
        }
    }

    #[Test]
    public function the_pick_goes_on_past_every_name_in_use(): void
    {
        // `video_field_2` is also the natural name of a field (`field_video_field_2`).
        foreach ([['field_video', 'field_video_field', 'field_video_field_2'], ['field_video_field_2', 'field_video_field', 'field_video']] as $order) {
            $tree = $this->readVideo([...$order, 'field_media']);

            self::assertSame('field_video_field', $tree['video_field']['label'] ?? null, implode(',', $order));
            self::assertSame('field_video_field_2', $tree['video_field_2']['label'] ?? null, implode(',', $order));
            self::assertSame('field_video', $tree['video_field_3']['drupal']['field'] ?? null, implode(',', $order));
        }
    }

    /**
     * @param list<string> $fields
     * @return array<string,array<string,mixed>>
     */
    private function readVideo(array $fields): array
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        $this->bundle('video', $fields);

        return $this->read('video', <<<'PHP'
            <?php
            if ($bundle === 'video') {
              $content['video'] = [
                'posterUrl' => $this->getMediaField($entity, 'media'),
              ];
            }
            PHP);
    }

    private function itemSource(string $prop, string $field): string
    {
        return <<<PHP
            <?php
            if (\$bundle === 'train_table') {
              \$items = \$this->getEntityReferenceField(\$entity, 'paragraphs');
              foreach (\$items as \$item) {
                \$content['items'][] = [
                  '{$prop}' => \$this->getTextField(\$item, '{$field}'),
                ];
              }
            }
            PHP;
    }

    /** @param list<string> $fields */
    private function bundle(string $bundle, array $fields, ?string $itemBundle = null): void
    {
        $this->put("paragraphs.paragraphs_type.{$bundle}.yml", ['id' => $bundle, 'label' => $bundle]);
        foreach ($fields as $field) {
            $nested = 'field_paragraphs' === $field;
            $this->put("field.storage.paragraph.{$field}.yml", [
                'field_name' => $field,
                'entity_type' => 'paragraph',
                'type' => $nested ? 'entity_reference_revisions' : 'string',
                'settings' => $nested ? ['target_type' => 'paragraph'] : [],
                'cardinality' => $nested ? -1 : 1,
            ]);
            $this->put("field.field.paragraph.{$bundle}.{$field}.yml", [
                'field_name' => $field,
                'entity_type' => 'paragraph',
                'bundle' => $bundle,
                'label' => $field,
                'required' => false,
                'settings' => $nested ? ['handler' => 'default:paragraph', 'handler_settings' => ['target_bundles' => [(string) $itemBundle => (string) $itemBundle]]] : [],
                'field_type' => $nested ? 'entity_reference_revisions' : 'string',
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function put(string $name, array $data): void
    {
        file_put_contents($this->dir . '/' . $name, Yaml::dump($data, 6, 2));
    }

    /** @return array<string,array<string,mixed>> */
    private function read(string $bundle, string $displaySource): array
    {
        $reader = new DrupalParagraphReader(
            DrupalConfig::fromDirectory($this->dir),
            new DrupalSettings(),
            DisplayEvidence::fromSource($displaySource),
        );

        return $reader->read($bundle);
    }
}
