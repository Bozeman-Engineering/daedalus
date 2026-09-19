<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\daedalus_ui\Event\EditableFieldAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the required attributes needed to make media replaceable.
 */
class ReplaceMediaFieldAttributes implements EventSubscriberInterface {

  /**
   * Default upload timeout in milliseconds.
   *
   * Used for the per-media `data-timeout` attribute that the client-side
   * uploader applies to its XHR request. Media uploads can be large (videos,
   * high-resolution images), so this allows several minutes before the request
   * is aborted. Formerly sourced from dropzonejs.settings:upload_timeout_ms.
   */
  const DEFAULT_UPLOAD_TIMEOUT_MS = 300000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the drop-target attribute bag for a media reference.
   *
   * The one source of the data-media-reference vocabulary: every render path
   * that wants file-drag's drop-to-replace behavior (the per-item field
   * wrappers stamped below, a collapsed block's composed root) computes its
   * bag here, so the attributes file-drag.js reads never fork per consumer.
   * Only the media TYPE is required — an empty reference field can attribute
   * its drop target (the first drop is what fills it) from the field's
   * target bundle alone.
   *
   * @param string $field_name
   *   The media reference field name (what file-drag posts the upload to).
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   The media type the drop creates or replaces.
   *
   * @return array
   *   The attribute bag: data-media-reference, data-media-bundle,
   *   data-timeout, and the source field's upload constraints
   *   (data-max-file-size in MB, data-accepted-files) when configured.
   */
  public static function mediaReferenceAttributes(string $field_name, MediaTypeInterface $media_type): array {
    $settings = $media_type->getSource()->getSourceFieldDefinition($media_type)->getSettings();
    $attributes = [
      'data-media-reference' => $field_name,
      'data-media-bundle' => $media_type->id(),
      'data-timeout' => self::DEFAULT_UPLOAD_TIMEOUT_MS,
    ];
    if (!empty($settings['max_filesize'])) {
      $attributes['data-max-file-size'] = round(Bytes::toNumber($settings['max_filesize']) / pow(Bytes::KILOBYTE, 2), 2);
    }
    if (!empty($settings['file_extensions'])) {
      $attributes['data-accepted-files'] = '.' . implode(',.', explode(' ', $settings['file_extensions']));
    }
    return $attributes;
  }

  /**
   * Marks a media reference field's element as replaceable.
   */
  public function onEditableFieldAttributes(EditableFieldAttributes $event) {
    $variables = $event->getVariables();
    $element = &$variables['element'];
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $element['#object'];
    $field_definition = $entity->getFieldDefinition($element['#field_name']);
    $field_storage = $field_definition->getFieldStorageDefinition();
    if ($field_storage->getType() === 'entity_reference' && $field_storage->getSetting('target_type') === 'media') {
      $medias = $entity->get($element['#field_name'])->referencedEntities();
      foreach ($medias as $delta => $media) {
        // Add attributes to media references so user can drop files right on
        // them to replace it.
        /** @var \Drupal\media\MediaTypeInterface $media_type */
        $media_type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
        $attributes = static::mediaReferenceAttributes($element['#field_name'], $media_type);
        foreach ($attributes as $name => $value) {
          $variables['items'][$delta]['content']['edit_mode_wrapper']['#attributes'][$name] = $value;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EditableFieldAttributes::class => ['onEditableFieldAttributes', 200],
    ];
  }

}
