import { openChannel } from '../../../../daedalus_ui/js/broadcast/channel.js';
import { generateUuid } from '../../../../daedalus_ui/js/edit_mode/utilities/uuid.js';
import { createRoster } from '../../../../daedalus_ui/js/edit_mode/materials/roster.js';
import {
  applyDelta,
  assertDelta,
  emptyRecords,
  flattenRecords,
  isEmptyDelta,
  recordsFromPayload,
} from '../../../../daedalus_ui/js/edit_mode/materials/delta.js';
import { createEnvelopeSync } from '../../../../daedalus_ui/js/edit_mode/utilities/settings-envelope.js';
import { failureText } from '../gesture-transport.js';
import { notify } from '../notify.js';

/**
 * The satellite state source: the inspector fed over the BroadcastChannel.
 *
 * Implements the same interface as the leader source, plus connection
 * awareness (the satellite alone can be orphaned — its host page IS the
 * connection for the in-page inspector). State is a local cache of the
 * leader's last broadcast; reads are synchronous against it, and intents
 * (once widgets exist) post back for the leader to apply. The leader
 * pre-digests everything session-shaped (selection, style map, root uuid,
 * the component → block type table) so the satellite needs no blueprint of
 * its own; the style guide payload and the materials roster envelope are
 * what it reads from its own page (drupalSettings), because those are site
 * truth, not session state.
 *
 * Materials: this window has no app bundle and so no store; it holds its
 * own per-kind record set, booted from drupalSettings.daedalusUi
 * .materials (the same MaterialsDelivery envelope the editing page boots
 * from, attached by InspectorPopout) and seated by the same rule
 * (daedalus_ui materials/delta.js), and it listens on the materials
 * wire (`materials:<scope>`, materials-store.js's protocol) for the deltas
 * the leader relays. A gesture this window runs itself (a mint, a swatch
 * edit) applies its response's delta here and posts it on the same wire,
 * so the leader's store learns it; the wire never echoes to the poster, so
 * nothing rings.
 */
export const createSatelliteSource = (scope) => {
  let state = {
    selected: null,
    selectedPart: null,
    selectionTags: null,
    styleMap: {},
    rootUuid: null,
    blockTypes: {},
    sectionLayouts: {},
    layout: [],
    layoutContext: null,
    mediaUrls: {},
    mediaLibraryAvailable: false,
    canvasVector: null,
    forceState: null,
    place: { place: 'styles', editing: null },
  };
  let connected = false;
  let canvasHighlight = null;
  let canvasPaint = null;
  // The shared selection hover, from the leader's slim hover broadcasts.
  // Deliberately outside `state`: transient pointer-speed chrome that
  // never rides the snapshot, so it starts (and stays) null until a
  // crossing is heard.
  let hovered = null;
  // The part-hover facet, hovered's twin: transient {key, part} chrome
  // outside `state` for the same reason.
  let hoveredPart = null;
  const hoverListeners = new Set();
  const hoverPartListeners = new Set();
  const selectionListeners = new Set();
  const styleMapListeners = new Set();
  const connectionListeners = new Set();
  const partListeners = new Set();
  const canvasHighlightListeners = new Set();
  const canvasPaintListeners = new Set();
  const canvasVectorListeners = new Set();
  const forceStateListeners = new Set();
  const placeListeners = new Set();
  const materialsListeners = new Set();
  // Op intents awaiting the leader's settlement, by request id (settle
  // below; the leader answers each with a `settled` frame).
  const pending = new Map();

  // The materials record set and its roster index (rebuilt on change, so
  // its identity is the change signal the inspector subscribes to).
  let records = emptyRecords();
  let materials = createRoster([]);
  const rebuildMaterials = () => {
    materials = createRoster(flattenRecords(records));
    materialsListeners.forEach((callback) => callback(materials));
  };
  const syncMaterials = createEnvelopeSync(
    () => globalThis.drupalSettings?.daedalusUi?.materials,
    (envelope) => {
      records = recordsFromPayload(envelope);
      rebuildMaterials();
    },
  );
  syncMaterials();
  const materialsWire = openChannel('materials', scope, {
    delta: (delta) => {
      assertDelta(delta);
      if (!isEmptyDelta(delta)) {
        applyDelta(records, delta);
        rebuildMaterials();
      }
    },
  });

  const setConnected = (value) => {
    if (connected !== value) {
      connected = value;
      connectionListeners.forEach((callback) => callback(connected));
    }
  };

  const receiveState = (next) => {
    setConnected(true);
    const previous = state;
    state = { ...previous, ...next };
    if (state.selected !== previous.selected) {
      selectionListeners.forEach((callback) =>
        callback(state.selected, previous.selected),
      );
    }
    // Broadcast payloads are structured clones, so a fresh reference on
    // every message: announce unless the broadcast omitted the map.
    if (state.styleMap !== previous.styleMap) {
      styleMapListeners.forEach((callback) => callback(state.styleMap));
    }
    // The part-aware selection pair, the facade's onPartChange shape:
    // fires on a key OR facet change, with (current, previous) pairs.
    if (
      state.selected !== previous.selected ||
      (state.selectedPart ?? null) !== (previous.selectedPart ?? null)
    ) {
      partListeners.forEach((callback) =>
        callback(
          { key: state.selected, part: state.selectedPart ?? null },
          { key: previous.selected, part: previous.selectedPart ?? null },
        ),
      );
    }
    // The vector is an object, so a broadcast that carries it always
    // arrives as a fresh structured clone — announce on reference change,
    // like the style map.
    if (state.canvasVector !== previous.canvasVector) {
      canvasVectorListeners.forEach((callback) => callback(state.canvasVector));
    }
    if (state.forceState !== previous.forceState) {
      forceStateListeners.forEach((callback) => callback(state.forceState));
    }
    if (
      state.place?.place !== previous.place?.place ||
      state.place?.editing !== previous.place?.editing
    ) {
      placeListeners.forEach((callback) => callback({ ...state.place }));
    }
  };

  const channel = openChannel('daedalus_stylish', scope, {
    snapshot: ({ state: next }) => receiveState(next),
    state: ({ state: next }) => receiveState(next),
    hover: ({ key }) => {
      const next = key ?? null;
      if (hovered !== next) {
        hovered = next;
        hoverListeners.forEach((callback) => callback(hovered));
      }
    },
    'hover-part': ({ key, part }) => {
      const next = key && part ? { key, part } : null;
      if (hoveredPart?.key !== next?.key || hoveredPart?.part !== next?.part) {
        hoveredPart = next;
        hoverPartListeners.forEach((callback) => callback(hoveredPart));
      }
    },
    // The leader's answer to an op intent (settle below): the op queue's
    // own settlement, so a satellite gesture that continues with the
    // result (a mint's record, a save's re-attach) reads exactly what the
    // leader read, and a failure rejects with the op queue's Error shape.
    settled: ({ request, ok, payload, error }) => {
      const waiting = pending.get(request);
      if (!waiting) {
        return;
      }
      pending.delete(request);
      if (ok) {
        waiting.resolve(payload);
        return;
      }
      const failure = new Error(error?.message ?? 'The mutation failed.');
      failure.payload = error?.payload ?? null;
      waiting.reject(failure);
    },
    // A rebooted leader asks satellites to re-introduce themselves — that
    // hello is what flips the new leader's panel to its popped-out state.
    'leader-ready': () => channel.post('hello'),
    'leader-gone': () => setConnected(false),
    close: () => window.close(),
  });

  channel.post('hello');
  window.addEventListener('pagehide', () => channel.post('bye'));

  /**
   * Posts an op intent and returns its settlement.
   *
   * The leader owns the op queue, the staged preview release and the
   * failure flash on its canvas; it answers every op intent (commit,
   * commit-batch, apply-variant, detach-variant) with a `settled` frame
   * carrying the op queue's own result, so the promise settles exactly
   * like the leader source's: the applied response payload on success (a
   * material op's caller reads its record from `materials.upsert`, the
   * same as on the leader), an Error carrying `.payload` on failure.
   *
   * This window reports its own fates: a rejection toasts in THIS
   * window's messenger (the hand is here; the leader toasts on its page
   * beside the flash, so the history honestly holds both reports), and
   * the promise still rejects so the caller can mark its input. A leader
   * that never answers (closed mid-flight) leaves the promise pending;
   * the satellite shows disconnected and corrects on the next pairing.
   *
   * @param {string} intent
   *   The intent name.
   * @param {object} payload
   *   The intent's fields (never a `type` key: the channel envelope
   *   reserves it for the frame name).
   *
   * @return {Promise<object>}
   */
  const settle = (intent, payload) => {
    const request = generateUuid();
    const settlement = new Promise((resolve, reject) => {
      pending.set(request, { resolve, reject });
    });
    channel.post('intent', { intent, ...payload, request });
    settlement.catch((error) => notify('error', failureText(error)));
    return settlement;
  };

  return {
    role: 'satellite',

    getSelected: () => state.selected,

    /**
     * The committed selection's part facet from the leader's last
     * broadcast, or null (the whole element). The facet rides the
     * selection channel: it is spine state on the leader, never a
     * satellite-local drill.
     */
    getSelectedPart: () => state.selectedPart ?? null,

    /**
     * Sends a select intent (the page tree's row click, the part
     * breadcrumb clear); the leader drives the canvas selection, part
     * facet included, and the change echoes back on the next state
     * broadcast. The leader stays the one authority on selection.
     */
    select: (key, part = null) => {
      channel.post('intent', { intent: 'select', key, part });
    },

    /**
     * Subscribes to selection KEY changes; returns unsubscribe.
     */
    onSelectionChange: (callback) => {
      selectionListeners.add(callback);
      return () => selectionListeners.delete(callback);
    },

    /**
     * Subscribes to the committed (key, part) pair, the part-aware
     * counterpart of onSelectionChange; returns unsubscribe.
     */
    onPartChange: (callback) => {
      partListeners.add(callback);
      return () => partListeners.delete(callback);
    },

    /**
     * The shared selection hover from the leader's last hover broadcast.
     */
    getHovered: () => hovered,

    /**
     * Sends a hover intent (a code tree row's mouseenter/leave); the
     * leader drives the shared hover on its page — canvas outline and
     * page-elements row light up over there. Null clears.
     *
     * Locally echoed (setCanvasHighlight's pattern) so this window's own
     * row highlight is instant and the rows' leave guard reads exactly;
     * the leader's hover broadcast then confirms as a no-op.
     */
    hover: (key) => {
      const next = key ?? null;
      if (hovered !== next) {
        hovered = next;
        hoverListeners.forEach((callback) => callback(hovered));
      }
      channel.post('intent', { intent: 'hover', key: next });
    },

    /**
     * Subscribes to hover changes; returns unsubscribe.
     */
    onHoverChange: (callback) => {
      hoverListeners.add(callback);
      return () => hoverListeners.delete(callback);
    },

    /**
     * The part-hover facet from the leader's last hover-part broadcast.
     */
    getHoveredPart: () => hoveredPart,

    /**
     * Sends a hover-part intent (a code part header's enter/leave); the
     * leader drives the spine's hoverPart facet — canvas dashed paint and
     * tree part-row glow light up over there. hoverPart(null) clears.
     *
     * Locally echoed like hover(), so this window's own hot mark is
     * instant and the headers' leave guard reads exactly; the leader's
     * hover-part broadcast then confirms as a no-op.
     */
    hoverPart: (key, part = null) => {
      const next = key && part ? { key, part } : null;
      if (hoveredPart?.key !== next?.key || hoveredPart?.part !== next?.part) {
        hoveredPart = next;
        hoverPartListeners.forEach((callback) => callback(hoveredPart));
      }
      channel.post('intent', {
        intent: 'hover-part',
        key: next?.key ?? null,
        part: next?.part ?? null,
      });
    },

    /**
     * Subscribes to part-hover facet changes; returns unsubscribe.
     */
    onHoverPartChange: (callback) => {
      hoverPartListeners.add(callback);
      return () => hoverPartListeners.delete(callback);
    },

    /**
     * The root entity's style map from the leader's last broadcast, or {}.
     */
    getStyleMap: () => state.styleMap ?? {},

    /**
     * Subscribes to style map changes; returns unsubscribe.
     */
    onStyleMapChange: (callback) => {
      styleMapListeners.add(callback);
      return () => styleMapListeners.delete(callback);
    },

    /**
     * The root entity's uuid, or null before a leader has been heard from.
     */
    getRootUuid: () => state.rootUuid ?? null,

    /**
     * Component uuid → inline block type, from the leader's last broadcast.
     */
    getBlockTypes: () => state.blockTypes ?? {},

    /**
     * Section uuid → layout plugin id, from the leader's last broadcast.
     */
    getSectionLayouts: () => state.sectionLayouts ?? {},

    /**
     * The staged layout's structural tree, from the leader's last
     * broadcast — what composition capture walks. Empty until a broadcast
     * arrives: capture degrades to single-element entries rather than
     * guess.
     */
    getLayout: () => state.layout ?? [],

    /**
     * Mints a v4 uuid for client-built structural ops. The satellite has
     * no app, so it uses daedalus_ui's shared generator directly —
     * the same code the leader's app mint wraps, so both windows mint
     * identically-shaped uuids the batch grammar honors.
     */
    generateUuid,

    /**
     * The media uuid → URL table from the leader's last broadcast.
     *
     * A snapshot copy, not the leader's live object (broadcast payloads are
     * structured clones): good enough for the media row's thumbnail, and a
     * pick refreshes it with the style map when the committed op's state
     * broadcast lands. This window renders no preview tag, so the live-read
     * requirement the leader has does not apply here.
     */
    getMediaUrls: () => state.mediaUrls ?? {},

    /**
     * Whether the LEADER can open the media library, from its broadcast.
     *
     * The satellite's own settings never carry the library URL (and this
     * window has no dialog stack anyway); the button is honest chrome for
     * "a pick intent will actually open something over there".
     */
    canPickMedia: () => state.mediaLibraryAvailable ?? false,

    /**
     * Sends a pick-media intent; the leader opens the modal on ITS page and
     * lands the whole delivery (table extension, staged preview, commit).
     *
     * Fire-and-forget like commit(): the committed token and the extended
     * URL table arrive on the next state broadcast.
     *
     * The `companions` map rides the intent verbatim: a satellite pick
     * carries its own computed seed (the widget computes it against this
     * window's guide and broadcast state, the base:{tag} layer's tag
     * from the relayed selection tags), and the leader merges it into
     * the one commit exactly as it would its own.
     *
     * @param {object} target
     *   {key, condition, property, label, companions}; same shape as the
     *   leader's.
     *
     * @return {boolean}
     *   Whether the intent was sent (mirrors the leader's opened boolean).
     */
    pickMedia: ({
      key,
      condition = 'default',
      property,
      label = null,
      companions = null,
    }) => {
      if (!(state.mediaLibraryAvailable ?? false)) {
        return false;
      }
      channel.post('intent', {
        intent: 'pick-media',
        key,
        condition,
        property,
        label,
        companions,
      });
      return true;
    },

    /**
     * The materials roster: this window's own index over the boot envelope
     * and every delta heard on the materials wire.
     */
    getMaterials: () => materials,

    /**
     * Subscribes to roster changes; returns unsubscribe.
     */
    onMaterialsChange: (callback) => {
      materialsListeners.add(callback);
      return () => materialsListeners.delete(callback);
    },

    /**
     * Lands a gesture route's response run from THIS window.
     *
     * The gesture (a mint, a swatch edit, a delete) already ran over this
     * window's own session; its response's `materials` delta applies to
     * this window's record set and posts on the materials wire, where the
     * leader's store (and any other paired window) applies it. The leader
     * then re-stamps the canvas custom properties from its store, so the
     * canvas over there follows too. An empty delta (a response that
     * changed no material) applies and posts nothing.
     */
    applyResponse: (payload) => {
      const delta = payload?.materials;
      if (!delta) {
        return;
      }
      assertDelta(delta);
      if (isEmptyDelta(delta)) {
        return;
      }
      applyDelta(records, delta);
      rebuildMaterials();
      materialsWire.post('delta', {
        upsert: delta.upsert,
        remove: delta.remove,
      });
    },

    /**
     * Relays a live swatch-value preview to the leader page (the canvas
     * lives there); null releases it. The record travels by id: the
     * leader resolves it against its own store.
     */
    previewSwatch: (record, value) => {
      if (record) {
        channel.post('intent', {
          intent: 'swatch-preview',
          id: record.id,
          value,
        });
      }
    },

    /**
     * Relays a keyframes session's draft steps to the leader page (the
     * canvas and its preview sheet live there); null releases them. The
     * record travels by id: the leader resolves it against its own store.
     */
    previewAnimation: (record, steps) => {
      if (record) {
        channel.post('intent', {
          intent: 'animation-preview',
          id: record.id,
          steps: steps ?? null,
        });
      }
    },

    /**
     * Reports a gesture outcome through THIS window's messenger, the page
     * builder's one notification spine (js/src/notify.js).
     *
     * The satellite hosts the messenger itself (daedalus_stylish/satellite depends
     * on daedalus_ui/messenger): a gesture run here (a mint fetches
     * directly, then seams its delivery to the leader) toasts where the
     * hand is and lands in the one shared history every window reads.
     * Never load-bearing: a document without the messenger drops it.
     */
    notify,

    /**
     * Sends an apply_variant intent; the leader runs it through its queue
     * and answers with the op's settlement (settle above): the promise
     * rejects on failure, and the failure toasts here.
     */
    applyVariant: (key, variant, label = null) =>
      settle('apply-variant', { key, variant, label }),

    /**
     * Sends a detach_variant intent; the leader runs it through its queue
     * and answers with the op's settlement (settle above), applyVariant's
     * contract.
     */
    detachVariant: (key, variant, label = null) =>
      settle('detach-variant', { key, variant, label }),

    /**
     * The selection's layout context, from the leader's last broadcast.
     *
     * Computed style lives in the canvas document, which this window does
     * not have; the leader reads it there and ships the digest with every
     * state broadcast (the same freshness contract as the style map). Null
     * until a broadcast arrives: adaptive widgets degrade to their
     * context-free rendering rather than guess.
     */
    getLayoutContext: () => state.layoutContext ?? null,

    /**
     * The markup annotation: none here.
     *
     * The blueprint mirror lives in the editing window; instance-part
     * labels degrade to their covering declaration's label (an owner
     * pattern part's, else the site instance namespace's — partLabel's
     * fallback), the same honesty rule as every other leader-only read.
     */
    getMarkup: () => null,

    /**
     * The canvas placeholder read: none here.
     *
     * Computed style lives in the canvas document, which this window does
     * not have, and the read is per-render (unlike the layout-context
     * digest it is not broadcast). Widgets degrade to placeholder-less
     * rendering, the same honesty rule as a missing canvas element on the
     * leader.
     */
    readCanvas: () => null,

    /**
     * The selection's rendered tag, from the leader's last broadcast.
     *
     * No canvas here, but the tag is selection state the leader relays
     * beside the (key, part) pair (`selectionTags`: the element's tag and
     * the drilled part's), so the base:{tag} tier (skin defaults
     * provenance, base variant offers) reads the same tag over here as
     * on the leader. Only the broadcast selection is answerable: another
     * key, or a part other than the selected one, is honestly null (the
     * never-over-claim posture of every other leader-only read).
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} sub
     *   A declared part selector, or null for the element.
     *
     * @return {?string}
     */
    readCanvasTag: (key, sub = null) => {
      if (!key || key !== state.selected) {
        return null;
      }
      const tags = state.selectionTags ?? {};
      if (sub === null || sub === undefined) {
        return tags.element ?? null;
      }
      return sub === (state.selectedPart ?? null) ? (tags.part ?? null) : null;
    },

    /**
     * The canvas state vector from the leader's last broadcast, or null.
     */
    getCanvasVector: () => state.canvasVector ?? null,

    /**
     * Sends a canvas-vector intent (drive the canvas there); the leader
     * poses its vector and the change echoes back on the next broadcast.
     */
    setCanvasVector: (partial) => {
      channel.post('intent', { intent: 'canvas-vector', partial });
    },

    /**
     * Subscribes to canvas vector changes; returns unsubscribe.
     */
    onCanvasVectorChange: (callback) => {
      canvasVectorListeners.add(callback);
      return () => canvasVectorListeners.delete(callback);
    },

    /**
     * Sends a motion-replay intent; the leader re-arms the target's
     * trigger on its canvas (the satellite has none), and the entrance
     * plays over there.
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} sub
     *   A declared part selector ('.quote'), or null for the element.
     *
     * @return {boolean}
     *   Whether the intent was sent (the leader's canvas answers whether
     *   anything replayed; no echo is needed here).
     */
    replayMotion: (key, sub = null) => {
      channel.post('intent', { intent: 'motion-replay', key, sub });
      return true;
    },

    /**
     * The selection-scoped force state from the leader's last broadcast.
     */
    getForceState: () => state.forceState ?? null,

    /**
     * Sends a force-state intent; the leader stamps its canvas (the
     * satellite has none) and the state echoes back on the next broadcast.
     */
    setForceState: (forceState) => {
      channel.post('intent', {
        intent: 'force-state',
        state: forceState || null,
      });
    },

    /**
     * Subscribes to force-state changes; returns unsubscribe.
     */
    onForceStateChange: (callback) => {
      forceStateListeners.add(callback);
      return () => forceStateListeners.delete(callback);
    },

    /**
     * The place state from the leader's last broadcast:
     * {place, editing}, a fresh copy per read.
     */
    getPlace: () => ({
      place: 'styles',
      editing: null,
      ...(state.place ?? {}),
    }),

    /**
     * Sends a place intent; the leader holds the one place state and
     * the new value echoes back on the next state broadcast, so both
     * panels always look at the same thing.
     */
    setPlace: (next) => {
      channel.post('intent', {
        intent: 'place',
        state: { place: next?.place, editing: next?.editing ?? null },
      });
    },

    /**
     * Subscribes to place changes; returns unsubscribe.
     */
    onPlaceChange: (callback) => {
      placeListeners.add(callback);
      return () => placeListeners.delete(callback);
    },

    /**
     * The last canvas highlight this satellite requested.
     *
     * Local echo only: the highlight renders as canvas chrome on the
     * leader page (this window has no canvas), and hover signals are
     * transient — round-tripping them through a state broadcast would add
     * latency for nothing. The leader stays authoritative about what
     * actually shows (it clears on deselection).
     */
    getCanvasHighlight: () => canvasHighlight,

    /**
     * Sends a canvas-highlight intent; the leader's bands render it.
     *
     * Same spec as the leader source: null, or {key, sub, properties}.
     */
    setCanvasHighlight: (spec) => {
      canvasHighlight = spec ?? null;
      channel.post('intent', {
        intent: 'canvas-highlight',
        spec: canvasHighlight,
      });
      canvasHighlightListeners.forEach((callback) => callback(canvasHighlight));
    },

    /**
     * Subscribes to this window's highlight requests; returns unsubscribe.
     */
    onCanvasHighlightChange: (callback) => {
      canvasHighlightListeners.add(callback);
      return () => canvasHighlightListeners.delete(callback);
    },

    /**
     * The last canvas paint this satellite requested.
     *
     * Local echo only, the highlight's posture: the paint renders on the
     * leader page's canvas; this window just remembers what it asked for.
     */
    getCanvasPaint: () => canvasPaint,

    /**
     * Sends a canvas-paint intent; the leader's canvas paints it.
     *
     * Same spec as the leader source: null, or {layers}.
     */
    setCanvasPaint: (paint) => {
      canvasPaint = paint ?? null;
      channel.post('intent', { intent: 'canvas-paint', paint: canvasPaint });
      canvasPaintListeners.forEach((callback) => callback(canvasPaint));
    },

    /**
     * Subscribes to this window's paint requests; returns unsubscribe.
     */
    onCanvasPaintChange: (callback) => {
      canvasPaintListeners.add(callback);
      return () => canvasPaintListeners.delete(callback);
    },

    /**
     * Sends a live-preview intent to the leader (its page shows the CSS).
     */
    preview: (key, condition, property, value) => {
      channel.post('intent', {
        intent: 'preview',
        key,
        condition,
        property,
        value,
      });
    },

    /**
     * Asks the leader to release staged preview values (edit cancelled).
     */
    previewRelease: (key, condition, properties) => {
      channel.post('intent', { intent: 'release', key, condition, properties });
    },

    /**
     * Sends whole-entry preview intents (the code editor's overlay).
     */
    previewEntries: (entriesByKey) => {
      channel.post('intent', {
        intent: 'preview-entries',
        entries: entriesByKey,
      });
    },

    /**
     * Asks the leader to release staged entry replacements.
     */
    previewEntriesRelease: (keys) => {
      channel.post('intent', { intent: 'release-entries', keys });
    },

    /**
     * Runs a batch commit through the leader (the code editor's settle,
     * the material gesture ops); the leader releases the staged entry
     * previews and answers with the batch's settlement (settle above).
     */
    commitBatch: (batch) => settle('commit-batch', { batch }),

    /**
     * Sends a commit intent; the leader runs the update_style op through
     * its queue, releases the staged preview, and answers with the op's
     * settlement (settle above): the promise rejects on failure so the
     * widget can mark its input, and the failure toasts here.
     */
    commit: (change) => settle('commit', { change }),

    /**
     * Whether a leader has been heard from yet.
     */
    isConnected: () => connected,

    /**
     * Subscribes to connection changes; returns unsubscribe.
     */
    onConnectionChange: (callback) => {
      connectionListeners.add(callback);
      return () => connectionListeners.delete(callback);
    },
  };
};
