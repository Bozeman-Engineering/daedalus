<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms discarding the tempstore changes of the route's host.
 *
 * The host is a content entity or a Layout Builder display (its staged
 * template); the tempstore repository routes either to the strategy that
 * stages it, so one delete drops the stage the top bar's Save would have
 * committed.
 */
class ConfirmDiscardChangesForm extends ConfirmFormBase {

  public function __construct(
    protected EntityTempstoreRepository $tempstoreRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type = NULL, ?EntityInterface $entity = NULL) {
    if ($entity === NULL) {
      throw new \InvalidArgumentException('No host to discard the changes of.');
    }
    $form_state->set('workspace_safe', TRUE);
    $form_state->set('daedalus_edit_host', $entity);
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
    $entity = $form_state->get('daedalus_edit_host');
    if (!$entity instanceof EntityInterface) {
      throw new \InvalidArgumentException('No host to discard the changes of.');
    }
    Cache::invalidateTags([daedalus_edit_cache_tag($entity)]);
    $this->tempstoreRepository->delete($entity);
  }

}
