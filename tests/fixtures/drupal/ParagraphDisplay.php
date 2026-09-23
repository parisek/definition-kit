<?php

// Anonymised excerpt of a real Drupal ExtraField display class. It is a test
// fixture for Drupal\DisplayEvidence, which tokenizes it; nothing loads or
// runs it, and the classes it names do not exist here.

namespace Fixture\Plugin\ExtraField\Display;

class ParagraphDisplay extends DisplayBase {

  public function view(ContentEntityInterface $entity) {
    $bundle = $entity->bundle();
    $content = [];

    // shared fields
    $title_field = $this->getTextField($entity, 'title');
    if (!empty($title_field)) {
      $content['heading']['title'] = $title_field;
    }
    $wrapper_id_field = $this->getTextField($entity, 'wrapper_id');
    if (!empty($wrapper_id_field)) {
      $content['wrapper_id'] = $wrapper_id_field;
    }

    if (in_array($bundle, ['html', 'content'])) {
      $content['html'] = $this->getTextareaField($entity, 'content');
      $template = 'content';
    }
    elseif ($bundle === 'card_list') {
      $items = $this->getEntityReferenceField($entity, 'paragraphs', ['return_format' => 'array']);
      foreach ($items as $item) {
        $content['items'][] = [
          'name' => $this->getTextField($item, 'title'),
          'image' => $this->getMediaField($item, 'image'),
          'company' => $this->getTextareaField($item, 'perex'),
          'phone' => $this->getTextField($item, 'phone'),
          'email' => $this->getTextField($item, 'email'),
        ];
      }
      $template = 'card-list';
    }
    elseif ($bundle === 'stats') {
      $items = $this->getEntityReferenceField($entity, 'paragraphs', ['return_format' => 'array']);
      foreach ($items as $item) {
        $content['items'][] = [
          'label' => $this->getTextField($item, 'label'),
          'value' => $this->getTextField($item, 'value'),
          'theme' => $this->getSelectField($item, 'theme'),
          'image' => $this->getMediaField($item, 'media'),
        ];
      }
    }
    elseif ('quote_image' === $bundle) {
      $content['image_side'] = $this->getSelectField($entity, 'image_side');
      $content['quote'] = $this->getTextareaField($entity, 'quote');
      $content['author']['name'] = $this->getTextField($entity, 'author_name');
      $content['signature'] = $this->getMediaField($entity, 'signature');
      $content['image'] = $this->getMediaField($entity, 'media');
      $content['button'] = $this->getLinkField($entity, 'link');
    }
    elseif ($bundle === 'teaser_feed') {
      // A query, not a field: no evidence.
      $items = $storage->loadMultiple($ids);
      foreach ($items as $item) {
        $content['items'][] = [
          'title' => $item->label(),
          'image' => $this->getMediaField($item, 'image'),
        ];
      }
    }
    else {
      $content['message'] = $this->t('Implementation for @template is missing', ['@template' => $bundle]);
      $template = 'alert';
    }

    return ['#content' => $content];
  }
}
