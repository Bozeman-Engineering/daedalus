import store from '../store/store';
import { register } from '../hotkeys/hotkeys';
import { announce } from '../utilities/announce';
import { enumerateGapTargets, liveCaret, treeRowForLocation } from './gaps';
import { movableFor } from './selector-drag';
import { representedChainKeys } from '../selection/resolver';
import {
  projectionIndex,
  projectionRoot,
} from '../components/page-elements/projection-index';
import {
  getDragSource,
  beginExternalDrag,
  endExternalDrag,
  setCurrentDrop,
  commitCurrentDrop,
} from './drag-controller';

/**
 * The keyboard lift: Space lifts, arrows walk the caret, Space/Enter
 * drops, Escape cancels (the dnd-kit / gov.uk lift-and-move shape).
 *
 * A lift is the keyboard face of a drag: it arms the SAME registered drag
 * source a pointer drag would (move_block/move_section for a lifted
 * selection, new_block/new_section for a palette item), enumerates the
 * gaps that source could drop into (the same gap grammar the pointer
 * resolves per position), and steps the ONE insertion caret through them.
 * The drop commits through the drag controller's one pipeline (ops
 * builders, optimistic move / placeholder-materialize, failure display),
 * so keyboard and pointer placement cannot diverge.
 *
 * The walk STEERS like the arrow traversal: the flat gap list is grouped
 * into the projection's tree, each gap's group being its container's
 * nearest PRESENTED key (the projection filter), so the walk and the panel
 * agree on structure by construction. Up/Down step the slots within one
 * container and continue into the adjacent SIBLING container's slots at
 * the edge (containers are not stops of their own, only slots are, so a
 * hard clamp would seal each container and make a second column
 * unreachable; the clamp survives only at the outermost sibling's edge,
 * which is where traversal clamps too), Right enters the container
 * beside the caret (routing through gap-less intermediates to the nearest
 * descendant with slots), Left pops out beside the container just exited —
 * Escape's step-up shape. One steering wheel for moving and traversing;
 * only the stepping order is keyboard-specific — the gap set, the
 * announcements, and the drop pipeline are the pointer's.
 *
 * While lifted, the caret-walking bindings live in the dispatcher's LIFT
 * context (above selection/tool, below typing), so they outrank the arrow
 * traversal, the palette's insert-session keys, and the Selector's Escape
 * for exactly as long as the lift holds — the context gates on isLifted(),
 * nothing registers or unregisters dynamically.
 *
 * The element never moves during the walk — like a pointer drag, the caret
 * is the feedback and the drop is the commit — so Escape's "snap back" is
 * simply ending the gesture.
 */

/**
 * The live lift: {type, spec, data, gaps, index, walk}, or null. The walk
 * is the tree grouping over the flat gap list: groupOf[i] = the projection
 * key of gap i's container, groups = Map<groupKey, flat indices in slot
 * order>.
 */
let lifted = null;

/**
 * Whether a keyboard lift currently holds the caret. The hotkey
 * dispatcher's lift context gates on this (call-time read, cycle-safe).
 */
export const isLifted = () => !!lifted;

/**
 * The display label of a gap neighbor's selectable key, from the spine's
 * one label resolver (Blueprint.labelForKey, never null).
 */
const labelForElement = (element) => {
  const key = element?.getAttribute?.('data-selectable');
  if (!key) {
    return Drupal.t('the container');
  }
  return Drupal.Daedalus.Blueprint.labelForKey(key);
};

/**
 * A spoken/announced description of a gap: where a drop would land.
 */
const describeGap = (gap, index, count) => {
  let place;
  if (gap.empty) {
    place = Drupal.t('Inside @container', {
      '@container': labelForElement(gap.container),
    });
  } else {
    const neighbor = gap.index > 0 ? gap.items[gap.index - 1] : gap.items[0];
    place =
      gap.index > 0
        ? Drupal.t('After @label', { '@label': labelForElement(neighbor) })
        : Drupal.t('Before @label', { '@label': labelForElement(neighbor) });
  }
  if (gap.newSection) {
    place = Drupal.t('@place, in a new container', { '@place': place });
  }
  return Drupal.t('@place (@index of @count)', {
    '@place': place,
    '@index': index + 1,
    '@count': count,
  });
};

/**
 * Groups the flat gap list into the projection's tree, the walk's
 * structure. A gap's group is its CONTAINER's nearest presented key
 * (representedChainKeys, the projection filter: an unrepresented region
 * hoists to its section exactly as its panel rows do); containers above
 * the main entity ground to the projection root. Groups nest via the
 * projection index's ancestry, so the walk needs no DOM-containment
 * bookkeeping of its own.
 */
const buildWalk = (gaps) => {
  const root = projectionRoot();
  const groupOf = gaps.map(
    (gap) => representedChainKeys(gap.container)[0] ?? root,
  );
  const groups = new Map();
  groupOf.forEach((key, index) => {
    if (!groups.has(key)) {
      groups.set(key, []);
    }
    groups.get(key).push(index);
  });
  return { groupOf, groups };
};

/**
 * The gap whose caret point sits nearest an element (the lifted element
 * for moves, the pending insert anchor for palette lifts), so the walk
 * starts where the user's attention already is.
 */
const nearestGapIndex = (gaps, near) => {
  if (!(near instanceof Element)) {
    return 0;
  }
  const rect = near.getBoundingClientRect();
  const cx = rect.left + rect.width / 2;
  const cy = rect.top + rect.height / 2;
  let best = 0;
  let bestDistance = Infinity;
  gaps.forEach((gap, index) => {
    const point = gap.empty
      ? (() => {
          const r = gap.container.getBoundingClientRect();
          return { left: r.left + r.width / 2, top: r.top + r.height / 2 };
        })()
      : (liveCaret(gap) ?? { left: 0, top: 0 });
    const distance = (point.left - cx) ** 2 + (point.top - cy) ** 2;
    if (distance < bestDistance) {
      bestDistance = distance;
      best = index;
    }
  });
  return best;
};

/**
 * Watches the store while lifted: a selection change (the user clicked
 * elsewhere), a tool change (a session started), or a blueprint
 * replacement (a mutation re-rendered the page — the enumerated gaps are
 * stale) cancels the lift. Unsubscribed BEFORE a drop commits, so the
 * drop's own mutation never reads as a cancel.
 */
let unwatch = null;

const watchStore = () => {
  const snapshot = (state) => ({
    selectedKey: state.selection.selectedKey,
    tool: state.tool.currentTool,
    blueprint: state.blueprint.blueprint,
  });
  let previous = snapshot(store.getState());
  unwatch = store.subscribe(() => {
    const current = snapshot(store.getState());
    if (
      current.selectedKey !== previous.selectedKey ||
      current.tool !== previous.tool ||
      current.blueprint !== previous.blueprint
    ) {
      cancel();
    }
    previous = current;
  });
};

const unwatchStore = () => {
  unwatch?.();
  unwatch = null;
};

/**
 * Lifts an element into the caret walk.
 *
 * @param {string} type
 *   A registered drag-source type (move_block, new_block, ...).
 * @param {Object} data
 *   The drag data the source's operations() consumes.
 * @param {Object} options
 *   - near: an Element the caret should start beside (the moved element,
 *     the pending insert anchor).
 *   - location: a placement-grammar location {anchor, placement, region?};
 *     when a gap matches it exactly, the caret starts there (wins over
 *     near).
 *
 * @return {boolean}
 *   Whether the lift armed (false: unknown source, a drag already active,
 *   or no valid gaps).
 */
export const lift = (
  type,
  data = {},
  { near = null, location = null } = {},
) => {
  if (lifted || store.getState().drag.active) {
    return false;
  }
  const spec = getDragSource(type);
  if (!spec) {
    return false;
  }
  let gaps = enumerateGapTargets(spec.targets);
  if (spec.acceptsTarget) {
    gaps = gaps.filter((gap) => spec.acceptsTarget(gap, data));
  }
  if (!gaps.length) {
    announce(Drupal.t('There is nowhere to place this.'));
    return false;
  }
  if (!beginExternalDrag(type, data)) {
    return false;
  }
  let index = -1;
  if (location) {
    index = gaps.findIndex(
      (gap) =>
        gap.location.anchor === location.anchor &&
        gap.location.placement === location.placement &&
        (gap.location.region ?? null) === (location.region ?? null) &&
        !gap.newSection,
    );
  }
  if (index === -1) {
    index = nearestGapIndex(gaps, near);
  }
  lifted = { type, spec, data, gaps, index, walk: buildWalk(gaps) };
  setCurrentDrop(gaps[index]);
  watchStore();
  announce(
    Drupal.t(
      'Lifted. Arrow keys move the insertion point, Space or Enter drops, Escape cancels. @gap',
      {
        '@gap': describeCurrentGap(),
      },
    ),
  );
  return true;
};

/**
 * The current gap described in its GROUP's terms (position among the
 * slots of one container — the unit the walk steps within).
 */
const describeCurrentGap = () => {
  const list = lifted.walk.groups.get(lifted.walk.groupOf[lifted.index]);
  return describeGap(
    lifted.gaps[lifted.index],
    list.indexOf(lifted.index),
    list.length,
  );
};

/**
 * Commits a walk move: caret, reveal on both surfaces, announce. A move
 * that crosses into a different container names it first (an empty gap's
 * description already does).
 */
const moveTo = (flatIndex) => {
  const from = lifted.walk.groupOf[lifted.index];
  lifted.index = flatIndex;
  const gap = lifted.gaps[flatIndex];
  setCurrentDrop(gap);
  (gap.empty
    ? gap.container
    : gap.items[Math.min(gap.index, gap.items.length - 1)]
  )?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  const row = treeRowForLocation(gap.location)?.row;
  if (row?.offsetParent) {
    row.scrollIntoView({ block: 'nearest' });
  }
  const crossed = lifted.walk.groupOf[flatIndex] !== from && !gap.empty;
  announce(
    crossed
      ? Drupal.t('In @container. @gap', {
          '@container': labelForElement(gap.container),
          '@gap': describeCurrentGap(),
        })
      : describeCurrentGap(),
  );
};

/**
 * The nearest sibling group in the stepped direction: scans the group
 * container's projection siblings and descends into each (the sibling
 * itself first, then its children in step order) for the first group it
 * hosts — a multi-region sibling section is entered at its first region
 * stepping down, its last stepping up. Null at the outermost sibling's
 * edge; the walk clamps there.
 */
const adjacentSiblingGroup = (groupKey, delta) => {
  const index = projectionIndex();
  const parent = index?.get(groupKey)?.parent;
  if (!parent) {
    return null;
  }
  const groupWithin = (key) => {
    if (lifted.walk.groups.has(key)) {
      return key;
    }
    const children = index.get(key)?.children ?? [];
    for (const child of delta > 0 ? children : [...children].reverse()) {
      const found = groupWithin(child);
      if (found) {
        return found;
      }
    }
    return null;
  };
  const siblings = index.get(parent)?.children ?? [];
  for (
    let i = siblings.indexOf(groupKey) + delta;
    i >= 0 && i < siblings.length;
    i += delta
  ) {
    const found = groupWithin(siblings[i]);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * Steps the caret to the next/previous slot WITHIN the current container;
 * at the container's edge, continues into the adjacent sibling container's
 * slots (its first stepping down, its last stepping up). Only slots are
 * stops, so without the continuation the edge would seal each container —
 * a second column had no keyboard path in. Clamps at the outermost
 * sibling's edge, like traversal's sibling clamp.
 */
const stepWithin = (delta) => {
  if (!lifted) {
    return false;
  }
  const list = lifted.walk.groups.get(lifted.walk.groupOf[lifted.index]);
  const position = list.indexOf(lifted.index);
  const target = position + delta;
  if (target >= 0 && target < list.length) {
    moveTo(list[target]);
    return;
  }
  const sibling = adjacentSiblingGroup(
    lifted.walk.groupOf[lifted.index],
    delta,
  );
  if (!sibling) {
    // The outermost edge: handled (no page scroll), no move.
    return;
  }
  const slots = lifted.walk.groups.get(sibling);
  moveTo(delta > 0 ? slots[0] : slots[slots.length - 1]);
};

/**
 * Enters the container beside the caret: the adjacent item's nearest
 * projected descendant (or the item itself — a container with slots of
 * its own) that has slots in this walk. The breadth-first search routes
 * through intermediates whose own slots the source filtered out.
 */
const stepIn = () => {
  if (!lifted) {
    return false;
  }
  const gap = lifted.gaps[lifted.index];
  const index = projectionIndex();
  const item = gap.empty
    ? null
    : gap.items[Math.min(gap.index, gap.items.length - 1)];
  const start = item?.getAttribute('data-selectable');
  if (!start || !index) {
    // An empty container has nothing to enter; handled, no page scroll.
    return;
  }
  const currentGroup = lifted.walk.groupOf[lifted.index];
  const queue = [start];
  while (queue.length) {
    const key = queue.shift();
    if (key !== currentGroup && lifted.walk.groups.has(key)) {
      moveTo(lifted.walk.groups.get(key)[0]);
      return;
    }
    queue.push(...(index.get(key)?.children ?? []));
  }
};

/**
 * Pops out to the parent container's slots, landing just after the
 * container the caret was inside — Escape's step-up shape.
 */
const stepOut = () => {
  if (!lifted) {
    return false;
  }
  const index = projectionIndex();
  if (!index) {
    return;
  }
  // Climb the projection ancestry to the nearest enclosing group; the key
  // climbed FROM is the container being exited.
  let exited = lifted.walk.groupOf[lifted.index];
  let ancestor = index.get(exited)?.parent ?? null;
  while (ancestor && !lifted.walk.groups.has(ancestor)) {
    exited = ancestor;
    ancestor = index.get(ancestor)?.parent ?? null;
  }
  if (!ancestor) {
    return;
  }
  const list = lifted.walk.groups.get(ancestor);
  const after = list.find((i) => {
    const gap = lifted.gaps[i];
    return (
      !gap.empty &&
      gap.index > 0 &&
      gap.items[gap.index - 1].getAttribute('data-selectable') === exited
    );
  });
  moveTo(after ?? list[0]);
};

/**
 * Drops at the current gap: the commit rides the drag controller's one
 * pipeline (the source's operations, optimistic move or placeholder, the
 * op queue, failure display).
 */
export const drop = () => {
  if (!lifted) {
    return false;
  }
  unwatchStore();
  lifted = null;
  commitCurrentDrop();
  announce(Drupal.t('Dropped.'));
};

/**
 * Cancels the lift: the caret disappears, nothing moved, nothing mutates.
 */
export const cancel = () => {
  if (!lifted) {
    return false;
  }
  unwatchStore();
  lifted = null;
  endExternalDrag();
  announce(Drupal.t('Move cancelled.'));
};

/**
 * Registers the lift bindings and the contrib-facing facade. Called once
 * by the app entry, after initDrag()/initSelectorDrag() and initHotkeys().
 */
export const initKeyboardLift = () => {
  // Space lifts the current selection's movable, the same promotion a
  // Selector drag applies: a field lifts the block placement that owns it,
  // deep selections lift their nearest movable ancestor. The
  // binding lives in the SELECTION context; while lifted, the lift-context
  // twin below outranks it and Space means drop.
  register({
    id: 'selector.lift',
    key: 'space',
    when: 'selection',
    label: Drupal.t('Move the selected element (lift / drop)'),
    section: Drupal.t('Selection'),
    remappable: true,
    action: (event) => {
      // The keyboard-move grammar is the Selector's, like the pointer's
      // drag arming; and a focused control (a verb button, a link) keeps
      // its native Space behavior.
      if (store.getState().tool.currentTool !== 'selector') {
        return false;
      }
      if (
        event.target instanceof Element &&
        event.target.closest('button, a[href], select')
      ) {
        return false;
      }
      const key = store.getState().selection.selectedKey;
      const element = movableFor(key);
      if (!element) {
        return false;
      }
      const parts = element.getAttribute('data-selectable').split(':');
      return (
        lift(
          parts[0] === 'container' ? 'move_section' : 'move_block',
          { uuid: parts[1], element },
          { near: element },
        ) || false
      );
    },
  });

  // The caret walk, the traversal's steering wheel transposed to slots:
  // Up/Down within the container, Right in, Left out. These are
  // static registrations gated by the LIFT context (isLifted), so they
  // exist only while a lift holds (no dynamic register/unregister
  // choreography), and they outrank the arrow traversal and the palette's
  // insert-session keys by context.
  [
    {
      id: 'lift.next',
      key: 'arrowdown',
      label: Drupal.t('Move: next position'),
      action: () => stepWithin(1),
    },
    {
      id: 'lift.previous',
      key: 'arrowup',
      label: Drupal.t('Move: previous position'),
      action: () => stepWithin(-1),
    },
    {
      id: 'lift.in',
      key: 'arrowright',
      label: Drupal.t('Move: step inside'),
      action: () => stepIn(),
    },
    {
      id: 'lift.out',
      key: 'arrowleft',
      label: Drupal.t('Move: step outside'),
      action: () => stepOut(),
    },
    {
      id: 'lift.drop',
      key: 'space',
      label: Drupal.t('Move: drop'),
      action: () => drop(),
    },
    {
      id: 'lift.drop_2',
      key: 'enter',
      label: Drupal.t('Move: drop'),
      action: () => drop(),
    },
    {
      id: 'lift.cancel',
      key: 'escape',
      label: Drupal.t('Move: cancel'),
      action: () => cancel(),
    },
  ].forEach((spec) =>
    register({ ...spec, when: 'lift', section: Drupal.t('Selection') }),
  );

  // The public face: the palette lifts its highlighted item through this
  // (Enter on a palette item = lifted-with-a-new-block), and tests
  // read the walk's state.
  Drupal.Daedalus = Drupal.Daedalus || {};
  Object.assign(Drupal.Daedalus.Drag, {
    lift,
    isLifted,
    cancelLift: cancel,
    /**
     * The current gap's location while lifted, for consumers/tests:
     * {anchor, placement, region?, newSection, index, count, groupKey,
     * groupIndex, groupCount} or null. index/count address the flat gap
     * list; the group fields address the walk's tree terms.
     */
    getLiftGap: () => {
      if (!lifted) {
        return null;
      }
      const groupKey = lifted.walk.groupOf[lifted.index];
      const list = lifted.walk.groups.get(groupKey);
      return {
        ...lifted.gaps[lifted.index].location,
        newSection: !!lifted.gaps[lifted.index].newSection,
        index: lifted.index,
        count: lifted.gaps.length,
        groupKey,
        groupIndex: list.indexOf(lifted.index),
        groupCount: list.length,
      };
    },
  });
};
