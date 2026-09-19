<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_edit\Event\FieldAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Default field attributes.
 */
class FormDefaultFieldAttributes implements EventSubscriberInterface {

  use AttributesTrait;

  /**
   * Add form item attributes.
   *
   * Add attributes that associate the form element with the rendered
   * page markup, and ship the wrapper hidden.
   *
   * Every field wrapper leaves the server hidden and nothing else is
   * decided here: whether the field has a leaf on the canvas is a theme
   * and layout fact (the product theme places no page title block; an
   * empty or unplaced Layout Builder field renders nothing) the server
   * cannot see, and the view display's hidden list was a proxy that
   * failed exactly there. The client reveals the wrappers with no canvas
   * leaf and binds their changes to the tempstore auto-save.
   *
   * @see js/daedalus_edit/panel-widget-visibility.js
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The event.
   */
  public function addFormItemAttributesAlter(FieldAttributes $event) {
    $field_name = $event->getFieldName();
    $form = $event->getForm();
    $form_item = &$form[$field_name];
    $entity = $event->getEntity();
    $form_item['#attributes']['data-daedalus-edit-form-item-wrapper-id'] = sprintf('%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), $field_name, 'value');
    $form_item['#attributes']['data-daedalus-edit-form-item-widget'] = self::getWidget($event);
    $form_item['#attributes']['class'][] = 'daedalus-edit-form-item-wrapper';
    $form_item['#attributes']['class'][] = 'daedalus-edit-hidden';

    $event->setForm($form);
  }

  /**
   * Attributes the built form items with their page markup association.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The field attributes event.
   */
  public function addFormItemAttributesAfterBuild(FieldAttributes $event) {
    $form_state = $event->getFormState();
    $form = $event->getForm();
    $entity = $event->getEntity();
    $field_name = $event->getFieldName();
    $form_item = &$form[$field_name];

    $properties = $entity->getFieldDefinition($field_name)->getFieldStorageDefinition()->getPropertyNames();

    if (!empty($form_item['widget']['#theme']) && $form_item['widget']['#theme'] === 'field_multiple_value_form') {
      foreach (Element::children($form_item['widget']) as $delta) {
        foreach ($properties as $property) {
          if (!empty($form_item['widget'][$delta][$property])) {
            $form_item['widget'][$delta][$property] = $this->addFormItemAttributes($form_state, $form_item['widget'][$delta][$property], $entity, $field_name, $delta, $property);
          }
        }
      }
    }
    elseif (!empty($form_item['widget']['#type']) && $form_item['widget']['#type'] === 'checkboxes') {
      foreach (Element::children($form_item['widget']) as $delta) {
        foreach ($properties as $property) {
          if (!empty($form_item['widget'][$delta])) {
            $form_item['widget'][$delta] = $this->addFormItemAttributes($form_state, $form_item['widget'][$delta], $entity, $field_name, $delta, $property);
          }
        }
      }

    }
    // Add attributes to fields with no children.
    else {
      $form_item['widget'] = $this->addFormItemAttributes($form_state, $form_item['widget'], $entity, $field_name, '0', end($properties));
    }
    $event->setForm($form);
  }

  /**
   * Adds attributes that associate the form element with the page markup.
   */
  private function addFormItemAttributes(FormStateInterface $form_state, &$form_item, EntityInterface $entity, $field_name, $delta, $property) {
    $form_item_id = sprintf('%s::%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), $field_name, $delta, $property);

    $form_item['#wrapper_attributes'] = new Attribute([
      'class' => ['daedalus-edit-form-item'],
      'data-daedalus-edit-form-item-id' => $form_item_id,
    ]);
    if (!empty($form_item['#format'])) {
      $form_item['#wrapper_attributes']->setAttribute('data-daedalus-edit-format', $form_item['#format']);
    }
    // Add an effect to fields that were just added to the page.
    if ($form_state->get('added_to_page') === $field_name) {
      $definition = $entity->getFieldDefinition($field_name);
      $main_property = method_exists($definition, 'getMainPropertyName') ? $definition->getMainPropertyName() : 'value';
      if ($main_property === $property) {
        $form_item['#wrapper_attributes']->addClass('daedalus-edit-field-added-to-page');
      }
    }
    return $form_item;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FieldAttributes::ALTER => ['addFormItemAttributesAlter', -1],
      FieldAttributes::AFTER_BUILD => ['addFormItemAttributesAfterBuild', -1],
    ];
  }

}
