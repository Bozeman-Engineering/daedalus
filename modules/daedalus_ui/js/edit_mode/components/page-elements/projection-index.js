import store from '../../store/store';
import { compose } from './compose';
import { toViewTree } from './markup-view';
import { instanceRows } from './instance-rows';
import {
  partPlacement,
  rootTag,
  partTag,
  partLabel,
} from '../../blueprint/markup-index';

/**
 * THE selection index over the panel's projected tree, BOTH tiers:
 * address → {label, kind, bundle, parent, rowParent, children, rows, tier,
 * owner?, tag?}, memoized on the mirror reference: the mirror is replaced
 * wholesale by every mutation response, so reference equality is change
 * detection.
 *
 * Addresses: `key` for data nodes (tier 'element') and `key::selector`
 * for markup nodes (tier 'part': a declared shell part placed as its own
 * element, or a minted instance carrier), the grammar style-map keys and
 * the selectable-key `::` sub form already speak. The store's
 * {selectedKey, selectedPart} pair IS an address split into (owner, part);
 * the string form is used only as this map's key (partAddress).
 *
 * One projection, one index, many readers: the selection facade's
 * labelForKey and typeForKey, keyboard traversal, the breadcrumb's
 * ancestry, drag, lift, gaps, and the resolver's presented gate all
 * read THIS map, so labels, ancestry, and the universe never disagree
 * across surfaces.
 *
 * The index composes through the SAME pure pipeline the markup view
 * renders with (compose → toViewTree with instanceRows and the
 * markup-index accessors), view-independent: shell and instance rows
 * enter whether or not the Markup toggle is on, because the toggle
 * decides how much anatomy the panel draws, not what exists on the page.
 * A hoisted part ('root' placement: the unlinked image block's img IS the
 * block root) is absent by construction, compose's placement gate mounts
 * no row for it, so no surface can select it. Sketch rows for keys the
 * model is silent on are rows like any other, so the placement predicate
 * (`has(partAddress(key, id))`) answers for them too; nothing degrades to
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
 * is not itself a presented key (a part facet commits its owner as the
 * selection, so such a row could name nothing). The element tier is
 * therefore exactly projection.js's key set; only the part tier is new.
 */

let indexSource = null;
let index = null;

/**
 * The index address of a part: `key::selector` (the owning element key and
 * the declared part selector or instance class).
 *
 * @param {string} key
 *   The owning element key.
 * @param {string} selector
 *   The part selector ('.image-block-image', '.s-ab').
 *
 * @return {string}
 */
export const partAddress = (key, selector) => `${key}::${selector}`;

/**
 * Splits an index address back into the store's (key, part) pair:
 * partAddress's inverse, the one place the `::` grammar is read. An
 * element key yields part null. Keys never contain `::` (their segments
 * are single-colon), so the first `::` is always the split.
 *
 * @param {string} address
 *   An index address (`key` or `key::selector`).
 *
 * @return {{key: string, part: ?string}}
 */
export const splitAddress = (address) => {
  const at = address.indexOf('::');
  return at === -1
    ? { key: address, part: null }
    : { key: address.slice(0, at), part: address.slice(at + 2) };
};

/**
 * The index address the store's {selectedKey, selectedPart} pair seats on:
 * the part address when the index places the part, else the owner key. A
 * facet naming a non-node (a hoisted part, which the facade's public
 * select() can still commit) rides on its element rather than on nothing,
 * so ancestry and traversal degrade the same way. Pure over a given index
 * so the node suite can drive it.
 *
 * @param {?Map<string, object>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selected element key (the owner, for a part selection).
 * @param {?string} part
 *   The selected part id, or null for an element selection.
 *
 * @return {string}
 */
export const placedAddress = (index, key, part = null) =>
  part !== null && !!index?.has(partAddress(key, part))
    ? partAddress(key, part)
    : key;

/**
 * Builds the index from the composed full tree.
 *
 * Pre-order: an owner's entry is set before its subtree walks, so a part
 * row can ask whether its owner is presented by the time it is reached.
 */
const buildIndex = (state) => {
  const map = new Map();
  const tree = toViewTree(
    compose(state.blueprint, drupalSettings.daedalusUi?.anatomy, {
      root: state.root,
      targets: true,
      placement: partPlacement,
    }),
    instanceRows,
    { root: rootTag, part: partTag, label: partLabel },
  );
  // Walks one view node; returns what hoists to the caller: the element
  // keys for its `children` and the addresses for its `rows`.
  const walk = (node, elementParent, rowParent) => {
    const address = node.pseudo
      ? partAddress(node.parentKey, node.id)
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
          owner: node.parentKey,
          tag: node.tag ?? null,
        }
      : {
          label: node.label,
          kind: node.kind,
          bundle: node.bundle ?? null,
          parent: elementParent,
          rowParent,
          children: [],
          rows: [],
          tier: 'element',
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
 * @return {?Map<string, {label: string, kind: string, bundle: ?string, parent: ?string, rowParent: ?string, children: string[], rows: string[], tier: 'element'|'part', owner?: string, tag?: ?string}>}
 */
export const projectionIndex = () => {
  const state = store.getState().blueprint;
  if (!state.blueprint || !state.root) {
    return null;
  }
  if (indexSource !== state.blueprint) {
    indexSource = state.blueprint;
    index = buildIndex(state);
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
