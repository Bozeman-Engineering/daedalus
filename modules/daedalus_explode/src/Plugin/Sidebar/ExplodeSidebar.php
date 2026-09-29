<?php

declare(strict_types=1);

namespace Drupal\daedalus_explode\Plugin\Sidebar;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\SidebarPluginBase;

/**
 * The Explode panel: its open state IS the exploded canvas.
 *
 * Not a tool: a tool would be exclusive with the Selector and own no
 * pointer grammar of its own. A tool-independent panel composes with every
 * tool (select, edit, style while exploded), gets a Panels hotkey for
 * free, and the DOM-as-truth sidebar manager plus the (side, slot) cookie
 * give open-on-reload for free. The client half (js/src/index.js) stamps
 * data-daedalus-ui-canvas-view="exploded" on <html> when the panel opens
 * and clears it when the panel closes; every owner of rect-measured
 * chrome hides under that attribute in its own stylesheet (see
 * daedalus_ui.api.php, canvas_view).
 *
 * Right rail, top slot: the slot the Styles inspector holds, so the two
 * are mutually exclusive there and opening Explode parks the inspector
 * (the bottom slot's transient panels stay available while exploded).
 * The panel declares no top-bar toggle: its opener is a checked entry in
 * the Page elements panel's Show menu, registered client-side through
 * app.tree.registerShowMenuItem (js/src/index.js), and the hot_key
 * binding toggles the panel through the sidebar manager when no button
 * exists (the Sidebar attribute's hot_key contract).
 *
 * The build is a header plus a mount point. The panel's controls (tilt,
 * turn, gap, zoom, depth cap, labels, parts, isolate) render client-side
 * into the mount from the same bundle.
 */
#[Sidebar(
  id: 'daedalus_explode',
  label: new TranslatableMarkup('Explode'),
  side: 'right',
  weight: 1,
  sidebar_type: 'daedalus_explode',
  mode: 'edit',
  slot: 'top',
  hot_key: 'x',
)]
final class ExplodeSidebar extends SidebarPluginBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      'header' => $this->buildPanelHeader($this->t('Explode')),
      'panel' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '',
        '#attributes' => [
          'id' => 'daedalus-explode-panel',
          'class' => ['daedalus-ui-panel__body'],
        ],
      ],
      '#attached' => [
        'library' => ['daedalus_explode/explode'],
      ],
    ];
  }

}
