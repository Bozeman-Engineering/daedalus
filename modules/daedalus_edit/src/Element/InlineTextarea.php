<?php

namespace Drupal\daedalus_edit\Element;

use Drupal\filter\Element\TextFormat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;

/**
 * Inline CKEditor element.
 */
#[FormElement('inline_textarea')]
class InlineTextarea extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#process' => [
        [static::class, 'processFormat'],
      ],
      '#base_type' => 'textarea',
      '#theme_wrappers' => ['text_format_wrapper'],
      '#theme' => 'inline_textarea',
    ];
  }

  /**
   * Process callback: builds the text format selector without its theme.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The processed element.
   */
  public static function processFormat(&$element, FormStateInterface $form_state, &$complete_form) {
    $element = TextFormat::processFormat($element, $form_state, $complete_form);
    unset($element['#theme']);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if (is_array($input) && isset($input['value'])) {
      return $input['value'];
    }
    // Use the #default_value.
    return NULL;
  }

}
