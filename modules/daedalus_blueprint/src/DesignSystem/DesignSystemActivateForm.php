<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms activating a design system: the admin adapter over activate().
 */
class DesignSystemActivateForm extends EntityConfirmFormBase {

  /**
   * The activation.
   */
  protected ActiveDesignSystem $activeDesignSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->activeDesignSystem = $container->get(ActiveDesignSystem::SERVICE_ID);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Activate the %label design system?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The site offers the materials of one design system at a time, beside its global materials. Activating %label switches every editor to its materials at once.', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Activate');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->activeDesignSystem->activate((string) $this->entity->id());
    $this->messenger()->addStatus($this->t('Activated the %label design system.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('entity.design_system.collection'));
  }

}
