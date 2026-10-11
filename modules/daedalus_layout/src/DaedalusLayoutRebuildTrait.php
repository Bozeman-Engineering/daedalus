<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Provides AJAX responses to rebuild Daedalus Layout.
 */
trait DaedalusLayoutRebuildTrait {

  /**
   * Rebuilds the layout and closes the dialog.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response to rebuild the layout and close the dialog.
   */
  protected function rebuildAndClose(SectionStorageInterface $section_storage): AjaxResponse {
    $response = $this->rebuildLayout($section_storage);
    $response->addCommand(new CloseDialogCommand('.ui-dialog-content'));
    return $response;
  }

  /**
   * Rebuilds the layout.
   *
   * The layout_builder_plus element always renders the root layout, so a
   * nested-scoped storage rebuilds the whole page.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response to rebuild the active layout.
   */
  protected function rebuildLayout(SectionStorageInterface $section_storage): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('[data-drupal-messages]'));
    $layout = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $section_storage,
      // The replacement is the layout root alone: the page's canvas root
      // (the main-entity wrapper around it) stays where it is.
      '#canvas_root' => FALSE,
    ];
    $response->addCommand(new ReplaceCommand('#layout-builder', $layout));

    return $response;
  }

  /**
   * Rebuild a left-rail sidebar panel.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   * @param string $sidebar_id
   *   The sidebar plugin id (== its DOM id) to rebuild.
   */
  public function rebuildLeftSidebar(AjaxResponse $response, string $sidebar_id): void {
    $panel = \Drupal::service('daedalus_ui.ui')->buildSidebarPanel($sidebar_id);
    $response->addCommand(new ReplaceCommand("#$sidebar_id", $panel));
  }

}
