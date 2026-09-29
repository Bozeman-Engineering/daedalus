/* eslint-disable max-classes-per-file */
// Two CodeMirror WidgetType subclasses (the winner chip and the pending
// line's chrome) render the decorations this view module owns.
import { h, Fragment } from 'preact';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import {
  EditorView,
  keymap,
  placeholder,
  tooltips,
  Decoration,
  WidgetType,
} from '@codemirror/view';
import { EditorState, StateEffect, StateField } from '@codemirror/state';
import { history, defaultKeymap, historyKeymap } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { forceLinting, linter } from '@codemirror/lint';
import {
  acceptCompletion,
  autocompletion,
  completionKeymap,
} from '@codemirror/autocomplete';
import {
  analyzeIsland,
  islandDelta,
  islandOps,
  matchLines,
  printIsland,
  stackLines,
  unseenOwnLines,
} from './island.js';
import { ELEMENT_LAYER, layerColor, variantLayerId } from './layers.js';
import { buildStack } from '../style-stack.js';
import {
  createStitching,
  nextTabTarget,
  previousTabTarget,
  tabStops,
} from './stitching.js';
import {
  PSEUDO,
  bracedSelector,
  conditionRemovalOps,
  draftRoster,
  elementIslands,
  occupiedRoster,
  ownIslandLines,
  partRemovalOps,
  posedRoster,
  restoredLines,
  ruleIslandLines,
  selectorGestureOptions,
  settleOps,
  shouldRestoreTinted,
} from './element-model.js';
import { Prelude } from './selector-chrome.js';
import {
  FRESH_LINE,
  createIslandSurface,
  pendingMark,
  runLabel,
  tooltipParent,
  winnerChip,
} from './island-surface.js';
import { lineMarks } from './line-marks.js';
import {
  doorRequest,
  documentSlot,
  entriesToOps,
  errorLines,
  lineCount,
  structuralChunks,
} from './paste-door.js';
import { notify } from '../notify.js';
import { lineMenuItems, runMenuItems } from './line-menu.js';
import {
  carriedBy,
  documentBaselineLines,
  documentLineWinner,
  documentTree,
  overlaySessionLines,
  wearerWinners,
} from './variant-document.js';
import { rowWords } from './page-model.js';
import { useMenu } from '../menu.js';
import { axisVocabulary, continuedKey, findVariant } from '../variant-scope.js';
import { styleTarget } from '../widgets/lib/use-highlight.js';
import {
  parseKey,
  bundleForKey,
  declarationForKey,
  resolvedConstraintsFor,
  declaredSets,
  definitionFor,
  baseTags,
  flattenElements,
  propertiesClosed,
  propertyGranted,
  tagWord,
} from '../style-guide.js';
import {
  TYPE_BLOCK,
  TYPE_CONTAINER,
  CLASS_PART,
  TAG,
  TYPE,
  UNIVERSAL,
  typeAddress,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import {
  animationOptions,
  scaleTokens,
  swatchOptions,
  tokenOptions,
} from '../materials.js';
import { KEYFRAMES_PROPERTIES } from '../keyframes-names.js';
import { imageSlot } from '../widget-values.js';
import { widePropertyNames, wideValueKeywords } from './wide-tier.js';
import { PART_SELECTOR, boxWord, selectorLabel, partsForKey, typeLabels } from '../parts.js';
import {
  conditionRenders,
  isFactCondition,
  satisfiedFacts,
} from '../editing-at.js';
import { createMatcher } from '../selector-matcher.js';
import {
  addressDeclaration,
  addressLabel,
  ownerName,
} from '../write-address.js';

/**
 * The per-element islands body (the page tree mounts one per element)
 * and the variant document view built on the same engine.
 *
 * Structure is chrome, never characters: the selected element renders as a
 * tree of Preact rows — its default declaration island, a condition row per
 * condition holding values, and a part row per styled sub-element — with
 * one island per (key, condition) pair holding a FLAT DECLARATION LIST
 * (element-model.js computes the roster; island.js is the
 * parse/validate/print/diff engine). Islands are printed markup by
 * default; exactly one, the one holding the cursor, is a live CodeMirror
 * instance (island-surface.js owns the swap), so the code view's cost is
 * bounded to one editor however big the page is.
 * Selectors, condition wrappers, and selector headers cannot be typed
 * here — they MATERIALIZE from typing instead: a relative selector typed
 * at a declaration position (`>`, `.`, `pb-`, a bare tag word, a chain
 * of them on one line) opens ONE selector completion built from the
 * element itself (element-model.js selectorGestureOptions over the
 * selector matcher: the children present, their types and tags, the
 * declared parts and base tags; open sites additionally offer an
 * explicit "Create this part" confirm for an undeclared class), and
 * picking an option creates its chrome row + island, exactly as typing
 * `{` after a whole selector does (the brace is swallowed with the
 * selector, bracedSelector); a bare word merges with the property
 * completions at the same anchor, since `p` is a paragraph rule and the
 * start of `padding` alike; `&`/`@` opens the condition vocabulary and
 * picking one creates its condition row + island. Materialized rows are
 * view-state drafts until their first declaration commits. The inverse gesture, the
 * row's × button, unsets the row's OWN styles and collapses it — with
 * variant-restore honesty: variant-supplied values survive (only detach
 * removes delivery), so a variant-backed row stays. At-rules
 * cannot live in an island either: a known condition steers to its `@`
 * gesture, anything else is an honest error. The canvas alone creates
 * keys.
 *
 * Cadence: preview per keystroke (per-property staging through the source,
 * so the satellite gets parity for free), commit per declaration when it
 * SETTLES — a ';' lands, the cursor leaves the declaration, or the island
 * blurs; deletions unset on the same boundary (element-model.js settleOps).
 * One op = one history entry; an invalid line blocks only itself, and
 * tempstore save/discard remains the durability gate.
 *
 * Layers render as one document with quiet per-line provenance: the buffer
 * is the layered document (every worn layer's declarations in delivery
 * order, the cascade's own last-wins reading); a variant-supplied line
 * wears its layer's identity hue as a passive wash, and hovering it names
 * the supplier. The one loud mark is the shadowing story: a lost line
 * strikes through in place with a small dot naming the winning layer in
 * its color (the display that replaced ghost rows). Editing the winning
 * value commits an override (references stay worn), deleting a variant's
 * line honestly no-ops and re-prints at the settle boundary, and deleting
 * the element's own winning line unsets the override so the variant
 * beneath resurfaces.
 *
 * The keyboard treats the islands as one surface (stitching.js): arrows
 * cross island boundaries when the cursor runs out of buffer, Tab walks
 * the DevTools chain — value, next declaration, a new declaration at the
 * island's end, then the next island (in the page tree: the next element,
 * layout order) — and Shift-Tab walks it backwards. Chrome rows never take
 * the cursor: every chrome button carries tabindex="-1" and the stitching
 * registry only holds islands. An island torn down focused (a re-render
 * replacing its row) parks its cursor in the registry and the remount
 * reclaims it.
 */

/**
 * The provenance decoration channel: recomputed sets replace wholesale.
 */
const setIslandDecorations = StateEffect.define();
const islandDecorationsField = StateField.define({
  create: () => Decoration.none,
  update: (decorations, transaction) => {
    let value = decorations.map(transaction.changes);
    for (const effect of transaction.effects) {
      if (effect.is(setIslandDecorations)) {
        value = effect.value;
      }
    }
    return value;
  },
  provide: (field) => EditorView.decorations.from(field),
});

/**
 * Guide-driven completion: the style guide IS the vocabulary.
 *
 * Property position (no ':' yet on the line) completes the declaration's
 * declared property groups' properties (every guide group on an undeclared
 * open-site target), plus the wide tier — every standard property from
 * csstree's embedded tables (the server recognizes them through the
 * generated groups), ranked below the guide's own (boost -1) since the
 * curated surface is the guidance. Value position completes the property's
 * closed value space — the declaration's "allowed" constraint plus the
 * definition's keywords (a scale constraint's closed space is its minted
 * tokens as var() references; a motion property's the roster's animations
 * as their motion-{name} spellings), or for a wide-tier property the
 * keywords of its grammar in csstree's tables. No closed values, no popup — with ONE
 * typed exception: a token trigger ("--" or "var(--" under the cursor)
 * opens the minted-token vocabulary, shape-filtered where the property's
 * definition maps cleanly onto scale shapes, each completion inserting the
 * full var() reference. Free-typed values stay the normal path, validated
 * at the :/; boundary like everywhere else. The wide tier participates
 * only where the lint accepts it (open sites, or a strict declaration that
 * declares a generated group), so completion never offers what the island
 * would flag.
 */

/**
 * The scale shapes whose tokens a property definition type can hold.
 *
 * Filtering is a courtesy where the mapping is clean (a color property
 * wants color tokens); definition types without a clean mapping (text,
 * family, any, keyword — and wide-tier properties, whose typed definitions
 * are server-only) offer the whole vocabulary.
 */
const TOKEN_SHAPES_FOR = {
  dimension: ['dimension', 'number'],
  number: ['number'],
  color: ['color'],
  // A keyframes name is no token's shape: the roster's animations are its
  // vocabulary (animationCompletions), offered as values, not on `--`.
  animation_name: [],
};

/**
 * The swatch kinds a property definition type's completions offer.
 *
 * The palette's swatch half of the mapping above: color swatches in color
 * slots and on rows without a clean mapping, no swatches on numeric rows
 * (no kind fits). Free <image> slots (widget-values.js imageSlot) bypass
 * this table: the value is free-typed grammar where a gradient swatch is
 * the whole layer and a color swatch is a legitimate stop, so both offer.
 * A keyword-typed media property (content) offers no swatch kind at all:
 * its grammar takes its keywords or one media token, and a swatch
 * reference is refused there, so the offer follows the type and the
 * flag adds only the picker.
 */
const SWATCH_KINDS_FOR = {
  color: ['color'],
  dimension: [],
  number: [],
  animation_name: [],
};

/**
 * The palette completions for one property: tokens and swatches, filtered.
 */
const tokenCompletions = (guide, materials, definition) => {
  const shapes = definition?.type
    ? (TOKEN_SHAPES_FOR[definition.type] ?? null)
    : null;
  const kinds = definition?.media
    ? imageSlot(definition)
      ? null
      : []
    : definition?.type
      ? (SWATCH_KINDS_FOR[definition.type] ?? ['color'])
      : ['color'];
  return [
    ...tokenOptions(guide, materials, shapes).map((token) => ({
      label: token.ref,
      type: 'constant',
      detail: token.value
        ? `${token.scaleLabel} · ${token.value}`
        : token.scaleLabel,
    })),
    ...swatchOptions(guide, materials, kinds).map((swatch) => ({
      label: swatch.ref,
      type: 'constant',
      detail: swatch.value
        ? `${swatch.kindLabel} · ${swatch.value}`
        : swatch.kindLabel,
    })),
  ];
};

/**
 * The roster's animations as value completions for a motion property:
 * the property-scoped slot rule as an offer. Only the two properties
 * whose values carry keyframes names (animation-name, and the animation
 * shorthand's name layer) offer them, each inserting the motion-{name}
 * spelling the value wears; the detail names the animation. A keyframes
 * name is an ident, not a var(), so these ride the value offer beside
 * the property's keywords (`none`), never the `--` token trigger.
 */
const animationCompletions = (materials, property) =>
  KEYFRAMES_PROPERTIES.includes(property)
    ? animationOptions(materials).map((animation) => ({
        label: animation.ref,
        type: 'constant',
        detail: animation.label,
      }))
    : [];
/**
 * The property and value completions over one served node: the granted
 * properties (every wide-tier name when the world is open), then a
 * property's keywords, constraints, scale tokens and animations, then
 * the `--` token trigger.
 *
 * Reads {guide, materials, declaration} through the ref (CM6 lives
 * outside preact's render loop). Exported for the keyframes editor's
 * islands (keyframe-island.js), which complete under the universal
 * vocabulary and want no gesture or tag offers.
 */
export const guideCompletions = (context, propsRef) => {
  const { guide, materials, declaration } = propsRef.current;
  const groups = guide?.groups ?? {};
  const wideOpen = !propertiesClosed(guide);
  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);
  const colon = before.indexOf(':');
  if (colon === -1) {
    // A property is one bare word: a line carrying a combinator, a dot
    // or a second token is a selector being typed (selectorCompletions).
    if (!/^[A-Za-z-]*$/.test(before.trim())) {
      return null;
    }
    const word = context.matchBefore(/[A-Za-z-]+$/);
    if (!word && !context.explicit) {
      return null;
    }
    const sets = declaredSets(declaration);
    const declared = sets.filter((id) => groups[id]);
    const ids = declared.length ? declared : Object.keys(groups);
    const options = [];
    const offered = new Set();
    ids.forEach((id) => {
      Object.entries(groups[id]?.properties ?? {}).forEach(
        ([property, definition]) => {
          // The roster's grant: a narrowed group offers only the names it
          // grants (offer curation in every enforcement mode, law on
          // strict, the same as the widgets). A node granting nothing
          // through the catalog offers the whole catalog, the open-world
          // posture of an undeclared place.
          if (declared.length && !propertyGranted(declaration, property)) {
            return;
          }
          offered.add(property);
          options.push({
            label: property,
            type: 'property',
            detail: definition?.label,
            apply: `${property}: `,
          });
        },
      );
    });
    // The wide tier: every name outside the catalog when the world is
    // open; on a closed site exactly the names the node's generated
    // groups grant (the roster serves them like a curated group's).
    const owned = new Set();
    Object.values(groups).forEach((group) => {
      Object.keys(group?.properties ?? {}).forEach((property) =>
        owned.add(property),
      );
    });
    const wide = wideOpen
      ? widePropertyNames()
      : sets
          .filter((id) => !groups[id])
          .flatMap((id) => declaration.grants[id]);
    wide.forEach((property) => {
      if (offered.has(property) || owned.has(property)) {
        return;
      }
      offered.add(property);
      options.push({
        label: property,
        type: 'property',
        apply: `${property}: `,
        boost: -1,
      });
    });
    if (!options.length) {
      return null;
    }
    return {
      from: word ? word.from : context.pos,
      options,
      validFor: /^[A-Za-z-]*$/,
    };
  }
  if (/;\s*$/.test(before)) {
    return null;
  }
  const property = before.slice(0, colon).trim();
  const definition = definitionFor(guide, property);
  // Completions are offers: the node's own constraint block, else the
  // site-wide constraint default (the offer vocabulary's lookup layer),
  // so a site-level scale binding completes its tokens everywhere.
  const constraints = resolvedConstraintsFor(guide, declaration, property);
  const allowed = constraints?.allowed;
  const keywords =
    definition !== null || !wideOpen
      ? definition?.keywords
      : wideValueKeywords(property);
  const values = [
    ...new Set([
      ...(Array.isArray(allowed) ? allowed : []),
      ...(Array.isArray(keywords) ? keywords : []),
    ]),
  ];
  const options = values.map((value) => ({ label: value, type: 'constant' }));
  if (typeof constraints?.scale === 'string') {
    // A scale constraint's closed space is its minted tokens as var()
    // references; the detail names the token and its display value.
    options.push(
      ...scaleTokens(guide, materials, constraints.scale).map((token) => ({
        label: token.ref,
        type: 'constant',
        detail: token.value ? `${token.name} · ${token.value}` : token.name,
      })),
    );
  }
  options.push(...animationCompletions(materials, property));
  if (options.length) {
    const word = context.matchBefore(/[^\s:;]*$/);
    return {
      from: word ? word.from : context.pos,
      options,
      validFor: /^[^\s:;]*$/,
    };
  }
  // The token trigger: typing "--" (or "var(--") asks for the palette
  // vocabulary — minted tokens and published swatches; the completion
  // inserts the full var() reference. Typed, so free values keep their
  // no-popup path. Underscores are in the charset: swatch machine names
  // carry them (--swatch-brand_red).
  const tokenWord = context.matchBefore(/(?:var\(\s*)?--[A-Za-z0-9_-]*$/);
  if (tokenWord) {
    const tokenChoices = tokenCompletions(guide, materials, definition);
    if (tokenChoices.length) {
      return {
        from: tokenWord.from,
        options: tokenChoices,
        validFor: /^(?:var\(\s*)?--[A-Za-z0-9_-]*$/,
      };
    }
  }
  return null;
};

/**
 * The declaration-position read every gesture shares: the line's text
 * before and after the cursor, or null when the cursor is not at a
 * declaration position (text follows it, or a colon precedes it). A
 * generated box's `::` tail (`&::`, `> pb-text::bef`) is the selector
 * gesture's own token, not a declaration's colon.
 */
const gestureLine = (context) => {
  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);
  const after = line.text.slice(context.pos - line.from);
  if (after.trim() !== '' || before.replace(/::[A-Za-z]*$/, '').includes(':')) {
    return null;
  }
  return { line, before, after };
};

/**
 * The sentence a selector completion's detail reads: what the rule
 * addresses, in the reader's words.
 */
const selectorDetail = (option) => {
  const lower = (label) =>
    typeof label === 'string' && label !== ''
      ? label.charAt(0).toLowerCase() + label.slice(1)
      : label;
  switch (option.kind) {
    case UNIVERSAL:
      return Drupal.t('Children');
    case TYPE:
      return option.name === TYPE_CONTAINER || option.name === TYPE_BLOCK
        ? Drupal.t('Every @label here', { '@label': lower(option.label ?? option.name) })
        : Drupal.t('Every @label block here', {
            '@label': lower(option.label ?? option.name),
          });
    case TAG:
      return Drupal.t('Every @label here', { '@label': lower(option.label ?? option.name) });
    case PSEUDO:
      return boxWord(option.name);
    default:
      return option.label ?? option.name;
  }
};

/**
 * Materialization-from-typing: the selector gesture.
 *
 * A selector typed alone on a line (nothing after the cursor, no colon
 * before it) opens the one selector completion: element-model.js
 * selectorGestureOptions reads the position (the owner, `>`, a chain's
 * landing) and the element (the matcher's actual children, the declared
 * parts and base tags) and offers full selectors; each option's label
 * is the selector it mints, its detail the human reading ("Children",
 * "Every text block here", "Every paragraph here", the part's label,
 * a generated box's "Before" / "After"). Picking one REMOVES the typed
 * text and materializes the chrome row + island instead of inserting
 * anything: structure never becomes characters. The offer re-queries
 * whenever the token under the cursor changes shape (a space, a `>`, a
 * `.`, a `::`), so a chain typed on one line completes compound by
 * compound and a subject continues into its boxes (`> pb-text::`; the
 * owner's own through the anchor, `&::`).
 */
const selectorCompletions = (context, propsRef) => {
  const { materialize } = propsRef.current;
  if (!materialize?.selectors) {
    return null;
  }
  const read = gestureLine(context);
  if (!read) {
    return null;
  }
  const match = /^(\s*)([A-Za-z0-9_.*>& -]*(?:::[A-Za-z]*)?)$/.exec(read.before);
  if (!match || match[2].trim() === '') {
    return null;
  }
  const typed = match[2];
  const offer = selectorGestureOptions(typed, materialize.selectors.context);
  if (!offer) {
    return null;
  }
  const tokenFrom = read.line.from + match[1].length;
  const pick = (selector) => (view, completion, from, to) => {
    view.dispatch({ changes: { from: tokenFrom, to } });
    materialize.selectors.onMaterialize(selector);
  };
  const typeOf = (kind) =>
    kind === UNIVERSAL || kind === PSEUDO ? 'keyword' : kind === TYPE ? 'type' : 'class';
  const completions = offer.options.map((option) => ({
    label: option.selector,
    type: typeOf(option.kind),
    detail: selectorDetail(option),
    apply: pick(option.selector),
  }));
  if (offer.create) {
    completions.push({
      label: offer.create,
      type: 'class',
      detail: Drupal.t('Create this part'),
      boost: -1,
      apply: pick(offer.create),
    });
  }
  if (!completions.length) {
    return null;
  }
  // The stem is the typed text up to the token under the cursor; the
  // popup stays valid while only that token grows, and a new compound
  // (a space, a combinator, a dot) asks the source again.
  const token = /[A-Za-z0-9_.*-]*$/.exec(typed)?.[0] ?? '';
  const stem = typed.slice(0, typed.length - token.length);
  return {
    from: tokenFrom,
    options: completions,
    validFor: (text) =>
      text.startsWith(stem) && /^[A-Za-z0-9_.*-]*$/.test(text.slice(stem.length)),
  };
};

/**
 * Materialization-from-typing: the `&` / `@` gestures.
 *
 * A condition sigil owns its line: `&` and `@` open the condition
 * vocabulary on any island, the structural facts grouped under Position.
 * On the element's own island the `&` is also the anchor of its
 * generated boxes, so that popup ends with `::before` and `::after`
 * under their own group (the selector gesture's offer for `&`, labelled
 * past the sigil so the filter reads the typed tail); typing the `::`
 * hands the line to the selector gesture. Picking an option REMOVES the
 * typed token and materializes the condition row (or the box row) + island
 * instead of inserting text.
 */
const conditionCompletions = (context, propsRef) => {
  const { guide, materialize } = propsRef.current;
  if (!materialize) {
    return null;
  }
  const read = gestureLine(context);
  if (!read) {
    return null;
  }
  const conditionMatch = /^(\s*)[&@]([A-Za-z0-9_-]*)$/.exec(read.before);
  if (!conditionMatch) {
    return null;
  }
  const tokenFrom = read.line.from + conditionMatch[1].length;
  const vocabulary = Object.entries(guide?.conditions ?? {});
  if (!vocabulary.length) {
    return null;
  }
  // Structural facts (first-child, empty, …) are offered under their
  // own Position group after the posable conditions: a fact is the
  // element's place in the markup, never a canvas pose, so the group
  // says what picking one means (a row that renders when the element
  // sits there).
  const position = { name: Drupal.t('Position'), rank: 1 };
  const box = { name: Drupal.t('Generated box'), rank: 2 };
  const boxes =
    read.before.trim().startsWith('&') && materialize.selectors
      ? (selectorGestureOptions('&', materialize.selectors.context)?.options ?? [])
      : [];
  return {
    // The filter range starts past the sigil so typed text matches
    // condition names.
    from: tokenFrom + 1,
    options: [
      ...vocabulary.map(([name, definition]) => ({
        label: name,
        type: 'keyword',
        detail: definition?.label,
        ...(isFactCondition(name, guide?.conditions) && { section: position }),
        apply: (view, completion, from, to) => {
          view.dispatch({ changes: { from: tokenFrom, to } });
          materialize.condition(name);
        },
      })),
      ...boxes.map((option) => ({
        label: option.selector.slice(1),
        type: 'keyword',
        detail: selectorDetail(option),
        section: box,
        apply: (view, completion, from, to) => {
          view.dispatch({ changes: { from: tokenFrom, to } });
          materialize.selectors.onMaterialize(option.selector);
        },
      })),
    ],
    validFor: /^[A-Za-z0-9_-]*$/,
  };
};

/**
 * The one completion source: the condition gesture, then the selector
 * gesture and the properties.
 *
 * The `&`/`@` sigils own their token shape and preempt. A bare word is
 * ambiguous — the start of a property OR a tag rule (`p`, a paragraph
 * rule, and the start of `padding`) — so the property completions and
 * the selector offer merge into one popup at the same anchor (the typed
 * word), letting the editor's own filtering rank them; an exact tag
 * match surfaces its row offer beside the properties. Any other
 * selector shape (`>`, `.`, `pb-`, a chain) is the selector gesture
 * alone, and the property offer stands down there (guideCompletions
 * reads only a bare word as a property).
 */
const completionSource = (context, propsRef) => {
  const condition = conditionCompletions(context, propsRef);
  if (condition) {
    return condition;
  }
  const selector = selectorCompletions(context, propsRef);
  const properties = guideCompletions(context, propsRef);
  if (selector && properties && selector.from === properties.from) {
    return {
      ...properties,
      options: [...properties.options, ...selector.options],
      validFor: /^[A-Za-z0-9-]*$/,
    };
  }
  return selector ?? properties;
};

/**
 * Resolves a stitch placement against an island's own buffer.
 *
 * Neighbors hand the cursor over with an intent, not a position — the
 * receiving island knows its own text: edges for horizontal arrival,
 * first/last line with the goal column for vertical arrival, first/last
 * tab stop for the Tab chain. Takes the state, not a view: a wake
 * resolves the placement against the freshly built state before any
 * EditorView exists. The fresh-line placement (FRESH_LINE) never
 * arrives here: the surface resolves it, since it edits the document.
 */
const placementAnchor = (state, placement) => {
  const doc = state.doc;
  switch (placement.edge) {
    case 'end':
      return doc.length;
    case 'firstLine': {
      const line = doc.line(1);
      return Math.min(line.from + (placement.column ?? 0), line.to);
    }
    case 'lastLine': {
      const line = doc.line(doc.lines);
      return Math.min(line.from + (placement.column ?? 0), line.to);
    }
    case 'firstStop': {
      const stops = tabStops(doc.toString());
      return stops.length ? stops[0].pos : 0;
    }
    case 'lastStop': {
      const stops = tabStops(doc.toString());
      return stops.length ? stops[stops.length - 1].pos : doc.length;
    }
    default:
      return 0;
  }
};

/**
 * The arrow half of the stitching keymap: boundary crossings only.
 *
 * Each handler defers to CodeMirror whenever the cursor can still move
 * inside the island (moveVertically respects line wrapping, so a wrapped
 * last line keeps its visual rows); only a cursor with nowhere left to go
 * crosses to the neighbor, carrying its goal column.
 */
const stitchArrowKeys = (stitchTo) => [
  {
    key: 'ArrowDown',
    run: (view) => {
      const selection = view.state.selection.main;
      if (view.moveVertically(selection, true).head !== selection.head) {
        return false;
      }
      const line = view.state.doc.lineAt(selection.head);
      return stitchTo(1, {
        edge: 'firstLine',
        column: selection.head - line.from,
      });
    },
  },
  {
    key: 'ArrowUp',
    run: (view) => {
      const selection = view.state.selection.main;
      if (view.moveVertically(selection, false).head !== selection.head) {
        return false;
      }
      const line = view.state.doc.lineAt(selection.head);
      return stitchTo(-1, {
        edge: 'lastLine',
        column: selection.head - line.from,
      });
    },
  },
  {
    key: 'ArrowRight',
    run: (view) =>
      view.state.selection.main.head === view.state.doc.length
        ? stitchTo(1, { edge: 'start' })
        : false,
  },
  {
    key: 'ArrowLeft',
    run: (view) =>
      view.state.selection.main.head === 0
        ? stitchTo(-1, { edge: 'end' })
        : false,
  },
];

/**
 * The winner dot: the small chrome chip on a lost line naming the layer
 * whose value beats it, in that layer's stable hue. A widget, never
 * buffer text, so it cannot diff or read as an edit. The DOM comes from
 * the shared builder (island-surface.js) the printer uses too.
 */
class WinnerWidget extends WidgetType {
  constructor(label, color) {
    super();
    this.label = label;
    this.color = color;
  }

  eq(other) {
    return other.label === this.label && other.color === this.color;
  }

  toDOM() {
    return winnerChip(this.label, this.color);
  }

  // CodeMirror's WidgetType hook: the answer is the widget class's, never
  // an instance's.
  // eslint-disable-next-line class-methods-use-this
  ignoreEvent() {
    return true;
  }
}

/**
 * The run label: the block naming a layer run once above its first line,
 * in the layer's hue (line-marks.js `run`). A block widget before the
 * line, never buffer text, so it cannot diff or read as an edit; the
 * printer places the same DOM before the printed line (island-surface.js
 * runLabel), so the two postures agree on where the label sits. The
 * label is the run's handle: ignoreEvent keeps its right-click out of
 * the editor (CodeMirror hands the event no further), so it bubbles to
 * the island's contextmenu, which opens the run menu.
 */
class RunWidget extends WidgetType {
  constructor(label, color, layer) {
    super();
    this.label = label;
    this.color = color;
    this.layer = layer;
  }

  eq(other) {
    return (
      other.label === this.label &&
      other.color === this.color &&
      other.layer === this.layer
    );
  }

  toDOM() {
    return runLabel(this.label, this.color, this.layer);
  }

  // CodeMirror's WidgetType hook: the answer is the widget class's, never
  // an instance's.
  // eslint-disable-next-line class-methods-use-this
  ignoreEvent() {
    return true;
  }
}

/**
 * The pending-row mark: the dismiss (or restore) control on a pending
 * line. Document islands (the editing session's body) wear it on staged
 * lines:
 * dismiss drops the staged edit and the stored sheet resurfaces.
 * Interactive chrome, so ignoreEvent lets the button's own handler run
 * without moving the cursor. The DOM comes from the shared builder
 * (island-surface.js) the printer uses too.
 */
class PendingMarkWidget extends WidgetType {
  constructor(kind, id, onRun) {
    super();
    this.kind = kind;
    this.id = id;
    this.onRun = onRun;
  }

  eq(other) {
    return other.kind === this.kind && other.id === this.id;
  }

  toDOM() {
    return pendingMark(this.kind, this.id, this.onRun);
  }

  // CodeMirror's WidgetType hook: the answer is the widget class's, never
  // an instance's.
  // eslint-disable-next-line class-methods-use-this
  ignoreEvent() {
    return true;
  }
}

/**
 * One island: one (key, condition) declaration list, in one of two
 * postures. Printed by default (static markup from the same line model)
 * and live (a small CM6 editor) only while it holds the cursor: waking on
 * pointerdown, stitch arrival, autoFocus, or a parked-focus claim, and
 * parking on blur. The surface (island-surface.js) owns the container in
 * both postures; the cost of the code view is bounded to one editor.
 *
 * `stack` is this island's key's style stack (buildStack), the layered
 * document the island prints at its exact condition (stackLines): built
 * with the defaults floor for the selected element's islands (one
 * `declared:{scope}` / `skin:{scope}` layer per declaring scope, the
 * base:{tag} layer when the canvas tag is known), without it for the
 * tree scopes' sparse rows, and for a document island the anchoring
 * wearer's stack (the honesty read's winners) or null when unanchored. A
 * floor line is display, never a write target: editing it is a SET on
 * the own layer, its menu offers the lift and the skin doorway, never
 * Unset.
 *
 * `project` is the island's read of its stack's lines in element mode,
 * `(lines, condition) => lines` (element-model.js): the selected
 * element's own islands drop the ancestor tier's lines (ownIslandLines:
 * the rules print as rule islands beneath), a rule island strikes what
 * the selected element beats (ruleIslandLines, the `beaten` mark);
 * null prints the stack's lines as they are (the tree's sparse rows, a
 * document island).
 *
 * `rule` marks a rule island (its own layer is the rule's entry, so its
 * own run reads "this rule"); `onOpenDocument` is the variant doorway
 * the run menu opens a variant run through (the Inspector's
 * openDocument: reference, condition, anchor), null where no host wires
 * it (the satellite), which drops the item.
 */
const Island = ({
  source,
  guide,
  materials,
  declaration,
  mapKey,
  condition,
  styleMap,
  variants,
  axes,
  materialize,
  autoFocus,
  stitch,
  sheet = null,
  posed = false,
  stack = null,
  project = null,
  rule = false,
  onOpenDocument = null,
}) => {
  const containerRef = useRef(null);
  const surfaceRef = useRef(null);
  const session = useRef({});
  const [hasErrors, setHasErrors] = useState(false);
  // The posture as a class: the surface swaps the editor in and out
  // imperatively (island-surface.js) and reports each swap through
  // onPosture; the live island is the one lifted off the panel
  // (inspector.css `--live`), a paint only, so the class may arrive a
  // render after the wake without moving a pixel.
  const [live, setLive] = useState(false);
  // The right-click line and run menus (the shared primitive;
  // line-menu.js computes the items, the handlers below execute them).
  const menu = useMenu();
  const islandId = `${mapKey}|${condition}`;
  const rootPos = mapKey.indexOf('::');
  const rootKey = rootPos === -1 ? mapKey : mapKey.slice(0, rootPos);
  const relative = mapKey.slice(rootKey.length);

  // The stack is rebuilt per render; its signature keys the re-print on
  // what it says (a floor layer appearing, a tag change swapping the
  // base:{tag} layer), not on its identity.
  const stackSignature = stack ? JSON.stringify(stack.layers) : '';

  // CM6 lives outside preact's render loop; its closures read the latest
  // props through this ref.
  const propsRef = useRef({});
  propsRef.current = {
    source,
    guide,
    materials,
    declaration,
    styleMap,
    variants,
    axes,
    materialize,
    stitch,
    sheet,
    stack,
    project,
    rule,
    onOpenDocument,
  };

  // The wearer cascade's winners for this island — the document honesty
  // read: a sheet line (or pending set) a higher layer beats on the
  // anchoring wearer strikes through with the winner named, so the buffer
  // never promises a look Push cannot deliver here. An unanchored
  // document island (nothing on this page carries the fragment) has no
  // wearer cascade to read.
  const currentWinners = () => {
    const { sheet: sheetCtx, stack: currentStack } = propsRef.current;
    if ((sheetCtx && !sheetCtx.anchored) || !currentStack) {
      return new Map();
    }
    return wearerWinners(stackLines(currentStack, condition));
  };

  // The island's document. Element mode prints the wearer's layered
  // document through the island's projection; a document island (the
  // editing session's body) prints the VARIANT'S OWN SHEET at its
  // document key with the session's staged lines rendered in place
  // (pending marks), wearer-context shadowing overlaid for display only.
  const currentLines = () => {
    const {
      sheet: sheetCtx,
      stack: currentStack,
      project: projection,
    } = propsRef.current;
    if (sheetCtx) {
      const baseline = documentBaselineLines(
        sheetCtx.variantStyles,
        sheetCtx.slot,
        condition,
      );
      const winners = currentWinners();
      return overlaySessionLines(
        baseline,
        sheetCtx.session,
        sheetCtx.slot,
        condition,
      ).map((line) => {
        const winner = documentLineWinner(
          winners,
          sheetCtx.reference,
          line.property,
        );
        return winner === null ? line : { ...line, lost: true, winner };
      });
    }
    const lines = currentStack ? stackLines(currentStack, condition) : [];
    return projection ? projection(lines, condition) : lines;
  };

  // The document diff baseline: the PURE variant sheet, no overlay, no
  // shadowing — within its own sheet nothing shadows, so every buffer
  // difference against it is exactly the staged set/unset it means.
  // Null in element mode (diffs run against the layered lines there).
  const currentBaseline = () => {
    const { sheet: sheetCtx } = propsRef.current;
    return sheetCtx
      ? documentBaselineLines(sheetCtx.variantStyles, sheetCtx.slot, condition)
      : null;
  };
  // An element island hands the lint the lines it printed from, so the
  // delivered text beneath the own layer is never judged as the user's; a
  // document island's lines are the variant's own sheet, all of it the
  // user's to write, so it hands none.
  const analyze = (text) =>
    analyzeIsland(text, {
      guide: propsRef.current.guide,
      materials: propsRef.current.materials,
      declaration: propsRef.current.declaration,
      lines: propsRef.current.sheet ? [] : (session.current.lines ?? []),
    });

  const releaseStaged = () => {
    const staged = session.current.staged ?? {};
    if (Object.keys(staged).length) {
      propsRef.current.source.previewRelease(mapKey, condition, staged);
      session.current.staged = {};
    }
  };

  // The optimistic half, per keystroke: stage exactly what the buffer's
  // cascade says differently from the baseline — the same delta the
  // commit side runs (islandDelta), so changed effective values stage,
  // vanished own lines stage null, and a deleted variant line stages
  // nothing: the variant keeps delivering, which is exactly the honest
  // no-op the commit side promises. On a document island the baseline is
  // the variant's own sheet and the preview lands on the anchoring
  // wearer ("preview locally on this wearer"): sets a higher layer beats
  // there stay out (the canvas keeps the truth Push would deliver; the
  // buffer's strikethrough tells the story), pending unsets preview
  // nothing (the variant keeps delivering until Push), and an unanchored
  // island previews nothing at all.
  const previewPass = (view) => {
    const doc = view.state.doc.toString();
    const analysis = analyze(doc);
    setHasErrors(
      analysis.diagnostics.some(
        (diagnostic) => diagnostic.severity === 'error',
      ) || doorDiagnostics(view).length > 0,
    );
    const sheetCtx = propsRef.current.sheet;
    const delta = islandDelta(
      analysis,
      session.current.baseline ?? session.current.lines ?? [],
    );
    const staged = {};
    if (sheetCtx) {
      const winners = currentWinners();
      delta.sets.forEach(({ property, value }) => {
        if (
          sheetCtx.anchored &&
          documentLineWinner(winners, sheetCtx.reference, property) === null
        ) {
          staged[property] = value;
        }
      });
    } else {
      delta.sets.forEach(({ property, value }) => {
        staged[property] = value;
      });
      delta.unsets.forEach((property) => {
        staged[property] = null;
      });
    }
    const previous = session.current.staged ?? {};
    const dropped = {};
    Object.entries(previous).forEach(([property, value]) => {
      if (!(property in staged)) {
        dropped[property] = value;
      }
    });
    if (Object.keys(dropped).length) {
      propsRef.current.source.previewRelease(mapKey, condition, dropped);
    }
    Object.entries(staged).forEach(([property, value]) => {
      if (!(property in previous) || previous[property] !== value) {
        propsRef.current.source.preview(mapKey, condition, property, value);
      }
    });
    session.current.staged = staged;
    return analysis;
  };

  // The settle half: one single-property op per settled declaration,
  // straight through the source's op queue (one op = one history entry).
  // `pending` de-duplicates between dispatch and the authoritative
  // refresh; entries retire when the refreshed lines reflect them (or the
  // op fails, so a correction can re-commit).
  const commitPass = (view, { includeActive }) => {
    const doc = view.state.doc.toString();
    const analysis = analyze(doc);
    // Structure settles through the door, never as declarations: every
    // closed block in the buffer goes to the spine's parser on the same
    // boundary a declaration commits on, and leaves the buffer when its
    // entries land (settleChunks).
    settleChunks(view, analysis);
    const cursor = includeActive ? null : view.state.selection.main.head;
    const sheetCtx = propsRef.current.sheet;
    if (sheetCtx) {
      // The document settle half: no ops, no tempstore — the settled diff
      // against the variant's own sheet REPLACES this island's staged
      // lines in the pending session (stageIslandLines), so a line
      // re-typed back to baseline unstages itself and everything else
      // waits for the Push/Discard bar. Same boundaries as element
      // commits: a mid-edit declaration stages when it settles.
      const settled = settleOps(
        mapKey,
        condition,
        doc,
        analysis,
        session.current.baseline ?? [],
        { cursor, includeActive },
      );
      sheetCtx.stage(
        sheetCtx.slot,
        condition,
        settled.map((op) => {
          const [property, value] = Object.entries(op.properties)[0];
          return { property, value };
        }),
      );
      return;
    }
    const ops = settleOps(
      mapKey,
      condition,
      doc,
      analysis,
      session.current.lines ?? [],
      { cursor, includeActive },
    );
    const pending = session.current.pending;
    ops.forEach((op) => {
      const [property, value] = Object.entries(op.properties)[0];
      if (pending.has(property) && pending.get(property) === value) {
        return;
      }
      pending.set(property, value);
      propsRef.current.source
        .commit({
          key: mapKey,
          condition,
          properties: { [property]: value },
          label:
            value === null
              ? Drupal.t('Removed @property', { '@property': property })
              : Drupal.t('Styled @property', { '@property': property }),
        })
        .catch(() => {
          if (pending.get(property) === value) {
            pending.delete(property);
          }
        });
    });
    restorePass(view, analysis);
  };

  // --- The paste door --------------------------------------------------
  // Text is a door, never the store. A pasted text with structure (a
  // nested `&:hover { }`, an `@container` wrapper, a `#id { }` block from
  // the AI's answer, a `.quote { }` part) never enters the buffer as
  // text, and a closed block the buffer holds at a settle leaves it: the
  // text goes whole to the spine's parse door through the source seam
  // (source.parseCss: the leader fetches the route, the satellite relays),
  // which answers the flat (key, condition) entries it means through the
  // one parser the AI's style op uses. The element document lands them
  // as one update_style batch plus apply_variant for rule wear (one
  // history entry, the grant judged at the write exactly as the AI's
  // path); the variant document stages them (wear refused with a notice:
  // a document has no element to wear on). Correctives become standing
  // diagnostics at their lines and the chunk stays put until it is
  // edited, when the next settle sends it again.

  // The door's standing correctives as CM diagnostics: the sent texts
  // located verbatim in the buffer (in order), each error's line mapped
  // through them. A text no longer found verbatim was edited, which
  // retires the correctives (the next settle re-sends the chunk).
  const doorDiagnostics = (view) => {
    const door = session.current.door;
    if (!door) {
      return [];
    }
    const { doc } = view.state;
    const text = doc.toString();
    const segments = [];
    let cursor = 0;
    for (let i = 0; i < door.texts.length; i += 1) {
      const at = text.indexOf(door.texts[i], cursor);
      if (at === -1) {
        session.current.door = null;
        return [];
      }
      segments.push({
        startLine: doc.lineAt(at).number,
        lines: lineCount(door.texts[i]),
      });
      cursor = at + door.texts[i].length;
    }
    return errorLines(door.errors, segments).map(({ line, message }) => {
      const docLine = doc.line(Math.min(Math.max(line, 1), doc.lines));
      return { from: docLine.from, to: docLine.to, severity: 'error', message };
    });
  };

  // Lands the door's entries: the element document's one batch, the
  // variant document's staged lines per (slot, condition), merged over
  // what the session already stages there (a paste is additive) and
  // dropped where the pasted value is the sheet's own (nothing pending).
  const landDoorAnswer = ({ entries = [], wear = [] }) => {
    const sheetCtx = propsRef.current.sheet;
    if (!sheetCtx) {
      const { operations, count } = entriesToOps(entries, wear);
      if (!operations.length) {
        return;
      }
      propsRef.current.source
        .commitBatch({
          operations,
          label: Drupal.t('Pasted @count declarations', { '@count': count }),
        })
        .catch(() => {
          // The op failure report lands on the leader page.
        });
      return;
    }
    if (wear.length) {
      notify(
        'warning',
        Drupal.t(
          'A variant document cannot wear another variant; the pasted wear was skipped. Apply that variant on the element instead.',
        ),
      );
    }
    const grouped = new Map();
    let strangers = 0;
    entries.forEach(({ key, condition: entryCondition, properties }) => {
      const slot = documentSlot(key, mapKey, sheetCtx.slot);
      if (slot === null) {
        strangers += 1;
        return;
      }
      const id = `${slot}\u0000${entryCondition}`;
      const group = grouped.get(id) ?? { slot, condition: entryCondition, properties: {} };
      Object.assign(group.properties, properties ?? {});
      grouped.set(id, group);
    });
    grouped.forEach(({ slot, condition: entryCondition, properties }) => {
      const baseline = new Map(
        documentBaselineLines(sheetCtx.variantStyles, slot, entryCondition).map(
          (line) => [line.property, line.value],
        ),
      );
      const merged = new Map(
        (sheetCtx.session?.lines ?? [])
          .filter((line) => line.relative === slot && line.condition === entryCondition)
          .map((line) => [line.property, line.value]),
      );
      Object.entries(properties).forEach(([property, value]) => {
        merged.set(property, value);
      });
      sheetCtx.stage(
        slot,
        entryCondition,
        [...merged.entries()]
          .filter(([property, value]) => baseline.get(property) !== value)
          .map(([property, value]) => ({ property, value })),
      );
    });
    if (strangers) {
      notify(
        'warning',
        Drupal.t(
          'Part of the pasted CSS addressed another element and was skipped; a document island takes its own rules only.',
        ),
      );
    }
  };

  // Sends texts with structure through the door, once per text: a text
  // whose correctives stand is not re-sent until it is edited, and a
  // text in flight is not sent twice. Resolves true when the entries
  // landed, false when correctives stand (the caller places the text).
  const sendToDoor = (texts, chunks, { onErrors = null, onLanded = null } = {}) => {
    const text = texts.join('\n');
    const standing = session.current.door;
    if (session.current.doorInFlight === text || standing?.texts.join('\n') === text) {
      return;
    }
    const { guide: currentGuide, sheet: sheetCtx, source: currentSource } = propsRef.current;
    if (sheetCtx && !sheetCtx.anchored) {
      notify(
        'warning',
        Drupal.t(
          'Nothing on this page carries this fragment, so pasted CSS has no element to be read under; paste where an element wears the variant.',
        ),
      );
      return;
    }
    const request = doorRequest(text, chunks, {
      mapKey,
      rootKey,
      condition,
      guide: currentGuide,
      document: Boolean(sheetCtx),
    });
    session.current.doorInFlight = text;
    currentSource.parseCss(request.key, request.css).then(
      (answer) => {
        session.current.doorInFlight = null;
        const errors = Array.isArray(answer?.errors) ? answer.errors : [];
        if (errors.length) {
          session.current.door = { texts, errors };
          onErrors?.(errors);
          return;
        }
        session.current.door = null;
        landDoorAnswer(answer ?? {});
        onLanded?.();
      },
      () => {
        // The source reported the failure (the leader's messenger, the
        // satellite's own); the text stays where it is.
        session.current.doorInFlight = null;
      },
    );
  };

  // The paste: text with structure never enters the buffer. It goes to
  // the door whole (its bare declarations included: they are the
  // island's own entry in the answer); only when correctives stand does
  // it land where the paste would have put it, its errors at their lines.
  const handlePaste = (event, view) => {
    const text = event.clipboardData?.getData('text/plain') ?? '';
    const chunks = structuralChunks(text);
    if (!chunks.length) {
      return false;
    }
    event.preventDefault();
    sendToDoor([text], chunks, {
      onErrors: (errors) => {
        if (surfaceRef.current?.live === view) {
          view.dispatch(view.state.replaceSelection(text));
          forceLinting(view);
          return;
        }
        notify('error', errorLines(errors, [])[0]?.message ?? '');
      },
    });
    return true;
  };

  // The settle's half: every closed block the buffer holds goes to the
  // door, and leaves the buffer when its entries land (a block on lines
  // of its own takes its line with it).
  const settleChunks = (view, analysis) => {
    const chunks = analysis.structural ?? [];
    if (!chunks.length) {
      return;
    }
    const texts = chunks.map((chunk) => chunk.text);
    sendToDoor(texts, chunks, {
      onErrors: () => {
        if (surfaceRef.current?.live === view) {
          forceLinting(view);
        }
      },
      onLanded: () => removeChunks(view, texts),
    });
  };

  const removeChunks = (view, texts) => {
    if (surfaceRef.current?.live !== view) {
      return;
    }
    const { doc } = view.state;
    const text = doc.toString();
    const changes = [];
    let cursor = 0;
    texts.forEach((chunk) => {
      const at = text.indexOf(chunk, cursor);
      if (at === -1) {
        return;
      }
      cursor = at + chunk.length;
      let from = at;
      let to = at + chunk.length;
      const first = doc.lineAt(from);
      const last = doc.lineAt(to);
      if (
        text.slice(first.from, from).trim() === '' &&
        text.slice(to, last.to).trim() === ''
      ) {
        from = first.from;
        to = last.to;
        if (to < doc.length) {
          to += 1;
        } else if (from > 0) {
          from -= 1;
        }
      }
      changes.push({ from, to });
    });
    if (changes.length) {
      view.dispatch({ changes });
    }
  };

  // The honest-restore half of variant-line deletion: deleting a variant-
  // supplied line commits nothing, so no authoritative refresh ever
  // re-prints the buffer — the variant keeps delivering while the island
  // claims the line is gone. On the same settle boundaries commits use,
  // once the buffer holds no other work (nothing held, nothing pending,
  // no committable diffs — the model's gate), re-print the baseline so
  // the survivor visibly returns, cursor kept in place.
  const restorePass = (view, analysis = null) => {
    const lines = session.current.lines ?? [];
    const currentAnalysis = analysis ?? analyze(view.state.doc.toString());
    if (
      session.current.pending?.size ||
      !shouldRestoreTinted(mapKey, condition, currentAnalysis, lines)
    ) {
      return;
    }
    const printed = printIsland(lines);
    session.current.printed = printed;
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: printed },
      selection: {
        anchor: Math.min(view.state.selection.main.head, printed.length),
      },
    });
    releaseStaged();
    redecorate(view);
  };

  // The one lineMarks context both postures read (redecorate and the
  // printer's marksFor), so a line wears the same marks whichever posture
  // shows it: the library for the supplier words, the sheet gate, the
  // own layer's word.
  const marksContext = () => {
    const {
      variants: library,
      sheet: sheetCtx,
      rule: isRule,
    } = propsRef.current;
    return {
      library: library ?? [],
      sheet: Boolean(sheetCtx),
      own: isRule ? 'rule' : 'element',
    };
  };

  // Provenance and the shadowing story, as decorations over the plain
  // buffer. Buffer declarations pair with baseline lines first-fit
  // (matchLines); an edited line matches nothing and prints plain — the
  // override-in-the-making. What each matched line wears comes from the
  // one marks model (line-marks.js) both postures share; this side only
  // turns marks into Decoration.line + widgets (the run label a block
  // widget before the run's first line).
  const redecorate = (view, analysis = null) => {
    const currentAnalysis = analysis ?? analyze(view.state.doc.toString());
    const matched = matchLines(
      currentAnalysis.declarations,
      session.current.lines ?? [],
    );
    const marks = lineMarks(matched, marksContext());
    const seen = new Set();
    const ranges = [];
    currentAnalysis.declarations.forEach((parsed, index) => {
      const mark = marks[index];
      if (!mark) {
        return;
      }
      const docLine = view.state.doc.lineAt(parsed.from);
      if (seen.has(docLine.from)) {
        return;
      }
      seen.add(docLine.from);
      if (mark.run) {
        ranges.push(
          Decoration.widget({
            widget: new RunWidget(mark.run.label, mark.run.color, mark.run.layer),
            block: true,
            side: -1,
          }).range(docLine.from),
        );
      }
      if (mark.pending) {
        // A staged document edit: the focus treatment plus the dismiss
        // mark (the review/exclude surface, in the stylesheet
        // representation).
        ranges.push(
          Decoration.widget({
            widget: new PendingMarkWidget(
              mark.pending.kind,
              mark.pending.id,
              dismissPendingLine,
            ),
            side: 2,
          }).range(docLine.to),
        );
      }
      if (mark.classes.length) {
        ranges.push(
          Decoration.line({
            class: mark.classes.join(' '),
            ...(mark.attributes ? { attributes: mark.attributes } : {}),
          }).range(docLine.from),
        );
      }
      if (mark.winner) {
        ranges.push(
          Decoration.widget({
            widget: new WinnerWidget(mark.winner.label, mark.winner.color),
            side: 1,
          }).range(docLine.to),
        );
      }
    });
    view.dispatch({
      effects: setIslandDecorations.of(Decoration.set(ranges, true)),
    });
  };

  // The printer's half of the one marks model: the same lineMarks context
  // redecorate reads, so a line wears the same marks whichever posture
  // shows it.
  const marksFor = (lines) => lineMarks(lines, marksContext());

  // Prints the island's truth: the current session lines, marked. The
  // fresh-mount and authoritative-refresh print; printedLines carries the
  // per-text-line line shapes for the printed line menu.
  const printTruth = () => {
    const lines = session.current.lines ?? [];
    const printed = printIsland(lines);
    session.current.printed = printed;
    session.current.printedLines = lines;
    surfaceRef.current?.print(printed.split('\n'), marksFor(lines));
  };

  // The park trigger's body: timers cleared, then the surface swaps the
  // live view for its print (onPark below derives that print from the
  // dying view, so nothing the user typed vanishes under them).
  const parkIsland = () => {
    if (!surfaceRef.current?.live) {
      return;
    }
    clearTimeout(session.current.previewTimer);
    clearTimeout(session.current.commitTimer);
    surfaceRef.current.park();
  };

  // The parked print, derived from the dying view: its exact text, each
  // line marked through matchLines (redecorate's alignment, printed). The
  // authoritative refresh re-prints the truth when it lands; until then
  // the print shows what the live view showed.
  const parkedPrint = (view) => {
    const doc = view.state.doc;
    const analysis = analyze(doc.toString());
    const matched = matchLines(
      analysis.declarations,
      session.current.lines ?? [],
    );
    const perLine = new Array(doc.lines).fill(null);
    analysis.declarations.forEach((parsed, index) => {
      const line = doc.lineAt(parsed.from);
      if (perLine[line.number - 1] === null) {
        perLine[line.number - 1] = matched[index] ?? null;
      }
    });
    const text = doc.toString();
    session.current.printed = text;
    session.current.printedLines = perLine;
    return { texts: text.split('\n'), marks: marksFor(perLine) };
  };

  // The right-click line menu (line-menu.js is the model): opened over a
  // MATCHED baseline line only — an edited, mid-flight declaration keeps
  // the native menu; its gesture is finishing the edit. Document islands
  // carry no menu: the sheet is the write target, typing/deleting is the
  // whole gesture set, and pending lines wear their own dismiss mark.
  const openLineMenu = (event, view) => {
    if (propsRef.current.sheet) {
      return false;
    }
    const pos = view.posAtCoords({ x: event.clientX, y: event.clientY });
    if (pos === null) {
      return false;
    }
    const analysis = analyze(view.state.doc.toString());
    const matched = matchLines(
      analysis.declarations,
      session.current.lines ?? [],
    );
    const docLine = view.state.doc.lineAt(pos);
    const index = analysis.declarations.findIndex(
      (parsed) => parsed.from >= docLine.from && parsed.from <= docLine.to,
    );
    const line = index === -1 ? null : matched[index];
    if (!line) {
      return false;
    }
    if (!openMenu(event, line)) {
      return false;
    }
    event.preventDefault();
    return true;
  };

  // The island's contextmenu, both postures: a run label opens the run
  // menu (the label is a block outside the editor's event ownership in
  // the live posture, so the event bubbles here live or printed); a
  // printed line opens the line menu WITHOUT waking, the line index from
  // the DOM target (data-line), the line from the print's stored shapes.
  // The live view keeps its own posAtCoords path for its lines above; a
  // live island's line contextmenu bubbles here too, so that half stands
  // down when live.
  const openPrintLineMenu = (event) => {
    const run = event.target.closest?.('.daedalus-stylish-island__run');
    if (run) {
      if (openRunMenu(event, run.dataset.layer ?? '')) {
        event.preventDefault();
      }
      return;
    }
    if (surfaceRef.current?.live || propsRef.current.sheet) {
      return;
    }
    const lineElement = event.target.closest?.('.daedalus-stylish-island__line');
    if (!lineElement) {
      return;
    }
    const line =
      (session.current.printedLines ?? [])[
        Number(lineElement.dataset.line ?? -1)
      ] ?? null;
    if (openMenu(event, line)) {
      event.preventDefault();
    }
  };

  // Opens the line menu at the pointer; false (no menu) leaves the native
  // menu to the browser.
  const openMenu = (event, line) =>
    menu.open({
      x: event.clientX,
      y: event.clientY,
      items: lineMenuItems(line)
        .map((item) => menuItem(item, line))
        .filter(Boolean),
    });

  // Opens the run menu at the pointer, headed by the run's word: the
  // layer's doorways (line-menu.js runMenuItems); false (the own run, a
  // doorway no host wires) leaves the native menu to the browser.
  const openRunMenu = (event, layer) =>
    menu.open({
      x: event.clientX,
      y: event.clientY,
      header: runWord(layer),
      items: runMenuItems(layer, propsRef.current.stack?.layers ?? [])
        .map((item) => runItem(item))
        .filter(Boolean),
    });

  // The lift: the line's value becomes an own override — the same landing
  // typing in the line produces (references stay worn; a lifted shadowed
  // value re-points the own override at it).
  const liftLine = (line) => {
    propsRef.current.source
      .commit({
        key: mapKey,
        condition,
        properties: { [line.property]: line.value },
        label: Drupal.t('Moved @property to this element', {
          '@property': line.property,
        }),
      })
      .catch(() => {
        // The op failure report lands on the leader page.
      });
  };

  // The unset: the override lifts and whatever the worn layers deliver
  // beneath resurfaces — deleting the line, as a click.
  const unsetLine = (line) => {
    propsRef.current.source
      .commit({
        key: mapKey,
        condition,
        properties: { [line.property]: null },
        label: Drupal.t('Removed @property', { '@property': line.property }),
      })
      .catch(() => {
        // The op failure report lands on the leader page.
      });
  };

  // Dismissing a staged document edit: the pending line drops from the
  // session AND the buffer re-prints the reverted sheet immediately —
  // dismissal is an explicit gesture on this island, so the buffer
  // follows it even under the cursor (clamped), where the authoritative
  // refresh would politely defer to focus.
  const dismissPendingLine = (id) => {
    const sheetCtx = propsRef.current.sheet;
    if (!sheetCtx || !surfaceRef.current) {
      return;
    }
    sheetCtx.dismiss(id);
    const baseline = documentBaselineLines(
      sheetCtx.variantStyles,
      sheetCtx.slot,
      condition,
    );
    const remaining = {
      kind: 'update',
      lines: (sheetCtx.session?.lines ?? []).filter((line) => line.id !== id),
    };
    const winners = currentWinners();
    const lines = overlaySessionLines(
      baseline,
      remaining,
      sheetCtx.slot,
      condition,
    ).map((line) => {
      const winner = documentLineWinner(
        winners,
        sheetCtx.reference,
        line.property,
      );
      return winner === null ? line : { ...line, lost: true, winner };
    });
    session.current.lines = lines;
    const view = surfaceRef.current.live;
    if (view) {
      const printed = printIsland(lines);
      session.current.printed = printed;
      view.dispatch({
        changes: { from: 0, to: view.state.doc.length, insert: printed },
        selection: {
          anchor: Math.min(view.state.selection.main.head, printed.length),
        },
      });
      redecorate(view);
    } else {
      // A printed island's dismiss mark: re-print the reverted sheet.
      printTruth();
    }
    releaseStaged();
  };

  // The stitching keymap: Tab walks the DevTools chain (accept the open
  // completion, else value -> next declaration -> new declaration ->
  // next island), arrows cross island boundaries when the cursor has
  // nowhere left to go inside this one. Chrome rows are never targets:
  // the registry only knows islands.
  const stitchTo = (direction, placement) =>
    propsRef.current.stitch?.focusNeighbor(islandId, direction, placement) ??
    false;
  const stitchKeymap = [
    { key: 'Tab', run: acceptCompletion },
    {
      key: 'Tab',
      run: (view) => {
        const target = nextTabTarget(
          view.state.doc.toString(),
          view.state.selection.main.head,
        );
        if (target.type === 'stop') {
          view.dispatch({
            selection: { anchor: target.pos },
            scrollIntoView: true,
          });
          return true;
        }
        if (target.type === 'new-declaration') {
          view.dispatch({
            changes: { from: target.pos, insert: '\n' },
            selection: { anchor: target.pos + 1 },
            scrollIntoView: true,
          });
          return true;
        }
        return stitchTo(1, { edge: 'firstStop' });
      },
    },
    {
      key: 'Shift-Tab',
      run: (view) => {
        const target = previousTabTarget(
          view.state.doc.toString(),
          view.state.selection.main.head,
        );
        if (target.type === 'stop') {
          view.dispatch({
            selection: { anchor: target.pos },
            scrollIntoView: true,
          });
          return true;
        }
        return stitchTo(-1, { edge: 'lastStop' });
      },
    },
    ...stitchArrowKeys(stitchTo),
  ];

  // One surface per island; recreate on selection/condition change. The
  // island prints immediately (printTruth below); the editor exists only
  // while this island holds the cursor: wake builds it synchronously
  // inside the triggering event, park destroys it on blur.
  useEffect(() => {
    if (!containerRef.current) {
      return undefined;
    }
    const lines = currentLines();
    session.current = {
      lines,
      baseline: currentBaseline(),
      printed: printIsland(lines),
      staged: {},
      pending: new Map(),
      previewTimer: null,
      commitTimer: null,
      door: null,
      doorInFlight: null,
    };
    setHasErrors(false);
    // A re-print retires the line the menu was over.
    menu.close();

    // The live posture's state, over the standing print's exact text (the
    // pointerdown caret mapping's contract); wake places the caret in the
    // state before any view exists.
    const buildState = () =>
      EditorState.create({
        doc: session.current.printed,
        extensions: [
          history(),
          css(),
          EditorView.lineWrapping,
          placeholder(Drupal.t('property: value;')),
          islandDecorationsField,
          tooltips({
            parent: tooltipParent(containerRef.current.ownerDocument),
          }),
          autocompletion({
            override: [(context) => completionSource(context, propsRef)],
          }),
          // The braced materialize: `p {` / `> pb-text {` / `.quote {`
          // typed at a declaration position mints the selector's row
          // without the pick, the brace and the selector swallowed
          // together (bracedSelector decides; a partial selector or a
          // declaration keeps the brace as text for the lint to name).
          EditorView.inputHandler.of((view, from, to, inserted) => {
            const selectors = propsRef.current.materialize?.selectors;
            if (inserted !== '{' || !selectors) {
              return false;
            }
            const line = view.state.doc.lineAt(from);
            const selector = bracedSelector(
              line.text.slice(0, from - line.from),
              line.text.slice(to - line.from),
            );
            if (selector === null) {
              return false;
            }
            const leading = line.text.length - line.text.trimStart().length;
            view.dispatch({ changes: { from: line.from + leading, to: line.to } });
            selectors.onMaterialize(selector);
            return true;
          }),
          linter(
            (lintView) => {
              const docLength = lintView.state.doc.length;
              return [
                ...analyze(lintView.state.doc.toString()).diagnostics.map(
                  (diagnostic) => ({
                    from: Math.min(diagnostic.from, docLength),
                    to: Math.min(diagnostic.to, docLength),
                    severity: diagnostic.severity,
                    message: diagnostic.message,
                  }),
                ),
                // The door's standing correctives, at their lines.
                ...doorDiagnostics(lintView),
              ];
            },
            { delay: 300 },
          ),
          keymap.of([
            ...completionKeymap,
            ...stitchKeymap,
            ...defaultKeymap,
            ...historyKeymap,
          ]),
          EditorView.updateListener.of((update) => {
            if (update.docChanged) {
              session.current.cursorLine = update.state.doc.lineAt(
                update.state.selection.main.head,
              ).number;
              let semicolon = false;
              update.changes.iterChanges((fromA, toA, fromB, toB, inserted) => {
                if (inserted.toString().includes(';')) {
                  semicolon = true;
                }
              });
              clearTimeout(session.current.previewTimer);
              session.current.previewTimer = setTimeout(() => {
                const analysis = previewPass(update.view);
                redecorate(update.view, analysis);
              }, 150);
              if (semicolon) {
                // The declaration just terminated: the settle boundary.
                commitPass(update.view, { includeActive: true });
              } else {
                clearTimeout(session.current.commitTimer);
                session.current.commitTimer = setTimeout(
                  () => commitPass(update.view, { includeActive: false }),
                  400,
                );
              }
            } else if (update.selectionSet) {
              // The cursor left a declaration: everything settled off the
              // active line may commit.
              const head = update.state.selection.main.head;
              const headLine = update.state.doc.lineAt(head).number;
              if (session.current.cursorLine !== headLine) {
                session.current.cursorLine = headLine;
                commitPass(update.view, { includeActive: false });
              }
            }
          }),
          EditorView.domEventHandlers({
            paste: (event, target) => handlePaste(event, target),
            blur: (event, target) => {
              commitPass(target, { includeActive: true });
              // Park on blur, EXCEPT when the whole window lost focus: a
              // Cmd-Tab must not lose the cursor.
              if (document.hasFocus()) {
                parkIsland();
              }
            },
            contextmenu: (event, target) => openLineMenu(event, target),
          }),
        ],
      });
    const buildView = (state) => {
      const built = new EditorView({ state });
      session.current.cursorLine = state.doc.lineAt(
        state.selection.main.head,
      ).number;
      redecorate(built);
      return built;
    };
    const surface = createIslandSurface(containerRef.current, {
      buildState,
      buildView,
      resolveAnchor: placementAnchor,
      onPark: parkedPrint,
      onPendingRun: dismissPendingLine,
      onPosture: setLive,
    });
    surfaceRef.current = surface;
    printTruth();

    // The stitching registry: this island joins the chain (arrival wakes
    // it at the placement) and, the focus-restore seam, reclaims the
    // cursor a same-id predecessor parked when a re-render tore it down
    // mid-edit.
    const registry = propsRef.current.stitch;
    const unregister = registry?.register(islandId, {
      element: containerRef.current,
      focus: (placement) => surface.wake({ placement }),
    });
    const parked = registry?.claimFocus(islandId);
    if (parked) {
      surface.wake({ anchor: parked.head });
    }

    return () => {
      clearTimeout(session.current.previewTimer);
      clearTimeout(session.current.commitTimer);
      releaseStaged();
      const live = surface.live;
      if (registry && live?.hasFocus) {
        registry.rememberFocus(islandId, live.state.selection.main.head);
      }
      unregister?.();
      surface.destroy();
      surfaceRef.current = null;
    };
    // The sheet is part of the island's document identity: an island is
    // either the layered effective document or one variant document key,
    // so a reference change recreates the editor exactly like a
    // selection change.
  }, [mapKey, condition, sheet?.reference ?? null]);

  // A freshly materialized island takes the cursor: typing flows straight
  // from the gesture into the new row's declarations, on a fresh line
  // after the floor lines the drafted row prints (a wake at the island's
  // start would join the first typed declaration to the first floor
  // line).
  useEffect(() => {
    if (autoFocus) {
      surfaceRef.current?.wake({ placement: { edge: FRESH_LINE } });
    }
  }, [autoFocus]);

  // Authoritative refresh: a settled commit, a foreign edit, or the
  // async variant library replaced the resolved read. The baseline lines always
  // update (future diffs run against the new truth); the buffer re-prints
  // only when clean, or unfocused with a formatting-only difference —
  // never under the user's cursor.
  useEffect(() => {
    const lines = currentLines();
    session.current.lines = lines;
    session.current.baseline = currentBaseline();
    const byProperty = new Map(lines.map((line) => [line.property, line]));
    [...(session.current.pending ?? new Map()).entries()].forEach(
      ([property, value]) => {
        const line = byProperty.get(property);
        const reflected =
          value === null
            ? !line || line.layer !== ELEMENT_LAYER
            : line && line.layer === ELEMENT_LAYER && line.value === value;
        if (reflected) {
          session.current.pending.delete(property);
        }
      },
    );
    const surface = surfaceRef.current;
    if (!surface) {
      return;
    }
    const view = surface.live;
    if (!view) {
      // The printed posture simply re-prints the new truth: it has no
      // cursor to protect.
      printTruth();
      releaseStaged();
      setHasErrors(false);
      return;
    }
    const printed = printIsland(lines);
    const doc = view.state.doc.toString();
    const clean = doc === session.current.printed;
    if (
      printed !== session.current.printed &&
      (clean ||
        (!view.hasFocus &&
          !islandOps(
            mapKey,
            condition,
            analyze(doc),
            session.current.baseline ?? lines,
          ).length))
    ) {
      session.current.printed = printed;
      if (doc !== printed) {
        view.dispatch({
          changes: { from: 0, to: doc.length, insert: printed },
        });
      }
      releaseStaged();
      setHasErrors(false);
    } else if (!propsRef.current.sheet) {
      // The buffer stays the user's, but an own line the truth carries
      // and the buffer never showed (born by another door while this
      // island is live: the op spine's seed beneath a generated box's
      // first declaration, a satellite's or an AI turn's write) joins it
      // above the user's text, in truth order (island.js
      // unseenOwnLines). Without the splice the next settle would read
      // its absence as a deletion and unset it. The cursor maps forward
      // over the insert; a property with the island's own write in
      // flight is left alone until that write settles.
      const unseen = unseenOwnLines(
        analyze(doc),
        lines,
        session.current.pending.keys(),
      );
      if (unseen.length) {
        view.dispatch({
          changes: { from: 0, insert: `${printIsland(unseen)}\n` },
        });
      }
    }
    // The deferred variant-line-restore case: a deletion no-oped while other
    // edits were in flight; now that they've committed and the baseline
    // caught up, the boundary that skipped can complete.
    restorePass(view);
    redecorate(view);
    // The document session is input too: a dismissed (or staged)
    // pending line re-prints the sheet exactly like an authoritative map
    // refresh — when the buffer is clean; never under the user's cursor.
    // The stack is input by content (it is rebuilt per render; the
    // signature keys on what its layers say), so a row becoming the
    // selection re-prints with its floor, and a tag change re-prints
    // the base:{tag} layer.
  }, [styleMap, variants, sheet?.session ?? null, stackSignature]);

  // The menu's items (the shared menu primitive renders the chrome; the
  // keyboard's route to the same landings is typing, which always lifts).
  // The skin doorway is the row menu's (provenance-model.js skinDoorway):
  // it opens the active skin's edit form in a new window and exists only
  // while the guide carries the URL (null drops the item).
  const menuItem = (item, line) => {
    switch (item.type) {
      case 'lift':
        return {
          label: Drupal.t('Move to this element'),
          title: Drupal.t(
            "Make @property this element's own override — the same landing as typing in this line. The layer beneath keeps its value: a variant stays worn, the skin stays where it is.",
            { '@property': line.property },
          ),
          run: () => liftLine(line),
        };
      case 'edit-skin': {
        const url = propsRef.current.guide?.skinEditUrl ?? null;
        return url
          ? {
              label: Drupal.t('Edit skin…'),
              title: Drupal.t("Opens the active skin's form."),
              run: () => {
                window.open(url, '_blank', 'noopener');
              },
            }
          : null;
      }
      default:
        return {
          label: Drupal.t('Unset'),
          title: Drupal.t(
            'Remove this override; values the worn layers deliver beneath resurface.',
          ),
          run: () => unsetLine(line),
        };
    }
  };

  // The run's word for the run menu's header: the label the run wears
  // (the printed run's text, or the marks re-derived for a live view's
  // run), so the menu is headed by exactly what was right-clicked.
  const runWord = (layer) =>
    marksFor(session.current.printedLines ?? session.current.lines ?? []).find(
      (mark) => mark?.run?.layer === layer,
    )?.run.label ?? null;

  // The run menu's items (the shared menu primitive renders the chrome).
  // The variant doorway is the wear bar's (the Inspector's openDocument:
  // the editing session on the variant's document, at this island's
  // condition when it is not the default, anchored at the carrier a
  // rule-carried or worn ancestor run names); the skin doorway is the
  // line menu's. A rule has no doorway here: it prints as a rule island
  // whose header is its seat (line-menu.js runMenuItems).
  const runItem = (item) => {
    switch (item.type) {
      case 'edit-variant': {
        const open = propsRef.current.onOpenDocument;
        if (!open) {
          return null;
        }
        const label =
          findVariant(propsRef.current.variants ?? [], item.reference)?.label ??
          item.reference;
        return {
          label: Drupal.t('Edit @variant', { '@variant': label }),
          title: Drupal.t(
            "Edit this variant's whole document, in context of this wearer. Edits are pending until pushed.",
          ),
          run: () =>
            open(
              item.reference,
              condition !== 'default' ? condition : null,
              item.anchor,
            ),
        };
      }
      case 'edit-skin':
        return menuItem({ type: 'edit-skin' }, null);
      default:
        return {
          label: Drupal.t('Declared in code'),
          hint: Drupal.t('edited beside the templates'),
          disabled: true,
          reason: Drupal.t(
            'A declaration ships with its module; the site has no form for it.',
          ),
        };
    }
  };

  // A document island prints one sheet with no per-line provenance
  // (line-marks.js), so its rail hue is the island's: the variant's own
  // identity color, inherited by every line (a staged line overrides it
  // with the focus color in inspector.css).
  const railHue = sheet
    ? { '--daedalus-stylish-line-hue': layerColor(variantLayerId(sheet.reference)) }
    : undefined;

  return (
    <div
      className={`daedalus-stylish-island${live ? ' daedalus-stylish-island--live' : ''}${hasErrors ? ' daedalus-stylish-island--invalid' : ''}${posed ? ' daedalus-stylish-island--posed' : ''}`}
      style={railHue}
    >
      <div
        className="daedalus-stylish-island__editor"
        ref={containerRef}
        onContextMenu={openPrintLineMenu}
      />
    </div>
  );
};

/**
 * One element's chrome rows + islands (the shared roster body).
 *
 * The per-element unit of the code view: the page tree mounts one per
 * styled element under its chrome row (the full body for the selection,
 * sparse for the rest). Owns the materialization drafts: rows the
 * selector and `&`/`@` gestures created that hold no committed values
 * yet. Drafts are view state keyed to the element
 * (an element change discards them); once a draft row's first declaration
 * commits, the map-derived roster surfaces the row itself and the draft
 * merges away (draftRoster deduplicates).
 *
 * A condition row reads the condition's word as its label and its
 * nested-rule prelude as its code face (`&:hover`, `&:not(:last-child)`,
 * `@container (max-width: 640px) &`; prelude.js, rendered by
 * selector-chrome.js Prelude): under a selector row the `&` is that
 * row, exactly as CSS nesting reads it, so the rows ARE the page's
 * stylesheet in nesting form. The condition rows are also code view's
 * pose surface (the widget modes' Styled-at, one level closer to the
 * styles): clicking a row drives the canvas to its compound (`onPose`,
 * the Inspector's driveCanvas), and the island matching the posed
 * compound (`posed`, derived from the canvas vector + force state) wears
 * the "Posed" tag — posed, not editing here: islands address their own
 * conditions, so the pose only picks what the canvas previews. A
 * structural fact (first-child, empty, …) is no pose: its row lights
 * ("Applies") when this element satisfies it in the blueprint tree, and
 * a compound with facts lights when its posable parts are posed and its
 * facts hold.
 *
 * With `posedGhost` (the hosts set it on the SELECTED element only), a
 * posed compound with no stored island renders an empty chrome-minted
 * GHOST island at the root (posedRoster) — the code-view twin of
 * pose+edit minting the condition block in the widget modes. Typing into
 * it commits the condition block like any island; a ghost row carries no
 * × because its existence is the pose's, not the map's (empty again, the
 * row disappears on the next roster print).
 *
 * With `sparse` (the tree scopes set it on every UNSELECTED row), the
 * roster prunes to occupied islands (occupiedRoster): no empty
 * placeholder editors on rows the user is not working in; selecting the
 * element brings the full roster back.
 *
 * Each island prints its key's style stack (style-stack.js buildStack,
 * the one model every row and the AI's explain tool read). The defaults
 * floor (the active skin's defaults over the declaration's own, one
 * layer per declaring scope) and the ancestor tier (every ancestor rule
 * the selector matcher says reaches the key) join the SELECTED
 * element's stacks only, DevTools' rule (inherited rules for the
 * element you are inspecting): sparse rows build the map's layers
 * alone, so the page scope stays readable and the occupancy read is
 * untouched. Each island's stack is built for its own key (a part
 * island reads the part's scope, its base:{tag} layer keyed by that
 * key's read subject from the tree: the root's tag, a part's declared
 * tag, none for a tag tier or a chain). The own islands print the
 * floor's lines and drop the ancestor tier's (element-model.js
 * ownIslandLines; a floor line a rule beats keeps the rule's winner
 * chip), because every ancestor rule reaching a key prints as a RULE
 * ISLAND of its own beneath the key's own rows (rulesFor over the
 * roster's `ancestors`, nearest ancestor first, at the root and inside
 * each part row): the rule's own key's stack, the map's layers alone,
 * so it diffs, commits and lifts exactly as the owner's part row for
 * the same rule (a write lands at the ancestor key), struck where this
 * element beats it (ruleIslandLines, the winner chip naming the layer).
 *
 * Selector row headers (the human label, selectorLabel(), beside the
 * row's prelude with its combinator chips, Prelude) ride the
 * selection spine like the tree's part rows do:
 * `targetSelector` (the spine's styling target's selector, passed only
 * when this element IS the selection) wears the active mark, hovering a
 * header drives the hoverSelector facet through the source, and clicking
 * one selects the owner and targets the selector under it — selector
 * rows at the same two-way sync the element rows already have. The hot
 * mark paints imperatively from the page tree (page-view.js), addressed
 * by the headers' data attributes. A rule row's header speaks the
 * address words the subject line's chip speaks (write-address.js
 * addressLabel), carries the OWNER's key and the rule's selector in the
 * same data attributes, hovers the rule's reach and seats the rule as
 * the target on click; `address` (the inspector's write address, an
 * ancestor rule the address set carries, passed for the selection
 * alone) lights the seated one, and a seated rule the map does not
 * hold yet mounts as an empty island that takes the cursor: the chip
 * and the headers are two readouts of one spine target.
 */
export const ElementIslands = ({
  source,
  guide,
  materials,
  elementKey,
  styleMap,
  variants,
  stitch = null,
  posed = 'default',
  onPose = null,
  posedGhost = false,
  sparse = false,
  targetSelector = null,
  address = null,
  onOpenDocument = null,
}) => {
  const selected = elementKey;
  // The stitching registry spans the whole code surface: the page tree
  // passes one shared instance so the chain crosses elements; the
  // single-element view stitches its own islands.
  const localStitch = useRef(null);
  if (!stitch && !localStitch.current) {
    localStitch.current = createStitching();
  }
  const stitching = stitch ?? localStitch.current;
  const blockTypes = source.getBlockTypes();
  const layoutsTable = source.getSectionLayouts();
  const layoutTree = source.getLayout?.() ?? [];
  const tree = source.getTree?.() ?? null;
  // The matcher over the tree, for the rule headers' owner words; the
  // roster and the stacks build their own reads of the same tree.
  const matcher = useMemo(
    () => (Array.isArray(tree) ? createMatcher(tree) : null),
    [tree],
  );
  const axes = axisVocabulary(
    guide,
    selected,
    blockTypes,
    layoutsTable,
    layoutTree,
  );
  const library = variants ?? [];
  const selectedParsed = parseKey(selected);
  const bundle = bundleForKey(selectedParsed, blockTypes, layoutsTable);
  const declarationOf = (key) =>
    declarationForKey(guide, parseKey(key), bundle);
  const conditionLabel = (name) => guide?.conditions?.[name]?.label ?? name;

  // Materialized-but-uncommitted rows, plus which island takes the cursor.
  // Keyed to the selection so stale drafts never leak across elements.
  const emptyDrafts = { for: selected, conditions: {}, parts: [], focus: null };
  const [gestureState, setGestureState] = useState(emptyDrafts);
  const drafts = gestureState.for === selected ? gestureState : emptyDrafts;
  const withDrafts = (mutate) =>
    setGestureState((previous) =>
      mutate(previous.for === selected ? previous : emptyDrafts),
    );

  const materializeSelector = (selector) =>
    withDrafts((state) => ({
      ...state,
      parts: state.parts.includes(selector)
        ? state.parts
        : [...state.parts, selector],
      focus: `${selected}::${selector}|default`,
    }));
  const materializeCondition = (mapKey, name) =>
    withDrafts((state) => {
      const existing = state.conditions[mapKey] ?? [];
      return {
        ...state,
        conditions: {
          ...state.conditions,
          [mapKey]: existing.includes(name) ? existing : [...existing, name],
        },
        focus: `${mapKey}|${name}`,
      };
    });

  // Removal gestures: unset the row's OWN styles (one gesture, one history
  // entry) and drop its draft. Variant-restore honesty: variant-supplied
  // values are not the gesture's to remove — a variant-backed row stays,
  // until the variant is detached — so the × advertises which of
  // the two it will do.
  const removeCondition = (mapKey, condition) => {
    const ops = conditionRemovalOps(styleMap ?? {}, mapKey, condition);
    if (ops.length) {
      source.commitBatch({
        operations: ops,
        label: Drupal.t('Removed @condition styles', {
          '@condition': conditionLabel(condition),
        }),
      });
    }
    withDrafts((state) => ({
      ...state,
      conditions: {
        ...state.conditions,
        [mapKey]: (state.conditions[mapKey] ?? []).filter(
          (name) => name !== condition,
        ),
      },
      focus: null,
    }));
  };
  const removePart = (part) => {
    const ops = partRemovalOps(styleMap ?? {}, part.key);
    if (ops.length) {
      source.commitBatch({
        operations: ops,
        label: Drupal.t('Removed @part styles', { '@part': part.selector }),
      });
    }
    withDrafts((state) => {
      const conditions = { ...state.conditions };
      delete conditions[part.key];
      return {
        ...state,
        conditions,
        parts: state.parts.filter((selector) => selector !== part.selector),
        focus: null,
      };
    });
  };

  const removeTitle = (restores) =>
    restores.length
      ? Drupal.t(
          'Removes the styles this element added here. Values supplied by applied variants stay until the variant is detached.',
        )
      : Drupal.t('Remove this row and unset its styles.');

  // The full body (the selection) lists the ancestor rules reaching each
  // key and places the write address's rule; a sparse row lists none.
  const baseModel = elementIslands(
    styleMap ?? {},
    selected,
    guide,
    library,
    axes,
    blockTypes,
    layoutsTable,
    sparse ? null : tree,
    sparse ? null : address,
  );
  const rosterModel = sparse
    ? occupiedRoster(baseModel, styleMap ?? {}, selected, library, axes)
    : baseModel;
  const storedModel = draftRoster(rosterModel, drafts, selected, guide);
  const model = posedGhost
    ? posedRoster(storedModel, posed, guide)
    : storedModel;
  // A ghost row exists because the canvas stands there, not because the
  // map does: no × (the pose surface moves it away), everything else an
  // ordinary island.
  const isGhost = (mapKey, condition) =>
    mapKey === selected &&
    condition === posed &&
    !storedModel.conditions.includes(condition);

  // Selector materialization is the element's gesture (selector rows
  // do not nest); condition materialization belongs to every island.
  // The offer is an ELEMENT projection: it reads the blueprint's lean
  // tree through the matcher (the children present, their types and
  // tags), the declared anatomy (the owner's parts, and a type's parts
  // for a chain landing on it), the base tag vocabulary worded by the
  // guide's one tag word (tagWord: the base declaration's label, else
  // the shipped word), the owner's own prose tags (its markup
  // annotation), and the DOM-presence
  // facts for the owner's declared parts (the layout context's
  // self.parts), which describe the CANVAS SELECTION only and so join in
  // just when this element is it; anywhere else (a styled-but-unselected
  // page-tree row, the satellite pre-broadcast) the unknown facts
  // degrade to offering, the gate's rule. The rows already materialized
  // below (model.parts, off the style map) are the store projection and
  // ignore presence by construction.
  const partsOfType = (type) => {
    const address = typeAddress(type);
    const declaration =
      type === TYPE_CONTAINER
        ? guide?.kinds?.container
        : type === TYPE_BLOCK
          ? guide?.kinds?.block
          : address?.startsWith('block_type:')
            ? guide?.blockTypes?.[address.slice('block_type:'.length)]
            : null;
    return Object.entries(flattenElements(declaration))
      .filter(([selector]) => PART_SELECTOR.test(selector))
      .map(([selector, part]) => ({ id: selector, label: part?.label ?? selector }));
  };
  const selectorGestures = {
    context: {
      key: selected,
      tree,
      parts: partsForKey(guide, selected, blockTypes, layoutsTable),
      partsFor: partsOfType,
      presence:
        selected === source.getSelected?.()
          ? (source.getLayoutContext?.()?.self?.parts ?? null)
          : null,
      closed: propertiesClosed(guide),
      tags: Object.fromEntries(
        baseTags(guide?.base).map((tag) => [tag, tagWord(guide, tag)]),
      ),
      present: source.getMarkup?.(selected)?.tags ?? null,
      types: typeLabels(guide),
    },
    onMaterialize: materializeSelector,
  };

  // The posed tag matches non-default compounds only: the default
  // condition is the canvas's resting pose, and marking every default
  // island at rest would say nothing.
  // A condition row is lit when its condition renders on this element right
  // now: its posable parts are the posed compound and its structural
  // facts (first-child, empty, …) are ones this element satisfies, read
  // from the lean node tree's sibling index (editing-at.js
  // satisfiedFacts over the selector matcher). A fact on a SELECTOR row
  // qualifies the row's targets, not this element, so there no fact
  // ever lights: the row addresses other elements' places. An ancestor
  // rule reaching the ROOT qualifies this element (it is the rule's
  // match), so its rows read the own facts; one reaching a part reads
  // none, like the part row.
  const conditionTable = guide?.conditions ?? {};
  const ownFacts = satisfiedFacts(tree, selected, conditionTable);
  const noFacts = new Set();
  const isPosed = (condition, facts) =>
    conditionRenders(condition, posed, facts, conditionTable);
  // The lit tag's word: "Posed" for a compound the canvas stands at,
  // "Applies" for a pure fact, which no pose produced.
  const litTag = (condition) =>
    condition
      .split('+')
      .every((part) => isFactCondition(part, conditionTable))
      ? Drupal.t('Applies')
      : Drupal.t('Posed');

  // The style stack for one of this element's islands, keyed by the
  // island's own key: with the floor and the ancestor tier (the tree)
  // for the selection's full body only (the docblock's rule). Built once
  // per island key; the key's islands share it.
  const stackFor = (mapKey) =>
    buildStack({
      guide,
      map: styleMap ?? {},
      key: mapKey,
      variants: library,
      axes,
      blockTypes,
      sectionLayouts: layoutsTable,
      floor: !sparse,
      tree: sparse ? null : tree,
    });
  // A rule island's stack: the rule's own key, the map's layers alone
  // (the rule's wear, the owner's wear continued to the position, the
  // rule's entry as the own layer), the owner's axis vocabulary ordering
  // the owner's wear as the ancestor tier reads it. No floor: the
  // owner's floor at the position is not a layer this element's stack
  // knows, so it could vouch for none of its lines.
  const ruleStackFor = (rule) =>
    buildStack({
      guide,
      map: styleMap ?? {},
      key: rule.key,
      variants: library,
      axes: axisVocabulary(guide, rule.owner, blockTypes, layoutsTable, tree),
      blockTypes,
      sectionLayouts: layoutsTable,
      floor: false,
    });

  // One island per condition of one island key. `stack` is the key's
  // stack, `project` the Island's line projection, `facts` the fact set
  // the condition rows light against, `declaration` the grant judge for the
  // key's writes, `wake` the condition whose island takes the cursor on
  // mount beside the drafts' focus.
  const islandsFor = (
    mapKey,
    conditions,
    {
      stack,
      project = null,
      facts = noFacts,
      declaration = declarationOf(mapKey),
      wake = null,
      rule = false,
    },
  ) =>
    conditions.map((condition) => (
      <Fragment key={`${mapKey}|${condition}`}>
        {condition !== 'default' && (
          <div
            className={`daedalus-stylish-islands__condition${isPosed(condition, facts) ? ' daedalus-stylish-islands__condition--posed' : ''}`}
          >
            {/* The pose gesture: chrome never takes the cursor — the
              mouse's gesture, skipped by the keyboard chain. */}
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__condition-pose"
              title={Drupal.t(
                'Pose the canvas at "@condition" to preview these styles',
                { '@condition': conditionLabel(condition) },
              )}
              onClick={() => onPose?.(condition)}
            >
              <span className="daedalus-stylish-islands__condition-label">
                {conditionLabel(condition)}
              </span>
              <Prelude
                selector={null}
                condition={condition}
                guide={guide}
                className="daedalus-stylish-islands__prelude"
              />
            </button>
            {/* The canvas stands here: this island's styles render. */}
            {isPosed(condition, facts) && (
              <span className="daedalus-stylish-islands__condition-lit">
                {litTag(condition)}
              </span>
            )}
            {!isGhost(mapKey, condition) && (
              <button
                type="button"
                tabIndex={-1}
                className="daedalus-stylish-islands__remove daedalus-ui-icon-button daedalus-ui-icon-button--muted"
                title={removeTitle(
                  restoredLines(
                    styleMap ?? {},
                    mapKey,
                    condition,
                    library,
                    axes,
                  ),
                )}
                onClick={() => removeCondition(mapKey, condition)}
              >
                ×
              </button>
            )}
          </div>
        )}
        <Island
          source={source}
          guide={guide}
          materials={materials}
          declaration={declaration}
          mapKey={mapKey}
          condition={condition}
          styleMap={styleMap}
          variants={library}
          axes={axes}
          materialize={{
            selectors: mapKey === selected ? selectorGestures : null,
            condition: (name) => materializeCondition(mapKey, name),
          }}
          autoFocus={
            drafts.focus === `${mapKey}|${condition}` || wake === condition
          }
          stitch={stitching}
          posed={isPosed(condition, facts)}
          stack={stack}
          project={project}
          rule={rule}
          onOpenDocument={onOpenDocument}
        />
      </Fragment>
    ));

  // The rule rows reaching one island key (the roster's `ancestors`,
  // nearest ancestor first): a header on the selection spine like the
  // part headers, carrying the OWNER's key and the rule's selector in
  // the same data attributes (the page tree's hot painter and the FJS
  // reads address both alike), then the rule's islands. The header's
  // words are the address's (addressLabel: "All children of Hero"), the
  // same sentence the subject line's chip offers for it. Hover paints
  // the rule's reach through the hoverSelector facet; a click seats the
  // rule as the styling target (the selection stays put), and the
  // inspector's write address lights the seated row. A seated rule the
  // map does not hold yet (`existing` false: the chip's mintable entry)
  // is an empty island that takes the cursor, so the seat is one move
  // from the first declaration; its first settled write mints the entry
  // at the ancestor key. `facetStack` is the reached key's full stack,
  // whose winners strike what this element beats in the rule's lines.
  const rulesFor = (rules, facetStack, facts) =>
    rules.map((rule) => {
      const lit =
        address !== null &&
        address.key === rule.owner &&
        address.selector === rule.selector;
      return (
        <Fragment key={rule.key}>
          <div
            className={`daedalus-stylish-islands__part daedalus-stylish-islands__part--rule${lit ? ' daedalus-stylish-islands__part--active' : ''}`}
            data-daedalus-stylish-part-owner={rule.owner}
            data-daedalus-stylish-part={rule.selector}
            onMouseEnter={() => source.hoverSelector?.(rule.owner, rule.selector)}
            onMouseLeave={() => {
              const current = source.getHoveredSelector?.();
              if (
                current?.key === rule.owner &&
                current?.selector === rule.selector
              ) {
                source.hoverSelector?.(null);
              }
            }}
          >
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__part-select"
              title={rule.key}
              aria-pressed={lit}
              onClick={() => source.target?.(rule.owner, rule.selector)}
            >
              <span className="daedalus-stylish-islands__part-label">
                {addressLabel(
                  guide,
                  { key: rule.owner, selector: rule.selector },
                  ownerName(matcher, rule.owner),
                  blockTypes,
                  layoutsTable,
                )}
              </span>
              <Prelude
                selector={rule.selector}
                guide={guide}
                className="daedalus-stylish-islands__prelude"
              />
            </button>
          </div>
          <div className="daedalus-stylish-islands__part-body">
            {islandsFor(rule.key, rule.conditions, {
              stack: ruleStackFor(rule),
              project: (lines, condition) =>
                ruleIslandLines(lines, rule, stackLines(facetStack, condition)),
              facts,
              declaration: addressDeclaration(
                guide,
                { key: rule.owner, selector: rule.selector },
                blockTypes,
                layoutsTable,
              ),
              wake: lit && !rule.existing ? 'default' : null,
              rule: true,
            })}
          </div>
        </Fragment>
      );
    });

  const rootStack = stackFor(selected);
  return (
    <div className="daedalus-stylish-islands">
      {islandsFor(selected, model.conditions, {
        stack: rootStack,
        project: ownIslandLines,
        facts: ownFacts,
      })}
      {model.parts.map((part) => {
        const partStack = stackFor(part.key);
        // A part facet lights while the target sits on the element; under
        // an ancestor address the seated rule row inside it is the lit one.
        const active = address === null && part.selector === targetSelector;
        return (
          <Fragment key={part.key}>
            {/* The part header is the part's chrome row on the selection
                spine, the page tree rows' pattern one level down: hovering
                it drives the hoverSelector FACET (never the element hover — a
                part header must not light its owner's solid outline; the
                tree part rows' rule), leave clears only a facet this header
                set, and clicking selects the owner (when it is not the
                selection already) and targets the selector under it — the
                code twin of a tree part-row click. Active reads from the
                targetSelector prop (spine state); the hot mark is painted
                imperatively by the page tree (page-view.js) through the
                data attributes, hover's pointer-speed rule. */}
            <div
              className={`daedalus-stylish-islands__part${active ? ' daedalus-stylish-islands__part--active' : ''}`}
              data-daedalus-stylish-part-owner={selected}
              data-daedalus-stylish-part={part.selector}
              onMouseEnter={() => source.hoverSelector?.(selected, part.selector)}
              onMouseLeave={() => {
                const current = source.getHoveredSelector?.();
                if (
                  current?.key === selected &&
                  current?.selector === part.selector
                ) {
                  source.hoverSelector?.(null);
                }
              }}
            >
              <button
                type="button"
                tabIndex={-1}
                className="daedalus-stylish-islands__part-select"
                title={part.key}
                aria-pressed={active}
                onClick={() => {
                  if (source.getSelected?.() !== selected) {
                    source.select?.(selected);
                  }
                  source.target?.(selected, part.selector);
                }}
              >
                <span className="daedalus-stylish-islands__part-label">
                  {selectorLabel(
                    guide,
                    selected,
                    part.selector,
                    blockTypes,
                    layoutsTable,
                    source.getMarkup?.(selected),
                  )}
                </span>
                <Prelude
                  selector={part.selector}
                  guide={guide}
                  className="daedalus-stylish-islands__prelude"
                />
              </button>
              <button
                type="button"
                tabIndex={-1}
                className="daedalus-stylish-islands__remove daedalus-ui-icon-button daedalus-ui-icon-button--muted"
                title={removeTitle(
                  part.conditions.flatMap((condition) =>
                    restoredLines(
                      styleMap ?? {},
                      part.key,
                      condition,
                      library,
                      axes,
                    ),
                  ),
                )}
                onClick={() => removePart(part)}
              >
                ×
              </button>
            </div>
            <div className="daedalus-stylish-islands__part-body">
              {islandsFor(part.key, part.conditions, {
                stack: partStack,
                project: ownIslandLines,
              })}
              {rulesFor(part.ancestors ?? [], partStack, noFacts)}
            </div>
          </Fragment>
        );
      })}
      {rulesFor(model.ancestors ?? [], rootStack, ownFacts)}
    </div>
  );
};

/**
 * The editing session's code body: the edited variant's WHOLE document
 * as a root + children view (four controls, one loud mode).
 *
 * One chrome row per document SLOT in documentTree order — the root
 * first and always present (the honest edit surface even an empty
 * document offers), each slot's part and '> *' rows under it, and a
 * slot-path tree for a composition's children — with one island per
 * (document key, condition) beneath. Every island prints the variant's
 * OWN sheet with the open session's staged lines rendered in place
 * (pending marks) and stages its settles back into the session through
 * `document.stage`; the Push/Discard bar is the only commit surface.
 *
 * Wearer context: each fragment's islands anchor on a current-page
 * carrier of that fragment (carriedBy — the session's anchor wins when
 * it carries it, then an element carrier, then a rule carrier), which is
 * where typed previews land and what the shadowing overlay (winner dots)
 * reads. A rule carrier (`container:X::a` wearing the document)
 * continues a slot's suffix by descent (continuedKey), so its islands
 * preview on every match. A fragment nothing on this page carries still
 * renders — a variant's reach is site-wide — but its "carried by"
 * chrome says so and its islands stage without previewing (no canvas
 * element could honestly show the line).
 *
 * @param {string} props.anchor
 *   The session's anchor: the styling target's wear key the document was
 *   entered from (an element or a rule).
 * @param {object} props.document
 *   The document context the Inspector builds: {reference, styles,
 *   session, rootKind, stage(slot, condition, lines), dismiss(id)}.
 */
export const VariantDocumentView = ({
  source,
  guide,
  materials,
  anchor,
  styleMap,
  variants,
  document: doc,
  posed = 'default',
  onPose = null,
}) => {
  const library = variants ?? [];
  const map = styleMap ?? {};
  const blockTypes = source.getBlockTypes();
  const layoutsTable = source.getSectionLayouts();
  const layout = source.getLayout?.() ?? [];
  const tree = documentTree(doc.styles, doc.session, doc.rootKind);
  const carriers = carriedBy(map, doc.reference);
  // The matcher over the blueprint tree, for the slot headers' element
  // words (the same read every host makes: the leader off the mirror,
  // the satellite off the broadcast tree).
  const nodeTree = source.getTree?.() ?? null;
  const nodeMatcher = useMemo(
    () => (Array.isArray(nodeTree) ? createMatcher(nodeTree) : null),
    [nodeTree],
  );
  // One stitching registry over the whole document, so the keyboard
  // chain crosses slot boundaries in document order.
  const stitchRef = useRef(null);
  if (!stitchRef.current) {
    stitchRef.current = createStitching();
  }
  // Materialized-but-uncommitted condition rows on document keys (the `@`
  // gesture): view state keyed to the document, exactly the host body's
  // draft rule one document over.
  const emptyDrafts = { for: doc.reference, conditions: {}, focus: null };
  const [drafts, setDrafts] = useState(emptyDrafts);
  const draftConditions = drafts.for === doc.reference ? drafts.conditions : {};
  const draftFocus = drafts.for === doc.reference ? drafts.focus : null;
  const materializeCondition = (key, name) =>
    setDrafts((previous) => {
      const base = previous.for === doc.reference ? previous.conditions : {};
      const existing = base[key] ?? [];
      return {
        for: doc.reference,
        conditions: {
          ...base,
          [key]: existing.includes(name) ? existing : [...existing, name],
        },
        focus: `${key}|${name}`,
      };
    });
  const conditionLabel = (name) => guide?.conditions?.[name]?.label ?? name;
  const isEntry = (value) =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

  // The anchoring carrier for a fragment: the session's anchor when it
  // carries the fragment (the entered-from wearer), else the first
  // element carrier in map order (a read subject of its own), else the
  // first rule carrier, else none.
  const isRule = (key) => styleTarget(key).selector !== null;
  const carrierOf = (fragment) => {
    const keys = carriers.get(fragment) ?? [];
    if (keys.includes(anchor)) {
      return anchor;
    }
    return keys.find((key) => !isRule(key)) ?? keys[0] ?? null;
  };
  // The element a carrier's part labels resolve through: the carrier
  // itself, or a rule carrier's root (the markup a part is declared in).
  const labelOwner = (fragment) => {
    const carrier = carrierOf(fragment);
    return carrier === null ? '' : styleTarget(carrier).key;
  };
  // A slot header's words: the element's minted type word and name (the
  // tree node's `_element` through the matcher's facts, spelled by the
  // page tree's rowWords so both documents read one way) when an
  // element carrier anchors the slot to a live node; else the slot's
  // own vocabulary, since a variant nobody wears has positions, not
  // elements (a container slot is nested by construction: no variant
  // roots at the entity, so its word is "Container").
  const slotWords = (node) => {
    if (node.slot === '') {
      return { word: Drupal.t('Root'), name: null };
    }
    const carrier = carrierOf(node.slot);
    const facts =
      carrier !== null && !isRule(carrier)
        ? (nodeMatcher?.facts(carrier) ?? null)
        : null;
    if (facts?.type) {
      return rowWords(facts);
    }
    const word =
      node.kind === 'container'
        ? Drupal.t('Container')
        : node.kind === 'block'
          ? Drupal.t('Block')
          : node.kind === 'field'
            ? Drupal.t('Field')
            : Drupal.t('Slot');
    return { word, name: null };
  };

  // A document key's islands: its stored conditions, the session's staged
  // ones, and the drafts — the default condition always present as the
  // add-a-line surface (every listed key is an edit surface here).
  const conditionsFor = (key) => {
    const stored = Object.keys(
      isEntry(doc.styles?.[key]) ? doc.styles[key] : {},
    ).filter((name) => name !== 'variants');
    const staged = (doc.session?.lines ?? [])
      .filter((line) => line.relative === key)
      .map((line) => line.condition);
    return [
      ...new Set([
        'default',
        ...stored,
        ...staged,
        ...(draftConditions[key] ?? []),
      ]),
    ];
  };

  const carriedChip = (fragment) => {
    const keys = carriers.get(fragment) ?? [];
    const text =
      keys.length === 0
        ? Drupal.t('not on this page')
        : keys.includes(anchor)
          ? isRule(anchor)
            ? Drupal.t('carried by this rule')
            : Drupal.t('carried by this element')
          : Drupal.formatPlural(
              keys.length,
              'carried by 1 place here',
              'carried by @count places here',
            );
    return (
      <span
        className="daedalus-ui-chip daedalus-ui-chip--muted daedalus-stylish-document__carried"
        title={
          keys.length
            ? Drupal.t('Elements on this page carrying this fragment: @keys', {
                '@keys': keys.join(', '),
              })
            : Drupal.t(
                'Nothing on this page carries this fragment; the variant still delivers it site-wide.',
              )
        }
      >
        {text}
      </span>
    );
  };

  const isPosed = (condition) => condition === posed && condition !== 'default';

  const islandsFor = (node, key) => {
    const suffix = key.slice(node.slot.length);
    const carrier = carrierOf(node.slot);
    // A rule carrier continues the suffix by descent; a suffix the
    // grammar refuses under it anchors nothing (delivery prints nothing
    // there either).
    const carried = carrier === null ? null : continuedKey(carrier, suffix);
    const anchored = carried !== null;
    const mapKey = anchored ? carried : `~${key}`;
    const axes = anchored
      ? axisVocabulary(guide, carrier, blockTypes, layoutsTable, layout)
      : [];
    const declaration = anchored
      ? declarationForKey(
          guide,
          parseKey(mapKey),
          bundleForKey(parseKey(carrier), blockTypes, layoutsTable),
        )
      : null;
    // The anchoring wearer's stack, the map's layers alone: the honesty
    // read's winners (a sheet line a higher layer beats on this wearer).
    const wearerStack = anchored
      ? buildStack({
          map: styleMap ?? {},
          key: mapKey,
          variants: library,
          axes,
          floor: false,
        })
      : null;
    return conditionsFor(key).map((condition) => (
      <Fragment key={`${key}|${condition}`}>
        {condition !== 'default' && (
          <div
            className={`daedalus-stylish-islands__condition${isPosed(condition) ? ' daedalus-stylish-islands__condition--posed' : ''}`}
          >
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__condition-pose"
              title={Drupal.t(
                'Pose the canvas at "@condition" to preview these styles',
                { '@condition': conditionLabel(condition) },
              )}
              onClick={() => onPose?.(condition)}
            >
              <span className="daedalus-stylish-islands__condition-label">
                {conditionLabel(condition)}
              </span>
              <Prelude
                selector={null}
                condition={condition}
                guide={guide}
                className="daedalus-stylish-islands__prelude"
              />
            </button>
            {isPosed(condition) && (
              <span className="daedalus-stylish-islands__condition-lit">
                {Drupal.t('Posed')}
              </span>
            )}
          </div>
        )}
        <Island
          source={source}
          guide={guide}
          materials={materials}
          declaration={declaration}
          mapKey={mapKey}
          condition={condition}
          styleMap={styleMap}
          variants={library}
          axes={axes}
          materialize={{
            parts: null,
            condition: (name) => materializeCondition(key, name),
          }}
          autoFocus={draftFocus === `${key}|${condition}`}
          stitch={stitchRef.current}
          sheet={{
            reference: doc.reference,
            variantStyles: doc.styles,
            session: doc.session,
            slot: key,
            anchored,
            stage: doc.stage,
            dismiss: doc.dismiss,
          }}
          posed={isPosed(condition)}
          stack={wearerStack}
        />
      </Fragment>
    ));
  };

  return (
    <div className="daedalus-stylish-document">
      {tree.map((node) => {
        const words = slotWords(node);
        return (
        <div
          key={node.slot || 'root'}
          className="daedalus-stylish-document__slot"
          style={
            node.depth ? { '--daedalus-stylish-document-depth': node.depth } : undefined
          }
        >
          <div className="daedalus-stylish-document__header">
            <span className="daedalus-stylish-document__kind">{words.word}</span>
            {words.name !== null && (
              <span className="daedalus-stylish-document__name">{words.name}</span>
            )}
            {node.detail && (
              <code className="daedalus-stylish-document__detail">{node.detail}</code>
            )}
            {node.slot !== '' && node.kind === null && (
              <code className="daedalus-stylish-document__detail">{node.slot}</code>
            )}
            {carriedChip(node.slot)}
          </div>
          {node.keys.map(({ key, selector }) => (
            <Fragment key={key}>
              {selector !== null && (
                <div className="daedalus-stylish-islands__part">
                  <span className="daedalus-stylish-islands__part-label">
                    {selectorLabel(
                      guide,
                      labelOwner(node.slot),
                      selector,
                      blockTypes,
                      layoutsTable,
                      labelOwner(node.slot)
                        ? source.getMarkup?.(labelOwner(node.slot))
                        : null,
                    )}
                  </span>
                  <Prelude
                    selector={selector}
                    guide={guide}
                    className="daedalus-stylish-islands__prelude"
                  />
                </div>
              )}
              <div
                className={
                  selector !== null
                    ? 'daedalus-stylish-islands__part-body'
                    : undefined
                }
              >
                {islandsFor(node, key)}
              </div>
            </Fragment>
          ))}
        </div>
        );
      })}
    </div>
  );
};
