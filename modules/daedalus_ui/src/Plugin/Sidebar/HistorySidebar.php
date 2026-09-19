<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Sidebar;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\SidebarPluginBase;

/**
 * The edit-history panel: the timeline of staged changes, undo/redo.
 *
 * A right-rail panel bound to Edit Mode. The markup here is the empty
 * shell; history-sidebar.js fills the list from the History facade (the
 * client mirror of the server-side timeline) and keeps it current as
 * mutations and restores move the pointer.
 *
 * Slot bottom: see SettingsSidebar — the transient right-rail panels share
 * the bottom slot so the persistent Styles inspector keeps the top. Weight
 * orders the toggle-button cluster in the top bar.
 */
#[Sidebar(
  id: 'history',
  label: new TranslatableMarkup('History'),
  side: 'right',
  weight: 4,
  sidebar_type: 'history',
  mode: 'edit',
  slot: 'bottom',
)]
final class HistorySidebar extends SidebarPluginBase {

  use ButtonBuilderTrait;

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      'contents' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['history-content']],
        'header' => $this->buildPanelHeader($this->t('History'), [
          'undo_button' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Undo'),
            '#attributes' => [
              'id' => 'history-undo',
              'class' => ['daedalus-ui-pill-button'],
              'disabled' => 'disabled',
            ],
          ],
          'redo_button' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Redo'),
            '#attributes' => [
              'id' => 'history-redo',
              'class' => ['daedalus-ui-pill-button'],
              'disabled' => 'disabled',
            ],
          ],
        ]),
        'list' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '',
          '#attributes' => [
            'class' => ['history-list', 'daedalus-ui-panel-list', 'daedalus-ui-panel__body'],
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
      'daedalus-ui-history',
      $this->t('History'),
      $this->t('Undo and redo your changes'),
      'daedalus_ui',
      'history',
      ['data-sidebar-button-for' => '#history'],
    );
  }

}
