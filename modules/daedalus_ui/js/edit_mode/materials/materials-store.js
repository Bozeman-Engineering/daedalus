import store from '../store/store';
import {
  materialsBooted,
  materialsDelta,
  assertDelta,
  isEmptyDelta,
} from '../store/slices/materials-slice';
import { createEnvelopeSync } from '../utilities/settings-envelope';
import { openChannel } from '../../broadcast/channel.js';

/**
 * @file
 * The materials store's delivery seams: page-load boot and the delta wire.
 *
 * Boot: drupalSettings.daedalusUi.materials is the JSON envelope
 * `{records: [...]}` BlueprintMirror attaches beside the blueprint
 * envelope on every seam that renders the editable page (the same idempotent
 * string-equality sync as the mirror; see settings-envelope.js).
 *
 * Delta: every mutation-response payload carries `materials` {upsert,
 * remove} (daedalus_blueprint_api's MutationResponseBuilder, the daedalus_stylish and
 * stencil gesture routes, the AI turn's payloads). The op queue applies it
 * through applyMaterialsDelta() on every payload, and the applied delta is
 * relayed over the shared BroadcastChannel wire (broadcast/channel.js) so
 * every paired window of this edit session (a popped-out inspector, a
 * second tab on the same page) learns the mint the moment it lands:
 *
 * - channel `materials:<scope>`, scope = the edit session's
 *   (EditSessionScope, delivered once as daedalusUi.editSession.scope by
 *   the Edit mode; a pop-out page carries the same value as
 *   daedalusUi.popout.scope);
 * - one frame, `delta` {upsert, remove}, the response's delta verbatim.
 *
 * There is no hello/snapshot protocol: every window boots from the server
 * truth of its own page load and takes deltas from there, so a received
 * delta is applied and never re-posted (BroadcastChannel never echoes to
 * the posting context, and a relay of a relay would ring).
 */

let channel = null;

const openWire = () => {
  const scope = drupalSettings.daedalusUi?.editSession?.scope;
  if (!scope) {
    return null;
  }
  return openChannel('materials', scope, {
    delta: (delta) => {
      assertDelta(delta);
      if (!isEmptyDelta(delta)) {
        store.dispatch(materialsDelta(delta));
      }
    },
  });
};

/**
 * Applies a mutation response's materials delta and relays it to the
 * session's other windows. An empty delta (the shape every response
 * carries) touches nothing.
 */
export const applyMaterialsDelta = (delta) => {
  assertDelta(delta);
  if (isEmptyDelta(delta)) {
    return;
  }
  store.dispatch(materialsDelta(delta));
  channel?.post('delta', { upsert: delta.upsert, remove: delta.remove });
};

const syncFromSettings = createEnvelopeSync(
  () => drupalSettings.daedalusUi?.materials,
  (envelope) => store.dispatch(materialsBooted(envelope)),
);

/**
 * Boots the store from drupalSettings and opens the delta wire. Called
 * once by the app entry, beside the blueprint mirror; the settings sync is
 * re-checked on every behavior attach, never init-only, for the same
 * reasons as the mirror's (a cold non-edit load entering Edit Mode via
 * AJAX, a LoadEditablePage re-render's settings merge).
 */
export const initMaterialsStore = () => {
  syncFromSettings();
  channel = openWire();
  Drupal.behaviors.NpMaterialsStore = {
    attach: () => syncFromSettings(),
  };
};
