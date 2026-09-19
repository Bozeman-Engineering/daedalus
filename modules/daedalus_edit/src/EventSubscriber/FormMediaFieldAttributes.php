<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Render\Element;
use Drupal\daedalus_edit\Event\FieldAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Media field attributes.
 */
class FormMediaFieldAttributes implements EventSubscriberInterface {

  use AttributesTrait;

  /**
   * Add form item attributes.
   *
   * Add attributes that associate the form element with the rendered
   * page markup.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The event.
   */
  public function addFormItemAttributesAlter(FieldAttributes $event) {
    if (self::isMediaReferenceField($event)) {
      $entity = $event->getEntity();
      $field_name = $event->getFieldName();
      $form = $event->getForm();
      $form_item = &$form[$field_name];

      $form_item['#attributes']['class'][] = 'daedalus-edit-hidden';
      $form_item['#attributes']['class'][] = 'daedalus-edit-form-item-wrapper';
      $form_item['#attributes']['data-daedalus-edit-handle'] = 'wrapper';
      $form_item['#attributes']['data-daedalus-edit-form-item-wrapper-id'] = sprintf('%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), $field_name, 'value');
      $form_item['#attributes']['data-daedalus-edit-form-item-widget'] = self::getWidget($event);
      $form_item['widget']['#type'] = 'container';
      $form_item['widget']['#attributes']['class'][] = 'daedalus-edit-form-item';
      $form_item['widget']['#attributes']['data-daedalus-edit-form-item-id'] = sprintf('%s::%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), $field_name, 0, 'value');
      $form_item['widget']['#attributes']['data-daedalus-edit-media-value'] = self::getMediaValue($form_item);

      // There could be multiple identical field widgets on the same page. e.g.
      // Say we have a custom block with a field_hero_media field on it. If we
      // have two of the same block types being edited that means two forms on
      // the page with the same field. The AJAX wrapper in MediaLibraryWidget
      // is $field_name . '-media-library-wrapper' . $id_suffix; so we'd have
      // duplicate ID's. Let's add a little more specificity when we are
      // clicking the add and remove buttons on the block form.
      // @see https://www.drupal.org/project/drupal/issues/3345064#comment-15774869
      $wrapper_id = $form_item['widget']['#attributes']['id'] = $form_item['widget']['#attributes']['id'] . '-' . $entity->uuid();
      foreach (Element::children($form_item['widget']['selection']) as $key) {
        $form_item['widget']['media_library_update_widget']['#ajax']['wrapper'] = $wrapper_id;
        $form_item['widget']['selection'][$key]['remove_button']['#ajax']['wrapper'] = $wrapper_id;
      }
      // Add more specificity when we are clicking the Insert selected button in
      // the modal.
      // @see ViewsFormMediaLibraryWidgetAlter
      $state = $form_item['widget']['open_button']['#media_library_state'];
      $parameters = $state->getOpenerParameters();
      if (empty($parameters['field_widget_id'])) {
        throw new \InvalidArgumentException('field_widget_id parameter is missing.');
      }
      $parameters['field_widget_id_and_block_uuid'] = $wrapper_id;
      $state->add(['media_library_opener_parameters' => $parameters]);
      $state->set('hash', $state->getHash());
      $form_item['widget']['media_library_selection']['#attributes']['data-media-library-widget-value'] = $parameters['field_widget_id_and_block_uuid'];
      $form_item['widget']['media_library_update_widget']['#attributes']['data-media-library-widget-update'] = $parameters['field_widget_id_and_block_uuid'];

      $event->setForm($form);
      $event->stopPropagation();
    }
  }

  /**
   * Keeps the after-build stampers off media reference fields.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The field attributes event.
   */
  public static function addFormItemAttributesAfterBuild(FieldAttributes $event) {
    if (self::isMediaReferenceField($event)) {
      $event->stopPropagation();
    }
  }

  /**
   * Is media field.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The FieldAttributes event.
   *
   * @return bool
   *   Whether the field is a Media field.
   */
  public static function isMediaReferenceField(FieldAttributes $event): bool {
    return self::getWidget($event) === 'media_library_widget';
  }

  /**
   * Get media value.
   *
   * Extract all media entity target IDs from the form item's selection.
   *
   * @param array $form_item
   *   The form item render array.
   *
   * @return string
   *   A serialized string of target IDs (JSON encoded array).
   */
  protected static function getMediaValue(array $form_item): string {
    $target_ids = [];

    // Check if selection exists and has children.
    if (isset($form_item['widget']['selection'])) {
      foreach (Element::children($form_item['widget']['selection']) as $delta) {
        // Extract target_id value from each selection item.
        if (isset($form_item['widget']['selection'][$delta]['target_id']['#value'])) {
          $target_ids[] = $form_item['widget']['selection'][$delta]['target_id']['#value'];
        }
      }
    }

    return json_encode($target_ids);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FieldAttributes::ALTER => ['addFormItemAttributesAlter', 100],
      FieldAttributes::AFTER_BUILD => ['addFormItemAttributesAfterBuild', 100],
    ];
  }

}
