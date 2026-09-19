<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\daedalus_edit\Event\FieldAttributes;
use Drupal\Core\Field\FieldDefinitionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handle field attribute.
 *
 * The "handle" is what DOM element will be used when swapping the form item
 * into the markup of the page for editing. Options:
 *  - form_item:
 */
class FormHandleFieldAttribute implements EventSubscriberInterface {

  /**
   * Stamps the handle attribute on the field's form item.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The field attributes event.
   */
  public function addFormItemAttributesAlter(FieldAttributes $event) {
    $form = $event->getForm();
    $entity = $event->getEntity();
    $field_name = $event->getFieldName();
    $field_definition = $entity->getFieldDefinition($field_name);
    $handle = self::getDefault($field_definition);
    if (method_exists($field_definition, 'getThirdPartySettings')) {
      $third_party_settings = $field_definition->getThirdPartySettings('daedalus_edit');
      if (!empty($third_party_settings['handle'])) {
        $handle = $third_party_settings['handle'];
      }
    }
    $form[$field_name]['#attributes']['data-daedalus-edit-handle'] = $handle;
    $event->setForm($form);
  }

  /**
   * Gets the default handle for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return string
   *   The handle: 'wrapper', 'form_item' or a field-type specific one.
   */
  public static function getDefault(FieldDefinitionInterface $field_definition) {
    $type = $field_definition->getType();
    $map = [
      'entity_reference' => 'wrapper',
      'entity_reference_revisions' => 'wrapper',
    ];
    if (!empty($map[$type])) {
      return $map[$type];
    }
    elseif ($field_definition->getFieldStorageDefinition()->getCardinality() !== 1) {
      return 'wrapper';
    }
    else {
      return 'form_item';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FieldAttributes::ALTER => ['addFormItemAttributesAlter'],
    ];
  }

}
