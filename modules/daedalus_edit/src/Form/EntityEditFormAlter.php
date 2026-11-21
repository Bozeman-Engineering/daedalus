<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\CommitRefusedException;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Routing\ResettableStackedRouteMatchInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Entity edit form alter.
 *
 * Alters the regular entity edit page of a host whose unsaved copy the
 * form was built on: the page gains an Update button (stage what the form
 * built) and a Discard button, and its Save commits. All three go through
 * the storage handler's doors (persist, discard, commit), the same ones
 * every other road to the host's stage and row takes, so the page has no
 * write of its own: the commit door's checks (the page saved elsewhere,
 * whole-entity validation) and the persisted hook hold here too.
 *
 * The alter keys on what the form is, never on a mode the request names:
 * a content entity form, on the entity's own edit-form route, built on the
 * unsaved copy the route's converter swapped in.
 */
class EntityEditFormAlter {

  use MessengerTrait;
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public function __construct(
    protected EntityStorageHandler $storageHandler,
    protected HostResolver $hostResolver,
    protected ResettableStackedRouteMatchInterface $routeMatch,
  ) {}

  /**
   * Alter active tempstore entity edit forms.
   *
   * @param array $form
   *   Forms.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    if (!$this->isTempstoredEntityEditRoute($form_state)) {
      return;
    }

    // The form was built on the unsaved copy, so its own save would write
    // that copy and the form's values to the database beside the commit
    // door. The save leaves the chain for the door, in its place, so a
    // site's handlers before and after it run as they did. A Save this
    // alter cannot put on the door must not fall back onto the form's own.
    $handlers = $form['actions']['submit']['#submit'] ?? [];
    if (!in_array('::save', $handlers, TRUE)) {
      throw new \LogicException(sprintf('Daedalus Edit: the edit form of %s names no save to commit through the storage handler.', $form_state->getFormObject()->getEntity()->getEntityTypeId()));
    }
    $form['actions']['submit']['#submit'] = array_map(
      fn ($handler) => $handler === '::save' ? [$this, 'commitEntity'] : $handler,
      $handlers,
    );
    $form['actions']['update'] = [
      '#type' => 'submit',
      '#submit' => [
        '::submitForm',
        [$this, 'stageEntity'],
      ],
      '#value' => $this->t('Update'),
      '#weight' => 30,
    ];
    // A discard reads nothing the form holds, so an invalid form discards.
    $form['actions']['discard'] = [
      '#type' => 'submit',
      '#submit' => [[$this, 'discardStage']],
      '#limit_validation_errors' => [],
      '#value' => $this->t('Discard changes'),
      '#weight' => 50,
    ];
  }

  /**
   * Stages the edited entity through the persist door.
   *
   * The core entity edit page's Update button: the whole form-built entity
   * is staged (this is the page's own form, not the panel's one-field
   * transport).
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function stageEntity(&$form, FormStateInterface $form_state) {
    $this->storageHandler->persist($this->formHost($form_state));
  }

  /**
   * Commits the edited entity through the commit door.
   *
   * The edit page's Save, in the place of the form's own save: the
   * form-built entity (the unsaved copy plus the form's values) is saved by
   * the door, which ends the stage. A refusal saves nothing and leaves the
   * stage: its messages are shown and the form comes back as submitted.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, after the entity form's own submit handler.
   */
  public function commitEntity(&$form, FormStateInterface $form_state) {
    $host = $this->formHost($form_state);
    try {
      $this->storageHandler->commit($host);
    }
    catch (CommitRefusedException $refusal) {
      foreach ($refusal->messages() as $message) {
        $this->messenger()->addError($message);
      }
      $form_state->setRebuild();
      return;
    }
    $entity = $host->entity();
    $this->messenger()->addStatus($this->t('@label has been saved.', ['@label' => $entity->label()]));
    if ($entity->hasLinkTemplate('canonical')) {
      $form_state->setRedirectUrl($entity->toUrl());
    }
  }

  /**
   * Drops the edited entity's unsaved copy through the discard door.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function discardStage(&$form, FormStateInterface $form_state) {
    $this->storageHandler->discard($this->formHost($form_state));
  }

  /**
   * The host of the entity the form holds.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host of the form's entity: the form-built one after the entity
   *   form's own submit handler, the unsaved copy before it.
   */
  protected function formHost(FormStateInterface $form_state): HostInterface {
    return $this->hostResolver->fromEntity($form_state->getFormObject()->getEntity());
  }

  /**
   * Is tempstore entity edit route.
   *
   * Determines if the edited entity has an Daedalus Edit tempstore version.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when the form edits an entity active in the tempstore.
   */
  protected function isTempstoredEntityEditRoute(FormStateInterface $form_state) {
    $actively_used_tempstore_entities = daedalus_edit_active_tempstore_entities();
    $form_object = $form_state->getFormObject();
    if (!empty($actively_used_tempstore_entities) && $form_object instanceof ContentEntityForm) {
      $entity = $form_object->getEntity();
      foreach ($actively_used_tempstore_entities as $tempstore_entity) {
        // Only form alter the edit page of the tempstore entity.
        if (
          $entity->getEntityTypeId() === $tempstore_entity->getEntityTypeId() &&
          $entity->id() === $tempstore_entity->id() &&
          $entity->toUrl('edit-form')->getRouteName() === $this->routeMatch->getRouteName()
        ) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
