import store from '../store/store';
import {
  dragStarted,
  dragEnded,
  commitStarted,
  commitFinished,
  dropTargeted,
} from '../store/slices/drag-slice';
import { enqueueOperations } from '../ops/op-queue';
import { reportMutationFailure } from '../ops/failure-report';
import {
  resolveDropTarget,
  liveCaret,
  treeRowKeyCandidates,
  containerKeyForLocation,
  isRootFlowLocation,
  unitOf,
} from './gaps';
import { updateAutoScroll, stopAutoScroll } from './auto-scroll';
import {
  matchElementTarget,
  elementInterior,
  elementTargetAt,
} from './element-targets';
import { projectionIndex } from '../components/page-elements/projection-index';
import { representedChainKeys } from '../selection/resolver';
import * as builders from './ops-builders';

/**
 * The drag controller — one drag layer for every placement gesture.
 *
 * Palette drags, canvas moves, media file-drags, and tree-row drops are all
 * the same interaction: a drag source starts a drag, the caret shows the
 * one gap the cursor means, and the drop becomes a blueprint operation
 * batch through the op queue. Sources register a spec; the controller owns
 * target resolution, the drop pipeline, placeholder-materialize, and
 * failure display — a source only says what it targets and which
 * operations its drop means.
 *
 * Native HTML5 drag-and-drop drives the gesture: the sources' elements are
 * real draggables (drag image for free), dragover supplies the tracked
 * cursor, and Escape-cancel is the browser's own (dragend without a drop).
 * The document-level listeners engage only while a REGISTERED source's
 * drag is active, so foreign drags (OS file drags, text selections) pass
 * through untouched. Tray items (panel blocks, tree rows) are their own
 * draggables via makeDraggable; canvas elements drag through a delegated
 * ancestor instead (makeDragDelegate) — an `<svg>` block root never fires
 * dragstart no matter what `draggable` says.
 *
 * Drop targets come in two KINDS, discriminated by target.kind:
 * - 'gap': insert between flow items (the caret line). Resolution,
 *   geometry, and grammar live in gaps.js.
 * - 'element': land ON a compatible element already on the page (drop a
 *   file onto an image to replace its media). Resolution and geometry
 *   live in element-targets.js; the caret draws the element face (a
 *   highlight over the landing element). A source opts in by declaring
 *   its element face; the element's interior claims the cursor, its edge
 *   bands fall through to gap resolution so the flow around it stays
 *   reachable.
 *
 * A drag source spec:
 * - targets: 'block' (region gaps, section gaps wrap in a new section) or
 *   'section' (section-flow gaps). Default 'block'.
 * - operations(target, data): the blueprint ops a GAP drop means. May be
 *   async; return null/[] to abort the drop. The standard builders are
 *   exported as Drag.builders.
 * - acceptsTarget(target, data): optional per-target filter — reject the
 *   gaps a source cannot drop into (e.g. a move onto itself) and the caret
 *   never offers them. Gap targets only; the element face filters with
 *   its own accepts.
 * - elements: optional — the source's element-target face:
 *   - selector: the elements this source can land on (closest() match
 *     from the cursor's target).
 *   - accepts(element, data): optional per-element filter (e.g. mime
 *     compatibility). Rejected elements fall through to gap resolution.
 *   - operations(target, data): what an element drop means. Same contract
 *     as the gap face — async allowed, returned blueprint ops ride the op
 *     queue — but a face whose work is not blueprint ops (the
 *     upload/replace pipeline) runs it here and returns null/[]: the
 *     committing chrome holds on the element until the promise settles.
 *   - label: optional chrome label on the targeted element — a string, or
 *     (element, data) => string.
 *   Element drops never materialize a placeholder and never run the
 *   optimistic move (nothing inserts).
 * - materialize: whether the drop inserts a placeholder skeleton at the
 *   gap until real markup lands (the new-markup class of drop). Default
 *   true; moves pass false, since their markup already exists on the page.
 * - optimistic: 'move' applies the drop optimistically (the moved-markup
 *   class of drop): the dragged data.element moves into the gap at
 *   enqueue and moves back if the server rejects the op. For sources whose
 *   markup already exists on the page; placeholder sources leave this
 *   unset.
 * - onComplete(response, target, data): optional hook after the mutation
 *   response has been applied to the page.
 */

const sources = new Map();

// The live drag: {type, spec, data}, or null.
let activeSource = null;
// The resolved drop target under the cursor, or null. Read per frame by
// the insertion caret (imperative, like the outline's geometry).
let currentDrop = null;
// The drop whose commit is holding the caret (in its committing style) at
// the drop point, or null. Keyed to the drop, not a flag: an optimistic
// commit releases the caret immediately, so a second commit can be in
// flight before the first one's response lands.
let committingDrop = null;

// Gap changes within this distance of the current caret line are ignored:
// the per-boundary dead zone that keeps the caret from flickering where
// containers meet.
const HYSTERESIS_PX = 12;

/**
 * Registers a drag source.
 *
 * @param {string} type
 *   The source type (e.g. 'new_block').
 * @param {Object} spec
 *   The source spec (see the file docblock).
 */
export const registerDragSource = (type, spec) => {
  sources.set(type, { targets: 'block', materialize: true, ...spec });
};

/**
 * A registered drag source's spec, or undefined. The keyboard lift
 * reads a source's targets/acceptsTarget to enumerate the gaps it may step
 * through.
 */
export const getDragSource = (type) => sources.get(type);

/**
 * Makes an element a drag handle for a registered source type.
 *
 * Handler properties (not addEventListener) keep repeated calls from a
 * re-attaching behavior idempotent.
 *
 * @param {Element} element
 *   The draggable element.
 * @param {string} type
 *   The registered source type.
 * @param {?Function} getData
 *   Maps the element to the drag data the source's operations() consumes.
 *   Called at dragstart. Defaults to an empty object.
 */
export const makeDraggable = (element, type, getData = null) => {
  element.setAttribute('draggable', 'true');
  element.ondragstart = (event) => {
    // Draggables nest (a movable block inside a movable section): the
    // browser starts the drag on the innermost draggable, but dragstart
    // bubbles, so an enclosing draggable's handler would overwrite
    // activeSource with its own drag. The source that started the gesture
    // owns it.
    event.stopPropagation();
    const spec = sources.get(type);
    if (!spec) {
      return;
    }
    activeSource = { type, spec, data: getData?.(element, event) ?? {} };
    // Some browsers only start a drag that carries data.
    event.dataTransfer.setData('text/plain', type);
    event.dataTransfer.effectAllowed = 'copyMove';
    store.dispatch(dragStarted({ sourceType: type, targets: spec.targets }));
  };
  element.ondragend = (event) => {
    event.stopPropagation();
    endDrag();
  };
};

/**
 * Arms an ancestor as a delegated drag handle for the elements it holds.
 *
 * Canvas elements cannot each be their own drag handle: an `<svg>` block
 * root never fires dragstart no matter what `draggable` says (Chrome 151
 * behaves this way), and inner native draggables (images, links) win
 * the innermost-draggable race against the element root. One armed HTML
 * ancestor solves both: the delegate carries the gesture for whatever
 * element resolve() names, and an inner native draggable's own dragstart
 * bubbles here and is REINTERPRETED as that element's drag — no
 * per-element `draggable`, no img guards, and every current and future
 * block form is a drag source by construction.
 *
 * The delegate's `draggable` attribute stays the CALLER's to toggle:
 * arming policy (which tool, which hover) is the consumer's domain, and a
 * draggable ancestor eats text-selection gestures, so it must be on only
 * while a drag could mean something (see selector-drag.js).
 *
 * Handler properties keep repeated calls idempotent, like makeDraggable.
 *
 * @param {Element} delegate
 *   The HTML ancestor carrying the draggable attribute and the dragstart.
 *   Must enclose every element the gesture can mean.
 * @param {Function} resolve
 *   (event) => {type, data, element} naming the drag the gesture means,
 *   or null when it means nothing. `type` is a registered source type,
 *   `data` rides to the source's operations(), and `element` (optional)
 *   becomes the drag image, anchored where the cursor grabbed it.
 */
export const makeDragDelegate = (delegate, resolve) => {
  // Whether the live gesture is one this delegate reinterpreted — a
  // foreign inner drag's bubbled dragend must not end a drag it never
  // owned.
  let owns = false;
  delegate.ondragstart = (event) => {
    owns = false;
    const resolved = resolve(event);
    const spec = resolved && sources.get(resolved.type);
    if (!spec) {
      // The gesture means nothing. The delegate itself must never drag
      // (the whole canvas as a ghost image); an inner native draggable's
      // own gesture stays a foreign drag and passes through, as ever.
      if (event.target === delegate) {
        event.preventDefault();
      }
      return;
    }
    event.stopPropagation();
    owns = true;
    activeSource = { type: resolved.type, spec, data: resolved.data ?? {} };
    event.dataTransfer.setData('text/plain', resolved.type);
    event.dataTransfer.effectAllowed = 'copyMove';
    // The delegate started the drag, so the browser would ghost the
    // delegate (or the inner draggable that bubbled); the drag image is
    // the element the gesture MEANS.
    if (resolved.element) {
      const rect = resolved.element.getBoundingClientRect();
      event.dataTransfer.setDragImage(
        resolved.element,
        event.clientX - rect.left,
        event.clientY - rect.top,
      );
    }
    store.dispatch(
      dragStarted({ sourceType: resolved.type, targets: spec.targets }),
    );
  };
  delegate.ondragend = (event) => {
    if (!owns) {
      return;
    }
    owns = false;
    event.stopPropagation();
    endDrag();
  };
};

/**
 * Removes a drag handle.
 */
export const removeDraggable = (element) => {
  element.removeAttribute('draggable');
  element.ondragstart = null;
  element.ondragend = null;
};

/**
 * Arms the drag layer for an externally-originated drag.
 *
 * An OS file drag never fires dragstart on an in-page draggable, so the
 * layer would ignore it (by design — foreign drags pass through). A
 * detector that recognizes the external gesture (file-drag's Files
 * dragenter) arms a registered source explicitly; from there the caret,
 * target resolution, and drop pipeline behave exactly as for a native
 * source drag.
 *
 * External drags have no dragend either: a drop ends the gesture in the
 * document drop handler, and the detector calls endExternalDrag() when
 * the drag leaves the window.
 *
 * @param {string} type
 *   A registered source type.
 * @param {Object} data
 *   Drag data for the source's operations(). The drop's files are only
 *   readable at drop time, so the drop handler adds them as data.files.
 *
 * @return {boolean}
 *   Whether the layer armed (false: unknown source or a drag is already
 *   active).
 */
export const beginExternalDrag = (type, data = {}) => {
  const spec = sources.get(type);
  if (!spec || activeSource) {
    return false;
  }
  activeSource = { type, spec, data, external: true };
  store.dispatch(dragStarted({ sourceType: type, targets: spec.targets }));
  return true;
};

/**
 * Ends an externally-armed drag (left the window / detector cancelled).
 *
 * A no-op for native source drags — their own dragend owns the end.
 */
export const endExternalDrag = () => {
  if (activeSource?.external) {
    endDrag();
  }
};

/**
 * The caret's per-frame read: the current drop target and phase.
 */
export const getCurrentDrop = () =>
  currentDrop
    ? { drop: currentDrop, committing: currentDrop === committingDrop }
    : null;

/**
 * Publishes the current drop's panel-row and container keys when the
 * drop's IDENTITY changes — the tree unfolds the path to the row (the
 * drop's twin of the selection's auto-unfold), which is what lets the
 * caret's panel face draw inside folded containers, and the selection
 * indicator shows the container's trail (the depth indicator). Called
 * wherever currentDrop is assigned; identity only, the per-frame geometry
 * stays module state.
 */
const publishDropRow = () => {
  const index = projectionIndex();
  // An element target addresses no insertion location; its panel identity
  // is the landing element's own projected row (the block being landed
  // on), and it inserts into no container.
  const rowKey =
    (currentDrop?.kind === 'element'
      ? representedChainKeys(currentDrop.element)[0]
      : currentDrop &&
        index &&
        treeRowKeyCandidates(currentDrop.location).find((candidate) =>
          index.has(candidate),
        )) ?? null;
  const containerKey =
    currentDrop && currentDrop.kind !== 'element'
      ? containerKeyForLocation(currentDrop.location)
      : null;
  const state = store.getState().drag;
  if (state.dropRowKey !== rowKey || state.dropContainerKey !== containerKey) {
    store.dispatch(dropTargeted({ rowKey, containerKey }));
  }
};

/**
 * Sets the drop target directly: the keyboard face of dragover.
 *
 * While a keyboard lift holds the (externally-armed) drag, arrow steps set
 * the caret's target here instead of deriving it from a cursor position;
 * the caret component reads it back through getCurrentDrop() exactly as it
 * does for pointer drags. Ignored when no drag is active.
 */
export const setCurrentDrop = (drop) => {
  if (activeSource) {
    currentDrop = drop;
    publishDropRow();
  }
};

/**
 * Commits the current drop and ends an externally-armed drag — the
 * keyboard lift's drop key. Mirrors the document drop handler's external
 * sequence: commitDrop() runs (the committing flag keeps the caret alive
 * through the in-flight mutation), then the gesture ends.
 *
 * @return {boolean}
 *   Whether a drop was committed.
 */
export const commitCurrentDrop = () => {
  if (!activeSource?.external || !currentDrop) {
    return false;
  }
  commitDrop();
  endDrag();
  return true;
};

/**
 * Resolves and stores the drop target for a cursor position.
 */
const updateDrop = (target, x, y) => {
  const { spec, data } = activeSource;
  // The current element target keeps its claim while the cursor is still
  // over it and not decisively in its edge band — the element face of the
  // gap dead zone below.
  if (
    currentDrop?.kind === 'element' &&
    currentDrop.element.isConnected &&
    matchElementTarget(target, spec, data) === currentDrop.element &&
    elementInterior(currentDrop.element, x, y, HYSTERESIS_PX)
  ) {
    return;
  }
  // Element targets resolve first: over a compatible element's interior
  // the drop means the element; everywhere else (its edge bands included)
  // the gap grammar owns the position.
  let next = elementTargetAt(target, x, y, spec, data);
  if (!next) {
    next = resolveDropTarget(target, x, y, spec.targets);
    if (next && spec.acceptsTarget && !spec.acceptsTarget(next, data)) {
      next = null;
    }
  }
  if (next && currentDrop && next.gapId !== currentDrop.gapId) {
    // Dead-zone: stay on the current gap while the cursor is still within
    // a few pixels of its line — boundary flicker insurance. (liveCaret is
    // null for element targets; their stickiness is handled above.)
    const line = liveCaret(currentDrop);
    if (line) {
      const distance =
        line.orientation === 'vertical'
          ? Math.abs(y - line.top)
          : Math.abs(x - line.left);
      if (distance < HYSTERESIS_PX) {
        return;
      }
    }
  }
  currentDrop = next;
  publishDropRow();
};

/**
 * A drop target's insertion point in unit terms: {parent, before}.
 *
 * Derived from the gap's neighboring items rather than drop.insert's raw
 * container guess, so it lands between the flow's real children. Returns
 * a null parent when the target has no canvas insertion point (tree-row
 * drops).
 */
const insertionPoint = (drop) => {
  // Tree ROWS are a drop surface, not canvas flow items: a row-derived
  // point would insert real page nodes INTO the panel (and strand them
  // there when the authoritative swap replaces only canvas subtrees).
  // Rows ride drop.items solely for liveCaret's line geometry.
  const canvasItem = (item) =>
    item?.isConnected && !item.hasAttribute('data-tree-key') ? item : null;
  const beforeItem = canvasItem(drop.items?.[drop.index] ?? null);
  const anchorItem = canvasItem(drop.items?.[drop.index - 1] ?? null);
  if (beforeItem) {
    const unit = unitOf(beforeItem);
    return { parent: unit.parentElement, before: unit };
  }
  if (anchorItem) {
    const unit = unitOf(anchorItem);
    return { parent: unit.parentElement, before: unit.nextSibling };
  }
  // Empty containers resolve no items; the container itself is the parent
  // (null for tree drops — their designed "no canvas insertion point").
  return { parent: drop.insert.parent, before: drop.insert.before };
};

/**
 * Inserts the placeholder skeleton at a drop target's gap.
 */
const insertPlaceholder = (drop) => {
  const { parent, before } = insertionPoint(drop);
  if (!parent?.isConnected) {
    return null;
  }
  const placeholder = document.createElement('div');
  placeholder.className = 'daedalus-ui-drop-placeholder';
  parent.insertBefore(placeholder, before?.isConnected ? before : null);
  return placeholder;
};

/**
 * Builds the optimistic {apply, rollback} hooks for a move drop.
 *
 * The dragged node's markup already exists and only its position changes
 * (the moved-markup class of drop), so apply() moves the real node into
 * the gap the placeholder would occupy and rollback() puts it back. The
 * response's
 * authoritative subtree swap replaces the affected regions when it lands.
 */
const optimisticMove = (drop, data) => {
  const element = data.element;
  const point = insertionPoint(drop);
  // No live node to move, or no canvas insertion point (tree-row drops
  // name no parent): fall back to non-optimistic — the mutation still
  // lands, the response swap shows it.
  if (!element?.isConnected || !point.parent) {
    return null;
  }
  // Move the element's whole positional unit (its entity wrapper rides
  // along), or the move strands the wrapper and reparents nothing.
  const unit = unitOf(element);
  let origin = null;
  return {
    apply: () => {
      origin = { parent: unit.parentElement, next: unit.nextSibling };
      if (point.parent.isConnected) {
        point.parent.insertBefore(
          unit,
          point.before?.isConnected ? point.before : null,
        );
      }
    },
    rollback: () => {
      // A preceding op's subtree swap can have replaced the node or its
      // origin; the swapped-in markup is already authoritative, nothing
      // to restore.
      if (!unit.isConnected || !origin?.parent?.isConnected) {
        return;
      }
      origin.parent.insertBefore(
        unit,
        origin.next?.isConnected ? origin.next : null,
      );
    },
  };
};

/**
 * The element a gap drop lands, from its operation batch: the last created
 * element's uuid (creates carry client-minted uuids; a wrapping batch ends
 * with the op that names the placed element), else the moved element.
 * Selection follows it once the response settles (the op queue's select
 * option), for every gap source uniformly: a drop means "put this here",
 * and focus belongs on what was just put there.
 */
const landedUuid = (operations) => {
  const landed = [...operations]
    .reverse()
    .find((operation) => operation.uuid || operation.op === 'move_element');
  return landed?.uuid ?? landed?.element ?? null;
};

/**
 * Ends the caret's committing linger for a drop.
 *
 * Idempotent with commitDrop's finally block: an optimistic drop releases
 * the caret at apply — the moved node is the drop feedback — instead of
 * holding the committing style through the server round trip.
 */
const releaseCaret = (drop) => {
  if (committingDrop === drop) {
    committingDrop = null;
    store.dispatch(commitFinished());
  }
  if (currentDrop === drop) {
    currentDrop = null;
    publishDropRow();
  }
};

/**
 * Commits the current drop: source ops → placeholder → op queue.
 */
const commitDrop = async () => {
  const drop = currentDrop;
  const { spec, data } = activeSource;
  const context = Drupal.Daedalus.Blueprint.rootContext();
  // Element drops need no blueprint context up front: a face whose
  // operations run their own pipeline (upload/replace) works on pages
  // with no blueprint root. Returned blueprint ops still require it at
  // enqueue, where its absence fails loudly.
  if (!drop || (!context && drop.kind !== 'element')) {
    return;
  }

  const isElement = drop.kind === 'element';
  committingDrop = drop;
  store.dispatch(commitStarted());
  let placeholder = null;
  try {
    // The drop's meaning comes from the face that resolved the target;
    // the committing chrome holds on the target while an async face works
    // (an element face's upload pipeline included).
    const operations = await (isElement
      ? spec.elements.operations(drop, data)
      : spec.operations(drop, data));
    if (!operations || operations.length === 0) {
      return;
    }
    // The placeholder materializes the drop instantly (the new-markup
    // class of drop): a visibly transient skeleton holds the gap until the
    // response's subtree swap replaces its container; it sits inside the
    // re-rendered region (or the whole entity for new sections), so the
    // swap discards it without bookkeeping. Element drops insert nothing:
    // no placeholder, no optimistic move.
    if (!isElement && spec.materialize) {
      placeholder = insertPlaceholder(drop);
    }
    const optimistic =
      !isElement && spec.optimistic === 'move'
        ? optimisticMove(drop, data)
        : null;
    // apply() runs synchronously inside enqueue, so by the time the
    // promise is in hand the node has visibly moved. Gap drops select what
    // they landed; element drops change an element in place and leave the
    // selection alone.
    const request = enqueueOperations({
      ...context,
      operations,
      optimistic,
      select: isElement ? null : landedUuid(operations),
    });
    if (optimistic) {
      releaseCaret(drop);
    }
    const response = await request;
    spec.onComplete?.(response, drop, data);
  } catch (error) {
    // The mutation-failure display: remove what failed to materialize
    // (failed moves snapped back via rollback) and report the rest (flash
    // on the reverted element, structured-error notification, loud tail).
    // An element drop's failure flashes on the element it targeted.
    placeholder?.remove();
    reportMutationFailure(
      error,
      (isElement ? drop.element : data.element) ?? null,
    );
  } finally {
    // Belt and braces: on success the swap already discarded it.
    placeholder?.remove();
    // A new drag may have started during the round trip; releaseCaret only
    // clears the target this commit owned.
    releaseCaret(drop);
  }
};

const onDragOver = (event) => {
  if (!activeSource) {
    return;
  }
  // Cancelling dragover is what makes the document a drop target.
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
  updateDrop(event.target, event.clientX, event.clientY);
  // Browsers do not reliably auto-scroll the window while a drag holds at
  // a viewport edge (see auto-scroll.js); the controller does.
  updateAutoScroll(event.clientY);
};

const onDragEnter = (event) => {
  if (activeSource) {
    event.preventDefault();
  }
};

const onDrop = (event) => {
  if (!activeSource) {
    return;
  }
  event.preventDefault();
  const wasExternal = activeSource.external;
  if (wasExternal) {
    // An external drag's payload rides the drop event — files are not
    // readable from dragenter/dragover.
    activeSource.data.files = Array.from(event.dataTransfer?.files ?? []);
  }
  commitDrop();
  if (wasExternal) {
    // No dragend follows an external drag; the drop is the end. The
    // committing flag keeps the caret alive through the in-flight commit,
    // matching the native dragend-right-after-drop sequence.
    endDrag();
  }
};

/**
 * Ends the drag gesture (drop committed, or cancelled via Escape /
 * released outside a target). The committing flag keeps the caret alive
 * through an in-flight commit; a plain cancel clears it immediately.
 */
const endDrag = () => {
  activeSource = null;
  stopAutoScroll();
  if (!committingDrop) {
    currentDrop = null;
  }
  store.dispatch(dragEnded());
};

/**
 * Boots the drag layer: document listeners + the contrib-facing facade.
 */
export const initDrag = () => {
  document.addEventListener('dragover', onDragOver);
  document.addEventListener('dragenter', onDragEnter);
  document.addEventListener('drop', onDrop);

  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.Drag = {
    registerSource: registerDragSource,
    getSource: getDragSource,
    makeDraggable,
    removeDraggable,
    beginExternalDrag,
    endExternalDrag,
    builders,
    isRootFlowLocation,
  };
};
