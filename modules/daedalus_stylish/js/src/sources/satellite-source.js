import { openChannel } from '../../../../daedalus_ui/js/broadcast/channel.js';
import { openPicker as openVariantPicker } from '../apply-variant-hotkey.js';
import { runCollapseGesture } from '../code/collapse-seam.js';
import { runDoor } from '../inspector-doors.js';
import { generateUuid } from '../../../../daedalus_ui/js/edit_mode/utilities/uuid.js';
import { createRoster } from '../../../../daedalus_ui/js/edit_mode/materials/roster.js';
import {
  capableInTree,
  verdictInTree,
} from '../../../../daedalus_ui/js/edit_mode/selection/capable-model.js';
import { layoutOf } from '../../../../daedalus_ui/js/edit_mode/blueprint/document-tree.js';
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
 * the leader relays. A gesture this window runs itself (a mint, a token
 * edit) applies its response's delta here and posts it on the same wire,
 * so the leader's store learns it; the wire never echoes to the poster, so
 * nothing rings.
 */

/**
 * Creates the satellite source for one pop-out session.
 *
 * @param {string} scope
 *   The session scope (the leader's `?scope`, delivered as
 *   daedalusUi.popout.scope): the suffix every broadcast channel of the
 *   pairing carries.
 *
 * @return {object}
 *   The state source the inspector renders against, the leader source's
 *   interface plus isConnected / onConnectionChange.
 */
export const createSatelliteSource = (scope) => {
  let state = {
    selected: null,
    target: null,
    address: null,
    styleMap: {},
    rootUuid: null,
    // The root's verdict (`_element.can`), null until the leader's first
    // broadcast: the gate's answer for the root and the host's own
    // fields, which the layout the gate walks does not hold.
    rootCan: null,
    blockTypes: {},
    sectionLayouts: {},
    // The document tree (the host entity above the blueprint's layout),
    // the one structure the leader broadcasts. Unknown until the first
    // broadcast (null, never an empty document): the readers that gate
    // on tree facts degrade to offering, and the structural walks
    // (capture, depth, provenance, the page tree) read it as empty.
    document: null,
    // The canvas's self-provenance (a blueprint canvas's machine name),
    // null until the leader's first broadcast and on every page canvas:
    // the provenance read then falls back to nothing, never a guess.
    selfProvenance: null,
    layoutContext: null,
    mediaUrls: {},
    mediaLibraryAvailable: false,
    canvasVector: null,
    forceState: null,
    place: { place: 'styles', editing: null },
    // The leader's delivered skin defaults (null until a push refreshed
    // them; the popout page's own guide payload tells the truth till
    // then), so this panel's stacks read the same skin the leader's do.
    skinDefaults: null,
  };
  let connected = false;
  let canvasHighlight = null;
  let canvasPaint = null;
  // The shared selection hover, from the leader's slim hover broadcasts.
  // Deliberately outside `state`: transient pointer-speed chrome that
  // never rides the snapshot, so it starts (and stays) null until a
  // crossing is heard.
  let hovered = null;
  // The selector-hover facet, hovered's twin: transient {key, selector}
  // chrome outside `state` for the same reason.
  let hoveredSelector = null;
  const hoverListeners = new Set();
  const hoverSelectorListeners = new Set();
  const selectionListeners = new Set();
  const styleMapListeners = new Set();
  const connectionListeners = new Set();
  const targetListeners = new Set();
  const canvasHighlightListeners = new Set();
  const canvasPaintListeners = new Set();
  const canvasVectorListeners = new Set();
  const forceStateListeners = new Set();
  const placeListeners = new Set();
  const skinDefaultsListeners = new Set();
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
    // The selection and its two styling facts, the facade's
    // onTargetChange shape: fires on a selection, facet OR address
    // change (each pair value-compared), with ({selected, target,
    // address}, previous).
    const target = state.target ?? null;
    const address = state.address ?? null;
    const previousTarget = previous.target ?? null;
    const previousAddress = previous.address ?? null;
    const moved = (a, b) => a?.key !== b?.key || a?.selector !== b?.selector;
    if (
      state.selected !== previous.selected ||
      moved(target, previousTarget) ||
      moved(address, previousAddress)
    ) {
      targetListeners.forEach((callback) =>
        callback(
          { selected: state.selected, target, address },
          {
            selected: previous.selected,
            target: previousTarget,
            address: previousAddress,
          },
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
    // A structured clone per broadcast: announce on content, not
    // reference, so a selection broadcast never re-renders the panel's
    // guide.
    if (
      JSON.stringify(state.skinDefaults ?? null) !==
      JSON.stringify(previous.skinDefaults ?? null)
    ) {
      skinDefaultsListeners.forEach((callback) =>
        callback(state.skinDefaults ?? null),
      );
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
    'hover-selector': ({ key, selector }) => {
      const next = key && selector ? { key, selector } : null;
      if (
        hoveredSelector?.key !== next?.key ||
        hoveredSelector?.selector !== next?.selector
      ) {
        hoveredSelector = next;
        hoverSelectorListeners.forEach((callback) => callback(hoveredSelector));
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
    // The apply-variant hotkey resolved on the leader while the panel
    // lives here (the press itself may have come from this window, over
    // daedalus_ui's pop-out keyboard wire): open the picker through the
    // opener seam the wear bar mounted in this document fills. Its
    // verdict stays here; the leader already counted the press as
    // handled when it routed it.
    'open-variant-picker': () => openVariantPicker(),
    // A collapse-all or expand-all hotkey resolved on the leader while
    // the panel lives here: run the gesture over THIS window's outline
    // posture through the seam the mounted page view fills (a collapse
    // is a reading posture of one window; the leader's outline is
    // displaced). Nothing to do when code mode is not showing here.
    'collapse-outline': ({ gesture }) => runCollapseGesture(gesture),
    // A page elements panel doorway ("Edit {variant}", "Outline everything
    // wearing it") run on the leader while the panel lives here: the
    // editing session and the Outline Variant toggle are THIS window's
    // inspector's, so the door runs through the seam it fills
    // (inspector-doors.js). Nothing to do when no inspector is mounted.
    doorway: (payload) => runDoor(payload),
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
   *   The op's settlement: the applied response payload, or a rejection
   *   with the op queue's Error.
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
     * The styling SUBJECT facet from the leader's last broadcast ({key,
     * selector}), or null (the whole element). The facet rides the
     * selection channel: it is spine state on the leader, never a
     * satellite-local drill.
     */
    getTarget: () => state.target ?? null,

    /**
     * The styling WRITE ADDRESS from the leader's last broadcast ({key,
     * selector}), or null (the subject's own key). Spine state on the
     * leader, relayed beside the facet.
     */
    getAddress: () => state.address ?? null,

    /**
     * Sends a select intent (the page tree's row click, the subject
     * chip's way back); the leader drives the canvas selection (which
     * drops any facet and address), and the change echoes back on the
     * next state broadcast. The leader stays the one authority on
     * selection.
     *
     * @param {string} key
     *   The selectable key to select.
     */
    select: (key) => {
      channel.post('intent', { intent: 'select', key });
    },

    /**
     * Sends a target intent (a code-mode row header seating a chain, a
     * tree part row, the chip's box doorway: a SUBJECT facet on the
     * selected key); the leader's spine seats it (its canvas paints the
     * matches) and the change echoes back on the next state broadcast.
     *
     * @param {string} key
     *   The selected element's selectable key.
     * @param {?string} selector
     *   The relative selector to seat, or null for the whole element.
     */
    target: (key, selector) => {
      channel.post('intent', { intent: 'target', key, selector });
    },

    /**
     * Sends an address intent (the address switcher, "Edit at", a
     * code-mode rule header: the WRITE ADDRESS under the subject; null
     * drops it); the leader's spine seats it (its canvas paints the
     * rule's matches dotted) and the change echoes back on the next
     * state broadcast.
     *
     * @param {?string} key
     *   The selectable key the rule hangs on (the selection or an
     *   ancestor), or null to drop the address.
     * @param {?string} selector
     *   The rule's relative selector, or null for the key's own entry.
     */
    address: (key, selector = null) => {
      channel.post('intent', { intent: 'address', key, selector });
    },

    /**
     * Subscribes to selection KEY changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with (selectedKey, previousKey); either may be null.
     */
    onSelectionChange: (callback) => {
      selectionListeners.add(callback);
      return () => selectionListeners.delete(callback);
    },

    /**
     * Subscribes to the selection and its styling target, the
     * target-aware counterpart of onSelectionChange; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with ({selected, target, address}, previous), the current
     *   facts then the previous ones.
     */
    onTargetChange: (callback) => {
      targetListeners.add(callback);
      return () => targetListeners.delete(callback);
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
     *
     * @param {?string} key
     *   The selectable key to hover, or null to clear.
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
     *
     * @param {Function} callback
     *   Called with the hovered key, or null.
     */
    onHoverChange: (callback) => {
      hoverListeners.add(callback);
      return () => hoverListeners.delete(callback);
    },

    /**
     * The selector-hover facet from the leader's last hover-selector
     * broadcast.
     */
    getHoveredSelector: () => hoveredSelector,

    /**
     * Sends a hover-selector intent (a code part header's enter/leave);
     * the leader drives the spine's hoverSelector facet — canvas dashed
     * paint and tree part-row glow light up over there.
     * hoverSelector(null) clears.
     *
     * Locally echoed like hover(), so this window's own hot mark is
     * instant and the headers' leave guard reads exactly; the leader's
     * hover-selector broadcast then confirms as a no-op.
     *
     * @param {?string} key
     *   The part's owner key, or null to clear.
     * @param {?string} selector
     *   The part's relative selector; null clears.
     */
    hoverSelector: (key, selector = null) => {
      const next = key && selector ? { key, selector } : null;
      if (
        hoveredSelector?.key !== next?.key ||
        hoveredSelector?.selector !== next?.selector
      ) {
        hoveredSelector = next;
        hoverSelectorListeners.forEach((callback) => callback(hoveredSelector));
      }
      channel.post('intent', {
        intent: 'hover-selector',
        key: next?.key ?? null,
        selector: next?.selector ?? null,
      });
    },

    /**
     * Subscribes to selector-hover facet changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the hovered facet ({key, selector}), or null.
     */
    onHoverSelectorChange: (callback) => {
      hoverSelectorListeners.add(callback);
      return () => hoverSelectorListeners.delete(callback);
    },

    /**
     * The root entity's style map from the leader's last broadcast, or {}.
     */
    getStyleMap: () => state.styleMap ?? {},

    /**
     * Subscribes to style map changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the new style map.
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
     * THE capability gate over the leader's broadcast (daedalus_ui
     * selection/capable-model.js): a layout element answers from its
     * node's `_element.can` in the layout read out of the document; the
     * root and anything the layout does not hold (the host's own fields,
     * an inline entity under them) answer from the root's verdict,
     * exactly as the leader's projection index lets such rows inherit
     * their element's. Null for everything until the leader has been
     * heard from.
     *
     * @param {string} key
     *   The selectable key.
     * @param {string} capability
     *   The capability name ('style', 'move', ...).
     */
    capable: (key, capability) => {
      const uuid = key?.split('::')[0]?.split(':')[1] ?? null;
      if (!uuid) {
        return false;
      }
      const layout = layoutOf(state.document);
      if (uuid !== state.rootUuid && verdictInTree(layout, uuid)) {
        return capableInTree(layout, uuid, capability);
      }
      return state.rootCan?.[capability] === true;
    },

    /**
     * Component uuid → inline block type, from the leader's last broadcast.
     */
    getBlockTypes: () => state.blockTypes ?? {},

    /**
     * Section uuid → layout plugin id, from the leader's last broadcast.
     */
    getSectionLayouts: () => state.sectionLayouts ?? {},

    /**
     * The blueprint's lean node tree, read back out of the leader's
     * broadcast document (daedalus_ui document-tree.js layoutOf: the
     * entity root's children, the sections at the top), the shape every
     * walker that wants the sections at the top reads (composition
     * capture, the depth and provenance reads, the axis vocabulary's
     * nested-section read). Never a second broadcast field: one
     * structure crosses the wire. Null before the document arrives:
     * capture degrades to single-element entries rather than guess, and
     * the gated readers degrade to offering.
     */
    getTree: () => (state.document ? layoutOf(state.document) : null),

    /**
     * The document tree from the leader's last broadcast (daedalus_ui
     * document-tree.js documentTree: the host entity above the
     * blueprint's layout, keyed by the root's uuid and carrying the
     * root's annotation): the one structure the leader sends, what the
     * selector matcher, the style stack's ancestor tier and the code
     * view's page tree read here exactly as they do on the leader. Null
     * before a leader has been heard from.
     */
    getDocument: () => state.document ?? null,

    /**
     * The canvas's self-provenance, from the leader's last broadcast:
     * the blueprint's machine name when the leader's canvas is a
     * blueprint's own document (its unstamped elements then read as its
     * own placement, variant-scope.js blueprintProvenance), null before
     * the first broadcast and on a page canvas.
     */
    getSelfProvenance: () => state.selfProvenance ?? null,

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
     * The routed `changes` list rides the intent verbatim: a satellite
     * pick is routed in this window (the widget pipeline's route() over
     * the picked property and the seed it computed against this window's
     * guide and broadcast state), with the picked property holding the
     * serializable MEDIA_PICK_PLACEHOLDER, and the leader fills and lands
     * it exactly as it would its own. No function crosses the wire.
     *
     * @param {object} pick
     *   The pick, the leader source's shape.
     * @param {string} pick.condition
     *   The condition name the changes land under; defaults to 'default'.
     * @param {string} pick.property
     *   The picked property, the one holding MEDIA_PICK_PLACEHOLDER.
     * @param {?string} pick.label
     *   The undo label for the settle, or null.
     * @param {Array<{key: string, properties: object}>} pick.changes
     *   The routed changes, exactly one carrying the placeholder.
     *
     * @return {boolean}
     *   Whether the intent was sent (mirrors the leader's opened boolean).
     */
    pickMedia: ({ condition = 'default', property, label = null, changes }) => {
      if (!(state.mediaLibraryAvailable ?? false)) {
        return false;
      }
      channel.post('intent', {
        intent: 'pick-media',
        condition,
        property,
        label,
        changes,
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
     *
     * @param {Function} callback
     *   Called with the new roster.
     */
    onMaterialsChange: (callback) => {
      materialsListeners.add(callback);
      return () => materialsListeners.delete(callback);
    },

    /**
     * Lands a gesture route's response run from THIS window.
     *
     * The gesture (a mint, a token edit, a delete) already ran over this
     * window's own session; its response's `materials` delta applies to
     * this window's record set and posts on the materials wire, where the
     * leader's store (and any other paired window) applies it. The leader
     * then re-stamps the canvas custom properties from its store, so the
     * canvas over there follows too. An empty delta (a response that
     * changed no material) applies and posts nothing.
     *
     * @param {object} payload
     *   The route's response: its `materials` delta beside the lane's own
     *   payload.
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
     * Relays a live material-value preview (a token's) to
     * the leader page (the canvas lives there); null releases it. The
     * record travels by kind and id: the leader resolves it against its
     * own store.
     *
     * @param {?object} record
     *   The token record (delivery shape), or null.
     * @param {?(string|object)} value
     *   The draft value in the record's shape, or null to release.
     */
    previewMaterial: (record, value) => {
      if (record) {
        channel.post('intent', {
          intent: 'material-preview',
          kind: record.kind,
          id: record.id,
          value: value ?? null,
        });
      }
    },

    /**
     * Relays a keyframes session's draft steps to the leader page (the
     * canvas and its preview sheet live there); null releases them. The
     * record travels by id: the leader resolves it against its own store.
     *
     * @param {?object} record
     *   The animation record (delivery shape), or null.
     * @param {?object} steps
     *   The draft steps map, or null to release.
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
     *
     * @param {string} key
     *   The style key the reference lands on.
     * @param {string} variant
     *   The variant's machine name.
     * @param {?string} label
     *   The undo label, or null for the server's default.
     */
    applyVariant: (key, variant, label = null) =>
      settle('apply-variant', { key, variant, label }),

    /**
     * Sends a detach_variant intent; the leader runs it through its queue
     * and answers with the op's settlement (settle above), applyVariant's
     * contract.
     *
     * @param {string} key
     *   The style key carrying the reference.
     * @param {string} variant
     *   The variant's machine name.
     * @param {?string} label
     *   The undo label, or null for the server's default.
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
     * The canvas state vector from the leader's last broadcast, or null.
     */
    getCanvasVector: () => state.canvasVector ?? null,

    /**
     * Sends a canvas-vector intent (drive the canvas there); the leader
     * poses its vector and the change echoes back on the next broadcast.
     *
     * @param {object} partial
     *   The axes to pose ({width, scheme, motion}, any subset).
     */
    setCanvasVector: (partial) => {
      channel.post('intent', { intent: 'canvas-vector', partial });
    },

    /**
     * Subscribes to canvas vector changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the new vector, or null.
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
     * @param {?string} selector
     *   A canonical relative selector ('.quote'), or null for the element.
     *
     * @return {boolean}
     *   Whether the intent was sent (the leader's canvas answers whether
     *   anything replayed; no echo is needed here).
     */
    replayMotion: (key, selector = null) => {
      channel.post('intent', { intent: 'motion-replay', key, selector });
      return true;
    },

    /**
     * The selection-scoped force state from the leader's last broadcast.
     */
    getForceState: () => state.forceState ?? null,

    /**
     * Sends a force-state intent; the leader stamps its canvas (the
     * satellite has none) and the state echoes back on the next broadcast.
     *
     * @param {?string} forceState
     *   A state-axis condition name ('hover'), or null to clear.
     */
    setForceState: (forceState) => {
      channel.post('intent', {
        intent: 'force-state',
        state: forceState || null,
      });
    },

    /**
     * Subscribes to force-state changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the new force state (a condition name or null).
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
     *
     * @param {{place: string, editing: ?string}} next
     *   The place to set and the edited document reference, or null.
     */
    setPlace: (next) => {
      channel.post('intent', {
        intent: 'place',
        state: { place: next?.place, editing: next?.editing ?? null },
      });
    },

    /**
     * Subscribes to place changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the new {place, editing}, a fresh copy.
     */
    onPlaceChange: (callback) => {
      placeListeners.add(callback);
      return () => placeListeners.delete(callback);
    },

    /**
     * The leader's delivered skin defaults, or null while the popout
     * page's own guide payload still tells the truth.
     */
    getSkinDefaults: () => state.skinDefaults ?? null,

    /**
     * Subscribes to skin defaults deliveries; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the delivered defaults map, or null.
     */
    onSkinDefaultsChange: (callback) => {
      skinDefaultsListeners.add(callback);
      return () => skinDefaultsListeners.delete(callback);
    },

    /**
     * Relays a skin session's landing to the leader page (the canvas and
     * the one delivered-defaults state live there); the delivered map
     * echoes back on the next state broadcast.
     */
    refreshDefaults: () => settle('refresh-defaults', {}),

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
     * Same spec as the leader source: null, or {key, selector, properties}.
     *
     * @param {?{key: string, selector: ?string, properties: string[]}} spec
     *   The style target and the properties being edited, or null to
     *   clear.
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
     *
     * @param {Function} callback
     *   Called with the requested highlight spec, or null.
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
     *
     * @param {?{layers: Array<{id: string, variant: ?string,
     *   label: ?string, flash: ?boolean}>}} paint
     *   The layers to paint, or null to clear.
     */
    setCanvasPaint: (paint) => {
      canvasPaint = paint ?? null;
      channel.post('intent', { intent: 'canvas-paint', paint: canvasPaint });
      canvasPaintListeners.forEach((callback) => callback(canvasPaint));
    },

    /**
     * Subscribes to this window's paint requests; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the requested paint spec, or null.
     */
    onCanvasPaintChange: (callback) => {
      canvasPaintListeners.add(callback);
      return () => canvasPaintListeners.delete(callback);
    },

    /**
     * Sends a live-preview intent to the leader (its page shows the CSS).
     *
     * @param {string} key
     *   The style key (an element, or a rule under `::`).
     * @param {string} condition
     *   The condition name ('default' for the unconditioned entry).
     * @param {string} property
     *   The CSS property.
     * @param {?string} value
     *   The value to show, or null to preview the property unset.
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
     *
     * @param {string} key
     *   The style key the values were staged under.
     * @param {string} condition
     *   The condition name they were staged under.
     * @param {object} properties
     *   Property → last staged value; only still-matching stages release.
     */
    previewRelease: (key, condition, properties) => {
      channel.post('intent', { intent: 'release', key, condition, properties });
    },

    /**
     * Sends whole-entry preview intents (the code editor's overlay).
     *
     * @param {object} entriesByKey
     *   Selectable key → style entry, or null to preview the key removed.
     */
    previewEntries: (entriesByKey) => {
      channel.post('intent', {
        intent: 'preview-entries',
        entries: entriesByKey,
      });
    },

    /**
     * Asks the leader to release staged entry replacements.
     *
     * @param {string[]} keys
     *   The selectable keys whose staged entries release.
     */
    previewEntriesRelease: (keys) => {
      channel.post('intent', { intent: 'release-entries', keys });
    },

    /**
     * Runs a batch commit through the leader (the code editor's settle,
     * the material gesture ops); the leader releases the staged entry
     * previews and answers with the batch's settlement (settle above).
     *
     * @param {{operations: object[], label: ?string}} batch
     *   The operations and their undo label, the leader source's shape.
     */
    commitBatch: (batch) => settle('commit-batch', { batch }),

    /**
     * Reads a CSS fragment under a style key through the leader's parse
     * door (the code view's paste); the leader fetches the route on its
     * page and answers with the door's result (settle above), a
     * transport failure toasting here.
     *
     * @param {string} key
     *   The style key the text was pasted into (an element or a rule).
     * @param {string} css
     *   The fragment.
     */
    parseCss: (key, css) => settle('parse-css', { key, css }),

    /**
     * Prints one style key's own entries as CSS through the leader (the
     * header's Copy CSS); the leader fetches the read route on its page
     * and answers with the text (settle above), a transport failure
     * toasting here. The clipboard write itself happens in this window,
     * inside its own gesture.
     *
     * @param {string} key
     *   The style key (an element, or a rule).
     */
    readCss: (key) => settle('read-css', { key }),

    /**
     * Sends a commit intent; the leader runs the update_style op through
     * its queue, releases the staged preview, and answers with the op's
     * settlement (settle above): the promise rejects on failure so the
     * widget can mark its input, and the failure toasts here.
     *
     * @param {object} change
     *   {key, condition, properties, label}, the leader source's shape.
     */
    commit: (change) => settle('commit', { change }),

    /**
     * Whether a leader has been heard from yet.
     */
    isConnected: () => connected,

    /**
     * Subscribes to connection changes; returns unsubscribe.
     *
     * @param {Function} callback
     *   Called with the connected boolean.
     */
    onConnectionChange: (callback) => {
      connectionListeners.add(callback);
      return () => connectionListeners.delete(callback);
    },
  };
};
