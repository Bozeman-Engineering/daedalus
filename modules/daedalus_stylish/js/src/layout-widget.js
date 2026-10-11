/**
 * The layout widget's pure logic (mode + share).
 *
 * Arrangement is two count-independent variants on the container's
 * `container_layout` axis (`columns`: equal shares; `row`: natural widths,
 * wrapping) plus per-child flex-grow overrides only where the user
 * deviates from equal under Columns — no track string exists anywhere.
 * This module is everything the widget computes and every batch it
 * fires, kept Drupal-free so tests/js/layout-widget.test.mjs can lock it
 * under plain node: the node tree reads (a container's children, an
 * element's row parent), the arrangement state (stack / row / columns /
 * custom honesty), the proportion strip math (snap scale, pair-preserving
 * drag), the count-aware preset recipes (generated here, never entities),
 * the alignment axes (the folded-in Arrange control: mode-scoped rows
 * over the flex vocabulary, since a container is a flex column by
 * declaration and a flex row under either worn variant, so no pick ever
 * writes a display), and the exact
 * spine-op batches (mode flip, preset with minting, drag commit, count
 * step, alignment pick, gap override/reset — the gap field row's one
 * value-level primitive; the row's VALUE is the container stack's winner
 * at the pose, style-stack.js stackAt, read by the surface).
 * The Preact surface (layout-section.js) renders these facts and forwards
 * these batches through the state source; it adds no vocabulary of its
 * own, which is what makes satellite parity free.
 *
 * The pose doctrine, both halves. The Stack | Row | Columns segment is a
 * WEAR surface: `wearing` is the unconditioned arrangement reference and
 * the mode flip applies or removes it with no condition. Everything else
 * is a PROPERTY surface and takes the editing compound the canvas pose
 * derives: `mode` is the EFFECTIVE arrangement at that compound (the
 * root's own layers through the condition cascade, overlaid by the worn
 * variant's own posed blocks through posedBlock, so a Columns wear whose
 * narrow block sets flex-direction: column reads stack there, `via`
 * naming the variant and the compound), the alignment, grow and gap
 * reads happen at the compound, and every update_style they fire lands
 * there (compoundOperations, the one op spelling).
 */

import {
  isContainerNode,
  isFlowNode,
  nodeChildren,
  parseKey,
  bundleForKey,
  declarationForKey,
  resolvedConstraintsFor,
  definitionFor,
} from './style-guide.js';
import { entryRefs } from './variant-scope.js';
import { normalizeValue, segmentState } from './widget-values.js';
import {
  inheritedValue,
  posedBlock,
  valueAtPose,
} from './condition-cascade.js';
import { wornRootStyles } from './wear-bar-model.js';
import { compoundOperations } from './controls.js';

/** The shipped variant (labeled "Columns") the Columns mode applies. */
export const COLUMNS_VARIANT = 'columns';

/** The shipped variant (labeled "Row") the Row mode applies. */
export const ROW_VARIANT = 'row';

/** The variant axis (labeled "Layout") that means "arrangement mode". */
export const CONTAINER_LAYOUT_AXIS = 'container_layout';

/**
 * The worn modes by their variant: the mode id IS the shipped variant's
 * machine name, so the segment, the AI's apply_variant and the map all
 * speak one word per arrangement. Stack is the absence of a wear.
 */
const MODE_VARIANTS = {
  row: ROW_VARIANT,
  columns: COLUMNS_VARIANT,
};

/**
 * The snap scale a divider drag lands on: simple ratios of the pair's
 * span. Kept deliberately coarse (Tim: the snap scale on dividers stays)
 * so dragged proportions stay nameable facts, not pixel noise.
 */
export const SNAP_STOPS = [0.2, 0.25, 1 / 3, 0.4, 0.5, 0.6, 2 / 3, 0.75, 0.8];

const round2 = (value) => Math.round(value * 100) / 100;

/**
 * Finds a container node by uuid, at any depth.
 */
const findSection = (nodes, uuid) => {
  for (const node of Array.isArray(nodes) ? nodes : []) {
    if (!isContainerNode(node)) {
      continue;
    }
    if (node.uuid === uuid) {
      return node;
    }
    const found = findSection(nodeChildren(node), uuid);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * A container's ordered children as widget descriptors.
 *
 * Blocks as direct row children just work (the `> *` rule styles them), so
 * both node flavors are first-class columns: {key, uuid, kind, empty} —
 * empty only ever true for a childless container (the silent-remove case).
 * Only a container FLOW qualifies (isFlowNode: element children directly,
 * no region nodes); a multi-region container is not positionally
 * addressable and gets no widget.
 *
 * @param {Array} tree
 *   The lean node tree (source.getTree()).
 * @param {string} uuid
 *   The container's section uuid.
 *
 * @return {?Array<{key: string, uuid: string, kind: string, empty: boolean}>}
 *   The children in order, or null when the container is not a flow this
 *   widget can arrange.
 */
export const containerChildren = (tree, uuid) => {
  const section = findSection(tree, uuid);
  if (!isFlowNode(section)) {
    return null;
  }
  return section.children.map((node) =>
    isContainerNode(node)
      ? {
          key: `container:${node.uuid}`,
          uuid: node.uuid,
          kind: 'container',
          empty: nodeChildren(node).length === 0,
        }
      : {
          key: `block:${node.uuid}`,
          uuid: node.uuid,
          kind: 'block',
          empty: false,
        },
  );
};

/**
 * The container section directly holding an element, with its position.
 *
 * The Column section's parent lookup: only container-flow parents count
 * (the ones the widget can arrange), and only direct children (a page
 * band's parent is the entity, which never answers).
 *
 * @param {Array} tree
 *   The lean node tree.
 * @param {string} key
 *   The element's selectable key (container:{uuid} or block:{uuid}).
 *
 * @return {?{key: string, uuid: string, index: number, count: number}}
 *   The parent container and this element's position, or null.
 */
export const parentContainerOf = (tree, key) => {
  const parsed = parseKey(key);
  if (!parsed || (parsed.kind !== 'container' && parsed.kind !== 'block')) {
    return null;
  }
  let found = null;
  const walk = (nodes) => {
    for (const node of Array.isArray(nodes) ? nodes : []) {
      if (!isContainerNode(node)) {
        continue;
      }
      if (isFlowNode(node)) {
        const index = node.children.findIndex(
          (child) => child?.uuid === parsed.uuid,
        );
        if (index !== -1) {
          found = {
            key: `container:${node.uuid}`,
            uuid: node.uuid,
            index,
            count: node.children.length,
          };
          return;
        }
      }
      walk(nodeChildren(node));
      if (found) {
        return;
      }
    }
  };
  walk(tree);
  return found;
};

/**
 * The properties whose own value on the container root claims the
 * arrangement by hand (arrangementState's Custom), keyed by the carrier
 * name the state reports and the takeover clears (modeOps).
 */
const CUSTOM_PROPERTIES = {
  display: 'display',
  direction: 'flex-direction',
  tracks: 'grid-template-columns',
};

const isBlock = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * An element's own layers for one property: every condition block of its
 * entry carrying the property, as {condition, value} rows (the row shape
 * condition-cascade's reads take). The reference list is not a layer.
 */
const ownLayers = (map, target, property) =>
  Object.entries(map?.[target] ?? {})
    .filter(
      ([condition, block]) =>
        condition !== 'variants' &&
        isBlock(block) &&
        (typeof block[property] === 'string' ||
          typeof block[property] === 'number'),
    )
    .map(([condition, block]) => ({
      condition,
      value: String(block[property]),
    }));

/**
 * The value an element's own block AT the compound carries for a
 * property, or null: the committed value a property row shows and the
 * one an unset at this compound can retire.
 *
 * @param {Object} map
 *   The style map.
 * @param {string} target
 *   The element's selectable key.
 * @param {string} property
 *   The CSS property name.
 * @param {string} condition
 *   The editing compound.
 *
 * @return {?string}
 */
export const ownAt = (map, target, property, condition) => {
  const value = map?.[target]?.[condition]?.[property];
  return typeof value === 'string' || typeof value === 'number'
    ? String(value)
    : null;
};

/**
 * An element's own value for a property as the posed compound delivers
 * it: the compound's own block first, else the cascade-winning own block
 * applying within it (condition-cascade valueAtPose, the inspector's
 * "who shows through at this pose" read). Null when no own layer reaches
 * the pose.
 *
 * @return {?{condition: string, value: string}}
 */
const ownAtPose = (map, target, property, condition, conditions) =>
  valueAtPose(ownLayers(map, target, property), condition, conditions);

/**
 * The worn arrangement-axis reference on a container, or null: the
 * reference whose variant the store's records resolve to the axis (the
 * two shipped names are the fallback for a record the store lacks).
 */
const arrangementRef = (map, key, variants) => {
  const byName = Object.fromEntries(
    (variants ?? []).map((variant) => [variant.id, variant]),
  );
  const shipped = Object.values(MODE_VARIANTS);
  for (const ref of entryRefs(map?.[key])) {
    const axis =
      byName[ref.variant]?.group ??
      (shipped.includes(ref.variant) ? CONTAINER_LAYOUT_AXIS : null);
    if (axis === CONTAINER_LAYOUT_AXIS) {
      return ref;
    }
  }
  return null;
};

/**
 * Whether the worn arrangement variant stacks its children at the posed
 * compound: its own posed blocks deliver flex-direction: column there.
 * The wear's slice root (wornRootStyles) less its default block (the
 * default block is the wear itself, not a pose) merges through the
 * cascade's posedBlock, so every block the compound covers lands in
 * structural order and the later wins. Nothing is posed at the default
 * compound, and an unresolved wearing stacks nothing.
 */
const stacksAtPose = (ref, variants, condition, conditions) => {
  if (ref === null || condition === 'default') {
    return false;
  }
  const posed = Object.fromEntries(
    Object.entries(wornRootStyles(variants, ref) ?? {}).filter(
      ([block]) => block !== 'default',
    ),
  );
  return (
    normalizeValue(
      posedBlock(posed, condition, conditions)?.['flex-direction'],
    ) === 'column'
  );
};

/**
 * A container's arrangement state: stack, row, columns, or the honest
 * Custom.
 *
 * `wearing` is the worn arrangement-axis reference, unconditioned by
 * construction (the wear surface's fact: the segment reflects it at
 * every pose, and it is reported even under Custom so a takeover knows
 * what the entry already wears). `mode` is the EFFECTIVE arrangement at
 * the posed compound, and its id is the worn shipped variant's machine
 * name (`row` or `columns`, MODE_VARIANTS): a wear whose own posed
 * blocks stack the children (the shipped Columns variant's narrow
 * block) reads as stack with `via` naming the variant and the compound,
 * the property rows then render for the stack and the surface says who
 * decided; `via` is null otherwise. A wear on the axis the widget does
 * not ship (a design system's own arrangement) reads as stack with
 * `wearing` reporting it: the segment lights nothing and the takeover
 * replaces it within the axis.
 *
 * Custom means hand-written layout styles claim the mode at this pose:
 * any own display, own flex-direction or own grid-template-columns
 * reaching the compound. The widget itself never writes any of the
 * three (the container kind's declaration makes every container a flex
 * column and either worn variant flips the direction, so a pick or a
 * gap write needs no display of its own), which is what makes every own
 * value a hand claim: a display re-arranges the box outside the
 * declared model, a direction claims what the arrangement axis owns
 * (the axis's `owns` line steers the AI to the wear), explicit tracks
 * are the advanced path. Own values read through the condition cascade
 * (ownAtPose), so a default-block claim still claims at Narrow. The
 * widget never silently rewrites custom styles: customKeys lists the
 * carrying keys so clicking Stack, Row or Columns can be the explicit
 * takeover gesture, and `carriers` names the condition block each claim
 * lives in (null when unset) so the takeover's clears land where the
 * value actually is.
 *
 * @param {Object} map
 *   The style map.
 * @param {string} key
 *   The container's selectable key.
 * @param {Array<Object>} variants
 *   The store's variant records.
 * @param {string} condition
 *   The editing compound ('default' when unposed).
 * @param {Object} conditions
 *   The condition table (guide.conditions), for cascade order.
 *
 * @return {{mode: string, wearing: ?string, via: ?{variant: string,
 *   condition: string}, customKeys: string[], carriers: {display:
 *   ?string, direction: ?string, tracks: ?string}}}
 */
export const arrangementState = (
  map,
  key,
  variants = [],
  condition = 'default',
  conditions = {},
) => {
  const ref = arrangementRef(map, key, variants);
  const wearing = ref?.variant ?? null;
  const stacks = stacksAtPose(ref, variants, condition, conditions);
  // One box: the container root is the only carrier the widget reads.
  const claims = Object.fromEntries(
    Object.entries(CUSTOM_PROPERTIES).map(([carrier, property]) => [
      carrier,
      ownAtPose(map, key, property, condition, conditions),
    ]),
  );
  const carriers = Object.fromEntries(
    Object.entries(claims).map(([carrier, own]) => [
      carrier,
      own?.condition ?? null,
    ]),
  );
  const customKeys = Object.values(claims).some((own) => own !== null)
    ? [key]
    : [];
  if (customKeys.length) {
    return { mode: 'custom', wearing, via: null, customKeys, carriers };
  }
  const worn = Object.keys(MODE_VARIANTS).find(
    (mode) => MODE_VARIANTS[mode] === wearing,
  );
  return {
    mode: worn !== undefined && !stacks ? worn : 'stack',
    wearing,
    via: stacks ? { variant: wearing, condition } : null,
    customKeys,
    carriers,
  };
};

/**
 * One alignment stop table over the flex vocabulary, labeled for the
 * screen axis it faces (the same three flex stops read Left / Center /
 * Right on the stack's cross axis and Top / Middle / Bottom on either
 * main axis). Canonical writes use the flex-legacy spellings every
 * browser honors on a flex container; `matches` are the equivalent
 * spellings a stop honestly represents (an AI or advanced-mode write of
 * the modern start/end alias lights the button, clicking never rewrites
 * it).
 */
const flexStops = ([start, center, end]) => [
  { id: 'start', value: 'flex-start', matches: ['flex-start', 'start'], label: start },
  { id: 'center', value: 'center', matches: ['center'], label: center },
  { id: 'end', value: 'flex-end', matches: ['flex-end', 'end'], label: end },
];

const HORIZONTAL_STOPS = flexStops(['Left', 'Center', 'Right']);
const VERTICAL_STOPS = flexStops(['Top', 'Middle', 'Bottom']);

/**
 * The fourth stop a Row's main axis earns: the children pushed to both
 * edges with the free space between them. Only a main axis whose items
 * keep their own size can distribute anything, so it rides the Row's
 * Horizontal row alone (a stack's vertical main axis has no free space
 * to spread, a Columns' children fill theirs).
 */
const SPREAD_STOP = {
  id: 'spread',
  value: 'space-between',
  matches: ['space-between'],
  label: 'Spread',
};

const ROW_HORIZONTAL_STOPS = [...HORIZONTAL_STOPS, SPREAD_STOP];

/**
 * The Layout section's alignment axes for a mode, mode-scoped. A
 * container is a flex box at every pose (a column by the kind's
 * declaration, a row under either worn variant), so every mode speaks
 * flex and no pick needs a display of its own:
 * - stack: both screen axes on the flex column — align-items horizontal
 *   (the cross axis: per-item, so text still wraps at the box), and
 *   justify-content vertical (the main axis: packs the children to the
 *   top, middle or bottom of a band taller than its content).
 * - row: both screen axes on the flex row — justify-content horizontal
 *   (the main axis: natural-width children packed left, center, right
 *   or spread to the edges), and align-items vertical (the cross axis).
 * - columns: only the cross axis, align-items on the flex row — main-axis
 *   distribution is meaningless while columns fill (flex: 1 1 0).
 * - custom: no rows; the pill owns the story.
 *
 * @param {string} mode
 *   An arrangementState() mode.
 *
 * @return {Array<{orientation: string, label: string, property: string,
 *   stops: Array}>}
 */
export const alignmentAxes = (mode) => {
  if (mode === 'stack') {
    return [
      {
        orientation: 'horizontal',
        label: 'Horizontal',
        property: 'align-items',
        stops: HORIZONTAL_STOPS,
      },
      {
        orientation: 'vertical',
        label: 'Vertical',
        property: 'justify-content',
        stops: VERTICAL_STOPS,
      },
    ];
  }
  if (mode === 'row') {
    return [
      {
        orientation: 'horizontal',
        label: 'Horizontal',
        property: 'justify-content',
        stops: ROW_HORIZONTAL_STOPS,
      },
      {
        orientation: 'vertical',
        label: 'Vertical',
        property: 'align-items',
        stops: VERTICAL_STOPS,
      },
    ];
  }
  if (mode === 'columns') {
    return [
      {
        orientation: 'vertical',
        label: 'Vertical',
        property: 'align-items',
        stops: VERTICAL_STOPS,
      },
    ];
  }
  return [];
};

/**
 * One alignment axis's row state: the axis property's winner at the
 * pose mapped onto its stops, with the custom-state honesty rule (an
 * unrepresentable value renders as the explicit custom pill, never as
 * nothing).
 *
 * The container root is the one carrier (the one-box model: the root IS
 * the box that lays out the children, at every depth), and the read is
 * the root's layer stack at the pose (style-stack.js stackAt, the read
 * the Gap row beside these rows and every choice row make): the own
 * default block reaching Narrow through the condition cascade, a worn
 * arrangement variant's alignment, the skin's, each lights its stop, so
 * what the row lights is what the canvas shows, in the delivering
 * layer's hue. The pick still lands at the compound's own block
 * (alignOps), and Unset is offered only while that block delivers.
 *
 * @param {?{winner: ?{value: string}}} read
 *   The axis property's stack read at the pose (stackAt), or null when
 *   the row has no stack.
 * @param {{stops: Array}} axis
 *   An alignmentAxes() axis.
 *
 * @return {{selected: ?string, custom: boolean, value: ?string}}
 */
export const alignmentState = (read, axis) => {
  const value = read?.winner?.value ?? null;
  const state = segmentState(value, axis.stops);
  return { ...state, value: state.custom ? value : null };
};

/**
 * The batch an alignment pick (stop value) or unset (null) fires.
 *
 * Writes land on the container root (the one box) at the posed compound,
 * the axis property alone: the container is a flex box at every pose by
 * declaration, so a pick never carries a display. Unsetting retires only
 * what the compound's own block carries (an empty batch when it carries
 * nothing).
 *
 * @param {Object} map
 *   The style map.
 * @param {string} key
 *   The container's selectable key.
 * @param {{property: string}} axis
 *   An alignmentAxes() axis.
 * @param {?string} value
 *   The picked stop's canonical value, or null to unset.
 * @param {string} condition
 *   The editing compound ('default' when unposed).
 *
 * @return {Object[]}
 */
export const alignOps = (map, key, axis, value, condition = 'default') => {
  if (value === null && ownAt(map, key, axis.property, condition) === null) {
    return [];
  }
  return compoundOperations([
    { key, condition, properties: { [axis.property]: value } },
  ]);
};

/**
 * A child's own flex-grow as the posed compound delivers it (its own
 * layers through the condition cascade), or null (unset = equal).
 */
export const growOf = (
  map,
  childKey,
  condition = 'default',
  conditions = {},
) => {
  const own = ownAtPose(map, childKey, 'flex-grow', condition, conditions);
  const value = own === null ? NaN : parseFloat(own.value);
  return Number.isFinite(value) && value >= 0 ? value : null;
};

/**
 * The children's effective grow weights at the posed compound (unset
 * reads as 1, the `> *` rule's equal fill).
 */
export const childGrows = (
  map,
  childKeys,
  condition = 'default',
  conditions = {},
) => childKeys.map((key) => growOf(map, key, condition, conditions) ?? 1);

/**
 * The strip's percent labels for a weight list.
 */
export const percentLabels = (grows) => {
  const total = grows.reduce((sum, grow) => sum + grow, 0) || 1;
  return grows.map((grow) => `${Math.round((grow / total) * 100)}%`);
};

/**
 * Snaps a drag fraction to the nearest scale stop.
 */
export const snapFraction = (fraction) =>
  SNAP_STOPS.reduce(
    (best, stop) =>
      Math.abs(stop - fraction) < Math.abs(best - fraction) ? stop : best,
    0.5,
  );

/**
 * One divider-drag step: the pair beside the divider re-splits at the
 * snapped fraction while its COMBINED weight is preserved, so the other
 * columns never move (67/33 dragged to center becomes 1.5/1.5, not 1/1).
 *
 * @param {number[]} grows
 *   The effective weights (childGrows()).
 * @param {number} leftIndex
 *   The divider's left segment index.
 * @param {number} fraction
 *   The pointer's fraction of the pair's span (0..1).
 *
 * @return {number[]}
 *   The next weights, two-decimal rounded.
 */
export const dragPair = (grows, leftIndex, fraction) => {
  const pair = grows[leftIndex] + grows[leftIndex + 1];
  const left = round2(pair * snapFraction(fraction));
  const next = [...grows];
  next[leftIndex] = left;
  next[leftIndex + 1] = round2(pair - left);
  return next;
};

/**
 * The update_style ops that land a grow assignment.
 *
 * Writes land at the posed compound. Weight 1 normalizes to UNSET (equal
 * is the `> *` rule's job — per-child CSS exists only where the user
 * deviates) unless another own layer still delivers a weight at this
 * pose (a default 2 posed Narrow), where an unset would change nothing
 * and equal is written out as 1; unchanged children emit nothing, and
 * values write as strings (the map's value shape). `next` entries are
 * numbers, or null for "equal".
 *
 * @return {Object[]}
 */
export const growOps = (
  childKeys,
  map,
  next,
  condition = 'default',
  conditions = {},
) => {
  const changes = [];
  childKeys.forEach((key, index) => {
    const raw = next[index];
    const desired = raw === null || raw === undefined ? 1 : round2(raw);
    const current = growOf(map, key, condition, conditions) ?? 1;
    if (desired === current) {
      return;
    }
    const inherited = inheritedValue(
      ownLayers(map, key, 'flex-grow'),
      condition,
      conditions,
    );
    changes.push({
      key,
      condition,
      properties: {
        'flex-grow':
          desired === 1 && inherited === null ? null : String(desired),
      },
    });
  });
  return compoundOperations(changes);
};

/**
 * The preset recipes offered at a child count.
 *
 * Count-aware and GENERATED (split_* entities retire): every def is
 * {weights, equal}, weights one per column. Defs longer than the current
 * count MINT the missing columns (a one-child band goes straight to three
 * columns in one click); shorter defs reset the trailing children to
 * equal.
 *
 * @return {Array<{weights: number[], equal: boolean}>}
 */
export const presetDefs = (count) => {
  const defs = [];
  const push = (weights, equal = false) => defs.push({ weights, equal });
  if (count <= 2) {
    push([1, 1], count === 2);
    push([2, 1]);
    push([1, 2]);
  }
  if (count === 3) {
    push([1, 1, 1], true);
    push([1, 2, 1]);
    push([2, 1, 1]);
    push([1, 1, 2]);
  }
  if (count <= 3) {
    if (count < 3) {
      push([1, 1, 1]);
    }
    push([1, 1, 1, 1]);
  }
  if (count === 4) {
    push([1, 1, 1, 1], true);
    push([2, 1, 1, 1]);
    push([1, 1, 1, 2]);
  }
  if (count > 4) {
    push(Array(count).fill(1), true);
  }
  return defs;
};

/**
 * Whether the current weights match a preset's ratios (within 1%).
 */
export const presetActive = (grows, weights) => {
  if (weights.length !== grows.length) {
    return false;
  }
  const total = grows.reduce((sum, grow) => sum + grow, 0) || 1;
  const weightTotal = weights.reduce((sum, weight) => sum + weight, 0) || 1;
  return grows.every(
    (grow, index) =>
      Math.abs(grow / total - weights[index] / weightTotal) < 0.01,
  );
};

/**
 * The batch a preset pick fires: mint the missing columns (pure
 * add_section — equal fill is the `> *` rule, no per-child seeding
 * exists), then the grow writes where the recipe deviates from equal.
 * Later batch operations see the sections earlier ones added, so minted
 * children are keyed by their minted uuids.
 *
 * @param {Object} options
 * @param {string} options.containerUuid
 * @param {string[]} options.childKeys
 *   The current children's keys, in order.
 * @param {Object} options.map
 *   The style map (current grows read from it).
 * @param {number[]} options.weights
 *   The preset's weights.
 * @param {() => string} options.generateUuid
 * @param {string} [options.condition]
 *   The editing compound the grow writes land at ('default' when unposed).
 * @param {Object} [options.conditions]
 *   The condition table (guide.conditions).
 *
 * @return {{operations: Object[], minted: string[]}}
 */
export const presetPlan = ({
  containerUuid,
  childKeys,
  map,
  weights,
  generateUuid,
  condition = 'default',
  conditions = {},
}) => {
  const operations = [];
  const minted = [];
  const keys = [...childKeys];
  while (keys.length < weights.length) {
    const uuid = generateUuid();
    minted.push(uuid);
    operations.push({
      op: 'add_section',
      uuid,
      location: { anchor: containerUuid, placement: 'inside' },
    });
    keys.push(`container:${uuid}`);
  }
  const next = keys.map((key, index) =>
    index < weights.length && weights[index] !== 1 ? weights[index] : null,
  );
  operations.push(...growOps(keys, map, next, condition, conditions));
  return { operations, minted };
};

/**
 * The batch a mode flip fires.
 *
 * Custom keys clear first (clicking Stack, Row or Columns IS the
 * explicit takeover gesture — d: Custom pill, no remove button; the
 * clear retires every hand claim, display, flex-direction and
 * grid-template-columns, while the alignment properties stay in the
 * map, inert, exactly like grows stay inert under Stack), then the
 * arrangement axis changes hands: Row and Columns each apply their
 * shipped variant (within-axis replace covers the other mode's wear and
 * a foreign arrangement variant alike), Stack REMOVES the worn
 * reference without materializing (remove_variant) so the children's
 * grow overrides stay in the map, inert — flipping back restores the
 * proportions. An empty batch means the click changed nothing.
 *
 * The flip is a WEAR gesture: apply_variant / remove_variant carry no
 * condition. Its clears retire the claims the state found at the pose,
 * and land where each claim lives (state.carriers, the wear bar's Reset
 * overrides precedent): a default-block claim claims at Narrow too, and
 * unsetting it at Narrow would change nothing.
 *
 * @param {string} key
 *   The container's selectable key.
 * @param {{mode: string, wearing: ?string, customKeys: string[],
 *   carriers: {display: ?string, direction: ?string, tracks:
 *   ?string}}} state
 *   The current arrangement state (arrangementState()).
 * @param {string} mode
 *   'stack', 'row' or 'columns'.
 *
 * @return {Object[]}
 */
export const modeOps = (key, state, mode) => {
  const changes = [];
  const clear = (target, property, condition) => {
    const change = changes.find(
      (candidate) =>
        candidate.key === target && candidate.condition === condition,
    );
    if (change) {
      change.properties[property] = null;
    } else {
      changes.push({
        key: target,
        condition,
        properties: { [property]: null },
      });
    }
  };
  // A property no block carries rides a claiming block's op (one
  // takeover op per claiming block, never a no-op unset on another).
  const { display, direction, tracks } = state.carriers;
  const claiming = display ?? direction ?? tracks ?? 'default';
  for (const customKey of state.customKeys) {
    Object.entries(CUSTOM_PROPERTIES).forEach(([carrier, property]) => {
      clear(customKey, property, state.carriers[carrier] ?? claiming);
    });
  }
  const variant = MODE_VARIANTS[mode] ?? null;
  const operations = compoundOperations(changes);
  if (variant !== null && state.wearing !== variant) {
    operations.push({ op: 'apply_variant', key, variant });
  }
  if (mode === 'stack' && state.wearing !== null) {
    operations.push({ op: 'remove_variant', key, variant: state.wearing });
  }
  return operations;
};

/**
 * Whether the Layout section owns this selection's gap story: a container
 * whose children form an arrangeable flow — exactly the Layout section's
 * own render gate. The spacing control's gap row yields on these
 * selections (one gap surface per container, the layout widget's), and
 * keeps serving
 * everything else the standard gapGate opens: blocks laying out multiple
 * fields, and any container the node tree cannot arrange.
 *
 * @param {Array} tree
 *   The lean node tree (source.getTree()).
 * @param {string} key
 *   The selection's key.
 *
 * @return {boolean}
 */
export const layoutOwnsGap = (tree, key) => {
  const parsed = parseKey(key);
  return (
    parsed !== null &&
    parsed.kind === 'container' &&
    !parsed.selector &&
    containerChildren(tree, parsed.uuid) !== null
  );
};

/**
 * The batch a gap write fires — the ONE value-level primitive behind the
 * gap field row (a stop pick commits its var() reference, a scrub or a
 * typed value its literal, a settle value-matching a minted token the
 * captured reference); null is the unset/reset gesture, back to whatever
 * the layers beneath deliver at the pose (the row's placeholder hint when
 * nothing does). An override lands on the container root (the one box)
 * at the posed compound, the gap alone in both modes: the container
 * lays its children out as a flex box at every pose by declaration, so
 * the gap acts the moment it is written and no display rides along.
 *
 * @param {string} key
 *   The container's selectable key.
 * @param {?string} value
 *   The CSS value, or null for the unset gesture.
 * @param {string} condition
 *   The editing compound ('default' when unposed).
 *
 * @return {Object[]}
 */
export const gapValueOps = (key, value, condition = 'default') =>
  compoundOperations([{ key, condition, properties: { gap: value } }]);

/**
 * A Layout section row's value-space facts for one property on the
 * container ROOT: the guide's global definition and the root's resolved
 * constraints for it (its own node block, else the site-wide constraint
 * default — resolvedConstraintsFor, the offer vocabulary's lookup
 * layer). No gate lives here: the row rides the value-space spine and
 * ADAPTS to the vocabulary (a `scale:` or exhaustive `allowed:` list
 * renders chips or the well with detent scrub, an open gap the free
 * field with magnets, an alignment axis its built-in table unless the
 * guide constrains the property), and the choice row itself hides a
 * closed row minting nothing while no value is written. A guide defining
 * no such property yields an empty definition. Read here rather than in
 * the Preact surface so it stays node-testable.
 *
 * @param {Object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {string} key
 *   The container's selectable key.
 * @param {string} property
 *   The CSS property the row writes on the root.
 * @param {Object} blockTypes
 *   The blueprint's block types (source.getBlockTypes()).
 * @param {Object} sectionLayouts
 *   The blueprint's section layouts (source.getSectionLayouts()).
 *
 * @return {{definition: Object, constraints: Object}}
 */
export const rootField = (
  guide,
  key,
  property,
  blockTypes = {},
  sectionLayouts = {},
) => {
  // The root's own declaration through the ordinary resolution path, so a
  // nested container and a page band answer identically.
  const parsed = parseKey(key);
  const declaration = parsed
    ? declarationForKey(
        guide,
        parsed,
        bundleForKey(parsed, blockTypes, sectionLayouts),
      )
    : null;
  const definition = definitionFor(guide, property) ?? {};
  const constraints =
    resolvedConstraintsFor(guide, declaration, property) ?? {};
  return { definition, constraints };
};

/**
 * The batch the count stepper's + fires: a pure add_section. No style
 * write — the minted column joins at grow 1 purely by CSS (a child added
 * to a 67/33 row lands 50/25/25 with nothing written).
 *
 * @return {{operations: Object[], minted: string}}
 */
export const addColumnPlan = (containerUuid, generateUuid) => {
  const minted = generateUuid();
  return {
    operations: [
      {
        op: 'add_section',
        uuid: minted,
        location: { anchor: containerUuid, placement: 'inside' },
      },
    ],
    minted,
  };
};

/**
 * What the count stepper's minus would remove: the trailing child, with
 * the confirmation fact (an empty container removes silently, anything
 * else asks). Null when nothing may be removed (the last child stays —
 * emptying a container is canvas work, not a count gesture).
 *
 * @param {Array<{key: string, uuid: string, empty: boolean}>} children
 *   The container's children (containerChildren()).
 *
 * @return {?{key: string, uuid: string, empty: boolean, operations: Object[]}}
 */
export const removalPlan = (children) => {
  if (!Array.isArray(children) || children.length <= 1) {
    return null;
  }
  const last = children[children.length - 1];
  return {
    key: last.key,
    uuid: last.uuid,
    empty: last.empty,
    operations: [{ op: 'remove_element', element: last.uuid }],
  };
};
