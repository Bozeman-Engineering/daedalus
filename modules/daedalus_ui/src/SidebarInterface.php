<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

/**
 * Interface for sidebar plugins.
 *
 * Sidebar plugins are the single mechanism for contributing panels to the
 * daedalus_ui sidebar wrappers. A panel may be always-on or bound to a mode
 * and/or tool via the #[Sidebar] attribute. Two axes govern a panel: applies()
 * decides whether it exists in the DOM at all, while its attribute binding plus
 * its (side, slot) open-panel cookie decide whether an existing panel is
 * visible. applies() takes no entity -- panels that need the routed entity
 * resolve it themselves.
 */
interface SidebarInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Whether this sidebar should exist in the DOM on the current request.
   *
   * This is the presence axis, not the visibility axis: mode/tool binding and
   * the (side, slot) open-panel cookie decide whether an existing panel is
   * shown. Deliberately takes no entity -- panels that need request context
   * (permissions, route, routed entity) inject and resolve the relevant service
   * themselves.
   *
   * @return bool
   *   TRUE if the sidebar applies.
   */
  public function applies(): bool;

  /**
   * Build the panel contents.
   *
   * Returns only the inner render array. Core wraps it in the panel container
   * (id, classes, data-sidebar-type, visibility).
   *
   * @return array
   *   The render array for the panel contents.
   */
  public function build(): array;

  /**
   * Build the open/close toggle button.
   *
   * The plugin owns the button markup (icon, label, position). The button must
   * carry a data-sidebar-button-for attribute pointing at the panel so
   * core's shared sidebar toggle behavior can drive it.
   *
   * @return array
   *   The render array for the toggle button, or an empty array for none.
   */
  public function buildToggleButton(): array;

}
