/**
 * @file
 * The widget-verb popover grammar shared by composed-element editors.
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
 * popover it controls, with one popover open at a time across the
 * edit's widgets.
 *
 * Caret retention is a separate concern from activation: a mousedown on
 * any button moves focus out of a contenteditable, so every toggle
 * preventDefaults its mousedown and ACTS on click (the same press
 * grammar as the frame's plain verb buttons). Ending the edit is not a
 * concern here at all: the widgets render inside .daedalus-ui-frame chrome,
 * which the armed session's press decision always keeps.
 *
 * Per-gesture state rides the EditableElement (plugin instances are
 * registry singletons): `verbWidgets`, a Map of widget name to its
 * {toggle, popover}. A widget's cleanup (returned to the frame's slot)
 * releases its entry; the frame unmounts the widgets when the edit ends
 * or the selection moves, so the registry empties with the gesture.
 *
 * Handed to registration callbacks as `verbWidgets` on the
 * DaedalusEditQueue API.
 *
 * @see ../editor-registry.js
 * @see daedalus_ui/js/edit_mode/verbs/verbs.js (the widget contract)
 */

/**
 * The edit's widget registry, created on first use.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 *
 * @return {Map<string, {toggle: ?Element, popover: ?Element}>}
 *   The registry.
 */
const widgetsOf = (EditableElement) => {
  EditableElement.verbWidgets ??= new Map();
  return EditableElement.verbWidgets;
};

const entryOf = (EditableElement, name) => {
  const widgets = widgetsOf(EditableElement);
  if (!widgets.has(name)) {
    widgets.set(name, { toggle: null, popover: null });
  }
  return widgets.get(name);
};

/**
 * Toggles a popover open, closing the edit's other ones.
 *
 * Opening moves focus into the popover's
 * [data-daedalus-edit-popover-autofocus] element when it declares one (an
 * input popover's typing surface — its whole point); popovers without
 * one leave focus where it is, so a pick keeps the caret in the editing
 * surface.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {string} name
 *   The popover to toggle.
 * @param {?boolean} open
 *   Force a state instead of toggling.
 */
export function togglePopover(EditableElement, name, open = null) {
  const widgets = EditableElement.verbWidgets;
  const popover = widgets?.get(name)?.popover;
  if (!popover) {
    return;
  }
  const show = open ?? popover.hidden;
  widgets.forEach((entry, entryName) => {
    if (entry.popover) {
      entry.popover.hidden = entryName !== name || !show;
    }
    entry.toggle?.setAttribute(
      'aria-expanded',
      String(entryName === name && show),
    );
  });
  if (show) {
    const autofocus = popover.querySelector(
      '[data-daedalus-edit-popover-autofocus]',
    );
    if (autofocus) {
      autofocus.focus();
      autofocus.select?.();
    }
  }
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
  // fires on click like every face button.
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
 * Builds an empty, hidden popover for the editor to fill.
 *
 * Appended to the widget slot container, which anchors it
 * (.daedalus-ui-frame__widget is the positioning context; the popover hangs
 * above the chip, see css/verb-widgets.css).
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {Element} container
 *   The frame's widget slot container.
 * @param {string} name
 *   The popover name its toggle addresses it by.
 *
 * @return {Element}
 *   The popover element.
 */
export function buildPopover(EditableElement, container, name) {
  const popover = document.createElement('div');
  popover.className = 'daedalus-edit-widget-popover';
  popover.dataset.daedalusEditPopover = name;
  popover.hidden = true;
  entryOf(EditableElement, name).popover = popover;
  container.append(popover);
  return popover;
}

/**
 * Releases a widget's registry entry — the widget's unmount cleanup.
 *
 * The frame clears the slot's DOM itself; this drops the per-gesture
 * references so a re-mounted widget (a draft resumed over swapped
 * markup, a density flip)
 * builds fresh.
 *
 * @param {EditableElement} EditableElement
 *   The element whose widget to release.
 * @param {string} name
 *   The widget name.
 */
export function releaseWidget(EditableElement, name) {
  EditableElement.verbWidgets?.delete(name);
}
