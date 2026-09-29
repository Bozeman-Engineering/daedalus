<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Reads and writes per-user editor preferences.
 *
 * Server-readable editor preferences (hotkeys, media file associations, and
 * tool toggles such as AI image generation) are stored per-account on the
 * user entity's "daedalus_ui_settings" map field, namespaced by the owning
 * module. This service is the unified read/write API for them, so each new
 * preference does not need its own bespoke save route and controller.
 *
 * Purely visual, client-only toggles (e.g. container outlines) are NOT stored
 * here -- they live in the browser's localStorage because the server never
 * needs to read them.
 */
class EditorSettings {

  const FIELD_NAME = 'daedalus_ui_settings';

  public function __construct(
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets a preference value for the current user.
   *
   * @param string $namespace
   *   The owning module's machine name.
   * @param string $key
   *   The preference key within the namespace.
   * @param mixed $default
   *   Value returned when the preference is unset or the user is anonymous.
   *
   * @return mixed
   *   The stored value, or $default.
   */
  public function get(string $namespace, string $key, mixed $default = NULL): mixed {
    $user = $this->loadCurrentUser();
    if (!$user) {
      return $default;
    }
    $settings = $user->get(self::FIELD_NAME)->getValue();
    return $settings[0][$namespace][$key] ?? $default;
  }

  /**
   * Sets a preference value for the current user.
   *
   * @param string $namespace
   *   The owning module's machine name.
   * @param string $key
   *   The preference key within the namespace.
   * @param mixed $value
   *   The value to store.
   */
  public function set(string $namespace, string $key, mixed $value): void {
    $user = $this->loadCurrentUser();
    if (!$user) {
      return;
    }
    $settings = $user->get(self::FIELD_NAME)->getValue();
    $data = $settings[0] ?? [];
    $data[$namespace][$key] = $value;
    $user->set(self::FIELD_NAME, $data);
    // Preferences are not workspace content; persist on the live user entity.
    daedalus_ui_save_outside_workspace($user);
  }

  /**
   * Gets the current user's editing chrome density.
   *
   * The one server-side read of the preference: the SSR stamp
   * (DaedalusUiHooks::preprocessHtml) and the settings sidebar select both
   * come through here. The save route is generic, so normalize on read:
   * anything but a known tier collapses to the 'full' default.
   *
   * The tiers are ordered: chrome declaring data-daedalus-ui-conceal="minimal" hides
   * at minimal density and denser; data-daedalus-ui-conceal="clean" hides only at
   * clean.
   *
   * @return string
   *   'full' (show everything), 'minimal', or 'clean'.
   */
  public function getChromeDensity(): string {
    $density = $this->get('daedalus_ui', 'chrome_density', 'full');
    return in_array($density, ['minimal', 'clean'], TRUE) ? $density : 'full';
  }

  /**
   * Loads the current user entity, or NULL when anonymous.
   *
   * @return \Drupal\user\UserInterface|null
   *   The current user, or NULL.
   */
  protected function loadCurrentUser(): ?UserInterface {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
  }

}
