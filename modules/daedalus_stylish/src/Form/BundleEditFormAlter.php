<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Form\BundleEditFormAlter as DaedalusUiBundleEditFormAlter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;

/**
 * Adds the per-bundle "Let editors style elements" box to bundle edit forms.
 *
 * The checkbox reflects and drives the style map field's existence via
 * DaedalusStylishFieldManager. Unchecking records the deliberate opt-out (the
 * daedalus_stylish.settings opt_outs record) so auto-enablement never re-defaults
 * the bundle ON; checking clears it. Clearing the box reveals what the save
 * deletes: the count of the bundle's entities that store styles today
 * (core's #states, shown only when the box is cleared and there is
 * something to lose).
 *
 * The box sits in the Styling group of the shared "Page builder" section
 * Daedalus UI owns (BundleEditFormAlter::ensureGroup()), which also decides
 * whether the section belongs on the form at all.
 */
class BundleEditFormAlter {

  use StringTranslationTrait;

  public function __construct(
    protected DaedalusStylishFieldManager $fieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DaedalusUiBundleEditFormAlter $sectionOwner,
  ) {}

  /**
   * Implements hook_form_alter() for bundle entity edit forms.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    if (!$this->sectionOwner->ensureGroup($form, $form_state, DaedalusUiBundleEditFormAlter::GROUP_STYLING)) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    $bundle = (string) $bundle_entity->id();
    $enabled = $this->fieldManager->isEnabled($bundle_of, $bundle);
    $group = &$form['page_builder'][DaedalusUiBundleEditFormAlter::GROUP_STYLING];
    $group['daedalus_stylish_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let editors style elements'),
      '#description' => $this->t('Colors, spacing, type and variants on anything on the page.'),
      '#default_value' => $enabled,
      // Explicit flat #parents: the submit handler reads the flat value.
      '#parents' => ['daedalus_stylish_enabled'],
      '#attributes' => [DaedalusUiBundleEditFormAlter::SUMMARY_ATTRIBUTE => $this->t('Styling on')],
    ];
    $styled = $enabled ? $this->fieldManager->countStyled($bundle_of, $bundle) : 0;
    if ($styled > 0) {
      $target_type = $this->entityTypeManager->getDefinition($bundle_of);
      $group['daedalus_stylish_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning'], 'role' => 'alert'],
        '#states' => [
          'visible' => [
            ':input[name="daedalus_stylish_enabled"]' => ['checked' => FALSE],
          ],
        ],
        'text' => [
          '#markup' => $this->formatPlural(
            $styled,
            'Saving with this off deletes the styles stored on the 1 existing @item. This cannot be undone.',
            'Saving with this off deletes the styles stored on @count existing @items. This cannot be undone.',
            ['@item' => $target_type->getSingularLabel(), '@items' => $target_type->getPluralLabel()],
          ),
        ],
      ];
    }
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
