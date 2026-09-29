import store from '../../store/store';
import { compose } from './compose';
import { toViewTree } from './markup-view';
import { instanceRows } from './instance-rows';
import { generatedRows, withGeneratedRows } from './generated-rows';
import { getPartBehavior } from './view-registry';
import {
  partPlacement,
  rootTag,
  partTag,
  partLabel,
} from '../../blueprint/markup-index';

/**
 * THE selection index over the panel's projected tree, BOTH tiers:
 * address → {label, elementType?, kind, bundle, parent, rowParent,
 * children, rows, tier, source, owner?, tag?}, memoized on what it reads
 * by reference: the
 * mirror (replaced wholesale by every mutation response), the materials
 * record set (replaced by every delta) and the registered part behavior,
 * so reference equality is change detection.
 *
 * EXISTENCE, not reach. This map answers what addresses are on the page,
 * view-independently: shell, instance and box rows enter whether or not
 * the Markup toggle is on. Which of them the user may currently land on
 * (the pointer's depth stop, the keyboard's next row, the breadcrumb's
 * crumbs) is REACH, the view's question, answered by reach.js as a
 * predicate over this same map: each entry records its `source`, the
 * row's origin as stamped where the row was composed ('element' for a
 * model row, 'instance' for a minted rich-text carrier, a view id, the
 * Markup view's for a declared shell row and a generated box), and reach
 * is `depicted(source, view visibility)`. Seats, labels and the
 * programmatic doorways (the facade's target(), the switcher, code mode,
 * the AI) address existence and never consult reach; entry roads do.
 *
 * Addresses: `key` for data nodes (tier 'element') and `key::selector`
 * for markup nodes (tier 'part': a declared shell part placed as its own
 * element, a minted instance carrier, or a generated box `&::before` /
 * `&::after` the part behavior's cascade delivers, generated-rows.js),
 * the grammar style-map keys already speak (`{root}::{relative
 * selector}`). The store's selection plus its styling target IS an
 * address split into (owner, selector); the string form is used only as
 * this map's key (selectorAddress). Rows exist only for placed shell
 * parts, instance carriers and delivered boxes: a target the index does
 * not place (the children '> *', a type '> pb-text', an ancestor rule, a
 * box nothing delivers) seats on the selected element, as does a placed
 * row the panel is not currently drawing (reach.js seatAddress).
 *
 * One projection, one index, many readers: the selection facade's
 * labelForKey and typeForKey, keyboard traversal, the breadcrumb's
 * ancestry, drag, lift, gaps, the resolver's presented gate and reach.js
 * all read THIS map, so labels, ancestry, existence and reach never
 * disagree across surfaces. An element entry's `label` and `elementType`
 * are the row's, which are the server-minted `_element.name` and
 * `_element.type` (projection.js): the two resolvers copy words out of
 * this map, they derive none. Part entries carry a label only (a part's
 * word is the matched-form label or the declared one).
 *
 * The index composes through the SAME pure pipeline the markup view
 * renders with (compose → toViewTree with instanceRows and the
 * markup-index accessors, then withGeneratedRows), the fullest
 * composition any view draws, so every row a view can render has its
 * entry here; a view only ever draws a subset of what exists.
 * A hoisted part ('root' placement: the unlinked image block's img IS the
 * block root) is absent by construction, compose's placement gate mounts
 * no row for it, so no surface can select it. Sketch rows for keys the
 * model is silent on are rows like any other, so the placement predicate
 * (`has(selectorAddress(key, id))`) answers for them too; nothing degrades to
 * the DOM.
 *
 * Two child lists, two questions (not a duplication):
 * - `children`: element keys only, parts transparent. Structure consumers
 *   (drag, lift, gaps, the resolver's parent walk) read it unchanged.
 * - `rows`: the ordered addresses of both tiers as the panel renders them
 *   (shells first, then the children mounting at that position, then
 *   instance rows). Traversal and the breadcrumb read it.
 * `parent` is the element-tier parent for an element entry (parts
 * transparent) and the row parent, the owner key or the enclosing part
 * address, for a part entry. `rowParent` is the row parent for both: the
 * entry whose `rows` lists this address, so a field claimed into a shell
 * knows the shell it sits in while structure consumers never see it.
 *
 * Transparent rows hoist their descendants into the nearest row ancestor:
 * keyless region and value rows, absorbed model rows (their identity
 * merged into the row that presents the content, so their key leaves the
 * universe exactly as in the built-in projection), and a part whose owner
 * is not itself a presented key (a part target selects its owner, so
 * such a row could name nothing). The element tier is
 * therefore exactly projection.js's key set; only the part tier is new.
 */

let indexSource = null;
let index = null;

/**
 * What the index reads, by reference: a change in any of the three
 * re-derives it (the generated-box rows read all three through
 * generated-rows.js; the rest of the tree rides the mirror alone).
 */
const indexSourceOf = (state) => ({
  mirror: state.blueprint.blueprint,
  records: state.materials.records,
  behavior: getPartBehavior(),
});

/**
 * The index address of a selector under an owner: `key::selector` (the
 * owning element key and the canonical relative selector, a declared
 * part's selector or an instance class for the rows the index places).
 *
 * @param {string} key
 *   The owning element key.
 * @param {string} selector
 *   The selector ('.image-block-image', '.s-ab').
 *
 * @return {string}
 */
export const selectorAddress = (key, selector) => `${key}::${selector}`;

/**
 * Splits an index address back into the store's (key, selector) pair:
 * selectorAddress's inverse, the one place the `::` grammar is read. An
 * element key yields selector null. Keys never contain `::` (their
 * segments are single-colon), so the first `::` is always the split, even
 * for a box's selector, which carries its own (`block:x::&::before` splits
 * into `block:x` and `&::before`).
 *
 * @param {string} address
 *   An index address (`key` or `key::selector`).
 *
 * @return {{key: string, selector: ?string}}
 */
export const splitAddress = (address) => {
  const at = address.indexOf('::');
  return at === -1
    ? { key: address, selector: null }
    : { key: address.slice(0, at), selector: address.slice(at + 2) };
};

/**
 * Builds the index from the composed full tree.
 *
 * Pre-order: an owner's entry is set before its subtree walks, so a part
 * row can ask whether its owner is presented by the time it is reached.
 */
const buildIndex = (state) => {
  const map = new Map();
  const tree = withGeneratedRows(
    toViewTree(
      compose(state.blueprint, drupalSettings.daedalusUi?.anatomy, {
        root: state.root,
        targets: true,
        placement: partPlacement,
      }),
      instanceRows,
      { root: rootTag, part: partTag, label: partLabel },
    ),
    generatedRows,
  );
  // Walks one view node; returns what hoists to the caller: the element
  // keys for its `children` and the addresses for its `rows`.
  const walk = (node, elementParent, rowParent) => {
    const address = node.pseudo
      ? selectorAddress(node.parentKey, node.id)
      : (node.key ?? null);
    const isRow = node.pseudo
      ? map.has(node.parentKey)
      : !!address && !node.absorbed;
    if (!isRow) {
      const hoisted = { children: [], rows: [] };
      for (const child of node.children) {
        const collected = walk(child, elementParent, rowParent);
        hoisted.children.push(...collected.children);
        hoisted.rows.push(...collected.rows);
      }
      return hoisted;
    }
    if (node.pseudo && typeof node.source !== 'string') {
      // Every pseudo row is stamped where it is composed (toViewTree,
      // toInstanceRow, toGeneratedRow); one without a source could never
      // answer reach, so it is a composition bug, not a row.
      throw new Error(`Pseudo row ${address} carries no source.`);
    }
    const entry = node.pseudo
      ? {
          label: node.label,
          kind: 'part',
          bundle: null,
          parent: rowParent,
          rowParent,
          children: [],
          rows: [],
          tier: 'part',
          source: node.source,
          owner: node.parentKey,
          tag: node.tag ?? null,
        }
      : {
          label: node.label,
          elementType: node.elementType ?? null,
          kind: node.kind,
          bundle: node.bundle ?? null,
          parent: elementParent,
          rowParent,
          children: [],
          rows: [],
          tier: 'element',
          source: 'element',
        };
    map.set(address, entry);
    const nextElementParent = node.pseudo ? elementParent : address;
    for (const child of node.children) {
      const collected = walk(child, nextElementParent, address);
      entry.children.push(...collected.children);
      entry.rows.push(...collected.rows);
    }
    return {
      children: node.pseudo ? [...entry.children] : [address],
      rows: [address],
    };
  };
  walk(tree, null, null);
  return map;
};

/**
 * The memoized projection index, or null before the mirror boots.
 *
 * @return {?Map<string, {label: string, elementType?: ?string, kind: string, bundle: ?string, parent: ?string, rowParent: ?string, children: string[], rows: string[], tier: 'element'|'part', source: string, owner?: string, tag?: ?string}>}
 */
export const projectionIndex = () => {
  const state = store.getState();
  if (!state.blueprint.blueprint || !state.blueprint.root) {
    return null;
  }
  const source = indexSourceOf(state);
  if (
    indexSource?.mirror !== source.mirror ||
    indexSource?.records !== source.records ||
    indexSource?.behavior !== source.behavior
  ) {
    indexSource = source;
    index = buildIndex(state.blueprint);
  }
  return index;
};

/**
 * The projection root's key (the page-entity row — the walk's first keyed
 * node), or null before the mirror boots. Every part entry carries a
 * parent, so the first parentless entry is always an element.
 */
export const projectionRoot = () => {
  const rooted = projectionIndex();
  if (!rooted) {
    return null;
  }
  for (const [key, entry] of rooted) {
    if (!entry.parent) {
      return key;
    }
  }
  return null;
};
