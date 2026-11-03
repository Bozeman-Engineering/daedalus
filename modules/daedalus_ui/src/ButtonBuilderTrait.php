<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;

/**
 * Builds the top bar's shared button anatomies with icon support.
 *
 * Two anatomies: the panel toggle (buildTopBarButton(): a container or a
 * link every sidebar plugin's buildToggleButton() returns) and the menu
 * trigger (buildMenuTrigger(): a real button with a caret that opens the
 * shared menu primitive). Both wear the chrome tooltip attribute so the
 * label rides the tooltip spine wherever the density ladder hides it.
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
   * Builds a menu trigger: a top bar button whose press opens a menu.
   *
   * A real button (focusable, aria-haspopup="menu", aria-expanded the
   * behavior flips) wearing the toggle anatomy (icon, label span, the
   * tooltip attribute) plus a caret glyph after the label, the same
   * "more of this" caret the selection chip draws. The markup carries no
   * menu of its own: the behavior bound to the id opens the shared menu
   * primitive (Drupal.Daedalus.menu) over whatever it lists at open time.
   *
   * @param string $id
   *   The button's id, the hook its menu behavior binds to.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label beside the icon (inline at full density, the tooltip below).
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The button's description, its aria-label.
   * @param string $pack_id
   *   The icon pack id.
   * @param string $icon_id
   *   The icon id.
   * @param array $attributes
   *   Extra attributes (a conceal declaration, data hooks).
   *
   * @return array
   *   A render array for the trigger.
   */
  protected function buildMenuTrigger(string $id, $label, $title, string $pack_id, string $icon_id, array $attributes = []): array {
    return [
      '#type' => 'inline_template',
      '#template' => "<button{{ attributes }}>{{ icon(pack_id, icon_id, { class: 'toolbar-button__icon', size: 20 }) }}<span class='toolbar-button__label'>{{ label }}</span>{{ icon('daedalus_ui', 'caret-down', { class: 'toolbar-button__caret', size: 14 }) }}</button>",
      '#context' => [
        'attributes' => new Attribute($attributes + [
          'type' => 'button',
          'id' => $id,
          'class' => ['toolbar-button', 'daedalus-ui-menu-trigger'],
          'aria-label' => $title,
          'aria-haspopup' => 'menu',
          'aria-expanded' => 'false',
          'data-daedalus-ui-tooltip' => $label,
        ]),
        'label' => $label,
        'pack_id' => $pack_id,
        'icon_id' => $icon_id,
      ],
    ];
  }

}
