<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\daedalus_edit\Event\FieldAttributes;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;

/**
 * Shared lookups for the field attribute subscribers.
 */
trait AttributesTrait {

  /**
   * Gets the widget type of the event's field.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The field attributes event.
   *
   * @return string|null
   *   The widget plugin id, or NULL when the field has no form component.
   */
  protected static function getWidget(FieldAttributes $event): ?string {
    $field_name = $event->getFieldName();
    $form_display = $event->getFormState()->get('form_display');
    if (empty($form_display)) {
      // Must not be an entity form, load the form display.
      $entity = $event->getEntity();
      $form_display = static::getEntityDisplayRepository()->getFormDisplay($entity->getEntityTypeId(), $entity->bundle());
    }

    // Not every dispatched field has a form-display component: entity forms
    // carry non-widget elements for real fields (a node form's hidden
    // 'changed' element), and the dispatch loop only checks that the form
    // element exists. No component means no widget — NULL, the declared
    // "no widget" answer every subscriber's id comparison handles.
    return $form_display->getComponent($field_name)['type'] ?? NULL;
  }

  /**
   * Get entity display repository.
   *
   * @return \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   *   The entity display repository service.
   */
  protected static function getEntityDisplayRepository(): EntityDisplayRepositoryInterface {
    return \Drupal::service('entity_display.repository');
  }

}
