import { h } from 'preact';
import { useRef, useState } from 'preact/hooks';
import { magneticCapture, stopLabel } from './value-space.js';
import { useMenu } from './menu.js';

/**
 * The stop picker: a detented text input's curated offer, as a menu.
 *
 * A detented space shapes free entry without closing it, so the input
 * stays a text field and the vocabulary needs its own door. That door is
 * the shared menu primitive (menu.js over Drupal.Daedalus.menu):
 * a chevron after the input, or Alt+ArrowDown from inside it, opens the
 * stops as a list anchored to the value box, with the stop the shown
 * value sits on checked and nothing checked while the value is off the
 * vocabulary. A pick is a magnetic capture: the caller previews the
 * stop's display value and settles it through the same path a typed
 * match takes, so the commit is the stop's reference and no second
 * write path exists.
 *
 * Both detented text inputs mount it: the DimensionField's open render
 * (widgets/lib/dimension-field.js) and the TextField
 * (widgets/lib/text-field.js, the advanced text rows and the basic
 * text-shape rows alike). The native option list it replaces filtered its
 * options against the typed text, so it could never show the whole offer
 * once a value was present.
 *
 * The chevron is built with h() rather than JSX so the module stays
 * importable by node (tests/js/stop-menu.test.mjs exercises the pure
 * item derivation).
 */

/**
 * The menu items for a stop list: every stop by its display label, the
 * one the shown value magnetizes to checked. Every item is checkable
 * (a boolean `checked`) so the gutter holds its place whether or not the
 * value sits on a stop; off-stop, none is checked.
 *
 * @param {Array<{value: string, ref: string, tokens: Array}>} stops
 *   The detented space's stops.
 * @param {string} face
 *   The shown value (the input's text).
 * @param {Function} onPick
 *   Called with the picked stop after the menu has closed.
 *
 * @return {Array<{label: string, checked: boolean, run: Function}>}
 */
export const stopMenuItems = (stops, face, onPick) => {
  const current =
    typeof face === 'string' && face.trim() !== ''
      ? (magneticCapture(stops, face.trim())?.stop ?? null)
      : null;
  return stops.map((stop) => ({
    label: stopLabel(stop),
    checked: stop === current,
    run: () => onPick(stop),
  }));
};

/**
 * Whether a keydown in the input is the open gesture (Alt+ArrowDown).
 *
 * @param {{key: string, altKey: boolean}} event
 *
 * @return {boolean}
 */
export const isOpenKey = (event) =>
  event.key === 'ArrowDown' && event.altKey === true;

/**
 * The stop picker's chrome and gestures for one detented text input.
 *
 * @param {object} options
 * @param {Array} options.stops
 *   The space's stops; with none the chevron renders nothing and the
 *   keyboard opener is inert.
 * @param {string} options.face
 *   The shown value, deciding the checked item.
 * @param {{current: ?Element}} options.anchorRef
 *   A ref to the value box the menu hangs under.
 * @param {Function} options.onPick
 *   Called with the picked stop.
 *
 * @return {{chevron: ?import('preact').VNode, onInputKeyDown: Function}}
 *   `chevron` mounts after the input (null without stops);
 *   `onInputKeyDown(event)` returns true when it consumed the keydown
 *   (Alt+ArrowDown opened the menu), so the input's own handler stops.
 */
export const useStopMenu = ({ stops, face, anchorRef, onPick }) => {
  const menu = useMenu();
  // The chevron toggles: the decision reads a ref (a click can land before
  // the state's re-render), the state only paints aria-expanded.
  const openRef = useRef(false);
  const [expanded, setExpanded] = useState(false);
  const note = (open) => {
    openRef.current = open;
    setExpanded(open);
  };
  const open = () => {
    if (!stops.length) {
      return false;
    }
    const opened = menu.open({
      anchor: anchorRef.current,
      items: stopMenuItems(stops, face, onPick),
      onClose: () => note(false),
    });
    note(opened);
    return opened;
  };
  const onInputKeyDown = (event) => {
    if (!stops.length || !isOpenKey(event)) {
      return false;
    }
    event.preventDefault();
    open();
    return true;
  };
  const chevron = stops.length
    ? h(
        'button',
        {
          type: 'button',
          className:
            'daedalus-stylish-stop-menu__chevron daedalus-ui-icon-button daedalus-ui-icon-button--muted',
          'aria-haspopup': 'menu',
          'aria-expanded': expanded,
          'aria-label': Drupal.t('Design system values'),
          title: Drupal.t('Pick a design system value · Alt+↓ from the field'),
          onClick: () => {
            if (openRef.current) {
              menu.close();
            } else {
              open();
            }
          },
        },
        '▾',
      )
    : null;
  return { chevron, onInputKeyDown };
};
