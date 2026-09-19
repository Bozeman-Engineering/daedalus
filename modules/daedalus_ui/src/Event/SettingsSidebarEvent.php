<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Settings Sidebar Event.
 *
 * Tools can add to the settings sidebar via a method. This allows non-tools
 * like hotkeys or Drag and drop media to provide settings.
 */
class SettingsSidebarEvent extends Event {

  /**
   * The settings render arrays, keyed by contributor id.
   */
  protected array $settings = [];

  /**
   * Gets the collected settings render arrays.
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * Adds a settings render array under a contributor id.
   */
  public function setSettings(string $id, array $settings): void {
    $this->settings[$id] = $settings;
  }

}
