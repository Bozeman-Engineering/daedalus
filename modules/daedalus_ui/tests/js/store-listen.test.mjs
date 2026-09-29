/**
 * @file
 * listenToStateChange under a callback that dispatches.
 *
 * The store's change listener fires a callback when its selector's value
 * changes. A callback may dispatch (the Add panel's insert session disarms
 * on a selection change; the selection frame's consumers re-derive), and a
 * dispatch re-enters the subscriber synchronously, so the listener must
 * record the change BEFORE calling back: with the previous value still
 * stale the same change would fire again without end (the call stack
 * overflow that once kept a mode listener from ever settling). A
 * change the callback itself makes to the watched value is still
 * delivered, once.
 *
 * Run: node --test modules/daedalus_ui/tests/js/store-listen.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';

// The app's sources import each other without extensions (webpack
// resolves them); node needs the same leniency.
registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

globalThis.window = globalThis;

const { default: store, listenToStateChange } = await import(
  '../../js/edit_mode/store/store.js'
);
const { setSelected, setHover, clearSelection } = await import(
  '../../js/edit_mode/store/slices/selection-slice.js'
);

test('a callback that dispatches an unrelated change fires once', () => {
  store.dispatch(clearSelection());
  const seen = [];
  listenToStateChange(
    (state) => state.selection.selectedKey,
    (key) => {
      seen.push(key);
      // The re-entrant dispatch: the subscriber runs again inside this
      // callback with the watched value unchanged since it was recorded.
      store.dispatch(setHover(null));
    },
  );
  store.dispatch(setSelected('block:a'));
  assert.deepEqual(seen, ['block:a']);
});

test('a change the callback makes to the watched value is delivered once', () => {
  store.dispatch(clearSelection());
  const seen = [];
  listenToStateChange(
    (state) => state.selection.selectedKey,
    (key) => {
      seen.push(key);
      if (key === 'block:b') {
        store.dispatch(setSelected('block:c'));
      }
    },
  );
  store.dispatch(setSelected('block:b'));
  assert.deepEqual(seen, ['block:b', 'block:c']);
  assert.equal(store.getState().selection.selectedKey, 'block:c');
});
