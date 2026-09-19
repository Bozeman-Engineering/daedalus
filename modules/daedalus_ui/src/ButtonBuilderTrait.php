<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Component\Utility\NestedArray;
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
   * Builds a split-button with a main action and dropdown.
   *
   * @param string $id
   *   The button ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The button label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The button title (tooltip).
   * @param string $pack_id
   *   The icon pack ID.
   * @param string $icon_id
   *   The icon ID.
   * @param array $dropdown_content
   *   A render array for the dropdown contents.
   * @param array|null $attributes
   *   Optional extra attributes for container buttons.
   *
   * @return array
   *   A render array for the split-button.
   */
  protected function buildSplitButton(string $id, $label, $title, string $pack_id, string $icon_id, array $dropdown_content, ?array $attributes = NULL): array {
    $button = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $id,
        'class' => ['daedalus-ui-split-button'],
        'title' => $title,
      ],
      'main' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'toolbar-button',
            'daedalus-ui-split-button__main',
          ],
        ],
        'label' => [
          '#type' => 'inline_template',
          '#template' => "{{ icon(pack_id, icon_id, { class: 'toolbar-button__icon', size: 20 }) }}<span class='toolbar-button__label'>{{ label }}</span>",
          '#context' => [
            'label' => $label,
            'pack_id' => $pack_id,
            'icon_id' => $icon_id,
          ],
        ],
      ],
      'caret' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['daedalus-ui-split-button__caret'],
        ],
        'chevron' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '&#9662;',
          '#attributes' => [
            'class' => ['daedalus-ui-split-button__chevron'],
          ],
        ],
      ],
      'dropdown' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['daedalus-ui-split-button__dropdown'],
        ],
        'content' => $dropdown_content,
      ],
    ];

    // Add URL for link buttons.
    // Add extra attributes for container buttons.
    if (is_array($attributes)) {
      $button['#attributes'] = NestedArray::mergeDeep($button['#attributes'], $attributes);
    }

    return $button;
  }

}
