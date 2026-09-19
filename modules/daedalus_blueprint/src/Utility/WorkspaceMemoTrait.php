<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Utility;

/**
 * Keys a request-lifetime memo by the active workspace.
 *
 * A memo of entity loads or queries is only valid for the storage window
 * it was filled in: inside a workspace the storages answer with staged
 * revisions, outside it with live ones, and one request crosses that
 * line routinely (a compile runs inside executeOutsideWorkspace() right
 * after a staged save; a preview renders inside the workspace right
 * after). A memo keyed by nothing would hand the live set to the preview
 * or the staged set to the compiled file, so every such memo keys on
 * workspaceKey(). The using class holds a nullable
 * `$workspaceManager` (the optional workspaces.manager service).
 */
trait WorkspaceMemoTrait {

  /**
   * The memo key: the active workspace's id, '' outside every workspace.
   */
  protected function workspaceKey(): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    return (string) $this->workspaceManager->getActiveWorkspace()->id();
  }

}
