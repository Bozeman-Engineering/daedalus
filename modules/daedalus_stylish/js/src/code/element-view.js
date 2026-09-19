/* eslint-disable max-classes-per-file */
// Two CodeMirror WidgetType subclasses (the winner chip and the pending
// line's chrome) render the decorations this view module owns.
import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
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
import { linter } from '@codemirror/lint';
import {
  acceptCompletion,
  autocompletion,
  completionKeymap,
} from '@codemirror/autocomplete';
import {
  analyzeIsland,
  islandDelta,
  islandLines,
  islandOps,
  matchLines,
  printIsland,
} from './island.js';
import { ELEMENT_LAYER } from './layers.js';
import {
  createStitching,
  nextTabTarget,
  previousTabTarget,
  tabStops,
} from './stitching.js';
import {
  conditionRemovalOps,
  draftRoster,
  elementIslands,
  occupiedRoster,
  partGestureOptions,
  partRemovalOps,
  posedRoster,
  restoredLines,
  settleOps,
  shouldRestoreTinted,
  tagGestureOptions,
} from './element-model.js';
import {
  createIslandSurface,
  pendingMark,
  tooltipParent,
  winnerChip,
} from './island-surface.js';
import { lineMarks } from './line-marks.js';
import { lineMenuItems } from './line-menu.js';
import {
  carriedBy,
  documentBaselineLines,
  documentLineWinner,
  documentTree,
  overlaySessionLines,
  wearerWinners,
} from './variant-document.js';
import { lineTints as lineTintsStore } from '../line-tints.js';
import { useMenu } from '../menu.js';
import {
  axisVocabulary,
  declaredDefaultEntry,
  skinDefaultEntry,
} from '../variant-scope.js';
import {
  parseKey,
  bundleForKey,
  declarationForKey,
  resolvedConstraintsFor,
  declaredSets,
  definitionFor,
  baseTags,
  instanceTags,
  propertiesClosed,
  propertyGranted,
  KIND_LABELS,
} from '../style-guide.js';
import {
  animationOptions,
  scaleTokens,
  swatchOptions,
  tokenOptions,
} from '../materials.js';
import { KEYFRAMES_PROPERTIES } from '../keyframes-names.js';
import { widePropertyNames, wideValueKeywords } from './wide-tier.js';
import { partLabel, partsForKey } from '../parts.js';

/**
 * The per-element islands body (the page tree mounts one per element)
 * and the variant document view built on the same engine.
 *
 * Structure is chrome, never characters: the selected element renders as a
 * tree of Preact rows — its default declaration island, a WHEN row per
 * condition holding values, and a part row per styled sub-element — with
 * one island per (key, condition) pair holding a FLAT DECLARATION LIST
 * (element-model.js computes the roster; island.js is the
 * parse/validate/print/diff engine). Islands are printed markup by
 * default; exactly one, the one holding the cursor, is a live CodeMirror
 * instance (island-surface.js owns the swap), so the code view's cost is
 * bounded to one editor however big the page is.
 * Selectors, condition wrappers, and part headers cannot be typed here —
 * they MATERIALIZE from typing instead: `.` opens the declared-anatomy
 * completion and picking a part creates its chrome row + island (open
 * sites additionally offer an explicit "Create this part" confirm for an
 * undeclared class); `&`/`@` opens the condition vocabulary and picking
 * one creates its WHEN row + island; a bare declared instance tag typed
 * at a declaration position offers its TAG-TIER row ('{key}::p')
 * alongside the property completions (tagCompletions), materializing it
 * beside the part rows. Materialized rows are view-state
 * drafts until their first declaration commits. The inverse gesture, the
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
 * (no kind fits). Media-capable properties (<image> slots) bypass this
 * table: the value is free-typed grammar where a gradient swatch is the
 * whole layer and a color swatch is a legitimate stop, so both offer.
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
    ? null
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
 * Materialization-from-typing: the `.` / `&` / `@` gestures.
 *
 * A gesture token owns its line (nothing typed after the cursor): `.` on
 * an element island opens the declared-anatomy completion — parts are
 * REFERENCES into anatomy, typing cannot create markup — gated on DOM
 * presence (partGestureOptions: a declared part absent from the canvas is
 * not offered; rows already materialized from the style map persist
 * regardless, the store projection) — plus, on open
 * sites, one explicit "Create this part" entry for a well-formed
 * undeclared class (picking it IS the confirmation). `&` and `@` open the
 * condition vocabulary on any island. Picking an option REMOVES the typed
 * token and materializes the chrome row + island instead of inserting
 * text; structure never becomes characters.
 */
const gestureCompletions = (context, propsRef) => {
  const { guide, materialize } = propsRef.current;
  if (!materialize) {
    return null;
  }
  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);
  const after = line.text.slice(context.pos - line.from);
  if (after.trim() !== '') {
    return null;
  }

  const partMatch = materialize.parts
    ? /^(\s*)(\.[A-Za-z0-9_-]*)$/.exec(before)
    : null;
  if (partMatch) {
    const tokenFrom = line.from + partMatch[1].length;
    const { options, create } = partGestureOptions(
      partMatch[2],
      materialize.parts.declared,
      {
        closed: materialize.parts.closed,
        presence: materialize.parts.presence ?? null,
      },
    );
    const pick = (sub) => (view, completion, from, to) => {
      view.dispatch({ changes: { from: tokenFrom, to } });
      materialize.parts.onMaterialize(sub);
    };
    const completions = options.map((option) => ({
      label: option.sub,
      type: 'class',
      detail: option.label,
      apply: pick(option.sub),
    }));
    if (create) {
      completions.push({
        label: create,
        type: 'class',
        detail: Drupal.t('Create this part'),
        boost: -1,
        apply: pick(create),
      });
    }
    return completions.length
      ? {
          from: tokenFrom,
          options: completions,
          validFor: /^\.[A-Za-z0-9_-]*$/,
        }
      : null;
  }

  const conditionMatch = /^(\s*)[&@]([A-Za-z0-9_-]*)$/.exec(before);
  if (conditionMatch) {
    const tokenFrom = line.from + conditionMatch[1].length;
    const vocabulary = Object.entries(guide?.conditions ?? {});
    if (!vocabulary.length) {
      return null;
    }
    return {
      // The filter range starts past the sigil so typed text matches
      // condition names.
      from: tokenFrom + 1,
      options: vocabulary.map(([name, definition]) => ({
        label: name,
        type: 'keyword',
        detail: definition?.label,
        apply: (view, completion, from, to) => {
          view.dispatch({ changes: { from: tokenFrom, to } });
          materialize.condition(name);
        },
      })),
      validFor: /^[A-Za-z0-9_-]*$/,
    };
  }
  return null;
};

/**
 * Tag-row materialization from typing: the bare-tag half of the gestures.
 *
 * A tag has no sigil — a bare tag name IS how CSS spells it — so the
 * offer rides the property-completion position instead of owning a token
 * shape: at a declaration position (no ':' yet, nothing after the
 * cursor) on an element ROOT island, base's declared tags join the popup
 * alongside the property options (completionSource merges the two), each
 * gated on content presence (tagGestureOptions: a tag the element's prose
 * does not contain is not offered). Picking one removes the typed
 * word and materializes the '{key}::{tag}' row alongside the part rows —
 * structure never becomes characters, exactly the `.` gesture's rule.
 */
const tagCompletions = (context, propsRef) => {
  const { materialize } = propsRef.current;
  if (!materialize?.tags) {
    return null;
  }
  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);
  const after = line.text.slice(context.pos - line.from);
  if (after.trim() !== '' || before.includes(':')) {
    return null;
  }
  const word = context.matchBefore(/[a-z][a-z0-9-]*$/);
  if (!word && !context.explicit) {
    return null;
  }
  const offered = tagGestureOptions(
    materialize.tags.declared,
    materialize.tags.present ?? null,
  );
  if (!offered.length) {
    return null;
  }
  const from = word ? word.from : context.pos;
  return {
    from,
    options: offered.map((option) => ({
      label: option.tag,
      type: 'class',
      detail: Drupal.t('Every @label here', { '@label': option.label }),
      apply: (view, completion, applyFrom, to) => {
        view.dispatch({ changes: { from, to } });
        materialize.tags.onMaterialize(option.tag);
      },
    })),
    validFor: /^[a-z][a-z0-9-]*$/,
  };
};

/**
 * The one completion source: gestures first, then properties and tags.
 *
 * The `.`/`&`/`@` gestures own their token shapes and preempt. A bare
 * word is ambiguous — the start of a property OR a declared instance tag
 * — so the property completions and the tag-row offers merge into one
 * popup at the same anchor (the typed word), letting the editor's own
 * filtering rank them; an exact tag match ('p') naturally surfaces its
 * row offer beside the 'padding's.
 */
const completionSource = (context, propsRef) => {
  const gesture = gestureCompletions(context, propsRef);
  if (gesture) {
    return gesture;
  }
  const tags = tagCompletions(context, propsRef);
  const properties = guideCompletions(context, propsRef);
  if (tags && properties && tags.from === properties.from) {
    return {
      ...properties,
      options: [...properties.options, ...tags.options],
      validFor: /^[A-Za-z0-9-]*$/,
    };
  }
  return properties ?? tags;
};

/**
 * Resolves a stitch placement against an island's own buffer.
 *
 * Neighbors hand the cursor over with an intent, not a position — the
 * receiving island knows its own text: edges for horizontal arrival,
 * first/last line with the goal column for vertical arrival, first/last
 * tab stop for the Tab chain. Takes the state, not a view: a wake
 * resolves the placement against the freshly built state before any
 * EditorView exists.
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
 * `floor` is the defaults floor for this island's key ({skinEntry,
 * declaredEntry}, the raw condition-keyed entries), printed at the
 * bottom of the layered document at the island's exact condition
 * (islandLines); null prints the map's lines only. A floor line is
 * display, never a write target: editing it is a SET on the own layer,
 * its menu offers the lift and the skin doorway, never Unset.
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
  floor = null,
}) => {
  const containerRef = useRef(null);
  const surfaceRef = useRef(null);
  const session = useRef({});
  const [hasErrors, setHasErrors] = useState(false);
  // The right-click line menu (the shared primitive; line-menu.js
  // computes the items, the handlers below execute them).
  const menu = useMenu();
  const islandId = `${mapKey}|${condition}`;
  const rootPos = mapKey.indexOf('::');
  const rootKey = rootPos === -1 ? mapKey : mapKey.slice(0, rootPos);
  const relative = mapKey.slice(rootKey.length);

  const floorSignature = floor ? JSON.stringify(floor) : '';

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
    floor,
  };

  // The wearer cascade's winners for this island — the document honesty
  // read: a sheet line (or pending set) a higher layer beats on the
  // anchoring wearer strikes through with the winner named, so the buffer
  // never promises a look Push cannot deliver here. An unanchored
  // document island (nothing on this page carries the fragment) has no
  // wearer cascade to read.
  const currentWinners = () => {
    const {
      styleMap: map,
      variants: library,
      axes: currentAxes,
      sheet: sheetCtx,
    } = propsRef.current;
    if (sheetCtx && !sheetCtx.anchored) {
      return new Map();
    }
    return wearerWinners(
      islandLines(
        map ?? {},
        mapKey,
        condition,
        library ?? [],
        currentAxes,
        propsRef.current.floor ?? null,
      ),
    );
  };

  // The island's document. Element mode prints the wearer's layered
  // document; a document island (the editing session's body) prints the
  // VARIANT'S OWN SHEET at its document key with the session's staged
  // lines rendered in place (pending marks), wearer-context shadowing
  // overlaid for display only.
  const currentLines = () => {
    const {
      styleMap: map,
      variants: library,
      axes: currentAxes,
      sheet: sheetCtx,
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
    return islandLines(
      map ?? {},
      mapKey,
      condition,
      library ?? [],
      currentAxes,
      propsRef.current.floor ?? null,
    );
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
  const analyze = (text) =>
    analyzeIsland(text, {
      guide: propsRef.current.guide,
      materials: propsRef.current.materials,
      declaration: propsRef.current.declaration,
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
      ),
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

  // Provenance and the shadowing story, as decorations over the plain
  // buffer. Buffer declarations pair with baseline lines first-fit
  // (matchLines); an edited line matches nothing and prints plain — the
  // override-in-the-making. What each matched line wears comes from the
  // one marks model (line-marks.js) both postures share; this side only
  // turns marks into Decoration.line + widgets.
  const redecorate = (view, analysis = null) => {
    const currentAnalysis = analysis ?? analyze(view.state.doc.toString());
    const { variants: library, sheet: sheetCtx } = propsRef.current;
    const matched = matchLines(
      currentAnalysis.declarations,
      session.current.lines ?? [],
    );
    const marks = lineMarks(matched, {
      library: library ?? [],
      sheet: Boolean(sheetCtx),
      tints: lineTintsStore.get(),
    });
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
  const marksFor = (lines) =>
    lineMarks(lines, {
      library: propsRef.current.variants ?? [],
      sheet: Boolean(propsRef.current.sheet),
      tints: lineTintsStore.get(),
    });

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

  // Re-marks the standing print without changing its text (a decoration-
  // state change, the printed twin of redecorate): same printed lines,
  // marks re-derived.
  const reprintMarks = () => {
    surfaceRef.current?.print(
      (session.current.printed ?? '').split('\n'),
      marksFor(session.current.printedLines ?? session.current.lines ?? []),
    );
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

  // The line menu on the print, WITHOUT waking: the line index comes from
  // the DOM target (data-line), the line from the print's stored shapes.
  // The live view keeps its own posAtCoords path above; a live island's
  // contextmenu bubbles here too, so this half stands down when live.
  const openPrintLineMenu = (event) => {
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
          linter(
            (lintView) => {
              const docLength = lintView.state.doc.length;
              return analyze(lintView.state.doc.toString()).diagnostics.map(
                (diagnostic) => ({
                  from: Math.min(diagnostic.from, docLength),
                  to: Math.min(diagnostic.to, docLength),
                  severity: diagnostic.severity,
                  message: diagnostic.message,
                }),
              );
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
  // from the gesture into the new row's declarations (a wake, at the
  // island's start).
  useEffect(() => {
    if (autoFocus) {
      surfaceRef.current?.wake({ anchor: 0 });
    }
  }, [autoFocus]);

  // Decoration-state changes re-decorate in place: no doc change, no
  // text change (the printed posture re-derives its marks over the same
  // text). The signature keys on content (whether variant tints are on)
  // so a parent re-render with an identical state costs nothing.
  const [tints, setTints] = useState(lineTintsStore.get());
  useEffect(() => lineTintsStore.subscribe(setTints), []);
  const decorationSignature = `${tints}`;
  useEffect(() => {
    const live = surfaceRef.current?.live;
    if (live) {
      redecorate(live);
    } else if (surfaceRef.current) {
      reprintMarks();
    }
  }, [decorationSignature]);

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
    }
    // The deferred variant-line-restore case: a deletion no-oped while other
    // edits were in flight; now that they've committed and the baseline
    // caught up, the boundary that skipped can complete.
    restorePass(view);
    redecorate(view);
    // The document session is input too: a dismissed (or staged)
    // pending line re-prints the sheet exactly like an authoritative map
    // refresh — when the buffer is clean; never under the user's cursor.
    // The floor is input by content (the entries are rebuilt per render;
    // the signature keys on what they say), so a row becoming the
    // selection re-prints with its floor, and a tag change re-prints
    // the base:{tag} layer.
  }, [styleMap, variants, sheet?.session ?? null, floorSignature]);

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

  return (
    <div
      className={`daedalus-stylish-island${hasErrors ? ' daedalus-stylish-island--invalid' : ''}${posed ? ' daedalus-stylish-island--posed' : ''}`}
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
 * sparse for the rest). Owns the materialization drafts: rows the `.`/`&`/`@` gestures created that
 * hold no committed values yet. Drafts are view state keyed to the element
 * (an element change discards them); once a draft row's first declaration
 * commits, the map-derived roster surfaces the row itself and the draft
 * merges away (draftRoster deduplicates).
 *
 * The WHEN rows are also code view's pose surface (the widget modes'
 * Styled-at, one level closer to the styles): clicking a row drives the
 * canvas to its compound (`onPose`, the Inspector's driveCanvas), and the
 * island matching the posed compound (`posed`, derived from the canvas
 * vector + force state) wears the POSED marker — POSED, not EDITING HERE:
 * islands address their own conditions, so the pose only picks what the
 * canvas previews.
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
 * The defaults floor (the active skin's defaults over the declaration's
 * own, the tier every widget mode reads through provenanceFor) prints on
 * the SELECTED element's islands only, DevTools' rule (inherited rules
 * for the element you are inspecting): sparse rows print the map's lines,
 * so the page scope stays readable and the occupancy read is untouched.
 * Each island's floor is read for its own key (a part island reads the
 * part's scope) with the rendered tag `source.readCanvasTag` answers for
 * the selection and that part (the satellite answers for the selection
 * from the leader's relayed tags, null elsewhere, so the base:{tag} layer
 * degrades rather than over-claims).
 *
 * Part headers ride the selection spine like the tree's part rows do:
 * `selectedPart` (the spine facet, passed only when this element IS the
 * selection) wears the active mark, hovering a header drives the
 * hoverPart facet through the source, and clicking one selects
 * (owner key, part) — parts at the same two-way sync the element rows
 * already have. The hot mark paints imperatively from the page tree
 * (page-view.js), addressed by the headers' data attributes.
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
  selectedPart = null,
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

  const materializePart = (sub) =>
    withDrafts((state) => ({
      ...state,
      parts: state.parts.includes(sub) ? state.parts : [...state.parts, sub],
      focus: `${selected}::${sub}|default`,
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
        label: Drupal.t('Removed WHEN @condition styles', {
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
        label: Drupal.t('Removed @part styles', { '@part': part.sub }),
      });
    }
    withDrafts((state) => {
      const conditions = { ...state.conditions };
      delete conditions[part.key];
      return {
        ...state,
        conditions,
        parts: state.parts.filter((sub) => sub !== part.sub),
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

  const baseModel = elementIslands(
    styleMap ?? {},
    selected,
    guide,
    library,
    axes,
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

  // Part materialization is the element's gesture (parts do not nest);
  // condition materialization belongs to every island.
  // The `.` offer is an element projection, so it carries the DOM-presence
  // facts (layout context self.parts) for partGestureOptions' gate. The
  // facts describe the CANVAS SELECTION only, so they join in just when
  // this element is it; any other element's islands (a styled-but-
  // unselected page-tree row) have no facts and degrade to offering, the
  // gates' unknown-context posture. The rows already materialized below
  // (model.parts, off the style map) are the store projection and ignore
  // presence by construction.
  const partGestures = {
    declared: partsForKey(guide, selected, blockTypes, layoutsTable),
    closed: propertiesClosed(guide),
    presence:
      selected === source.getSelected?.()
        ? (source.getLayoutContext?.()?.self?.parts ?? null)
        : null,
    onMaterialize: materializePart,
  };
  // Tag rows materialize alongside part rows through the same draft lane
  // (a tag row IS a sub row: materializePart drafts '{key}::p' exactly
  // like '{key}::.quote'). The offer is the site's base tag vocabulary
  // (the one declaration of a prose tag's styleability), each labelled
  // by its identity label where the instance lane declares one and by
  // the bare tag otherwise (links never mint), gated on the element's
  // markup annotation: its `tags` fact (the tag names the prose contains,
  // minting or not) is reachable for any key the model speaks for, so no
  // canvas-selection join is needed (the satellite's null annotation
  // degrades to offering, tagGestureOptions' rule).
  const tagLabels = instanceTags(guide?.instances);
  const tagGestures = {
    declared: Object.fromEntries(
      baseTags(guide?.base).map((tag) => [tag, tagLabels[tag] ?? tag]),
    ),
    present: source.getMarkup?.(selected)?.tags ?? null,
    onMaterialize: materializePart,
  };

  // The POSED marker matches non-default compounds only: the default
  // condition is the canvas's resting pose, and marking every default
  // island at rest would say nothing.
  const isPosed = (condition) => condition === posed && condition !== 'default';

  // The defaults floor for one of this element's islands, the selection's
  // full body only (the docblock's rule): the same entries provenanceFor
  // builds, keyed by the island's own key, the tag read for its part.
  const floorFor = (mapKey) => {
    if (sparse) {
      return null;
    }
    const pos = mapKey.indexOf('::');
    const sub = pos === -1 ? null : mapKey.slice(pos + 2);
    const tag = source.readCanvasTag?.(selected, sub) ?? null;
    return {
      skinEntry: skinDefaultEntry(guide, mapKey, blockTypes, layoutsTable, tag),
      declaredEntry: declaredDefaultEntry(
        guide,
        mapKey,
        blockTypes,
        layoutsTable,
      ),
    };
  };

  const islandsFor = (mapKey, conditions) =>
    conditions.map((condition) => (
      <Fragment key={`${mapKey}|${condition}`}>
        {condition !== 'default' && (
          <div
            className={`daedalus-stylish-islands__when${isPosed(condition) ? ' daedalus-stylish-islands__when--posed' : ''}`}
          >
            {/* The pose gesture: chrome never takes the cursor — the
              mouse's gesture, skipped by the keyboard chain. */}
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__when-pose"
              title={Drupal.t(
                'Pose the canvas at "@condition" to preview these styles',
                { '@condition': conditionLabel(condition) },
              )}
              onClick={() => onPose?.(condition)}
            >
              <span className="daedalus-stylish-islands__when-keyword">
                {Drupal.t('WHEN')}
              </span>
              <span className="daedalus-stylish-islands__when-condition">
                {conditionLabel(condition)}
              </span>
            </button>
            {/* The canvas stands here: this island's styles render. */}
            {isPosed(condition) && (
              <span className="daedalus-stylish-islands__posed-tag">
                {Drupal.t('POSED')}
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
          declaration={declarationOf(mapKey)}
          mapKey={mapKey}
          condition={condition}
          styleMap={styleMap}
          variants={library}
          axes={axes}
          materialize={{
            parts: mapKey === selected ? partGestures : null,
            tags: mapKey === selected ? tagGestures : null,
            condition: (name) => materializeCondition(mapKey, name),
          }}
          autoFocus={drafts.focus === `${mapKey}|${condition}`}
          stitch={stitching}
          posed={isPosed(condition)}
          floor={floorFor(mapKey)}
        />
      </Fragment>
    ));

  return (
    <div className="daedalus-stylish-islands">
      {islandsFor(selected, model.conditions)}
      {model.parts.map((part) => (
        <Fragment key={part.key}>
          {/* The part header is the part's chrome row on the selection
              spine, the page tree rows' pattern one level down: hovering
              it drives the hoverPart FACET (never the element hover — a
              part header must not light its owner's solid outline; the
              tree part rows' rule), leave clears only a facet this header
              set, and clicking selects (owner key, part) — the code twin
              of a tree part-row click. Active reads from the selectedPart
              prop (spine state); the hot mark is painted imperatively by
              the page tree (page-view.js) through the data attributes,
              hover's pointer-speed rule. */}
          <div
            className={`daedalus-stylish-islands__part${part.sub === selectedPart ? ' daedalus-stylish-islands__part--active' : ''}`}
            data-daedalus-stylish-part-owner={selected}
            data-daedalus-stylish-part={part.sub}
            onMouseEnter={() => source.hoverPart?.(selected, part.sub)}
            onMouseLeave={() => {
              const current = source.getHoveredPart?.();
              if (current?.key === selected && current?.part === part.sub) {
                source.hoverPart?.(null);
              }
            }}
          >
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__part-select"
              title={part.key}
              aria-pressed={part.sub === selectedPart}
              onClick={() => source.select?.(selected, part.sub)}
            >
              <span className="daedalus-stylish-islands__part-label">
                {partLabel(
                  guide,
                  selected,
                  part.sub,
                  blockTypes,
                  layoutsTable,
                  source.getMarkup?.(selected),
                )}
              </span>
              <code className="daedalus-stylish-islands__part-selector">{part.sub}</code>
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
            {islandsFor(part.key, part.conditions)}
          </div>
        </Fragment>
      ))}
    </div>
  );
};

/**
 * The editing session's code body: the edited variant's WHOLE document
 * as a root + children view (four controls, one loud mode).
 *
 * One chrome row per document SLOT in documentTree order — the root
 * first and always present (the honest edit surface even an empty
 * document offers), each slot's part and '::>' rows under it, and a
 * slot-path tree for a composition's children — with one island per
 * (document key, condition) beneath. Every island prints the variant's
 * OWN sheet with the open session's staged lines rendered in place
 * (pending marks) and stages its settles back into the session through
 * `document.stage`; the Push/Discard bar is the only commit surface.
 *
 * Wearer context: each fragment's islands anchor on a current-page
 * carrier of that fragment (carriedBy — the selection wins when it
 * carries it), which is where typed previews land and what the shadowing
 * overlay (winner dots) reads. A fragment nothing on this page carries
 * still renders — a variant's reach is site-wide — but its "carried by"
 * chrome says so and its islands stage without previewing (no canvas
 * element could honestly show the line).
 *
 * @param {object} props.document
 *   The document context the Inspector builds: {reference, styles,
 *   session, rootKind, stage(slot, condition, lines), dismiss(id)}.
 */
export const VariantDocumentView = ({
  source,
  guide,
  materials,
  selected,
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
  // One stitching registry over the whole document, so the keyboard
  // chain crosses slot boundaries in document order.
  const stitchRef = useRef(null);
  if (!stitchRef.current) {
    stitchRef.current = createStitching();
  }
  // Materialized-but-uncommitted WHEN rows on document keys (the `@`
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

  // The anchoring carrier for a fragment: the selection when it carries
  // the fragment (the entered-from wearer), else the first carrier in map
  // order, else none.
  const carrierOf = (fragment) => {
    const keys = carriers.get(fragment) ?? [];
    return keys.includes(selected) ? selected : (keys[0] ?? null);
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
        : keys.includes(selected)
          ? Drupal.t('carried by this element')
          : Drupal.formatPlural(
              keys.length,
              'carried by 1 element here',
              'carried by @count elements here',
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
    const anchored = carrier !== null;
    const mapKey = anchored ? carrier + suffix : `~${key}`;
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
    return conditionsFor(key).map((condition) => (
      <Fragment key={`${key}|${condition}`}>
        {condition !== 'default' && (
          <div
            className={`daedalus-stylish-islands__when${isPosed(condition) ? ' daedalus-stylish-islands__when--posed' : ''}`}
          >
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-islands__when-pose"
              title={Drupal.t(
                'Pose the canvas at "@condition" to preview these styles',
                { '@condition': conditionLabel(condition) },
              )}
              onClick={() => onPose?.(condition)}
            >
              <span className="daedalus-stylish-islands__when-keyword">
                {Drupal.t('WHEN')}
              </span>
              <span className="daedalus-stylish-islands__when-condition">
                {conditionLabel(condition)}
              </span>
            </button>
            {isPosed(condition) && (
              <span className="daedalus-stylish-islands__posed-tag">
                {Drupal.t('POSED')}
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
        />
      </Fragment>
    ));
  };

  return (
    <div className="daedalus-stylish-document">
      {tree.map((node) => (
        <div
          key={node.slot || 'root'}
          className="daedalus-stylish-document__slot"
          style={
            node.depth ? { '--daedalus-stylish-document-depth': node.depth } : undefined
          }
        >
          <div className="daedalus-stylish-document__header">
            <span className="daedalus-stylish-document__kind">
              {node.slot === ''
                ? Drupal.t('Root')
                : node.kind === 'container'
                  ? Drupal.t(KIND_LABELS.container)
                  : node.kind === 'block'
                    ? Drupal.t('Block')
                    : node.kind === 'field'
                      ? Drupal.t('Field')
                      : Drupal.t('Slot')}
            </span>
            {node.detail && (
              <code className="daedalus-stylish-document__detail">{node.detail}</code>
            )}
            {node.slot !== '' && node.kind === null && (
              <code className="daedalus-stylish-document__detail">{node.slot}</code>
            )}
            {carriedChip(node.slot)}
          </div>
          {node.keys.map(({ key, part, children }) => (
            <Fragment key={key}>
              {(part !== null || children) && (
                <div className="daedalus-stylish-islands__part">
                  <span className="daedalus-stylish-islands__part-label">
                    {children
                      ? Drupal.t('Children')
                      : carrierOf(node.slot)
                        ? partLabel(
                            guide,
                            carrierOf(node.slot),
                            part,
                            blockTypes,
                            layoutsTable,
                            source.getMarkup?.(carrierOf(node.slot)),
                          )
                        : part}
                  </span>
                  <code className="daedalus-stylish-islands__part-selector">
                    {children ? '::>' : part}
                  </code>
                </div>
              )}
              <div
                className={
                  part !== null || children
                    ? 'daedalus-stylish-islands__part-body'
                    : undefined
                }
              >
                {islandsFor(node, key)}
              </div>
            </Fragment>
          ))}
        </div>
      ))}
    </div>
  );
};
