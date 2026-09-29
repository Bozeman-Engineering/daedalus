<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The Sidebar attribute.
 *
 * Sidebar plugins are the single mechanism for contributing panels to the
 * daedalus_ui sidebar wrappers. The wrappers render on every page;
 * whether a panel exists in the DOM is decided by the plugin's applies(),
 * while whether an existing panel is visible is decided by its binding ($mode)
 * plus its (side, slot) open-panel cookie. Panels are present-but-hidden and
 * revealed client-side -- the wrappers are never re-rendered on a mode switch.
 *
 * Panels on the same side but in different slots stack and can be open at the
 * same time; panels sharing a (side, slot) are mutually exclusive. A slot is
 * an attribute value, not a container: adding another slot is CSS + policy,
 * not a markup change.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Sidebar extends AttributeBase {

  /**
   * Constructs a new Sidebar instance.
   *
   * @param string $id
   *   The plugin ID. It is the panel's single identity: used verbatim as the
   *   panel wrapper DOM id and as the value stored in its (side, slot)
   *   open-panel cookie ("{side}_{slot}_sidebar") while this panel is the one
   *   open there. Use snake_case; the side and slot are carried by $side and
   *   $slot, never baked into the id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable name of the sidebar.
   * @param string $side
   *   Which sidebar wrapper to render into: 'left' or 'right'.
   * @param int $weight
   *   The order the panel appears within its wrapper.
   * @param string $sidebar_type
   *   The data-sidebar-type value that selects the JS behavior (the JS sidebar
   *   plugin type). Defaults to 'default' (DefaultSidebar).
   * @param string|null $mode
   *   Bind visibility to a daedalus_ui mode (for example 'edit'). The panel
   *   is hidden whenever that mode is not the active mode. NULL means the panel
   *   is mode-independent and always-on (for example the AI chat).
   * @param bool $use_lazy_builder
   *   Render this panel behind a #lazy_builder placeholder so that, under
   *   BigPipe, its markup and attached assets stream in after the initial
   *   response instead of rendering inline. Opt in only when the panel needs
   *   it: a panel whose content is a deferred-upgrade custom element (e.g. the
   *   <deep-chat> AI panel) breaks when its callbacks are wired inline before
   *   the upgrade. Most panels render inline (the default) for flicker-free
   *   SSR.
   * @param string $slot
   *   The vertical slot within the side: 'top' (default) or 'bottom'. Panels
   *   in different slots on the same side stack and may be open together;
   *   panels sharing a (side, slot) are mutually exclusive and tracked by one
   *   "{side}_{slot}_sidebar" cookie.
   * @param bool $default_open
   *   Whether this panel opens on every entry into its mode. A deliberate
   *   close (toggle click) writes an EMPTY "{side}_{slot}_sidebar" cookie and
   *   sticks only for the rest of that mode session: in-mode page loads render
   *   the closed state from it, but leaving the mode expires the cookie
   *   (closeEphemeral()) and the client entry path opens the panel
   *   unconditionally, so the next entry starts open again. An ABSENT cookie
   *   shows the panel server-side (the page-load entry paths). At most one
   *   panel per (side, slot) should declare this.
   * @param string|null $hot_key
   *   Default hotkey binding string (e.g. 'a') that toggles this panel, in
   *   the hotkey registry's grammar. Every panel is listed in the hotkey
   *   settings tray and user-bindable regardless; this only supplies the
   *   out-of-the-box key. The binding registers under the id "sidebar.{id}"
   *   and drives the panel's data-sidebar-button-for toggle button, so it
   *   follows the exact pointer path. A panel with no toggle button (an
   *   empty buildToggleButton(); it opens from elsewhere, such as the Page
   *   elements panel's Show menu) is toggled through the sidebar manager
   *   by its DOM open state instead, the same open/close policy; the key
   *   falls through to other bindings only when the panel element is not
   *   on the page.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly string $side,
    public readonly int $weight,
    public readonly string $sidebar_type = 'default',
    public readonly ?string $mode = NULL,
    public readonly bool $use_lazy_builder = FALSE,
    public readonly string $slot = 'top',
    public readonly bool $default_open = FALSE,
    public readonly ?string $hot_key = NULL,
  ) {}

}
