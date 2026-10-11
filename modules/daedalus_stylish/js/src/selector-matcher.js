/**
 * @file
 * The selector matcher: which elements of the blueprint tree a relative
 * selector reaches.
 *
 * The client twin of the server's SelectorMatcher
 * (src/Selector/SelectorMatcher.php, authoritative), locked by
 * SelectorMatcherTest's shared battery through
 * tests/js/selector-matcher-cases.mjs. Both read the same input: the
 * delivered blueprint's lean node tree (`layout`: kind-discriminated
 * container, region and block nodes carrying daedalus_ui's `_element`
 * annotations; the leader reads it off the mirror, the satellite
 * receives it by broadcast).
 *
 * A rule `{owner}::{selector}` is evaluated the way the browser evaluates
 * the printed `@scope ([data-selectable="{owner}"]) { targets }`: the
 * subject (the last compound) must be in scope (the owner or a
 * descendant), the compounds before it are ancestor or sibling
 * constraints the scope does not bound, a leading `>` anchors to the
 * owner (`:scope >`), and the chain walks the tree as it walks the DOM:
 * `>` and descent along the parent axis, `+` and `~` along the parent's
 * ordered children. Per compound: `*` matches every node (region
 * wrappers included, being real elements between a multi-region section
 * and its blocks); a type matches by the element address the stamp
 * carries (`layout:{id}`, `block_type:{bundle}`, `block_plugin:{base
 * id}`; the two kind families by prefix, as CssPrinter::addressMembers
 * selects); a tag matches a node whose resolved root tag
 * (`_element.markup.tag`) is the tag; a part class matches no node
 * (anatomy inside a root is not a tree fact, never over-claimed); a
 * compound's qualifiers narrow it on the node: `:has()` holds when its
 * argument, anchored at the node (a descendant lead strictly inside it,
 * `>` a child, `+` the next sibling, `~` a later one), matches some node
 * of the tree, or, for one unqualified tag reached by descent, when the
 * node carries the tag inside (the bag below); `:not()` holds when its
 * compound does not match the node, a part class there judged by the
 * node's own anatomy (`parts`).
 *
 * A node's anatomy is a fact of the node, not of the tree: `parts` is
 * the annotation's declared part tree flattened to `selector → tag`
 * (`_element.markup.parts`, nested parts with their own tags, a hoisted
 * part at the root's tag, a part declaring no tag kept with null, the
 * unknown), then the minted instance elements the node's rich text
 * carries (`_element.markup.instances` on a composed render, each
 * `_element.fields.NAME.markup.instances` on an open one, rows nesting
 * through `children`; each an identity class → its tag, never shadowing
 * a declared part), and `tags` (the "is there a {tag} inside" bag)
 * carries every part's tag beside the rich text's, so the tag tier, the
 * completion's inner roster (presentRoster) and reaches() all see the
 * anchor a link part renders and the paragraph a minted instance is.
 * The bag has no order or depth, so nothing sibling-led, child-led or
 * qualified is ever answered inside a node: `> p + p` matches p-rooted
 * blocks after a p-rooted sibling, never a rich text's inner paragraphs,
 * and `:has(> a)` on a block is false where `:has(a)` reads the bag.
 *
 * reaches() is the stack's question: does an ancestor's rule land on
 * this element's root (its own entry), inside it on a tag (its `::{tag}`
 * tier: an unqualified tag subject reached by descent from a prefix
 * matching the element or an ancestor, when the element carries the tag
 * inside, in its rich text or as a part), or on one of its parts (a
 * class subject right after a type compound matching the element; or a
 * tag subject equal to the part's rendered tag, reached by descent
 * exactly as the tag tier is: the container's `a` rule styles the link
 * part's anchor)? Anatomy is told from identity at the class subject: a
 * declared part class is the type's anatomy, so the chain reaches
 * whether or not this node renders the part (a legal address either
 * way); an identity class (INSTANCE_PART, minted onto one element in
 * the world) reaches only a node whose parts carry it. A `>`, `+` or
 * `~` into a tag subject, or a qualified
 * one, cannot be backed by the tree (the annotation carries no depth
 * under a root), so it never reaches inside, parts included. A terminal
 * pseudo-element is a box with no node (it exists iff the cascade sets
 * `content`), so the tree never sees it: matches and matching answer a
 * pseudo rule's HOSTS (`> pb-text::before` matches the text blocks,
 * `&::before` the owner itself), and reaches compares the pseudo by
 * name (the rule's and the position's must agree) before answering the
 * host as it would the pseudo-free position. Structural facts evaluate
 * against the sibling index by their printed wrapper (satisfies,
 * structuralHolds), the twin of the browser's own matching for the
 * parameterless pseudo-classes.
 *
 * presentRoster() and typeOfAddress() are the roster over the facts:
 * the one "what is actually here" derivation the code view's selector
 * completion offers from (the types and tags a matched set carries).
 */
import {
  CHILD,
  CLASS_PART,
  DESCENDANT,
  HAS,
  NEXT,
  SUBSEQUENT,
  SUFFIX,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
  parseSelector,
  typeAddress,
  typeName,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { INSTANCE_PART } from './parts.js';
import { blockPluginBase } from './style-guide.js';

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const nonEmptyString = (value) =>
  typeof value === 'string' && value !== '' ? value : null;

/**
 * Whether a structural pseudo-class holds at a sibling position.
 *
 * The tree twin of the browser's own matching for the parameterless
 * structural facts (plus odd and even, the two parameterless spellings
 * of :nth-child) and their :not() negations. The wrapper IS the
 * pseudo-class the printer emits, so evaluating it reads the same text
 * the browser will; a wrapper outside this vocabulary (a site's
 * :nth-child(3n+1)) is unknown and never holds, which a row reads as
 * unlit rather than a promise. Each negation is spelled out rather than
 * derived from a :not() prefix so that an unknown wrapper inside :not()
 * stays unknown instead of holding by negation.
 *
 * @param {string} wrapper
 *   The condition's pseudo-class wrapper.
 * @param {{index: number, count: number, empty: boolean}} place
 *   The 0-based sibling index, the sibling count, and whether the
 *   element is empty.
 */
export const structuralHolds = (wrapper, { index, count, empty }) => {
  switch (wrapper) {
    case ':first-child':
      return index === 0;
    case ':last-child':
      return index === count - 1;
    case ':only-child':
      return count === 1;
    case ':empty':
      return empty;
    case ':nth-child(odd)':
      return index % 2 === 0;
    case ':nth-child(even)':
      return index % 2 === 1;
    case ':not(:first-child)':
      return index !== 0;
    case ':not(:last-child)':
      return index !== count - 1;
    case ':not(:only-child)':
      return count !== 1;
    case ':not(:empty)':
      return !empty;
    default:
      return false;
  }
};

/**
 * Whether an element address is a member of a type compound's family
 * (the structural twin of CssPrinter::addressMembers).
 */
export const typeMatches = (type, address) => {
  if (type === TYPE_CONTAINER) {
    return address.startsWith('layout:');
  }
  if (type === TYPE_BLOCK) {
    return address.startsWith('block_type:') || address.startsWith('block_plugin:');
  }
  return address === typeAddress(type);
};

/**
 * The facts one node carries, before its place among its siblings
 * (SelectorMatcher::nodeFacts mirror).
 */
const nodeFacts = (node) => {
  const kind =
    typeof node.kind === 'string'
      ? node.kind
      : 'layout_id' in node
        ? 'container'
        : 'block';
  const uuid = nonEmptyString(node.uuid);
  let key = null;
  let address = null;
  if (kind === 'container') {
    key = uuid === null ? null : `container:${uuid}`;
    const layoutId = nonEmptyString(node.layout_id);
    address = layoutId === null ? null : `layout:${layoutId}`;
  } else if (kind === 'block') {
    key = uuid === null ? null : `block:${uuid}`;
    const blockType = nonEmptyString(node.block_type);
    const pluginBase = blockPluginBase(node);
    if (blockType !== null) {
      address = `block_type:${blockType}`;
    } else if (pluginBase !== null) {
      // The base plugin (a field node's is field_block), as
      // NodeTreeBuilder::pluginBase derives it for ElementAddress::blockPlugin.
      address = `block_plugin:${pluginBase}`;
    }
  }
  const markup = isPlainObject(node._element?.markup) ? node._element.markup : {};
  const tag = nonEmptyString(markup.tag);
  // The tags the node's rich text carries: on the block's own markup for
  // a composed render, per field for an open one; both render inside the
  // root, so the union answers "is there a {tag} inside".
  const tags = [];
  const collect = (list) => {
    (Array.isArray(list) ? list : []).forEach((name) => {
      if (typeof name === 'string' && name !== '' && !tags.includes(name)) {
        tags.push(name);
      }
    });
  };
  collect(markup.tags);
  Object.values(isPlainObject(node._element?.fields) ? node._element.fields : {}).forEach(
    (field) => collect(field?.markup?.tags),
  );
  // The anatomy: the declared part tree flattened, a nested part right
  // after its parent with its own tag, then the hoisted parts (the root's
  // own element wearing the class) at the root's tag. A part declaring
  // no tag is kept with null: the part exists, its tag is unknown, and
  // an unknown never matches a rule.
  const parts = {};
  const flatten = (list) => {
    Object.entries(isPlainObject(list) ? list : {}).forEach(([selector, part]) => {
      if (selector === '' || !isPlainObject(part)) {
        return;
      }
      parts[selector] = nonEmptyString(part.tag);
      flatten(part.parts);
    });
  };
  flatten(markup.parts);
  (Array.isArray(markup.hoisted) ? markup.hoisted : []).forEach((selector) => {
    if (typeof selector === 'string' && selector !== '') {
      parts[selector] = tag;
    }
  });
  // The identity: the minted instance elements the rich text carries,
  // each row's class as the part selector at the row's tag, nested
  // carriers after their parent in document order; riding the composed
  // block's own markup or, for an open render, each field's. An instance
  // never shadows a declared part: declared anatomy is the type's word,
  // and it was folded first.
  const fold = (rows) => {
    (Array.isArray(rows) ? rows : []).forEach((row) => {
      if (!isPlainObject(row)) {
        return;
      }
      const className = nonEmptyString(row.class);
      if (className !== null && !Object.hasOwn(parts, `.${className}`)) {
        parts[`.${className}`] = nonEmptyString(row.tag);
      }
      fold(row.children);
    });
  };
  fold(markup.instances);
  Object.values(isPlainObject(node._element?.fields) ? node._element.fields : {}).forEach(
    (field) => fold(field?.markup?.instances),
  );
  // A part's tag is inside the root as much as the prose's tags are.
  collect(Object.values(parts));
  return {
    key,
    kind,
    address,
    tag,
    tags,
    parts,
    // The element-naming spine, minted by daedalus_ui's annotator and
    // riding the tree: what the element is called, what it is, and the
    // glyph it wears (a URL, or null when nothing answered the icon
    // collector), one spine for the word and the face.
    name: nonEmptyString(node._element?.name),
    type: nonEmptyString(node._element?.type),
    icon: nonEmptyString(node._element?.icon),
  };
};

/**
 * The type compound a node's address spells.
 *
 * `pb-{bundle}` for a block type; the kind spellings for everything the
 * page language names by kind: a container layout (`layout:…`) is
 * `pb-container`, a plugin block (`block_plugin:…`) is `pb-block`.
 *
 * @param {?string} address
 *   A node's root address (the matcher's facts).
 *
 * @return {?string}
 */
export const typeOfAddress = (address) => {
  if (address === null || address === undefined) {
    return null;
  }
  return (
    typeName(address) ??
    (address.startsWith('layout:') ? TYPE_CONTAINER : TYPE_BLOCK)
  );
};

/**
 * The types and tags a set of matched nodes carries, in first-seen order.
 *
 * The one derivation behind every "what is actually here" offer: the
 * code view's selector completion reads it over the matcher's facts.
 * Types come from each node's address (typeOfAddress); tags are each
 * node's root tag, plus (with `inner`) the tags inside it, its prose's
 * and its parts', for a descendant position where a rule reaches inside
 * a block (a container's completion offers `a` when a child's link part
 * renders one).
 *
 * @param {Array<object>} facts
 *   Matcher facts ({address, tag, tags}) for the matched nodes.
 * @param {{inner?: boolean}} [options]
 *
 * @return {{types: string[], tags: string[]}}
 */
export const presentRoster = (facts, { inner = false } = {}) => {
  const types = [];
  const tags = [];
  const note = (list, name) => {
    if (name && !list.includes(name)) {
      list.push(name);
    }
  };
  (Array.isArray(facts) ? facts : []).forEach((fact) => {
    if (!fact) {
      return;
    }
    note(types, typeOfAddress(fact.address ?? null));
    note(tags, fact.tag ?? null);
    if (inner) {
      (Array.isArray(fact.tags) ? fact.tags : []).forEach((tag) =>
        note(tags, tag),
      );
    }
  });
  return { types, tags };
};

/**
 * Builds a matcher over a lean node tree.
 *
 * @param {?Array} tree
 *   The ordered top-level container nodes (the blueprint's `layout`).
 *
 * @return {{has: Function, facts: Function, ancestors: Function,
 *   matches: Function, matching: Function, reaches: Function,
 *   satisfies: Function}}
 *   The matcher: has(key), facts(key) → {key, kind, address, tag, tags,
 *   parts, name, type, icon, index, count, childCount, parent} or null
 *   (`parts` the present declared parts and the minted instances inside,
 *   selector → tag, null for a part declaring no tag; `name`, `type` and
 *   `icon` the words and the glyph the annotator minted on the node,
 *   `_element.name`, `_element.type` and `_element.icon`, the one
 *   element-naming vocabulary every surface reads), ancestors(key) →
 *   addressable ancestor keys FARTHEST first,
 *   matches(owner, selector, candidate), matching(owner, selector) →
 *   keys in document order (the hosts for a rule ending in a pseudo),
 *   reaches(owner, selector, candidate, sub) with sub null (the root),
 *   a bare tag, a part class, or any of the three with its terminal
 *   pseudo (`&::before`, `a::before`, `.cls::after`), satisfies(key,
 *   wrapper). A selector (rule or sub) is text or parseSelector's record
 *   `{compounds, pseudo}`; a bare compounds array has dropped the
 *   pseudo and is refused. A selector outside the grammar throws a
 *   TypeError: a rule nothing can print is a caller bug.
 */
export const createMatcher = (tree) => {
  const nodes = [];
  const byKey = new Map();
  // The top-level node indexes in order (the root nodes' sibling list).
  const roots = [];
  const index = (list, parent) => {
    const siblings = (Array.isArray(list) ? list : []).filter(isPlainObject);
    siblings.forEach((node, position) => {
      const id = nodes.length;
      const children = (Array.isArray(node.children) ? node.children : []).filter(isPlainObject);
      const facts = {
        ...nodeFacts(node),
        index: position,
        count: siblings.length,
        childCount: children.length,
        parent,
        children: [],
      };
      nodes.push(facts);
      if (facts.key !== null) {
        byKey.set(facts.key, id);
      }
      if (parent === null) {
        roots.push(id);
      } else {
        nodes[parent].children.push(id);
      }
      index(children, id);
    });
  };
  index(tree, null);

  // The selector record ({compounds, pseudo}, parseSelector's shape) from
  // text or an already parsed record; a bare compounds array is refused
  // because it has dropped the pseudo, and text outside the grammar is a
  // caller bug.
  const selectorOf = (selector) => {
    const parsed =
      typeof selector === 'string'
        ? parseSelector(selector)
        : isPlainObject(selector) && Array.isArray(selector.compounds)
          ? selector
          : null;
    if (parsed === null) {
      throw new TypeError(`"${String(selector)}" is not a relative selector.`);
    }
    return parsed;
  };
  const inScope = (node, root) => {
    for (let at = node; at !== null; at = nodes[at].parent) {
      if (at === root) {
        return true;
      }
    }
    return false;
  };
  // The node's ancestors, nearest first.
  const ancestorsOf = (id) => {
    const ancestors = [];
    for (let at = nodes[id].parent; at !== null; at = nodes[at].parent) {
      ancestors.push(at);
    }
    return ancestors;
  };
  // The ordered sibling list a node belongs to (the parent's children,
  // or the top-level list for a root node; the node sits at its own
  // `index` in it), and the node's earlier siblings, nearest first.
  const siblings = (id) => {
    const { parent } = nodes[id];
    return parent === null ? roots : nodes[parent].children;
  };
  const earlierSiblings = (id) => siblings(id).slice(0, nodes[id].index).reverse();
  // Whether the node's facts match a compound's subject: `*` and an
  // implied subject (a `:not()` argument of qualifiers alone) match
  // every node; a tag the node's root tag; a type the node's address by
  // family. A part class matches no node in a chain (anatomy inside a
  // root is not a tree fact, never over-claimed) but IS judged as a
  // `:not()` argument, the one compound carrying no combinator: a node
  // whose parts carry the class, hoisted onto its root or nested inside
  // it, wears the class and is what `:not(.cls)` excludes (the
  // flattened parts do not tell the two apart, and excluding both is
  // the reading that never over-claims a reach).
  const subjectMatches = (id, compound) => {
    const facts = nodes[id];
    switch (compound.kind) {
      case null:
      case UNIVERSAL:
        return true;
      case TAG:
        return facts.tag !== null && facts.tag === compound.name;
      case TYPE:
        return facts.address !== null && typeMatches(compound.name, facts.address);
      case CLASS_PART:
        return compound.combinator === null && Object.hasOwn(facts.parts, compound.name);
      default:
        return false;
    }
  };
  // Whether one node matches one compound: its subject, then every
  // qualifier (`:has()` by hasHolds, `:not()` when its compound does not
  // match the node).
  const compoundMatches = (id, compound) =>
    subjectMatches(id, compound) &&
    compound.qualifiers.every((qualifier) =>
      qualifier.name === HAS
        ? hasHolds(id, qualifier.argument)
        : !compoundMatches(id, qualifier.argument),
    );
  // Whether a `:has()` argument holds at a node: the argument is a
  // relative selector anchored at the node, holding when any node of the
  // tree matches it with its lead related to this node (chainMatches
  // anchored). The tree stops at element roots, so an argument reaching
  // INSIDE a block is answered by the annotation's tag bag instead,
  // which carries neither order nor depth: only one unqualified tag
  // reached by descent can be read there (`:has(a)` on a block whose
  // link part renders an anchor holds; `:has(> a)` never does).
  const hasHolds = (id, argument) => {
    const n = argument.length;
    const [lead] = argument;
    if (
      n === 1 &&
      lead.kind === TAG &&
      lead.combinator === DESCENDANT &&
      !lead.qualifiers.length &&
      nodes[id].tags.includes(lead.name)
    ) {
      return true;
    }
    return [...nodes.keys()].some((candidate) => chainMatches(candidate, argument, n - 1, id, true));
  };
  // The engine's right-to-left walk: compound i on the node, then its
  // combinator decides where compound i - 1 must match (the parent for
  // `>`, any ancestor for descent, the previous sibling for `+`, any
  // earlier sibling for `~`, siblings read in the parent's ordered
  // children). The first compound's combinator is its lead, related to
  // the anchor: at the top level the anchor is the scope root, `>`
  // anchors the parent to it and the descendant lead anchors nothing (a
  // descendant-first chain prints without `:scope`; the scope bounds the
  // subject alone, inScope); inside `:has()` the anchor is the qualified
  // node and every lead relates to it (descent strictly inside it, `>` a
  // child of it, `+` right after it, `~` anywhere after it).
  const chainMatches = (id, compounds, i, anchor, anchored) => {
    if (!compoundMatches(id, compounds[i])) {
      return false;
    }
    const { combinator } = compounds[i];
    const { parent } = nodes[id];
    if (i === 0) {
      switch (combinator) {
        case DESCENDANT:
          return !anchored || (id !== anchor && inScope(id, anchor));
        case CHILD:
          return parent === anchor;
        case NEXT:
          return (earlierSiblings(id)[0] ?? null) === anchor;
        case SUBSEQUENT:
          return earlierSiblings(id).includes(anchor);
        default:
          return false;
      }
    }
    let candidates;
    switch (combinator) {
      case DESCENDANT:
        candidates = ancestorsOf(id);
        break;
      case CHILD:
        candidates = parent === null ? [] : [parent];
        break;
      case NEXT:
        candidates = earlierSiblings(id).slice(0, 1);
        break;
      case SUBSEQUENT:
        candidates = earlierSiblings(id);
        break;
      default:
        candidates = [];
    }
    return candidates.some((at) => chainMatches(at, compounds, i - 1, anchor, anchored));
  };
  const addressableParent = (id) => {
    for (let at = nodes[id].parent; at !== null; at = nodes[at].parent) {
      if (nodes[at].key !== null) {
        return nodes[at].key;
      }
    }
    return null;
  };
  const rootOf = (key) => {
    const pos = typeof key === 'string' ? key.indexOf(SUFFIX) : -1;
    return pos === -1 ? key : key.slice(0, pos);
  };
  // A descendant subject sits INSIDE the node, so the chain before it
  // must match the node itself or any ancestor (the engine's walk from
  // the subject's parent upward); a lone subject reaches by scope alone.
  const descentReaches = (node, compounds, root) => {
    const n = compounds.length;
    if (n === 1) {
      return true;
    }
    for (let at = node; at !== null; at = nodes[at].parent) {
      if (chainMatches(at, compounds, n - 2, root, false)) {
        return true;
      }
    }
    return false;
  };

  // Whether the owner's rule targets the candidate's root. A rule ending
  // in a pseudo-element targets the generated boxes of its hosts, and the
  // host is what the tree can name: `> pb-text::before` matches every
  // text block child (the box hanging on each), the owner's own pseudo
  // (`&::before`, no compounds) the owner alone. Whether the rule
  // DELIVERS at a key is reaches' question, where the pseudo must agree.
  const matches = (owner, selector, candidate) => {
    const { compounds } = selectorOf(selector);
    const root = byKey.get(owner) ?? null;
    const node = byKey.get(candidate) ?? null;
    if (root === null || node === null || !inScope(node, root)) {
      return false;
    }
    if (!compounds.length) {
      return node === root;
    }
    return chainMatches(node, compounds, compounds.length - 1, root, false);
  };

  return {
    has: (key) => byKey.has(key),

    facts: (key) => {
      const id = byKey.get(key) ?? null;
      if (id === null) {
        return null;
      }
      const node = nodes[id];
      return {
        key: node.key,
        kind: node.kind,
        address: node.address,
        tag: node.tag,
        tags: node.tags,
        parts: node.parts,
        name: node.name,
        type: node.type,
        icon: node.icon,
        index: node.index,
        count: node.count,
        childCount: node.childCount,
        parent: addressableParent(id),
      };
    },

    ancestors: (key) => {
      const id = byKey.get(key) ?? null;
      if (id === null) {
        return [];
      }
      const keys = [];
      for (let at = nodes[id].parent; at !== null; at = nodes[at].parent) {
        if (nodes[at].key !== null) {
          keys.push(nodes[at].key);
        }
      }
      return keys.reverse();
    },

    matches,

    // The hosts, for a rule ending in a pseudo-element (see matches).
    matching: (owner, selector) => {
      const { compounds } = selectorOf(selector);
      const root = byKey.get(owner) ?? null;
      if (root === null) {
        return [];
      }
      if (!compounds.length) {
        return [nodes[root].key];
      }
      const keys = [];
      nodes.forEach((node, id) => {
        if (
          node.key !== null &&
          inScope(id, root) &&
          chainMatches(id, compounds, compounds.length - 1, root, false)
        ) {
          keys.push(node.key);
        }
      });
      return keys;
    },

    // `sub` is the candidate's own key position the rule is asked about,
    // as the relative selector the key's suffix spells (text or the
    // parsed record; the grammar structures it into a host and the
    // terminal pseudo or none): null the root, `p` the tag tier, `.cls`
    // a part, `&::before` the root's own generated box, `.cls::after`
    // the part's, `a::before` the tag tier's. A pseudo-element is a box
    // with no node, so it is compared by name: the rule's and the
    // position's must agree (none on both, or the same one), then the
    // host is answered exactly as the pseudo-free position is. A rule
    // ending in a pseudo never reaches a pseudo-free position (its
    // styles land on generated boxes alone), and a pseudo-free rule
    // never reaches a pseudo position. Any other position shape (a
    // chain, the children, with or without a pseudo) is a rule of its
    // own, never a tier: false.
    reaches: (owner, selector, candidate, sub = null) => {
      const rule = selectorOf(selector);
      const tier = sub === null || sub === undefined ? null : selectorOf(sub);
      // The generated box is a cascade fact, not a tree fact: existence
      // holds by name alone, and a rule for one box never lands on the
      // element itself or on the other box.
      if (rule.pseudo !== (tier?.pseudo ?? null)) {
        return false;
      }
      // The root, or its own generated box (the anchor has no host
      // compound).
      if (tier === null || !tier.compounds.length) {
        return matches(owner, rule, candidate);
      }
      // The `p` or `.cls` tier is ONE unqualified compound reached by
      // descent; anything else is a rule of its own.
      const [position] = tier.compounds;
      if (
        tier.compounds.length !== 1 ||
        position.combinator !== DESCENDANT ||
        position.qualifiers.length ||
        ![TAG, CLASS_PART].includes(position.kind)
      ) {
        return false;
      }
      const { compounds } = rule;
      const root = byKey.get(owner) ?? null;
      const node = byKey.get(candidate) ?? null;
      if (root === null || node === null || !inScope(node, root) || !compounds.length) {
        return false;
      }
      const last = compounds[compounds.length - 1];
      const n = compounds.length;
      const { name } = position;
      // A tag subject sits INSIDE the node, where the annotation carries
      // a bag of tags without order or depth: only an unqualified tag
      // reached by descent can be answered there.
      const tagSubject =
        last.kind === TAG && last.combinator === DESCENDANT && !last.qualifiers.length;
      if (position.kind === TAG) {
        if (!tagSubject || last.name !== name) {
          return false;
        }
        return nodes[node].tags.includes(name) && descentReaches(node, compounds, root);
      }
      // A class subject after a type: a declared part is the type's
      // anatomy, a legal address on every node the type matches, whether
      // or not this one renders it; an identity class is minted onto one
      // element in the world, so it reaches only the node carrying it.
      if (last.kind === CLASS_PART) {
        return (
          last.name === name &&
          n >= 2 &&
          (!INSTANCE_PART.test(name) || Object.hasOwn(nodes[node].parts, name)) &&
          chainMatches(node, compounds, n - 2, root, false)
        );
      }
      if (!tagSubject) {
        return false;
      }
      const partTag = nodes[node].parts[name] ?? null;
      return partTag !== null && partTag === last.name && descentReaches(node, compounds, root);
    },

    satisfies: (key, wrapper) => {
      const id = byKey.get(rootOf(key)) ?? null;
      if (id === null) {
        return false;
      }
      const node = nodes[id];
      // A block's root holds its content, which the tree carries no
      // nodes for: a block is never empty; a container or region wrapper
      // without child nodes is.
      return structuralHolds(wrapper, {
        index: node.index,
        count: node.count,
        empty: node.kind !== 'block' && node.childCount === 0,
      });
    },
  };
};
