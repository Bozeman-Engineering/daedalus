/**
 * @file
 * The style stack: the one client model of "what styles this key, and
 * from where".
 *
 * The client twin of the server's StyleStack (src/StyleStack.php),
 * parity-locked by StackEquivalenceTest the way PrinterEquivalenceTest
 * locks the printer: for one style-map key, every layer that styles it,
 * in DELIVERY order, bottom to top, each carrying its condition-keyed
 * styles RAW (as stored, no cascade, no winner marks), so the stack reads
 * like the printed document. Every row, well, code island and the AI's
 * explain tool read this one model; none reconstructs a winner of its
 * own.
 *
 * The layers, bottom to top:
 * - declared:{scope}: the element's declaration's own defaults
 *   (guide.declaredDefaults), the captured scope's family kind block
 *   then its refinement block, read at the key's relative position;
 * - skin:base:{tag}: the active skin's base:{tag} block, keyed by the
 *   READ SUBJECT's tag (subjectTag: the one element the key reads, from
 *   the blueprint tree's annotation, `_element.markup.tag` for an
 *   element key, the declared part's tag for a part key; a tag tier or a
 *   chain has no subject, so no base layer; no tree, none either, and
 *   `baseTags` names what the skin carries). It prints unscoped at zero
 *   specificity, so it sits beneath every scoped block whatever order
 *   the stylesheet prints them in;
 * - skin:{scope}: the active PUBLISHED skin's defaults
 *   (guide.skinDefaults), kind block then refinement block;
 * - the ancestor tier: present only when the caller passes the
 *   blueprint's lean node tree (`tree`, with the floor): one GROUP of
 *   layers per ancestor rule position that REACHES the key
 *   (selector-matcher.js reaches(): an ancestor's selector entry whose
 *   targets are this element's root, or, for a tag or part key, the tag
 *   inside it or that part), farthest ancestor first and, per ancestor,
 *   positions in selectorSuffixes() order. A group is the position's
 *   map layers with their identities kept: the variants delivered there
 *   (the rule's own wear and its owner's worn fragments, delivery
 *   order), one layer each as ancestor:{owner}::{position}|{variant}
 *   carrying the variant's identity and its `carrier` (the address whose
 *   reference supplies it: the rule, or the owner's root), then the
 *   rule's own entry as ancestor:{owner}::{position}. Each layer folds
 *   the structural facts this element satisfies into the posable
 *   remainder of its compounds on its own (foldFacts), so own beats worn
 *   by order inside the group as it does at the rule's own key. A worn
 *   layer joins the group only where it delivers (a reference is a fact
 *   of its carrier, listed empty or stale at the rule's own key; a
 *   member's tier carries what reaches the member); a group exists when
 *   any of its layers delivers at the key, and the own layer always
 *   rides its group, empty or not (a rule that only wears is still a
 *   rule that exists; an entry delivering nothing lists nothing). The
 *   tier sits here because delivery puts it here: an
 *   ancestor's rule prints at the weight of the element's own equivalent
 *   rule, so it beats every bare default and loses to the element's own
 *   `@scope` by proximity. Its tier keeps every "this element wears"
 *   reader honest: a worn ancestor layer is a variant the RULE wears,
 *   never this element (layers.js ancestorVariant reads the identity);
 * - variant:{name}: one layer per worn reference of the key's CARRIERS
 *   in delivery order (ruleCarriers, then the root, each carrier's
 *   references by orderedEntryRefs): a rule key wears variants of its
 *   own, delivered inside the owner's @scope before the element lanes
 *   with the variant's root entry at the rule's targets and its '::sel'
 *   entries continued under the rule by descent (relative-selector.js
 *   under(), the printer's rebase), then the ROOT key's references
 *   answer for the root and every '::selector' key under it, each
 *   reference's styles looked up through ITS OWN fragment at the key's
 *   relative position (fragmentStyles, the one lookup delivery shares);
 *   a composition pair (root plus fragment reference of one variant) is
 *   two layers with one id, which is why winners are INDEXES, never ids;
 * - element: the key's own entry, always present and last.
 *
 * A tier layer that declares nothing at the key is not a layer; a worn
 * reference is always a layer, stale or empty, because the reference is
 * a fact of its carrier. Conditions stay raw in every layer; the cascade
 * is a READ: layersAt() answers one exact stored condition (the code
 * view's and the explain tool's contract, index winners) and stackAt()
 * answers one property at a POSE through the condition cascade (the
 * rows' contract, client-only).
 *
 * In a variant-editing session the edited reference's layer, on
 * whichever carrier of the key wears it (the root, or a rule wearing at
 * the key: the session's anchor when a rule wears the document), carries
 * the staged document (documentSlotEntry) and is the editable layer
 * instead of the key's own; every other layer is what it always is.
 *
 * Under a WRITE ADDRESS (`editable`, an ancestor rule `{key, selector}`
 * the panel writes at while the selection stays put) the editable layer
 * is that rule's ancestor layer instead of the element's own: the
 * existing layer when the rule delivers something, else a virtual empty
 * one at the position delivery would give the rule (after every rule
 * of a farther ancestor and of the same owner, before every nearer
 * ancestor's, so the nearest ancestor sits closest to the own entry),
 * so a first write mints `{key}::{selector}` exactly as an own key with
 * no entry reads today. The rows keep reading THIS element's stack; only
 * which layer answers `own` (the Unset verb, the settle-against value)
 * moves. A session and an address never coexist (opening a session
 * resets the address), and a floorless or treeless build carries no
 * ancestor tier, so it carries no editable layer under an address.
 *
 * The stack holds only what the page builder owns: no browser-computed
 * layer.
 */
import { entryAtPose } from './condition-cascade.js';
import { isFactCondition } from './editing-at.js';
import { createMatcher } from './selector-matcher.js';
import { fragmentStyles } from './slot-key.js';
import {
  ELEMENT_LAYER,
  TIER_ANCESTOR,
  TIER_DECLARED,
  TIER_SKIN,
  TIER_VARIANT,
  ancestorLayerId,
  ancestorWearLayerId,
  declaredLayerId,
  skinLayerId,
  variantLayerId,
} from './code/layers.js';
import { documentSlotEntry } from './code/variant-document.js';
import {
  axisVocabulary,
  captureScope,
  entryRefs,
  familyKindOf,
  findVariant,
  orderedEntryRefs,
} from './variant-scope.js';
import {
  CLASS_PART,
  DESCENDANT,
  SUFFIX,
  TAG,
  parseSelector,
  under,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * The tiers: a declaration's own defaults, an active skin's defaults, a
 * worn reference, the element's own layer (whose tier is its id). The
 * layer id grammar is layers.js's (`declared:{scope}`, `skin:{scope}`,
 * `variant:{reference}`, `element`), the one vocabulary every identity
 * surface reads hues and menus from.
 */
export { TIER_ANCESTOR, TIER_DECLARED, TIER_SKIN, TIER_VARIANT };
export const TIER_ELEMENT = ELEMENT_LAYER;

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A style entry's condition-keyed values, normalized to strings.
 *
 * The values-only read (StyleStack::conditionValues mirror): the reserved
 * "variants" key is not a condition and never reads here; non-object
 * conditions, null and nested values drop; every value is a trimmed
 * string. Insertion order is kept, so the stack's raw styles print in
 * the order the entry stores them.
 *
 * @param {?object} entry
 *   A condition-keyed entry (a map entry, a variant's relative entry, a
 *   defaults block).
 *
 * @return {Object<string, Object<string, string>>}
 *   condition → property → value; {} when nothing is set.
 */
export const conditionValues = (entry) => {
  const conditions = {};
  Object.entries(isPlainObject(entry) ? entry : {}).forEach(
    ([condition, values]) => {
      if (condition === 'variants' || !isPlainObject(values)) {
        return;
      }
      Object.entries(values).forEach(([property, value]) => {
        if (value === null || value === undefined || typeof value === 'object') {
          return;
        }
        (conditions[condition] ??= {})[property] = String(value).trim();
      });
    },
  );
  return conditions;
};

/**
 * A tier layer (declared or skin) for one declaring block.
 */
const tierLayer = (tier, scope, styles) => ({
  id: tier === TIER_SKIN ? skinLayerId(scope) : declaredLayerId(scope),
  tier,
  scope,
  variant: null,
  fragment: null,
  via: null,
  viaLabel: null,
  label: null,
  description: null,
  axis: null,
  resolved: true,
  editable: false,
  carrier: null,
  styles,
});

/**
 * One worn reference's layer, its styles read at a relative position
 * inside the reference's fragment ('' or a '::selector' suffix), or the
 * session's staged entry when the reference is being edited
 * (StyleStack::referenceLayer mirror).
 *
 * @param {{key: string, selector: ?string}} carrier
 *   The address whose entry wears the reference: the root (selector
 *   null) or a rule under it.
 */
const referenceLayer = (ref, at, variants, carrier, editing = false, staged = undefined) => {
  const record = findVariant(variants, ref.variant);
  const origin = ref.via === null ? null : findVariant(variants, ref.via);
  return {
    id: variantLayerId(ref.variant),
    tier: TIER_VARIANT,
    scope: typeof record?.scope === 'string' ? record.scope : null,
    variant: ref.variant,
    fragment: ref.fragment,
    via: ref.via,
    viaLabel: typeof origin?.label === 'string' ? origin.label : null,
    label: typeof record?.label === 'string' ? record.label : null,
    description:
      typeof record?.description === 'string' ? record.description : null,
    axis: typeof record?.group === 'string' ? record.group : null,
    resolved: record !== null,
    editable: editing,
    carrier,
    styles: conditionValues(
      staged !== undefined
        ? staged
        : record
          ? fragmentStyles(record.value, ref.fragment)[at]
          : null,
    ),
  };
};

/**
 * The rules whose wear delivers at a '::' key, in delivery order
 * (StyleStack::ruleCarriers mirror).
 *
 * Wear at a rule prints inside the owner's @scope with the variant's
 * suffixes continued under the rule by descent (the printer's rebase,
 * under()), so a rule R = "{root}::{r}" delivers at the key K =
 * "{root}::{sel}" when K is R itself (the variant's root entry, read at
 * '') or when one of R's worn variants holds a '::s' entry with
 * under(r, s) printing sel (read at '::s'). The rules come in map order,
 * as the rule-wear lane prints them; a rule's every reference is then a
 * layer at that position, empty or not, exactly as a root's references
 * are at a part key.
 *
 * @return {Array<[string, string]>}
 *   [rule key, relative position] pairs; [] at a root key.
 */
const ruleCarriers = (map, rootKey, relative, variants) => {
  if (relative === '') {
    return [];
  }
  const key = rootKey + relative;
  const sel = relative.slice(SUFFIX.length);
  const carriers = [];
  Object.entries(map ?? {}).forEach(([ruleKey, entry]) => {
    if (!isPlainObject(entry) || !ruleKey.startsWith(rootKey + SUFFIX)) {
      return;
    }
    if (ruleKey === key) {
      carriers.push([ruleKey, '']);
      return;
    }
    const rule = ruleKey.slice(rootKey.length + SUFFIX.length);
    if (parseSelector(rule) === null) {
      return;
    }
    const ats = [];
    entryRefs(entry).forEach((ref) => {
      const record = findVariant(variants, ref.variant);
      if (!record) {
        return;
      }
      Object.keys(fragmentStyles(record.value, ref.fragment)).forEach((suffix) => {
        if (
          suffix !== '' &&
          under(rule, suffix.slice(SUFFIX.length)) === sel &&
          !ats.includes(suffix)
        ) {
          ats.push(suffix);
        }
      });
    });
    ats.forEach((at) => carriers.push([ruleKey, at]));
  });
  return carriers;
};

/**
 * The map's layers at a key: its carriers' worn references in delivery
 * order (the rules wearing at the key, then the root), then the own
 * entry (StyleStack::mapLayers mirror). Each reference layer names its
 * carrier (`carrier`: the rule's address, or the root's with a null
 * selector). In a variant-editing session the edited reference's layer
 * on whichever carrier wears it (the root, or a rule the key reads
 * through: the rule itself at '' when the session anchors at a rule)
 * carries the staged document and is the editable layer instead of the
 * own one.
 */
const mapLayers = ({
  guide,
  map,
  rootKey,
  relative,
  variants,
  axes,
  blockTypes,
  sectionLayouts,
  session,
  addressed = false,
}) => {
  const layers = [];
  let sessionApplied = false;
  // A carrier's reference as a layer at its position; the edited one
  // carries the staged document at that slot (the sheet Push produces).
  const layerOf = (ref, at, carrier) => {
    const editing = session?.reference === ref.variant;
    let staged;
    if (editing) {
      const record = findVariant(variants, ref.variant);
      staged = documentSlotEntry(
        isPlainObject(record?.value) ? record.value : null,
        ref.fragment + at,
        session.pending ?? null,
      ).entry;
      sessionApplied = true;
    }
    return referenceLayer(ref, at, variants, carrier, editing, staged);
  };
  ruleCarriers(map, rootKey, relative, variants).forEach(([ruleKey, at]) => {
    const carrier = {
      key: rootKey,
      selector: ruleKey.slice(rootKey.length + SUFFIX.length),
    };
    orderedEntryRefs(
      map[ruleKey],
      variants,
      axisVocabulary(guide, ruleKey, blockTypes, sectionLayouts),
    ).forEach((ref) => {
      layers.push(layerOf(ref, at, carrier));
    });
  });
  orderedEntryRefs(map?.[rootKey], variants, axes).forEach((ref) => {
    layers.push(layerOf(ref, relative, { key: rootKey, selector: null }));
  });
  layers.push({
    id: ELEMENT_LAYER,
    tier: TIER_ELEMENT,
    scope: null,
    variant: null,
    fragment: null,
    via: null,
    viaLabel: null,
    label: null,
    description: null,
    axis: null,
    resolved: true,
    editable: !sessionApplied && !addressed,
    carrier: null,
    styles: conditionValues(map?.[rootKey + relative]),
  });
  return layers;
};

/**
 * The '::selector' suffixes any layer styles at an element key
 * (StyleStack::partSuffixes mirror): the selectors the key's rules'
 * wear continues under them (a rule '::> pb-text' wearing a variant with
 * a '::.link' entry delivers at '::> pb-text .link', the printer's
 * rebase by under()), rules in map order and references in delivery
 * order; then the suffixes each of the key's own worn references'
 * fragment delivers, in delivery order; then the map's own selector
 * entries beside the key, in map order; deduplicated. A generated box
 * is one more suffix of the root: '::&::before' and '::.quote::after'
 * list as siblings of '::.quote', never under it, and a rule's worn
 * '::&::before' entry continues under the rule as '::> pb-text::before'
 * (the anchor binds to the rule's landing). [] for a key that already
 * carries a selector (selectors do not nest, and a pseudo-element is
 * terminal).
 *
 * @param {object} guide
 *   The style guide payload (a rule's delivery vocabulary), or null to
 *   keep every rule's stored reference order.
 *
 * @return {string[]}
 */
export const selectorSuffixes = (
  map,
  key,
  variants,
  axes = null,
  guide = null,
  blockTypes = {},
  sectionLayouts = {},
) => {
  if (key.includes(SUFFIX)) {
    return [];
  }
  const suffixes = [];
  const push = (suffix) => {
    if (!suffixes.includes(suffix)) {
      suffixes.push(suffix);
    }
  };
  Object.entries(map ?? {}).forEach(([ruleKey, entry]) => {
    if (!isPlainObject(entry) || !ruleKey.startsWith(key + SUFFIX)) {
      return;
    }
    const rule = ruleKey.slice(key.length + SUFFIX.length);
    if (parseSelector(rule) === null) {
      return;
    }
    const ruleVocabulary = guide
      ? axisVocabulary(guide, ruleKey, blockTypes, sectionLayouts)
      : null;
    orderedEntryRefs(entry, variants, ruleVocabulary).forEach((ref) => {
      const record = findVariant(variants, ref.variant);
      if (!record) {
        return;
      }
      Object.keys(fragmentStyles(record.value, ref.fragment)).forEach((suffix) => {
        const continued =
          suffix === '' ? null : under(rule, suffix.slice(SUFFIX.length));
        if (continued !== null) {
          push(SUFFIX + continued);
        }
      });
    });
  });
  orderedEntryRefs(map?.[key], variants, axes).forEach((ref) => {
    const record = findVariant(variants, ref.variant);
    if (!record) {
      return;
    }
    Object.keys(fragmentStyles(record.value, ref.fragment)).forEach((suffix) => {
      if (suffix !== '') {
        push(suffix);
      }
    });
  });
  Object.entries(map ?? {}).forEach(([mapKey, entry]) => {
    if (mapKey.startsWith(key + SUFFIX) && isPlainObject(entry)) {
      push(mapKey.slice(key.length));
    }
  });
  return suffixes;
};

/**
 * Folds a rule's structural facts into what it delivers to one element
 * (StyleStack::foldFacts mirror).
 *
 * A condition compound carrying facts (`first-child`, `hover+odd`)
 * applies to the element only when every fact holds for it (the
 * matcher, by the fact's printed wrapper): a compound with an
 * unsatisfied fact drops, a satisfied one delivers under its posable
 * remainder (`default`, `hover`), where its values beat a remainder that
 * carried fewer facts (each fact is one pseudo-class of specificity)
 * and, at equal facts, a later block beats an earlier one. The element's
 * own keys keep their fact conditions raw (the code view lights them);
 * only another element's rule is read from this element's place.
 */
const foldFacts = (entry, matcher, key, conditions) => {
  const folded = {};
  const weights = {};
  Object.entries(entry).forEach(([condition, properties]) => {
    const keep = [];
    let facts = 0;
    let holds = true;
    for (const part of condition.split('+')) {
      if (!isFactCondition(part, conditions)) {
        keep.push(part);
      } else {
        facts += 1;
        if (!matcher.satisfies(key, conditions[part]?.wrapper ?? '')) {
          holds = false;
          break;
        }
      }
    }
    if (!holds) {
      return;
    }
    const target = keep.length ? keep.join('+') : 'default';
    Object.entries(properties).forEach(([property, value]) => {
      if ((weights[target]?.[property] ?? -1) > facts) {
        return;
      }
      (folded[target] ??= {})[property] = value;
      (weights[target] ??= {})[property] = facts;
    });
  });
  return folded;
};

/**
 * The ancestor tier: every ancestor rule position reaching the key as a
 * group of layers, farthest ancestor first (StyleStack::ancestorLayers
 * mirror).
 *
 * A key has ancestor layers at its root (the ancestor's rule targets
 * this element), at a tag tier (`::p`: the rule's subject is that tag
 * inside this element) and at a part (`::.cls`: the rule qualifies a
 * type compound this element matches); any other selector key is a
 * rule of its own. Per ancestor, its selector suffixes come in
 * selectorSuffixes() order (the rules' continued suffixes, the root's
 * worn fragments' suffixes, then the map's); each reaching position
 * contributes its map layers (mapLayers at the ancestor's position: the
 * rule's own wear and the owner's wear at the position, the owner's
 * ordered by the ANCESTOR's axis vocabulary, then the rule's entry) with
 * their identities kept, each folded on its own (foldFacts), the worn
 * ones as worn ancestor layers naming the variant and its carrier where
 * they deliver, the entry as the own ancestor layer. A group exists when
 * any of its layers delivers at the key, and the own layer rides its
 * group empty or not; a position delivering nothing contributes no
 * layer, unless it is the write address (`editable`): that rule's own
 * layer is the editable one, marked when its group exists and inserted
 * empty at its delivery position when it does not (placeEditable).
 */
const ancestorLayers = ({
  guide,
  map,
  rootKey,
  relative,
  variants,
  blockTypes,
  sectionLayouts,
  tree,
  matcher,
  editable,
}) => {
  if (!matcher.has(rootKey)) {
    return placeEditable([], matcher, rootKey, editable);
  }
  let sub = null;
  if (relative !== '') {
    // The `::p` or `::.cls` tier is ONE unqualified compound reached by
    // descent, with or without its generated box behind it; the owner's
    // own box (`::&::before`) has no compound at all. A qualified
    // compound (`p:has(a)`) or a chain is a rule of its own.
    const record = parseSelector(relative.slice(SUFFIX.length));
    if (record === null) {
      return [];
    }
    const { compounds } = record;
    const tier =
      compounds.length === 0 ||
      (compounds.length === 1 &&
        compounds[0].combinator === DESCENDANT &&
        !compounds[0].qualifiers.length &&
        [TAG, CLASS_PART].includes(compounds[0].kind));
    if (!tier) {
      return [];
    }
    // The position with its pseudo: the matcher compares the boxes by
    // name and answers the host.
    sub = record;
  }
  const conditions = isPlainObject(guide?.conditions) ? guide.conditions : {};
  const layers = [];
  matcher.ancestors(rootKey).forEach((ancestor) => {
    const axes = axisVocabulary(guide, ancestor, blockTypes, sectionLayouts, tree);
    selectorSuffixes(map, ancestor, variants, axes, guide, blockTypes, sectionLayouts).forEach((suffix) => {
      const position = suffix.slice(SUFFIX.length);
      const selector = parseSelector(position);
      if (selector === null || !matcher.reaches(ancestor, selector, rootKey, sub)) {
        return;
      }
      const group = mapLayers({
        guide,
        map,
        rootKey: ancestor,
        relative: suffix,
        variants,
        axes,
        blockTypes,
        sectionLayouts,
        session: null,
      }).flatMap((layer) => {
        const styles = foldFacts(layer.styles, matcher, rootKey, conditions);
        if (layer.tier === TIER_ELEMENT) {
          return [ancestorLayer(matcher, ancestor, position, styles)];
        }
        // A worn layer joins the group only where it delivers: the
        // reference is a fact of its carrier (the rule's own stack
        // lists it empty or stale), while a member's tier carries what
        // reaches the member.
        return Object.keys(styles).length
          ? [ancestorWearLayer(matcher, ancestor, position, layer, styles)]
          : [];
      });
      if (group.some((layer) => Object.keys(layer.styles).length)) {
        layers.push(...group);
      }
    });
  });
  return placeEditable(layers, matcher, rootKey, editable);
};

/**
 * The owner's name an ancestor layer carries for the sentences: the
 * tree node's minted `_element.name` (the matcher's `name` fact), null
 * when the tree has no node for the owner.
 */
const ownerOf = (matcher, owner) => matcher.facts(owner)?.name ?? null;

/**
 * A rule position's own ancestor layer: the rule's entry, read from the
 * member's place. Its label is its owner's name (the layer IS the
 * owner's rule), carried again as `owner` so the own and the worn
 * spelling name the owner through one field.
 */
const ancestorLayer = (matcher, owner, selector, styles) => ({
  id: ancestorLayerId(owner, selector),
  tier: TIER_ANCESTOR,
  scope: owner,
  variant: null,
  fragment: null,
  via: null,
  viaLabel: null,
  label: ownerOf(matcher, owner),
  owner: ownerOf(matcher, owner),
  description: null,
  axis: null,
  resolved: true,
  editable: false,
  carrier: null,
  styles: styles ?? {},
});

/**
 * A rule position's worn ancestor layer: a variant layer of the
 * position (mapLayers at the ancestor) re-identified as the ancestor
 * tier's, keeping the variant's identity (variant, fragment, via, label,
 * description, axis, resolved) and its carrier, scoped to the owner like
 * the own layer and naming the owner like it (`owner`), never editable
 * (a doorway edits at the carrier).
 */
const ancestorWearLayer = (matcher, owner, position, layer, styles) => ({
  ...layer,
  id: ancestorWearLayerId(owner, position, layer.variant),
  tier: TIER_ANCESTOR,
  scope: owner,
  owner: ownerOf(matcher, owner),
  editable: false,
  styles,
});

/**
 * The write address's layer among the ancestor layers: the existing one
 * marked editable, or a virtual empty one inserted where delivery would
 * put the rule.
 *
 * The tier lists rules farthest ancestor first and, per owner, in
 * selectorSuffixes() order (a new key joins the map last); so a rule not
 * yet in the map sits after every layer of an owner at or beyond its
 * own on the ancestor walk and before every nearer owner's. An owner
 * the tree does not place among the key's ancestors (or no tree at
 * all) sits nearest the own entry, the honest fallback: the write must
 * still have an editable layer to read `own` from.
 *
 * @param {Array<object>} layers
 *   The ancestor layers, farthest first.
 * @param {object} matcher
 *   The matcher over the tree.
 * @param {string} rootKey
 *   The key's root.
 * @param {?{key: string, selector: string}} editable
 *   The write address, or null for the own key.
 *
 * @return {Array<object>}
 */
const placeEditable = (layers, matcher, rootKey, editable) => {
  if (
    !isPlainObject(editable) ||
    typeof editable.key !== 'string' ||
    typeof editable.selector !== 'string' ||
    editable.selector === ''
  ) {
    return layers;
  }
  const id = ancestorLayerId(editable.key, editable.selector);
  const existing = layers.find((layer) => layer.id === id);
  if (existing) {
    return layers.map((layer) =>
      layer === existing ? { ...layer, editable: true } : layer,
    );
  }
  const ancestors = matcher.ancestors(rootKey);
  const rank = (owner) => {
    const at = ancestors.indexOf(owner);
    return at === -1 ? ancestors.length : at;
  };
  const own = rank(editable.key);
  let position = layers.length;
  for (let i = 0; i < layers.length; i += 1) {
    if (rank(layers[i].scope) > own) {
      position = i;
      break;
    }
  }
  const virtual = {
    ...ancestorLayer(matcher, editable.key, editable.selector, {}),
    editable: true,
  };
  return [...layers.slice(0, position), virtual, ...layers.slice(position)];
};

/**
 * The read subject's tag over an indexed tree (subjectTag's worker).
 *
 * @param {object} matcher
 *   The matcher over the tree (createMatcher).
 * @param {string} rootKey
 *   The key's root.
 * @param {string} relative
 *   The key's '::selector' suffix ('' at the root).
 *
 * @return {?string}
 */
const subjectTagOf = (matcher, rootKey, relative) => {
  const facts = matcher.facts(rootKey);
  if (facts === null) {
    return null;
  }
  if (relative === '') {
    return facts.tag;
  }
  // A declared part: ONE unqualified class compound reached by descent
  // (the anatomy's spelling), with no generated box behind it; the
  // part's tag is the annotation's, null for a part declaring none. Any
  // other suffix names a rule (a tag tier or a chain has many matches
  // and no one subject) or a generated box (no element, no tag).
  const record = parseSelector(relative.slice(SUFFIX.length));
  const compounds = record === null || record.pseudo !== null ? null : record.compounds;
  if (
    compounds === null ||
    compounds.length !== 1 ||
    compounds[0].combinator !== DESCENDANT ||
    compounds[0].qualifiers.length ||
    compounds[0].kind !== CLASS_PART ||
    !Object.hasOwn(facts.parts, compounds[0].name)
  ) {
    return null;
  }
  return facts.parts[compounds[0].name];
};

/**
 * The read subject's tag: the ONE element a style-map key reads.
 *
 * The subject is the element itself for an element key (its rendered
 * root tag, the annotation's `_element.markup.tag`), its declared part
 * for a part key (`::.cls`: the part's declared tag, null for a part
 * declaring none), and nothing for a tag tier or a chain (`::p`,
 * `::> *`, `::> pb-text .link`: a rule's matches are many, so no one
 * tag stands for them) nor for a generated box (`::&::before`,
 * `::.cls::after`: no element, no tag, floorless like a chain). The tag
 * keys the skin's base:{tag} layer
 * (buildStack) and, through offerTag(), the base variants' offer; it is
 * a fact of the tree, never a canvas read, so the leader and the
 * satellite answer alike. Null without a tree, for a key the tree does
 * not carry (an entity or field key, a bare key outside it) and for
 * every subjectless key.
 *
 * @param {?Array} tree
 *   The blueprint's lean node tree (source.getTree()), or null.
 * @param {string} key
 *   A full style-map key.
 *
 * @return {?string}
 */
export const subjectTag = (tree, key) => {
  if (!Array.isArray(tree) || typeof key !== 'string' || key === '') {
    return null;
  }
  const pos = key.indexOf(SUFFIX);
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  return subjectTagOf(createMatcher(tree), rootKey, key.slice(rootKey.length));
};

/**
 * The tag the base:{tag} variants' OFFER is gated on at a key
 * (variant-scope.js applicableVariants' tag argument).
 *
 * The read subject's tag where the key has a subject (subjectTag: an
 * element's rendered root tag, a part's declared tag), and for a TAG
 * RULE the rule's own tag (`::a`, `container:X::> pb-text a`: the last
 * compound names the tag every match renders as, the landing its wear
 * pairs at, base:a). A type rule or the children (`::> pb-text`,
 * `::> *`) name no one tag, so no base variant offers there, the
 * never-over-offering posture; the server would accept a base variant
 * at any landing (Address::pairsWith's base rule), so this gate is the
 * client's alone.
 *
 * @param {?Array} tree
 *   The blueprint's lean node tree (source.getTree()), or null.
 * @param {string} key
 *   A full style-map key.
 *
 * @return {?string}
 */
export const offerTag = (tree, key) => {
  const subject = subjectTag(tree, key);
  if (subject !== null) {
    return subject;
  }
  if (typeof key !== 'string') {
    return null;
  }
  const pos = key.indexOf(SUFFIX);
  const record = pos === -1 ? null : parseSelector(key.slice(pos + SUFFIX.length));
  // A generated box (`a::before`, `&::after`) renders as no tag: no base
  // variant pairs there (the roster's landingScope is null for a pseudo).
  const compounds = record === null || record.pseudo !== null ? null : record.compounds;
  const last = compounds?.[compounds.length - 1] ?? null;
  return last?.kind === TAG ? last.name : null;
};

/**
 * Builds the layer stack a style-map key wears, bottom to top.
 *
 * @param {object} args
 * @param {object} args.guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide): its
 *   declaredDefaults, skinDefaults and, through the caller's `axes`, the
 *   scope's vocabulary.
 * @param {?object} args.map
 *   The style map (mirror view).
 * @param {string} args.key
 *   A full style-map key (root, a '::selector' relative such as
 *   '::.class', '::p' or '::> *', or block-field form).
 * @param {?Array<object>} args.variants
 *   The store's variant records (the roster's delivery shape).
 * @param {?Array<string>} [args.axes]
 *   The root scope's axis vocabulary (axisVocabulary) for delivery-order
 *   resolution; null keeps the stored reference order.
 * @param {object} [args.blockTypes]
 *   component uuid → inline block type (or 'plugin:{id}'), for scope
 *   capture.
 * @param {object} [args.sectionLayouts]
 *   section uuid → layout id, for scope capture.
 * @param {boolean} [args.floor]
 *   Whether the declared, skin and ancestor tiers join the stack (true:
 *   the selected element's full read, DevTools' rule: inherited rules
 *   for the element you are inspecting); false prints the map's layers
 *   only (the tree scopes' sparse rows, the roster's occupancy read).
 * @param {?Array} [args.tree]
 *   The blueprint's lean node tree (source.getTree(): the delivered
 *   `_tree`), when the caller holds it: keys the ancestor tier and the
 *   skin's base:{tag} layer (the read subject's tag, subjectTag), both
 *   with the floor. Null leaves both out.
 * @param {?{reference: string, pending: ?object}} [args.session]
 *   An open variant-editing session: the edited reference and the
 *   pending update session (pending.js) whose staged lines overlay the
 *   document. The reference's layer on this key's carrier (its root, or
 *   a rule wearing at it, the rule itself when the key IS the anchoring
 *   rule) then carries the staged document and is the editable layer;
 *   null when no session is open or no carrier of this key wears the
 *   reference.
 * @param {?{key: string, selector: string}} [args.editable]
 *   The write address: an ancestor rule whose layer is the editable one
 *   instead of the element's own (marked when it delivers, inserted
 *   empty at its delivery position when it does not; see the file doc).
 *   Null (the default) keeps the own layer editable, today's stack byte
 *   for byte. Needs the floor and the tree, like the tier it lives in.
 *
 * @return {{key: string, rootKey: string, relative: string, scope:
 *   ?string, layers: Array<object>, baseTags: string[], skin: boolean}}
 *   The stack. Each layer is {id, tier, scope, variant, fragment, via,
 *   viaLabel, label, description, axis, resolved, editable, carrier,
 *   styles} (styles: condition → property → value, raw as stored;
 *   `carrier` the address `{key, selector}` whose entry wears the
 *   reference a layer reads, the root's with a null selector, for a
 *   variant layer and a worn ancestor layer, null for every other
 *   layer). `scope` is the captured root address (null when the key
 *   does not parse), `baseTags` the tags the active skin carries
 *   base:{tag} defaults for and `skin` whether the payload carries an
 *   active skin's defaults.
 */
export const buildStack = ({
  guide,
  map,
  key,
  variants,
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  floor = true,
  tree = null,
  session = null,
  editable = null,
}) => {
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const relative = key.slice(rootKey.length);
  // The floor is the OWNER's: a rule reads its root's declared and skin
  // tiers at its own relative (captureScope of the rule itself would be
  // its landing, the scope its wear pairs at, not its floor).
  const scope = captureScope(rootKey, blockTypes, sectionLayouts);
  // The tree, indexed once for both tiers it keys: the subject's tag
  // for the base layer and the ancestor walk.
  const matcher = floor && Array.isArray(tree) ? createMatcher(tree) : null;
  const tag = matcher === null ? null : subjectTagOf(matcher, rootKey, relative);
  const skinDefaults = isPlainObject(guide?.skinDefaults)
    ? guide.skinDefaults
    : null;
  const declaredDefaults = isPlainObject(guide?.declaredDefaults)
    ? guide.declaredDefaults
    : {};

  const layers = [];
  const pushTier = (tier, tierScope, entry) => {
    const styles = conditionValues(entry);
    if (Object.keys(styles).length) {
      layers.push(tierLayer(tier, tierScope, styles));
    }
  };
  if (floor && scope !== null) {
    // The floor's block addresses: the family kind's block beneath the
    // refinement's, the rank order delivery prints both tiers in.
    const scopes = [familyKindOf(scope), scope].filter(
      (candidate) => candidate !== null,
    );
    scopes.forEach((tierScope) => {
      pushTier(TIER_DECLARED, tierScope, declaredDefaults[tierScope]?.[relative]);
    });
    if (tag !== null && skinDefaults !== null) {
      // A base block styles the subject element itself, whatever key
      // names it, so it reads at the root position.
      pushTier(TIER_SKIN, `base:${tag}`, skinDefaults[`base:${tag}`]?.['']);
    }
    scopes.forEach((tierScope) => {
      pushTier(TIER_SKIN, tierScope, skinDefaults?.[tierScope]?.[relative]);
    });
  }

  // The write address, when it is not the own key: the own layer then
  // yields the editable mark to the address's ancestor layer.
  const address =
    isPlainObject(editable) &&
    typeof editable.key === 'string' &&
    typeof editable.selector === 'string' &&
    editable.selector !== '' &&
    `${editable.key}${SUFFIX}${editable.selector}` !== key
      ? editable
      : null;

  if (matcher !== null) {
    layers.push(
      ...ancestorLayers({
        guide,
        map,
        rootKey,
        relative,
        variants,
        blockTypes,
        sectionLayouts,
        tree,
        matcher,
        editable: address,
      }),
    );
  }

  layers.push(
    ...mapLayers({
      guide,
      map,
      rootKey,
      relative,
      variants,
      axes,
      blockTypes,
      sectionLayouts,
      session,
      addressed: address !== null,
    }),
  );

  return {
    key,
    rootKey,
    relative,
    scope,
    layers,
    baseTags: Object.keys(skinDefaults ?? {})
      .filter((address) => address.startsWith('base:'))
      .map((address) => address.slice('base:'.length)),
    skin: skinDefaults !== null,
  };
};

/**
 * The stack's lines at one exact condition, winners marked by index.
 *
 * The cascade's own reading at a STORED condition (no cascade across
 * conditions; StyleStack::linesAt mirror): per property the LAST
 * defining layer wins, delivery order with the editable layer on top,
 * and every earlier defining layer is lost to it. Lines come in layer
 * order, then the layer's property order.
 *
 * @param {object} stack
 *   A buildStack() result.
 * @param {string} condition
 *   A stored condition ('default', 'hover', 'narrow', a compound).
 *
 * @return {Array<{layer: number, property: string, value: string, lost:
 *   boolean, winner: ?number}>}
 *   `layer` indexes the stack's layers; `winner` is the winning layer's
 *   index when lost, else null. [] when no layer sets anything at the
 *   condition.
 */
export const layersAt = (stack, condition) => {
  const winners = new Map();
  stack.layers.forEach((layer, index) => {
    Object.keys(layer.styles[condition] ?? {}).forEach((property) => {
      winners.set(property, index);
    });
  });
  const lines = [];
  stack.layers.forEach((layer, index) => {
    Object.entries(layer.styles[condition] ?? {}).forEach(
      ([property, value]) => {
        const lost = winners.get(property) !== index;
        lines.push({
          layer: index,
          property,
          value,
          lost,
          winner: lost ? winners.get(property) : null,
        });
      },
    );
  });
  return lines;
};

/**
 * The condition-keyed entry the stack delivers: per stored condition,
 * every property's winning value at that exact condition (layersAt's
 * winners), a projection of layersAt and never a third read.
 *
 * The advanced rows' roster: the sections and rows a key shows are the
 * declared groups plus every property ANY layer sets (a style that
 * applies to the element shows, whichever tier delivers it), and a
 * row's "Also set at" menu lists the other stored conditions carrying
 * a value. Conditions come in first-seen order across the layers
 * bottom to top; consumers rank them (buildSections, propertyValues).
 *
 * @param {object} stack
 *   A buildStack() result.
 *
 * @return {Object<string, Object<string, string>>}
 *   condition → property → the winning value; {} when no layer sets
 *   anything.
 */
export const deliveredEntry = (stack) => {
  const entry = {};
  const conditions = new Set();
  stack.layers.forEach((layer) => {
    Object.keys(layer.styles).forEach((condition) => conditions.add(condition));
  });
  conditions.forEach((condition) => {
    layersAt(stack, condition).forEach((line) => {
      if (!line.lost) {
        (entry[condition] ??= {})[line.property] = line.value;
      }
    });
  });
  return entry;
};

/**
 * The layer a line (or a read entry) names.
 *
 * @param {object} stack
 *   A buildStack() result.
 * @param {number} index
 *   A layer index (a layersAt() line's `layer` or `winner`, a stackAt()
 *   entry's `layer`).
 *
 * @return {?object}
 *   The layer, or null when the index names none.
 */
export const layerAt = (stack, index) =>
  typeof index === 'number' ? (stack.layers[index] ?? null) : null;

/**
 * The index of the stack's editable layer (the element's own, or the
 * session's document layer).
 *
 * @return {number}
 *   The index, -1 when no layer is editable.
 */
export const editableLayer = (stack) =>
  stack.layers.findIndex((layer) => layer.editable);

/**
 * One property's delivery at a POSE: the winner, the layers beneath it,
 * and what the editable layer says.
 *
 * The rows' read. Each layer answers through the condition cascade
 * (entryAtPose: its `default` block delivers everywhere, a subset
 * compound overrides it, the posed compound's own block wins), and the
 * winner is the TOPMOST layer with a delivering block, because the
 * printer emits every layer's conditions in document order and a higher
 * layer's applicable block always follows a lower one's. The shown value
 * is always the winner's; the mark is the winner's layer; the menu is
 * `beneath`; the verb is Unset when `own` delivers at the exact pose
 * (own.condition === pose), otherwise an edit writes an own override at
 * the pose; a null winner is honestly unset.
 *
 * @param {object} stack
 *   A buildStack() result.
 * @param {string} property
 *   The property.
 * @param {string} pose
 *   The editing compound ('default', a single, or 'a+b').
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for cascade order.
 *
 * @return {{winner: ?object, beneath: Array<object>, own: ?object}}
 *   Each entry is {layer: index, id, tier, variant, condition, value}
 *   (the delivering block's condition and value); `beneath` lists the
 *   delivering layers under the winner nearest first, so beneath[0] is
 *   what an own override overrides; `own` is the editable layer's
 *   delivering block, or null when it delivers nothing at the pose.
 */
export const stackAt = (stack, property, pose, conditions = {}) => {
  const reads = [];
  stack.layers.forEach((layer, index) => {
    const block = entryAtPose(layer.styles, property, pose, conditions);
    if (block) {
      reads.push({
        layer: index,
        id: layer.id,
        tier: layer.tier,
        variant: layer.variant,
        condition: block.condition,
        value: block.value,
      });
    }
  });
  const editable = editableLayer(stack);
  return {
    winner: reads.length ? reads[reads.length - 1] : null,
    beneath: reads.slice(0, -1).reverse(),
    own: reads.find((read) => read.layer === editable) ?? null,
  };
};
