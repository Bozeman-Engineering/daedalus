import { openChannel } from '../../../../daedalus_ui/js/broadcast/channel.js';

/**
 * The leader half of the pop-out pair.
 *
 * The main page stays the single authority: it owns the op queue, the
 * tempstore session, and (later) the live-preview style tag. The satellite
 * is a projection — it renders whatever state the leader broadcasts and
 * sends intents back; it never mutates anything itself. Two peer windows
 * racing ops over one tempstore was the rejected shape.
 *
 * The wire itself is daedalus_ui's shared channel envelope, opened
 * under the 'daedalus_stylish' prefix. Daedalus Stylish's protocol on it (all messages
 * `{ type, ...payload }`):
 * - satellite → 'hello'        I exist; send me everything. Sent at boot
 *                              and in reply to leader-ready, so both sides
 *                              of a refresh re-pair.
 * - leader    → 'snapshot'     Full state `{ state }`, in reply to hello.
 * - leader    → 'state'        Same shape as snapshot, on every change.
 * - leader    → 'hover'        The shared selection hover `{ key }` — its
 *                              own slim message because hover moves at
 *                              pointer speed and the state snapshot
 *                              digests layout context per post.
 * - leader    → 'hover-selector' The selector-hover facet
 *                              `{ key, selector }` (both null when
 *                              cleared) — hover's twin for the same
 *                              pointer-speed reason.
 * - leader    → 'leader-ready' I (re)booted; any satellite should hello.
 * - leader    → 'leader-gone'  I'm unloading. A refresh follows with
 *                              leader-ready; a real close leaves the
 *                              satellite showing disconnected.
 * - satellite → 'intent'       A requested mutation `{ intent }`; the
 *                              leader applies it through the op queue.
 * - leader    → 'settled'      An op intent's outcome (commit,
 *                              commit-batch, apply-variant, detach-variant,
 *                              parse-css, read-css)
 *                              `{ request, ok, payload | error }`: the op
 *                              queue's own settlement, relayed so the
 *                              satellite's promise settles like the
 *                              leader's (a material op's caller continues
 *                              with the written record; a failure toasts
 *                              in the satellite's own messenger).
 * - satellite → 'bye'          Window closing; leader restores the panel.
 * - leader    → 'close'        Close yourself (the bring-back control).
 * - leader    → 'open-variant-picker' Open the variant picker for the
 *                              current selection in your window. The
 *                              apply-variant hotkey resolves on the
 *                              leader (a press in the satellite reaches
 *                              it through daedalus_ui's pop-out keyboard
 *                              wire), and while the panel is popped out
 *                              the picker belongs where the panel is.
 * - leader    → 'collapse-outline' Run one all-gesture `{ gesture }`
 *                              ('collapse_all' or 'expand_all') over
 *                              code mode's outline in your window. The
 *                              outline's hotkeys resolve on the leader
 *                              (same wire as above), a collapse is a
 *                              reading posture of one window, and while
 *                              the panel is popped out the leader's
 *                              outline is displaced: the satellite
 *                              applies the gesture to its own posture
 *                              (code/collapse-seam.js).
 *
 * Keyboard is NOT on this channel: every Edit Mode shortcut pressed in
 * the satellite is forwarded to the leader's dispatcher by daedalus_ui's
 * pop-out keyboard wire (`daedalus_ui:<scope>`, hotkeys/popout-relay.js)
 * and resolved there. This protocol carries only what an action decides
 * once resolved.
 *
 * Presence is tracked by handshake, not connection state (BroadcastChannel
 * has none): hello marks the satellite present, bye marks it gone, and a
 * snapshot broadcast at leader boot re-provokes a hello from any satellite
 * that outlived a leader refresh.
 */
export const createPopoutLeader = ({ scope, url, source }) => {
  let poppedOut = false;
  let satelliteWindow = null;
  const listeners = new Set();

  const setPoppedOut = (value) => {
    if (poppedOut !== value) {
      poppedOut = value;
      listeners.forEach((callback) => callback(poppedOut));
    }
  };

  // Everything session-shaped the satellite renders from, pre-digested so
  // it needs no blueprint mirror of its own: the selection, the root map's
  // values, and the component → block type and section → layout tables its
  // declaration resolution needs. The style guide itself is NOT broadcast —
  // it is site configuration, served to the satellite page directly
  // (InspectorPopout).
  const snapshotState = () => ({
    // The selection channel carries the styling target beside the key:
    // the satellite renders the same selection and target the leader's
    // spine holds, never a parallel target state of its own.
    selected: source.getSelected(),
    target: source.getTarget(),
    styleMap: source.getStyleMap(),
    rootUuid: source.getRootUuid(),
    blockTypes: source.getBlockTypes(),
    sectionLayouts: source.getSectionLayouts(),
    // The staged layout's structural tree, so satellite-initiated variant
    // capture can walk the subtree the leader page stages.
    layout: source.getLayout(),
    // The lean node tree, so the satellite's stacks carry the same
    // ancestor layers and its fact rows light the same way.
    tree: source.getTree?.() ?? [],
    // The selection's layout surroundings (parent computed display et al),
    // digested here because computed style needs the canvas document the
    // satellite lacks. Rides every broadcast, so it refreshes exactly when
    // the selection or the styles do.
    layoutContext: source.getLayoutContext(),
    // The media rows' render inputs: the uuid → URL table (thumbnails) and
    // whether a pick intent can actually open the library over here. A pick
    // extends the table before its commit settles, and that commit's style
    // map change triggers the broadcast, so the snapshot never ships a
    // token the table can't answer.
    mediaUrls: source.getMediaUrls(),
    mediaLibraryAvailable: source.canPickMedia(),
    // The canvas state vector + the selection-scoped force state (task
    // 10): the satellite's "Editing at" chips mirror the leader's canvas,
    // and its writes must land at the same compound the leader's would.
    canvasVector: source.getCanvasVector(),
    forceState: source.getForceState(),
    // The place state (four controls, one loud mode): the satellite's
    // panel must stand where the leader's would (same place, same
    // mounted editing session), so the one source-owned {place,
    // editing} state rides every broadcast.
    place: source.getPlace(),
  });

  // The vector subscription binds on the first hello, not at creation:
  // the canvas vector facade attaches in its own behavior, which this
  // leader (created inside the app-boot queue) may run ahead of — and a
  // satellite only exists after a user gesture, long past both. Bound
  // once; every other broadcast refreshes the vector snapshot anyway.
  let vectorBound = false;
  const bindVector = () => {
    if (!vectorBound) {
      vectorBound = true;
      source.onCanvasVectorChange(broadcast);
    }
  };

  // Relays an op intent's settlement. The op settles on this page (op
  // queue, preview release, the op failure report's flash and toast); the
  // satellite's promise waits on the `settled` answer, carrying the op
  // queue's payload or its Error shape (message + payload), so it settles
  // exactly like the leader source's and the satellite reports the fate
  // in its own window.
  const settle = (request, promise) =>
    promise.then(
      (result) =>
        channel.post('settled', { request, ok: true, payload: result }),
      (error) =>
        channel.post('settled', {
          request,
          ok: false,
          error: {
            message: error?.message ?? null,
            payload: error?.payload ?? null,
          },
        }),
    );

  const channel = openChannel('daedalus_stylish', scope, {
    hello: () => {
      setPoppedOut(true);
      bindVector();
      channel.post('snapshot', { state: snapshotState() });
    },
    bye: () => {
      // The satellite's pending session (panel state) died inside its
      // window, so the synced `editing` must not survive it: left set,
      // the returning in-page panel would boot into a half-mounted mode
      // (auto-outline on, no session, no exits). A closing window runs
      // no effect cleanup, so this is the leader-side half of the
      // Inspector's own unmount rule. Cleared BEFORE the popped-out
      // flip below, so the remounting panel's first place read is
      // already honest.
      source.setPlace({ ...source.getPlace(), editing: null });
      setPoppedOut(false);
      // A satellite that closes mid-hover can never send its clearing
      // signal; drop any highlight, paint, or selection hover so no
      // chrome outlives its window. (A canvas hover this happens to
      // clear repaints on the leader's next pointer move.)
      source.setCanvasHighlight(null);
      source.setCanvasPaint(null);
      source.hover?.(null);
      source.hoverSelector?.(null);
    },
    // Satellite widget gestures, applied through the leader source — the
    // leader stays the single writer (one op queue, one preview tag). An
    // op failure reports on this page (the flash and this page's toast)
    // AND settles back to the satellite, which reports it in its
    // own window; the reverted map follows on the next state broadcast.
    intent: (payload) => {
      if (payload.intent === 'preview') {
        source.preview(
          payload.key,
          payload.condition,
          payload.property,
          payload.value,
        );
      } else if (payload.intent === 'release') {
        source.previewRelease(
          payload.key,
          payload.condition,
          payload.properties,
        );
      } else if (payload.intent === 'commit') {
        settle(payload.request, source.commit(payload.change));
      } else if (payload.intent === 'preview-entries') {
        source.previewEntries(payload.entries);
      } else if (payload.intent === 'release-entries') {
        source.previewEntriesRelease(payload.keys);
      } else if (payload.intent === 'commit-batch') {
        settle(payload.request, source.commitBatch(payload.batch));
      } else if (payload.intent === 'parse-css') {
        // The code view's paste door: a write-nothing read of a CSS
        // fragment under a key, fetched from this page (the CSRF token
        // and the root entity are here); the entries go back for the
        // satellite's island to land through its own commit-batch.
        settle(payload.request, source.parseCss(payload.key, payload.css));
      } else if (payload.intent === 'read-css') {
        // The header's Copy CSS: the server print of a key's own entries,
        // fetched from this page (the root entity is here); the text goes
        // back for the satellite's own clipboard write.
        settle(payload.request, source.readCss(payload.key));
      } else if (payload.intent === 'apply-variant') {
        settle(
          payload.request,
          source.applyVariant(payload.key, payload.variant, payload.label),
        );
      } else if (payload.intent === 'detach-variant') {
        settle(
          payload.request,
          source.detachVariant(payload.key, payload.variant, payload.label),
        );
      } else if (payload.intent === 'pick-media') {
        // A satellite media row's pick: the modal and the delivery (table
        // extension, staged preview, commit) all live on this page. The
        // committed token reaches the satellite on the next state broadcast.
        source.pickMedia(payload);
      } else if (payload.intent === 'swatch-preview') {
        // A satellite's live swatch-value preview (the gradient swatch
        // session's drag): the canvas lives here, so the draft lands on
        // this page's token scope element; null releases it back to the
        // store's value. Committed changes never come this way: they ride
        // the materials wire as deltas (materials-store.js).
        source.previewSwatch(
          source.getMaterials().get('swatch', payload.id),
          payload.value,
        );
      } else if (payload.intent === 'animation-preview') {
        // A satellite's keyframes session settle: the canvas and the
        // preview sheet live here, so the draft steps print into this
        // page's animation preview tag; null releases them back to the
        // store's steps. Committed steps never come this way: they ride
        // the materials wire as deltas.
        source.previewAnimation(
          source.getMaterials().get('animation', payload.id),
          payload.steps,
        );
      } else if (payload.intent === 'select') {
        // The satellite's selection intents (page tree row click, the
        // subject chip's way back): drive the canvas selection here (the
        // spine lives on this page); the change broadcasts back through
        // the ordinary selection subscription.
        source.select(payload.key);
      } else if (payload.intent === 'target') {
        // The satellite's target intents (a code-mode row header, the
        // address switcher, "Edit at"): seat the styling target on the
        // spine here (the canvas paints its matches here); the change
        // broadcasts back the same way.
        source.target(payload.key, payload.selector);
      } else if (payload.intent === 'hover') {
        // The satellite page tree's row hover: drive the shared selection
        // hover here (canvas outline + page-elements row), the echo
        // returning on the hover broadcast — select's pattern.
        source.hover?.(payload.key);
      } else if (payload.intent === 'hover-selector') {
        // The satellite's part-header hover: drive the spine's
        // hoverSelector facet here (canvas dashed paint + tree part-row
        // glow), the echo returning on the hover-selector broadcast —
        // hover's pattern.
        source.hoverSelector?.(payload.key, payload.selector ?? null);
      } else if (payload.intent === 'canvas-highlight') {
        // Satellite hover/scrub signals: the bands render on THIS page
        // (the satellite has no canvas), driven through the one
        // source-owned highlight state.
        source.setCanvasHighlight(payload.spec);
      } else if (payload.intent === 'canvas-paint') {
        // Satellite outline paint/flash: the outlines render on THIS page,
        // through the one source-owned paint state.
        source.setCanvasPaint(payload.paint);
      } else if (payload.intent === 'canvas-vector') {
        // A satellite "drive the canvas there": the vector chrome lives on
        // this page; the new pose broadcasts back on its change.
        source.setCanvasVector(payload.partial);
      } else if (payload.intent === 'force-state') {
        source.setForceState(payload.state);
      } else if (payload.intent === 'motion-replay') {
        // A satellite Replay: the canvas and the motion-triggers runtime
        // live on this page, so the target re-arms here.
        source.replayMotion(payload.key, payload.selector ?? null);
      } else if (payload.intent === 'place') {
        // A satellite place pick: the one place state lives on this
        // page; the new value broadcasts back on its change.
        source.setPlace(payload.state);
      }
    },
  });

  // Recover a satellite orphaned by a leader refresh: it re-hellos on
  // leader-ready, which re-marks it present above. The unload signal lets
  // the satellite distinguish a leader mid-refresh from a leader gone for
  // good — a following leader-ready resolves the former.
  channel.post('leader-ready');
  window.addEventListener('pagehide', () => channel.post('leader-gone'));

  const broadcast = () => channel.post('state', { state: snapshotState() });
  // One selection subscription, the target-aware one: it fires on
  // selection AND target changes, so the snapshot's selection and target
  // always travel together.
  source.onTargetChange(broadcast);
  // Hover rides its own slim message (see the protocol note): transient
  // pointer-speed chrome, deliberately outside the snapshot — a satellite
  // that connects mid-hover just waits for the next crossing.
  source.onHoverChange?.((key) => channel.post('hover', { key }));
  source.onHoverSelectorChange?.((pair) =>
    channel.post('hover-selector', {
      key: pair?.key ?? null,
      selector: pair?.selector ?? null,
    }),
  );
  source.onStyleMapChange(broadcast);
  source.onForceStateChange(broadcast);
  source.onPlaceChange(broadcast);

  return {
    isPoppedOut: () => poppedOut,

    /**
     * Subscribes to popped-out changes; returns unsubscribe.
     */
    onChange: (callback) => {
      listeners.add(callback);
      return () => listeners.delete(callback);
    },

    /**
     * Opens (or refocuses — the window is named) the satellite window.
     */
    open: () => {
      satelliteWindow = window.open(
        url,
        'daedalus_stylish_inspector',
        'popup=yes,width=340,height=720',
      );
    },

    /**
     * Returns the inspector to the page by closing the satellite.
     *
     * The direct close covers the normal case; the 'close' broadcast covers
     * a satellite this leader has no reference to (opened before a leader
     * refresh). Either way the satellite's pagehide posts bye, which is
     * what restores the in-page panel.
     */
    bringBack: () => {
      channel.post('close');
      if (satelliteWindow && !satelliteWindow.closed) {
        satelliteWindow.close();
      }
    },

    /**
     * Opens the variant picker in the satellite window.
     *
     * The apply-variant action's popped-out branch (index.js): the wear
     * bar that owns the picker is mounted over there, so the open goes
     * over the wire and the satellite's opener seam answers it. The
     * wire is one-way and asynchronous, so the satellite's verdict (no
     * bar mounted, an apply in flight, an empty offer) never comes back;
     * the action counts the press as handled once it is routed.
     */
    openVariantPicker: () => channel.post('open-variant-picker'),

    /**
     * Runs a collapse-all or expand-all gesture over the satellite's
     * code mode outline.
     *
     * The outline hotkeys' popped-out branch (index.js): the posture is
     * per window and the leader's view is unmounted while the panel is
     * popped out, so the GESTURE (never a posture) goes over the wire
     * and the satellite runs it over its own posture through the seam
     * its mounted view fills. One-way, like the picker open: the
     * routed press counts as handled.
     *
     * @param {string} gesture
     *   'collapse_all' or 'expand_all'.
     */
    collapseOutline: (gesture) =>
      channel.post('collapse-outline', { gesture }),
  };
};
