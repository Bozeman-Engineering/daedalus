<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemForm;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_stylish\SkinManager;

/**
 * Narrows the design system form's default_skin to the system's members.
 *
 * The blueprint's DesignSystemForm keeps default_skin a free-text
 * machine name: the unit knows no skins, only that a package may name
 * one. daedalus_stylish, which owns the skins, alters that field into a
 * select of the system's MEMBER skins (every skin whose design_system
 * names the system), because the default is the package's answer to
 * "which of my skins" and no other skin can be it. The submit path is
 * untouched: the select's value is the same machine name the textfield
 * carried, and the empty option is the form's own "no skin".
 *
 * A stored default the members no longer include (the skin was deleted
 * or moved to another system) stays selectable under its machine name,
 * so the form never rewrites the unit's config behind the editor's
 * back; the add form has no id yet, so no skin can be its member, and
 * it offers "None" alone.
 */
class DesignSystemFormAlter {

  use StringTranslationTrait;

  public function __construct(
    protected SkinManager $skinManager,
  ) {}

  /**
   * Implements hook_form_alter() for the design system add and edit forms.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof DesignSystemForm || !isset($form['default_skin'])) {
      return;
    }
    $system = $form_object->getEntity();
    assert($system instanceof DesignSystemInterface);
    $options = ['' => $this->t('- None -')];
    if (!$system->isNew()) {
      foreach ($this->skinManager->memberSkins((string) $system->id()) as $machine_name => $skin) {
        $options[$machine_name] = $this->t('@label (@machine_name)', [
          '@label' => $skin->label(),
          '@machine_name' => $machine_name,
        ]);
      }
    }
    $current = $system->getDefaultSkin();
    if ($current !== NULL && !isset($options[$current])) {
      $options[$current] = $this->t('@machine_name (not a member of this design system)', ['@machine_name' => $current]);
    }
    $form['default_skin']['#type'] = 'select';
    $form['default_skin']['#options'] = $options;
    $form['default_skin']['#default_value'] = $current ?? '';
    $form['default_skin']['#description'] = $system->isNew()
      ? $this->t('The skin the site wears with this design system while it has chosen none. A skin joins the system by naming it as its design system; save the system first, then pick one of its member skins here.')
      : $this->t("The skin the site wears with this design system while it has chosen none: the package's answer. A site chooses among the system's published, conforming member skins on the canvas (the skin card) or with drush daedalus_stylish:skin:choose.");
    unset($form['default_skin']['#maxlength']);
  }

}
