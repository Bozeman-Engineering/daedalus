<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Form\BundleEditFormAlter as DaedalusUiBundleEditFormAlter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;

/**
 * Adds the per-bundle "Enable styling" setting to bundle edit forms.
 *
 * The checkbox reflects and drives the style map field's existence via
 * DaedalusStylishFieldManager. Unchecking records the deliberate opt-out (the
 * daedalus_stylish.settings opt_outs record) so auto-enablement never re-defaults
 * the bundle ON; checking clears it.
 *
 * The section lives in the shared "Page builder" vertical tab created by
 * Daedalus UI's BundleEditFormAlter::ensurePageBuilderSection().
 */
class BundleEditFormAlter {

  use StringTranslationTrait;

  public function __construct(
    protected DaedalusStylishFieldManager $fieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_alter() for bundle entity edit forms.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof BundleEntityFormBase) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_object->getEntity();
    // Enabling requires an existing bundle; skip the add form.
    if (empty($bundle_entity->id())) {
      return;
    }
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    if (!$bundle_of) {
      return;
    }
    $target_type = $this->entityTypeManager->getDefinition($bundle_of);
    if (!$target_type->entityClassImplements(FieldableEntityInterface::class)) {
      return;
    }

    DaedalusUiBundleEditFormAlter::ensurePageBuilderSection($form);
    $form['page_builder']['daedalus_stylish'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus Stylish'),
      '#open' => TRUE,
    ];
    $form['page_builder']['daedalus_stylish']['daedalus_stylish_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable styling'),
      '#description' => $this->t('Let editors style elements on pages of this type. Disabling deletes the styles already stored on existing content.'),
      '#default_value' => $this->fieldManager->isEnabled($bundle_of, (string) $bundle_entity->id()),
      // Explicit flat #parents: the submit handler reads the flat value.
      '#parents' => ['daedalus_stylish_enabled'],
    ];
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler: sync the field to the checkbox.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    $bundle = (string) $bundle_entity->id();
    $enabled = (bool) $form_state->getValue('daedalus_stylish_enabled');
    if ($enabled === $this->fieldManager->isEnabled($bundle_of, $bundle)) {
      return;
    }
    if ($enabled) {
      $this->fieldManager->enable($bundle_of, $bundle);
    }
    else {
      $this->fieldManager->disable($bundle_of, $bundle);
    }
  }

}
