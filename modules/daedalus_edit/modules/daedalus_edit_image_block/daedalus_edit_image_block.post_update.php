<?php

/**
 * @file
 * Post update functions for daedalus_edit_image_block.
 */

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\MediaType;

/**
 * Rebuild the image block as one composed, collapsed element.
 *
 * The image block now renders as a single composed root (img, anchor when
 * linked) instead of the block-wrapper > field > media > formatter chain:
 * - a new field_image_url link field makes the image linkable (Cmd+K and
 *   the balloon link control on media_library sites; the entity form on
 *   fallback-widget sites), created here from the shipped install YAML;
 * - the view display hides both fields — the composed template renders
 *   them via hook_entity_view + preprocess;
 * - the bundle ships the merged presentation, matching the text block.
 */
function daedalus_edit_image_block_post_update_composed_image_root(): ?string {
  $source = new FileStorage(\Drupal::service('extension.list.module')->getPath('daedalus_edit_image_block') . '/config/install');

  if (!FieldStorageConfig::loadByName('block_content', 'field_image_url')) {
    FieldStorageConfig::create($source->read('field.storage.block_content.field_image_url'))->save();
  }
  if (!FieldConfig::loadByName('block_content', 'image', 'field_image_url')) {
    FieldConfig::create($source->read('field.field.block_content.image.field_image_url'))->save();
  }

  /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
  $display_repository = \Drupal::service('entity_display.repository');

  $form_display = $display_repository->getFormDisplay('block_content', 'image');
  if (!$form_display->getComponent('field_image_url')) {
    $form_display->setComponent('field_image_url', [
      'type' => 'link_default',
      'weight' => 27,
      'settings' => [
        'placeholder_url' => '',
        'placeholder_title' => '',
      ],
    ])->save();
  }

  $display_repository->getViewDisplay('block_content', 'image')
    ->removeComponent('field_image')
    ->removeComponent('field_image_url')
    ->save();

  BlockContentType::load('image')
    ->setThirdPartySetting('daedalus_ui', 'presentation', 'merged')
    ->save();

  return t('The image block renders as one composed element: field_image_url added, both fields hidden in the view display, merged presentation set.');
}

/**
 * Allow the Vector image media type in the image block's media reference.
 *
 * SVG enters the suite as a general media capability (the vector_image
 * type, svg_image_field's media bundle). The image block is its first page
 * content consumer: the composed render inlines sanitized SVG markup so
 * fill="currentColor" inherits from the page. Existing sites widen here;
 * the shipped install config carries the widened target_bundles for new
 * installs. Sites without the vector_image media type are skipped, since
 * referencing an absent bundle would ship a broken config dependency; they
 * pick the bundle up when the type arrives (the daedalus_media_vector
 * ingredient, or a composing recipe's own), by re-running this update or
 * saving the field.
 */
function daedalus_edit_image_block_post_update_vector_image_target_bundle(): ?string {
  if (!MediaType::load('vector_image')) {
    return t('No vector_image media type on this site; the image block keeps its current media types.');
  }

  $field = FieldConfig::loadByName('block_content', 'image', 'field_image');
  $settings = $field->getSetting('handler_settings');
  if (isset($settings['target_bundles']['vector_image'])) {
    return t('The image block already accepts Vector image media.');
  }
  $settings['target_bundles']['vector_image'] = 'vector_image';
  $field->setSetting('handler_settings', $settings)->save();

  return t('The image block now accepts Vector image media.');
}
