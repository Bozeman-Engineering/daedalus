<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms discarding the tempstore changes of the route's host.
 *
 * The host is a content entity or a Layout Builder display (its staged
 * template). The stage ends through the storage handler's discard door,
 * the one door out of a stage beside the commit: it drops the stage the
 * top bar's Save would have committed and tells the persisted hook's
 * listeners the working copy changed back.
 */
class ConfirmDiscardChangesForm extends ConfirmFormBase {

  public function __construct(
    protected EntityStorageHandler $storageHandler,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint.host_resolver'),
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
    $this->storageHandler->discard($this->hostResolver->fromEntity($entity));
  }

}
