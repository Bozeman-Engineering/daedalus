<?php

namespace Drupal\daedalus_ui;

/**
 * Tracks view modes for entities during request lifecycle.
 */
class ViewModeTracker {

  /**
   * Storage for view modes keyed by entity type and ID.
   *
   * @var array
   */
  protected $viewModes = [];

  /**
   * Sets the view mode for an entity.
   *
   * First write wins: the routed entity's build records its view mode before
   * any later render of the same entity (a teaser of the routed node further
   * down the page, for example) could overwrite it with a secondary mode.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string $view_mode
   *   The view mode.
   */
  public function setViewMode(string $entity_type_id, string $entity_id, string $view_mode): void {
    if (!isset($this->viewModes[$entity_type_id][$entity_id])) {
      $this->viewModes[$entity_type_id][$entity_id] = $view_mode;
    }
  }

  /**
   * Gets the view mode for an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string $default
   *   The default view mode if not found.
   *
   * @return string
   *   The view mode.
   */
  public function getViewMode(string $entity_type_id, string $entity_id, string $default = 'default'): string {
    return $this->viewModes[$entity_type_id][$entity_id] ?? $default;
  }

  /**
   * Clears stored view modes.
   */
  public function clear(): void {
    $this->viewModes = [];
  }

  /**
   * Clears view mode for a specific entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   */
  public function clearEntity(string $entity_type_id, string $entity_id): void {
    unset($this->viewModes[$entity_type_id][$entity_id]);
  }

}
