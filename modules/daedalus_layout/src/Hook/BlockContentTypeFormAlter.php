<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Form\BundleEditFormAlter;

/**
 * Adds the block icon setting to the block type edit form.
 *
 * The icon is a property of the block TYPE, stored as
 * `block_content.type.*.third_party.daedalus_layout.icon` in the icon value grammar
 * (`media:<uuid>` or a root-relative path, see
 * \Drupal\daedalus_blueprint\IconResolver). The section lives in the shared
 * "Page builder" vertical tab created by Daedalus UI's
 * BundleEditFormAlter::ensurePageBuilderSection(), next to the Daedalus UI
 * and Daedalus Stylish sections.
 *
 * Value routing is FLAT (explicit #parents + own submit handler doing
 * setThirdPartySetting()), never a third_party_settings #parents route:
 * copyFormValuesToEntity() would replace the entity's third-party settings
 * wholesale, clobbering keys no form element carries.
 */
class BlockContentTypeFormAlter {

  use StringTranslationTrait;

  /**
   * Implements hook_form_FORM_ID_alter() for block_content_type_edit_form.
   */
  #[Hook('form_block_content_type_edit_form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id): void {
    /** @var \Drupal\block_content\BlockContentTypeInterface $block_type */
    $block_type = $form_state->getFormObject()->getEntity();
    if (empty($block_type->id())) {
      return;
    }
    BundleEditFormAlter::ensurePageBuilderSection($form);
    $form['page_builder']['daedalus_layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus Layout'),
      '#open' => TRUE,
    ];
    $form['page_builder']['daedalus_layout']['icon'] = [
      '#type' => 'daedalus_layout_icon_picker',
      '#title' => $this->t('Block icon'),
      '#description' => $this->t('Represents this block type in the page builder, e.g. on its item in the Add panel. Leave unset for the default block icon.'),
      // Explicit flat #parents: the submit handler reads the flat value.
      '#parents' => ['daedalus_layout_icon'],
      '#default_value' => $block_type->getThirdPartySetting('daedalus_layout', 'icon'),
      '#host' => $block_type,
    ];
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\block_content\BlockContentTypeInterface $block_type */
    $block_type = $form_state->getFormObject()->getEntity();
    $value = trim((string) $form_state->getValue('daedalus_layout_icon'));
    if ($value !== '') {
      $block_type->setThirdPartySetting('daedalus_layout', 'icon', $value);
    }
    else {
      // Unset entirely: "no icon" is the key's absence, not an empty string
      // (an empty value would still ship in config and read as configured).
      $block_type->unsetThirdPartySetting('daedalus_layout', 'icon');
    }
  }

}
