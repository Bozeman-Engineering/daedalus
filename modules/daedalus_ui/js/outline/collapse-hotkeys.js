import { collapseAll, expandAll } from './collapse';

/**
 * @file
 * The collapse hotkey registrar: the two all-gestures of one outline as
 * bindings in the one hotkey registry.
 *
 * The registry is reached through its runtime facade
 * (Drupal.Daedalus.Hotkeys, published by the app's initHotkeys), never
 * by importing hotkeys.js: js/outline/ is compiled into other bundles
 * (daedalus_stylish's), and a module import would give that bundle its
 * own copy of the registry and of the store behind it, a second store
 * whose mode-slice globals overwrite the app's at load. The facade is
 * the one registry every bundle in the leader document shares.
 *
 * An outline that offers collapse-all and expand-all on the keyboard
 * registers `${id}.collapse_all` and `${id}.expand_all` here, in the
 * settings tray's Panels section beside the panel toggles, remappable,
 * and in the `global` context (the dispatcher's typing guard keeps a
 * bare key out of text entry, and both outlines may be open at once, so
 * a second outline registers its pair unbound by default and the user
 * binds them in the tray). The registrar knows nothing of the outline's
 * state: each action asks the adapter for its current posture and
 * outline (`read()`), runs the model's gesture, and hands the next
 * posture back (`apply(next, gesture)`), the gesture name riding along
 * so an adapter that must forward the gesture instead of applying it
 * (code mode's leader while the inspector is popped out, whose own
 * outline is displaced) can. The action always applies: the model hands
 * back the same object when nothing changes, so a state setter sees a
 * no-op, and an adapter forwarding the gesture judges nothing here. The
 * stroke is handled either way, like any bound key.
 *
 * Registered for the outline's lifetime: the call returns the
 * unregister, for the adapter's effect cleanup.
 */

/**
 * The two binding ids of an outline, for the control's tooltips and any
 * consumer naming them.
 *
 * @param {string} id
 *   The outline's id, e.g. 'page_elements'.
 *
 * @return {{collapseAll: string, expandAll: string}}
 */
export const collapseHotkeyIds = (id) => ({
  collapseAll: `${id}.collapse_all`,
  expandAll: `${id}.expand_all`,
});

/**
 * Registers an outline's collapse-all and expand-all bindings.
 *
 * @param {object} spec
 *   - id: the outline's id; the bindings are `${id}.collapse_all` and
 *     `${id}.expand_all`.
 *   - label: the outline's name as the tray lists it ('Page elements'),
 *     since two outlines may register the same pair.
 *   - keys: optional default keys `{ collapseAll, expandAll }`; a missing
 *     one registers unbound (listed in the tray, fires once bound).
 *   - read: function() returning `{ folds, outline }`, the adapter's
 *     current posture and outline.
 *   - apply: function(next, gesture) receiving the next posture and the
 *     gesture name ('collapse_all' or 'expand_all').
 *
 * @return {Function}
 *   Unregisters both bindings.
 */
export const registerCollapseHotkeys = ({ id, label, keys = {}, read, apply }) => {
  const ids = collapseHotkeyIds(id);
  const gestures = [
    {
      id: ids.collapseAll,
      gesture: 'collapse_all',
      key: keys.collapseAll ?? '',
      run: collapseAll,
      label: Drupal.t('Collapse all in @outline', { '@outline': label }),
    },
    {
      id: ids.expandAll,
      gesture: 'expand_all',
      key: keys.expandAll ?? '',
      run: expandAll,
      label: Drupal.t('Expand all in @outline', { '@outline': label }),
    },
  ];
  const registry = Drupal.Daedalus.Hotkeys;
  gestures.forEach(({ id: bindingId, gesture, key, run, label: bindingLabel }) => {
    registry.register({
      id: bindingId,
      key,
      when: 'global',
      label: bindingLabel,
      section: Drupal.t('Panels'),
      remappable: true,
      action: () => {
        const { folds, outline } = read();
        apply(run(folds, outline), gesture);
      },
    });
  });
  return () => gestures.forEach(({ id: bindingId }) => registry.unregister(bindingId));
};
