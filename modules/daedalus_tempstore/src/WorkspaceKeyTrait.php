<?php

namespace Drupal\daedalus_tempstore;

/**
 * Provides workspace-aware key generation for tempstore strategies.
 *
 * This trait adds workspace ID suffixes to tempstore keys, ensuring proper
 * isolation between workspaces. Follows the same pattern as core's langcode
 * handling in getTempstoreKey().
 */
trait WorkspaceKeyTrait {

  /**
   * Appends workspace ID to a tempstore key.
   *
   * Requires $this->workspaceManager property to be available (can be NULL).
   *
   * If workspaces module is enabled and there's an active workspace,
   * appends ".{workspace_id}" to the key. Otherwise appends ".live"
   * to indicate the live workspace.
   *
   * @param string $key
   *   The base tempstore key.
   *
   * @return string
   *   The key with workspace ID appended.
   */
  protected function appendWorkspaceToKey(string $key): string {
    if ($this->workspaceManager === NULL) {
      return $key;
    }

    if ($this->workspaceManager->hasActiveWorkspace()) {
      return $key . '.workspace:' . $this->workspaceManager->getActiveWorkspace()->id();
    }

    return $key . '.live';
  }

}
