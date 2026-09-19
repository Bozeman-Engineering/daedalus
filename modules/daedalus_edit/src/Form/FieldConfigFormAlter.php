<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_edit\EventSubscriber\FormHandleFieldAttribute;
use Drupal\daedalus_edit\LinkAutocomplete;
use Drupal\daedalus_ui\Form\FieldConfigFormAlter as DaedalusUiFieldConfigFormAlter;

/**
 * Field config form alter.
 *
 * Daedalus Edit field settings, co-located in the shared "Page builder" section
 * (daedalus_ui owns the section; every page-builder module parks its
 * field settings there). #parents routes the values into core's
 * third_party_settings tree, so FieldConfigEditForm persists them without
 * a custom submit handler.
 */
class FieldConfigFormAlter {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for 'field_config_edit_form'.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\field_ui\Form\FieldConfigEditForm $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\Core\Field\FieldConfigInterface $field */
    $field = $form_object->getEntity();

    DaedalusUiFieldConfigFormAlter::ensurePageBuilderSection($form);
    $form['page_builder']['daedalus_edit_disable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable inline editing for this field'),
      '#default_value' => $field->getThirdPartySetting('daedalus_edit', 'disable'),
      '#weight' => 10,
      '#parents' => ['third_party_settings', 'daedalus_edit', 'disable'],
    ];
    $form['page_builder']['daedalus_edit_handle'] = [
      '#type' => 'radios',
      '#title' => $this->t('Form Item Handle'),
      '#default_value' => $field->getThirdPartySetting('daedalus_edit', 'handle') ?? FormHandleFieldAttribute::getDefault($field) ?? 'form_item',
      '#options' => [
        'form_item' => $this->t('Form Item'),
        'wrapper' => $this->t('Wrapper'),
      ],
      '#description' => $this->t('Select whether the markup on the page that is being edited will be replaced by the entire wrapper or just the form item.'),
      '#weight' => 11,
      '#parents' => ['third_party_settings', 'daedalus_edit', 'handle'],
      '#states' => [
        'invisible' => [
          ':input[name="third_party_settings[daedalus_edit][disable]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    // Which entity types the composed link widget suggests while the
    // user types a title in THIS field. Per field, so each block type's
    // link scopes its own suggestions. Independent of the disable
    // checkbox above: that governs inline FORM editing, and composed link
    // fields are typically form-disabled while still widget-edited.
    if ($field->getFieldStorageDefinition()->getType() === 'link') {
      $options = [];
      foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
        if ($definition instanceof ContentEntityTypeInterface) {
          $options[$entity_type_id] = $definition->getLabel();
        }
      }
      $form['page_builder']['daedalus_edit_link_target_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Link autocomplete suggestions'),
        '#description' => $this->t('The entity types suggested while typing a title into this link field in the page builder. Uncheck all to offer no suggestions.'),
        '#options' => $options,
        '#default_value' => $field->getThirdPartySetting('daedalus_edit', 'link_target_types', LinkAutocomplete::DEFAULT_TARGET_TYPES),
        '#weight' => 12,
        '#parents' => ['third_party_settings', 'daedalus_edit', 'link_target_types'],
        // Persist a clean list of the checked type ids, not the checkbox
        // grid with its unchecked zeros.
        '#element_validate' => [[static::class, 'filterTargetTypes']],
      ];
    }
  }

  /**
   * Element validate: reduces the checkboxes value to the checked ids.
   *
   * @param array $element
   *   The checkboxes element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function filterTargetTypes(array $element, FormStateInterface $form_state): void {
    $form_state->setValueForElement($element, Checkboxes::getCheckedCheckboxes($form_state->getValue($element['#parents'])));
  }

}
