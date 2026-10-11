/**
 * Value mapping for the inspector's widgets (background, corners_border,
 * position, text_alignment, typography, text_color, spacing_scale,
 * width, the advanced rows): style-map values ⇄ stop vocabularies —
 * segmented-button rows for iconic enums and preset pairs, and the ONE
 * fallback vocabulary per property (FALLBACKS + fallbackFor) behind
 * every field row in both modes (value-space.js rides propertyStops).
 *
 * Pure and preact-free on purpose: this is where the custom-state honesty
 * rule lives as testable logic (tests/js/widget-values.test.mjs runs under
 * node). A widget's stops are its whole vocabulary — a committed value
 * either maps to exactly one stop (the button lights up) or it doesn't,
 * and then the state is EXPLICITLY custom: never "nothing set", never
 * silently normalized. The components in widgets/ render these states;
 * they add no mapping of their own.
 *
 * The properties these tables drive are the PHP plugins' owned-property
 * lists (src/Plugin/ControlWidget/*), which the control contract's
 * guide-build validation enforces. A widget wanting to write a property
 * not owned there must update the attribute FIRST.
 *
 * Stop shape: {id, value, matches, label}
 * - `value` is the canonical CSS value a click commits.
 * - `matches` is the set of committed values the stop honestly REPRESENTS
 *   (aliases like 'start' for 'flex-start' — an AI or advanced-mode write
 *   of an equivalent value lights the button). Matching never rewrites:
 *   toggling a lit button off unsets; only picking a stop writes its
 *   canonical value.
 * - `label` is an untranslated string; components wrap it in Drupal.t()
 *   (this module runs under node where Drupal.t does not exist).
 * - `paint` (stopsFor-derived stops) is the display value a swatch face
 *   paints, straight from the material record — never a var() the browser
 *   would have to resolve. Stops without it paint their `value` (Segments).
 * - `paintDark` (stopsFor-derived stops) is the scheme-paired display value beside
 *   `paint` (schemeDisplayValue), null for single-valued stops. A stop
 *   carrying it renders as a split face (default half / dark half); the
 *   same no-resolution rule applies.
 */

import {
  animationOptions,
  materialOf,
  scaleTokens,
  swatchOptions,
  tokenOptions,
} from './materials.js';
import { scaleRow } from './style-guide.js';

/**
 * Normalizes a committed value for stop matching.
 *
 * null/undefined/blank mean "not set". CSS keyword comparison is
 * case-insensitive; whitespace runs collapse so shorthand values compare
 * structurally ('0.5rem  0.5rem' === '0.5rem 0.5rem').
 *
 * @param {*} value
 *
 * @return {?string}
 */
export const normalizeValue = (value) => {
  if (value === null || value === undefined) {
    return null;
  }
  const normalized = String(value).trim().toLowerCase().replace(/\s+/g, ' ');
  return normalized === '' ? null : normalized;
};

/**
 * Shared alignment axis labels, one set per ORIENTATION — which property
 * means "horizontal" depends on the layout mode in effect, so labels
 * follow the screen axis, not the property. Used by the position widget's
 * axes and mirrored by the layout widget's alignment rows
 * (layout-widget.js, the folded-in container half of the Figma split).
 */
const AXIS_LABELS = {
  horizontal: ['Left', 'Center', 'Right'],
  vertical: ['Top', 'Middle', 'Bottom'],
};

/**
 * The position widget's stop values for its alignment properties, one
 * table per property (canonical write + the aliases a stop honestly
 * represents).
 *
 * Canonical writes are the modern start/end spellings, which every
 * current engine resolves in flex and grid containers alike. The flex
 * legacy spellings and the self-* forms are aliases; 'stretch', 'baseline'
 * and 'auto' stay custom, per the honesty rule. left/right exist only for
 * justify-self (they are not valid align-self values).
 */
const POSITION_VALUES = {
  'align-self': [
    ['start', ['start', 'flex-start', 'self-start']],
    ['center', ['center']],
    ['end', ['end', 'flex-end', 'self-end']],
  ],
  'justify-self': [
    ['start', ['start', 'self-start', 'left']],
    ['center', ['center']],
    ['end', ['end', 'self-end', 'right']],
  ],
};

const positionAxis = (orientation, property, write) => ({
  label: orientation === 'horizontal' ? 'Horizontal' : 'Vertical',
  orientation,
  property,
  write,
  stops: POSITION_VALUES[property].map(([value, matches], index) => ({
    id: ['start', 'center', 'end'][index],
    value,
    matches,
    label: AXIS_LABELS[orientation][index],
  })),
});

/**
 * The margin axis: a flex column's main-axis placement, spelled as the
 * child's auto margins.
 *
 * In a flex container free space on the main axis goes to auto margins
 * before justify-content (CSS Flexbox 8.1), so `margin-bottom: auto`
 * pins a stacked child to the top of a tall band, `margin-top: auto` to
 * its bottom, and both center it. No single child property says this
 * (justify-self is ignored by flex), so the axis writes the PAIR: each
 * stop's pair (MARGIN_PAIRS, by stop id) is what a pick sets on the two
 * sides, `auto` on the side that takes the free space and null on the
 * other, which only clears a standing `auto` there (marginAxisWrite: a
 * typed margin on the other side is left alone, never overwritten by a
 * placement). The row's `value` is the placement token marginAxisValue
 * reads from which sides are `auto` (a stop's `value` and `matches`, so
 * the row's state maps through segmentState like every other axis),
 * never a CSS spelling: `property` names the pair for the row's identity
 * and is not written. The pair lives here by id rather than on the stop
 * because the choice row's offer (choice-row-model.js choiceStops) is a
 * projection of the stop table and hands a pick back by id.
 */
const MARGIN_PAIR = ['margin-top', 'margin-bottom'];

const MARGIN_PAIRS = {
  start: { 'margin-top': null, 'margin-bottom': 'auto' },
  center: { 'margin-top': 'auto', 'margin-bottom': 'auto' },
  end: { 'margin-top': 'auto', 'margin-bottom': null },
};

const marginAxis = (orientation) => ({
  label: orientation === 'horizontal' ? 'Horizontal' : 'Vertical',
  orientation,
  property: 'margin-block',
  pair: MARGIN_PAIR,
  write: 'local',
  stops: Object.keys(MARGIN_PAIRS).map((id, index) => ({
    id,
    value: id,
    matches: [id],
    label: AXIS_LABELS[orientation][index],
  })),
});

const marginAuto = (values, side) => normalizeValue(values?.[side]) === 'auto';

/**
 * The margin axis's shown value: which stop the child's auto margins
 * light, read from the pair.
 *
 * `margin-bottom: auto` alone is Top, `margin-top: auto` alone is
 * Bottom, both are Middle; neither is unset. A numeric margin on either
 * side is not a placement and never reads as custom: the axis owns only
 * the `auto` spelling, so a typed 2rem beside an auto is left alone by
 * the read exactly as by the write.
 *
 * @param {Object<string, ?string>} values
 *   The widget's shown values (margin-top and margin-bottom among them).
 *
 * @return {?string}
 *   The stop id ('start', 'center', 'end') or null when unset.
 */
export const marginAxisValue = (values) => {
  const top = marginAuto(values, 'margin-top');
  const bottom = marginAuto(values, 'margin-bottom');
  if (top && bottom) {
    return 'center';
  }
  if (top) {
    return 'end';
  }
  if (bottom) {
    return 'start';
  }
  return null;
};

/**
 * The margin axis's write for a pick: the stop's pair, with a null
 * kept only where it clears a standing `auto`.
 *
 * A stop sets `auto` on its side(s) and clears the other side's `auto`
 * (Middle to Top must drop the top auto); a null pick (unset, the lit
 * stop clicked) clears every `auto`. A side that reads anything else (a
 * typed margin, nothing) is omitted: the placement never overwrites a
 * margin it did not write, and a null over nothing would remove nothing.
 *
 * @param {?string} id
 *   The picked stop's id ('start', 'center', 'end'), or null for unset.
 * @param {Object<string, ?string>} values
 *   The widget's shown values at the pose.
 *
 * @return {Object<string, ?string>}
 *   The properties to commit as one gesture; empty when nothing changes.
 */
export const marginAxisWrite = (id, values) => {
  const pair =
    MARGIN_PAIRS[id] ??
    Object.fromEntries(MARGIN_PAIR.map((side) => [side, null]));
  return Object.fromEntries(
    Object.entries(pair).filter(
      ([side, value]) => value !== null || marginAuto(values, side),
    ),
  );
};

/**
 * The position widget's two axes for the selection's parent context: the
 * child-positions-itself half of the Figma split (the layout widget's
 * alignment rows are the container half).
 *
 * The child's vocabulary is align-self + justify-self plus the auto-margin
 * pair; what varies with the parent's layout mode is which screen axis
 * each property means and whether a write can take effect (`write`):
 * - 'local': the property places the child right now, a plain own-key
 *   write (the margin axis writes its pair, still on the child's key).
 * - 'none': the parent owns this axis (a flex row's main axis
 *   distributes children via justify-content; no child property exists),
 *   or its display mode places nothing. The row renders disabled, so a
 *   committed value stays visible, never silently broken.
 *
 * Modes:
 * - flex column parent (every stacked container by the declared tier):
 *   align-self is the horizontal (cross) axis; the vertical (main) axis
 *   is the margin axis, the flex spec's own per-child main-axis
 *   placement.
 * - flex row parent (Columns): align-self is the vertical cross axis;
 *   justify-self is ignored by flex and takes the horizontal axis as
 *   'none', so a committed value still has a row.
 * - grid parent (a hand-made grid): both axes local; align-self
 *   vertical, justify-self horizontal.
 * - plain block parent ('block', a field inside a block wrapper) and any
 *   other display (inline, table, ...): both axes 'none'. Nothing places
 *   a child there and no upgrade rides in: a container is always flex
 *   by declaration, the Layout section (container-only) writes no
 *   display, and a hand-written display is the code view's; the rows
 *   state the fact and prescribe no gesture.
 * - unknown parent (null facts): both axes local. The registry gate
 *   (positionGate) hides the widget here unless a value is set, and a set
 *   value must stay visible and unsettable: plain local writes.
 *
 * @param {?{display: string, flexDirection: string}} parent
 *   The selection's parent facts (source.getLayoutContext()?.parent).
 *
 * @return {{horizontal: object, vertical: object}}
 */
export const positionAxes = (parent) => {
  const display = normalizeValue(parent?.display);
  if (display === 'flex' || display === 'inline-flex') {
    const direction = normalizeValue(parent?.flexDirection) ?? 'row';
    return direction.startsWith('column')
      ? {
          horizontal: positionAxis('horizontal', 'align-self', 'local'),
          vertical: marginAxis('vertical'),
        }
      : {
          horizontal: positionAxis('horizontal', 'justify-self', 'none'),
          vertical: positionAxis('vertical', 'align-self', 'local'),
        };
  }
  const write =
    display === null || display === 'grid' || display === 'inline-grid'
      ? 'local'
      : 'none';
  return {
    horizontal: positionAxis('horizontal', 'justify-self', write),
    vertical: positionAxis('vertical', 'align-self', write),
  };
};

/**
 * The position widget's dynamic gate: is the parent context KNOWN?
 *
 * Every position row is parent-relative (which axis a property means,
 * whether a write can take effect), so with no parent facts every row
 * would be a guess. Unlike textGate this does NOT degrade to offering on
 * unknown (the widget has no context-free row the way text_alignment has
 * its text row); a set value still offers through the registry's
 * value-set OR, rendered as plain local writes.
 *
 * @param {?object} self
 *   The selection's own facts (unused; the offer rule passes them first).
 * @param {?{display: string}} parent
 *   The selection's parent facts (layout.parent), or null when unknown.
 *
 * @return {boolean}
 */
export const positionGate = (self, parent) =>
  normalizeValue(parent?.display) !== null;

/**
 * The "controlled by <parent>" read: container-driven alignment the child
 * did not write.
 *
 * When the child has no local align-self (or defers with 'auto') and the
 * parent's computed align-items actually places children ('normal' and
 * 'stretch' are the browser defaults, i.e. nothing chosen), the position
 * on this axis is the parent's doing. The widget shows that as
 * attribution, the extension of the honesty rule: "Custom" is a value the
 * widget cannot represent, "controlled by <parent>" is a value it does
 * not own. A local pick stays available, since align-self overrides
 * align-items per spec.
 *
 * @param {?{alignItems: string}} parent
 *   The selection's parent facts (source.getLayoutContext()?.parent).
 * @param {*} localValue
 *   The child's shown align-self (the winner at the pose, controls.js
 *   litValues over the model's reads, with the in-flight draft over it:
 *   useGesture's `values`).
 * @param {Array<{id: string, value: string, matches: string[]}>} stops
 *   The align-self axis's stop row (positionAxes(parent) — the axis whose
 *   property is align-self).
 *
 * @return {?{selected: ?string, value: string}}
 *   The stop the parent's value maps to (null when unrepresentable, e.g.
 *   'baseline') plus the raw value for attribution copy, or null when the
 *   parent is not controlling this axis.
 */
export const parentAlignment = (parent, localValue, stops) => {
  const local = normalizeValue(localValue);
  if (local !== null && local !== 'auto') {
    return null;
  }
  const value = normalizeValue(parent?.alignItems);
  if (value === null || value === 'normal' || value === 'stretch') {
    return null;
  }
  const stop = stops.find((candidate) => candidate.matches.includes(value));
  return { selected: stop?.id ?? null, value };
};

/**
 * Dynamic gates: the canvas-applicability half of the offer rule.
 *
 * A control is OFFERED when it is guide-sanctioned AND canvas-applicable,
 * OR when a value is already set — a set value must always be visible and
 * unsettable; hidden-with-value-set must never happen. Gated off means
 * HIDDEN, never disabled: a disabled row is noise in lay mode, and
 * advanced mode always has the property. These functions answer ONLY the
 * applicability question, from the selection's own facts (layout.self);
 * the value-set half and the OR live in the pipeline (controls.js
 * offered()), so a widget author cannot forget them.
 *
 * Unknown facts (null self: no canvas element for the key, a satellite
 * before its first broadcast) read as applicable — degrade to offering,
 * the pre-gate behavior, rather than hiding controls on a guess.
 */

/**
 * Whether text-styling widgets apply to the selection: does it carry any
 * real text a visitor would see? The text_alignment and typography gate;
 * text color reuses it when it lands.
 *
 * @param {?{hasText: boolean}} self
 *   The selection's own facts (layout.self), or null when unknown.
 *
 * @return {boolean}
 */
export const textGate = (self) => !self || self.hasText;

/**
 * A material reference as a stop: the swatch chip commits the record's
 * style spelling (its var() reference, as the value grammar spells it).
 *
 * The stop honestly represents ONLY the reference spelling — a literal
 * equal to the material's current resolution is a different statement
 * (it will not track a swatch edit or a skin swap), so it stays custom.
 * `paint` carries the display value straight from the record: the face
 * never relies on var() resolution, because the panel DOM may sit outside
 * the token scope; `paintDark` is its scheme pair (null for single-valued
 * materials), same rule. `group` is the palette-side grouping label (a
 * scale's label, a swatch kind's label) the swatch-well popover sections
 * by; flat renderers ignore it.
 */
const referenceStop = (ref, label, paint, paintDark = null, group = null) => ({
  id: ref,
  value: ref,
  matches: [normalizeValue(ref)],
  label,
  paint,
  paintDark,
  group,
});

/**
 * A guide `allowed:` literal as a stop, painted from palette data.
 *
 * The guide's EXACT spelling is the canonical write (allowed checks are
 * verbatim on both validators). A literal paints as itself; an allowed
 * entry that is itself a material reference (a var() the guide curated
 * into the list, the grammar's whole-value read) paints from the
 * referenced token's or swatch's display value (scheme pair included),
 * same no-resolution rule as everywhere. A plain literal never carries a
 * pair: it is one value under every scheme. A value a `fallback` stop
 * honestly represents adopts that stop's id, label and aliases (an
 * allowed '.5rem' lights as S, and an AI's committed '0.5rem' still
 * lights it); anything else labels as itself.
 */
const literalStop = (guide, materials, raw, fallback = []) => {
  const value = raw === null || raw === undefined ? '' : String(raw).trim();
  const normalized = normalizeValue(value);
  const material = materialOf(guide, materials, value);
  let id = normalized;
  let matches = [normalized];
  let paint = value;
  let paintDark = null;
  let label = value;
  if (material !== null) {
    paint = material.value;
    paintDark = material.valueDark;
    label = material.record.kind === 'swatch' ? material.label : material.name;
  } else {
    const twin = fallback.find((stop) => stop.matches.includes(normalized));
    if (twin) {
      id = twin.id;
      label = twin.label;
      matches = [...new Set([...twin.matches, normalized])];
    }
  }
  return { id, value, matches, label, paint, paintDark };
};

/**
 * Collapses equivalent spellings into the first occurrence, so a
 * redundant list never yields two stops for one value (an alias shared
 * with an earlier stop is the same value respelled).
 */
const dedupe = (stops) => {
  const deduped = [];
  stops.forEach((stop) => {
    if (
      stop.id !== null &&
      !deduped.some((existing) =>
        existing.matches.some((alias) => stop.matches.includes(alias)),
      )
    ) {
      deduped.push(stop);
    }
  });
  return deduped;
};

/**
 * A row's constraint-honest stop vocabulary: the ONE derivation behind
 * every stop-table widget (the guide constraint is the per-property
 * switch; widgets own gestures, never vocabularies). Three tiers, the
 * same closed-space rules both validators enforce, so a stop never
 * commits a value the server rejects:
 * - A `scale:` constraint closes the row to that scale's rostered tokens
 *   as var()-committing reference stops, labeled by display VALUE on a
 *   dimension or number scale (10px reads; the value-space face rule,
 *   stopFace) and by token NAME on every other shape (sm/md/lg on a
 *   shadow scale, because shadow CSS does not read; color rows paint
 *   faces from the same stop shape and the name carries what the paint
 *   cannot) and lit by the reference spelling ONLY (a literal equal to a
 *   token's current resolution is a different statement: it will not
 *   track a skin swap, so it stays custom). WHICH of the minted tokens
 *   the row offers is scaleRow (style-guide.js, the roster's
 *   scaleStops() twin): the element's curated `stops:` in list order
 *   where its declaration lists them (names the roster does not carry
 *   drop; the scale's offered tokens stand in when none survive), else
 *   the scale's tokens the skin marks `offered`, in stored order; on a
 *   curated row the site-minted tokens follow while offered on open and
 *   guarded systems and are withheld on strict. Offer-shaping, not
 *   legality: validation keeps accepting the whole scale, and a scale
 *   minting nothing yields the honest empty row. Any `allowed:`
 *   literals beside the scale append after the tokens (the server's
 *   allowed-beside-scale rule).
 * - A bare `allowed:` list IS the row, in list order, the guide's EXACT
 *   spellings as canonical writes (allowed checks are verbatim on both
 *   validators, PropertyGroupBase and validate-value.js, so any
 *   respelling would commit a value the guide rejects). Literals
 *   twin-label against the fallback table and paint as themselves; a
 *   var() entry the roster resolves paints and labels from record data
 *   (literalStop).
 * - No constraint: the fallback argument stands, identically (the
 *   property's one built-in vocabulary, fallbackFor — the deliberate
 *   zero-config offer on sites that declare none, or the materials
 *   store's color/gradient swatches for color- and image-shaped rows).
 *   Callers derive it through propertyStops, never hand a table of
 *   their own.
 * Equivalent spellings collapse into the first occurrence, so a
 * redundant list never yields two stops for one value. Committed values
 * outside the row keep the standard honesty path: no stop matches, so
 * segmentState renders the explicit custom pill.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster (materials.js).
 * @param {?{scale: string, stops: Array<string>, allowed: Array<string>}} constraints
 *   The bound property's guide constraints (model.constraints[property]),
 *   or null/undefined when unconstrained.
 * @param {Array<{id: string, value: string, matches: string[],
 *   label: string}>} fallback
 *   The unconstrained row (fallbackFor(guide, materials, property,
 *   definition)).
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string}>}
 */
export const stopsFor = (guide, materials, constraints, fallback) => {
  const allowed = Array.isArray(constraints?.allowed)
    ? constraints.allowed
    : [];
  const scale = constraints?.scale;
  let stops;
  if (typeof scale === 'string') {
    const declaration = guide?.tokenScales?.[scale];
    const tokens = scaleRow(
      guide,
      scaleTokens(guide, materials, scale),
      constraints,
    );
    const numeric =
      declaration?.shape === 'dimension' || declaration?.shape === 'number';
    stops = [
      ...tokens.map((token) =>
        referenceStop(
          token.ref,
          numeric && token.value ? token.value : token.name,
          token.value,
          token.valueDark,
          declaration?.label ?? scale,
        ),
      ),
      ...allowed.map((raw) => literalStop(guide, materials, raw, fallback)),
    ];
  } else if (allowed.length) {
    stops = allowed.map((raw) => literalStop(guide, materials, raw, fallback));
  } else {
    return fallback;
  }
  return dedupe(stops);
};

/**
 * The color rows' unconstrained vocabulary: the whole color palette as a
 * stop row (fallbackFor's answer for every color-shaped definition, and
 * the gradient builder's stop-color offer).
 *
 * The materials roster is the one color vocabulary (the hardcoded neutral
 * tables died with it): color-shaped skin tokens first (roster order:
 * scale declaration order, labeled by token name — the face paints the
 * value, so a `name · value` label would say it twice), then color-kind
 * swatches (labeled by swatch label), each stop committing its style
 * spelling and lit by the reference spelling only. Every stop carries
 * `paint` from record data (Segments' faces never resolve var()) and
 * `paintDark`, its scheme pair (null when the material is single-valued
 * — those faces stay whole); equivalent spellings collapse into the first
 * occurrence. No materials means no stops — the honest empty vocabulary;
 * committed values still render as the custom pill. A swatch minted
 * mid-session is here the moment the store learns it: the row derives per
 * render from the roster it is handed.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster (materials.js).
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string}>}
 */
export const paletteFallback = (guide, materials) =>
  dedupe([
    ...tokenOptions(guide, materials, ['color']).map((token) =>
      referenceStop(
        token.ref,
        token.name,
        token.value,
        token.valueDark,
        token.scaleLabel,
      ),
    ),
    ...swatchOptions(guide, materials, ['color']).map((swatch) =>
      referenceStop(
        swatch.ref,
        swatch.label,
        swatch.value,
        swatch.valueDark,
        swatch.kindLabel,
      ),
    ),
  ]);

/**
 * The <image> rows' unconstrained vocabulary: every gradient-kind swatch
 * as a reference stop, roster (label) order — fallbackFor's answer for a
 * free <image> definition (imageSlot), and the unconstrained tier of the color
 * widgets' gradient row (widget-gradients.js gradientStops). No token
 * scale shape maps onto images, so swatches are the whole offer; a
 * gradient swatch minted mid-session is here the moment the store learns
 * it.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster (materials.js).
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string, group: string}>}
 */
export const gradientFallback = (guide, materials) =>
  dedupe(
    swatchOptions(guide, materials, ['gradient']).map((swatch) =>
      referenceStop(
        swatch.ref,
        swatch.label,
        swatch.value,
        swatch.valueDark,
        swatch.kindLabel,
      ),
    ),
  );

/**
 * The keyframes-name rows' unconstrained vocabulary: every rostered
 * animation as a reference stop committing its motion-{name} spelling,
 * roster (label) order, then the definition's keywords (`none`, the
 * property's own way of wearing nothing) — fallbackFor's answer for the
 * `animation_name` type. The stop's value IS the spelling (a keyframes
 * name is a plain ident the browser reads as written, no var() to
 * resolve), labeled by the animation's label; the paint is the spelling
 * too, since motion has no face a swatch could show. No group: nothing
 * grids these stops. An animation minted mid-session is here the moment
 * the store learns it.
 *
 * @param {object} materials
 *   The materials roster (materials.js).
 * @param {string[]} keywords
 *   The definition's keywords (Motion's animation-name declares
 *   ['none']).
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string}>}
 */
export const animationFallback = (materials, keywords = []) =>
  dedupe([
    ...animationOptions(materials).map((animation) =>
      referenceStop(animation.ref, animation.label, animation.ref),
    ),
    ...keywordStops(keywords),
  ]);

/**
 * Whether a gap row applies to the selection: gap only exists BETWEEN
 * sibling boxes, so it needs a structural container (one that holds other
 * selectable elements — a text field of paragraphs lays out markup, not
 * page structure) actually laying out more than one child box. On
 * anything else gap is a no-op a lay user cannot see.
 *
 * @param {?{isContainer: boolean, childCount: number}} self
 *   The selection's own facts (layout.self), or null when unknown.
 *
 * @return {boolean}
 */
export const gapGate = (self) =>
  !self || (self.isContainer && self.childCount > 1);

/**
 * The number of child boxes a control's BOUND element lays out: the
 * selection's own childCount for a root binding, the bound part's entry
 * in self.partChildCount (layout-context.js: the part's first canvas
 * match, its own children) for an element binding. Null when unknown: a
 * null context, or a part the canvas shows no match for (whether the
 * part EXISTS is the presence map's question, answered before any gate
 * runs).
 *
 * @param {?{childCount: number, partChildCount: Object<string, number>}} self
 *   The selection's own facts (layout.self), or null when unknown.
 * @param {?string} element
 *   The control's home part selector (model.home.element), null for a root
 *   binding.
 *
 * @returns {?number}
 */
export const boundChildCount = (self, element) => {
  if (!self) {
    return null;
  }
  const count = element === null || element === undefined
    ? self.childCount
    : self.partChildCount?.[element];
  return typeof count === 'number' ? count : null;
};

/**
 * The gap widget's gate: whether the BOUND element lays out more than one
 * child box. Gap exists only between siblings, so on the page's layout
 * root (the entity kind's `gap` control bound to `.daedalus-layout-layout`) a
 * one-band page hides the row; unknown degrades to offering like every
 * gate. Reads the bound part's OWN children, never the selection's: the
 * selection is the entity root, whose one child box is the layout root
 * itself. The registry's value-set override (a committed gap keeps the
 * row) rides above this, as for every gate.
 *
 * @param {?{childCount: number, partChildCount: Object<string, number>}} self
 *   The selection's own facts (layout.self), or null when unknown.
 * @param {?string} element
 *   The control's home part selector (model.home.element), null for a root
 *   binding.
 *
 * @returns {boolean}
 */
export const boundGapGate = (self, element) => {
  const count = boundChildCount(self, element);
  return count === null || count > 1;
};

/**
 * The spacing steps: the padding family's UNBOUND offer (the shorthand
 * and its four longhands, so the field row and the box-model diagram's
 * side cells scrub one scale). The suite binds padding to the space
 * scale site-wide (daedalus_stylish.page_builder.yml constraint_defaults), so on
 * a shipped site the rows offer the active skin's space tokens and this
 * table is never consulted; it is reached only where no binding resolves
 * for the property (a site layer withdrawing the suite's block with
 * `padding: null`, or a fixture with no site bindings), and never as a
 * fallback for a bound scale minting nothing (an empty closed space is
 * the skin defect shown honestly). Rem-based so it tracks the root font
 * size; the aliases cover the equivalent spellings a human or AI
 * plausibly writes.
 */
const SPACING_STEPS = [
  {
    id: 'none',
    value: '0',
    matches: ['0', '0px', '0rem', '0em'],
    label: 'None',
  },
  { id: 's', value: '0.5rem', matches: ['0.5rem', '.5rem'], label: 'S' },
  { id: 'm', value: '1rem', matches: ['1rem'], label: 'M' },
  { id: 'l', value: '2rem', matches: ['2rem'], label: 'L' },
  { id: 'xl', value: '4rem', matches: ['4rem'], label: 'XL' },
];

/**
 * The stroke widths: the border-width family's built-in vocabulary (the
 * shorthand, its four side longhands and outline-width, so the stroke
 * row, the ring's side editors and the outline row scrub one ramp).
 * See the border-width entry in the FALLBACKS docblock.
 */
const STROKE_WIDTHS = [
  { id: 'thin', value: '1px', matches: ['1px'], label: 'Thin' },
  { id: 'medium', value: '2px', matches: ['2px'], label: 'Medium' },
  { id: 'thick', value: '4px', matches: ['4px'], label: 'Thick' },
  { id: 'none', value: '0', matches: ['0', '0px'], label: 'None' },
];

/**
 * The stroke styles: the border-style family's and outline-style's
 * built-in vocabulary, the four line styles a glyph can draw. See the
 * border-style entry in the FALLBACKS docblock.
 */
const STROKE_STYLES = ['solid', 'dashed', 'dotted', 'double'].map((style) => ({
  id: style,
  value: style,
  matches: [style],
  label: style.charAt(0).toUpperCase() + style.slice(1),
}));

/**
 * The ONE built-in vocabulary per property, both modes.
 *
 * Every field row and Segments row over a property reads its fallback
 * here (fallbackFor), and every advanced row reads the SAME entry, so a
 * gap that is detented in Basic is detented in Advanced: one value space
 * per property, never per widget. A property absent here has no built-in
 * vocabulary (its space is free until a guide constraint or the
 * materials store shapes it); color- and image-shaped definitions read
 * the store instead (fallbackFor). A guide `scale:` or `allowed:`
 * constraint replaces an entry wholesale through stopsFor.
 *
 * - text-align: canonical writes are direction-relative ('start'/'end'
 *   track the text direction, the i18n-safe spelling); the absolute
 *   keywords are aliases the stops honestly represent (the
 *   justify-content precedent: 'left' lights the start stop, clicking
 *   never rewrites it). 'justify' is a real stop: a legitimate text-align
 *   value that would otherwise always read as custom.
 * - font-size / font-weight / line-height: each row independent (a size
 *   click never writes weight). Sizes are rem-based (pixel spellings are
 *   NOT aliases: their equivalence assumes a 16px root the guide never
 *   promised). Weights are BARE VALUES (400–700, the ramp variable fonts
 *   made continuous) labeled as themselves — values, never names, on
 *   every face — with the spec keyword equivalents as aliases (normal=400,
 *   bold=700). Line heights write unitless multipliers (the
 *   inheritance-safe spelling); the CSS keyword 'normal' stays custom on
 *   purpose — it is UA-dependent (~1.2, not the Normal stop's 1.5), so no
 *   stop can honestly claim it.
 * - border-radius: a rem scale plus two ends of the range: None is an
 *   explicit 0 — squared corners overriding an inherited theme radius, a
 *   different statement from unset — and Round is the effectively-infinite
 *   radius that fully rounds the shorter axis (a circle on a square image
 *   part, a pill on a wide box). '50%' stays custom: it rounds per-axis
 *   into an ellipse, a different shape from what the Round stop shows.
 * - border-width: px widths on purpose — hairline borders are the one
 *   place px is the honest unit (a border must not scale with the root
 *   font size). The CSS keywords thin/medium/thick are NOT aliases: their
 *   computed widths are UA-defined, the line-height 'normal' precedent —
 *   no stop may claim a value it cannot promise to show. None is an
 *   explicit 0, LAST: under the layer stack it beats a skin- or
 *   variant-supplied border where unset would reveal it (the
 *   border-radius and box-shadow None precedent), and it is the twin a
 *   guide's `allowed: ['0']` faces as "None". The stroke row's width
 *   field reads this ramp, and its completion rule (stroke-model.js
 *   strokeWrite) carries the FIRST stop as the width a style or color
 *   pick on an unset stroke lands with, which is why None is last. The
 *   twelve side longhands read the same ramp (the padding-top
 *   precedent: the ring's side editors scrub one scale), and
 *   outline-width reads it verbatim: an outline is a stroke drawn
 *   outside the box, the same px vocabulary.
 * - border-style / outline-style (+ the border side longhands): the
 *   four line styles the stroke row's style well faces as glyphs
 *   (solid, dashed, dotted, double). The grammar's other keywords
 *   (none, hidden, groove, ridge, inset, outset, outline's auto) stay
 *   custom: a keyword definition would otherwise offer them all, but
 *   the row's vocabulary is the four a glyph can honestly draw, and
 *   `none` is not a style choice, it is the stroke's unset (the
 *   completion rule clears the whole stroke, never a part). The
 *   advanced rows read the same four detents, free entry staying open.
 * - outline-offset: px steps outward from the box edge (0 is the
 *   outline hugging the border), the gap precedent's shape: values as
 *   labels, the px spelling the id, the commit and the only alias.
 *   Negative offsets (an inset outline) are valid and typed, never a
 *   stop.
 * - box-shadow: each preset is one whole shadow value however long
 *   (normalizeValue collapses whitespace but keeps commas, so
 *   multi-shadow values compare structurally and honestly read custom).
 *   None is an explicit 'none' — flattening an inherited theme shadow, a
 *   different statement from unset. The three presets share one soft
 *   black at growing offset/blur so elevation reads as a scale; px on
 *   purpose (a screen-space effect). No aliases beyond the canonical
 *   spelling: shadow grammar has too many exactly-equivalent forms for
 *   normalized-string matching to claim any of them honestly.
 * - padding (+ longhands): SPACING_STEPS, see above.
 * - gap: the rem steps the Layout section's field row magnetizes to (the
 *   resting default is the row's placeholder hint, never a stop), the
 *   spacing tables' unit (one unit per value space: the built-in
 *   spacing vocabulary never spoke px beside rem padding on purpose);
 *   labels are the values themselves (values, never names, on every
 *   face), the rem spelling is the id, the commit, and the only alias,
 *   zero spelled bare and matching every unit's zero (the spacing
 *   table's rule, so a declared `gap: 0px` floor lights it). The
 *   spacing control's gap row (a block laying out its fields) reads the
 *   same entry: one gap vocabulary, whichever surface offers it.
 * - width: fractional presets. 'Full' is 100% — 'auto' is
 *   content-driven, a different idea, and renders as custom rather than
 *   pretending to be a preset.
 * - timeline-trigger / animation-trigger: the trigger of an animation, the
 *   two shorthands of the CSS Animation Triggers draft spelled verbatim
 *   (the printer prints them as-is; the daedalus_stylish/motion_triggers runtime
 *   reads the same spellings where the browser lacks them). Every stop
 *   names the one trigger `--in-view` so an override of either property
 *   stays bound to the other. "How far in" is a labelled activation
 *   range spelled with both of its points (When visible = entry 20%
 *   exit 100%, half = entry 50% exit 100%, fully = contain 0% exit
 *   100%): the range runs from the start until the element has fully
 *   left, so an element already in view when the trigger is created
 *   still plays. A lone start (`entry 20%`) is a different, narrower
 *   range natively (active only while crossing the entry band), so
 *   each stop matches its canonical spelling only and a bare start
 *   typed in advanced or code reads as its own value, never as the
 *   stop. "How often" is an action pair (play-once, replay + reset
 *   every time, play-forwards + play-backwards). `none` on either is the
 *   plain CSS animation (starts at render / no binding); the pair is one
 *   binding, so the basic control writes both together where one alone
 *   would leave it half-formed (triggerPairWrite). Finer values are
 *   typed in advanced or code; the row stays open around the stops.
 */
export const FALLBACKS = {
  'text-align': [
    { id: 'start', value: 'start', matches: ['start', 'left'], label: 'Left' },
    { id: 'center', value: 'center', matches: ['center'], label: 'Center' },
    { id: 'end', value: 'end', matches: ['end', 'right'], label: 'Right' },
    { id: 'justify', value: 'justify', matches: ['justify'], label: 'Justify' },
  ],
  'font-size': [
    {
      id: 's',
      value: '0.875rem',
      matches: ['0.875rem', '.875rem'],
      label: 'S',
    },
    { id: 'm', value: '1rem', matches: ['1rem'], label: 'M' },
    { id: 'l', value: '1.5rem', matches: ['1.5rem'], label: 'L' },
    { id: 'xl', value: '2.25rem', matches: ['2.25rem'], label: 'XL' },
  ],
  'font-weight': [
    { id: '400', value: '400', matches: ['400', 'normal'], label: '400' },
    { id: '500', value: '500', matches: ['500'], label: '500' },
    { id: '600', value: '600', matches: ['600'], label: '600' },
    { id: '700', value: '700', matches: ['700', 'bold'], label: '700' },
  ],
  'line-height': [
    { id: 'tight', value: '1.25', matches: ['1.25'], label: 'Tight' },
    { id: 'normal', value: '1.5', matches: ['1.5'], label: 'Normal' },
    { id: 'loose', value: '2', matches: ['2', '2.0'], label: 'Loose' },
  ],
  'border-radius': [
    {
      id: 'none',
      value: '0',
      matches: ['0', '0px', '0rem', '0em'],
      label: 'None',
    },
    { id: 's', value: '0.25rem', matches: ['0.25rem', '.25rem'], label: 'S' },
    { id: 'm', value: '0.5rem', matches: ['0.5rem', '.5rem'], label: 'M' },
    { id: 'l', value: '1rem', matches: ['1rem'], label: 'L' },
    { id: 'round', value: '9999px', matches: ['9999px'], label: 'Round' },
  ],
  'border-width': STROKE_WIDTHS,
  'border-top-width': STROKE_WIDTHS,
  'border-right-width': STROKE_WIDTHS,
  'border-bottom-width': STROKE_WIDTHS,
  'border-left-width': STROKE_WIDTHS,
  'border-style': STROKE_STYLES,
  'border-top-style': STROKE_STYLES,
  'border-right-style': STROKE_STYLES,
  'border-bottom-style': STROKE_STYLES,
  'border-left-style': STROKE_STYLES,
  'outline-width': STROKE_WIDTHS,
  'outline-style': STROKE_STYLES,
  'outline-offset': [0, 2, 4, 8].map((px) => ({
    id: `${px}px`,
    value: `${px}px`,
    matches: px === 0 ? ['0px', '0'] : [`${px}px`],
    label: `${px}px`,
  })),
  'box-shadow': [
    { id: 'none', value: 'none', matches: ['none'], label: 'None' },
    {
      id: 'low',
      value: '0 1px 2px rgb(0 0 0 / 0.1)',
      matches: ['0 1px 2px rgb(0 0 0 / 0.1)'],
      label: 'Low',
    },
    {
      id: 'medium',
      value: '0 2px 8px rgb(0 0 0 / 0.15)',
      matches: ['0 2px 8px rgb(0 0 0 / 0.15)'],
      label: 'Medium',
    },
    {
      id: 'high',
      value: '0 8px 24px rgb(0 0 0 / 0.2)',
      matches: ['0 8px 24px rgb(0 0 0 / 0.2)'],
      label: 'High',
    },
  ],
  padding: SPACING_STEPS,
  'padding-top': SPACING_STEPS,
  'padding-right': SPACING_STEPS,
  'padding-bottom': SPACING_STEPS,
  'padding-left': SPACING_STEPS,
  gap: [
    { id: '0', value: '0', matches: ['0', '0px', '0rem', '0em'], label: '0' },
    ...['0.5rem', '1rem', '1.5rem', '2.5rem'].map((rem) => ({
      id: rem,
      value: rem,
      matches: [rem],
      label: rem,
    })),
  ],
  width: [
    { id: 'quarter', value: '25%', matches: ['25%'], label: '25%' },
    { id: 'half', value: '50%', matches: ['50%'], label: '50%' },
    { id: 'three-quarters', value: '75%', matches: ['75%'], label: '75%' },
    { id: 'full', value: '100%', matches: ['100%'], label: 'Full' },
  ],
  'timeline-trigger': [
    { id: 'load', value: 'none', matches: ['none'], label: 'On load' },
    {
      id: 'visible',
      value: '--in-view view() entry 20% exit 100%',
      matches: ['--in-view view() entry 20% exit 100%'],
      label: 'When visible',
    },
    {
      id: 'half',
      value: '--in-view view() entry 50% exit 100%',
      matches: ['--in-view view() entry 50% exit 100%'],
      label: 'When half visible',
    },
    {
      id: 'full',
      value: '--in-view view() contain 0% exit 100%',
      matches: ['--in-view view() contain 0% exit 100%'],
      label: 'When fully visible',
    },
  ],
  'animation-trigger': [
    {
      id: 'once',
      value: '--in-view play-once',
      matches: ['--in-view play-once'],
      label: 'Once',
    },
    {
      id: 'always',
      value: '--in-view replay reset',
      matches: ['--in-view replay reset'],
      label: 'Every time',
    },
    {
      id: 'both-ways',
      value: '--in-view play-forwards play-backwards',
      matches: ['--in-view play-forwards play-backwards'],
      label: 'Forwards and back',
    },
    { id: 'off', value: 'none', matches: ['none'], label: 'Off' },
  ],
};

/**
 * The trigger pair's two properties and the default each completes the
 * other with: the FALLBACKS stop a bound half implies for a missing one
 * (When visible for the range, Once for the action).
 */
const TRIGGER_PAIR = {
  'timeline-trigger': { partner: 'animation-trigger', completion: 'once' },
  'animation-trigger': { partner: 'timeline-trigger', completion: 'visible' },
};

/**
 * Whether a trigger value is the OFF half of the pair: unset (nothing in
 * the layer stack) or an explicit `none`.
 */
const triggerOff = (value) => {
  const normalized = normalizeValue(value);
  return normalized === null || normalized === 'none';
};

/**
 * The Motion trigger control's write for one row's settle: the pair as
 * one binding.
 *
 * `timeline-trigger` names a trigger and its range, `animation-trigger`
 * binds the element's animation to it by name; a bound action with no
 * timeline is a half-formed binding (the printer's hold pauses the
 * animation, the runtime has no range to watch, and the draft leaves an
 * unmatched trigger name unspecified natively), and a range with no
 * action is inert while its row claims a Start. So a settle that turns
 * the trigger OFF (an explicit `none` on either row: Start "On load",
 * Play "Off") clears the partner too when it is bound, and a settle
 * that turns it ON (any other value) completes a missing or `none`
 * partner with its default stop. Changing the range keeps the action
 * and changing the action keeps the range: the partner is only written
 * where the settle would otherwise leave the pair half-formed. An
 * unset (null: an emptied row, "follow the layer beneath") writes the
 * row's own property alone, since what resurfaces is the layer stack's
 * business, and a settle on any other property passes through.
 *
 * @param {Object<string, ?string>} properties
 *   The settling row's write ({property: value}, null for unset).
 * @param {Object<string, string>} values
 *   The control's shown values at the pose (controls.js litValues over
 *   the model's reads: the winner's pair, a worn variant's included).
 *
 * @return {Object<string, ?string>}
 *   The properties to commit as one gesture.
 */
export const triggerPairWrite = (properties, values) => {
  const entries = Object.entries(properties);
  if (entries.length !== 1 || !(entries[0][0] in TRIGGER_PAIR)) {
    return properties;
  }
  const [property, value] = entries[0];
  if (value === null) {
    return properties;
  }
  const { partner, completion } = TRIGGER_PAIR[property];
  const partnerOff = triggerOff(values?.[partner]);
  if (normalizeValue(value) === 'none') {
    return partnerOff ? properties : { ...properties, [partner]: 'none' };
  }
  if (!partnerOff) {
    return properties;
  }
  return {
    ...properties,
    [partner]: FALLBACKS[partner].find((stop) => stop.id === completion).value,
  };
};

/**
 * A keyword definition's own vocabulary as stops: each keyword commits
 * itself, labeled as itself (CSS grammar, not guide law — the row stays
 * open around them unless a constraint closes it).
 */
const keywordStops = (keywords) =>
  keywords.map((keyword) => ({
    id: keyword,
    value: keyword,
    matches: [normalizeValue(keyword)],
    label: keyword,
  }));

/**
 * Whether a media-capable definition is a free <image> slot.
 *
 * The offer follows the type; the media flag adds the picker. A media
 * definition whose type leaves the value free (background-image, type
 * text) takes anything an <image> can be, so the gradient swatches, the
 * gradient builder and every swatch kind offer beside the media picker.
 * A keyword-typed media definition (content: `""`, `none` or exactly one
 * media token) takes no gradient and no swatch reference the server
 * would accept, so its offer is its keywords plus the picker alone: a
 * stop the server refuses is a lie in the row. Read by fallbackFor, the
 * code view's token completions and the advanced row's gradient half.
 *
 * @param {?object} definition
 *   The property's guide definition ({type, keywords, media, ...}).
 *
 * @return {boolean}
 */
export const imageSlot = (definition) =>
  definition?.media === true && definition?.type !== 'keyword';

/**
 * A property's unconstrained vocabulary: the ONE fallback both modes
 * read, keyed by the property definition.
 *
 * Resolution order, first answer wins:
 * - The property's FALLBACKS entry (a curated table beats the shape rule:
 *   text-align keeps its iconic direction-relative stops, box-shadow its
 *   elevation presets).
 * - A keyword definition offers its keywords (keywordStops), whatever
 *   else it carries: the row is detented around CSS grammar, free entry
 *   (var(), calc(), the CSS-wide keywords the server accepts) staying
 *   open. Judged before the media branch so a keyword-typed media
 *   property (content) offers `""` and `none`, never a gradient the
 *   server refuses there (imageSlot).
 * - A free <image> definition offers the store's gradient swatches
 *   (gradientFallback); a color-shaped one the whole color palette
 *   (paletteFallback) — so a swatch minted mid-session appears in every
 *   color and image space at once, in both modes.
 * - A keyframes-name definition (`animation_name`) offers the store's
 *   animations by their motion-{name} spellings plus its keywords
 *   (animationFallback): the advanced Motion row's one-name offer.
 * - Anything else has no built-in vocabulary: [] (a free space until a
 *   constraint shapes it).
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster (materials.js).
 * @param {string} property
 *   The CSS property name (the definition's key in the guide).
 * @param {?object} definition
 *   The property's guide definition ({type, keywords, media, ...}, any
 *   subset; definitionFor / buildSections row.definition), or null.
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string}>}
 */
export const fallbackFor = (guide, materials, property, definition) => {
  const table = FALLBACKS[property];
  if (Array.isArray(table)) {
    return table;
  }
  if (
    definition?.type === 'keyword' &&
    Array.isArray(definition.keywords) &&
    definition.keywords.length
  ) {
    return keywordStops(definition.keywords);
  }
  if (imageSlot(definition)) {
    return gradientFallback(guide, materials);
  }
  if (definition?.type === 'color') {
    return paletteFallback(guide, materials);
  }
  if (definition?.type === 'animation_name') {
    return animationFallback(
      materials,
      Array.isArray(definition.keywords) ? definition.keywords : [],
    );
  }
  return [];
};

/**
 * A property's constraint-honest stop row: stopsFor over the property's
 * one fallback (fallbackFor). The call every widget and every value
 * space makes, so no surface can hand stopsFor a vocabulary of its own.
 *
 * The one exception is an AXIS-RELATIVE vocabulary: the position and
 * alignment rows write one value set per property (start / center /
 * end) but face it per SCREEN axis (Top / Middle / Bottom, Left / Center
 * / Right), and which axis a property means depends on the parent's
 * layout mode, so the table cannot live under the property's name.
 * Those rows hand their axis table in as `fallback`; a guide constraint
 * on the property still displaces it exactly as it displaces the
 * property-keyed table (twins keep their icons).
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {string} property
 *   The CSS property name.
 * @param {?object} definition
 *   The property's guide definition, or null.
 * @param {?object} constraints
 *   The property's resolved constraints, or null/undefined.
 * @param {?Array<{id: string, value: string, matches: string[],
 *   label: string}>} fallback
 *   An axis-relative built-in table standing in for the property's own
 *   fallback (positionAxes / alignmentAxes stops), or null for
 *   fallbackFor's answer.
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string}>}
 */
export const propertyStops = (
  guide,
  materials,
  property,
  definition,
  constraints,
  fallback = null,
) =>
  stopsFor(
    guide,
    materials,
    constraints,
    fallback ?? fallbackFor(guide, materials, property, definition),
  );

/**
 * Maps one property's committed value onto a stop row.
 *
 * The custom-state honesty rule as a function: unset is {selected: null,
 * custom: false} (honestly nothing), a representable value selects its
 * stop, and anything else — space-between under a three-stop row, a
 * hand-typed 42% — is {selected: null, custom: true}: shown as an explicit
 * custom state, never as "nothing set".
 *
 * @param {*} value
 *   The shown value at the pose (the stack winner's: style-stack.js
 *   stackAt, or a draft layered over it).
 * @param {Array<{id: string, value: string, matches: string[]}>} stops
 *
 * @return {{selected: ?string, custom: boolean}}
 */
export const segmentState = (value, stops) => {
  const normalized = normalizeValue(value);
  if (normalized === null) {
    return { selected: null, custom: false };
  }
  const stop = stops.find((candidate) =>
    candidate.matches.includes(normalized),
  );
  return stop
    ? { selected: stop.id, custom: false }
    : { selected: null, custom: true };
};
