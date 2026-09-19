import {
  styleMapOf,
  componentBlockTypes,
  sectionLayouts,
  layoutTree,
  tokenDisplayValue,
} from '../style-guide.js';
import { notify } from '../notify.js';
import { customProperty } from '../materials.js';
import { animationPreviewCss } from '../animations-model.js';
import { PLACES } from '../surface-state.js';
import { createPreview } from '../preview.js';
import { readLayoutContext } from '../layout-context.js';
import { partsForKey } from '../parts.js';
import {
  resolveRoot,
  resolveTargets,
  readComputed,
  paddingPx,
  borderPx,
} from '../computed-read.js';
import { openMediaLibrary, mediaLibraryAvailable } from '../media-library.js';
import {
  log,
  logEnabled,
  styleMapDelta,
  variantCarriers,
  carrierDelta,
} from '../log.js';

/**
 * The in-page state source: the inspector fed by the edit-mode app.
 *
 * The inspector component is written against a state-source interface, not
 * against the app, because it renders in two hosts: in the page beside the
 * canvas (this source, wrapping the app facades) and in a popped-out
 * satellite window where no app exists (a source wrapping the
 * BroadcastChannel). Everything the component may need routes through the
 * source so the satellite can proxy it — never reach around the source to
 * app globals from component code.
 *
 * Style values are a projection of the blueprint mirror: the map is
 * the daedalus_stylish_styles field of the root entity's blueprint, and block-type
 * resolution walks the blueprint's layout. Both re-derive when the mirror
 * is replaced (every mutation response replaces it), so the panel's values
 * settle with the same authority the canvas markup does.
 *
 * Writes follow the preview/settle split: preview() is the optimistic half
 * (per keystroke, the page <style> tag only — nothing queued), commit() is
 * the settle half (one update_style op through the app's FIFO op queue).
 * The leader source is the ONLY writer: satellite widgets send intents over
 * the broadcast channel and the pop-out leader routes them here, so one op
 * queue and one preview tag serve both hosts.
 *
 * Being the single writer also makes it the natural instrumentation point:
 * the op dispatch, the mirror's style-map delta, and the reference outcome
 * of an apply or detach all log from here (log.js; switch them on with
 * `Drupal.Daedalus.logging.enable('daedalus_stylish')`).
 */
export const createLeaderSource = (app, guide = {}, mediaUrls = {}) => {
  // The uuid → block type and section uuid → layout id tables are derived
  // from the mirror and cached per mirror instance (the mirror is replaced
  // wholesale, never mutated, so reference identity is the correct
  // invalidation).
  let typesFor = null;
  let types = {};
  let layouts = {};
  let tree = [];
  const deriveTables = () => {
    const blueprint = app.Blueprint.getBlueprint();
    if (blueprint !== typesFor) {
      typesFor = blueprint;
      types = componentBlockTypes(blueprint);
      layouts = sectionLayouts(blueprint);
      tree = layoutTree(blueprint);
    }
  };
  const blockTypes = () => {
    deriveTables();
    return types;
  };
  const sectionLayoutTable = () => {
    deriveTables();
    return layouts;
  };
  const layout = () => {
    deriveTables();
    return tree;
  };
  const preview = createPreview({
    guide,
    getBaseMap: () => styleMapOf(app.Blueprint.getBlueprint()),
    // The media uuid -> URL table (drupalSettings.daedalusStylish.mediaUrls seed):
    // read per render through the live object, so extending it on a pick
    // needs no re-wiring, just a mutation before the pick's stage() call.
    getMediaUrls: () => mediaUrls,
    // The scope-root lookup the inline value delivery writes through: the
    // same [data-selectable] resolution resolveTargets rides, so a staged
    // value lands on exactly the element the compiled @scope rule opens at.
    resolveRoot,
  });
  // Every mutation response replaces the mirror; staged-but-unsettled
  // preview must re-project over the new authoritative base.
  app.onBlueprintChange(() => preview.baseChanged());

  // The swatch delivery extension: the compiled swatch stylesheet this
  // page loaded predates any swatch minted or edited this session, so a
  // committed var(--swatch-{name}) would resolve to nothing until a full
  // reload. Every store change re-stamps every swatch's custom property
  // inline on the token scope element (DefaultsStylesheet::SCOPE_CLASS, the
  // same element the compiled file targets; the values are identical, so
  // the reload hand-off is seamless). Idempotent and cheap (one property
  // per swatch), so it needs no diff; a deleted swatch's property
  // deliberately stays (the delete rewrote every reference to the
  // swatch's literal value server-side, so anything this page still paints
  // through the old var() keeps exactly the look the rewrite gave it).
  // Condition-keyed values stamp their default entry.
  const tokenScope = () => document.querySelector('.daedalus-stylish-token-scope');
  const stampSwatch = (scope, record, value) => {
    const property = customProperty(record);
    if (property !== null && typeof value === 'string' && value !== '') {
      scope.style.setProperty(property, value);
    }
  };
  app.Materials.onChange((roster) => {
    const scope = tokenScope();
    if (scope) {
      roster
        .ofKind('swatch')
        .forEach((record) =>
          stampSwatch(scope, record, tokenDisplayValue(record.value)),
        );
    }
    printAnimations(roster);
  });

  // The animation delivery extension, the swatch extension's twin at
  // at-rule scope: the compiled keyframes file this page loaded predates
  // any animation minted or edited this session, so the roster's
  // animations print whole into one <style> tag appended to the body
  // (later in the document than the file's <link> and the workspace
  // inline tag, and the last @keyframes of a name in document order
  // wins). Re-printed on every store change and per keyframes-session
  // settle (never per keystroke: a <style> text change is a full resolver
  // Reconstruct in WebKit); an unchanged print is not rewritten. Sessions'
  // live drafts substitute for the animation they edit until released.
  const animationDrafts = new Map();
  let animationTag = null;
  const printAnimations = (roster) => {
    const text = animationPreviewCss(roster, animationDrafts);
    if (!animationTag && text === '') {
      return;
    }
    if (!animationTag || !animationTag.isConnected) {
      animationTag = document.createElement('style');
      animationTag.setAttribute('data-daedalus-stylish-animation-preview', '');
      document.body.appendChild(animationTag);
    }
    if (animationTag.textContent !== text) {
      animationTag.textContent = text;
    }
  };

  // The observability ledger (log.js): the mirror swap is the server's own
  // account of what a gesture did, distribution and shadow-clearing
  // included, so the delta between the old and new style map is the one
  // log point that never lies about a mutation. Gated on the logger being
  // on: the diff walks the whole map, which is not work for every mutation
  // of every editing session.
  let loggedMap = styleMapOf(app.Blueprint.getBlueprint());
  app.onBlueprintChange((blueprint) => {
    const next = styleMapOf(blueprint);
    if (logEnabled()) {
      const delta = styleMapDelta(loggedMap, next);
      const keys = Object.keys(delta);
      if (keys.length) {
        log(
          'map',
          `style map changed (${keys.length} ${keys.length === 1 ? 'key' : 'keys'})`,
          delta,
        );
      } else {
        log('map', 'blueprint replaced, style map unchanged');
      }
    }
    loggedMap = next;
  });

  // The selection-scoped force state (the "Editing at" state
  // axis): a state-axis condition name ('hover') the canvas force-renders
  // on the selected element, or null. Panel state: any selection move
  // clears it (a forced state is an editing pose for ONE element, never a
  // sticky mode). The DOM consequence (the
  // data-force-state stamp the printers' pseudo compile matches) lives in
  // force-state.js, subscribed here.
  let forceState = null;
  const forceStateListeners = new Set();
  const announceForceState = () =>
    forceStateListeners.forEach((callback) => callback(forceState));
  app.onSelectionChange(() => {
    if (forceState !== null) {
      forceState = null;
      announceForceState();
    }
  });

  // The inspector's place state (four controls, one loud mode):
  // WHERE the panel is and whether the editing session is mounted:
  // {place, editing} with place one of surface-state.js's PLACES
  // (styles, materials) and editing the session's edited document
  // reference (verbatim as the map stores it), or null. Source-owned so
  // it survives mode switches untouched and both hosts always agree.
  // NOT selection-scoped here: the selection-move rule (surface-state.js
  // selectionMove, the session survives when the new selection wears the
  // edited document) needs the variant library and the open session,
  // which are panel knowledge, so the panels apply it and write the
  // result back through setPlace().
  let place = { place: 'styles', editing: null };
  const placeListeners = new Set();
  const announcePlace = () =>
    placeListeners.forEach((callback) => callback({ ...place }));

  // The canvas state vector facade (daedalus_ui's top-bar chrome),
  // resolved per call: the facade attaches in its own behavior, so binding
  // at source-creation time would race it. Guarded through globalThis so
  // the source stays node-testable (no Drupal in the test runner).
  const canvasVector = () =>
    globalThis.Drupal?.Daedalus?.CanvasVector ?? null;

  // The canvas-highlight state (transient band chrome for properties whose
  // effect is invisible geometry): null, or a normalized property-keyed
  // {key, sub, properties} spec — consumers say WHAT they are editing, the
  // painter registry (canvas-painters.js) owns how it tints. Panel state
  // the source owns so widgets in BOTH hosts
  // drive one renderer (canvas-highlight.js subscribes here; satellite
  // hover intents land here through the popout leader). Any selection move
  // clears it: the bands are edit-chrome for the current target only.
  let canvasHighlight = null;
  const highlightListeners = new Set();
  const announceCanvasHighlight = () =>
    highlightListeners.forEach((callback) => callback(canvasHighlight));
  app.onSelectionChange(() => {
    if (canvasHighlight !== null) {
      canvasHighlight = null;
      announceCanvasHighlight();
    }
  });

  // The canvas-paint state (Outline Variant projected onto the page):
  // null, or a {layers} spec of layers to paint, each {id, variant,
  // forms, label, flash} (layers.js shapes; forms the reference-form set
  // coverage matches; flash = the transient hover preview). The renderer
  // paints everything in the focus treatment — the spec carries no color.
  // Source-owned like the highlight so both hosts drive one renderer
  // (canvas-paint.js subscribes here; satellite intents land through the
  // popout leader) — but NOT cleared on selection moves: coverage is a
  // page-wide projection, and the owning view clears it when its outline
  // goes away (unmount, toggle off, hover leave).
  let canvasPaint = null;
  const paintListeners = new Set();
  const announceCanvasPaint = () =>
    paintListeners.forEach((callback) => callback(canvasPaint));
  // The label derives from the reference; id + forms + flash is the whole
  // identity of a spec (forms can grow when a library fetch resolves a
  // held reference, so they are part of it).
  const paintSignature = (paint) =>
    paint === null
      ? null
      : paint.layers
          .map(
            (layer) =>
              `${layer.id}${layer.flash ? '!' : ''}:${layer.variant ?? ''}`,
          )
          .join('|');

  // The one update_style settle path, hoisted from the returned
  // object because the media pick delivery commits through it too: the
  // widget's settle and the library selection must be the same op shape,
  // release semantics, and failure report.
  const commit = (change) => {
    const context = app.Blueprint.rootContext();
    if (!context) {
      return Promise.reject(
        new Error('No root entity mutation context on this page.'),
      );
    }
    const { key, condition = 'default', properties, label = null } = change;
    const operation = { op: 'update_style', key, properties };
    if (condition !== 'default') {
      operation.condition = condition;
    }
    log('op', `update_style ${key}`, { condition, properties, label });
    // The release is bound at dispatch to what is staged for these
    // properties (preview.settling): a capture commits a reference over
    // the literal it previewed, and the literal is what must settle.
    const settling = preview.settling(key, condition, properties);
    const request = app.Blueprint.mutate({
      ...context,
      operations: [operation],
      label,
    });
    request.then(
      () => preview.release(key, condition, settling),
      (error) => {
        log('op', `update_style ${key} REJECTED`, error);
        preview.release(key, condition, settling);
        // Flash the scope root: a sub-element part has no element of its
        // own — its parent carries the selectable key.
        app.Blueprint.reportFailure(error, key.split('::')[0]);
      },
    );
    return request;
  };

  // The reference outcome of a settled apply or detach: which keys gained
  // or lost references to the variant. The gesture names ONE key, but a
  // composition apply dresses the whole resolved subtree and a root detach
  // undresses the current one, so the carrier diff is the only honest
  // report of what the gesture reached. The "before" snapshot is taken at
  // dispatch (the map the server resolved against), so it is captured
  // unconditionally: apply and detach are user-paced gestures, not a hot
  // path.
  const logCarriers = (op, variant, before) => {
    if (!logEnabled()) {
      return;
    }
    const after = variantCarriers(
      styleMapOf(app.Blueprint.getBlueprint()),
      variant,
    );
    const delta = carrierDelta(before, after);
    log(
      'refs',
      `${op} ${variant} settled: +${Object.keys(delta.gained).length} -${Object.keys(delta.lost).length} keys, ${delta.carriers} carrying`,
      delta,
    );
  };

  return {
    role: 'leader',

    /**
     * Stages a live-preview value (null = unset) for one property.
     *
     * The optimistic half of an edit: the page's preview <style> tag updates
     * immediately, nothing is queued. Called per keystroke/slider tick.
     */
    preview: (key, condition, property, value) =>
      preview.stage(key, condition, property, value),

    /**
     * Releases staged preview values without committing (edit cancelled —
     * e.g. typed back to the committed value, then blurred).
     *
     * @param {string} key
     * @param {string} condition
     * @param {object} properties
     *   Property → last staged value; only still-matching stages release.
     */
    previewRelease: (key, condition, properties) =>
      preview.release(key, condition, properties),

    /**
     * Stages whole-entry preview replacements (the code editor's overlay).
     *
     * @param {object} entriesByKey
     *   Selectable key → style entry, or null to preview the key removed.
     */
    previewEntries: (entriesByKey) => preview.stageEntries(entriesByKey),

    /**
     * Releases staged entry replacements (buffer discarded or settled).
     */
    previewEntriesRelease: (keys) => preview.releaseEntries(keys),

    /**
     * Commits a batch of operations (the code editor's settle, and the
     * compound widget gesture).
     *
     * Same settle semantics as commit(), for several operations at once:
     * the batch applies atomically server-side (all land or none — the one
     * undo step a compound gesture needs), and every staging flavor on the
     * touched keys releases when it settles — whole-entry overlays AND
     * per-property stages (widget
     * gestures stage per key/condition/property, the parent's mid-gesture
     * display: grid included). Success: the mirror carries the change;
     * failure: honest revert + the op failure report on this page. Property
     * releases only drop still-matching values (preview.release), so a
     * newer in-flight gesture survives its predecessor's settlement; each
     * release is bound at dispatch to what was staged (preview.settling),
     * so a captured reference's commit still releases the literal it
     * previewed.
     *
     * @param {object} batch
     *   {operations, label} — operations as built by code/island.js,
     *   code/ops.js (paste), controls.js compoundOperations() or
     *   material-gestures.js (save_material / update_material, which
     *   address no style key: nothing to release, no element to flash).
     *
     * @return {Promise<object>}
     *   The op-queue promise, resolving with the applied response payload
     *   (rejects on failure; catch it).
     */
    commitBatch: ({ operations, label = null }) => {
      const context = app.Blueprint.rootContext();
      if (!context) {
        return Promise.reject(
          new Error('No root entity mutation context on this page.'),
        );
      }
      const keys = [
        ...new Set(
          operations
            .map((operation) => operation.key)
            .filter((key) => typeof key === 'string'),
        ),
      ];
      // Per update_style op, the release bound at dispatch to what is
      // staged for its properties (preview.settling, as commit() binds).
      const settling = operations
        .filter(
          (operation) =>
            operation.op === 'update_style' && operation.properties,
        )
        .map((operation) => {
          const condition = operation.condition ?? 'default';
          return [
            operation.key,
            condition,
            preview.settling(operation.key, condition, operation.properties),
          ];
        });
      const release = () => {
        preview.releaseEntries(keys);
        settling.forEach(([key, condition, properties]) =>
          preview.release(key, condition, properties),
        );
      };
      log(
        'op',
        `batch of ${operations.length} (${label ?? 'no label'})`,
        operations,
      );
      const request = app.Blueprint.mutate({ ...context, operations, label });
      request.then(release, (error) => {
        log('op', `batch of ${operations.length} REJECTED`, error);
        release();
        app.Blueprint.reportFailure(
          error,
          keys.length ? keys[0].split('::')[0] : null,
        );
      });
      return request;
    },

    /**
     * Commits property changes as one update_style op on the op queue.
     *
     * The settle half: properties map values to set, null to unset.
     * The staged preview for these properties is released when the op
     * settles — on success the mirror and swapped markup now carry the
     * change, on failure the preview honestly reverts. Failures are
     * reported through the one op failure report here (so
     * satellite-initiated commits fail loudly on the page that owns the
     * canvas); the returned promise still rejects so the widget can mark
     * its input.
     *
     * @param {object} change
     *   {key, condition, properties, label} — condition 'default' is
     *   omitted from the wire op (the server's default).
     *
     * @return {Promise<object>}
     *   The op-queue promise (rejects on failure; catch it).
     */
    commit,

    /**
     * The live media uuid → URL table (the preview transform's read).
     *
     * The seed object itself, mutated on pick (never a copy), so the
     * per-render getMediaUrls read in the preview sees extensions without
     * re-wiring. The media row's thumbnail reads it through here too.
     */
    getMediaUrls: () => mediaUrls,

    /**
     * Whether the media library can open (honest picker chrome).
     */
    canPickMedia: () => mediaLibraryAvailable(),

    /**
     * Opens the media library for one property row and lands the pick.
     *
     * The whole delivery runs here, not in the widget, because a satellite
     * row's pick arrives as an intent with only the target coordinates (the
     * modal and the op queue both live on this page). On selection: the live
     * URL table gains the server-resolved preview URL, the token stages so
     * the page previews instantly (before the op settles), and the token
     * commits through the ordinary update_style settle path, release and
     * failure report included. A selection without a URL (media lost its
     * file) still stages and commits: the table cannot answer it, so the
     * preview shows "none", exactly the server's emit-gate degradation.
     *
     * A `companions` map (the basic Background widget's seed, computed at
     * the click; the advanced row passes none) merges into the SAME
     * update_style op beside the token (one batch, one undo) and stages
     * with it, so the instant preview already paints cover/no-repeat/
     * center instead of flashing the tiled browser defaults. The settle
     * releases every staged property because they all ride the one
     * properties map. A satellite's payload arrives verbatim through the
     * pick-media intent relay, so its companions land identically.
     *
     * @param {object} target
     *   {key, condition, property, label, companions}; condition defaults
     *   to 'default', companions to none.
     *
     * @return {boolean}
     *   Whether the library could open (false without a server URL).
     */
    pickMedia: ({
      key,
      condition = 'default',
      property,
      label = null,
      companions = null,
    }) =>
      openMediaLibrary(({ uuid, token, url }) => {
        if (typeof url === 'string' && url !== '') {
          mediaUrls[uuid] = url;
        }
        const seeded =
          companions !== null &&
          typeof companions === 'object' &&
          !Array.isArray(companions)
            ? companions
            : {};
        preview.stage(key, condition, property, token);
        Object.entries(seeded).forEach(([companion, value]) =>
          preview.stage(key, condition, companion, value),
        );
        commit({
          key,
          condition,
          properties: { [property]: token, ...seeded },
          label,
        }).catch(() => {});
      }),

    /**
     * The materials roster: the store's current index (tokens, swatches,
     * variants, stencils in the delivery shape), read through the app's
     * Materials facade. Its identity changes exactly when the records do.
     */
    getMaterials: () => app.Materials.getRoster(),

    /**
     * Subscribes to roster changes (a boot, a delta from any lane: this
     * window's response, the paired window's relay, an AI turn); returns
     * unsubscribe. The callback receives the new roster.
     */
    onMaterialsChange: (callback) => app.Materials.onChange(callback),

    /**
     * Lands a gesture route's response on the page.
     *
     * The swatch and variant routes ride their own session (never the op
     * queue), and every write among them answers with the `materials`
     * delta beside its payload (and `history` where a lane records one).
     * Handing the whole response to the op queue's response path applies
     * exactly that: the store learns the minted, edited or removed
     * records (and relays the delta to the paired window over the
     * materials wire), the swatch custom properties re-stamp through the
     * subscription above, and a payload without blueprint or markup
     * touches nothing else. A satellite applies its own copy and relays
     * the delta itself (satellite-source.js).
     */
    applyResponse: (payload) => app.Blueprint.applyResponse(payload),

    /**
     * Previews a swatch value on the canvas without committing it.
     *
     * The gradient swatch session's live drag: the draft lands on the
     * token scope element's inline custom property (the same delivery
     * extension a committed change re-stamps), so every use on the canvas
     * answers per input event while the store keeps the committed value.
     * A null value releases the preview: the property returns to the
     * record's own value. A satellite's preview arrives as a
     * swatch-preview intent (the canvas lives here); a record the store no
     * longer holds previews nothing.
     *
     * @param {?object} record
     *   The swatch record (delivery shape), or null.
     * @param {?string} value
     *   The draft value, or null to release.
     */
    previewSwatch: (record, value) => {
      const scope = tokenScope();
      if (scope && record) {
        stampSwatch(scope, record, value ?? tokenDisplayValue(record.value));
      }
    },

    /**
     * Previews an animation's draft steps on the canvas without
     * committing them.
     *
     * The keyframes session's settle: the draft's @keyframes replaces the
     * animation's in the preview sheet (the same delivery extension a
     * store change re-prints), so every wearer on the canvas plays the
     * draft while the store keeps the committed steps. Null steps release
     * the preview: the sheet returns to the record's own steps. A
     * satellite's preview arrives as an animation-preview intent (the
     * canvas lives here); a record the store no longer holds previews
     * nothing.
     *
     * @param {?object} record
     *   The animation record (delivery shape), or null.
     * @param {?object} steps
     *   The draft steps map, or null to release.
     */
    previewAnimation: (record, steps) => {
      if (!record) {
        return;
      }
      if (steps === null || steps === undefined) {
        animationDrafts.delete(record.id);
      } else {
        animationDrafts.set(record.id, steps);
      }
      printAnimations(app.Materials.getRoster());
    },

    /**
     * Reports a gesture outcome through this window's messenger, the page
     * builder's one notification spine (toast + notifications-sidebar
     * history; js/src/notify.js). Statuses and errors alike ride it;
     * in-context correction surfaces (open dialogs, per-field marks) stay
     * inline and never come through here. Never load-bearing: a host
     * without the messenger silently drops it.
     */
    notify,

    /**
     * Commits an apply_variant op: a within-axis reference swap.
     *
     * The incoming variant replaces the reference sharing its axis (keeping
     * its precedence slot) and composes with other axes' references and the
     * entry's own overrides. Same settle semantics as commit(); the
     * scope check and the one-reference-per-axis rule are enforced
     * server-side.
     *
     * @return {Promise<object>}
     *   The op-queue promise (rejects on failure; catch it).
     */
    applyVariant: (key, variant, label = null) => {
      const context = app.Blueprint.rootContext();
      if (!context) {
        return Promise.reject(
          new Error('No root entity mutation context on this page.'),
        );
      }
      const before = variantCarriers(
        styleMapOf(app.Blueprint.getBlueprint()),
        variant,
      );
      log('op', `apply_variant ${variant} on ${key}`, {
        label,
        carriedBefore: before,
      });
      const request = app.Blueprint.mutate({
        ...context,
        operations: [{ op: 'apply_variant', key, variant }],
        label,
      });
      request.then(
        () => logCarriers('apply_variant', variant, before),
        (error) => {
          log('op', `apply_variant ${variant} on ${key} REJECTED`, error);
          app.Blueprint.reportFailure(error, key.split('::')[0]);
        },
      );
      return request;
    },

    /**
     * Commits a detach_variant op: ONE reference materializes into the
     * element's own properties and drops (per-ref, the explicit unlink
     * gesture) — the element keeps its look but stops following that
     * variant. References on other axes and the element's overrides stay.
     *
     * @return {Promise<object>}
     *   The op-queue promise (rejects on failure; catch it).
     */
    detachVariant: (key, variant, label = null) => {
      const context = app.Blueprint.rootContext();
      if (!context) {
        return Promise.reject(
          new Error('No root entity mutation context on this page.'),
        );
      }
      const before = variantCarriers(
        styleMapOf(app.Blueprint.getBlueprint()),
        variant,
      );
      log('op', `detach_variant ${variant} on ${key}`, {
        label,
        carriedBefore: before,
      });
      const request = app.Blueprint.mutate({
        ...context,
        operations: [{ op: 'detach_variant', key, variant }],
        label,
      });
      request.then(
        () => logCarriers('detach_variant', variant, before),
        (error) => {
          log('op', `detach_variant ${variant} on ${key} REJECTED`, error);
          app.Blueprint.reportFailure(error, key.split('::')[0]);
        },
      );
      return request;
    },

    /**
     * Mints a v4 uuid for client-built structural ops (the batch grammar
     * honors add_section/add_element uuids). The app's own generator, so
     * every uuid on this page comes from one mint.
     */
    generateUuid: () => app.generateUuid(),

    /**
     * The committed selection's selectable key, or null.
     */
    getSelected: () => app.Selection.getSelected(),

    /**
     * The committed selection's part facet — the part id sub-selected on
     * the selected element ('.quote'), or null (the whole element). Spine
     * state: its owner is always getSelected(), and any element-only
     * selection gesture clears it.
     */
    getSelectedPart: () => app.Selection.getSelectedPart(),

    /**
     * Selects a selectable key on the canvas (the page tree's row click),
     * optionally with a part facet (the breadcrumb's clear passes the bare
     * key, which clears the facet, the spine's own invariant).
     *
     * Routed through the source so the satellite's tree drives the same
     * spine by intent; the change comes back through onSelectionChange /
     * onPartChange. The selection spine stays the one authority, this is
     * just its programmatic face.
     */
    select: (key, part = null) => app.Selection.select(key, part),

    /**
     * Subscribes to selection KEY changes; returns unsubscribe. Fires when
     * the selected element changes, never on a part-facet change under the
     * same key, so element-scoped clear-on-change consumers keep meaning
     * "different element".
     */
    onSelectionChange: (callback) => app.onSelectionChange(callback),

    /**
     * Subscribes to the committed (key, part) pair — the part-aware
     * counterpart of onSelectionChange: fires on any facet change too,
     * with ({key, part}, previous). Returns unsubscribe.
     */
    onPartChange: (callback) => app.onPartChange(callback),

    /**
     * The hovered (would-select) selectable key, or null.
     */
    getHovered: () => app.Selection.getHovered(),

    /**
     * Drives the shared selection hover (a code tree row's mouseenter):
     * the canvas outline and the page-elements row light up exactly as if
     * the pointer crossed the element itself — one hover, every surface.
     * Callers follow the panel rows' contract: clear on leave only while
     * the hover is still theirs (getHovered() returns their key).
     */
    hover: (key) => app.Selection.hover(key),

    /**
     * Subscribes to hover changes; returns unsubscribe. Fires at pointer
     * speed (every boundary crossing), so consumers paint, not recompute.
     */
    onHoverChange: (callback) => app.onHoverChange(callback),

    /**
     * The hovered part facet ({key, part} | null) — the part counterpart
     * of getHovered(), spine state carrying its OWN owner key.
     */
    getHoveredPart: () => app.Selection.getHoveredPart(),

    /**
     * Drives the part-hover facet (a code part header's mouseenter): the
     * canvas dashed paint and the tree part-row glow light up exactly as
     * if the pointer Cmd-crossed the part itself — one facet, every
     * surface. Deliberately never the element hover (a part header must
     * not light its owner's solid outline). hoverPart(null) clears;
     * callers follow the rows' contract: clear on leave only while the
     * facet is still theirs.
     */
    hoverPart: (key, part = null) => app.Selection.hoverPart(key, part),

    /**
     * Subscribes to part-hover facet changes ({key, part} | null,
     * value-compared); returns unsubscribe. Pointer-speed like
     * onHoverChange: consumers paint, not recompute.
     */
    onHoverPartChange: (callback) => app.onHoverPartChange(callback),

    /**
     * The root entity's style map (selectable key → style entry), or {}.
     */
    getStyleMap: () => styleMapOf(app.Blueprint.getBlueprint()),

    /**
     * Subscribes to style map changes (mirror replacements); returns
     * unsubscribe.
     */
    onStyleMapChange: (callback) =>
      app.onBlueprintChange((blueprint) => callback(styleMapOf(blueprint))),

    /**
     * The root entity's uuid, or null before the mirror boots.
     */
    getRootUuid: () => app.Blueprint.getRoot()?.uuid ?? null,

    /**
     * Component uuid → inline block type (null for plugin blocks), from the
     * blueprint's layout — nested layouts included.
     */
    getBlockTypes: () => blockTypes(),

    /**
     * Section uuid → layout plugin id, from the blueprint's layout — nested
     * layouts included. The section counterpart of getBlockTypes(): what
     * per-layout guide declarations (guide.layouts) resolve by.
     */
    getSectionLayouts: () => sectionLayoutTable(),

    /**
     * The staged layout's structural tree (style-guide.js layoutTree()),
     * from the blueprint's layout: what composition capture walks to
     * translate a section's child map entries into slot keys, at any
     * nesting depth. Broadcast to the satellite with the rest of the
     * session state.
     */
    getLayout: () => layout(),

    /**
     * The selection facts: {self, parent} with self = the element's own
     * computed display/isContainer/childCount/hasText (read live from
     * the canvas DOM) plus per-declared-part presence, and parent = the
     * parent's computed display/flex-direction/align-items plus the
     * nearest selectable ancestor key, or null when the selection has no
     * canvas element. self.parts joins the declared selectors (the style
     * guide's elements map for the selection's type, partsForKey, the
     * same join the tree's part rows resolve through) with the MODEL's
     * presence answer: the blueprint mirror's `_element.markup`
     * annotation (app.Blueprint.partPresence), not a DOM scrape, link
     * state and media kind were decided from entity data at serialize
     * time, so the facts agree with the render by construction. A
     * selector the model does not speak for is omitted, which the gates
     * read as unknown-context and degrade to offering. self.partChildCount
     * is the DOM twin per declared part (how many child boxes the part's
     * first canvas match lays out), the fact an element-bound control's
     * gate reads (the page's Gap over the layout root); every declared
     * selector is counted, spoken for or not, since the count is a
     * canvas fact. Adaptive widgets read the facts to shape what they
     * OFFER; they never redirect a write (the "write locally, read
     * contextually" frame). The popout leader broadcasts the whole context
     * so the satellite, which has no canvas document and no mirror, reads
     * the same facts.
     */
    getLayoutContext: () => {
      const key = app.Selection.getSelected();
      const parts = {};
      const selectors = [];
      for (const part of partsForKey(
        guide,
        key,
        blockTypes(),
        sectionLayoutTable(),
      )) {
        selectors.push(part.id);
        const present = app.Blueprint.partPresence?.(key, part.id);
        if (typeof present === 'boolean') {
          parts[part.id] = present;
        }
      }
      return readLayoutContext(key, parts, selectors);
    },

    /**
     * The `_element.markup` annotation for a selectable key, or null when
     * the model does not speak for it, the instance model the mirror
     * delivers (what THIS element renders as, minted instance carriers
     * included). Instance-part labels resolve through it (partLabel).
     */
    getMarkup: (key) => app.Blueprint.markupForKey?.(key) ?? null,

    /**
     * Reads live computed values + the content-box size for a style
     * target — the panel's placeholder read (computed-read.js), routed
     * through the source so widget code never reaches around it to app
     * globals.
     *
     * First match only: a multi-match part selector's placeholder comes
     * from the first match (the bands loop every match themselves). The
     * content size is the border-box rect minus borders and padding —
     * the diagram's center readout.
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} sub
     *   A declared part selector ('.quote'), or null for the element.
     * @param {string[]} properties
     *   The computed properties to read.
     *
     * @return {?{values: Object<string, string>,
     *   content: {width: number, height: number}}}
     *   null when the target has no canvas element (widgets degrade to
     *   placeholder-less rendering rather than guess).
     */
    readCanvas: (key, sub, properties) => {
      const matches = resolveTargets(key, sub);
      if (!matches.length) {
        return null;
      }
      const element = matches[0];
      const computed = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const border = borderPx(computed);
      const padding = paddingPx(computed);
      return {
        values: readComputed(element, properties),
        content: {
          width: Math.max(
            0,
            rect.width -
              border.left -
              border.right -
              padding.left -
              padding.right,
          ),
          height: Math.max(
            0,
            rect.height -
              border.top -
              border.bottom -
              padding.top -
              padding.bottom,
          ),
        },
      };
    },

    /**
     * The drill target's rendered tag name ('h2'), or null when the
     * target has no canvas element. The base:{tag} provenance read
     * (skinDefaultEntry): whether a skin's bare-markup defaults hit
     * this element is a fact only the live DOM holds (a collapsed block
     * IS its tag; a wrapped block's root is a div), so the leader answers
     * from the canvas and relays the selection's tags to the satellite
     * with every state broadcast (popout-leader.js selectionTags).
     * localName, not tagName.toLowerCase(): an svg-rooted block
     * (the vector image) answers 'svg' either way, but SVG local names
     * are case-sensitive (clipPath), so lowercasing would lie for any
     * non-HTML target while localName is the canonical name in both
     * namespaces.
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} sub
     *   A declared part selector ('.quote'), or null for the element.
     *
     * @return {?string}
     */
    readCanvasTag: (key, sub) => {
      const matches = resolveTargets(key, sub);
      return matches.length ? matches[0].localName : null;
    },

    /**
     * Re-arms the target's motion trigger on the canvas (the Motion
     * trigger control's Replay and the advanced Motion section's): the
     * motion-triggers runtime (daedalus_stylish/motion_triggers, a dependency of
     * the inspector library) lifts the hold, rewinds and observes each
     * element afresh, or rewinds and plays it now where the browser runs
     * triggers natively. The canvas lives here, so the satellite relays a
     * motion-replay intent to this method (popout-leader.js).
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} sub
     *   A declared part selector ('.quote'), or null for the element.
     *
     * @return {boolean}
     *   Whether the target had a canvas element to replay (false is the
     *   honest no-op, the same rule as a missing element on readCanvas).
     */
    replayMotion: (key, sub = null) => {
      const matches = resolveTargets(key, sub);
      if (!matches.length) {
        return false;
      }
      matches.forEach((match) => Drupal.Daedalus.Stylish.motionTriggers.replay(match));
      return true;
    },

    /**
     * The canvas state vector snapshot ({width, scheme, motion}, plus the
     * measured stage width as `measured`), or null when this page carries
     * no vector chrome (the facade attaches with the top-bar cluster;
     * without it the inspector's compound honestly degrades to the state
     * axis alone).
     */
    getCanvasVector: () => canvasVector()?.get() ?? null,

    /**
     * Poses the canvas state vector (a partial over the current state) —
     * the "drive the canvas there" write half. No-op without the facade.
     */
    setCanvasVector: (partial) => {
      canvasVector()?.set(partial);
    },

    /**
     * Subscribes to canvas vector changes; returns unsubscribe.
     *
     * A page without the facade returns a no-op unsubscribe — the chips
     * simply never hear a vector.
     */
    onCanvasVectorChange: (callback) =>
      canvasVector()?.subscribe(callback) ?? (() => {}),

    /**
     * The selection-scoped force state: a state-axis condition name, or
     * null.
     */
    getForceState: () => forceState,

    /**
     * Sets (or clears, with null) the force state for the CURRENT
     * selection.
     */
    setForceState: (state) => {
      const next = state || null;
      if (forceState === next) {
        return;
      }
      forceState = next;
      announceForceState();
    },

    /**
     * Subscribes to force-state changes; returns unsubscribe.
     */
    onForceStateChange: (callback) => {
      forceStateListeners.add(callback);
      return () => forceStateListeners.delete(callback);
    },

    /**
     * The place state: {place, editing}, a fresh copy per read.
     */
    getPlace: () => ({ ...place }),

    /**
     * Sets the place state.
     *
     * The place value normalizes to the vocabulary (anything else lands
     * on 'styles', the home place); the editing reference travels
     * verbatim (machine name or uuid, as the map stores it); whether it
     * names a document the selection actually wears is the panels'
     * honesty to keep, the source just holds the state.
     */
    setPlace: (next) => {
      const value = PLACES.includes(next?.place) ? next.place : 'styles';
      const editing =
        typeof next?.editing === 'string' && next.editing !== ''
          ? next.editing
          : null;
      if (place.place === value && place.editing === editing) {
        return;
      }
      log(
        'place',
        editing === null ? value : `${value} (editing ${editing})`,
      );
      place = { place: value, editing };
      announcePlace();
    },

    /**
     * Subscribes to place changes; returns unsubscribe.
     */
    onPlaceChange: (callback) => {
      placeListeners.add(callback);
      return () => placeListeners.delete(callback);
    },

    /**
     * The current canvas highlight: null, or {key, sub, properties}.
     */
    getCanvasHighlight: () => canvasHighlight,

    /**
     * Sets (or clears, with null) the canvas highlight.
     *
     * The spec names a style target (selectable key + optional part
     * selector) plus the PROPERTIES being edited (['padding-top'], or
     * several under spread modifiers) — never how to paint them; the
     * painter registry maps each property to its tint, and painterless
     * properties no-op silently. Widgets set it on hover/scrub/
     * editor-open and clear it on leave/close; deselection clears it
     * automatically.
     *
     * @param {?{key: string, sub: ?string, properties: string[]}} spec
     */
    setCanvasHighlight: (spec) => {
      const next = spec
        ? {
            key: spec.key,
            sub: spec.sub ?? null,
            properties: spec.properties ?? [],
          }
        : null;
      if (
        (next === null && canvasHighlight === null) ||
        (next !== null &&
          canvasHighlight !== null &&
          next.key === canvasHighlight.key &&
          next.sub === canvasHighlight.sub &&
          next.properties.join(' ') === canvasHighlight.properties.join(' '))
      ) {
        return;
      }
      canvasHighlight = next;
      announceCanvasHighlight();
    },

    /**
     * Subscribes to canvas-highlight changes; returns unsubscribe.
     */
    onCanvasHighlightChange: (callback) => {
      highlightListeners.add(callback);
      return () => highlightListeners.delete(callback);
    },

    /**
     * The current canvas paint: null, or {layers}.
     */
    getCanvasPaint: () => canvasPaint,

    /**
     * Sets (or clears, with null) the canvas paint.
     *
     * The spec lists the layers to paint on the canvas in stacking order,
     * each carrying its identity ({id, variant, label} — layers.js
     * vocabulary; variant the record id coverage matches verbatim) plus
     * an optional flash flag (the hover preview pulses). No color rides
     * along: the
     * renderer paints the focus treatment. WHERE each layer paints is not
     * the caller's to say: the renderer projects coverage from the style
     * map's own refs (paintCoverage), so the spec can never disagree with
     * the map.
     *
     * @param {?{layers: Array<{id: string, variant: ?string,
     *   label: ?string, flash: ?boolean}>}} paint
     */
    setCanvasPaint: (paint) => {
      const next =
        paint && Array.isArray(paint.layers) && paint.layers.length
          ? {
              layers: paint.layers.map((layer) => ({
                id: layer.id,
                variant: layer.variant ?? null,
                label: layer.label ?? null,
                flash: Boolean(layer.flash),
              })),
            }
          : null;
      if (paintSignature(next) === paintSignature(canvasPaint)) {
        return;
      }
      canvasPaint = next;
      announceCanvasPaint();
    },

    /**
     * Subscribes to canvas-paint changes; returns unsubscribe.
     */
    onCanvasPaintChange: (callback) => {
      paintListeners.add(callback);
      return () => paintListeners.delete(callback);
    },
  };
};
