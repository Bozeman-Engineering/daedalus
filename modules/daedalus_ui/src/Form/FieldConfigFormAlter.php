<?php

namespace Drupal\daedalus_ui\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Presentation\Presentation;

/**
 * Field config form alter.
 *
 * The site-builder tier of the element model's field presentation decision
 * (see ElementModel::fieldPresentation()): a field-config third-party
 * setting outranks the owner's page_builder declaration, which outranks the
 * field-type derived default. The control lives in the shared "Page
 * builder" section (::ensurePageBuilderSection()) so every page-builder
 * field setting (this one, Daedalus Edit's inline-editing settings) is configured
 * in one place.
 */
class FieldConfigFormAlter {

  use StringTranslationTrait;

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

    self::ensurePageBuilderSection($form);
    // #parents routes the value into core's third_party_settings tree, so
    // FieldConfigEditForm persists it without a custom value mapping; the
    // submit handler only strips the 'auto' default back off the config.
    $form['page_builder']['daedalus_ui_presentation'] = [
      '#type' => 'select',
      '#title' => $this->t('Presentation'),
      '#description' => $this->t('How the page builder presents this field. "Default" derives it: a reference to a media entity presents as one element ("the image"), everything else shows its structure; a page_builder declaration can override that per bundle. "One element" always presents the field as a single element with its inner structure reachable by drilling in. "Show structure" always exposes the inner structure.'),
      '#options' => [
        'auto' => $this->t('Default'),
        Presentation::UNIT => $this->t('One element'),
        Presentation::OPEN => $this->t('Show structure'),
      ],
      '#default_value' => $field->getThirdPartySetting('daedalus_ui', ElementModel::SETTING) ?? 'auto',
      '#parents' => ['third_party_settings', 'daedalus_ui', ElementModel::SETTING],
    ];
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   *
   * 'Auto' means "no site-builder decision here, fall through to the
   * declaration and derived tiers", so it must not persist: only unit/open
   * are real overrides (ElementModel::fieldPresentation() honors nothing
   * else).
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    $parents = ['third_party_settings', 'daedalus_ui', ElementModel::SETTING];
    if (!in_array($form_state->getValue($parents), [Presentation::UNIT, Presentation::OPEN], TRUE)) {
      $values = $form_state->getValues();
      unset($values['third_party_settings']['daedalus_ui'][ElementModel::SETTING]);
      if (empty($values['third_party_settings']['daedalus_ui'])) {
        unset($values['third_party_settings']['daedalus_ui']);
      }
      if (empty($values['third_party_settings'])) {
        // No value at all beats an empty array: copyFormValuesToEntity()
        // replaces the entity's third-party settings wholesale with
        // whatever this key holds.
        unset($values['third_party_settings']);
      }
      $form_state->setValues($values);
    }
  }

  /**
   * Ensures the shared "Page builder" details section exists on the form.
   *
   * Page-builder modules co-locate their field settings in this one
   * section instead of each adding their own. Create-if-missing keeps the
   * result independent of form alter order; callers park their elements
   * under $form['page_builder'] with #parents routing values into core's
   * third_party_settings tree.
   *
   * @param array $form
   *   The field config edit form.
   */
  public static function ensurePageBuilderSection(array &$form): void {
    if (!isset($form['page_builder'])) {
      $form['page_builder'] = [
        '#type' => 'details',
        '#title' => t('Page builder'),
        '#open' => TRUE,
        '#weight' => 23,
      ];
    }
  }

}
