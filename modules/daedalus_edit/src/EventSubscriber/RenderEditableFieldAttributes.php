<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_edit\EditIdentity;
use Drupal\daedalus_edit\Event\SuppressInlineEditing;
use Drupal\daedalus_ui\Event\EditableFieldAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Adds the required attributes needed to make the field inline editable.
 */
class RenderEditableFieldAttributes implements EventSubscriberInterface {

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Attributes a rendered field as inline editable.
   *
   * @param \Drupal\daedalus_ui\Event\EditableFieldAttributes $event
   *   The field attribution event.
   */
  public function onEditableFieldAttributes(EditableFieldAttributes $event) {

    $suppression = $this->eventDispatcher->dispatch(new SuppressInlineEditing($event), SuppressInlineEditing::class);
    if ($suppression->isSuppressed()) {
      return;
    }

    $variables =& $event->getVariables();
    $variables['#cache']['contexts'][] = 'user.permissions';
    if (!$this->currentUser->hasPermission('access inline editing')) {
      return;
    }

    $element = &$variables['element'];
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $element['#object'];

    $field_definition = $entity->getFieldDefinition($element['#field_name']);
    // Check if inline editing has been disabled for this field.
    if (method_exists($field_definition, 'getThirdPartySettings')) {
      $third_party_settings = $field_definition->getThirdPartySettings('daedalus_edit');
      if (!empty($third_party_settings['disable'])) {
        return;
      }
    }

    $view_mode = $element['#view_mode'];
    if ($view_mode === '_custom') {
      // Support layout builder field blocks.
      if (!empty($element['#third_party_settings']['layout_builder']['view_mode'])) {
        $view_mode = $element['#third_party_settings']['layout_builder']['view_mode'];
      }
      else {
        // Layout Builder (and Daedalus Edit) only supports view modes, not dynamically
        // defined "display options" (which
        // \Drupal\Core\Field\FieldItemListInterface::view() always names the
        // "_custom" view mode).
        // @see \Drupal\Core\Field\FieldItemListInterface::view()
        // @see https://www.drupal.org/node/2120335
        return;
      }
    }
    // Get the actual view mode.
    $view_mode = _daedalus_ui_get_view_mode($entity, $view_mode);
    // The identities the form side must match (EditIdentity, the one
    // minter): the field's main property on every shape.
    $field_name = $element['#field_name'];
    $main_property = EditIdentity::mainProperty($field_definition);

    $variables['attributes']['data-daedalus-edit-field-value-wrapper'] = EditIdentity::fieldWrapperId($entity, $field_name, $main_property);

    if (!empty($variables['items'])) {
      foreach ($variables['items'] as $delta => &$item) {
        $item['content']['edit_mode_wrapper']['#attributes']['class'] = ['daedalus-edit-field-value'];
        $item['content']['edit_mode_wrapper']['#attributes']['data-daedalus-edit-id'] = EditIdentity::editId($entity, $field_name, $delta, $element['#language'], $element['#field_type'], $element['#formatter'], $main_property);
        $item['content']['edit_mode_wrapper']['#attributes']['data-daedalus-edit-page-element-id'] = EditIdentity::itemId($entity, $field_name, $delta, $main_property);
        $item['content']['edit_mode_wrapper']['#cache']['tags'][] = daedalus_edit_cache_tag($entity);
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
