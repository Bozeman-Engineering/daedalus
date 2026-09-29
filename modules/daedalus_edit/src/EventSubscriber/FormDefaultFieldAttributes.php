<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_edit\EditIdentity;
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
   * leaf; their widgets commit through the form transport.
   *
   * The wrapper id is the field's main property through EditIdentity, the
   * minter the render side uses: the client looks the wrapper up by the
   * property it parsed from the leaf's edit id, so a base reference field
   * (uid, target_id) finds its widget exactly as a text field does.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The event.
   *
   * @see js/daedalus_edit/panel-widget-visibility.js
   */
  public function addFormItemAttributesAlter(FieldAttributes $event) {
    $field_name = $event->getFieldName();
    $form = $event->getForm();
    $form_item = &$form[$field_name];
    $entity = $event->getEntity();
    $form_item['#attributes']['data-daedalus-edit-form-item-wrapper-id'] = EditIdentity::fieldWrapperId($entity, $field_name, EditIdentity::mainProperty($entity->getFieldDefinition($field_name)));
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

    // A field's form item is its widget. An element under a field name with
    // no widget (the hidden `changed` input ContentEntityForm adds) is not
    // one, and hanging a wrapper child on it here, after the build, would
    // leave an unbuilt element for the form error walk to trip on.
    if (!isset($form_item['widget'])) {
      return;
    }

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
    $form_item_id = EditIdentity::itemId($entity, $field_name, $delta, $property);

    $form_item['#wrapper_attributes'] = new Attribute([
      'class' => ['daedalus-edit-form-item'],
      'data-daedalus-edit-form-item-id' => $form_item_id,
    ]);
    if (!empty($form_item['#format'])) {
      $form_item['#wrapper_attributes']->setAttribute('data-daedalus-edit-format', $form_item['#format']);
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
