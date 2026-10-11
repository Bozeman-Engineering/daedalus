<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * The blueprint DETAILS form: label, description, subject, hosts, AI, status.
 *
 * The document is deliberately not edited here: it is composed on the
 * blueprint's own canvas (the canonical route, in Edit Mode), where the
 * spine validates it. This form curates the palette entry's face and what
 * the material is for; the machine name and the scope are identity
 * (placements' provenance stamps reference the name, and what a blueprint
 * stands in for does not change with its content), so neither is editable.
 */
class BlueprintForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('The blueprint %label has been updated.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
