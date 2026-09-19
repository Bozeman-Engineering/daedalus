<?php

namespace Drupal\daedalus_edit\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Lets subscribers attribute a field's form items for inline editing.
 *
 * Dispatched once while the entity form is altered (ALTER) and once more in
 * the form's after-build (AFTER_BUILD) for each field on the entity.
 */
class FieldAttributes extends Event {

  const ALTER = 'field_attributes.alter';
  const AFTER_BUILD = 'field_attributes.after_build';

  /**
   * Constructs a FieldAttributes event.
   *
   * @param array $form
   *   The entity form.
   * @param string $view_mode
   *   The view mode the entity is rendered in.
   * @param string $field_name
   *   The field whose form items are being attributed.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function __construct(
    private array $form,
    private string $view_mode,
    private string $field_name,
    private EntityInterface $entity,
    private FormStateInterface $form_state,
  ) {}

  /**
   * Gets the form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  public function getFormState(): FormStateInterface {
    return $this->form_state;
  }

  /**
   * Sets the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function setFormState(FormStateInterface $form_state): void {
    $this->form_state = $form_state;
  }

  /**
   * Gets the entity form.
   *
   * @return array
   *   The form render array.
   */
  public function getForm() {
    return $this->form;
  }

  /**
   * Sets the entity form.
   *
   * @param array $form
   *   The form render array.
   */
  public function setForm($form): void {
    $this->form = $form;
  }

  /**
   * Gets the entity being edited.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Gets the field name.
   *
   * @return string
   *   The field whose form items are being attributed.
   */
  public function getFieldName() {
    return $this->field_name;
  }

  /**
   * Gets the view mode.
   *
   * @return string
   *   The view mode the entity is rendered in.
   */
  public function getViewMode() {
    return $this->view_mode;
  }

}
