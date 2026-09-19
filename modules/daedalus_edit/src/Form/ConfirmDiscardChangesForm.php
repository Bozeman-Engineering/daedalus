<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms discarding the tempstore changes of the listed entities.
 */
class ConfirmDiscardChangesForm extends ConfirmFormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTempstoreRepository $tempstoreRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form_state->set('workspace_safe', TRUE);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'discard_changes_confirmation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to discard changes?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Discard changes');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $parameter = $this->getRequest()->attributes->get('entities');
    if (empty($parameter)) {
      throw new \InvalidArgumentException('No entities to save.');
    }
    foreach (explode('::', $parameter) as $entity) {
      [$entity_type_id, $entity_id] = explode('.', $entity);
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
      Cache::invalidateTags([daedalus_edit_cache_tag($entity)]);
      $this->tempstoreRepository->delete($entity);
    }
  }

}
