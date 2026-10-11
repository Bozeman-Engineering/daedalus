<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;

/**
 * Provides a helper method for building top bar buttons with icon support.
 */
trait ButtonBuilderTrait {

  /**
   * Build a top bar button with icon support.
   *
   * @param string $id
   *   The button ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The button label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The button description, exposed to assistive technology as the
   *   aria-label.
   * @param string $pack_id
   *   The icon pack ID.
   * @param string $icon_id
   *   The icon ID.
   * @param \Drupal\Core\Url|array|null $url_or_attributes
   *   Optional URL for link buttons, or extra attributes for container buttons.
   *
   * @return array
   *   A render array for the button.
   */
  protected function buildTopBarButton(string $id, $label, $title, string $pack_id, string $icon_id, $url_or_attributes = NULL): array {
    $is_link = $url_or_attributes instanceof Url;

    $button = [
      'wrapper' => [
        '#type' => $is_link ? 'link' : 'container',
        '#attributes' => [
          'id' => $id,
          'aria-label' => $title,
          // Below full chrome density top bar buttons render icon-only
          // (top-bar.css hides the label span) and the label rides the
          // chrome tooltip spine on hover, below the bar. The engine only
          // shows the pill while the inline label is hidden, so this
          // declaration is harmless at full density.
          'data-daedalus-ui-tooltip' => $label,
          'data-icon-text' => substr((string) $label, 0, 2),
          'class' => ['toolbar-button'],
        ],
        'label' => [
          '#type' => 'inline_template',
          '#template' => "{{ icon(pack_id, icon_id, { class: 'toolbar-button__icon', size: 20 }) }}<span class='toolbar-button__label'>{{label}}</span>",
          '#context' => [
            'id' => $id,
            'label' => $label,
            'title' => $title,
            'pack_id' => $pack_id,
            'icon_id' => $icon_id,
          ],
        ],
      ],
    ];

    // Add URL for link buttons.
    if ($is_link) {
      $button['wrapper']['#url'] = $url_or_attributes;
      $button['wrapper']['#title'] = $button['wrapper']['label'];
      unset($button['wrapper']['label']);
    }
    // Add extra attributes for container buttons.
    elseif (is_array($url_or_attributes)) {
      $button['wrapper']['#attributes'] = array_merge($button['wrapper']['#attributes'], $url_or_attributes);
    }

    return $button;
  }

  /**
   * Builds a split control: a top bar button with a menu caret beside it.
   *
   * The main half is an ordinary top bar button (buildTopBarButton(): same
   * id, label, tooltip and icon contract, so a behavior bound to the id
   * keeps working). The caret is a real button that opens the shared menu
   * primitive (Drupal.Daedalus.menu) over whatever items its behavior
   * supplies: the markup carries no menu of its own, and the caret renders
   * hidden until that behavior finds something to list, so a bar never
   * shows a caret that opens nothing.
   *
   * @param string $id
   *   The main button's id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The main button's label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The main button's description, its aria-label.
   * @param string $pack_id
   *   The icon pack id.
   * @param string $icon_id
   *   The icon id.
   * @param string $caret_id
   *   The caret button's id, the hook its menu behavior binds to.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $caret_title
   *   The caret's label: its aria-label, and its tooltip (the caret is
   *   icon-only at every chrome density).
   *
   * @return array
   *   A render array for the split control.
   */
  protected function buildSplitButton(string $id, $label, $title, string $pack_id, string $icon_id, string $caret_id, $caret_title): array {
    $main = $this->buildTopBarButton($id, $label, $title, $pack_id, $icon_id)['wrapper'];
    $main['#attributes']['class'][] = 'daedalus-ui-split-button__main';
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['daedalus-ui-split-button'],
      ],
      'main' => $main,
      'caret' => [
        '#type' => 'inline_template',
        '#template' => "<button{{ attributes }}>{{ icon('daedalus_ui', 'caret-down', { class: 'toolbar-button__icon', size: 14 }) }}</button>",
        '#context' => [
          'attributes' => new Attribute([
            'type' => 'button',
            'id' => $caret_id,
            'class' => ['toolbar-button', 'daedalus-ui-split-button__caret'],
            'aria-label' => $caret_title,
            'aria-haspopup' => 'menu',
            'data-daedalus-ui-tooltip' => $caret_title,
            'hidden' => TRUE,
          ]),
        ],
      ],
    ];
  }

}
