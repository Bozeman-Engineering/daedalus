import store from '../store/store';
import {
  setHover,
  setSelected,
  setSelectedSet,
  setTarget,
  clearSelection,
} from '../store/slices/selection-slice';
import { blueprintReplaced } from '../store/slices/blueprint-slice';
import { elementForKey } from '../selection/resolver';
import { canonicalSelector } from '../materials/relative-selector';
// Circular with history.js (it enqueues restores, we hand it timeline
// updates); safe: both sides dereference only at call time.
import { historyUpdated } from '../history/history';
import { applyMaterialsDelta } from '../materials/materials-store';
import { pingMutated } from '../../responsive_preview/ping';
import { preserveScroll } from './preserve-scroll';

/**
 * The mutation queue — the client half of the blueprint mutation contract.
 *
 * Every canvas mutation flows through here to the daedalus_blueprint_api
 * endpoint (POST /daedalus-blueprint/{type}/{id}), one at a time, in order.
 * The response contract {blueprint, markup, targets, errors, attachments,
 * drupal_commands, history, materials} is applied uniformly: the timeline
 * and the materials delta first, then assets, then the markup subtree
 * swaps, the mirror, then any piggybacked AJAX commands; the open edit
 * hears the end of it all (mutationApplied) so its draft can be
 * re-projected over the swapped markup.
 *
 * Optimistic UI rides the queue: an op may carry {apply, rollback} hooks.
 * apply() runs the moment the op is enqueued (the user sees the change
 * instantly); rollback() runs if the server rejects it. Because ops are
 * FIFO, a failure also rolls back and rejects everything queued behind it —
 * later ops were built against state that no longer exists.
 *
 * Two kinds of entry ride the one queue. An OP is sent to the endpoint by
 * the queue itself and its payload applied here. A JOB answers the same
 * contract by its own transport (daedalus_edit's form commit: a Drupal
 * form's AJAX submit whose response carries the payload as one
 * daedalusBlueprintApplyResponse command, applied through the command
 * handler below); the queue only sequences it, so a form commit and the
 * ops around it stay FIFO and later ops see the page the job left. Either
 * way an entry executes to a payload or throws, and the failure tail is
 * the same.
 */

let sequence = 0;
let running = false;
let csrfToken = null;
const queue = [];

/**
 * Resolvers waiting for the queue to drain (see drained()).
 */
const drainWaiters = [];

const getCsrfToken = async () => {
  if (csrfToken === null) {
    const response = await fetch(Drupal.url('session/token'));
    csrfToken = await response.text();
  }
  return csrfToken;
};

const send = async (op) => {
  const token = await getCsrfToken();
  // The mutation response's markup is rendered in THIS request's context, and
  // edit-mode attribution is cookie-driven — but the navigationMode cookie is
  // path-scoped to the edited page, so it never rides a request to the
  // endpoint's path. Carry the mode as a query parameter instead, the same
  // convention every other edit-mode AJAX path uses (ModeState::getMode()
  // reads the parameter before the cookie). Without it the re-render comes
  // back un-attributed and the swap strips the canvas of its editing markup.
  const mode = Drupal.Daedalus.getCookieValue('navigationMode') ?? 'none';
  // A restore op targets the history endpoint; both answer the same
  // contract, so everything downstream of send() is shared.
  const path = op.restore
    ? `daedalus-blueprint/${op.entityType}/${op.entityId}/history/restore`
    : `daedalus-blueprint/${op.entityType}/${op.entityId}`;
  const response = await fetch(Drupal.url(`${path}?navigationMode=${mode}`), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
    },
    body: JSON.stringify({
      ...(op.restore ? op.restore : { operations: op.operations }),
      ...(op.label ? { label: op.label } : {}),
      ...(op.langcode ? { langcode: op.langcode } : {}),
      view_mode: op.viewMode,
      ajax_page_state: drupalSettings.ajaxPageState,
    }),
  });
  // Validation failures (422) carry the same contract shape as success.
  return response.json();
};

/**
 * Plays an array of Drupal AJAX commands.
 */
const playCommands = (commands) => {
  if (!commands || commands.length === 0) {
    return Promise.resolve();
  }
  const ajax = Drupal.ajax({ url: '' });
  return Promise.resolve(ajax.success(commands, 'success'));
};

/**
 * Swaps re-rendered subtree markup into the canvas by selectable key.
 *
 * The whole key loop runs inside one preserveScroll call: a response that
 * replaces the entity root removes the page's main content for the span of
 * the removal, and WebKit clamps the window scroll inside that removal
 * (preserve-scroll.js). One save and one restore per response, never per
 * key.
 */
const swapMarkup = (markup) => {
  preserveScroll(() => {
    Object.entries(markup ?? {}).forEach(([key, html]) => {
      const element = elementForKey(key);
      if (!element) {
        // The mutation landed server-side but the canvas has nowhere to
        // show it — a structural blind spot (an unattributed container,
        // evicted nested-edit chrome), not a transient. Loud, because the
        // symptom is "nothing happened" while tempstore says otherwise.
        console.warn(
          `Daedalus: no element on the page for mutation response markup "${key}"; the swap was skipped. The change is staged and appears on reload.`,
        );
        return;
      }
      Drupal.detachBehaviors(element, drupalSettings, 'unload');
      // The rendered subtree has a single root (the wrapper carrying the
      // key).
      element.insertAdjacentHTML('afterend', html);
      const replacement = element.nextElementSibling;
      element.remove();
      if (replacement) {
        Drupal.attachBehaviors(replacement, drupalSettings);
      }
    });
  });

  // A swap can delete the very elements the selection points at (e.g. a
  // removed component). Stale keys must not linger in the store: prune the
  // set to the surviving members; the primary follows (or hands off to the
  // most recent survivor) and an emptied set clears.
  const { hoverKey, selectedKey, selectedKeys } = store.getState().selection;
  const surviving = selectedKeys.filter((key) => elementForKey(key));
  if (surviving.length !== selectedKeys.length) {
    if (surviving.length === 0) {
      store.dispatch(clearSelection());
    } else {
      store.dispatch(setSelectedSet({ keys: surviving, primary: selectedKey }));
    }
  } else if (hoverKey && !elementForKey(hoverKey)) {
    store.dispatch(setHover(null));
  }
  // The styling target is revalidated the same way (a selection write
  // above already cleared it): a target whose key no longer has an
  // element (an ancestor rule's container removed under the selection)
  // or whose selector the grammar no longer reads points at nothing and
  // clears, so no surface keeps painting or writing at a vanished
  // address.
  const { target } = store.getState().selection;
  if (
    target &&
    (!elementForKey(target.key) || canonicalSelector(target.selector) === null)
  ) {
    store.dispatch(setTarget(null));
  }
};

/**
 * Commits the selection a settled mutation asked for (the select option):
 * focus follows what the user just placed. Each value resolves against the
 * swapped-in canvas (a bare uuid probes the placeable kinds, block then
 * container); values whose element never landed drop out, so a structural
 * blind spot skips selection instead of pointing at nothing. A landing is
 * a plain selection: no request opens an editor (editing is the user's
 * next, explicit gesture through edit-session.js openEditor).
 */
const applySelect = (select) => {
  const keys = (Array.isArray(select) ? select : [select])
    .map((value) =>
      (value.includes(':')
        ? [value]
        : [`block:${value}`, `container:${value}`]
      ).find((key) => elementForKey(key)),
    )
    .filter(Boolean);
  if (keys.length === 1) {
    store.dispatch(setSelected(keys[0]));
  } else if (keys.length > 1) {
    store.dispatch(setSelectedSet({ keys }));
  }
};

export const applyResponse = async (payload) => {
  // Mutation and restore responses carry the moved history timeline.
  if (payload.history) {
    historyUpdated(payload.history);
  }
  // Every response carries the materials delta (minted, updated, removed
  // records; empty on most). It lands before the markup so a widget
  // re-rendering off the swap already reads the minted material, and it
  // is the WHOLE of a materials-only payload (an AI turn that minted a
  // swatch and mutated no page: no blueprint, no entity_uuid, no markup),
  // which therefore touches neither the canvas nor the mirror below.
  if (payload.materials) {
    applyMaterialsDelta(payload.materials);
  }
  // Assets before markup, so a swapped-in subtree's CSS/JS are present the
  // moment it enters the document.
  await playCommands(payload.attachments);
  swapMarkup(payload.markup);
  // The response's blueprint replaces the mirror wholesale (the server owns
  // the model; clients never write the mirror directly), AFTER the markup
  // swap: "mirror replaced" means "this mutation has settled", model and
  // canvas together. Subscribers read the live canvas on this event
  // (daedalus_stylish's part-presence facts join the declared elements against the
  // canvas DOM at render time), and their re-render flushes on a microtask
  // — dispatching before the swap handed every such consumer the previous
  // mutation's DOM, one mutation behind, healed only when the response's
  // incidental drupalSettings envelope re-sync happened to fire a second
  // replacement post-swap (an asset-delivery accident, absent in warm
  // sessions). The mirror is the ROUTE entity's model: a payload for
  // another entity (an AI turn can mutate entities beyond the page) must
  // not replace it — its markup swaps still applied above wherever that
  // entity renders on this page.
  if (payload.blueprint) {
    const rootUuid = store.getState().blueprint.root?.uuid;
    if (!payload.entity_uuid || !rootUuid || payload.entity_uuid === rootUuid) {
      store.dispatch(blueprintReplaced(payload.blueprint));
    }
  }
  await playCommands(payload.drupal_commands);
  // Tell any paired responsive preview window a change landed. It re-renders
  // the staged state straight from the server (a reload), so no page state
  // rides the ping.
  pingMutated();
  // The response is FULLY applied: markup swapped, mirror replaced, the
  // piggybacked commands played (they may evict the very form an open edit
  // rides, which is why this and not the mirror replace is the signal).
  // An open edit's draft, captured when the swap detached its subtree,
  // resumes here over the fresh markup. The resume is the editor's own
  // flight (a form path may refetch), so it neither delays the op's
  // resolution nor can its failure roll back a mutation the server kept.
  // The payload rides along: its targets say which fields the write
  // changed, which the engine's panel reads to refresh the widgets the
  // write left stale.
  Drupal.Daedalus?.EditSession?.mutationApplied(payload);
};

/**
 * Registers the mutation-response contract's Drupal AJAX command.
 *
 * A write that rides another transport (a Drupal form's own AJAX submit)
 * answers a Drupal AJAX command list, and daedalus_blueprint_api's
 * BlueprintApplyResponseCommand is how the payload rides it. The handler
 * applies a successful payload through the one applyResponse pipeline,
 * exactly as an endpoint response is applied, and hands the payload
 * (success or failure) to the Drupal.Ajax instance that carried it, where
 * the transport that asked reads it once the round has settled. A failed
 * payload applies nothing: the transport judges it as any op error.
 *
 * Called once by the app entry, after the AJAX framework is on the page.
 */
export const initApplyResponseCommand = () => {
  Drupal.AjaxCommands.prototype.daedalusBlueprintApplyResponse = function (
    ajax,
    response,
  ) {
    const { payload } = response;
    ajax.daedalusBlueprintPayload = payload;
    if (!payload?.success) {
      return undefined;
    }
    return applyResponse(payload);
  };
};

/**
 * Applies a payload the queue sent for: the contract's failure shape (a
 * 422 carries success false and the errors) throws with the payload
 * attached, a success applies to the page.
 */
const applied = async (payload) => {
  if (!payload.success) {
    const error = new Error(
      payload.errors?.[0]?.message ?? 'The mutation failed.',
    );
    error.payload = payload;
    throw error;
  }
  await applyResponse(payload);
  return payload;
};

const process = async () => {
  if (running) {
    return;
  }
  running = true;
  // Ops apply in order on purpose: each one reads the page the previous one
  // left, so the awaits are sequential.
  while (queue.length > 0) {
    const op = queue.shift();
    try {
      // An entry executes to its applied payload (an op: sent and applied
      // here; a job: applied by its own transport) or throws.
      // eslint-disable-next-line no-await-in-loop
      const payload = await op.execute();
      // Select after the swap (the element must be on the page) and before
      // resolve, so a caller's completion hook observes the selection.
      if (op.select) {
        applySelect(op.select);
      }
      op.resolve(payload);
    } catch (error) {
      // Everything behind the failure was built on state that no longer
      // exists: roll it all back newest-first (a later op's rollback origin
      // can reflect an earlier op's applied state), the failing op last.
      const abandoned = queue.splice(0);
      abandoned.reverse().forEach((pending) => {
        pending.optimistic?.rollback?.();
      });
      op.optimistic?.rollback?.();
      // The failing op's rejection reports for the whole rolled-back run
      // (the mutation-failure display's loud tail); the abandoned
      // rejections are flagged quiet.
      error.abandonedCount = abandoned.length;
      op.reject(error);
      abandoned.forEach((pending) => {
        const rejection = new Error('A preceding mutation failed.');
        rejection.abandoned = true;
        pending.reject(rejection);
      });
    }
  }
  running = false;
  drainWaiters.splice(0).forEach((resolve) => resolve());
};

/**
 * Queues an entry: its optimistic apply runs now, its execute() in turn.
 */
const enqueue = ({ execute, optimistic = null, select = null }) =>
  new Promise((resolve, reject) => {
    optimistic?.apply?.();
    queue.push({
      seq: ++sequence,
      execute,
      optimistic,
      select,
      resolve,
      reject,
    });
    process();
  });

/**
 * Queues a blueprint mutation.
 *
 * @param {Object} op
 *   - entityType / entityId: the root entity the operations target.
 *   - operations: the batch-operation list (the wire grammar).
 *   - label: optional human-readable gesture name ("Moved Heading") — it
 *     becomes the mutation's history-entry label in the History sidebar.
 *   - langcode: optional translation language.
 *   - viewMode: the view mode the canvas displays (drives the re-render).
 *   - optimistic: optional { apply, rollback } hooks. apply() runs now;
 *     rollback() runs if this op (or one before it) fails.
 *   - select: optional element(s) to select once the response has applied,
 *     each a selectable key or a bare uuid (kind probed from the landed
 *     markup). Placement gestures pass what they placed so focus follows
 *     it; nothing is selected on failure or when the element never landed.
 *     The landing is a plain selection: no request opens an editor.
 *
 * @return {Promise<Object>}
 *   Resolves with the full response payload after it has been applied to
 *   the page; rejects with an Error carrying .payload on failure.
 */
export const enqueueOperations = ({
  entityType,
  entityId,
  operations,
  label = null,
  langcode = null,
  viewMode = 'full',
  optimistic = null,
  select = null,
}) =>
  enqueue({
    execute: () =>
      send({ entityType, entityId, operations, label, langcode, viewMode }).then(
        applied,
      ),
    optimistic,
    select,
  });

/**
 * Queues a history restore (undo/redo/jump).
 *
 * Restores ride the same FIFO queue as mutations, so a restore never
 * overtakes a pending mutation — the "undo waits for the queue to drain"
 * rule holds by construction. The response is the standard contract and is
 * applied identically; there is no optimistic half (the server owns what
 * the restored state looks like).
 *
 * @param {Object} op
 *   - entityType / entityId: the root entity whose timeline to move.
 *   - restore: {direction: 'undo'|'redo'} or {to: seq}.
 *   - langcode / viewMode: as for enqueueOperations.
 *
 * @return {Promise<Object>}
 *   Resolves with the applied response payload; rejects with an Error
 *   carrying .payload on failure (NOTHING_TO_RESTORE lands here too).
 */
export const enqueueHistoryRestore = ({
  entityType,
  entityId,
  restore,
  langcode = null,
  viewMode = 'full',
}) =>
  enqueue({
    execute: () =>
      send({ entityType, entityId, restore, langcode, viewMode }).then(applied),
  });

/**
 * Queues a job: a write that answers the mutation-response contract by
 * its own transport.
 *
 * The job runs in its turn, FIFO with the ops around it, and its run()
 * settles once its response has been applied to the page (a transport
 * carrying the payload as a daedalusBlueprintApplyResponse command applies
 * it through the command handler above during the round). It resolves
 * with the applied payload, throws with a failed one (success false: the
 * spine's errors, judged like an op's 422), or rejects when the transport
 * itself refused (a form's validation errors, an HTTP failure); every
 * failure rolls back the queue behind it exactly as an op's does.
 *
 * @param {Object} job
 *   - run: () => Promise<Object> — the transport round trip, resolving
 *     with the payload it carried back.
 *   - optimistic: optional { apply, rollback } hooks, as for an op.
 *
 * @return {Promise<Object>}
 *   Resolves with the applied payload; rejects with the failure.
 */
export const enqueueJob = ({ run, optimistic = null }) =>
  enqueue({
    execute: () =>
      Promise.resolve(run()).then((payload) => {
        if (!payload?.success) {
          const error = new Error(
            payload?.errors?.[0]?.message ?? 'The mutation failed.',
          );
          error.payload = payload;
          throw error;
        }
        return payload;
      }),
    optimistic,
  });

/**
 * True while mutations are queued or in flight (undo waits for this).
 */
export const isDraining = () => running || queue.length > 0;

/**
 * Resolves once nothing is queued or in flight (Save waits for this: a
 * commit still on the wire must land in tempstore before the page
 * navigates to the save route).
 *
 * @return {Promise<void>}
 */
export const drained = () =>
  isDraining()
    ? new Promise((resolve) => {
        drainWaiters.push(resolve);
      })
    : Promise.resolve();
