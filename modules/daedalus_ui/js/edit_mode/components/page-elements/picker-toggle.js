import { h } from 'preact';
import { useSelector } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import { togglePicker } from '../../selection/picker';
import { getBindings, onChange } from '../../hotkeys/hotkeys';
import { describeBinding } from '../../hotkeys/binding';

/**
 * The element picker toggle: the DevTools "select an element" button on
 * the page elements tree's root row.
 *
 * A kit icon button the tree renders last among the outline's tools
 * (page-elements-tree.js OutlineTools). Pressed, it arms the
 * picker (selection/picker.js, the one toggle the `selector.picker`
 * hotkey shares): canvas hover and click then resolve the deepest
 * presented element and part exactly as holding the platform modifier
 * does, and the first canvas click disarms. `aria-pressed` follows the
 * selection slice's flag, whichever door armed or disarmed it (the
 * button, the hotkey, a handled click, Escape, leaving Edit Mode); the kit's
 * pressed rule (panel-controls.css) paints it, nothing here restyles it.
 *
 * The button exists for discoverability: the tooltip names the modifier
 * the picker stands in for and the binding's CURRENT key (a remap
 * re-words it), both through the registry's platform knowledge, and it
 * conceals at clean density only, like the close, so it never vanishes
 * at minimal, the trap the close's rule names.
 */

/**
 * The glyph: a reticle, the Safari Web Inspector's element-selection
 * icon: a ring with four ticks crossing it toward a clear center, so it
 * reads as "aim at one thing". Inline, like the verbs' icons
 * (verbs/rename-verb.js): a stock pointer glyph would read as "the
 * pointer", not "pick once". Stroke-drawn in currentColor at the
 * collapse glyphs' weight beside it; the ticks stop short of the center
 * (r 3) so the crossing stays open at the row's icon size.
 */
export const pickerIcon =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="6.5"/><path d="M12 2.5v6M12 15.5v6M2.5 12h6M15.5 12h6"/></svg>';

/**
 * The radius the reveal engine's auto-register gives declaratively
 * concealed chrome (reveal.js DEFAULT_NEAR): the panel's close gets it
 * that way, so both reveal at the same distance.
 */
const REVEAL_NEAR = 80;

export const PickerToggle = () => {
  const armed = useSelector((state) => state.selection.pickerArmed);
  // The binding's key changes under a remap (the settings tray); re-word
  // the tooltip on registry changes.
  const [, bump] = useState(0);
  useEffect(() => onChange(() => bump((count) => count + 1)), []);
  const buttonRef = useRef(null);

  // Preact-rendered, so the [data-daedalus-ui-conceal] auto-register
  // behavior never sees it; register with the reveal engine for the
  // button's lifetime.
  useEffect(() => {
    const el = buttonRef.current;
    Drupal.Daedalus.reveal.register(el, { near: REVEAL_NEAR });
    return () => Drupal.Daedalus.reveal.unregister(el);
  }, []);

  // selector.picker registers with the Selector grammar at app init,
  // before the tree mounts; its key is the effective one (remaps applied),
  // and may be empty when the user unbound it.
  const binding = getBindings().find(({ id }) => id === 'selector.picker');
  const key = describeBinding(binding.key);
  const modifier = describeBinding('mod');
  const tooltip = key
    ? Drupal.t(
        'Pick the deepest element under the pointer, like holding @modifier (@key)',
        { '@modifier': modifier, '@key': key },
      )
    : Drupal.t(
        'Pick the deepest element under the pointer, like holding @modifier',
        { '@modifier': modifier },
      );

  return (
    <button
      type="button"
      className="daedalus-ui-picker-toggle daedalus-ui-icon-button daedalus-ui-icon-button--muted"
      aria-pressed={armed}
      aria-label={Drupal.t('Pick an element')}
      data-daedalus-ui-tooltip={tooltip}
      data-daedalus-ui-conceal="clean"
      ref={buttonRef}
      onClick={() => togglePicker()}
      dangerouslySetInnerHTML={{ __html: pickerIcon }}
    />
  );
};
