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
import {
  ANCHOR,
  CLASS_PART,
  CLASS_PATTERN,
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
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * The offer kind of a generated box option (`&::before`,
 * `> pb-text::after`): the pseudo-element is the selector's terminal, not
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
 * '::> pb-text' wearing a variant with a '::.link' entry delivers at
 * '::> pb-text .link'), then the selectors the element's own worn
 * references supply (each reference read through ITS OWN fragment, the
 * delivery read), then its own '::selector' map entries (a part
 * '::.class', the tag tier '::p', the children '::> *', a type
 * '::> pb-text', their compounds), in delivery order, deduplicated. The
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
 * @param {?Array} tree
 *   The blueprint's lean node tree, when the caller holds it and the
 *   roster is the selection's full body; null lists no ancestor rules.
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
  tree = null,
  address = null,
  facet = null,
) => {
  const conditions = mapConditions(map, key, variants, axes, guide);
  // An own-key address never reaches the stack: handed as `editable` it
  // would place a virtual ancestor layer for a rule this roster already
  // rows as a part (placeEditable's fallback seat), a second island for
  // one key.
  const addressedKey =
    tree === null || address === null || address.key === key
      ? null
      : facet === null
        ? key
        : `${key}::${facet}`;
  const ancestorsOf = (islandKey) =>
    tree === null
      ? []
      : ancestorIslands({
          guide,
          map,
          key: islandKey,
          variants,
          axes,
          blockTypes,
          sectionLayouts,
          tree,
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
 * tree: the ancestor tier lists every rule position the selector
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
 *   variants, axes, blockTypes, sectionLayouts, tree) plus `editable`,
 *   the write address to place when it names this key's facet, or null.
 *
 * @return {Array<{owner: string, selector: string, key: string,
 *   conditions: string[], existing: boolean}>}
 *   Nearest ancestor first; [] without a tree or when nothing reaches.
 */
export const ancestorIslands = ({
  guide,
  map,
  key,
  variants,
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  tree,
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
    tree,
    editable,
  });
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
        axisVocabulary(guide, group.owner, blockTypes, sectionLayouts, tree),
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
 * The selector charset a gesture line may hold: compounds, the `>`, `+`
 * and `~` combinators, the two qualifiers' parens, spaces, the owner's
 * anchor `&`, and a generated box's `::` with its name (or the start of
 * one) as the line's tail. A single colon opens a qualifier only
 * (`:has(`, `:not(`); any other colon, a semicolon or a brace on the
 * line is a declaration or structure, never a selector being typed. The
 * charset gates; the grammar (canonicalSelector) judges the rest, so
 * `&hover`, `::marker` and `&::before .x` pass here and fail there.
 */
const SELECTOR_LINE = /^(?:[A-Za-z0-9_.*>+~()& -]|::(?=[A-Za-z]*\s*$)|:(?=has\(|not\())*$/;

/**
 * A line typing the owner's own generated box: the anchor alone, or the
 * anchor with its `::` tail (`&`, `&::`, `&::b`, `&::before`).
 */
const ANCHOR_LINE = /^\s*&(?:::[A-Za-z]*)?$/;

/**
 * A line's generated-box tail (`::`, `::b`, `::before`), glued to the
 * host before it.
 */
const BOX_TAIL = /::([A-Za-z]*)$/;

/**
 * The condition sigil's line: the anchor glued to a word (`&hover`,
 * `&first-child`), the condition completion's shape and never a
 * selector's.
 */
const SIGIL_LINE = /&[A-Za-z0-9_-]/;

/**
 * Whether a selector line holds a relation (a sibling combinator or a
 * qualifier): the completion offers nothing on one, a relation is typed
 * whole (the `{` mint reads it through the grammar).
 */
const RELATION_LINE = /[+~(]/;

/**
 * Every addressable key of a lean tree, document order.
 */
const documentOrder = (tree) => {
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
  walk(tree);
  return keys;
};

/**
 * Splits a typed selector line into its settled prefix and the token
 * being completed.
 *
 * @return {?{prefix: string, child: boolean, last: string,
 *   landing: ?object, box: boolean}}
 *   `prefix` is the canonical selector typed so far ('' at the owner),
 *   `child` whether a `>` follows it (the next compound is a direct
 *   child), `last` the partial token under the cursor ('' right after a
 *   combinator or a space), `landing` the prefix's last compound or
 *   null at the owner, `box` whether the token is a generated box's
 *   `::` tail (the owner's anchor `&` counts: its only continuations are
 *   its boxes). Null when the text is outside the grammar or holds a
 *   relation (no offer inside a qualifier or after a sibling
 *   combinator).
 */
const splitTyped = (typed) => {
  if (
    typeof typed !== 'string' ||
    !SELECTOR_LINE.test(typed) ||
    RELATION_LINE.test(typed)
  ) {
    return null;
  }
  if (typed.includes(ANCHOR)) {
    // The anchor is the whole head of the owner's own box and nothing
    // else (`& > p` and `&hover` are outside the grammar).
    return ANCHOR_LINE.test(typed)
      ? { prefix: '', child: false, last: typed.trim(), landing: null, box: true }
      : null;
  }
  const tail = BOX_TAIL.exec(typed);
  if (tail === null && typed.includes(SUFFIX)) {
    // A settled box (`> pb-text::before `) takes no continuation.
    return null;
  }
  if (tail !== null) {
    // A box continues a whole selector, glued (`> pb-text::b`); a space
    // before the `::`, a bare `::` and a box on a box are refused as the
    // grammar refuses them.
    const host = typed.slice(0, tail.index);
    const parsed = host === '' || /\s$/.test(host) ? null : parseSelector(host);
    if (parsed === null || parsed.pseudo !== null) {
      return null;
    }
    return {
      prefix: printSelector(parsed),
      child: false,
      last: tail[0],
      landing: parsed.compounds[parsed.compounds.length - 1],
      box: true,
    };
  }
  const text = typed.replace(/\s*>\s*/g, ' > ').trimStart();
  if (text.trim() === '') {
    return null;
  }
  const open = /\s$/.test(text);
  const tokens = text.trim().split(/\s+/);
  let last = open ? '' : tokens.pop();
  if (last === '>') {
    tokens.push('>');
    last = '';
  }
  const child = tokens[tokens.length - 1] === '>';
  const settled = child ? tokens.slice(0, -1) : tokens;
  if (settled.includes('>')) {
    // A combinator inside the prefix rides its compound; a dangling one
    // (`> > p`) is outside the grammar.
    const parsed = parseSelector(settled.join(' '));
    if (parsed === null) {
      return null;
    }
    return {
      prefix: printSelector(parsed),
      child,
      last,
      landing: parsed.compounds[parsed.compounds.length - 1],
      box: false,
    };
  }
  if (!settled.length) {
    return { prefix: '', child, last, landing: null, box: false };
  }
  const parsed = parseSelector(settled.join(' '));
  if (parsed === null) {
    return null;
  }
  return {
    prefix: printSelector(parsed),
    child,
    last,
    landing: parsed.compounds[parsed.compounds.length - 1],
    box: false,
  };
};

/**
 * The selector completion for a selector typed at a declaration position.
 *
 * One completion for every relative selector shape (Plan: the `>`, `.`,
 * `pb-` and tag-word gestures are one gesture): the typed line is a
 * selector prefix plus the token under the cursor, and the offer is
 * what that position can address, read off the ELEMENT (the selector
 * matcher over the blueprint tree, the same engine the stack and the
 * canvas paint read), never off the style map:
 * - right after `>`: the universal `*` (every child, present and
 *   future) first, then one type compound per type among the matched
 *   set's direct children (`pb-text`, `pb-container` for a nested
 *   section, `pb-block` for a plugin block), then one tag per declared
 *   base tag those children's roots render as;
 * - at a descendant position (the owner, or a space after a compound):
 *   one type per type in the matched set's subtree, one tag per
 *   declared base tag its subtree renders or contains (the owner's own
 *   prose tags included at the owner), and, after a type, that type's
 *   declared parts;
 * - a `.` token: the owner's declared parts (gated on DOM presence, a
 *   declared part absent from the canvas is not offered) or, after a
 *   type, the type's declared parts; on an open site a well-formed
 *   undeclared class additionally earns the explicit "Create this
 *   part" entry (`create`), and nothing else ever creates;
 * - a `::` tail, or the owner's anchor `&`: the two generated boxes of
 *   the subject before it, `::before` then `::after` (`&::before` and
 *   `&::after` at the owner, `> pb-text::before` after a type, a part's
 *   or a tag's likewise). A box is universal (every element has both),
 *   so nothing gates the offer here; a strict site's undeclared box is
 *   the server's refusal on the first write, as for an undeclared tag.
 *   The `::` must be glued to a whole selector (`> pb-text ::` offers
 *   nothing, as the grammar reads it as every descendant's box), and a
 *   box takes no continuation.
 * Each option carries the FULL canonical selector to mint (`> pb-text`,
 * `> pb-text .text-block-link`, `&::before`), its last compound's kind
 * and name (a box's: the PSEUDO kind and the pseudo name), and the
 * compound's human label (a type's bundle label, a tag's declared
 * label, a part's label; null for `*` and for a box, whose word is the
 * shared box vocabulary the view reads). The editor filters by the
 * typed text; the offer is the whole set for the position. Rows already
 * materialized from the map are the store projection and never pass
 * through here.
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
 *   The line's text before the cursor, as typed ('> pb-', '.qu', 'p').
 * @param {object} context
 *   - key: the owner's selectable key.
 *   - tree: the blueprint's lean node tree, or null when unknown.
 *   - parts: the owner's declared parts (partsForKey()).
 *   - partsFor: (type) => a type compound's declared parts, same shape.
 *   - presence: selector → bool DOM-presence facts for the owner's
 *     declared parts, or null when unknown.
 *   - closed: whether the guide's world is closed (propertiesClosed()).
 *   - tags: the declared base tag vocabulary ({tag: label}).
 *   - present: the owner's `_element.markup.tags` fact, or null.
 *   - types: the declared type compounds ({'pb-text': 'Text', …}, the
 *     kinds included): the label table, and the universe when the tree
 *     is unknown.
 *
 * @return {?{options: Array<{selector: string, kind: string, name: string,
 *   label: ?string}>, create: ?string}}
 *   The offer, or null when the typed text is not a selector prefix.
 */
export const selectorGestureOptions = (typed, context = {}) => {
  const split = splitTyped(typed);
  if (split === null) {
    return null;
  }
  const { prefix, child, last, landing, box } = split;
  const {
    key = null,
    tree = null,
    parts = [],
    partsFor = null,
    presence = null,
    closed = false,
    tags = {},
    present = null,
    types = {},
  } = context;
  const mint = (name) =>
    canonicalSelector([prefix, child ? '>' : '', name].filter(Boolean).join(' '));
  const options = [];
  const seen = new Set();
  const offer = (kind, name, label) => {
    const selector = mint(name);
    if (selector === null || seen.has(selector)) {
      return;
    }
    seen.add(selector);
    options.push({ selector, kind, name, label: label ?? null });
  };
  const vocabulary = Array.isArray(tags) ? {} : (tags ?? {});
  const typeTable = types ?? {};

  if (box) {
    // The subject's boxes, the anchor spelling the owner's own.
    PSEUDOS.forEach((pseudo) => {
      const selector = canonicalSelector(`${prefix || ANCHOR}${SUFFIX}${pseudo}`);
      if (selector !== null) {
        options.push({ selector, kind: PSEUDO, name: pseudo, label: null });
      }
    });
    return { options, create: null };
  }

  if (last.startsWith('.')) {
    const declared =
      landing === null
        ? (Array.isArray(parts) ? parts : []).filter(
            (part) => presence?.[part.id] !== false,
          )
        : landing.kind === TYPE && typeof partsFor === 'function'
          ? (partsFor(landing.name) ?? [])
          : [];
    declared.forEach((part) => offer(CLASS_PART, part.id, part.label));
    const wellFormed = CLASS_PATTERN.test(last);
    const isDeclared =
      landing === null
        ? (Array.isArray(parts) ? parts : []).some((part) => part.id === last)
        : declared.some((part) => part.id === last);
    const create =
      !closed && wellFormed && !isDeclared && (landing === null || landing.kind === TYPE)
        ? mint(last)
        : null;
    return { options, create };
  }

  if (child) {
    offer(UNIVERSAL, '*', null);
  }
  const matcher = tree === null || typeof key !== 'string' ? null : createMatcher(tree);
  if (matcher === null) {
    Object.entries(typeTable).forEach(([name, label]) => offer(TYPE, name, label));
    Object.entries(vocabulary).forEach(([tag, label]) => offer(TAG, tag, label));
  } else {
    // Descendants are the two kind families' matches merged back into
    // document order (the tree walk), the owner itself never among them.
    const nodes = child
      ? matcher.matching(key, prefix ? `${prefix} > *` : '> *')
      : documentOrder(tree).filter(
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
 * (`&::before` under `p` is `p::before`, `& > a` is `p > a`); a line
 * without it is relative to the rule (`> a` is `p > a`, `.x` is `p .x`).
 * On the owner's own island there is no rule and the line is as typed:
 * the anchor stays the owner's own box, judged as today.
 *
 * Raw text, not a selector: the completion feeds a partial line through
 * here (`&::`, `> pb-`) and reads the offer off the composed position,
 * so no grammar judges at this step; composeUnder() is the judged form.
 * One shape is refused before any substitution: the anchor glued to a
 * word (`&hover`, `&first-child`) is the condition sigil's line, never a
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
  return typed.includes(ANCHOR)
    ? typed.replaceAll(ANCHOR, rule)
    : `${rule} ${typed.trimStart()}`;
};

/**
 * Composes a selector typed inside a rule island under the rule and
 * canonicalizes it: nestUnder() then the grammar's judgement, so the
 * result is the selector the island's gesture mints (`&::before` under
 * `p` mints `p::before`, `&::after` under `> pb-text .link` mints
 * `> pb-text .link::after`). The grammar's refusals are the owner's (a
 * partial tail, a box on a box: a rule island that is itself a box
 * takes no continuation), and `&` alone is refused on every island: on
 * the owner's it is outside the grammar, inside a rule island it
 * composes to the rule itself, the row the island already is, so there
 * is nothing to mint.
 *
 * @param {?string} rule
 *   The island's rule selector, null or '' on the owner's own island.
 * @param {string} typed
 *   The line's text, as typed.
 *
 * @return {?string}
 *   The composed canonical selector, or null when the grammar refuses it
 *   or it names the rule itself.
 */
export const composeUnder = (rule, typed) => {
  const text = nestUnder(rule, typed);
  if (text === null) {
    return null;
  }
  const composed = canonicalSelector(text.trim());
  return composed === rule ? null : composed;
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
 * The selector a typed `{` mints, or null when the brace is ordinary text.
 *
 * Typing `p {` (or `> pb-text {`, `.quote {`, `> p + p {`,
 * `> pb-text:has(a) {`, `&::before {`, `.quote::after {`) at a
 * declaration position is the materialize gesture without the pick: the
 * brace lands only when the line before it is a whole selector in the
 * grammar and nothing follows the cursor; the caller swallows the typed
 * selector with the brace and mints the row, canonical (the grammar's
 * spelling). Inside a rule island the line composes under the rule
 * first (composeUnder: `&::before {` in the `p` island mints
 * `p::before`, `> a {` mints `p > a`). A line holding a colon outside
 * `:has(`, `:not(` or a terminal `::before` / `::after` is a
 * declaration, a partial token (`> pb-`, `&::`) or a dangling combinator
 * is not a selector, and a brace there stays text for the lint to name.
 * A minted box row is empty chrome until its first write, which the op
 * spine births with `content: ""`; the row then prints that line.
 *
 * @param {string} before
 *   The line's text before the cursor.
 * @param {string} after
 *   The line's text after the cursor.
 * @param {?string} rule
 *   The island's rule selector, null or '' on the owner's own island.
 *
 * @return {?string}
 *   The canonical selector to mint.
 */
export const bracedSelector = (before, after, rule = null) => {
  if (typeof before !== 'string' || typeof after !== 'string') {
    return null;
  }
  if (after.trim() !== '' || !SELECTOR_LINE.test(before)) {
    return null;
  }
  const text = before.trim();
  if (text === '') {
    return null;
  }
  return composeUnder(rule, text);
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
