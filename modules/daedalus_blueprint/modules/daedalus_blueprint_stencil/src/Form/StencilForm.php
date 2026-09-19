<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * The stencil METADATA edit form: label, description, AI offer, published.
 *
 * The element subtree is deliberately not editable here: it is written
 * by the canvas gestures (save-as-stencil, push-update), where the spine
 * validates it. This form only curates the palette entry's face, and the
 * "Offer to the AI" checkbox (the ai_offered base field's own form
 * display) is the stencil's one UI surface for the AI opt-out; the
 * description stays visible while off, since the palette reads it too
 * (it is required, half the palette entry's face). The machine name is
 * identity (placements' provenance stamps reference it), so it is not
 * editable either.
 */
class StencilForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('The stencil %label has been updated.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
