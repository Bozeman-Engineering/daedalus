<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Entity Form Interface.
 *
 * Accounts for the differences between an entity form and the block plugin
 * form for Layout Builder pages.
 */
interface EntityFormInterface {

  /**
   * Get form entity.
   *
   * There could be many relevant entities in the case of a page managed by
   * Layout Builder. Get the entity this form applies to.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity this form applies to.
   */
  public function getFormEntity(array &$form, FormStateInterface $form_state): EntityInterface;

  /**
   * Get main entity.
   *
   * This returns either the node or in the case of a Layout Builder page, the
   * parent entity (the node whose layout is being managed probably).
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The main entity of the page.
   */
  public function getMainEntity(array &$form, FormStateInterface $form_state): EntityInterface;

  /**
   * Update form state.
   *
   * Updates the form state and user input for a given field.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field whose value is written into the form state and user input.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the field belongs to.
   */
  public function updateFormState(array &$form, FormStateInterface $form_state, FieldItemListInterface $field, EntityInterface $entity);

  /**
   * Get the user input.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The user input.
   */
  public function getUserInput(FormStateInterface $form_state);

}
