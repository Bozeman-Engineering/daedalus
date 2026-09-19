import { enqueueHistoryRestore } from '../ops/op-queue';
import { register as registerHotkey } from '../hotkeys/hotkeys';

/**
 * Multi-step undo/redo — the client face of the edit-history timeline.
 *
 * The server owns the history (tempstore payload snapshots captured at the
 * one persistence choke point); this module mirrors the timeline METADATA
 * (labels, pointer, can-undo/redo) and asks the server to move the pointer.
 * A restore rides the same FIFO op queue as every mutation, so "undo waits
 * for pending mutations to drain" holds by construction, and the restore
 * response (the standard mutation contract) is applied by the same
 * pipeline: blueprint mirror replaced, subtree markup swapped.
 *
 * The mirror updates from the `history` key every mutation and restore
 * response carries; the sidebar calls refresh() for its initial fill. The
 * undo/redo hotkeys work without any preloaded state — the server answers
 * NOTHING_TO_RESTORE (handled quietly) when there is no step to take.
 */

let state = {
  loaded: false,
  pointer: null,
  canUndo: false,
  canRedo: false,
  entries: [],
};

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener(state));

/**
 * Replaces the mirrored timeline from a server `history` payload.
 */
export const historyUpdated = (metadata) => {
  if (!metadata) {
    return;
  }
  state = {
    loaded: true,
    pointer: metadata.pointer ?? null,
    canUndo: !!metadata.can_undo,
    canRedo: !!metadata.can_redo,
    entries: metadata.entries ?? [],
  };
  notify();
};

/**
 * Queues a restore against the route's root entity.
 *
 * @param {Object} body
 *   {direction: 'undo'|'redo'} or {to: seq}.
 *
 * @return {Promise<?Object>}
 *   The applied response payload, or null when there was nothing to do or
 *   the failure was already reported.
 */
const restore = async (body) => {
  const context = Drupal.Daedalus.Blueprint.rootContext();
  if (!context) {
    return null;
  }
  try {
    return await enqueueHistoryRestore({
      entityType: context.entityType,
      entityId: context.entityId,
      viewMode: context.viewMode,
      restore: body,
    });
  } catch (error) {
    // Racing past the timeline's edge (a spare mod+z) is not an error the
    // user needs to hear about.
    if (error.payload?.errors?.[0]?.code === 'NOTHING_TO_RESTORE') {
      return null;
    }
    Drupal.Daedalus.Blueprint.reportFailure(error);
    return null;
  }
};

/**
 * Undoes one step. No-op at the start of the timeline.
 */
export const undo = () => {
  if (state.loaded && !state.canUndo) {
    return;
  }
  restore({ direction: 'undo' });
};

/**
 * Redoes one step. No-op at the end of the timeline.
 */
export const redo = () => {
  if (state.loaded && !state.canRedo) {
    return;
  }
  restore({ direction: 'redo' });
};

/**
 * Jumps to a specific entry (a sidebar row click).
 */
export const restoreTo = (seq) => {
  if (state.loaded && state.pointer === seq) {
    return;
  }
  restore({ to: seq });
};

/**
 * Fetches the timeline from the server (the sidebar's initial fill).
 */
export const refresh = async () => {
  const context = Drupal.Daedalus.Blueprint.rootContext();
  if (!context) {
    return;
  }
  // navigationMode rides as a query parameter on every daedalus_blueprint_api
  // request — the cookie is path-scoped to the edited page.
  const mode = Drupal.Daedalus.getCookieValue('navigationMode') ?? 'none';
  const response = await fetch(
    Drupal.url(
      `daedalus-blueprint/${context.entityType}/${context.entityId}/history?navigationMode=${mode}`,
    ),
  );
  if (response.ok) {
    historyUpdated(await response.json());
  }
};

/**
 * The mirrored timeline state.
 */
export const getState = () => state;

/**
 * Subscribes to timeline changes.
 *
 * @return {Function}
 *   Unsubscribe.
 */
export const onChange = (listener) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/**
 * Boots the history facade and hotkeys. Called once by the app entry,
 * after hotkeys.
 */
export const initHistory = () => {
  registerHotkey({
    id: 'history.undo',
    key: 'mod+z',
    when: 'global',
    label: Drupal.t('Undo'),
    section: Drupal.t('Actions'),
    remappable: true,
    action: undo,
  });
  registerHotkey({
    id: 'history.redo',
    key: 'mod+shift+z',
    when: 'global',
    label: Drupal.t('Redo'),
    section: Drupal.t('Actions'),
    remappable: true,
    action: redo,
  });

  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.History = {
    undo,
    redo,
    restoreTo,
    refresh,
    getState,
    onChange,
  };
};
