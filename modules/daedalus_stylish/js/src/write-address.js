/**
 * @file
 * The address set: the addresses one selected element can be styled
 * at, and the grant judgement at one of them.
 *
 * The panel shows ONE selected element and reads its whole stack; a
 * write lands at the STYLING TARGET (the spine's `{key, selector}`,
 * daedalus_ui's selection facade), by default the element's own key
 * (its selector facet included). An element has more names than its
 * own: every ancestor rule reaching it (`container:Y::a` styles the
 * link part's anchor as surely as `block:X::.text-block-link` does),
 * and the rules its ancestors COULD carry for it that nobody has
 * written yet. addressSet() lists them, in the order the switcher shows
 * them: the own key, then the existing ancestor rules nearest ancestor
 * first (the element's stack's ancestor layers, style-stack.js), then
 * the mintable ones nearest ancestor first, each one the matcher
 * confirms reaches the selected facet on the tree and the roster walk
 * declares (on a strict site every compound must land; on an open site
 * only a tag must, since the validator refuses an undeclared tag in
 * every enforcement mode, STYLE_TAG_NOT_DECLARED, and takes the rest
 * grammar-only). Choosing an address seats it as the target
 * (`Selection.target(ancestor, selector)`); a mintable one changes
 * nothing until the first write, which mints `{key}::{selector}`
 * exactly as code mode's `a {` does.
 *
 * The switcher is for ELEMENT facets only: the root, a declared or
 * instance part (`.cls`), a tag tier (`a`), and the generated box of
 * any of them (`&::before` the root's, `.cls::after` a part's): a box
 * is one more facet of its host, addressed by the host's shapes with
 * the pseudo appended (`> pb-text::before`, `a::after`), since a rule
 * ending in a box reaches exactly the hosts the pseudo-free rule
 * reaches (selector-matcher.js reaches). The mintable shapes per
 * ancestor: for the root its children (`> *`), its type by child and by
 * descent (`> pb-text`, `pb-text`) and its root tag (`p`); for a tag
 * tier the tag and the type-then-tag chains (`a`, `> pb-text a`,
 * `pb-text a`); for a DECLARED part its rendered tag and the
 * type-then-part chains (`a`, `> pb-text .text-block-link`, `pb-text
 * .text-block-link`), since a declared class is the type's anatomy,
 * worn by every element of the type, so it names a class of things;
 * for an INSTANCE part (`.s-2kxs`, parts.js INSTANCE_PART, minted onto
 * one element in the world) the class names nothing but the element
 * itself, so its class of things is its TAG: the same three shapes with
 * the tag in the class's place (`p`, `> pb-formatted-text p`,
 * `pb-formatted-text p`), and, before any ancestor's, the element's own
 * tag tier (`block:X::p`, "Every paragraph here"), the nearest owner
 * that styles every such instance at once. A chain facet (`> pb-text`,
 * seated from a code-mode row header) is a rule of its own, edited one
 * thing at a time, and has no address set. A target ABOVE the
 * selection (an ancestor rule) names its facet by reach: facetOf()
 * answers which element facet the rule lands on, so the rows stay that
 * facet's and the set is the one the rule was chosen from.
 *
 * No click can seat a box (a pointer lands on the host), so the target
 * chip's menu is its doorway: boxAddresses() lists the two boxes of a
 * root or part facet, offered as "+ Before" / "+ After", and picking
 * one SEATS it as the target and nothing more; the entry is born by the
 * first property write there (the op spine seeds its `content`).
 *
 * withheldAt() is the other half: under an ancestor target the rows
 * stay the selected element's, and a property the own key grants that
 * the target's landing declaration does not is DISABLED with the
 * roster's reason as its title (controls.js reports it per model as
 * `withheld`; the Advanced rows judge the same way per property). No
 * prose lives inside a widget: a reason is a title.
 *
 * Pure and preact-free (tests/js/write-address.test.mjs); the sentences
 * go through Drupal.t at call time, like provenance-model.js's.
 */
import {
  bundleForKey,
  declarationForKey,
  declaredForKey,
  findBaseTagDeclaration,
  parseKey,
  propertiesClosed,
  propertyGranted,
  tagWord,
} from './style-guide.js';
import {
  INSTANCE_PART,
  boxWord,
  partsForKey,
  selectorLabel,
  selectorWords,
  typeLabels,
} from './parts.js';
import { createMatcher, typeOfAddress } from './selector-matcher.js';
import { TIER_ANCESTOR, buildStack } from './style-stack.js';
import { ancestorRule, ancestorVariant } from './code/layers.js';
import {
  ANCHOR,
  CHILD,
  CLASS_PART,
  DESCENDANT,
  PSEUDOS,
  SUFFIX,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
  canonicalSelector,
  parseSelector,
  printSelector,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Whether a value is a write address: `{key, selector}` with a
 * non-empty canonical selector.
 */
export const isAddress = (address) =>
  isPlainObject(address) &&
  typeof address.key === 'string' &&
  address.key !== '' &&
  typeof address.selector === 'string' &&
  address.selector !== '' &&
  canonicalSelector(address.selector) === address.selector;

/**
 * The style-map key an address names (`{key}::{selector}`, the bare key
 * for a null selector).
 *
 * @param {{key: string, selector: ?string}} address
 *
 * @return {string}
 */
export const addressKey = ({ key, selector }) =>
  selector === null || selector === undefined || selector === ''
    ? key
    : `${key}${SUFFIX}${selector}`;

/**
 * Whether two addresses name one key.
 */
export const sameAddress = (a, b) =>
  isPlainObject(a) && isPlainObject(b) && addressKey(a) === addressKey(b);

/**
 * The name the sentences give an address's owner: the name the tree
 * carries for its node (the annotator's minted `_element.name`, the
 * same word the subject line and the page tree read; the word
 * provenance-model.js ancestorName gives an ancestor layer), else a
 * generic word for a key the tree has no node for.
 *
 * @param {?object} matcher
 *   A matcher over the tree (createMatcher), or null.
 * @param {string} key
 *   The owner's selectable key.
 *
 * @return {string}
 */
export const ownerName = (matcher, key) =>
  matcher?.facts?.(key)?.name ?? Drupal.t('the parent element');

const lower = (text) =>
  typeof text === 'string' && text !== ''
    ? text.charAt(0).toLowerCase() + text.slice(1)
    : text;

/**
 * A type compound as a singular noun: "Text block", "Container", "Block".
 */
const typeNoun = (guide, type) => {
  if (type === TYPE_CONTAINER) {
    return Drupal.t('Container');
  }
  if (type === TYPE_BLOCK) {
    return Drupal.t('Block');
  }
  const label = typeLabels(guide)[type];
  return typeof label === 'string' && label !== type
    ? Drupal.t('@label block', { '@label': label })
    : type;
};

/**
 * A type compound as a plural noun: "Text blocks", "Containers", "Blocks".
 */
const typeNouns = (guide, type) => {
  if (type === TYPE_CONTAINER) {
    return Drupal.t('Containers');
  }
  if (type === TYPE_BLOCK) {
    return Drupal.t('Blocks');
  }
  const label = typeLabels(guide)[type];
  return typeof label === 'string' && label !== type
    ? Drupal.t('@label blocks', { '@label': label })
    : type;
};

/**
 * A tag's word lower-cased for the middle of a sentence ("link",
 * "paragraph"): the guide's one word for the tag (style-guide.js
 * tagWord()).
 */
const tagNoun = (guide, tag) => lower(tagWord(guide, tag));

const upper = (text) =>
  typeof text === 'string' && text !== ''
    ? text.charAt(0).toUpperCase() + text.slice(1)
    : text;

/**
 * The words for an ancestor address: "All children of Welcome", "Every
 * link in Welcome", "Text blocks in Welcome", "Text block links in
 * Welcome"; a relation reads its group's plural subject with its
 * phrases (parts.js selectorWords: "Paragraphs right after a paragraph
 * in Welcome", "Text blocks containing a link in Welcome", "Text blocks
 * without a link in Welcome", "Children except images in Welcome",
 * "Text block links after a link in Welcome"); any other shape reads
 * its selector's label (selectorLabel) in the owner. A rule ending in
 * a generated box reads its host's sentence with the box word as one
 * more step ("Text blocks directly in Welcome › Before"), the label
 * seam's own convention (parts.js selectorLabel).
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {{key: string, selector: string}} address
 *   The address.
 * @param {string} owner
 *   The owner's name (ownerName).
 * @param {object} [blockTypes]
 * @param {object} [sectionLayouts]
 *
 * @return {string}
 */
export const addressLabel = (
  guide,
  address,
  owner,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const parsed = parseSelector(address.selector);
  if (parsed?.pseudo) {
    return Drupal.t('@host › @box', {
      '@host': addressLabel(
        guide,
        { key: address.key, selector: printSelector({ ...parsed, pseudo: null }) },
        owner,
        blockTypes,
        sectionLayouts,
      ),
      '@box': boxWord(parsed.pseudo),
    });
  }
  const groups =
    selectorWords(guide, address.key, address.selector, blockTypes, sectionLayouts) ?? [];
  const [first, second] = groups;
  const things = (group) => [group.plural, ...group.phrases].join(' ');
  // The ready-made phrases word the single unrelated shapes.
  if (groups.length === 1 && !first.phrases.length) {
    if (first.compound.kind === UNIVERSAL) {
      return Drupal.t('All children of @owner', { '@owner': owner });
    }
    if (first.compound.kind === TAG) {
      return Drupal.t('Every @tag in @owner', {
        '@tag': first.noun,
        '@owner': owner,
      });
    }
    if (first.compound.kind === TYPE) {
      return first.combinator === CHILD
        ? Drupal.t('@types directly in @owner', {
            '@types': typeNouns(guide, first.compound.name),
            '@owner': owner,
          })
        : Drupal.t('@types in @owner', {
            '@types': typeNouns(guide, first.compound.name),
            '@owner': owner,
          });
    }
  }
  // A type then a part or tag (its relations riding along): "Text block
  // links in Welcome", and "directly in" when the type is
  // child-combinated, so the two shapes the set offers side by side
  // never read alike.
  if (
    groups.length === 2 &&
    first.compound.kind === TYPE &&
    !first.phrases.length &&
    second.combinator === DESCENDANT &&
    [TAG, CLASS_PART].includes(second.compound.kind)
  ) {
    const args = {
      '@type': typeNoun(guide, first.compound.name),
      '@owner': owner,
      '@things': things(second),
    };
    return first.combinator === CHILD
      ? Drupal.t('@type @things directly in @owner', args)
      : Drupal.t('@type @things in @owner', args);
  }
  // One related group: its plural subject and phrases.
  if (groups.length === 1) {
    return Drupal.t('@things in @owner', {
      '@things': upper(things(first)),
      '@owner': owner,
    });
  }
  return Drupal.t('@selector in @owner', {
    '@selector': selectorLabel(
      guide,
      address.key,
      address.selector,
      blockTypes,
      sectionLayouts,
      null,
    ),
    '@owner': owner,
  });
};

/**
 * The element facet a selector names, as its host and its box: `kind`
 * the HOST's shape, 'root' (null or the owner's own box `&::before`),
 * 'part' (one class), 'tag' (one descendant tag); `host` the host's
 * own selector (null for the root); `pseudo` the box's name when the
 * facet is a generated box, else null. Null for a chain or a qualified
 * compound (no address set).
 *
 * @param {?string} selector
 *
 * @return {?{kind: string, host: ?string, pseudo: ?string}}
 */
const facetShape = (selector) => {
  if (selector === null || selector === undefined || selector === '') {
    return { kind: 'root', host: null, pseudo: null };
  }
  const parsed = parseSelector(selector);
  if (parsed === null) {
    return null;
  }
  const { compounds, pseudo } = parsed;
  if (!compounds.length) {
    return pseudo === null ? null : { kind: 'root', host: null, pseudo };
  }
  if (
    compounds.length !== 1 ||
    compounds[0].combinator !== DESCENDANT ||
    compounds[0].qualifiers.length
  ) {
    return null;
  }
  const host = printSelector({ compounds, pseudo: null });
  if (compounds[0].kind === CLASS_PART) {
    return { kind: 'part', host, pseudo };
  }
  if (compounds[0].kind === TAG) {
    return { kind: 'tag', host, pseudo };
  }
  return null;
};

/**
 * A host facet's box selector: `&::before` for the root (a null host),
 * `.cls::after` / `a::after` for a part or tag host.
 */
const boxOf = (host, pseudo) => `${host ?? ANCHOR}${SUFFIX}${pseudo}`;

/**
 * The generated boxes a facet offers as doorways: the root's own
 * (`&::before`, `&::after`) and a part's (`.cls::before`, `.cls::after`),
 * each kept by the same declaredness rule as a mintable address (on a
 * strict site the host must declare the box, since the first write
 * there is refused otherwise, STYLE_PSEUDO_NOT_DECLARED; open and
 * guarded sites derive every box); [] for a tag tier, a chain, or a
 * box itself (a pseudo is terminal). The target chip's menu lists them
 * as "+ Before" / "+ After" (subject-model.js): seat only, no write.
 *
 * @param {object} args
 * @param {object} args.guide
 *   The style guide payload.
 * @param {string} args.key
 *   The selected element's selectable key.
 * @param {?string} args.selector
 *   The facet's own selector (null for the root).
 * @param {object} [args.blockTypes]
 * @param {object} [args.sectionLayouts]
 *
 * @return {Array<{key: string, selector: string, pseudo: string}>}
 */
export const boxAddresses = ({
  guide,
  key,
  selector,
  blockTypes = {},
  sectionLayouts = {},
}) => {
  const shape = facetShape(selector);
  if (shape === null || shape.pseudo !== null || shape.kind === 'tag') {
    return [];
  }
  return PSEUDOS.map((pseudo) => ({
    key,
    selector: boxOf(shape.host, pseudo),
    pseudo,
  })).filter((address) => declared(guide, address, blockTypes, sectionLayouts));
};

/**
 * The element facet a styling target names on the selected element,
 * as the facet's own address `{key, selector}`: the target's own
 * selector when it sits on the element (a part, a tag tier, a chain),
 * the root (`selector` null) with no target, and for a target ABOVE
 * the element (an ancestor rule) the facet the rule reaches on it,
 * judged by the matcher: the root first, then the element's declared
 * parts in annotation order, then its tag tiers, then its instance
 * parts (`container:Y::a` lands on the link part whose tag is `a`,
 * `container:Y::> pb-text` on the root; on a formatted text block
 * `container:Y::p` lands on the `p` TAG TIER, never on the first minted
 * paragraph, since "every paragraph" is the tier and an instance is one
 * member of it, while `container:Y::> pb-formatted-text .s-2kxs` still
 * lands on that instance, the one facet a class rule reaches), each
 * host followed by its two boxes (`container:Y::> pb-text::before`
 * lands on the root's `&::before`, `container:Y::a::after` on the link
 * part's `.text-block-link::after`; a rule ending in a box reaches
 * boxes alone, and by name, so the host order is the only order).
 * Null when an ancestor rule reaches no facet of the element, or when
 * the tree cannot say (no tree, an unknown key): the panel drops such
 * a target to the bare element.
 *
 * @param {object} args
 * @param {?Array} args.tree
 *   The blueprint's lean node tree, or null.
 * @param {string} args.key
 *   The selected element's selectable key.
 * @param {?{key: string, selector: string}} args.target
 *   The styling target, or null.
 *
 * @return {?{key: string, selector: ?string}}
 */
export const facetOf = ({ tree, key, target }) => {
  if (!isAddress(target)) {
    return { key, selector: null };
  }
  if (target.key === key) {
    return { key, selector: target.selector };
  }
  const matcher = Array.isArray(tree) ? createMatcher(tree) : null;
  const facts = matcher?.facts(key) ?? null;
  if (facts === null || !matcher.has(target.key)) {
    return null;
  }
  const parts = Object.keys(facts.parts ?? {});
  const facets = [
    null,
    ...parts.filter((part) => !INSTANCE_PART.test(part)),
    ...(Array.isArray(facts.tags) ? facts.tags : []),
    ...parts.filter((part) => INSTANCE_PART.test(part)),
  ].flatMap((host) => [host, ...PSEUDOS.map((pseudo) => boxOf(host, pseudo))]);
  const reached = facets.find((facet) =>
    matcher.reaches(target.key, target.selector, key, facet),
  );
  return reached === undefined ? null : { key, selector: reached };
};

/**
 * The own key's words, the face of the subject line's address chip:
 * "This element" for the root (the element's own name is the subject
 * line's word beside the chip, never repeated here), "This link" for a
 * part, "Every link here" for a tag tier, and for a chain facet (a rule
 * of the element's own, `> pb-text`, which has no address set) the
 * rule's sentence in the owner (addressLabel: "Text blocks directly in
 * Welcome"), the same grammar the set's ancestor entries speak. A
 * generated box reads its box word after its host's face, "Before" /
 * "After" alone for the root's own (the element word beside the chip
 * is the host; the page-elements tree's row reads the same word),
 * "This link › After" for a part's, "Every link here › After" for a
 * tag tier's.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {?object} matcher
 *   A matcher over the tree (createMatcher), or null.
 * @param {{key: string, selector: ?string}} own
 *   The facet's own address (the selected key and its selector).
 * @param {object} [blockTypes]
 * @param {object} [sectionLayouts]
 * @param {?object} [markup]
 *   The selected key's `_element.markup` annotation, so an instance
 *   part words itself ("This body text"), or null.
 *
 * @return {string}
 */
export const facetLabel = (
  guide,
  matcher,
  { key, selector },
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
) => {
  const shape = facetShape(selector);
  if (shape?.pseudo) {
    const box = boxWord(shape.pseudo);
    return shape.kind === 'root'
      ? box
      : Drupal.t('@host › @box', {
          '@host': facetLabel(
            guide,
            matcher,
            { key, selector: shape.host },
            blockTypes,
            sectionLayouts,
            markup,
          ),
          '@box': box,
        });
  }
  const kind = shape?.kind ?? null;
  if (kind === 'root') {
    return Drupal.t('This element');
  }
  if (kind === 'tag') {
    return Drupal.t('Every @tag here', { '@tag': tagNoun(guide, selector) });
  }
  if (kind === 'part') {
    return Drupal.t('This @part', {
      '@part': lower(
        selectorLabel(guide, key, selector, blockTypes, sectionLayouts, markup),
      ),
    });
  }
  return addressLabel(
    guide,
    { key, selector },
    ownerName(matcher, key),
    blockTypes,
    sectionLayouts,
  );
};

/**
 * Whether a mintable address is declared enough to offer: on a strict
 * site every compound must land and a terminal pseudo-element must be
 * declared by its host (the server refuses an undeclared type, part,
 * children section, tag or box there; style-guide.js declaredForKey,
 * the roster's declared()); on an open or guarded site only a tag
 * subject must be a declared base tag (refused in every mode), the
 * rest is grammar-only, today's posture.
 */
const declared = (guide, address, blockTypes, sectionLayouts) => {
  const compounds = parseSelector(address.selector)?.compounds ?? [];
  const last = compounds[compounds.length - 1];
  if (
    last?.kind === TAG &&
    findBaseTagDeclaration(guide?.base, last.name) === null
  ) {
    return false;
  }
  if (!propertiesClosed(guide)) {
    return true;
  }
  const parsed = parseKey(addressKey(address));
  return (
    parsed !== null &&
    declaredForKey(guide, parsed, bundleForKey(parsed, blockTypes, sectionLayouts))
  );
};

/**
 * The selected facet's address set, in switcher order.
 *
 * @param {object} args
 * @param {object} args.guide
 *   The style guide payload.
 * @param {?object} args.map
 *   The style map.
 * @param {string} args.key
 *   The selected element's selectable key.
 * @param {?string} args.selector
 *   The facet the target reaches on the element (null for the root).
 * @param {?Array} args.tree
 *   The blueprint's lean node tree; null when unknown (then the set is
 *   the own key alone).
 * @param {?Array<object>} [args.variants]
 *   The variant records the stack reads references through.
 * @param {?Array<string>} [args.axes]
 *   The root scope's axis vocabulary.
 * @param {object} [args.blockTypes]
 * @param {object} [args.sectionLayouts]
 * @param {?object} [args.markup]
 *   The selected key's `_element.markup` annotation (the own entry's
 *   words for an instance part), or null.
 *
 * @return {Array<{key: string, selector: ?string, label: string,
 *   existing: boolean}>}
 *   The own key first (`existing` true, its label the facet's face,
 *   facetLabel), for an instance part the element's own tag tier next
 *   (its tag face, "Every paragraph here"; `existing` when the map
 *   holds it, else offered when the roster declares the tag), then the
 *   existing ancestor rules reaching the facet nearest ancestor first,
 *   then the mintable ones nearest ancestor first (`existing` false).
 *   [] for a chain facet or an unparseable key.
 */
export const addressSet = ({
  guide,
  map,
  key,
  selector = null,
  tree = null,
  variants = [],
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
}) => {
  const facet = selector === null || selector === '' ? null : canonicalSelector(selector);
  if (selector !== null && selector !== '' && facet === null) {
    return [];
  }
  const shape = facetShape(facet);
  if (shape === null || parseKey(key) === null) {
    return [];
  }
  const { kind, host, pseudo } = shape;
  const matcher = Array.isArray(tree) ? createMatcher(tree) : null;
  const set = [
    {
      key,
      selector: facet,
      label: facetLabel(
        guide,
        matcher,
        { key, selector: facet },
        blockTypes,
        sectionLayouts,
        markup,
      ),
      existing: true,
    },
  ];
  if (matcher === null || !matcher.has(key)) {
    return set;
  }
  const seen = new Set([addressKey(set[0])]);
  const push = (address, existing) => {
    const id = addressKey(address);
    if (seen.has(id)) {
      return;
    }
    seen.add(id);
    set.push({
      ...address,
      label: addressLabel(
        guide,
        address,
        ownerName(matcher, address.key),
        blockTypes,
        sectionLayouts,
      ),
      existing,
    });
  };
  // Nearest ancestor first, the switcher's order.
  const ancestors = matcher.ancestors(key).reverse();
  const facts = matcher.facts(key);
  const type = typeOfAddress(facts.address);
  const rootTag = facts.tag;
  const partTag = kind === 'part' ? (facts.parts[host] ?? null) : null;
  // An instance part's class is worn by one element in the world, so its
  // class of things is its tag (a declared part's class IS the class of
  // things, the type's anatomy on every element of the type).
  const instance = kind === 'part' && INSTANCE_PART.test(host) && partTag !== null;
  const boxed = (candidate) =>
    pseudo === null ? candidate : `${candidate}${SUFFIX}${pseudo}`;

  // The element's own tag tier, for an instance part: the nearest owner
  // that styles every such instance at once ("Every paragraph here"),
  // so it sits before any ancestor's shape; existing when the map holds
  // it, else offered when the roster declares the tag.
  if (instance) {
    const own = { key, selector: boxed(partTag) };
    const held = isPlainObject(map) && Object.hasOwn(map, addressKey(own));
    if (held || declared(guide, own, blockTypes, sectionLayouts)) {
      seen.add(addressKey(own));
      set.push({
        ...own,
        label: facetLabel(guide, matcher, own, blockTypes, sectionLayouts, markup),
        existing: held,
      });
    }
  }

  // The existing rules: the facet's stack's ancestor groups, per owner in
  // the tier's own (delivery) order. A rule position is one group (its
  // worn layers, then its own layer, which always rides the group), so
  // the own layer alone names the rule: one entry per rule, whether it
  // carries lines of its own or only wears.
  const stack = buildStack({
    guide,
    map,
    key: addressKey({ key, selector: facet }),
    variants,
    axes,
    blockTypes,
    sectionLayouts,
    tree,
  });
  const rules = stack.layers
    .filter(
      (layer) =>
        layer.tier === TIER_ANCESTOR && ancestorVariant(layer.id) === null,
    )
    .map((layer) => ancestorRule(layer.id))
    .filter((rule) => rule !== null);
  ancestors.forEach((ancestor) => {
    rules
      .filter((rule) => rule.key === ancestor)
      .forEach((rule) => push(rule, true));
  });

  // The mintable rules: what each ancestor could carry for this facet,
  // kept when the matcher confirms the reach and the roster declares it.
  // A box facet is addressed by its host's shapes with the box appended
  // (`> pb-text::before`, `a::after`), judged at the box position (the
  // matcher requires the names to agree, then answers the host). A part
  // facet's chains end in the class of things: the class for a declared
  // part, the tag for an instance part (the matcher answers a tag rule
  // at a part position by the part's rendered tag either way).
  const sub = kind === 'root' && pseudo === null ? null : facet;
  const candidates = (() => {
    if (kind === 'root') {
      return [
        '> *',
        type === null ? null : `> ${type}`,
        type === null ? null : type,
        rootTag,
      ];
    }
    const subject = kind === 'part' ? partTag : host;
    const things = instance ? partTag : host;
    return [
      subject,
      type === null ? null : `> ${type} ${things}`,
      type === null ? null : `${type} ${things}`,
    ];
  })()
    .filter((candidate) => typeof candidate === 'string' && candidate !== '')
    .map(boxed);
  ancestors.forEach((ancestor) => {
    candidates.forEach((candidate) => {
      const address = { key: ancestor, selector: candidate };
      if (
        matcher.reaches(ancestor, candidate, key, sub) &&
        declared(guide, address, blockTypes, sectionLayouts)
      ) {
        push(address, false);
      }
    });
  });
  return set;
};

/**
 * The declaration the roster walk lands an address on (the grant and
 * constraint judge for a write there): declarationForKey over the
 * address's key, `{}` for an undeclared landing or an unparseable
 * address.
 *
 * @param {object} guide
 * @param {{key: string, selector: ?string}} address
 * @param {object} [blockTypes]
 * @param {object} [sectionLayouts]
 *
 * @return {object}
 */
export const addressDeclaration = (
  guide,
  address,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const parsed = parseKey(addressKey(address));
  if (parsed === null) {
    return {};
  }
  return declarationForKey(
    guide,
    parsed,
    bundleForKey(parsed, blockTypes, sectionLayouts),
  );
};

/**
 * The reason a write address withholds a property: the roster's answer
 * in words, the title a disabled row wears.
 *
 * @param {'grant'|'reach'} kind
 *   'grant': the address lands on a declaration that does not grant the
 *   property; 'reach': the address cannot be continued to the box the
 *   row styles (a part the address's selector cannot reach, or the root
 *   beneath a part address).
 * @param {object} args
 * @param {string} args.property
 * @param {{key: string, selector: string}} args.address
 * @param {string} args.owner
 *   The owner's name (ownerName).
 * @param {?string} [args.part]
 *   The box's label for the 'reach' kind.
 *
 * @return {string}
 */
export const withheldReason = (kind, { property, address, owner, part = null }) =>
  kind === 'reach'
    ? Drupal.t(
        '"@selector" on @owner does not reach the @part this row styles; choose an address that does, or this element alone.',
        {
          '@selector': address.selector,
          '@owner': owner,
          '@part': part ?? Drupal.t('element'),
        },
      )
    : Drupal.t(
        '@property is not allowed at "@selector" on @owner: the style guide does not grant it there. Choose this element alone to set it here.',
        { '@property': property, '@selector': address.selector, '@owner': owner },
      );

/**
 * The properties a write address withholds among the given ones, each
 * with its reason: those the address's landing declaration does not
 * grant (propertyGranted, the roster's read). The caller passes the
 * properties the OWN key grants (or shows); what the own key never
 * grants stays hidden and is never judged here.
 *
 * @param {object} args
 * @param {object} args.guide
 * @param {{key: string, selector: string}} args.address
 * @param {string[]} args.properties
 * @param {object} [args.blockTypes]
 * @param {object} [args.sectionLayouts]
 * @param {?Array} [args.tree]
 *   The tree, for the owner's name in the reason.
 *
 * @return {Object<string, string>}
 *   property → reason; {} when the address grants them all.
 */
export const withheldAt = ({
  guide,
  address,
  properties,
  blockTypes = {},
  sectionLayouts = {},
  tree = null,
}) => {
  const declaration = addressDeclaration(guide, address, blockTypes, sectionLayouts);
  const owner = ownerName(Array.isArray(tree) ? createMatcher(tree) : null, address.key);
  const withheld = {};
  (Array.isArray(properties) ? properties : []).forEach((property) => {
    if (!propertyGranted(declaration, property)) {
      withheld[property] = withheldReason('grant', { property, address, owner });
    }
  });
  return withheld;
};

/**
 * A part's label for the reach reason: its declared label under the
 * element's key, the class itself otherwise, "element" for the root.
 */
export const boxLabel = (guide, key, element, blockTypes = {}, sectionLayouts = {}) => {
  if (element === '' || element === null || element === undefined) {
    return Drupal.t('element');
  }
  const label = partsForKey(guide, key, blockTypes, sectionLayouts).find(
    (part) => part.id === element,
  )?.label;
  return lower(typeof label === 'string' ? label : element);
};
