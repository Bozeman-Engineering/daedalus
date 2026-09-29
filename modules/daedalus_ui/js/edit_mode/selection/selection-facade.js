import store from '../store/store';
import {
  setHover,
  setHoverSelector,
  setSelected,
  setTarget,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setDrill,
  clearSelection,
} from '../store/slices/selection-slice';
import { elementForKey } from './resolver';
import {
  enqueueOperations,
  enqueueJob,
  isDraining,
  drained,
  applyResponse,
} from '../ops/op-queue';
import { reportMutationFailure } from '../ops/failure-report';
import { preserveScroll } from '../ops/preserve-scroll';
import { labelForKey, targetLabel } from './label-for-key';
import { projectionIndex } from '../components/page-elements/projection-index';
import { typeForKey } from './type-for-key';
import { isFormOnlyStub } from '../blueprint/form-only-stub';
import { componentForUuid } from '../blueprint/component-for-uuid';
import { markupForKey, partPresence, partTag } from '../blueprint/markup-index';
import { resolveElement } from '../blueprint/element-resolver';
import { warnMissingEntityUi } from '../utilities/warn';
import { selectRoster } from '../store/slices/materials-slice';
import { MATERIAL_KINDS, scopesPair } from '../materials/roster';
import { canonicalSelector } from '../materials/relative-selector';
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
 * Non-app consumers (the AI chat panel, module JS that has not yet folded
 * into the app, third-party modules) read and drive selection through
 * this facade instead of touching the store. Keys are selectable keys
 * (data-selectable attribute values).
 */
/**
 * The relative selector a public write carries, canonicalized through the
 * grammar twin (relative-selector.js): null for an element-only write,
 * else the one canonical spelling the projection index addresses and
 * style-map keys carry. Text outside the grammar is a caller bug, never
 * an address to seat: it throws rather than committing one nothing can
 * resolve.
 */
const relativeSelector = (selector) => {
  if (selector === null || selector === undefined) {
    return null;
  }
  const canonical = canonicalSelector(selector);
  if (canonical === null) {
    throw new TypeError(
      `"${selector}" is not a relative selector (a part as '.cls', the children as '> *', a type as '> pb-text', a tag as 'p').`,
    );
  }
  return canonical;
};

/**
 * Whether `key` is an element-tier ancestor of `of` in the projection
 * index (the panel's own nesting, parts transparent). Unknown keys have
 * no ancestry: a key the index cannot place, or no index yet, answers
 * false.
 */
const isAncestor = (index, key, of) => {
  let cursor = index?.get(of)?.parent ?? null;
  while (cursor) {
    if (cursor === key) {
      return true;
    }
    cursor = index.get(cursor)?.parent ?? null;
  }
  return false;
};

export const initSelectionFacade = () => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * The seam every canvas replacer runs its DOM removal through
   * (ops/preserve-scroll.js): a canvas subtree swap never moves the window.
   */
  Drupal.Daedalus.preserveScroll = preserveScroll;

  Drupal.Daedalus.Selection = {
    /**
     * The committed selection's selectable key, or null.
     */
    getSelected: () => store.getState().selection.selectedKey,

    /**
     * The styling target, or null when styling acts on the selected
     * element itself: {key, selector}, the address every styling gesture
     * acts at (`${key}::${selector}` is its style-map key). Four shapes:
     * a declared part of the selection ({selected, '.cls'}), a tag tier
     * of it ({selected, 'p'}), a chain seated from code mode ({selected,
     * '> pb-text .quote'}), or an ancestor rule reaching it ({ancestor
     * key, '> pb-text'}). Its key is always getSelected() or an ancestor
     * of it, and it never outlives the selection: every selection write
     * clears it.
     */
    getTarget: () => store.getState().selection.target,

    /**
     * The hovered (would-select) selectable key, or null.
     */
    getHovered: () => store.getState().selection.hoverKey,

    /**
     * The hovered selector as {key, selector}, or null. It carries its own
     * owner key because selector hover need not ride element hover: a tree
     * part-row hover lights a part without touching getHovered().
     */
    getHoveredSelector: () => store.getState().selection.hoverSelector,

    /**
     * Programmatically drives the transient hover — the same state the
     * canvas resolver and the page-elements rows write, so every hover
     * surface (canvas outline, panel row, any subscribed panel) follows
     * one key. Callers mirror the panel rows' contract: set on enter,
     * and on leave clear only a hover you set (getHovered() still yours),
     * never clobber another surface's. Pass a selector to hover an
     * address under the element too (canvas Cmd-hover's shape, a part as
     * '.cls'); a bare key clears the selector facet. The selector is
     * canonicalized through the grammar, and text outside it throws.
     */
    hover: (key, selector = null) =>
      store.dispatch(setHover({ key, selector: relativeSelector(selector) })),

    /**
     * Drives ONLY the selector-hover facet, leaving the element hover
     * alone — the shape of a tree part-row hover, which lights the part
     * without lighting the owner element's hover outline. Pass the
     * selector's owner key with it; hoverSelector(null) clears. The
     * selector is canonicalized through the grammar, and text outside it
     * throws.
     */
    hoverSelector: (key, selector = null) => {
      const canonical = relativeSelector(selector);
      store.dispatch(
        setHoverSelector(key && canonical ? { key, selector: canonical } : null),
      );
    },

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
     * The set collapses to this one key, and the styling target clears: a
     * selection is an element identity, and a target is only ever set
     * explicitly on top of it (target()). Re-selecting the selected key
     * is the gesture that drops a target back to the whole element.
     */
    select: (key) => store.dispatch(setSelected(key)),

    /**
     * Sets the styling target on the current single selection: `key` is
     * the selected element (a part as '.cls', a tag tier as 'p', a chain
     * as '> pb-text .quote') or one of its ancestors per the projection
     * index's ancestry (an ancestor rule as '> pb-text', the inspector's
     * switcher). The selector is canonicalized through the grammar; text
     * outside it, a key that is neither the selection nor an ancestor,
     * or no single selection to target under, throws rather than seating
     * an address nothing can resolve. Whether the address is a member of
     * the element's address set (an ancestor rule that reaches it) is
     * the styling module's judgement, not the spine's.
     */
    target: (key, selector) => {
      const canonical = relativeSelector(selector);
      if (canonical === null) {
        throw new TypeError('A styling target needs a relative selector.');
      }
      const { selectedKey, selectedKeys } = store.getState().selection;
      if (!selectedKey || selectedKeys.length !== 1) {
        throw new TypeError(
          'A styling target needs a single selected element to belong to.',
        );
      }
      if (key !== selectedKey && !isAncestor(projectionIndex(), key, selectedKey)) {
        throw new TypeError(
          `Styling target key "${key}" is neither the selected element "${selectedKey}" nor an ancestor of it.`,
        );
      }
      store.dispatch(setTarget({ key, selector: canonical }));
    },

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
     * Clears selection, hover, drill, target and hover-selector state.
     */
    clear: () => store.dispatch(clearSelection()),

    /**
     * Subscribes to selection changes — KEY changes only, deliberately:
     * this fires when the selected ELEMENT changes, never on a styling
     * target change under the same key, so element-scoped consumers that
     * clear state on any fire (force-state stamps, highlight claims) are
     * not disturbed by target moves. Target-aware consumers subscribe to
     * onTargetChange instead.
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
     * Subscribes to the selection and its styling target — the
     * target-aware counterpart of onChange: fires when the selected
     * element OR the target changes (the target value-compared on its
     * key and selector), so styling surfaces (canvas selector paint,
     * tree part rows, the inspector, code mode) track the address from
     * one subscription.
     *
     * @param {Function} callback
     *   Called with ({selected, target}, {selected, target}) — current
     *   then previous; selected is the selected key (null for none),
     *   target is {key, selector} or null.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onTargetChange: (callback) => {
      const read = () => {
        const { selectedKey, target } = store.getState().selection;
        return { selected: selectedKey, target };
      };
      let previous = read();
      return store.subscribe(() => {
        const current = read();
        if (
          current.selected !== previous.selected ||
          current.target?.key !== previous.target?.key ||
          current.target?.selector !== previous.target?.selector
        ) {
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
     * Subscribes to selector-hover changes — the transient selector facet
     * the selector paint surfaces render from. Value-compared (owner key +
     * selector), so re-asserting the same selector does not fire. Like
     * onHoverChange, fires at pointer speed; consumers should paint, not
     * recompute.
     *
     * @param {Function} callback
     *   Called with (hoverSelector, previousHoverSelector) — each
     *   {key, selector} or null.
     *
     * @return {Function}
     *   Unsubscribe.
     */
    onHoverSelectorChange: (callback) => {
      let previous = store.getState().selection.hoverSelector;
      return store.subscribe(() => {
        const current = store.getState().selection.hoverSelector;
        if (
          current?.key !== previous?.key ||
          current?.selector !== previous?.selector
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
   * The mutation entry point for verbs, sub-modules, and third parties: queue a
   * blueprint operation batch against the endpoint and have the response
   * (markup swaps, assets, commands) applied to the page.
   */
  Drupal.Daedalus.Blueprint = {
    mutate: enqueueOperations,

    /**
     * Queues a write that answers the mutation-response contract by its
     * own transport (daedalus_edit's form commit: the panel form's AJAX
     * submit, whose response carries the payload as one
     * daedalusBlueprintApplyResponse command). The job runs FIFO with the
     * ops around it and resolves with the applied payload, so a form
     * commit and a spine op never overtake each other.
     */
    enqueueJob,
    isDraining,

    /**
     * Resolves once nothing is queued or in flight: the wait Save takes
     * before navigating to the save route, so a commit still on the wire
     * lands in tempstore first.
     */
    drained,

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
     * The words for a styling TARGET's selector ("every link", "Raster"):
     * the label resolver's sibling seam (label-for-key.js targetLabel),
     * the styling module's vocabulary where one registered a label seam,
     * the raw selector otherwise. The breadcrumb's target crumb and the
     * AI chat's target chip both read it here, so a target is worded one
     * way everywhere.
     */
    targetLabel,

    /**
     * The TYPE name for a selectable key: what kind of thing it is
     * ("Text", "Section", "Body"), never what it says. The label
     * resolver's sibling read of the same projection (type-for-key.js):
     * the server-minted `_element.type` the projection carries on the
     * node. Never null: the selection frame's canvas chips read THIS
     * resolver — the canvas already shows the content, so its chip speaks
     * type — and any other surface wanting type wording reads it here,
     * never re-deriving.
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
