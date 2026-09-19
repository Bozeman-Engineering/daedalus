import store from '../store/store';
import {
  setHover,
  setHoverPart,
  setSelected,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setDrill,
  clearSelection,
} from '../store/slices/selection-slice';
import { elementForKey } from './resolver';
import { enqueueOperations, isDraining, applyResponse } from '../ops/op-queue';
import { reportMutationFailure } from '../ops/failure-report';
import { labelForKey } from './label-for-key';
import { typeForKey } from './type-for-key';
import { isFormOnlyStub } from '../blueprint/form-only-stub';
import { componentForUuid } from '../blueprint/component-for-uuid';
import { markupForKey, partPresence, partTag } from '../blueprint/markup-index';
import { resolveElement } from '../blueprint/element-resolver';
import { warnMissingEntityUi } from '../utilities/warn';
import { selectRoster } from '../store/slices/materials-slice';
import { MATERIAL_KINDS, scopesPair } from '../materials/roster';
import {
  KIND,
  SLOT,
  attrSlot,
  slotAttribute,
  slotScope,
  isSlot,
  isMaterialKind,
  isInlineKind,
  resolveValue,
  references,
  canonical,
  spell,
  spellMedia,
  spellGenerate,
} from '../materials/grammar';

/**
 * The public face of selection and mutation for code outside the app.
 *
 * Non-app consumers — the AI chat panel, tool JS that has not yet folded
 * into the app, third-party modules — read and drive selection through
 * this facade instead of touching the store. Keys are selectable keys
 * (data-selectable attribute values).
 */
export const initSelectionFacade = () => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  Drupal.Daedalus.Selection = {
    /**
     * The committed selection's selectable key, or null.
     */
    getSelected: () => store.getState().selection.selectedKey,

    /**
     * The committed selection's part facet — the part id sub-selected on
     * the selected element, or null. Its owner is always getSelected();
     * the facet never outlives its key (any element-only selection
     * gesture clears it).
     */
    getSelectedPart: () => store.getState().selection.selectedPart,

    /**
     * The hovered (would-select) selectable key, or null.
     */
    getHovered: () => store.getState().selection.hoverKey,

    /**
     * The hovered part as {key, part}, or null. It carries its own owner
     * key because part hover need not ride element hover: a tree part-row
     * hover lights a part without touching getHovered().
     */
    getHoveredPart: () => store.getState().selection.hoverPart,

    /**
     * Programmatically drives the transient hover — the same state the
     * canvas resolver and the page-elements rows write, so every hover
     * surface (canvas outline, panel row, any subscribed panel) follows
     * one key. Callers mirror the panel rows' contract: set on enter,
     * and on leave clear only a hover you set (getHovered() still yours),
     * never clobber another surface's. Pass a part to hover a part of the
     * element too (canvas Cmd-hover's shape); a bare key clears the part
     * facet.
     */
    hover: (key, part = null) => store.dispatch(setHover({ key, part })),

    /**
     * Drives ONLY the part-hover facet, leaving the element hover alone —
     * the shape of a tree part-row hover, which lights the part without
     * lighting the owner element's hover outline. Pass the part's owner
     * key with it; hoverPart(null) clears.
     */
    hoverPart: (key, part = null) =>
      store.dispatch(setHoverPart(key && part ? { key, part } : null)),

    /**
     * The DOM element currently carrying a selectable key, or null.
     */
    getElement: (key) => elementForKey(key),

    /**
     * The ordered multi-select set; single-select is a set of one.
     * The primary (getSelected()) is always a member.
     */
    getSelectedSet: () => [...store.getState().selection.selectedKeys],

    /**
     * Programmatically selects a selectable key (e.g. from a panel row).
     * The set collapses to this one key. Pass a part to sub-select a part
     * of the element; omitting it clears any part facet (a part is only
     * ever selected explicitly).
     */
    select: (key, part = null) => store.dispatch(setSelected({ key, part })),

    /**
     * Toggles a key's membership in the multi-select set — the
     * programmatic face of Shift+click (canvas) / Cmd+click (panel).
     * Adding makes the key the primary.
     */
    toggle: (key) => store.dispatch(toggleSelected(key)),

    /**
     * Removes a key from the set if present (e.g. a chat chip dismiss);
     * a no-op otherwise.
     */
    deselect: (key) => store.dispatch(removeSelected(key)),

    /**
     * Replaces the whole set. The primary defaults to the last key; pass
     * one of the keys to make it the primary.
     */
    selectSet: (keys, primary = null) =>
      store.dispatch(setSelectedSet({ keys, primary })),

    /**
     * The drill context key, or null at the page root.
     */
    getDrill: () => store.getState().selection.drillKey,

    /**
     * Sets the drill context — double-click's programmatic face. Hover and
     * click (and so Selector drag arming) resolve one level inside it.
     */
    drill: (key) => store.dispatch(setDrill(key)),

    /**
     * Clears selection, hover, drill, and part-facet state.
     */
    clear: () => store.dispatch(clearSelection()),

    /**
     * Subscribes to selection changes — KEY changes only, deliberately:
     * this fires when the selected ELEMENT changes, never on a part-facet
     * change under the same key, so element-scoped consumers that clear
     * state on any fire (force-state stamps, highlight claims) are not
     * disturbed by part drills. Part-aware consumers subscribe to
     * onPartChange instead.
     *
     * @param {Function} callback
     *   Called with (selectedKey, previousKey) whenever the committed
     *   selection changes.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onChange: (callback) => {
      let previous = store.getState().selection.selectedKey;
      return store.subscribe(() => {
        const current = store.getState().selection.selectedKey;
        if (current !== previous) {
          const last = previous;
          previous = current;
          callback(current, last);
        }
      });
    },

    /**
     * Subscribes to the committed (key, part) pair — the part-aware
     * counterpart of onChange: fires when the selected element OR its part
     * facet changes, so part surfaces (canvas part paint, tree part rows,
     * inspector target, code mode) track sub-selection from one
     * subscription.
     *
     * @param {Function} callback
     *   Called with ({key, part}, {key, part}) — current then previous;
     *   part is null for an element-only selection, key is null for none.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onPartChange: (callback) => {
      const read = () => {
        const { selectedKey, selectedPart } = store.getState().selection;
        return { key: selectedKey, part: selectedPart };
      };
      let previous = read();
      return store.subscribe(() => {
        const current = read();
        if (current.key !== previous.key || current.part !== previous.part) {
          const last = previous;
          previous = current;
          callback(current, last);
        }
      });
    },

    /**
     * Subscribes to hover changes — the transient would-select key every
     * hover surface renders from. Fires at pointer speed (every boundary
     * crossing), so consumers should paint, not recompute.
     *
     * @param {Function} callback
     *   Called with (hoverKey, previousKey) whenever the hover changes;
     *   either may be null.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onHoverChange: (callback) => {
      let previous = store.getState().selection.hoverKey;
      return store.subscribe(() => {
        const current = store.getState().selection.hoverKey;
        if (current !== previous) {
          const last = previous;
          previous = current;
          callback(current, last);
        }
      });
    },

    /**
     * Subscribes to part-hover changes — the transient part facet the part
     * paint surfaces render from. Value-compared (owner key + part id), so
     * re-asserting the same part does not fire. Like onHoverChange, fires
     * at pointer speed; consumers should paint, not recompute.
     *
     * @param {Function} callback
     *   Called with (hoverPart, previousHoverPart) — each {key, part} or
     *   null.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onHoverPartChange: (callback) => {
      let previous = store.getState().selection.hoverPart;
      return store.subscribe(() => {
        const current = store.getState().selection.hoverPart;
        if (
          current?.key !== previous?.key ||
          current?.part !== previous?.part
        ) {
          const last = previous;
          previous = current;
          callback(current, last);
        }
      });
    },

    /**
     * Subscribes to multi-select SET changes; fires on any
     * membership or order change, including ones that leave the primary
     * untouched (this is what live-following consumers like the AI chat
     * chips read).
     *
     * @param {Function} callback
     *   Called with (keys, previousKeys) — both plain arrays.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onSetChange: (callback) => {
      let previous = store.getState().selection.selectedKeys;
      return store.subscribe(() => {
        const current = store.getState().selection.selectedKeys;
        if (current !== previous) {
          const last = previous;
          previous = current;
          callback([...current], [...last]);
        }
      });
    },
  };

  /**
   * The mutation entry point for verbs, tools, and third parties: queue a
   * blueprint operation batch against the endpoint and have the response
   * (markup swaps, assets, commands) applied to the page.
   */
  Drupal.Daedalus.Blueprint = {
    mutate: enqueueOperations,
    isDraining,

    /**
     * Applies a mutation-response payload ({blueprint, markup, targets,
     * attachments, drupal_commands}) to the page through the same pipeline
     * every queued mutation rides: mirror replacement, assets, subtree
     * swaps, then piggybacked commands. For consumers whose payloads arrive
     * OUTSIDE the op queue — the AI chat turn's injected responses and the
     * deferred-completion endpoint — so every mutation path converges on
     * one application of the one contract.
     *
     * @param {Object} payload
     *   A mutation-response payload.
     *
     * @return {Promise<void>}
     *   Resolves when the payload has been applied.
     */
    applyResponse,

    /**
     * The one mutation-failure display: flash the reverted element,
     * notify with the structured error, count the rolled-back tail. Pass
     * mutate() rejections here with the failed target's selectable key (or
     * element); quiet tail rejections are filtered internally.
     */
    reportFailure: reportMutationFailure,

    /**
     * The blueprint mirror: the full blueprint of the route's main entity,
     * or null before it boots. Read-only — it is replaced by the blueprint
     * half of every mutation response; consumers project, never write.
     */
    getBlueprint: () => store.getState().blueprint.blueprint,

    /**
     * Whether a mirror field value is a form-only stub, identity without
     * a value. The server treats a stub arriving in a write as
     * "leave this field alone", so consumers reading field values from the
     * mirror must treat a stub as "no value", never send one back, and
     * never spread one into an outgoing value.
     */
    isFormOnlyStub,

    /**
     * Finds a component node in a blueprint by its component uuid, at any
     * nesting depth. Pass getBlueprint() (or a mutation response's
     * blueprint) plus the `block:{uuid}` key's uuid segment.
     */
    componentForUuid,

    /**
     * The mirror's root-identity envelope, or null before it boots:
     * {entityType, entityId, uuid, label, langcode, viewMode}.
     */
    getRoot: () => store.getState().blueprint.root,

    /**
     * The `_element.markup` annotation for a selectable key, or null when
     * the model does not speak for it (markup-index.js): the canonical
     * instance model, what THIS element is rendering as, decided from
     * entity data at serialize time. Third parties (daedalus_stylish's
     * layout-context facts, instance-part labels) read markup through
     * here; the canvas DOM is a projection to verify against, never a
     * source.
     */
    markupForKey,

    /**
     * Whether a declared part selector (or minted instance class) is
     * present on a key's element, from the markup annotation
     * (markup-index.js): true present (hoisted counts, part-is-root IS
     * present), false positively absent, null when the model does not
     * speak (consumers degrade to the declared sketch).
     */
    partPresence,

    /**
     * The resolved tag one declared part rendered as, from the markup
     * annotation (markup-index.js): the part's own tag when it is its own
     * element, the root's tag when it hoisted (the part IS the root
     * there), null when the model does not speak or positively says
     * absent. The honest source for per-form facts a multi-form part's
     * presence cannot answer — the image editor's raster/vector split
     * (img vs svg) reads through here.
     */
    partTag,

    /**
     * The element resolver's client twin (blueprint/element-resolver.js):
     * (declaration, data facade, depth) -> the concrete element tree, the
     * SAME computation the server render and the `_element.markup`
     * annotation ride, locked to the PHP resolver by the round-trip
     * equivalence test. Editors resolve a gesture's previewed property bag
     * through this and morph the canvas DOM to the answer, so the live
     * preview cannot drift from what commits. See the module docblock for
     * the data facade's contract.
     */
    resolveElement,

    /**
     * A block type's effective declaration as delivered to the client
     * (drupalSettings.daedalusUi.anatomy, built by ClientAnatomy) —
     * the declaration input resolveElement() consumes. Null when the type
     * declares no markup model (or the anatomy payload is not on this
     * page); callers treat null as "the model does not speak", never as
     * an empty declaration.
     */
    blockTypeDeclaration: (blockType) =>
      drupalSettings?.daedalusUi?.anatomy?.blockTypes?.[blockType] ?? null,

    /**
     * The display label for a selectable key: the panel projection's label
     * when the key has a blueprint node, the spine vocabulary's fallback
     * otherwise (foreign entities, rows absorbed by a merged
     * presentation). Never null: the ONE resolver (label-for-key.js) the
     * indicator, keyboard traversal, the AI chat's chips, and the canvas
     * chrome all read, so no surface re-derives its own wording.
     */
    labelForKey,

    /**
     * The TYPE name for a selectable key: what kind of thing it is
     * ("Text", "Section", "Body"), never what it says. The label
     * resolver's sibling read of the same projection (type-for-key.js):
     * the node's kind plus the blueprint's bundle. Never null: the
     * selection frame's canvas chips read THIS resolver — the canvas
     * already shows the content, so its chip speaks type — and any other
     * surface wanting type wording reads it here, never re-deriving.
     */
    typeForKey,

    /**
     * Subscribes to mirror replacements.
     *
     * @param {Function} callback
     *   Called with the new blueprint whenever the mirror is replaced.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onBlueprintChange: (callback) => {
      let previous = store.getState().blueprint.blueprint;
      return store.subscribe(() => {
        const current = store.getState().blueprint.blueprint;
        if (current !== previous) {
          previous = current;
          callback(current);
        }
      });
    },

    /**
     * The page's root-entity mutation context, or null off editable pages.
     *
     * Read from the main entity wrapper's attribution, so callers can spread
     * it straight into mutate(): the operations target the root entity and
     * the response re-renders the view mode the canvas displays.
     *
     * @return {?{entityType: string, entityId: string, viewMode: string}}
     */
    rootContext: () => {
      const root = document.querySelector('[data-main-entity]');
      if (!root) {
        warnMissingEntityUi('Selection.rootContext', '[data-main-entity]');
        return null;
      }
      return {
        entityType: root.getAttribute('data-entity-type'),
        entityId: root.getAttribute('data-entity-id'),
        viewMode: root.getAttribute('data-daedalus-ui-view-mode') ?? 'full',
      };
    },
  };

  /**
   * The materials store's read face and the value grammar, for every
   * widget that offers, wears, spells or lints a material: pickers, wells,
   * gradient stops, the wear bar, the palette, code completions and lint,
   * daedalus_layout's stencil palette. Records are in the delivery shape
   * (MaterialRecord::toArray()); the roster order is the server's. The
   * store boots with the page (materials/materials-store.js) and follows
   * every mutation response's delta, so a read here is never stale within
   * a session and nothing fetches. Read-only: mints go through the op
   * queue and the gesture routes, whose responses carry the delta back.
   */
  const roster = () => selectRoster(store.getState());
  Drupal.Daedalus.Materials = {
    /**
     * The material kinds, roster order.
     */
    KINDS: MATERIAL_KINDS,

    /**
     * Whether the page-load payload has booted the store. Before boot (a
     * page without the payload) every read answers empty.
     */
    isBooted: () => store.getState().materials.booted,

    /**
     * The roster index over the current record set (materials/roster.js),
     * for consumers that hold one for a batch of lookups; its identity
     * changes exactly when the records do.
     */
    getRoster: roster,

    /**
     * Every record, roster order.
     */
    all: () => roster().all(),

    /**
     * The records of one kind, roster order.
     */
    ofKind: (kind) => roster().ofKind(kind),

    /**
     * One record by kind and id, or null.
     */
    get: (kind, id) => roster().get(kind, id),

    has: (kind, id) => roster().has(kind, id),

    /**
     * The record spelled exactly this way in a slot ('style', 'class',
     * 'stencil', 'animation', 'attr:{scope}:{name}'), or null.
     */
    bySpelling: (slot, spelling) => roster().bySpelling(slot, spelling),

    /**
     * Every spelling in a slot, {spelling: record}.
     */
    spellings: (slot) => roster().spellings(slot),

    /**
     * The ONE applicability predicate ("may this element wear this
     * material", "may this stencil land here"), shared with the server
     * (MaterialsRoster::applicable()), bound to the store's roster: a
     * stencil's band fact resolves against the variant records it holds.
     */
    applicable: (record, elementScope, topLevel = true, withinStencil = null) =>
      roster().applicable(record, elementScope, topLevel, withinStencil),
    scopesPair,

    /**
     * The value grammar's client twin (materials/grammar.js), bound to the
     * store's roster: what a value in a slot IS ({kind, id, spelling,
     * record}), every reference inside it, its canonical respelling, and
     * how to spell a record or an inline media/generate value. Pass a
     * roster explicitly to resolve against another record set.
     */
    KIND,
    SLOT,
    attrSlot,
    slotAttribute,
    slotScope,
    isSlot,
    isMaterialKind,
    isInlineKind,
    resolveValue: (value, slot, against = roster()) =>
      resolveValue(value, slot, against),
    references: (value, slot, against = roster()) =>
      references(value, slot, against),
    canonical: (value, slot, against = roster()) =>
      canonical(value, slot, against),
    spell,
    spellMedia,
    spellGenerate,

    /**
     * Subscribes to record-set changes: a boot, a delta from any lane
     * (this window's response, another window's relay).
     *
     * @param {Function} callback
     *   Called with the new roster index whenever the records change.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onChange: (callback) => {
      let previous = store.getState().materials.records;
      return store.subscribe(() => {
        const current = store.getState().materials.records;
        if (current !== previous) {
          previous = current;
          callback(roster());
        }
      });
    },
  };
};
