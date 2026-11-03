/**
 * @file
 * The selector matcher: which elements of the blueprint tree a relative
 * selector reaches.
 *
 * The client twin of the server's SelectorMatcher
 * (src/Selector/SelectorMatcher.php, authoritative), locked by
 * SelectorMatcherTest's shared battery through
 * tests/js/selector-matcher-cases.mjs. Both read the same input: the
 * DOCUMENT tree (daedalus_ui's documentTree() in
 * js/edit_mode/blueprint/document-tree.js; NodeTreeBuilder::document()
 * on the server): one top-level node of kind `entity`, the host above
 * its sections exactly as the canvas root wrapper
 * (`data-selectable="entity:{uuid}"`) stands above them in the DOM, with
 * the delivered blueprint's lean node tree (`layout`: kind-discriminated
 * container, region and block nodes carrying daedalus_ui's `_element`
 * annotations) as its children. The entity is the one root: the
 * farthest ancestor of every element, the owner whose `> *` is the
 * sections, the node whose minted words name the page. Its facts are
 * the blueprint root's own annotation (`_element.name/type/icon`, the
 * entity kind's markup); the host's fields render OUTSIDE the canvas
 * root, so no field markup folds into the root's bag. A document whose
 * root carries no uuid (a template host, the site frame) keeps the node
 * as structure nothing addresses: the sections' ancestry holds, nothing
 * names the root.
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
 * node's own anatomy (`parts`); `:is()` holds when any of its member
 * compounds matches the node, each member judged exactly as `:not()`'s
 * compound is (`> *:is(d-text, d-image)` is the two kinds;
 * `> d-text:is(:hover, :focus-within) a` reaches as `> d-text a`, states
 * being reach-neutral); a position qualifier (`:first-child`,
 * `:nth-child(2n+1)`, `:last-of-type`, `:empty` and kin, the grammar's
 * POSITIONS / NTH_POSITIONS) is judged by structuralHolds over the
 * node's place among its tree siblings (its index and count, its index
 * and count among the siblings sharing its root tag for the `-of-type`
 * forms, and whether it is empty) on a compound the tree matches by
 * node (`*`, the implied subject, a type, the anchor), and is
 * reach-neutral on a tag or class compound (holds as if absent: a tag's
 * position may be a position inside a root, where the tag bag below has
 * no order); a CLASS qualifier (`li.active`,
 * `> d-text.daedalus-stylish-variant--featured`) is judged by the node's
 * WEAR when the class is a variant class (variant-class.js: the class a
 * worn variant delivers on its element, a fact of the host's style map
 * the matcher is built with, so "the text blocks wearing Featured" is a
 * tree question) on a compound the tree matches by node, while a markup
 * class (the page's JavaScript toggles it, the tree never sees it) and
 * any class on a tag or class compound are reach-neutral; an ATTRIBUTE
 * qualifier and a pseudo-class that is no position (a state such as
 * `:hover`, an unpublished `:visited`) are reach-neutral everywhere
 * (reach is structural, and the pose lights the state); the anchor `&`,
 * the leading compound of a qualified-anchor selector (`&:first-child`,
 * `&:not(:last-child) > *`), matches the scope root alone, its
 * qualifiers judged against the owner node.
 *
 * A block's FIELD is inside its block, in a side table, never in the
 * tree: an open render's field root (`block:{uuid}:{name}`, the
 * validator's `field` key form, the key an open render's prose rows
 * hang under) is a record built from the block node's `_element.fields`
 * (one per annotated field, kind `field`, address `kind:field` as the
 * field root's stamp carries, no root tag, its tags and parts from the
 * field's own markup, its wear from the map, no minted words; a field
 * annotated `element: false` has no element in any view and gets no
 * record; a keyless block carries none). has() and facts() answer it,
 * ancestors() answers the block's ancestors plus the block (the field's
 * rows inherit the block's rules and its ancestors'), and reaches()
 * judges its tags and parts with the block's node as its place. A
 * record is never a node: `matching` never lists it, `matches` is false
 * for it (a field is not an element a rule targets; `> *` from the block
 * never sees it), no chain or `:has()` walks through it, and as an owner
 * it targets nothing (a field's own rules land inside it, where the tree
 * has no nodes).
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
 * pseudo rule's HOSTS (`> d-text::before` matches the text blocks,
 * `&::before` the owner itself), and reaches compares the pseudo by
 * name (the rule's and the position's must agree) before answering the
 * host as it would the pseudo-free position. A reader asking whether the
 * OWNER itself stands at a position (a stored fact condition's printed
 * wrapper, until the fact convergence rewrites it into the key) asks
 * matches with the wrapper as the owner's anchor qualifier
 * (`matches(key, '&' + wrapper, key)`): one judge, structuralHolds, for
 * the anchor and for every compound.
 *
 * presentRoster() and typeOfAddress() are the roster over the facts:
 * the one "what is actually here" derivation the code view's selector
 * completion offers from (the types and tags a matched set carries).
 */
import {
  ANCHOR_KIND,
  CHILD,
  CLASS_PART,
  CLASS_QUALIFIER,
  DESCENDANT,
  HAS,
  IS,
  NEXT,
  NOT,
  NTH_POSITIONS,
  POSITIONS,
  SUBSEQUENT,
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
import { fragmentClass, isVariantClass } from './variant-class.js';
import { entryRefs } from './variant-scope.js';

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const nonEmptyString = (value) =>
  typeof value === 'string' && value !== '' ? value : null;

/**
 * Whether a canonical `An+B` argument admits a 1-based position
 * (SelectorMatcher::nthHolds mirror).
 *
 * The argument is the grammar's canonical spelling (canonicalNth: `odd`,
 * `even`, `n`, `-n`, `2n+1`, `-n+3`, `n-1`, `3`, `0`); p is admitted
 * when p = An + B for an integer n >= 0. Anything else is a caller bug
 * and throws.
 */
export const nthHolds = (argument, position) => {
  if (argument === 'odd') {
    return position % 2 === 1;
  }
  if (argument === 'even') {
    return position % 2 === 0;
  }
  if (/^-?\d+$/.test(argument)) {
    return position === parseInt(argument, 10);
  }
  const match = /^(-?)(\d*)n([+-]\d+)?$/.exec(argument);
  if (match === null) {
    throw new TypeError(`"${String(argument)}" is not a canonical An+B.`);
  }
  const a = (match[2] === '' ? 1 : parseInt(match[2], 10)) * (match[1] === '-' ? -1 : 1);
  const b = match[3] === undefined ? 0 : parseInt(match[3], 10);
  if (a === 0) {
    return position === b;
  }
  const steps = position - b;
  return steps % a === 0 && steps / a >= 0;
};

/**
 * Whether a position qualifier holds at a place among siblings
 * (SelectorMatcher::structuralHolds mirror).
 *
 * The tree twin of the browser's own matching for CSS's tree-structural
 * pseudo-classes, over the grammar's record (`{name, argument}`: a
 * POSITIONS name with a null argument, an NTH_POSITIONS name with its
 * canonical `An+B`). `:nth-child(An+B)` holds at the 1-based position p
 * when p = An + B for some n >= 0 (`odd` and `even` as CSS reads them);
 * the `-last-` forms count from the end; the `-of-type` forms read the
 * place among the siblings sharing the node's root tag, and a node
 * whose tag the tree does not know is of no type: never first, last or
 * nth of it. A name outside the position vocabulary is a caller bug and
 * throws.
 *
 * @param {{name: string, argument: ?string}} qualifier
 *   The grammar's qualifier record.
 * @param {{index: number, count: number, empty: boolean, typeIndex:
 *   ?number, typeCount: ?number}} place
 *   The 0-based sibling index, the sibling count, whether the element is
 *   empty, and the index and count among the siblings sharing its tag
 *   (null for an unknown tag).
 */
export const structuralHolds = ({ name, argument = null }, { index, count, empty, typeIndex, typeCount }) => {
  switch (name) {
    case 'first-child':
      return index === 0;
    case 'last-child':
      return index === count - 1;
    case 'only-child':
      return count === 1;
    case 'empty':
      return empty;
    case 'first-of-type':
      return typeIndex === 0;
    case 'last-of-type':
      return typeIndex !== null && typeIndex === typeCount - 1;
    case 'only-of-type':
      return typeCount === 1;
    case 'nth-child':
      return nthHolds(String(argument), index + 1);
    case 'nth-last-child':
      return nthHolds(String(argument), count - index);
    case 'nth-of-type':
      return typeIndex !== null && nthHolds(String(argument), typeIndex + 1);
    case 'nth-last-of-type':
      return typeIndex !== null && nthHolds(String(argument), typeCount - typeIndex);
    default:
      throw new TypeError(`":${String(name)}" is not a position qualifier.`);
  }
};

/**
 * Whether a qualifier name is one of the grammar's position pseudo-classes.
 */
const isPosition = (name) => POSITIONS.includes(name) || NTH_POSITIONS.includes(name);

/**
 * Whether a class qualifier holds against a node's wear
 * (SelectorMatcher::classHolds mirror): a variant class holds when the
 * node wears it; a markup class is no tree fact and holds as if absent.
 */
const classHolds = (className, wear) => !isVariantClass(className) || wear.includes(className);

/**
 * The classes a node's worn variants deliver on it (SelectorMatcher::wear
 * mirror): the element entry's references, each as the class the stamp
 * carries (fragmentClass); a pair that cannot be a class name stamps
 * nothing, and a rule key's wear stamps no class and is not here.
 */
const wearOf = (map, key) => {
  const classes = [];
  (key === null || !isPlainObject(map) ? [] : entryRefs(map[key])).forEach((ref) => {
    const className = fragmentClass(ref.variant, ref.fragment);
    if (className !== null && !classes.includes(className)) {
      classes.push(className);
    }
  });
  return classes;
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
 * The anatomy a markup annotation carries, with field markups folded in
 * (SelectorMatcher::anatomy mirror): the one derivation behind a node's
 * bag and a field record's.
 *
 * @param {Object} markup
 *   The annotation's markup (`_element.markup`, or a field's
 *   `fields[name].markup`): `tag`, `tags`, `parts`, `hoisted`,
 *   `instances`.
 * @param {Array<Object>} fields
 *   The annotated fields whose markup renders inside the same root (a
 *   block's, for an open render); [] for a record over one field's markup.
 *
 * @return {{tags: string[], parts: Object<string, ?string>}}
 */
const anatomyOf = (markup, fields) => {
  const tag = nonEmptyString(markup.tag);
  // The tags the rich text carries: on the block's own markup for a
  // composed render, per field for an open one; both render inside the
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
  fields.forEach((field) => collect(field?.markup?.tags));
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
  fields.forEach((field) => fold(field?.markup?.instances));
  // A part's tag is inside the root as much as the prose's tags are.
  collect(Object.values(parts));
  return { tags, parts };
};

/**
 * The field records a keyed block node carries, before their wear
 * (SelectorMatcher::fieldRecords mirror).
 *
 * One per annotated field (`_element.fields[name]`) that has an element:
 * a field annotated `element: false` answers to its key in no view, so
 * it gets no record. Each is keyed `{block key}:{name}` (the validator's
 * `field` form), kind `field`, addressed `kind:field` (the field root's
 * stamp), with no root tag (the tree never knows the field wrapper's),
 * its tags and parts from the field's own markup, and no minted words.
 *
 * @param {Object} node
 *   The block node.
 * @param {string} key
 *   The block's key.
 *
 * @return {Array<Object>}
 */
const fieldRecords = (node, key) =>
  Object.entries(isPlainObject(node._element?.fields) ? node._element.fields : {})
    .filter(([name, field]) => name !== '' && isPlainObject(field) && field.element !== false)
    .map(([name, field]) => ({
      key: `${key}:${name}`,
      kind: 'field',
      address: 'kind:field',
      tag: null,
      ...anatomyOf(isPlainObject(field.markup) ? field.markup : {}, []),
      name: null,
      type: null,
      icon: null,
    }));

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
  if (kind === 'entity') {
    // The document's root: the host entity, addressed by the bare entity
    // kind (ElementAddress::ENTITY, the stamp the canvas root wrapper
    // carries); keyless without a uuid.
    key = uuid === null ? null : `entity:${uuid}`;
    address = 'kind:entity';
  } else if (kind === 'container') {
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
  // The annotated fields whose markup is inside the root: a block's (an
  // open render's prose renders inside the block). The entity root's
  // are NOT: the host's fields render outside the canvas root wrapper,
  // so an entity rule never reaches them in the browser and the root's
  // bag is its own markup alone.
  const fields =
    kind === 'entity'
      ? []
      : Object.values(isPlainObject(node._element?.fields) ? node._element.fields : {});
  const { tags, parts } = anatomyOf(markup, fields);
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
 * `d-{bundle}` for a block type; the kind spellings for everything the
 * page language names by kind: a container layout (`layout:…`) is
 * `d-container`, a plugin block (`block_plugin:…`) is `d-block`.
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
 * Builds a matcher over a document tree.
 *
 * @param {?Array} document
 *   The document tree (documentTree() in daedalus_ui's
 *   js/edit_mode/blueprint/document-tree.js): the one entity node, the
 *   blueprint's `layout` as its children. A bare layout is a document
 *   with no root node: the sections are then the roots and nothing
 *   stands above them.
 * @param {?Object} map
 *   The host's style map, for the wear: an element entry's variant
 *   references are the classes the element is stamped with, which a
 *   class qualifier naming one is judged against. Without a map no node
 *   wears anything, and a variant-class qualifier matches no node.
 *
 * @return {{has: Function, facts: Function, ancestors: Function,
 *   matches: Function, matching: Function, reaches: Function}}
 *   The matcher: has(key), facts(key) → {key, kind, address, tag, tags,
 *   parts, wear, name, type, icon, index, count, childCount, parent} or
 *   null (`kind` entity, container, block or field; `address` the element
 *   address, `kind:entity` for the root, `kind:field` for a block's
 *   field; `parts` the present declared
 *   parts and the minted instances
 *   inside, selector → tag, null for a part declaring no tag; `wear` the
 *   classes its worn variants deliver on it; `name`, `type` and
 *   `icon` the words and the glyph the annotator minted on the node,
 *   `_element.name`, `_element.type` and `_element.icon`, the one
 *   element-naming vocabulary every surface reads; a field record has
 *   null words, a null tag, no place (`index` and `count` null,
 *   `childCount` 0) and its block as `parent`), ancestors(key) →
 *   addressable ancestor keys FARTHEST first (a field's: its block's,
 *   then the block),
 *   matches(owner, selector, candidate), matching(owner, selector) →
 *   keys in document order (the hosts for a rule ending in a pseudo;
 *   never a field key), reaches(owner, selector, candidate, sub) with
 *   sub null (the root),
 *   a bare tag, a part class, or any of the three with its terminal
 *   pseudo (`&::before`, `a::before`, `.cls::after`). A selector (rule
 *   or sub) is text or parseSelector's record
 *   `{compounds, pseudo}`; a bare compounds array has dropped the
 *   pseudo and is refused. A selector outside the grammar throws a
 *   TypeError: a rule nothing can print is a caller bug.
 */
export const createMatcher = (document, map = null) => {
  const nodes = [];
  const byKey = new Map();
  // The top-level node indexes in order (the root nodes' sibling list:
  // the one entity node, or the sections of a rootless document).
  const roots = [];
  // The side table: field key → record (fieldRecords, with its wear and
  // `parent` the block's node index). Never a node: nothing here is in a
  // children list, in `roots`, or walked by a chain.
  const fields = new Map();
  const index = (list, parent) => {
    const siblings = (Array.isArray(list) ? list : []).filter(isPlainObject);
    siblings.forEach((node, position) => {
      const id = nodes.length;
      const children = (Array.isArray(node.children) ? node.children : []).filter(isPlainObject);
      const own = nodeFacts(node);
      const facts = {
        ...own,
        wear: wearOf(map, own.key),
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
      if (own.kind === 'block' && own.key !== null) {
        fieldRecords(node, own.key).forEach((record) => {
          fields.set(record.key, { ...record, wear: wearOf(map, record.key), parent: id });
        });
      }
      index(children, id);
    });
  };
  index(document, null);

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
  // A node's place among its siblings, as structuralHolds reads it
  // (SelectorMatcher::place mirror). A block's root holds its content,
  // which the tree carries no nodes for: a block is never empty; a
  // container or region wrapper without child nodes is. The type place
  // is the node's place among the siblings sharing its root tag, null
  // when the tree does not know the node's tag.
  const place = (id) => {
    const facts = nodes[id];
    let typeIndex = null;
    let typeCount = null;
    if (facts.tag !== null) {
      typeCount = 0;
      siblings(id).forEach((sibling) => {
        if (nodes[sibling].tag !== facts.tag) {
          return;
        }
        if (sibling === id) {
          typeIndex = typeCount;
        }
        typeCount += 1;
      });
    }
    return {
      index: facts.index,
      count: facts.count,
      empty: facts.kind !== 'block' && facts.childCount === 0,
      typeIndex,
      typeCount,
    };
  };
  // Whether the node's facts match a compound's subject: `*` and an
  // implied subject (a `:not()` argument or an `:is()` member of
  // qualifiers alone) match every node; a tag the node's root tag; a
  // type the node's address by family; the anchor `&` the scope root
  // alone. A part class matches no node in a chain (anatomy inside a
  // root is not a tree fact, never over-claimed) but IS judged as a
  // `:not()` argument or an `:is()` member, the compounds carrying no
  // combinator: a node whose parts carry the class, hoisted
  // onto its root or nested inside it, wears the class and is what
  // `:not(.cls)` excludes (the flattened parts do not tell the two
  // apart, and excluding both is the reading that never over-claims a
  // reach); a node wearing the class as a variant class is excluded
  // the same way.
  const subjectMatches = (id, compound, anchor) => {
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
        return (
          compound.combinator === null &&
          (Object.hasOwn(facts.parts, compound.name) || facts.wear.includes(compound.name.slice(1)))
        );
      case ANCHOR_KIND:
        return id === anchor;
      default:
        return false;
    }
  };
  // Whether one node matches one compound: its subject, then every
  // qualifier: `:has()` by hasHolds; `:not()` when its compound does not
  // match the node; `:is()` when any of its member compounds matches
  // the node, each member judged as `:not()`'s compound is; a position
  // qualifier by structuralHolds over the
  // node's place on a compound the tree matches by node (`*`, the
  // implied subject, a type, the anchor), reach-neutral on a tag or
  // class compound (a tag's position may be a position inside a root,
  // where the bag has no order, so the qualifier holds as if absent
  // rather than promising an order the tree cannot see). A class
  // qualifier naming a variant class holds by the node's wear on a
  // compound read by node (reach-neutral on a tag or class compound,
  // like a position); a markup class, an attribute and a pseudo-class
  // that is no position hold as if absent everywhere (the tree carries
  // no markup classes or attributes, and a state is the pose's to
  // light, never reach). `anchor` is the
  // node the chain's lead relates to (the scope root at the top level,
  // the one node `&` matches; the qualified node inside `:has()`);
  // `byNode` is false inside a `:not()` or an `:is()` whose host
  // compound is a tag or a class, so `p:not(:last-child)` and
  // `p:is(:first-child, :last-child)` are as reach-neutral as
  // `p:last-child`.
  const compoundMatches = (id, compound, anchor, byNode = true) => {
    if (!subjectMatches(id, compound, anchor)) {
      return false;
    }
    const judged = byNode && ![TAG, CLASS_PART].includes(compound.kind);
    return compound.qualifiers.every((qualifier) => {
      switch (qualifier.name) {
        case HAS:
          return hasHolds(id, qualifier.argument);
        case NOT:
          return !compoundMatches(id, qualifier.argument, anchor, judged);
        case IS:
          // Any member matches the node, each judged as `:not()`'s
          // compound is (SelectorMatcher::isHolds mirror): a member of
          // states alone holds as if absent, so
          // `> d-text:is(:hover, :focus-within) a` reaches as `> d-text a`.
          return qualifier.argument.some((member) => compoundMatches(id, member, anchor, judged));
        case CLASS_QUALIFIER:
          return !judged || classHolds(qualifier.argument, nodes[id].wear);
        default:
          return !isPosition(qualifier.name) || !judged || structuralHolds(qualifier, place(id));
      }
    });
  };
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
    if (!compoundMatches(id, compounds[i], anchor)) {
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
  // A node's addressable ancestors' keys, farthest first (the order the
  // stack layers them in: the farthest ancestor is the lowest layer).
  const ancestorKeys = (id) => {
    const keys = [];
    for (let at = nodes[id].parent; at !== null; at = nodes[at].parent) {
      if (nodes[at].key !== null) {
        keys.push(nodes[at].key);
      }
    }
    return keys.reverse();
  };
  // The facts a key answers: the entry's own, its place, its parent key.
  const publicFacts = (entry, place, parent) => ({
    key: entry.key,
    kind: entry.kind,
    address: entry.address,
    tag: entry.tag,
    tags: entry.tags,
    parts: entry.parts,
    wear: entry.wear,
    name: entry.name,
    type: entry.type,
    icon: entry.icon,
    ...place,
    parent,
  });
  // What reaches() judges a candidate by: its anatomy (tags and parts)
  // and its place in the tree. A node's own; a field record's tags and
  // parts at its block's node (the field is inside the block, so a rule
  // reaching inside the block reaches inside the field).
  const subjectOf = (key) => {
    const id = byKey.get(key) ?? null;
    if (id !== null) {
      return { place: id, tags: nodes[id].tags, parts: nodes[id].parts };
    }
    const record = fields.get(key) ?? null;
    return record === null ? null : { place: record.parent, tags: record.tags, parts: record.parts };
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
  // host is what the tree can name: `> d-text::before` matches every
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
    has: (key) => byKey.has(key) || fields.has(key),

    facts: (key) => {
      const id = byKey.get(key) ?? null;
      if (id !== null) {
        const node = nodes[id];
        return publicFacts(
          node,
          { index: node.index, count: node.count, childCount: node.childCount },
          addressableParent(id),
        );
      }
      // A field record has no place among siblings (the tree carries no
      // sibling list for it) and no child nodes; its parent is its block.
      const record = fields.get(key) ?? null;
      return record === null
        ? null
        : publicFacts(record, { index: null, count: null, childCount: 0 }, nodes[record.parent].key);
    },

    ancestors: (key) => {
      const id = byKey.get(key) ?? null;
      if (id !== null) {
        return ancestorKeys(id);
      }
      // A field's ancestors are its block's, then the block itself: the
      // field's rows inherit the block's rules and its ancestors'.
      const record = fields.get(key) ?? null;
      return record === null ? [] : [...ancestorKeys(record.parent), nodes[record.parent].key];
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
      // The candidate: a node, or a field record judged at its block.
      const subject = subjectOf(candidate);
      if (root === null || subject === null || !inScope(subject.place, root) || !compounds.length) {
        return false;
      }
      const { place, tags, parts } = subject;
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
        return tags.includes(name) && descentReaches(place, compounds, root);
      }
      // A class subject after a type: a declared part is the type's
      // anatomy, a legal address on every node the type matches, whether
      // or not this one renders it; an identity class is minted onto one
      // element in the world, so it reaches only the node carrying it.
      if (last.kind === CLASS_PART) {
        return (
          last.name === name &&
          n >= 2 &&
          (!INSTANCE_PART.test(name) || Object.hasOwn(parts, name)) &&
          chainMatches(place, compounds, n - 2, root, false)
        );
      }
      if (!tagSubject) {
        return false;
      }
      const partTag = parts[name] ?? null;
      return partTag !== null && partTag === last.name && descentReaches(place, compounds, root);
    },
  };
};
