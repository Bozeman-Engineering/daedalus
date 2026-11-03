import { islandOps, stackLines } from './island.js';
import {
  ELEMENT_LAYER,
  TIER_ANCESTOR,
  ancestorLayerId,
  ancestorRule,
  ancestorVariant,
  ancestorWearLayerId,
  isAncestorLayer,
} from './layers.js';
import { buildStack, deliveredEntry, selectorSuffixes } from '../style-stack.js';
import { parseKey } from '../style-guide.js';
import { createMatcher, presentRoster } from '../selector-matcher.js';
import { axisVocabulary } from '../variant-scope.js';
import { layoutOf } from '../../../../daedalus_ui/js/edit_mode/blueprint/document-tree.js';
import { planRuleMove } from '../rule-move.js';
import {
  ANCHOR,
  ANCHOR_KIND,
  CLASS_PART,
  CLASS_PATTERN,
  GENERATED_BOXES,
  HAS,
  IS,
  NOT,
  NTH_POSITIONS,
  POSITIONS,
  SUFFIX,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
  canonicalSelector,
  parseSelector,
  printSelector,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { OWNER, digest, reach } from '../../../../daedalus_ui/js/edit_mode/materials/selector-digest.js';
import { widePseudoElements } from '../wide-selectors.js';
import { vocabularyPseudos } from '../pseudo-vocabularies.js';

/**
 * The offer kind of a generated box option (`&::before`,
 * `> d-text::after`): the pseudo-element is the selector's terminal, not
 * a compound, so it is not one of the grammar's compound kinds.
 */
export const PSEUDO = 'pseudo';

/**
 * The single-element islands view model (pure; the view renders it).
 *
 * In the islands architecture ALL structure is chrome: which islands exist
 * for an element — its default declaration list, a condition row per condition
 * holding values, a part row per styled sub-element, and on the SELECTED
 * element a rule row per ancestor rule reaching it (DevTools' rule:
 * inherited rules for the element you are inspecting, each an editable
 * island writing at the ancestor's key) —
 * is a projection of the style map and the worn variants, never
 * something typing creates. This module computes that roster, the two
 * line projections the own and the rule islands print through, the
 * removal-gesture honesty reads, and the settle cadence filter (which of
 * an island's diffed ops have actually SETTLED and may commit). Everything
 * is data-in/data-out so the same logic serves both hosts and gets
 * node-test backpressure.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Orders condition names the way rows render: 'default' first (always
 * present), declared conditions in the guide's vocabulary order, unknown
 * names last in their given order (inert data still shows honestly).
 * Deduplicates, so callers can merge sources (an entry's holdings plus
 * materialized drafts) without bookkeeping.
 *
 * @param {string[]} names
 *   Condition names in any order, 'default' optional, duplicates fine.
 * @param {object} guide
 *   The style guide payload (for the conditions vocabulary order).
 *
 * @return {string[]}
 *   Condition names, 'default' first.
 */
export const orderConditions = (names, guide) => {
  const declared = Object.keys(guide?.conditions ?? {});
  const rank = (condition) => {
    const index = declared.indexOf(condition);
    return index === -1 ? declared.length : index;
  };
  const unique = [...new Set(names)].filter(
    (condition) => condition !== 'default',
  );
  unique.sort((a, b) => rank(a) - rank(b));
  return ['default', ...unique];
};

/**
 * The condition islands an entry needs: 'default' always, then every named
 * condition holding at least one value, in the guide's declaration order
 * (unknown names last, stored order — inert data still shows honestly).
 *
 * @param {?object} entry
 *   The MAP layers' delivered entry (deliveredEntry over the key's
 *   floorless stack): variant-supplied conditions get their condition rows
 *   exactly like own ones; the floor's conditions are not islands.
 * @param {object} guide
 *   The style guide payload (for the conditions vocabulary order).
 *
 * @return {string[]}
 *   Condition names, 'default' first.
 */
export const islandConditions = (entry, guide) => {
  if (!isEntryObject(entry)) {
    return ['default'];
  }
  const holding = Object.entries(entry)
    .filter(
      ([condition, values]) =>
        condition !== 'default' &&
        condition !== 'variants' &&
        isEntryObject(values) &&
        Object.values(values).some(
          (value) => value !== null && value !== undefined,
        ),
    )
    .map(([condition]) => condition);
  return orderConditions(holding, guide);
};

/**
 * The map layers' conditions at one island key (the element's own and
 * its worn references', through the stack), never the floor's: a
 * skin's narrow block is not an island of the element.
 */
const mapConditions = (map, key, variants, axes, guide) =>
  islandConditions(
    deliveredEntry(buildStack({ map, key, variants, axes, floor: false })),
    guide,
  );

/**
 * The island roster for one selected element.
 *
 * Enumerates what the chrome renders: the root's condition islands
 * (default always), and a selector row per position the stack says the
 * element styles. The positions are the stack's own enumeration,
 * selectorSuffixes() (StyleStack::partSuffixes' twin), verbatim: the
 * selectors the element's rules' wear continues under them (a rule
 * '::> d-text' wearing a variant with a '::.link' entry delivers at
 * '::> d-text .link'), then the selectors the element's own worn
 * references supply (each reference read through ITS OWN fragment, the
 * delivery read), then its own '::selector' map entries (a part
 * '::.class', the tag tier '::p', the children '::> *', a type
 * '::> d-text', their compounds), in delivery order, deduplicated. The
 * roster never walks the map itself: a row exists because the stack
 * delivers there, and only the stack knows where variants deliver, so
 * a rule's worn part row prints under the element with zero own entries
 * exactly as a worn part of the element does. Each row's conditions are
 * read from the stack at that position.
 *
 * With the blueprint's lean node tree (`tree`, the selected element's
 * full body only; the tree scopes' sparse rows pass none) the roster
 * also lists the ANCESTOR RULES reaching each island key (ancestorIslands:
 * the key's stack's ancestor tier, one rule row per position, nearest
 * ancestor first), at the root and per part row, each an island of the
 * rule's own key. Under a write address on an ANCESTOR (`address`, the
 * spine's address fact, its key not this one) the rule the address
 * names joins the SUBJECT facet's row (`facet`, the spine's target
 * selector: the spine carries both facts, nothing derives one from the
 * other) whether the map holds it yet or not: a mintable rule lists as
 * a virtual empty island, the type-here surface a seat from the subject
 * line's chip opens, `existing` false until its first write mints the
 * entry. An address on THIS key (the chip's own tag tier, "Every
 * paragraph here": `{key}::p`) names one of the roster's own part rows,
 * so it reaches no stack and places no rule row: the part row is the
 * island, the view lights it, and the view drafts it when the map lacks
 * it (one lit rule, one placement rule).
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The selected element's selectable key (never a '::' part key).
 * @param {object} guide
 *   The style guide payload.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root scope's axis vocabulary (delivery order).
 * @param {object} blockTypes
 *   The block types table (a rule's landing vocabulary).
 * @param {object} sectionLayouts
 *   The section layouts table (a rule's landing vocabulary).
 * @param {?Array} document
 *   The document tree (source.getDocument(): the host entity above its
 *   sections), when the caller holds it and the roster is the
 *   selection's full body; null lists no ancestor rules.
 * @param {?{key: string, selector: string}} address
 *   The write address (a rule on an ancestor, or on this key), or null.
 *   Only an ancestor's reaches the stack; an own-key address is a part
 *   row of this roster already (or a draft the view mints).
 * @param {?string} facet
 *   The subject facet's selector the address stands under (the spine's
 *   target selector; null for the root), so an ancestor address's rule
 *   lists once, under that facet's row alone.
 *
 * @return {{conditions: string[],
 *   parts: Array<{selector: string, key: string, conditions: string[],
 *   ancestors: Array<object>}>, ancestors: Array<{owner: string,
 *   selector: string, key: string, conditions: string[],
 *   existing: boolean}>}}
 */
export const elementIslands = (
  map,
  key,
  guide,
  variants,
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  document = null,
  address = null,
  facet = null,
) => {
  const conditions = mapConditions(map, key, variants, axes, guide);
  // An own-key address never reaches the stack: handed as `editable` it
  // would place a virtual ancestor layer for a rule this roster already
  // rows as a part (placeEditable's fallback seat), a second island for
  // one key.
  const addressedKey =
    document === null || address === null || address.key === key
      ? null
      : facet === null
        ? key
        : `${key}::${facet}`;
  const ancestorsOf = (islandKey) =>
    document === null
      ? []
      : ancestorIslands({
          guide,
          map,
          key: islandKey,
          variants,
          axes,
          blockTypes,
          sectionLayouts,
          document,
          editable: islandKey === addressedKey ? address : null,
        });
  const parts = selectorSuffixes(
    map,
    key,
    variants,
    axes,
    guide,
    blockTypes,
    sectionLayouts,
  ).map((suffix) => {
    const partKey = key + suffix;
    return {
      selector: suffix.slice(2),
      key: partKey,
      conditions: mapConditions(map, partKey, variants, axes, guide),
      ancestors: ancestorsOf(partKey),
    };
  });

  return { conditions, parts, ancestors: ancestorsOf(key) };
};

/**
 * The ancestor rules reaching one island key, as rule rows: DevTools'
 * inherited rules for the element you are inspecting.
 *
 * Read off the key's own style stack (buildStack with the floor and the
 * document: the ancestor tier lists every rule position the selector
 * matcher says reaches the key, farthest ancestor first, a GROUP of
 * layers per position), one row per position, the group's own layer
 * naming it (layers.js ancestorRule), re-ordered NEAREST ancestor first
 * (the subject line's address set reads the same way; per owner the
 * tier's own position order holds). Each row is an island of the rule's
 * own key (`{owner}::{selector}`): its conditions are the rule's own map
 * layers' (mapConditions at the rule key, the owner's axis vocabulary
 * ordering the owner's wear), never the merged read this element gets,
 * because the island EDITS the rule and a write lands at the condition
 * the rule stores. `existing` says whether the map carries the rule
 * (its entry, or a worn layer delivering at the position: a
 * continuation); under a write address naming a rule nobody wrote yet
 * (`editable`) the stack places a virtual empty own layer at the rule's
 * delivery position (style-stack.js placeEditable), and the row lists
 * there with `existing` false: the seat's type-here surface.
 *
 * @param {object} args
 *   buildStack's arguments for the island key (guide, map, key,
 *   variants, axes, blockTypes, sectionLayouts, document) plus
 *   `editable`, the write address to place when it names this key's
 *   facet, or null.
 *
 * @return {Array<{owner: string, selector: string, key: string,
 *   conditions: string[], existing: boolean}>}
 *   Nearest ancestor first; [] without a document or when nothing
 *   reaches.
 */
export const ancestorIslands = ({
  guide,
  map,
  key,
  variants,
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  document,
  editable = null,
}) => {
  const stack = buildStack({
    guide,
    map,
    key,
    variants,
    axes,
    blockTypes,
    sectionLayouts,
    document,
    editable,
  });
  // Each owner's axis vocabulary reads its depth off the LAYOUT (the
  // sections at the top), never off the document, under whose root
  // every section would read as nested.
  const layout = layoutOf(document);
  const groups = [];
  stack.layers.forEach((layer) => {
    if (layer.tier !== TIER_ANCESTOR) {
      return;
    }
    const rule = ancestorRule(layer.id);
    if (rule === null) {
      return;
    }
    const ruleKey = `${rule.key}::${rule.selector}`;
    let group = groups.find((candidate) => candidate.key === ruleKey);
    if (!group) {
      group = { owner: rule.key, selector: rule.selector, key: ruleKey, worn: 0 };
      groups.push(group);
    }
    if (ancestorVariant(layer.id) !== null) {
      group.worn += 1;
    }
  });
  // The tier walks farthest ancestor first, owners contiguous; the rows
  // read nearest first, each owner's positions in the tier's order.
  const owners = [...new Set(groups.map((group) => group.owner))].reverse();
  return owners
    .flatMap((owner) => groups.filter((group) => group.owner === owner))
    .map((group) => ({
      owner: group.owner,
      selector: group.selector,
      key: group.key,
      conditions: mapConditions(
        map,
        group.key,
        variants,
        axisVocabulary(guide, group.owner, blockTypes, sectionLayouts, layout),
        guide,
      ),
      existing: group.worn > 0 || isEntryObject(map?.[group.key]),
    }));
};

/**
 * The lines an OWN island prints: its stack's lines without the ancestor
 * tier's.
 *
 * On the selected element every ancestor rule reaching a key prints as
 * a rule island of its own beneath (ancestorIslands), so the own
 * islands drop the tier's lines (the rule's own entry and the variants
 * the rule wears, layers.js isAncestorLayer) and keep the floor's:
 * declared and skin lines still print here, and a floor line a rule
 * beats keeps its strike and the rule's winner chip, because the
 * winners are the whole stack's and only the printing moves.
 *
 * @param {Array<object>} lines
 *   stackLines() over the key's stack at the island's condition.
 *
 * @return {Array<object>}
 *   The same line shapes, the ancestor tier's lines left out.
 */
export const ownIslandLines = (lines) =>
  lines.filter((line) => !isAncestorLayer(line.layer));

/**
 * The lines a RULE island prints: the rule's own document, struck where
 * the selected element beats it.
 *
 * A rule island's stack is the rule's own key's (its wear and its entry,
 * the map's layers alone), so its lines diff, commit and lift exactly
 * as the owner's part row for the same rule: the rule's entry is the
 * own layer (a deletion unsets at the rule key, an edit of a worn line
 * overrides there). What this projection adds is display: a line the
 * rule delivers to the SELECTED element that a layer above the rule's
 * group beats there (`reaching`, the element's stack lines at the same
 * condition: the element's own layer, a variant it wears, a nearer
 * ancestor's rule) wears `beaten`, the beating layer's winner
 * ({layer, variant}), which line-marks.js renders as the strike and
 * the winner chip while islandDelta stays blind to it (the rule's own
 * cascade is the diff's baseline: a beaten line is still the rule's
 * effective value, and retyping it is no write). The match is by
 * layer, property AND value, so a fact-merged read (a rule's
 * `first-child` block merged into this element's default read) strikes
 * nothing it cannot vouch for. A line the rule's own cascade already
 * loses stays as it is.
 *
 * @param {Array<object>} lines
 *   stackLines() over the rule key's floorless stack at the condition.
 * @param {{owner: string, selector: string}} rule
 *   The rule row (ancestorIslands' shape).
 * @param {Array<object>} reaching
 *   stackLines() over the selected facet's full stack at the same
 *   condition (the floor and the tree).
 *
 * @return {Array<object>}
 *   The rule's lines, `beaten` added where the element beats one.
 */
export const ruleIslandLines = (lines, rule, reaching) =>
  lines.map((line) => {
    if (line.lost) {
      return line;
    }
    const id =
      line.layer === ELEMENT_LAYER
        ? ancestorLayerId(rule.owner, rule.selector)
        : ancestorWearLayerId(rule.owner, rule.selector, line.variant);
    const hit = reaching.find(
      (candidate) =>
        candidate.lost &&
        candidate.layer === id &&
        candidate.property === line.property &&
        candidate.value === line.value,
    );
    return hit ? { ...line, beaten: hit.winner } : line;
  });

/**
 * Prunes unoccupied islands from a roster (the tree scopes' read for
 * UNSELECTED rows).
 *
 * An island survives only when its layered document prints at least one
 * line, own overrides or variant-supplied values alike (stackLines over
 * the key's stack built WITHOUT the floor, lost lines included: the floor
 * prints on the selected element alone and never decides occupancy), and
 * a part row whose islands all prune drops whole.
 * The single-element body and the selected tree row keep the full roster:
 * empty islands are the type-here surface there. On every other tree row
 * they are placeholder noise, and the way in is selecting the element.
 * In practice only 'default' islands prune (islandConditions already
 * admits named conditions on held values alone), but the read is uniform.
 *
 * @param {{conditions: string[], parts: Array<object>}} model
 *   elementIslands()'s roster.
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The roster's element key.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root scope's axis vocabulary (delivery order).
 *
 * @return {{conditions: string[], parts: Array<object>}}
 *   The pruned roster, same shape.
 */
export const occupiedRoster = (model, map, key, variants, axes = null) => {
  const occupied = (islandKey) => {
    const stack = buildStack({
      map,
      key: islandKey,
      variants,
      axes,
      floor: false,
    });
    return (condition) => stackLines(stack, condition).length > 0;
  };
  const parts = model.parts
    .map((part) => ({
      ...part,
      conditions: part.conditions.filter(occupied(part.key)),
    }))
    .filter((part) => part.conditions.length > 0);
  return {
    ...model,
    conditions: model.conditions.filter(occupied(key)),
    parts,
  };
};

/**
 * Merges materialized drafts into the roster.
 *
 * A materialization gesture (a selector -> its row, `&`/`@` -> condition) creates a
 * chrome row + island BEFORE any declaration commits, so the row exists
 * only in view state until its first settled declaration writes it into
 * the map. This merge is where those drafts join the map-derived roster:
 * conditions slot into vocabulary order, draft parts append after the
 * map's parts (first-seen order holds), and anything the map already
 * surfaces deduplicates away — a draft that became real is absorbed, not
 * doubled. Draft part selectors that cannot parse as keys are dropped
 * (the gestures gate shape before drafting, this is the backstop).
 *
 * Merging never resurrects a pruned 'default' island (occupiedRoster):
 * a drafted named condition joins without dragging the empty default row
 * back in. Drafted NEW part rows keep their default island: that empty
 * island is exactly the draft's type-here surface. A rule row (the
 * root's and a part's ancestors) takes drafted conditions keyed by the
 * rule's key, the `&` gesture on a rule island.
 *
 * @param {{conditions: string[], parts: Array<object>,
 *   ancestors: Array<object>}} model
 *   elementIslands()'s roster.
 * @param {?{conditions: ?object, parts: ?string[]}} drafts
 *   Materialized-but-uncommitted rows: conditions maps island keys (the
 *   element key, a selector key or a rule key) to drafted condition
 *   names; parts lists drafted selectors ('.quote', 'p').
 * @param {string} key
 *   The selected element's key (the roster's root).
 * @param {object} guide
 *   The style guide payload.
 *
 * @return {{conditions: string[], parts: Array<object>,
 *   ancestors: Array<object>}}
 *   The roster with drafts merged, same shape as elementIslands().
 */
export const draftRoster = (model, drafts, key, guide) => {
  const draftConditions = drafts?.conditions ?? {};
  const merge = (had, drafted) => {
    const merged = orderConditions([...had, ...drafted], guide);
    return had.includes('default')
      ? merged
      : merged.filter((name) => name !== 'default');
  };
  const withDrafts = (rules) =>
    (rules ?? []).map((rule) => ({
      ...rule,
      conditions: merge(rule.conditions, draftConditions[rule.key] ?? []),
    }));
  const conditions = merge(model.conditions, draftConditions[key] ?? []);
  const parts = model.parts.map((part) => ({
    ...part,
    conditions: merge(part.conditions, draftConditions[part.key] ?? []),
    ancestors: withDrafts(part.ancestors),
  }));
  (drafts?.parts ?? []).forEach((selector) => {
    if (parts.some((part) => part.selector === selector)) {
      return;
    }
    const partKey = `${key}::${selector}`;
    if (!parseKey(partKey)) {
      return;
    }
    parts.push({
      selector,
      key: partKey,
      conditions: orderConditions(draftConditions[partKey] ?? [], guide),
      ancestors: [],
    });
  });
  return {
    ...model,
    conditions,
    parts,
    ancestors: withDrafts(model.ancestors),
  };
};

/**
 * Merges the posed compound's GHOST island into the roster.
 *
 * The code-view twin of pose+edit minting a condition block in the widget
 * modes: when the canvas stands at a non-default compound the element
 * holds no island for, the roster gains an empty chrome-minted island at
 * the ROOT for that compound — ready to type into, wearing the Posed
 * marker its condition already earns. Typing commits through the island's
 * ordinary settle path, which writes the condition block into the map and
 * makes the row roster-real. The ghost is DERIVED, never stored view
 * state: empty again (or the pose moving away) means the next roster
 * print simply has no reason to include it, so the row disappears —
 * the same honesty rule as empty islands vanishing on deselect.
 *
 * Root only: parts materialize their conditions through the `&` gesture;
 * a pose minting a ghost per part row would be noise, not an invitation.
 *
 * @param {{conditions: string[], parts: Array<object>}} model
 *   The roster (elementIslands(), drafts merged).
 * @param {?string} posed
 *   The compound the canvas is posed at ('default' or null poses nothing).
 * @param {object} guide
 *   The style guide payload (for condition ordering).
 *
 * @return {{conditions: string[], parts: Array<object>}}
 *   The roster with the ghost merged (unchanged when the pose is resting
 *   or the compound already has a row).
 */
export const posedRoster = (model, posed, guide) => {
  if (!posed || posed === 'default' || model.conditions.includes(posed)) {
    return model;
  }
  return {
    ...model,
    conditions: orderConditions([...model.conditions, posed], guide),
  };
};

/**
 * The condition sigil's line: the anchor glued to a word (`&hover`,
 * `&narrow`), the condition completion's shape and never a selector's.
 * The one shape read before the digester sees a line: the sigil is the
 * chrome's shorthand for summoning the condition vocabulary (typing
 * `&ho` and picking Hover), not a grammar word, and `&:hover {` through
 * the digester mints the same condition row.
 */
const SIGIL_LINE = /&[A-Za-z0-9_-]/;

/**
 * A line's generated-box tail (`::`, `::b`, `::before`), glued to the
 * host before it.
 */
const BOX_TAIL = /::([A-Za-z]*)$/;

/**
 * The `:` lane's token: one colon and the pseudo-class name being typed,
 * glued to the compound before it (`a:ho`, `> d-text:`, `&:`, a bare
 * `:` at the owner).
 */
const PSEUDO_TAIL = /(?<![:\s])(:[A-Za-z-]*)$/;

/**
 * A class glued to a tag (`li.act`, `p.`): the tag word and the class
 * token being typed.
 */
const TAG_CLASS_TAIL = /(?<![\w.:-])([a-z][a-z0-9]*)(\.[A-Za-z0-9_-]*)$/;

/**
 * The offer kinds of the `:` lane, beside the grammar's compound kinds:
 * a published posable state (it peels into the row's condition when it
 * stands on the last compound), a position pseudo-class (a qualifier),
 * and an open functional qualifier (`:not(`, `:has(`, `:nth-child(`)
 * the designer finishes typing, which inserts text instead of minting.
 */
export const STATE = 'state';
export const POSITION = 'position';
export const QUALIFIER = 'qualifier';

/**
 * Every addressable key of a document tree, document order: the entity
 * root first (when it carries a key), then the sections and what they
 * hold. The one consumer is the descendant offer below, whose owner
 * filter (`matches(key, …, node)` from the owner) never admits the root.
 */
const documentOrder = (document) => {
  const keys = [];
  const walk = (list) => {
    (Array.isArray(list) ? list : []).forEach((node) => {
      if (node === null || typeof node !== 'object') {
        return;
      }
      const kind = typeof node.kind === 'string' ? node.kind : 'layout_id' in node ? 'container' : 'block';
      if (typeof node.uuid === 'string' && node.uuid !== '' && kind !== 'region') {
        keys.push(`${kind}:${node.uuid}`);
      }
      walk(node.children);
    });
  };
  walk(document);
  return keys;
};

/**
 * The paren and bracket depth at the end of a text: positive inside an
 * unclosed qualifier or attribute, where the completion offers nothing
 * (a relation is typed whole; the `{` mint reads it through the
 * digester).
 */
const openDepth = (text) => {
  let depth = 0;
  for (const char of text) {
    if (char === '(' || char === '[') {
      depth += 1;
    } else if (char === ')' || char === ']') {
      depth -= 1;
    }
  }
  return depth;
};

/**
 * A settled prefix read the digester's way: `&` alone is the owner (''),
 * `& > p` is `> p`, `&:hover > ul` keeps the qualified anchor, and a `&`
 * anywhere but first is no selector.
 *
 * @return {?{prefix: string, landing: ?object, partsOwner: ?string}}
 *   The canonical prefix ('' at the owner), its last compound (null at
 *   the owner), and the type compound whose parts a class after it
 *   names (null when the owner's). Null outside the grammar or on a
 *   settled box (a box takes no continuation).
 */
const settledPrefix = (text) => {
  const trimmed = text.trim();
  if (trimmed.indexOf(ANCHOR, 1) !== -1) {
    return null;
  }
  const relative =
    trimmed === ANCHOR
      ? ''
      : trimmed.startsWith(ANCHOR) && /[\s>+~]/.test(trimmed[1])
        ? trimmed.slice(1).trim()
        : trimmed;
  if (relative === '') {
    return { prefix: '', landing: null, partsOwner: null };
  }
  const parsed = parseSelector(relative);
  if (parsed === null || parsed.pseudo !== null) {
    return null;
  }
  const { compounds } = parsed;
  const types = compounds.filter((compound) => compound.kind === TYPE);
  return {
    prefix: printSelector(parsed),
    landing: compounds.length ? compounds[compounds.length - 1] : null,
    partsOwner: types.length ? types[types.length - 1].name : null,
  };
};

/**
 * Splits a typed selector line into its settled prefix and the token
 * being completed.
 *
 * @return {?{prefix: string, child: boolean, last: string,
 *   landing: ?object, box: boolean, lane: string, partsOwner: ?string}}
 *   `prefix` is the canonical selector typed so far ('' at the owner),
 *   `child` whether a `>` follows it (the next compound is a direct
 *   child), `last` the partial token under the cursor ('' right after a
 *   combinator or a space), `landing` the prefix's last compound or
 *   null at the owner, `box` whether the token is a generated box's
 *   `::` tail (the owner's anchor `&` counts: its only continuations are
 *   its boxes), `lane` which offer the token asks for ('box', 'pseudo'
 *   for a `:` glued to a compound, 'class' for a `.` token by descent,
 *   'tag-class' for one glued to a tag, 'compound' for a word),
 *   `partsOwner` the type whose parts a class names. Null
 *   when the text is the condition sigil's shape, outside the grammar,
 *   inside an unclosed qualifier or attribute, or after a sibling
 *   combinator (a relation is typed whole).
 */
const splitTyped = (typed) => {
  if (typeof typed !== 'string' || SIGIL_LINE.test(typed)) {
    return null;
  }
  // A brace or a semicolon is structure or a declaration's end, never
  // part of a selector being typed.
  const text = typed.replace(/\s*>\s*/g, ' > ').trimStart();
  if (text.trim() === '' || /[{};]/.test(text) || openDepth(text) > 0) {
    return null;
  }
  // A box continues a whole selector, glued (`> d-text::b`, the owner's
  // through the anchor or a bare `::`); a space before the `::` and a
  // box on a box are refused as the digester refuses them.
  const tail = BOX_TAIL.exec(text);
  if (tail !== null) {
    const host = text.slice(0, tail.index);
    if (/\s$/.test(host)) {
      return null;
    }
    const settled = settledPrefix(host === '' ? ANCHOR : host);
    if (settled === null) {
      return null;
    }
    return { ...settled, child: false, last: tail[0], box: true, lane: 'box' };
  }
  if (text.includes(SUFFIX)) {
    // A settled box (`> d-text::before `) takes no continuation.
    return null;
  }
  // The `:` lane: a pseudo-class being typed on the compound before it
  // (a bare `:` is the owner's own, the digester's leading-colon sugar).
  const pseudo = PSEUDO_TAIL.exec(text);
  if (pseudo !== null) {
    const head = text.slice(0, pseudo.index);
    const settled = settledPrefix(head === '' ? ANCHOR : head);
    if (settled === null) {
      return null;
    }
    return { ...settled, child: false, last: pseudo[1], box: false, lane: 'pseudo' };
  }
  // A class glued to a tag (`li.act`): the tag's markup classes.
  const glued = TAG_CLASS_TAIL.exec(text);
  if (glued !== null) {
    const settled = settledPrefix(text.slice(0, glued.index + glued[1].length));
    if (settled === null || settled.landing?.kind !== TAG) {
      return null;
    }
    return { ...settled, child: false, last: glued[2], box: false, lane: 'tag-class' };
  }
  const open = /\s$/.test(text);
  const tokens = text.trim().split(/\s+/);
  let last = open ? '' : tokens.pop();
  if (last === '>') {
    tokens.push('>');
    last = '';
  }
  const child = tokens[tokens.length - 1] === '>';
  const head = (child ? tokens.slice(0, -1) : tokens).join(' ');
  if (head === '' && last === ANCHOR) {
    // The anchor alone: the owner, whose only continuations here are its
    // boxes (its states and classes are the `:` and `.` lanes).
    return { prefix: '', child: false, last, landing: null, box: true, lane: 'box', partsOwner: null };
  }
  const settled = head === '' ? { prefix: '', landing: null, partsOwner: null } : settledPrefix(head);
  if (settled === null) {
    return null;
  }
  return { ...settled, child, last, box: false, lane: last.startsWith('.') ? 'class' : 'compound' };
};

/**
 * The selector completion for a selector typed at a declaration position.
 *
 * One completion for every relative selector shape (Plan: the `>`, `.`,
 * `d-`, tag-word and `:` gestures are one gesture): the typed line is a
 * selector prefix plus the token under the cursor, and the offer is
 * what that position can address, read off the ELEMENT (the selector
 * matcher over the blueprint tree, the same engine the stack and the
 * canvas paint read), never off the style map:
 * - right after `>`: the universal `*` (every child, present and
 *   future) first, then one type compound per type among the matched
 *   set's direct children (`d-text`, `d-container` for a nested
 *   section, `d-block` for a plugin block), then one tag per declared
 *   base tag those children's roots render as;
 * - at a descendant position (the owner, or a space after a compound):
 *   one type per type in the matched set's subtree, one tag per
 *   declared base tag its subtree renders or contains (the owner's own
 *   prose tags included at the owner), and, after a type, that type's
 *   declared parts;
 * - a `.` token: the owner's declared parts (gated on DOM presence, a
 *   declared part absent from the canvas is not offered) or, after a
 *   type, the type's declared parts; glued to a tag (`li.act`) the
 *   declared parts of the owner the tag stands under, as the markup
 *   classes the completion can know; on an open site a well-formed
 *   undeclared class additionally earns the explicit "Create this
 *   part" entry (`create`), and nothing else ever creates;
 * - a `:` glued to a compound (or bare at the owner, `&:` the same): the
 *   published posable states (STATE options, each labelled by its
 *   condition; on the last compound the digester peels it into the
 *   row's condition, before it the state qualifies that compound), the
 *   position pseudo-classes (POSITION options), then `:nth-child(` and
 *   its family, `:not(`, `:has(` and `:is(` as QUALIFIER options whose
 *   pick inserts the text for the designer to finish (`insert`, no
 *   `selector`); nothing inside an unclosed `(` or `[`;
 * - a `::` tail, or the owner's anchor `&`: the two generated boxes of
 *   the subject before it, `::before` then `::after` (`&::before` and
 *   `&::after` at the owner, `> d-text::before` after a type, a part's
 *   or a tag's likewise). A box is universal (every element has both),
 *   so nothing gates the offer here; a strict site's undeclared box is
 *   the server's refusal on the first write, as for an undeclared tag.
 *   The `::` must be glued to a whole selector (`> d-text ::` offers
 *   nothing, as the grammar reads it as every descendant's box), and a
 *   box takes no continuation.
 * Each option carries the FULL canonical selector to mint (`> d-text`,
 * `> d-text .text-block-link`, `&::before`, `a:hover`), its last
 * compound's kind and name (a box's: the PSEUDO kind and the pseudo
 * name; a state's: STATE and the condition name), and the compound's
 * human label (a type's bundle label, a tag's declared label, a part's
 * label, a condition's label; null for `*`, a position and a box, whose
 * words the view reads). The editor filters by the typed text; the
 * offer is the whole set for the position. Rows already materialized
 * from the map are the store projection and never pass through here.
 *
 * Unknown facts degrade to offering, never to hiding: with no tree in
 * reach (the satellite before its broadcast) every declared type and
 * base tag is offered; only the tree's positive absence hides a type or
 * a tag. Selectors only the server can judge (an undeclared tag on a
 * strict site) are its refusal on the first commit, as they are for the
 * create entry.
 *
 * Inside a rule island the caller hands over the line nested under the
 * rule (nestUnder: `&::` in the `p` island arrives as `p::`, `> ` as
 * `p > `), so the position read here is the rule's and the offer is the
 * rule's matches' children, parts and boxes with no enumeration of its
 * own; the options mint the composed selector (`p::before`, `p > *`).
 *
 * @param {string} typed
 *   The line's text before the cursor, as typed ('> d-', '.qu', 'p').
 * @param {object} context
 *   - key: the owner's selectable key.
 *   - document: the document tree (source.getDocument(): the host
 *     entity above its sections, so the page itself is an owner whose
 *     descendants are everything on it), or null when unknown.
 *   - map: the host's style map (the matcher's wear: a worn variant's
 *     class is a document fact), or null.
 *   - parts: the owner's declared parts (partsForKey()).
 *   - partsFor: (type) => a type compound's declared parts, same shape.
 *   - presence: selector → bool DOM-presence facts for the owner's
 *     declared parts, or null when unknown.
 *   - closed: whether the guide's world is closed (propertiesClosed()).
 *   - tags: the declared base tag vocabulary ({tag: label}).
 *   - present: the owner's `_element.markup.tags` fact, or null.
 *   - types: the declared type compounds ({'d-text': 'Text', …}, the
 *     kinds included): the label table, and the universe when the
 *     document is unknown.
 *   - conditions: the published condition table (guide.conditions,
 *     name => {label, wrapper, axis}): the `:` lane's states.
 *
 * @return {?{options: Array<{selector?: string, insert?: string,
 *   kind: string, name: string, label: ?string}>, create: ?string}}
 *   The offer, or null when the typed text is not a selector prefix.
 */
export const selectorGestureOptions = (typed, context = {}) => {
  const split = splitTyped(typed);
  if (split === null) {
    return null;
  }
  const { prefix, child, last, landing, box, lane, partsOwner } = split;
  const {
    key = null,
    document = null,
    map = null,
    parts = [],
    partsFor = null,
    presence = null,
    closed = false,
    tags = {},
    present = null,
    types = {},
    conditions = {},
  } = context;
  const mint = (name) =>
    canonicalSelector([prefix, child ? '>' : '', name].filter(Boolean).join(' '));
  const options = [];
  const seen = new Set();
  const offer = (kind, name, label, selector = mint(name)) => {
    if (selector === null || seen.has(selector)) {
      return;
    }
    seen.add(selector);
    options.push({ selector, kind, name, label: label ?? null });
  };
  const vocabulary = Array.isArray(tags) ? {} : (tags ?? {});
  const typeTable = types ?? {};
  // The anchor is the owner: a qualified anchor landing reads as the
  // owner's own position.
  const atOwner = landing === null || landing.kind === ANCHOR_KIND;
  const subject = prefix || ANCHOR;

  if (box) {
    // The subject's pseudo-elements, the anchor spelling the owner's own:
    // the two generated boxes first, then the ones with a vocabulary of
    // their own, then every other the browser knows (the wide tier; the
    // completion filters by the typed tail). Whether this site admits one
    // is the validator's verdict at the write.
    [...GENERATED_BOXES, ...vocabularyPseudos(), ...widePseudoElements()].forEach((pseudo) => {
      offer(PSEUDO, pseudo, null, canonicalSelector(`${subject}${SUFFIX}${pseudo}`));
    });
    return { options, create: null };
  }

  if (lane === 'pseudo') {
    const table = conditions !== null && typeof conditions === 'object' ? conditions : {};
    Object.entries(table).forEach(([name, definition]) => {
      const wrapper = definition?.wrapper;
      if (typeof wrapper === 'string' && /^:[A-Za-z-]+$/.test(wrapper)) {
        offer(STATE, name, definition?.label ?? name, canonicalSelector(`${subject}${wrapper}`));
      }
    });
    POSITIONS.forEach((position) => {
      offer(POSITION, position, null, canonicalSelector(`${subject}:${position}`));
    });
    [...NTH_POSITIONS, NOT, HAS, IS].forEach((name) => {
      options.push({ insert: `${subject}:${name}(`, kind: QUALIFIER, name, label: null });
    });
    return { options, create: null };
  }

  if (lane === 'class' || lane === 'tag-class') {
    const ownerParts = (Array.isArray(parts) ? parts : []).filter(
      (part) => presence?.[part.id] !== false,
    );
    const typeParts = (type) =>
      typeof partsFor === 'function' ? (partsFor(type) ?? []) : [];
    // A class by descent is a part of the owner or of the type before
    // it; glued to a tag it is a markup class, and the classes the
    // completion can know are the declared parts of the owner the tag
    // stands under (the grammar admits any class there).
    const glued = lane === 'tag-class';
    const declared = atOwner
      ? ownerParts
      : landing.kind === TYPE
        ? typeParts(landing.name)
        : glued
          ? partsOwner === null
            ? ownerParts
            : typeParts(partsOwner)
          : [];
    declared.forEach((part) =>
      offer(CLASS_PART, part.id, part.label, glued ? canonicalSelector(`${prefix}${part.id}`) : mint(part.id)),
    );
    const wellFormed = CLASS_PATTERN.test(last);
    const isDeclared =
      atOwner
        ? (Array.isArray(parts) ? parts : []).some((part) => part.id === last)
        : declared.some((part) => part.id === last);
    const create =
      !closed && wellFormed && !isDeclared && (atOwner || landing.kind === TYPE)
        ? mint(last)
        : null;
    return { options, create };
  }

  if (child) {
    offer(UNIVERSAL, '*', null);
  }
  const matcher = document === null || typeof key !== 'string' ? null : createMatcher(document, map);
  if (matcher === null) {
    Object.entries(typeTable).forEach(([name, label]) => offer(TYPE, name, label));
    Object.entries(vocabulary).forEach(([tag, label]) => offer(TAG, tag, label));
  } else {
    // Descendants are the two kind families' matches merged back into
    // document order (the document walk), the owner itself never among
    // them, nor the entity root (nothing targets it from an owner).
    const nodes = child
      ? matcher.matching(key, prefix ? `${prefix} > *` : '> *')
      : documentOrder(document).filter(
          (node) =>
            node !== key &&
            (matcher.matches(key, prefix ? `${prefix} ${TYPE_BLOCK}` : TYPE_BLOCK, node) ||
              matcher.matches(key, prefix ? `${prefix} ${TYPE_CONTAINER}` : TYPE_CONTAINER, node)),
        );
    // The set's types and tags (the matcher's presentRoster, the one
    // "what is here" derivation): root tags only after `>`, the tags
    // inside (the prose's and the parts') at a descendant position.
    const { types: typeNames, tags: tagNames } = presentRoster(
      nodes.map((node) => matcher.facts(node)),
      { inner: !child },
    );
    if (!child && prefix === '') {
      (Array.isArray(present) ? present : Object.keys(vocabulary)).forEach((tag) => {
        if (tag && !tagNames.includes(tag)) {
          tagNames.push(tag);
        }
      });
    }
    typeNames.forEach((name) => offer(TYPE, name, typeTable[name] ?? name));
    Object.keys(vocabulary)
      .filter((tag) => tagNames.includes(tag))
      .forEach((tag) => offer(TAG, tag, vocabulary[tag]));
  }
  if (!child && landing?.kind === TYPE && typeof partsFor === 'function') {
    (partsFor(landing.name) ?? []).forEach((part) =>
      offer(CLASS_PART, part.id, part.label),
    );
  }
  return { options, create: null };
};

/**
 * Nests a selector line typed inside a rule island under the rule: CSS
 * nesting's relative rule, the substitution DialectCssParser::parseRule
 * makes before parsing a nested prelude. `&` is the rule's selector
 * (`&::before` under `p` is `p::before`, `& > a` is `p > a`, `&:hover`
 * is `p:hover`); a line starting with `:` is `&:` sugar (`:hover` under
 * `p` is `p:hover`, `::before` is `p::before`); any other line is
 * relative to the rule (`> a` is `p > a`, `.x` is `p .x`); an `@`
 * prelude names a condition and nests under nothing. On the owner's
 * own island there is no rule and the line is as typed: the digester
 * reads the anchor and the leading colon as the owner's own.
 *
 * Raw text, not a selector: the completion feeds a partial line through
 * here (`&::`, `> d-`) and reads the offer off the composed position,
 * so no grammar judges at this step; composeUnder() is the judged form.
 * One shape is refused before any substitution: the anchor glued to a
 * word (`&hover`, `&narrow`) is the condition sigil's line, never a
 * selector, and substituting the rule into it would spell a tag
 * (`phover`) the grammar admits.
 *
 * @param {?string} rule
 *   The island's rule selector (splitKey's `selector`), null or '' on
 *   the owner's own island.
 * @param {string} typed
 *   The line's text, as typed.
 *
 * @return {?string}
 *   The nested text, or null when `typed` is not a string or is the
 *   condition sigil's shape.
 */
export const nestUnder = (rule, typed) => {
  if (typeof typed !== 'string' || SIGIL_LINE.test(typed)) {
    return null;
  }
  if (typeof rule !== 'string' || rule === '') {
    return typed;
  }
  const text = typed.trimStart();
  if (text.startsWith('@')) {
    return typed;
  }
  if (text.startsWith(':')) {
    return `${rule}${text}`;
  }
  return typed.includes(ANCHOR) ? typed.replaceAll(ANCHOR, rule) : `${rule} ${text}`;
};

/**
 * Composes a selector typed inside a rule island under the rule and
 * digests it: nestUnder() then the digester's reading (selector-digest.js
 * digest: the grammar's judgement, the peel of a state on the last
 * compound into the row's condition, a list folded into one `:is()`
 * rule), so the result is what the island's gesture mints: `&::before`
 * under `p` mints `p::before`, `&::after` under `> d-text .link` mints
 * `> d-text .link::after`, `a:hover` on the owner's island mints the
 * `a` row under hover, `&:hover` (or `:hover`) mints the island's own
 * hover row, `h1, h2` mints the one `*:is(h1, h2)` row (reported
 * `folded`, so the host can say so once), and a published `@wrapper`
 * prelude the island's row under that condition. The digester's
 * refusals are the owner's (a partial tail, a box on a box, the sigil's
 * shape, a list whose members disagree or share no shape), and the
 * island's own row with no condition
 * is nothing to mint: `&` alone on the owner's island, or `&` inside a
 * rule island (it composes to the rule itself, the row the island
 * already is).
 *
 * @param {?string} rule
 *   The island's rule selector, null or '' on the owner's own island.
 * @param {string} typed
 *   The line's text, as typed.
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   The digester's options (the guide's condition table).
 *
 * @return {?{selector: string, condition: ?string, folded: boolean}}
 *   The one canonical selector to mint ('' the owner itself, the rule's
 *   own selector the island's own row; a typed list folded into its
 *   `:is()` rule) under its condition (null when none) and whether a
 *   list was folded, or null when the digester refuses the text or it
 *   names the island's own row with no condition.
 */
export const composeUnder = (rule, typed, options = {}) => {
  const text = nestUnder(rule, typed);
  if (text === null || text.trim() === '') {
    return null;
  }
  const answer = digest(text.trim(), options);
  if (answer.refusal !== null) {
    return null;
  }
  const self = typeof rule === 'string' && rule !== '' ? rule : OWNER;
  // A wrapper alone is the island's own row under the condition.
  const selector = answer.selector ?? self;
  if (selector === self && answer.condition === null) {
    return null;
  }
  return { selector, condition: answer.condition, folded: answer.folded };
};

/**
 * The nested spelling of a composed selector, for the completion's label
 * inside a rule island: the typed line's reading of the option it mints
 * (`p::before` under `p` reads `&::before` on an anchored line, `p > a`
 * reads `> a` on a bare one), so the popup spells what the user is
 * typing while each option mints the composed canonical selector, the
 * labelling trick the `&` sigil's boxes use. The composed selector
 * starts with the rule by construction (nestUnder() put it there and the
 * canonical print of a canonical prefix is itself), so the spelling is
 * the remainder behind it. On the owner's own island (no rule) a
 * selector is its own spelling.
 *
 * @param {?string} rule
 *   The island's rule selector, null or '' on the owner's own island.
 * @param {string} typed
 *   The line's text, as typed (whether it carries the anchor decides the
 *   spelling's head).
 * @param {string} composed
 *   A composed canonical selector under the rule.
 *
 * @return {string}
 *   The nested spelling.
 */
export const nestedSpelling = (rule, typed, composed) => {
  if (typeof rule !== 'string' || rule === '') {
    return composed;
  }
  const rest = composed.slice(rule.length);
  return typed.includes(ANCHOR) ? `${ANCHOR}${rest}` : rest.trimStart();
};

/**
 * What a typed `{` mints, or null when the brace is ordinary text.
 *
 * Typing `p {` (or `> d-text {`, `.quote {`, `> p + p {`,
 * `> d-text:has(a) {`, `&::before {`, `li:hover > ul {`, `a:hover {`,
 * `h1, h2 {`) at a declaration position is the materialize gesture
 * without the pick: the brace lands only when the digester reads the
 * line before it (composeUnder: one grammar, the brace its gate) and
 * nothing follows the cursor; the caller swallows the typed selector
 * with the brace and mints the rows, canonical (the grammar's
 * spelling), under the condition the digester peeled (`a:hover {` is the
 * `a` row's hover island; `&:hover {` the island's own, the same row the
 * `&hover` sigil's pick mints). A declaration (`color: red`), a partial
 * token (`> d-`, `&::`), a dangling combinator or the sigil's shape is
 * not a selector, and a brace there stays text for the lint to name. A
 * minted box row is empty chrome until its first write, which the op
 * spine births with `content: ""`; the row then prints that line.
 *
 * @param {string} before
 *   The line's text before the cursor.
 * @param {string} after
 *   The line's text after the cursor.
 * @param {?string} rule
 *   The island's rule selector, null or '' on the owner's own island.
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   The digester's options (the guide's condition table).
 *
 * @return {?{selector: string, condition: ?string, folded: boolean}}
 *   The canonical selector to mint under its condition, as
 *   composeUnder() answers.
 */
export const bracedSelector = (before, after, rule = null, options = {}) => {
  if (typeof before !== 'string' || typeof after !== 'string') {
    return null;
  }
  if (after.trim() !== '') {
    return null;
  }
  const text = before.trim();
  if (text === '') {
    return null;
  }
  return composeUnder(rule, text, options);
};

/**
 * The mint line's reach preview: what the canvas paints while a selector
 * is being typed, before any brace or pick.
 *
 * As each token settles the line is read exactly as the brace would read
 * it (nestUnder under the island's rule, then the digester), and the
 * answer is the one selector's structural REACH (selector-digest.js
 * reach: the posable state qualifiers stripped, since `li:hover > ul`
 * matches nothing in the DOM until hovered, while positions, classes and
 * attributes stay because the browser evaluates them live), canonical
 * and ready for the hover-selector facet (`li:hover > ul` previews
 * `li > ul`, `> d-text:hover a` previews `> d-text a`, `a:hover` previews
 * the `a` row's reach `a`). Null while the text does not digest (a
 * partial token, a dangling combinator, a declaration, the sigil's
 * shape, a brace or semicolon on the line, an empty line), and for what
 * the facet cannot paint as one selector: the owner itself (`&`,
 * `&:hover`; the selected element already shows), a wrapper alone (a
 * condition, no selector) and a reach with nothing addressable left. A
 * typed list previews the reach of the one `:is()` rule it folds into
 * (`h1, h2` previews `*:is(h1, h2)`). The caller hands a non-null
 * answer to the facet on the island's owner and clears it on null; this
 * is pure.
 *
 * @param {string} typed
 *   The line's text, as typed.
 * @param {{rule: ?string, conditions: ?Object, posable: ?string[]}} options
 *   The island's rule selector (null or '' on the owner's own island)
 *   and the digester's options (the guide's condition table).
 *
 * @return {?string}
 *   The canonical reach to paint, or null.
 */
export const previewReach = (typed, options = {}) => {
  if (typeof typed !== 'string' || /[{};]/.test(typed)) {
    return null;
  }
  const { rule = null, ...digestOptions } = options;
  const text = nestUnder(rule, typed);
  if (text === null || text.trim() === '') {
    return null;
  }
  const answer = digest(text.trim(), digestOptions);
  if (answer.refusal !== null || answer.selector === null) {
    return null;
  }
  const reached = reach(answer.selector, digestOptions);
  return reached === null || reached === OWNER ? null : reached;
};

/**
 * The unset ops that remove one island's OWN styles (the removal gesture).
 *
 * Removing a condition row (or a part row, per condition) unsets every own
 * value under the (key, condition) pair in ONE multi-property op — one
 * gesture, one history entry. Variant-supplied values are untouched by
 * construction: only the own layer holds anything to unset, which is
 * exactly the variant-restore honesty — after removal the variant's
 * values resurface (restoredLines() reports them), and the row honestly
 * stays until the variant is detached.
 *
 * @return {Array<object>}
 *   Zero or one update_style op ([] when the own layer holds nothing —
 *   removing a draft row is pure view state).
 */
export const conditionRemovalOps = (map, key, condition) => {
  const own = map?.[key]?.[condition];
  if (!isEntryObject(own)) {
    return [];
  }
  const properties = {};
  Object.entries(own).forEach(([property, value]) => {
    if (value !== null && value !== undefined) {
      properties[property] = null;
    }
  });
  if (!Object.keys(properties).length) {
    return [];
  }
  return [
    {
      op: 'update_style',
      key,
      ...(condition !== 'default' ? { condition } : {}),
      properties,
    },
  ];
};

/**
 * The unset ops that remove a whole part row: every own condition layer.
 * Same honesty as conditionRemovalOps(): variant-supplied part values
 * survive (only detach removes a variant's delivery), so a
 * variant-styled part's row remains, showing its variant lines.
 *
 * @return {Array<object>}
 *   update_style ops, [] when the part has no own entry (a draft row).
 */
export const partRemovalOps = (map, key) => {
  const entry = map?.[key];
  if (!isEntryObject(entry)) {
    return [];
  }
  const ops = [];
  Object.keys(entry).forEach((condition) => {
    if (condition === 'variants') {
      return;
    }
    ops.push(...conditionRemovalOps(map, key, condition));
  });
  return ops;
};

/**
 * Whether an object carries a key of its own (a plan's moves map is a
 * plain object keyed by selectors, read without the prototype's words).
 */
const owns = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

/**
 * The relative keys a rule move on one owner is planned over: the
 * owner's stored keys (every map entry under `{owner}::`) and the drafted
 * rows standing on it (a drafted part selector when the owner is the
 * drafts' element, a drafted condition's row key). A draft is a row the
 * designer can see, so a landing on one refuses like a landing on a
 * stored key (the plan's collision rule); a draft keyed to the moved
 * rule itself is a source the rename carries along.
 */
const ruleMoveKeys = (map, drafts, owner) => {
  const prefix = `${owner}${SUFFIX}`;
  const relatives = [];
  const add = (relative) => {
    if (!relatives.includes(relative)) {
      relatives.push(relative);
    }
  };
  const addKey = (key) => {
    if (key.startsWith(prefix)) {
      add(key.slice(prefix.length));
    }
  };
  Object.keys(map ?? {}).forEach(addKey);
  if (drafts?.for === owner) {
    (drafts.parts ?? []).forEach(add);
  }
  Object.keys(drafts?.conditions ?? {}).forEach(addKey);
  return relatives;
};

/**
 * Plans a rule row's selector change in the element view: the one
 * transformation (rule-move.js planRuleMove, the family following) over
 * the owner's stored and drafted keys, and what the view sends for it.
 *
 * A row with something stored under the move (an entry at the rule's
 * key or at any key continuing it, worn variants included: the op moves
 * entries whole) sends ONE `move_style {key, to}` through the spine,
 * where the handler plans the same family over the staged map and
 * StyleMapValidator judges the landing at the one door. A row with
 * nothing stored (a draft minted and never written, a seated rule the
 * map lacks) sends nothing: its rename is the view's alone
 * (renameDrafts, the spine's re-seat), and a row that is neither (a
 * variant-supplied row) is the view's to refuse honestly, the × rule.
 *
 * @param {object} map
 *   The style map.
 * @param {?{for: string, conditions: object, parts: string[]}} drafts
 *   The view's drafts (`for` the element the part drafts belong to).
 * @param {string} owner
 *   The rule's owner key (the selection for a part row, the ancestor for
 *   a rule row).
 * @param {string} from
 *   The row's selector.
 * @param {string} to
 *   The selector it becomes (the editor's canonical `to`).
 *
 * @return {{refusal: ?{reason: string, key: string},
 *   moves: ?Object<string, string>, operations: Array<object>}}
 *   The plan's refusal (rule-move.js REFUSED_*; `moves` null, no ops),
 *   or the family's key mapping (relative to relative, the rule's own
 *   first) and zero or one move_style op.
 */
export const ruleMovePlan = (map, drafts, owner, from, to) => {
  const plan = planRuleMove(ruleMoveKeys(map, drafts, owner), from, to);
  if (plan.refusal !== null) {
    return { refusal: plan.refusal, moves: null, operations: [] };
  }
  const rule = canonicalSelector(from);
  const stored = Object.keys(plan.moves).some((relative) =>
    isEntryObject(map?.[`${owner}${SUFFIX}${relative}`]),
  );
  return {
    refusal: null,
    moves: plan.moves,
    operations: stored
      ? [{ op: 'move_style', key: `${owner}${SUFFIX}${rule}`, to: plan.moves[rule] }]
      : [],
  };
};

/**
 * Renames the drafts a rule move carries: a drafted part selector the
 * move maps (when the owner is the drafts' element), a drafted
 * condition's row key under the owner, and the focus standing on one of
 * them, each to its landing. Drafts under other owners and selectors the
 * move leaves alone stay as they were. Pure: the view sets the result as
 * its gesture state.
 *
 * @param {{for: string, conditions: object, parts: string[], focus: ?string}} drafts
 *   The view's drafts.
 * @param {string} owner
 *   The moved rule's owner key.
 * @param {Object<string, string>} moves
 *   ruleMovePlan()'s moves (relative source to relative landing).
 *
 * @return {{for: string, conditions: object, parts: string[], focus: ?string}}
 */
export const renameDrafts = (drafts, owner, moves) => {
  const prefix = `${owner}${SUFFIX}`;
  const rekey = (key) => {
    if (typeof key !== 'string' || !key.startsWith(prefix)) {
      return key;
    }
    const relative = key.slice(prefix.length);
    return owns(moves, relative) ? `${prefix}${moves[relative]}` : key;
  };
  const conditions = Object.fromEntries(
    Object.entries(drafts.conditions ?? {}).map(([key, names]) => [rekey(key), names]),
  );
  const parts =
    drafts.for === owner
      ? (drafts.parts ?? []).map((selector) =>
          owns(moves, selector) ? moves[selector] : selector,
        )
      : drafts.parts;
  let { focus } = drafts;
  if (typeof focus === 'string') {
    const at = focus.lastIndexOf('|');
    focus = at === -1 ? rekey(focus) : `${rekey(focus.slice(0, at))}${focus.slice(at)}`;
  }
  return { ...drafts, conditions, parts, focus };
};

/**
 * The lines that will SURVIVE removing an island's own styles: what worn
 * variants deliver at the (key, condition) pair — every variant-layer line
 * of the layered document, shadowed ones included (they resurface once
 * their override unsets). The view uses this for variant-restore honesty:
 * a non-empty result means the removal gesture cannot empty the row, only
 * strip the element's own layer.
 *
 * @return {Array<object>}
 *   The variant-layer subset of stackLines() over the key's stack built
 *   without the floor (the floor is not removal's concern), same line
 *   shapes.
 */
export const restoredLines = (map, key, condition, variants, axes = null) =>
  stackLines(
    buildStack({ map, key, variants, axes, floor: false }),
    condition,
  ).filter((line) => line.layer !== ELEMENT_LAYER);

/**
 * Should the buffer re-print to restore deleted variant-supplied lines?
 *
 * Deleting a variant's line honestly no-ops — the variant keeps
 * delivering — but a no-op commits nothing, so no authoritative refresh
 * ever brings the line back while the island stays mounted. The view
 * re-prints the baseline at settle boundaries instead, gated here: some
 * variant-supplied line must be genuinely gone (its exact property+value
 * text absent from the buffer; a held property is a half-retyped line,
 * not a deletion), and the buffer must hold no other work — no held
 * tokens, no uncommitted diffs (islandOps covers changed values and
 * own-line deletions alike). Formatting-only differences never trigger a
 * re-print on their own. Own lines claim their buffer copies first so an
 * equal-valued variant duplicate cannot mask an own deletion.
 *
 * @param {string} key
 *   The island's full style-map key.
 * @param {string} condition
 *   The island's condition.
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the buffer.
 * @param {Array<{property: string, value: string, layer: string}>} lines
 *   The island's baseline lines (stackLines()).
 *
 * @return {boolean}
 *   TRUE when re-printing the baseline restores variant lines and loses
 *   nothing.
 */
export const shouldRestoreTinted = (key, condition, analysis, lines) => {
  if (analysis.held.size || islandOps(key, condition, analysis, lines).length) {
    return false;
  }
  const counts = new Map();
  analysis.declarations.forEach((declaration) => {
    const token = `${declaration.property}\0${declaration.value}`;
    counts.set(token, (counts.get(token) ?? 0) + 1);
  });
  let missing = false;
  const claim = (line) => {
    const token = `${line.property}\0${line.value}`;
    const count = counts.get(token) ?? 0;
    if (count > 0) {
      counts.set(token, count - 1);
    } else if (line.layer !== ELEMENT_LAYER) {
      missing = true;
    }
  };
  lines.filter((line) => line.layer === ELEMENT_LAYER).forEach(claim);
  lines.filter((line) => line.layer !== ELEMENT_LAYER).forEach(claim);
  return missing;
};

/**
 * The cadence filter: which of an island's diffed ops have SETTLED.
 *
 * Per-declaration commit without blur: a set commits only when its
 * declaration is terminated (';' after the value — the settle boundary)
 * and, unless `includeActive`, not on the line the cursor is on (typing
 * "1rem" toward "1rem 2rem" must not commit each intermediate value; the
 * declaration settles when the cursor leaves it, a ';' lands, or the
 * island blurs). Deletion = unset on the boundary: unset ops pass
 * unconditionally — islandOps' held-set already protects half-retyped
 * lines, and a whole-line deletion IS the settle gesture. Invalid
 * declarations were never in the ops (they hold only themselves).
 *
 * @param {string} key
 *   The island's full style-map key.
 * @param {string} condition
 *   The island's condition.
 * @param {string} text
 *   The island's buffer text.
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the buffer.
 * @param {Array<{property: string, value: string, variant: ?string}>} lines
 *   The island's baseline lines (stackLines()).
 * @param {object} options
 *   - cursor: the cursor offset in the buffer, or null (no active line).
 *   - includeActive: commit the cursor's declaration too (';' just typed,
 *     or the island blurred).
 *
 * @return {Array<object>}
 *   The settled subset of islandOps(), same op shapes.
 */
export const settleOps = (
  key,
  condition,
  text,
  analysis,
  lines,
  { cursor = null, includeActive = false } = {},
) => {
  const lineIndexOf = (offset) => {
    let line = 0;
    const end = Math.min(offset, text.length);
    for (let i = 0; i < end; i += 1) {
      if (text[i] === '\n') {
        line += 1;
      }
    }
    return line;
  };
  const terminatorOf = (declaration) => {
    let i = declaration.to;
    while (i < text.length && /\s/.test(text[i])) {
      i += 1;
    }
    return text[i] === ';' ? i : null;
  };
  const cursorLine = cursor === null ? null : lineIndexOf(cursor);
  const lastValidDeclaration = (property) => {
    for (let i = analysis.declarations.length - 1; i >= 0; i -= 1) {
      const declaration = analysis.declarations[i];
      if (declaration.property === property && declaration.valid) {
        return declaration;
      }
    }
    return null;
  };

  return islandOps(key, condition, analysis, lines).filter((op) => {
    const [property, value] = Object.entries(op.properties)[0];
    if (value === null) {
      return true;
    }
    const declaration = lastValidDeclaration(property);
    if (!declaration) {
      return false;
    }
    const terminator = terminatorOf(declaration);
    if (terminator === null) {
      return false;
    }
    if (
      !includeActive &&
      cursorLine !== null &&
      lineIndexOf(declaration.from) <= cursorLine &&
      cursorLine <= lineIndexOf(terminator)
    ) {
      return false;
    }
    return true;
  });
};
