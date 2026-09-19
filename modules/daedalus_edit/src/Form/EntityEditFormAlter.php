<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Routing\ResettableStackedRouteMatchInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Entity edit form alter.
 *
 * Alters the regular entity edit pages to add tempstore functionality.
 */
class EntityEditFormAlter {

  use MessengerTrait;
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public function __construct(
    protected ?EntityTempstoreRepository $tempstoreRepository,
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

    $form['actions']['submit']['#submit'][] = [$this, 'clearTempstore'];
    $form['actions']['update'] = [
      '#type' => 'submit',
      '#submit' => [
        '::submitForm',
        [$this, 'updateTempstore'],
      ],
      '#value' => $this->t('Update'),
      '#weight' => 30,
    ];
    $form['actions']['discard'] = [
      '#type' => 'submit',
      '#submit' => [[$this, 'clearTempstore']],
      '#value' => $this->t('Discard changes'),
      '#weight' => 50,
    ];
  }

  /**
   * Update tempstore.
   *
   * Updates the Daedalus Edit tempstore for the currently edited entity.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function updateTempstore(&$form, FormStateInterface $form_state) {
    $this->tempstoreRepository->set($form_state->getFormObject()->getEntity());
  }

  /**
   * Clear tempstore.
   *
   * Clears the Daedalus Edit tempstore for the currently edited entity.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function clearTempstore(&$form, FormStateInterface $form_state) {
    $this->tempstoreRepository->delete($form_state->getFormObject()->getEntity());
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
