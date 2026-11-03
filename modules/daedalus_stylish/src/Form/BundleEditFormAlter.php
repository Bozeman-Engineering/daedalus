<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Form\BundleEditFormAlter as DaedalusUiBundleEditFormAlter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;

/**
 * Adds the per-bundle "Let editors style elements" box to bundle forms.
 *
 * The checkbox reflects and drives the style map field's existence via
 * DaedalusStylishFieldManager; on the add form, where no field exists yet,
 * it offers the gate's default (on) and the first save writes the choice
 * once the bundle exists. Unchecking records the deliberate opt-out (the
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
    // What the bundle stores today: the field's existence, or nothing yet
    // on a new bundle (the add form), whose box offers the gate's default,
    // on. The element carries the stored fact to ::submit(), which writes
    // only a change: on a new bundle every value is one (an enable creates
    // the field, a clear records the opt-out that keeps auto-enablement
    // off), on an existing bundle only a flipped box.
    $stored = $bundle_entity->isNew() ? NULL : $this->fieldManager->isEnabled($bundle_of, $bundle);
    $enabled = $stored ?? TRUE;
    $group = &$form['page_builder'][DaedalusUiBundleEditFormAlter::GROUP_STYLING];
    $group['daedalus_stylish_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let editors style elements'),
      '#description' => $this->t('Colors, spacing, type and variants on anything on the page.'),
      '#default_value' => $enabled,
      '#daedalus_stylish_stored' => $stored,
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
    // After the form's own ::save, not before it like the handlers that
    // write a third-party setting onto the bundle entity: the field this
    // creates or deletes is a config entity of its own that depends on the
    // bundle, so the bundle must exist with its id first (on the add form
    // it has neither until ::submitForm builds it and ::save stores it).
    $form['actions']['submit']['#submit'][] = [$this, 'submit'];
  }

  /**
   * Submit handler: sync the field to the checkbox.
   *
   * Runs after the bundle entity is saved, so on the add form the bundle's
   * id is the saved one and the field it creates depends on a bundle that
   * exists. The bundle's own save already ran the auto-enable gate
   * (_daedalus_stylish_gate_check()), which skips an enabled or opted-out
   * bundle and never undoes this handler: the opt-out a clear records
   * here holds through later gate checks.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    $bundle = (string) $bundle_entity->id();
    $enabled = (bool) $form_state->getValue('daedalus_stylish_enabled');
    // The value the bundle stored when the form was built (NULL on the add
    // form: nothing yet). A box left at the stored value is no choice; with
    // nothing stored either value is one, so a new bundle's cleared box is
    // a deliberate opt-out even though no field exists yet to delete. The
    // stored fact is read from the build, not re-read here: the bundle's
    // save just ran the auto-enable gate, and a bundle the gate enabled
    // during this very save must not read as "the editor cleared it".
    $stored = $form['page_builder'][DaedalusUiBundleEditFormAlter::GROUP_STYLING]['daedalus_stylish_enabled']['#daedalus_stylish_stored'];
    if ($stored !== NULL && $enabled === $stored) {
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
