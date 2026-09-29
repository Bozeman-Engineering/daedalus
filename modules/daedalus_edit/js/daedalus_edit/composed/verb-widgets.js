/**
 * @file
 * The widget-verb grammar shared by composed-element editors.
 *
 * A collapsed block's composed root IS the editing surface, so its
 * controls cannot live inside it (the root may be a contenteditable
 * element or a void element). They live on the selection chip's verb
 * face instead, as EDIT-SCOPED WIDGET VERBS in the daedalus_ui verb
 * registry: the frame renders each verb's widget slot on the primary's
 * chip while the edit is open, and this module owns what an editor puts
 * in it — a popover toggle wearing the chip's own button vocabulary
 * (daedalus-ui-frame__verb, daedalus-ui-frame__icon, daedalus-ui-frame__label, so the density
 * ladder's labels reach these entries like any other verb) and the
 * content the toggle opens on the chrome's one floating surface,
 * Drupal.Daedalus.popover (daedalus_ui/popover).
 *
 * The surface owns the card: it mounts at body level (above the top bar
 * and every canvas chrome, which the chip anchor's own stacking context
 * can never reach), lands ABOVE the toggle so it never covers the
 * editing surface and flips below only when the viewport is short
 * above, re-places as its content grows, dismisses on an outside press
 * or scroll, and closes on Escape at document capture, restoring focus
 * to the opener. Its one-surface-per-window rule is the edit's
 * one-popover-at-a-time rule: opening a widget closes any other. This
 * module owns what rides it: the content node an editor fills
 * ([data-daedalus-edit-popover="name"], detached while closed), the
 * toggle's aria-expanded, and where focus goes when the surface closes
 * with focus inside it (the consumer's returnFocus, so a scroll or a
 * pick never orphans focus on body).
 *
 * Caret retention is a separate concern from activation: a mousedown on
 * any button moves focus out of a contenteditable, so every toggle
 * preventDefaults its mousedown and ACTS on click (the same press
 * grammar as the frame's plain verb buttons). Ending the edit is not a
 * concern here at all: the toggles render inside .daedalus-ui-frame chrome
 * and the surface root declares data-keeps-edit, both of which the
 * open edit's press decision keeps.
 *
 * Per-gesture state rides the EditableElement (plugin instances are
 * registry singletons): `verbWidgets`, a Map of widget name to its
 * {toggle, popover, open, returnFocus}. A widget's cleanup (returned to
 * the frame's slot) releases its entry and takes an open surface down
 * with it; the frame unmounts the widgets when the edit ends or the
 * selection moves, so the registry empties with the gesture.
 *
 * Handed to registration callbacks as `verbWidgets` on the
 * DaedalusEditQueue API.
 *
 * @see ../editor-registry.js
 * @see daedalus_ui/js/edit_mode/verbs/verbs.js (the widget contract)
 * @see daedalus_ui/js/popover/popover.js (the surface)
 */

/**
 * The edit's widget registry, created on first use.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 *
 * @return {Map<string, {toggle: ?Element, popover: ?Element, open: boolean,
 *   returnFocus: ?Function}>}
 *   The registry.
 */
const widgetsOf = (EditableElement) => {
  EditableElement.verbWidgets ??= new Map();
  return EditableElement.verbWidgets;
};

const entryOf = (EditableElement, name) => {
  const widgets = widgetsOf(EditableElement);
  if (!widgets.has(name)) {
    widgets.set(name, {
      toggle: null,
      popover: null,
      open: false,
      returnFocus: null,
    });
  }
  return widgets.get(name);
};

/**
 * Moves focus into an open popover's autofocus element, if it has one.
 *
 * An input popover's typing surface is its whole point; popovers
 * without one leave focus where it is, so a pick keeps the caret in the
 * editing surface.
 *
 * @param {Element} popover
 *   The open content node.
 */
const autofocus = (popover) => {
  const target = popover.querySelector(
    '[data-daedalus-edit-popover-autofocus]',
  );
  if (target) {
    target.focus();
    target.select?.();
  }
};

/**
 * Toggles a widget's popover on the surface.
 *
 * Opening rides Drupal.Daedalus.popover anchored to the widget's toggle,
 * preferring the side above it, which closes whatever else the surface
 * held (the edit's other widget included). Closing is the surface's
 * close; either way the surface's onClose is the one place the entry's
 * `open` and the toggle's aria-expanded fall back, so a dismissal the
 * surface decided on its own (an outside press, a scroll, Escape) syncs
 * the same way as a toggle click.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {string} name
 *   The popover to toggle.
 * @param {?boolean} open
 *   Force a state instead of toggling.
 */
export function togglePopover(EditableElement, name, open = null) {
  const entry = EditableElement.verbWidgets?.get(name);
  if (!entry?.popover) {
    return;
  }
  const show = open ?? !entry.open;
  if (!show) {
    if (entry.open) {
      Drupal.Daedalus.popover.close();
    }
    return;
  }
  if (entry.open) {
    // Re-asserting an open popover (Cmd+K over an open link input) lands
    // focus in it again.
    autofocus(entry.popover);
    return;
  }
  if (!entry.toggle) {
    throw new Error(
      `The "${name}" widget has no toggle to anchor its popover to; build the toggle before opening the popover.`,
    );
  }
  Drupal.Daedalus.popover.open({
    anchor: entry.toggle,
    side: 'above',
    content: entry.popover,
    onClose: () => {
      entry.open = false;
      entry.toggle?.setAttribute('aria-expanded', 'false');
      // The surface removed its root; focus that was inside it fell to
      // body (a pointer dismissal, a scroll, a pick that closed). The
      // editing surface is where it belongs, without scrolling the page
      // under a scroll that just dismissed the popover.
      const active = document.activeElement;
      if (!active || active === document.body) {
        entry.returnFocus?.(EditableElement)?.focus({ preventScroll: true });
      }
    },
  });
  entry.open = true;
  entry.toggle.setAttribute('aria-expanded', 'true');
  autofocus(entry.popover);
}

/**
 * Builds a widget's popover toggle into its slot container.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {Element} container
 *   The frame's widget slot container.
 * @param {Object} options
 *   - {string} name: the popover it controls.
 *   - {string} label: the accessible label.
 *   - {string} icon: the inline SVG markup.
 *   - {boolean} showLabel: whether the entry renders its text label
 *     (the frame's density ladder, passed through the widget context).
 *
 * @return {Element}
 *   The button.
 */
export function buildWidgetToggle(
  EditableElement,
  container,
  { name, label, icon, showLabel = false },
) {
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'daedalus-ui-frame__verb daedalus-edit-widget-toggle';
  toggle.dataset.daedalusEditToggle = name;
  // A pointer/hotkey surface, not a Tab stop (Tab flows leaf to leaf;
  // each popover handles its own keys).
  toggle.tabIndex = -1;
  toggle.setAttribute('aria-label', label);
  // The chrome tooltip spine (daedalus_ui js/tooltip.js): the pill
  // only shows while the toggle's inline label is hidden, so this is
  // quiet at full density.
  toggle.setAttribute('data-daedalus-ui-tooltip', label);
  toggle.setAttribute('data-daedalus-ui-tooltip-position', 'top');
  toggle.setAttribute('aria-haspopup', 'true');
  toggle.setAttribute('aria-expanded', 'false');
  const iconSpan = document.createElement('span');
  iconSpan.className = 'daedalus-ui-frame__icon';
  iconSpan.setAttribute('aria-hidden', 'true');
  iconSpan.innerHTML = icon;
  toggle.append(iconSpan);
  if (showLabel) {
    const labelSpan = document.createElement('span');
    labelSpan.className = 'daedalus-ui-frame__label';
    labelSpan.textContent = label;
    toggle.append(labelSpan);
  }
  // Keep the caret in the editing surface through the press; the action
  // fires on click like every face button. The surface counts its anchor
  // as inside, so this press never dismisses the popover it is about to
  // toggle closed.
  toggle.addEventListener('mousedown', (event) => {
    event.preventDefault();
  });
  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    togglePopover(EditableElement, name);
  });
  entryOf(EditableElement, name).toggle = toggle;
  container.append(toggle);
  return toggle;
}

/**
 * Builds a widget's empty popover content node for the editor to fill.
 *
 * Detached until its toggle opens it: togglePopover() hands it to the
 * surface as the content, and the surface's close detaches it again, so
 * a closed popover is absent from the document while the editor's
 * references to what it holds (an input's value, a picker's pressed
 * state) stay live on the node.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {string} name
 *   The popover name its toggle addresses it by.
 * @param {Object} options
 *   - {Function} returnFocus: (EditableElement) => ?Element — the
 *     element focus returns to when the surface closes with focus inside
 *     the popover (the editing surface).
 *
 * @return {Element}
 *   The content node.
 */
export function buildPopover(
  EditableElement,
  name,
  { returnFocus = null } = {},
) {
  const popover = document.createElement('div');
  popover.className = 'daedalus-edit-widget-content';
  popover.dataset.daedalusEditPopover = name;
  const entry = entryOf(EditableElement, name);
  entry.popover = popover;
  entry.returnFocus = returnFocus;
  return popover;
}

/**
 * Releases a widget's registry entry — the widget's unmount cleanup.
 *
 * The frame clears the slot's DOM itself; this takes the widget's open
 * surface down (the frame unmounts on edit end and selection moves, and
 * a popover must not outlive the toggle it hangs off) and drops the
 * per-gesture references so a re-mounted widget (a draft resumed over
 * swapped markup, a density flip) builds fresh.
 *
 * @param {EditableElement} EditableElement
 *   The element whose widget to release.
 * @param {string} name
 *   The widget name.
 */
export function releaseWidget(EditableElement, name) {
  if (EditableElement.verbWidgets?.get(name)?.open) {
    Drupal.Daedalus.popover.close();
  }
  EditableElement.verbWidgets?.delete(name);
}
