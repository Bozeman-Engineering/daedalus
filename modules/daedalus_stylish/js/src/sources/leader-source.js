import {
  parseKey,
  styleMapOf,
  componentBlockTypes,
  sectionLayouts,
} from '../style-guide.js';
import { notify } from '../notify.js';
import { failureText, getText, post } from '../gesture-transport.js';
import { fetchDefaults } from '../skins-api.js';
import { fetchFontStylesheet } from '../fonts-api.js';
import { fontsSignature } from '../fonts-model.js';
import { customProperty } from '../materials.js';
import { animationPreviewCss } from '../animations-model.js';
import { PLACES } from '../surface-state.js';
import { addressKey } from '../write-address.js';
import {
  TOKEN_SCOPE_CLASS,
  createMaterialDelivery,
  createPreview,
} from '../preview.js';
import { readLayoutContext } from '../layout-context.js';
import { partsForKey } from '../parts.js';
import {
  resolveRoot,
  resolveTargets,
  readComputed,
  pseudoOf,
  lengthBases,
  paddingPx,
  borderPx,
} from '../computed-read.js';
import {
  MEDIA_PICK_PLACEHOLDER,
  openMediaLibrary,
  mediaLibraryAvailable,
} from '../media-library.js';
import { compoundOperations } from '../controls.js';
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
 * derived from the root entity's blueprint (styleMapOf: the node styles
 * of its `layout` plus the node-less top-level `styles`), and block-type
 * resolution walks the same `layout`. Both re-derive when the mirror
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
  const deriveTables = () => {
    const blueprint = app.Blueprint.getBlueprint();
    if (blueprint !== typesFor) {
      typesFor = blueprint;
      types = componentBlockTypes(blueprint);
      layouts = sectionLayouts(blueprint);
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
    // The token scope element (DefaultsStylesheet::SCOPE_CLASS), the one
    // element the compiled skin and swatch files deliver custom
    // properties on: the scope lane writes its slots there.
    resolveScope: () => document.querySelector(`.${TOKEN_SCOPE_CLASS}`),
  });
  // Every mutation response replaces the mirror; staged-but-unsettled
  // preview must re-project over the new authoritative base.
  app.onBlueprintChange(() => preview.baseChanged());

  // The materials delivery extension: the compiled skin and swatch
  // stylesheets this page loaded predate any token or swatch minted or
  // edited this session, so a committed var(--color-accent) or
  // var(--swatch-{name}) would resolve to its old value (or nothing) until
  // a full reload. Every store change delivers the roster through the
  // preview's token scope lane (preview.js createMaterialDelivery: the
  // values that differ from what the files print, each condition under
  // its own wrapper), so the reload hand-off is seamless and the other
  // scheme never wears the wrong entry.
  const delivery = createMaterialDelivery(preview, app.Materials.getRoster());
  app.Materials.onChange((roster) => {
    delivery.apply(roster);
    printAnimations(roster);
    deliverFonts(roster);
  });

  // The font delivery extension, the animation extension's twin for the
  // at-rule the client cannot print: the compiled fonts file this page
  // loaded predates any font minted, edited or deleted this session, and
  // a face's url() is the managed file's, the server's to derive. So when
  // the roster's fonts change (a mint, a faces edit, a delete, this
  // window's or the paired one's: fonts-model.js fontsSignature, never an
  // unrelated store change) the leader re-reads the whole current print
  // (fonts-api.js fetchFontStylesheet) and stamps it on the page
  // (preview.js deliverFonts), so a minted family loads before any
  // reload. The boot roster is the compiled file's content and delivers
  // nothing; a later answer never overwrites a newer one; a refused read
  // (a window without the font grades) delivers nothing and the reload
  // shows the file.
  let deliveredFonts = fontsSignature(app.Materials.getRoster());
  let fontsRead = 0;
  const deliverFonts = (roster) => {
    const signature = fontsSignature(roster);
    if (signature === deliveredFonts) {
      return;
    }
    deliveredFonts = signature;
    const read = ++fontsRead;
    fetchFontStylesheet().then(
      ({ css }) => {
        if (read === fontsRead) {
          preview.deliverFonts(typeof css === 'string' ? css : '');
          log('fonts', 'font-face delivered', css);
        }
      },
      (error) => log('fonts', 'font-face delivery refused', error?.message),
    );
  };

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

  // The ledger's account of where writes land: the write address when
  // one stands (spine state, daedalus_ui's selection facade; the
  // switcher and provenance's "Edit at" seat a rule there through
  // address()), else the subject facet's own key, else the element. Its
  // every move is logged here, never held here.
  app.onTargetChange(({ selected, target, address }) => {
    const at = address ?? target ?? null;
    log('address', at === null ? (selected ?? 'none') : addressKey(at));
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
  // The active skin's defaults as delivered SINCE boot: null until a
  // skin session's push refreshed them (the guide payload's skinDefaults
  // is the boot truth the panel reads meanwhile), then the route's
  // answer verbatim (skins-api.js fetchDefaults), the same shape. Panel
  // state on neither window: the leader holds it and the satellite
  // reads it off the broadcast, so both panels' stacks read one skin.
  let skinDefaults = null;
  const skinDefaultsListeners = new Set();
  const announceSkinDefaults = () =>
    skinDefaultsListeners.forEach((callback) => callback(skinDefaults));

  // The canvas state vector facade (daedalus_ui's top-bar chrome),
  // resolved per call: the facade attaches in its own behavior, so binding
  // at source-creation time would race it. Guarded through globalThis so
  // the source stays node-testable (no Drupal in the test runner).
  const canvasVector = () =>
    globalThis.Drupal?.Daedalus?.CanvasVector ?? null;

  // The canvas-highlight state (transient band chrome for properties whose
  // effect is invisible geometry): null, or a normalized property-keyed
  // {key, selector, properties} spec — consumers say WHAT they are editing, the
  // painter registry (canvas-painters.js) owns how it tints. Panel state
  // the source owns so widgets in BOTH hosts
  // drive one renderer (canvas-highlight.js subscribes here; satellite
  // hover intents land here through the popout leader). Any move of the
  // styling address clears it, a selection move or a target move under
  // the same selection alike (the facade's target-aware subscription
  // fires for either): the bands are edit-chrome for the current write
  // address only, and a spec keyed at the old address would paint the
  // wrong matches until the next hover.
  let canvasHighlight = null;
  const highlightListeners = new Set();
  const announceCanvasHighlight = () =>
    highlightListeners.forEach((callback) => callback(canvasHighlight));
  app.onTargetChange(() => {
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
        // Flash the scope root: a selector entry has no element of its
        // own — its owner carries the selectable key.
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

  // The batch settle path, hoisted beside commit for the same reason: a
  // media pick whose routed changes touch several keys commits through
  // it, and the widget's compound gesture and the library selection must
  // be the same batch shape, release semantics and failure report.
  const commitBatch = ({ operations, label = null }) => {
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
        (operation) => operation.op === 'update_style' && operation.properties,
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
     *   code/paste-door.js entriesToOps() (the paste door, the header's
     *   Paste CSS included), controls.js compoundOperations() or
     *   material-gestures.js (save_material / update_material, which
     *   address no style key: nothing to release, no element to flash).
     *
     * @return {Promise<object>}
     *   The op-queue promise, resolving with the applied response payload
     *   (rejects on failure; catch it).
     */
    commitBatch,

    /**
     * Reads a CSS fragment under one style key through the spine's parse
     * door (the code view's paste; paste-door.js is the client half).
     *
     * A write-nothing POST to daedalus_blueprint_api's parse-css route
     * on this page's root entity (the staged working copy the ops edit),
     * with the CSRF token the gesture transport holds. The answer is the
     * door's: {key, entries: [{key, condition, properties}], wear:
     * [{key, variant}], errors: [BlueprintError::toArray()]} with
     * `context.line` relative to the sent css; the route answers 200
     * under correctives (the entries are the parser's best effort), so a
     * rejection here is the transport's (a refused key, the gates, the
     * network) and is reported on this page's messenger before it
     * rejects, the satellite relaying it to its own.
     *
     * @param {string} key
     *   The style key the text was pasted into (an element or a rule).
     * @param {string} css
     *   The fragment.
     *
     * @return {Promise<object>}
     */
    parseCss: (key, css) => {
      const context = app.Blueprint.rootContext();
      if (!context) {
        return Promise.reject(
          new Error('No root entity mutation context on this page.'),
        );
      }
      return post(
        `daedalus-blueprint/${encodeURIComponent(context.entityType)}/${encodeURIComponent(context.entityId)}/parse-css`,
        { key, css },
      ).catch((error) => {
        notify('error', failureText(error));
        throw error;
      });
    },

    /**
     * Prints one style key's own entries as CSS (the header's Copy CSS).
     *
     * A read of daedalus_blueprint_api's route in css mode on this
     * page's root entity (the staged working copy): the element the key
     * names, pruned to itself, printed as flat dialect rules by
     * DialectPrinter::printStyles() (`#<uuid>` as the id, the element's
     * words as a comment, its own wear as a comment line), or with a
     * rule key (`::selector`) that rule's entry and its continuations by
     * descent. The text is the dialect the AI reads and the parse door
     * takes back. A failure (a refused key, the gates, the network) is
     * reported on this page's messenger before it rejects, the satellite
     * relaying it to its own.
     *
     * @param {string} key
     *   The style key (an element, or a rule).
     *
     * @return {Promise<string>}
     *   The CSS text.
     */
    readCss: (key) => {
      const context = app.Blueprint.rootContext();
      if (!context) {
        return Promise.reject(
          new Error('No root entity mutation context on this page.'),
        );
      }
      const pos = key.indexOf('::');
      const root = pos === -1 ? key : key.slice(0, pos);
      const parsed = parseKey(root);
      if (!parsed) {
        return Promise.reject(new Error(`The key '${key}' does not parse.`));
      }
      const query = new URLSearchParams({ mode: 'css', uuid: parsed.uuid });
      if (pos !== -1) {
        query.set('selector', key.slice(pos + 2));
      }
      return getText(
        `daedalus-blueprint/${encodeURIComponent(context.entityType)}/${encodeURIComponent(context.entityId)}?${query}`,
      ).catch((error) => {
        notify('error', failureText(error));
        throw error;
      });
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
     * Opens the media library for one property and lands the pick.
     *
     * The whole delivery runs here, not in the widget, because a satellite
     * row's pick arrives as an intent with only the routed payload (the
     * modal and the op queue both live on this page). On selection: the live
     * URL table gains the server-resolved preview URL, the token stages so
     * the page previews instantly (before the op settles), and the token
     * commits through the ordinary update_style settle path, release and
     * failure report included. A selection without a URL (media lost its
     * file) still stages and commits: the table cannot answer it, so the
     * preview shows "none", exactly the server's emit-gate degradation.
     *
     * `changes` is the routed list the widget pipeline's route() produces
     * (basic-controls.js: the picked property and its set companions on
     * the one box the address rule answers for them as a group, each null
     * companion on the box its value lives on), or the advanced row's one
     * change. Exactly one change carries the picked property, holding
     * MEDIA_PICK_PLACEHOLDER (media-library.js) until the selection fills
     * it with the token; anything else is a contract violation and throws
     * before the modal opens. Every property of every change stages with
     * the token, so the instant preview already paints cover/no-repeat/
     * center and the replaced colour gone instead of flashing the tiled
     * browser defaults, and the settle releases them all. One change is
     * one update_style op; several are one atomic batch under the one
     * label (compoundOperations, as the pipeline's onCommit spells it), so
     * every part lands or none does. The source never routes: a widget
     * names no key, and the pipeline's rule is the only one. A
     * satellite's payload arrives verbatim through the pick-media intent
     * relay, so its pick lands identically.
     *
     * @param {object} pick
     *   {condition, property, label, changes}; condition defaults to
     *   'default', changes is [{key, properties}].
     *
     * @return {boolean}
     *   Whether the library could open (false without a server URL).
     */
    pickMedia: ({ condition = 'default', property, label = null, changes }) => {
      const carriers = Array.isArray(changes)
        ? changes.filter(
            (change) =>
              change?.properties?.[property] === MEDIA_PICK_PLACEHOLDER,
          )
        : [];
      if (carriers.length !== 1) {
        throw new Error(
          `A media pick carries ${property} in exactly one change; ` +
            `got ${carriers.length}.`,
        );
      }
      return openMediaLibrary(({ uuid, token, url }) => {
        if (typeof url === 'string' && url !== '') {
          mediaUrls[uuid] = url;
        }
        const filled = changes.map(({ key, properties }) => ({
          key,
          condition,
          properties:
            properties[property] === MEDIA_PICK_PLACEHOLDER
              ? { ...properties, [property]: token }
              : properties,
        }));
        filled.forEach(({ key, properties }) =>
          Object.entries(properties).forEach(([name, value]) =>
            preview.stage(key, condition, name, value),
          ),
        );
        const request =
          filled.length > 1
            ? commitBatch({ operations: compoundOperations(filled), label })
            : commit({ ...filled[0], label });
        request.catch(() => {});
      });
    },

    /**
     * The materials roster: the store's current index (tokens, swatches,
     * variants, blueprints in the delivery shape), read through the app's
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
     * Previews a material's value on the canvas without committing it.
     *
     * The palette token card's and the gradient swatch session's live
     * drag: the draft lands on the token scope lane (the same delivery a
     * committed change rides), so every use on the canvas answers per
     * input event while the store keeps the committed value. A null value
     * releases the preview: the property returns to the record's own
     * value. A satellite's preview arrives as a material-preview intent
     * (the canvas lives here); a record the store no longer holds
     * previews nothing.
     *
     * @param {?object} record
     *   The token or swatch record (delivery shape), or null.
     * @param {?(string|object)} value
     *   The draft value in the record's shape (a string or a
     *   condition-keyed map), or null to release.
     */
    previewMaterial: (record, value) => {
      if (!record) {
        return;
      }
      if (value === null || value === undefined) {
        delivery.restore(record);
        return;
      }
      const property = customProperty(record);
      if (property !== null) {
        preview.stageScoped(property, value);
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
     * The styling SUBJECT facet ({key, selector}: a part, tag tier or
     * chain under the selected element), or null for the whole element.
     * Spine state: its key is always getSelected(), and any selection
     * gesture clears it. Where a write lands is getAddress().
     */
    getTarget: () => app.Selection.getTarget(),

    /**
     * The styling WRITE ADDRESS ({key, selector}: a rule on the selected
     * element or one of its ancestors the rows write at while they keep
     * reading the subject), or null for the subject's own key. Spine
     * state: any selection gesture and any facet write clears it.
     */
    getAddress: () => app.Selection.getAddress(),

    /**
     * Selects a selectable key on the canvas (the page tree's row click,
     * the subject chip's way back): a bare element, which drops any
     * subject facet and write address, the spine's own invariant.
     *
     * Routed through the source so the satellite's tree drives the same
     * spine by intent; the change comes back through onSelectionChange /
     * onTargetChange. The selection spine stays the one authority, this
     * is just its programmatic face.
     *
     * A panel gesture, so the canvas reveals the element by the one
     * reveal rule (Drupal.Daedalus.scrollReveal, daedalus_ui/scroll_reveal)
     * the way the page elements row and the breadcrumb do; the satellite's
     * select intent settles through this seam, so a pop-out click scrolls
     * the leader's canvas too.
     */
    select: (key) => {
      app.Selection.select(key);
      Drupal.Daedalus.scrollReveal(app.Selection.getElement(key), {
        behavior: 'smooth',
      });
    },

    /**
     * Sets the styling SUBJECT facet on the current selection (a
     * code-mode row header seating a chain, a tree part row, the chip's
     * box doorway): the spine canonicalizes the selector and refuses a
     * key other than the selection (a rule on an ancestor is an address,
     * never a facet). A facet write drops any standing address. The
     * change comes back through onTargetChange.
     */
    target: (key, selector) => app.Selection.target(key, selector),

    /**
     * Sets the styling WRITE ADDRESS under the current subject (the
     * inspector's address switcher, provenance's "Edit at", a code-mode
     * rule header): the spine canonicalizes the selector and refuses a
     * key that is neither the selection nor an ancestor of it;
     * address(null) drops it. The change comes back through
     * onTargetChange.
     */
    address: (key, selector = null) => app.Selection.address(key, selector),

    /**
     * Subscribes to selection KEY changes; returns unsubscribe. Fires when
     * the selected element changes, never on a target change under the
     * same key, so element-scoped clear-on-change consumers keep meaning
     * "different element".
     */
    onSelectionChange: (callback) => app.onSelectionChange(callback),

    /**
     * Subscribes to the selection and its two styling facts — the
     * target-aware counterpart of onSelectionChange: fires on a
     * selection, facet OR address change, with ({selected, target,
     * address}, previous). Returns unsubscribe.
     */
    onTargetChange: (callback) => app.onTargetChange(callback),

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
     * The hovered selector facet ({key, selector} | null) — the selector
     * counterpart of getHovered(), spine state carrying its OWN owner key.
     */
    getHoveredSelector: () => app.Selection.getHoveredSelector(),

    /**
     * Drives the selector-hover facet (a code part header's mouseenter):
     * the canvas dashed paint and the tree part-row glow light up exactly
     * as if the pointer Cmd-crossed the part itself — one facet, every
     * surface. Deliberately never the element hover (a part header must
     * not light its owner's solid outline). hoverSelector(null) clears;
     * callers follow the rows' contract: clear on leave only while the
     * facet is still theirs.
     */
    hoverSelector: (key, selector = null) =>
      app.Selection.hoverSelector(key, selector),

    /**
     * Subscribes to selector-hover facet changes ({key, selector} | null,
     * value-compared); returns unsubscribe. Pointer-speed like
     * onHoverChange: consumers paint, not recompute.
     */
    onHoverSelectorChange: (callback) => app.onHoverSelectorChange(callback),

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
     * THE capability gate (daedalus_ui selection/capable.js): whether the
     * spine's published verdict on a key allows a capability. The
     * inspector asks style of the selection before mounting any write
     * control, in every mode; paste asks it at the target.
     */
    capable: (key, capability) => app.Selection.capable(key, capability),

    /**
     * The root's verdict (`_element.can` on the blueprint's root), or
     * null before the mirror boots: broadcast to the satellite, which
     * holds the lean tree but no root node, so it can answer the gate for
     * the root and the host's own fields.
     */
    getRootCan: () => app.Blueprint.getBlueprint()?._element?.can ?? null,

    /**
     * The root's three minted words (`_element.type`, `name` and `icon`
     * on the blueprint's root: the annotator's spine, what the page row
     * reads like every element row reads its node's), or null before the
     * mirror boots. Broadcast to the satellite with the lean tree, which
     * holds the layout elements alone and no root node.
     */
    getRootElement: () => {
      const element = app.Blueprint.getBlueprint()?._element ?? null;
      return element
        ? {
            type: element.type ?? null,
            name: element.name ?? null,
            icon: element.icon ?? null,
          }
        : null;
    },

    /**
     * Component uuid → inline block type (null for plugin blocks), from the
     * blueprint's tree — nested containers included.
     */
    getBlockTypes: () => blockTypes(),

    /**
     * Section uuid → layout plugin id, from the blueprint's tree — nested
     * containers included. The section counterpart of getBlockTypes():
     * what per-layout guide declarations (guide.layouts) resolve by.
     */
    getSectionLayouts: () => sectionLayoutTable(),

    /**
     * The blueprint's lean node tree (`layout`, kind-discriminated
     * container, region and block nodes carrying the `_element`
     * annotations): the ONE structure every stylish reader walks — the
     * selector matcher (ancestor layers in the stack, structural facts,
     * a rule's actual targets), composition capture (a section's
     * children into slot keys, at any nesting depth), the depth and
     * provenance reads, the code view's page tree. Broadcast to the
     * satellite with the rest of the session state.
     */
    getTree: () => app.Blueprint.getBlueprint()?.layout ?? [],

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
     * First match only: a multi-match selector's placeholder comes from
     * the first match (the bands loop every match themselves). The
     * content size is the border-box rect minus borders and padding —
     * the diagram's center readout. A generated box (`&::before`,
     * `.quote::after`) reads through its first host with
     * getComputedStyle's pseudo argument (computed-read.js: the box has
     * no node, the hosts are what resolve), and carries no content
     * size: no rect exists for a box, so the readout withholds rather
     * than report the host's. The read carries the canvas's measured
     * bases beside the values (computed-read.js lengthBases: the root's
     * font size for rem, the target's own for em), so a px placeholder
     * converts into the panel's unit at this page's real root.
     *
     * @param {string} key
     *   The style target's selectable key.
     * @param {?string} selector
     *   A canonical relative selector ('.quote', '> pb-text',
     *   '&::before'), or null for the element.
     * @param {string[]} properties
     *   The computed properties to read.
     *
     * @return {?{values: Object<string, string>,
     *   bases: {rootPx: ?number, fontPx: ?number},
     *   content: ?{width: number, height: number}}}
     *   null when the target has no canvas element (widgets degrade to
     *   placeholder-less rendering rather than guess); `content` null
     *   for a generated box.
     */
    readCanvas: (key, selector, properties) => {
      const matches = resolveTargets(key, selector);
      if (!matches.length) {
        return null;
      }
      const element = matches[0];
      const pseudo = pseudoOf(selector);
      if (pseudo !== null) {
        return {
          values: readComputed(element, properties, pseudo),
          bases: lengthBases(element, pseudo),
          content: null,
        };
      }
      const computed = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const border = borderPx(computed);
      const padding = paddingPx(computed);
      return {
        values: readComputed(element, properties),
        bases: lengthBases(element),
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
     * @param {?string} selector
     *   A canonical relative selector ('.quote'), or null for the element.
     *
     * @return {boolean}
     *   Whether the target had a canvas element to replay (false is the
     *   honest no-op, the same rule as a missing element on readCanvas).
     */
    replayMotion: (key, selector = null) => {
      const matches = resolveTargets(key, selector);
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
     * The active skin's defaults as delivered since boot, or null while
     * the boot payload (guide.skinDefaults) still tells the truth.
     */
    getSkinDefaults: () => skinDefaults,

    /**
     * Subscribes to skin defaults deliveries; returns unsubscribe.
     */
    onSkinDefaultsChange: (callback) => {
      skinDefaultsListeners.add(callback);
      return () => skinDefaultsListeners.delete(callback);
    },

    /**
     * Refreshes the skin's delivery after a push of its defaults.
     *
     * The skin session's landing (push-bar.js commitSession): the
     * server's current answer for the active skin (skins-api.js
     * fetchDefaults) lands twice here, the defaults map on this source
     * (announced to both windows' panels, whose stacks then read the
     * pushed document as the skin's) and the CSS on the canvas through
     * the preview's defaults delivery tag, so every element of the
     * pushed scope restyles before any reload. A satellite's push
     * arrives as a refresh-defaults intent (the canvas lives here).
     *
     * @return {Promise<object>}
     *   The delivered defaults map.
     */
    refreshDefaults: () =>
      fetchDefaults().then(({ defaults, css }) => {
        skinDefaults = defaults ?? null;
        preview.deliverDefaults(typeof css === 'string' ? css : '');
        log('skin', 'defaults delivered', skinDefaults);
        announceSkinDefaults();
        return skinDefaults;
      }),

    /**
     * The current canvas highlight: null, or {key, selector, properties}.
     */
    getCanvasHighlight: () => canvasHighlight,

    /**
     * Sets (or clears, with null) the canvas highlight.
     *
     * The spec names a style target (selectable key + optional relative
     * selector) plus the PROPERTIES being edited (['padding-top'], or
     * several under spread modifiers) — never how to paint them; the
     * painter registry maps each property to its tint, and painterless
     * properties no-op silently. Widgets set it on hover/scrub/
     * editor-open and clear it on leave/close; deselection clears it
     * automatically.
     *
     * @param {?{key: string, selector: ?string, properties: string[]}} spec
     */
    setCanvasHighlight: (spec) => {
      const next = spec
        ? {
            key: spec.key,
            selector: spec.selector ?? null,
            properties: spec.properties ?? [],
          }
        : null;
      if (
        (next === null && canvasHighlight === null) ||
        (next !== null &&
          canvasHighlight !== null &&
          next.key === canvasHighlight.key &&
          next.selector === canvasHighlight.selector &&
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
