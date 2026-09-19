<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Sidebar;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\SidebarPluginBase;

/**
 * The notification history panel.
 *
 * A right-rail panel bound to Edit Mode that displays the notification history
 * managed client-side by notifications-sidebar.js.
 *
 * Slot bottom: see SettingsSidebar — the transient right-rail panels share
 * the bottom slot so the persistent Styles inspector keeps the top. Weight
 * orders the toggle-button cluster in the top bar.
 */
#[Sidebar(
  id: 'notifications',
  label: new TranslatableMarkup('Notifications'),
  side: 'right',
  weight: 8,
  sidebar_type: 'notifications',
  mode: 'edit',
  slot: 'bottom',
)]
final class NotificationsSidebar extends SidebarPluginBase {

  use ButtonBuilderTrait;

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      'contents' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['notifications-content']],
        'header' => $this->buildPanelHeader($this->t('Notifications'), [
          'clear_button' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Clear All'),
            '#attributes' => [
              'id' => 'clear-notifications',
              'class' => ['daedalus-ui-pill-button'],
            ],
          ],
        ]),
        'list' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '',
          '#attributes' => [
            'class' => ['notifications-list', 'daedalus-ui-panel-list', 'daedalus-ui-panel__body'],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-ui-notifications',
      $this->t('Notifications'),
      $this->t('View notification history'),
      'daedalus_ui',
      'bell',
      ['data-sidebar-button-for' => '#notifications'],
    );
  }

}
