/**
 * @file
 * The client value-space spine: one pure model answering "what may this
 * property's value be", shared by every input surface in both modes (the
 * DimensionField's three renders, the basic-mode field rows, the
 * box-model diagram's side cells, the advanced rows' keyword/text/color
 * fields). Widgets own gestures; this module owns the vocabulary, the
 * scrub math, and the capture rules: no DOM, no preact, node-tested
 * (tests/js/value-space.test.mjs).
 *
 * ONE value space per property: the space is a function of the guide,
 * the materials roster, the property (its definition and resolved
 * constraints) and the editing condition — never of the surface asking.
 * Stops come from the constraints (scale / allowed), then the materials
 * store (color and gradient swatches for color- and image-shaped
 * properties, the published animations for the keyframes-name property),
 * then the property's one built-in table (widget-values.js fallbackFor);
 * Basic and Advanced read the same derivation, so a gap that is detented
 * in one mode is detented in the other.
 *
 * A property's value space has one of three kinds, and the KIND (never
 * the site's enforcement mode) is what drives the widgets:
 * - 'closed': a guide constraint (scale: or a non-empty allowed: list)
 *   closes the vocabulary while constraints are law (guarded/strict).
 *   The input is a select of stop values, scrubbing jumps detent to
 *   detent, commits are always the stop's reference spelling. A closed
 *   space can be honestly EMPTY (a scale minting nothing): the row hides
 *   unless a committed value keeps it visible.
 * - 'detented': the vocabulary shapes without closing (open enforcement,
 *   or no closing constraint but a stop table stands). Free entry stays;
 *   scrubbing is continuous with sticky magnets at the stops; a settled
 *   value that matches a stop captures its reference (magneticCapture).
 * - 'free': no vocabulary at all; today's field verbatim.
 *
 * Faces never show var() syntax: a numeric stop faces as its display
 * VALUE (stop.value, e.g. '0.5rem'; numbers read), a non-numeric
 * material stop as the material's NAME (sm/md/lg for a shadow scale;
 * shadow CSS does not read), a curated literal as its label twin
 * ('None' over '0'), see stopFace; storage keeps the reference
 * (stop.ref, e.g. 'var(--space-2)'). Tokens sharing one display value
 * collapse into a single stop whose `tokens` list is the collision set:
 * the select offers the value once (first token wins), the provenance
 * menu is where the twins separate. A keyframes-name space
 * (`animation_name`) is the one whose references are not var(): an
 * animation's motion-{name} spelling is a plain ident the browser reads
 * as written, so it is both the display value and the commit, and the
 * material behind it (the label the faces show beside it) resolves in
 * the animation slot the property asks for, never the style slot.
 *
 * The vocabulary derivation is propertyStops (widget-values.js),
 * absorbed, not duplicated: this module regroups its constraint-honest
 * stops into value-space shape and resolves reference displays at the
 * editing condition (displayAt).
 */

import { normalizeValue, propertyStops } from './widget-values.js';
import { isPlainObject, constraintsAreLaw } from './style-guide.js';
import { animationOf, materialOf } from './materials.js';
import { KIND } from '../../../daedalus_ui/js/edit_mode/materials/grammar.js';
import { appliesWithin, cascadeOrder } from './condition-cascade.js';
import { applyStep, parseDimension } from './widgets/lib/dimension-value.js';

/**
 * Horizontal pixels of pointer travel per detent in a closed space's
 * discrete scrub (the mock's BAND; a feel knob, tune on the real
 * canvas).
 */
export const SCRUB_BAND = 64;

/**
 * How far (in the space's own unit) a detented scrub's magnet reaches
 * around each stop (the mock's MAGNET; a feel knob).
 */
export const MAGNET_RADIUS = 0.12;

/**
 * Value change per pixel of pointer travel in a detented scrub (the
 * mock's PER_PX, tuned for rem; a feel knob).
 */
export const SCRUB_PER_PIXEL = 0.006;

/**
 * A detented stepper's grain between stops in rem (the mock: steppers
 * step 0.1 but land ON a stop when they cross one, so arrow-walking
 * still finds the system). The unit-sized grain every caller passes is
 * dimension-value's unitGrain (1 for px, this for rem); this constant
 * is stepAcross's default and the value-space tests' anchor.
 */
export const STEP_GRAIN = 0.1;

/**
 * The scrub feel knobs are rem-tuned (SCRUB_PER_PIXEL, MAGNET_RADIUS);
 * px-magnitude units scale them by the conventional root so a px scrub
 * travels and magnetizes like its rem twin.
 */
export const feelFactor = (unit) =>
  unit === 'rem' || unit === 'em' || unit === '' ? 1 : 16;

/**
 * A whole var() reference and nothing else: the commit spelling of a
 * reference stop (referenceStop builds exactly this; stored refs carry no
 * fallback tail).
 */
const REFERENCE = /^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/;

/**
 * The custom property a committed value references, or null for
 * anything that is not one whole var() reference.
 *
 * @param {*} value
 *   A committed value string.
 *
 * @return {?string}
 *   The variable name, leading dashes included ('--space-4').
 */
export const referenceVariable = (value) => {
  const match =
    typeof value === 'string' ? value.trim().match(REFERENCE) : null;
  return match ? match[1] : null;
};

/**
 * Whether a parsed dimension is a zero length, the unit-agnostic value.
 *
 * @param {{number: number, unit: string}} parsed
 *
 * @return {boolean}
 */
const isZero = (parsed) => Math.abs(parsed.number) < 1e-9;

/**
 * Whether two value spellings are the same value, the blessed-state
 * equality rule: parse-normalized within the SAME unit ('.5rem' equals
 * '0.5rem'), never unit-converted (8px is not 0.5rem even at a 16px
 * root; the guide never promised that root). Zero is the one value no
 * root is needed for: a zero length is zero in every unit ('0', '0px'
 * and '0rem' are one value, the grammar's own unitless-zero rule), so a
 * scale's `0` stop captures a zero settled in the field's unit. Values
 * outside the dimension grammar (keywords) compare as normalized
 * strings.
 *
 * @param {*} a
 * @param {*} b
 *
 * @return {boolean}
 */
export const valuesEqual = (a, b) => {
  const left = parseDimension(typeof a === 'string' ? a : String(a ?? ''));
  const right = parseDimension(typeof b === 'string' ? b : String(b ?? ''));
  if (left !== null && right !== null) {
    if (isZero(left) && isZero(right)) {
      return true;
    }
    return (
      left.unit === right.unit && Math.abs(left.number - right.number) < 1e-9
    );
  }
  return normalizeValue(a) !== null && normalizeValue(a) === normalizeValue(b);
};

/**
 * A committed value's display form at the editing condition: the
 * ref→display read behind every face (the user never sees var() outside
 * code mode).
 *
 * A literal displays as itself. A var() reference resolves from the
 * materials ROSTER, never getComputedStyle (the panel DOM may sit outside
 * the token scope): a plain token/swatch value is one value everywhere; a
 * condition-keyed value answers its cascade-winning entry among the keys
 * that apply within the editing compound ('default' always applies,
 * 'dark' applies at 'narrow+dark', the same structural order the
 * printer and the inherited-value provenance use, so the field and the
 * canvas can never disagree). A reference the palette does not know
 * answers null; the caller paints the raw spelling honestly.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster.
 * @param {*} value
 *   A committed value: a literal or a var() reference.
 * @param {string} condition
 *   The editing compound ('default', a single, or 'a+b').
 *
 * @return {?string}
 *   The display string, or null when the value is a reference the
 *   roster cannot resolve.
 */
export const displayAt = (guide, materials, value, condition = 'default') => {
  const variable = referenceVariable(value);
  if (variable === null) {
    return value === null || value === undefined ? null : String(value);
  }
  const raw = materialOf(guide, materials, value)?.record.value ?? null;
  if (raw === null) {
    return null;
  }
  if (!isPlainObject(raw)) {
    return String(raw);
  }
  const keys = Object.keys(raw).filter(
    (key) =>
      appliesWithin(key, condition) &&
      raw[key] !== null &&
      raw[key] !== undefined &&
      String(raw[key]) !== '',
  );
  if (!keys.length) {
    return null;
  }
  const winner = cascadeOrder(keys, guide?.conditions).pop();
  return String(raw[winner]);
};

/**
 * A reference stop's token identity: the material behind a var() as the
 * collision set and the provenance menu name it ({ref, kind, scale,
 * name}); a reference the roster lacks keeps the custom property as its
 * name, kind and scale null.
 */
const referenceToken = (guide, materials, ref, variable) => {
  const material = materialOf(guide, materials, ref);
  if (material === null) {
    return { ref: String(ref).trim(), kind: null, scale: null, name: variable };
  }
  const token = material.record.kind === 'token';
  return {
    ref: material.ref,
    kind: material.record.kind,
    scale: token ? material.scale : null,
    name: token ? material.name : material.label,
  };
};

/**
 * Whether a property's value space reads the ANIMATION slot: its
 * definition is the `animation_name` type (Motion's animation-name), so
 * a stop's value is a keyframes name and the material behind it is the
 * animation spelled so. Every other space reads the style slot (var()
 * references). The property-scoped slot rule: a bare ident means
 * different things per property, so only a space that knows its property
 * is animation-shaped asks the animation slot.
 */
const animationSpace = (definition) => definition?.type === 'animation_name';

/**
 * An animation stop's identity: the animation behind a keyframes name as
 * the one-member collision set ({ref, kind, scale, name}: the spelling,
 * the animation kind, no scale, the label), or null for a stop that is
 * not a rostered animation (`none`, a theme's keyframes name).
 */
const animationToken = (materials, name) => {
  const animation = animationOf(materials, name);
  return animation === null
    ? null
    : {
        ref: animation.ref,
        kind: KIND.ANIMATION,
        scale: null,
        name: animation.label,
      };
};

/**
 * Whether the guide CLOSES this property's vocabulary: a scale binding
 * or a non-empty allowed list, while constraints are law (guarded and
 * strict; per property, never per site). The one closedness question
 * every surface asks — a custom value (a typed literal, the gradient
 * builder's output, a media pick) is admissible exactly when the space
 * is NOT closed, in every enforcement mode; open enforcement demotes the
 * same constraint to offer-shaping and the space stays open.
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {?object} constraints
 *   The property's resolved constraints, or null.
 *
 * @return {boolean}
 */
export const spaceClosed = (guide, constraints) =>
  constraintsAreLaw(guide) &&
  (typeof constraints?.scale === 'string' ||
    (Array.isArray(constraints?.allowed) && constraints.allowed.length > 0));

/**
 * The one unit a space's stops share: the unit every dimension stop
 * carries, a unitless zero excepted (zero is every unit's zero, so `0`
 * beside rem stops leaves the space rem; a table of bare zeros alone
 * stays bare), or null when the stops disagree or none parses.
 *
 * @param {Array<{number: ?number, unit: ?string}>} stops
 *
 * @return {?string}
 */
const spaceUnit = (stops) => {
  const parsed = stops.filter((stop) => stop.unit !== null);
  const named = parsed.filter(
    (stop) => !(stop.unit === '' && stop.number === 0),
  );
  const units = [
    ...new Set((named.length ? named : parsed).map((stop) => stop.unit)),
  ];
  return units.length === 1 ? units[0] : null;
};

/**
 * A property's value space: kind + the stop list, the one derivation
 * every input surface adapts.
 *
 * Stops arrive from propertyStops (the constraint-honest vocabulary:
 * scale tokens, allowed literals, or the property's one fallback —
 * fallbackFor: the built-in table, the store's swatches for color and
 * image shapes, a keyword definition's keywords) and regroup by
 * DISPLAY VALUE at the editing condition: tokens sharing one value
 * collapse into a single stop carrying the collision set. Stop shape:
 * - id: the widget stop table's id (propertyStops: a token's reference
 *   spelling, a curated literal's twin id such as 'none'), carried so
 *   an icon map keyed the way Segments keys it (stop id) reads the same
 *   stop in both tables.
 * - value: the display string every face shows ('0.5rem').
 * - ref: the commit spelling, the first member's canonical write
 *   ('var(--space-2)' for a token stop, the guide's exact literal
 *   spelling otherwise).
 * - label: a curated LITERAL's name (a FALLBACKS twin such as 'None',
 *   a trigger row's 'When visible'); null on a material stop, whose
 *   name rides its tokens, and null when the label only respells the
 *   value.
 * - tokens: the collision set, [{ref, kind, scale, name}] (kind
 *   'token', 'swatch' or 'animation', scale null outside tokens, name
 *   the token name or the swatch's / animation's label); [] for a pure
 *   literal stop. tokens[0] is the default capture; the provenance menu
 *   separates twins. In a keyframes-name space the animation's spelling
 *   is the stop's value and ref alike, and its label rides here.
 * - number/unit: the display value's parse (null/null outside the
 *   dimension grammar; a keyword stop scrubs detent-to-detent but
 *   never magnetizes).
 * - paint / paintDark / group / media / thumbnail: the PRESENTATION
 *   payload a face renders from (the widget stop table's own fields,
 *   carried through instead of dropped): the material's display value
 *   as the face paint (record data, never a var() the browser would
 *   resolve; a literal stop paints as its value), its scheme pair (null
 *   for a single-valued material, so the face stays whole), the
 *   palette-side group label the grid sections by (a scale's label, a
 *   swatch kind's label; null for a curated literal), and the synthetic
 *   media entry's flag + thumbnail. Numeric faces ignore them; the
 *   color field's grid and the wells paint from them.
 *
 * The space also carries `unit` (the one unit every parsable stop
 * shares, else null; a unitless zero stop is every unit's zero and
 * names none, so a spacing table of `0, 0.5rem, 1rem` is a rem space
 * and the open field's unit select opens on rem, never on px; closed
 * rows drop the unit select because tokens carry their units) and
 * passes `definition`/`constraints` through so a field works from one
 * object (clamp still reads them directly).
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster.
 * @param {string} property
 *   The CSS property name (the key its fallback and definition live
 *   under).
 * @param {?object} definition
 *   The bound property's definition (buildSections row.definition,
 *   model.propertyDefinitions[property]), or null.
 * @param {?object} constraints
 *   The bound property's resolved constraints (row.constraints:
 *   {scale, stops, allowed, min, max, units}, any subset), or null.
 * @param {string} condition
 *   The editing compound; reference displays resolve here, so two
 *   tokens colliding at default may honestly separate at 'dark'.
 * @param {?Array<object>} fallback
 *   An axis-relative built-in table standing in for the property's own
 *   fallback (propertyStops' exception: the position and alignment
 *   rows), or null.
 *
 * @return {{kind: string, stops: Array<{value: string, ref: string,
 *   tokens: Array<{ref: string, kind: ?string, scale: ?string,
 *   name: string}>, number: ?number, unit: ?string}>, unit: ?string,
 *   definition: ?object, constraints: ?object}}
 */
export const valueSpaceFor = (
  guide,
  materials,
  property,
  definition,
  constraints,
  condition = 'default',
  fallback = null,
) => {
  const closed = spaceClosed(guide, constraints);
  const animations = animationSpace(definition);
  const stops = [];
  propertyStops(
    guide,
    materials,
    property,
    definition,
    constraints,
    fallback,
  ).forEach((stop) => {
    const variable = referenceVariable(stop.value);
    const display =
      variable === null
        ? String(stop.value)
        : (displayAt(guide, materials, stop.value, condition) ??
          stop.paint ??
          stop.value);
    const token =
      variable !== null
        ? referenceToken(guide, materials, stop.value, variable)
        : animations
          ? animationToken(materials, String(stop.value))
          : null;
    const twin = stops.find((existing) => valuesEqual(existing.value, display));
    if (twin) {
      if (token !== null) {
        twin.tokens.push(token);
      }
      return;
    }
    const parsed = parseDimension(display);
    // A curated literal's NAME (a FALLBACKS label such as "When visible"
    // over `--in-view view() entry 20% exit 100%`, a guide allowed
    // entry's twin): the face a plain list shows beside the value. A
    // material stop's name rides its tokens instead (stopsFor labels a
    // token by name on non-numeric scales; here that name is the
    // token's, never a literal's), and a label that only respells the
    // value is no name (gap's px steps, a keyword definition's own
    // keywords).
    const name =
      token === null &&
      typeof stop.label === 'string' &&
      stop.label !== display &&
      stop.label !== String(stop.value)
        ? stop.label
        : null;
    stops.push({
      id: stop.id,
      value: display,
      ref: stop.value,
      label: name,
      tokens: token === null ? [] : [token],
      number: parsed?.number ?? null,
      unit: parsed?.unit ?? null,
      paint: stop.paint ?? display,
      paintDark: stop.paintDark ?? null,
      group: stop.group ?? null,
      media: stop.media === true,
      thumbnail: stop.thumbnail ?? null,
    });
  });
  return {
    kind: closed ? 'closed' : stops.length ? 'detented' : 'free',
    stops,
    unit: spaceUnit(stops),
    definition: definition ?? null,
    constraints: constraints ?? null,
  };
};

/**
 * A stop's text in a plain list (a closed space's select, a detented
 * space's stop menu): the display value, prefixed by the material's name
 * when one stands behind it ("Lime green · #4dff8c", "accent · #1a1a2e")
 * or by a curated literal's own name ("When visible · --in-view view()
 * entry 20% exit 100%", the Motion trigger rows' labelled stops) — a
 * plain row
 * cannot paint a face, so the name carries what the paint would; an
 * unnamed literal stop is its value alone.
 *
 * @param {{value: string, label: ?string, tokens: Array<{name: string}>}} stop
 *   A value-space stop.
 *
 * @return {string}
 */
export const stopLabel = (stop) => {
  if (stop.tokens.length) {
    return `${stop.tokens[0].name} · ${stop.value}`;
  }
  return stop.label ? `${stop.label} · ${stop.value}` : stop.value;
};

/**
 * The most stops a choice row shows as chips; one more and the row is
 * the well (one face showing the current value, the roster in a
 * popover). A feel knob: five chips fit the panel's row beside the
 * label and the provenance slot.
 */
export const CHIP_BUDGET = 5;

/**
 * The longest text face a chip may carry, in characters (sm/md/lg/xl,
 * None, 10px fit; 0.8125rem and shadow CSS do not). An icon face is
 * always within budget.
 */
export const FACE_BUDGET = 5;

/**
 * A stop's FACE: the short text a chip or a well shows for it. A
 * numeric stop faces as its display value whoever minted it (10px for
 * a radius token; numbers read); a non-numeric material stop faces as
 * the material's name (sm for a shadow token, Lime green for a swatch;
 * shadow CSS and hex do not read); a curated literal faces as its label
 * twin where one exists (None over '0' or 'none', When visible over a
 * trigger range), else as its value.
 *
 * @param {{value: string, label: ?string, number: ?number,
 *   tokens: Array<{name: string}>}} stop
 *   A value-space stop.
 *
 * @return {string}
 */
export const stopFace = (stop) => {
  if (stop.tokens.length) {
    return stop.number === null ? stop.tokens[0].name : stop.value;
  }
  return stop.label ?? stop.value;
};

/**
 * The SURFACE a basic-mode choice row renders for a value space, derived
 * from the space alone (never chosen by a widget: the vocabulary is site
 * data, so its size and readability are only known at render, and a
 * widget-side choice is wrong for the next skin):
 * - 'field': a dimension or number property whose space is NOT closed
 *   (detented or free): the dimension field, typed values admissible.
 * - 'chips': at most CHIP_BUDGET stops, every face an icon or within
 *   FACE_BUDGET characters: the whole vocabulary in the row.
 * - 'well': otherwise, one face showing the current value, the roster
 *   in a popover. A closed type scale lands here (its rem faces exceed
 *   the budget), as does any long vocabulary.
 * An empty space answers 'chips' (nothing exceeds a budget); whether an
 * empty row renders at all is the choice row's hide rule.
 *
 * @param {{kind: string, stops: Array<object>, definition: ?object}} space
 *   The property's value space (valueSpaceFor).
 * @param {{icons: ?Object<string, *>, definition: ?object}} options
 *   `icons`: the row's icon map keyed by stop id (Segments' contract;
 *   alignment-icons.js), a stop it names is an icon face. `definition`:
 *   the property definition, defaulting to the space's own.
 *
 * @return {string}
 *   'chips', 'well' or 'field'.
 */
export const surfaceFor = (
  space,
  { icons = null, definition = space.definition } = {},
) => {
  const type = definition?.type;
  if ((type === 'dimension' || type === 'number') && space.kind !== 'closed') {
    return 'field';
  }
  const chips =
    space.stops.length <= CHIP_BUDGET &&
    space.stops.every(
      (stop) =>
        (icons !== null &&
          stop.id !== undefined &&
          icons[stop.id] !== undefined) ||
        stopFace(stop).length <= FACE_BUDGET,
    );
  return chips ? 'chips' : 'well';
};

/**
 * A closed space's discrete scrub accumulator, starting on a stop.
 *
 * @param {number} index
 *   The current stop index.
 *
 * @return {number}
 *   The raw accumulator (pixels of virtual travel).
 */
export const discreteScrubStart = (index) => index * SCRUB_BAND;

/**
 * One discrete scrub tick: accumulate pointer travel, snap to the
 * nearest detent (SCRUB_BAND pixels apart, switching at half-band: the
 * ka-chunk). The accumulator pins at both ends so there is no
 * rubber-band distance to scrub back through.
 *
 * @param {number} raw
 *   The running accumulator (discreteScrubStart, then each tick's raw).
 * @param {number} movementX
 *   The tick's horizontal pointer travel (PointerEvent.movementX).
 * @param {number} count
 *   The space's stop count.
 *
 * @return {{raw: number, index: number}}
 */
export const discreteScrubMove = (raw, movementX, count) => {
  const pinned = Math.max(
    0,
    Math.min((count - 1) * SCRUB_BAND, raw + movementX),
  );
  return { raw: pinned, index: Math.round(pinned / SCRUB_BAND) };
};

/**
 * A detented space's magnets: the stops that parse in the field's
 * current unit, ascending; the same-unit rule again (a px stop never
 * magnetizes a rem scrub), with zero the one exception: a zero stop is
 * every unit's zero, so a scale's `0` magnetizes a rem scrub at 0 and
 * the release commits the stop's own spelling.
 *
 * @param {Array<object>} stops
 *   The space's stops (valueSpaceFor).
 * @param {string} unit
 *   The field's current unit ('' for a bare number).
 *
 * @return {Array<{number: number, stop: object}>}
 */
export const detentsFor = (stops, unit) =>
  stops
    .filter(
      (stop) =>
        stop.number !== null &&
        (stop.unit === unit || (stop.number === 0 && unit !== '')),
    )
    .map((stop) => ({ number: stop.number, stop }))
    .sort((a, b) => a.number - b.number);

/**
 * One detented (continuous) scrub tick: advance the accumulator by
 * pointer travel, pin at any bounds, then let the nearest in-range
 * magnet capture. The returned `number` is what the field shows and
 * settles; `stop` is the capturing stop (null between magnets), whose
 * first token the settle commits (magneticCapture doctrine: scrubbing
 * onto a stop IS reaching the token).
 *
 * @param {number} raw
 *   The running accumulator (starts at the current numeric value).
 * @param {number} movementX
 *   The tick's horizontal pointer travel.
 * @param {Array<{number: number, stop: object}>} detents
 *   The space's magnets (detentsFor).
 * @param {{min: ?number, max: ?number, perPixel: number,
 *   magnet: number, precision: ?number}} options
 *   Bounds pin the accumulator; perPixel/magnet override the feel knobs;
 *   precision rounds the shown number BETWEEN magnets to that many
 *   decimals (the unit's precision, dimension-value's unitPrecision:
 *   whole pixels, hundredths of a rem), null keeping the raw travel. A
 *   magnet's number is always the stop's own.
 *
 * @return {{raw: number, number: number, stop: ?object}}
 */
export const magnetAdvance = (raw, movementX, detents, options = {}) => {
  const {
    min = null,
    max = null,
    perPixel = SCRUB_PER_PIXEL,
    magnet = MAGNET_RADIUS,
    precision = null,
  } = options;
  let advanced = raw + movementX * perPixel;
  if (min !== null && advanced < min) {
    advanced = min;
  }
  if (max !== null && advanced > max) {
    advanced = max;
  }
  const near = detents.reduce((best, detent) => {
    const distance = Math.abs(advanced - detent.number);
    return distance < magnet && (best === null || distance < best.distance)
      ? { detent, distance }
      : best;
  }, null);
  const between =
    precision === null ? advanced : Number(advanced.toFixed(precision));
  return {
    raw: advanced,
    number: near ? near.detent.number : between,
    stop: near ? near.detent.stop : null,
  };
};

/**
 * One detented stepper click: step by the grain, but land ON a stop
 * when the step crosses one (the crossed stop nearest the start), so
 * arrow-walking still finds the system. Precision-safe addition
 * (applyStep), no float noise.
 *
 * @param {number} number
 *   The current numeric value.
 * @param {number} direction
 *   +1 or -1.
 * @param {Array<{number: number, stop: object}>} detents
 *   The space's magnets (detentsFor).
 * @param {number} grain
 *   The step size between stops.
 *
 * @return {{number: number, stop: ?object}}
 */
export const stepAcross = (number, direction, detents, grain = STEP_GRAIN) => {
  const next = applyStep(number, direction, grain);
  const crossed = detents
    .filter((detent) =>
      direction > 0
        ? detent.number > number && detent.number <= next
        : detent.number < number && detent.number >= next,
    )
    .reduce(
      (best, detent) =>
        best === null ||
        Math.abs(detent.number - number) < Math.abs(best.number - number)
          ? detent
          : best,
      null,
    );
  return {
    number: crossed ? crossed.number : next,
    stop: crossed ? crossed.stop : null,
  };
};

/**
 * A closed space's VALUE direction: +1 when the curated stop order runs
 * value-ascending, -1 when it runs largest-first (a type scale declared
 * display -> caption). Gestures speak value ("increase" grows the value,
 * a rightward scrub too) while the select keeps curated list order.
 * Majority vote over the parsable neighbors, so one appended literal
 * ('0' after ascending tokens) cannot flip the ramp.
 *
 * @param {Array<object>} stops
 *   The space's stops (valueSpaceFor).
 *
 * @return {number}
 *   +1 or -1.
 */
export const valueOrient = (stops) => {
  let ups = 0;
  let downs = 0;
  for (let index = 1; index < stops.length; index += 1) {
    const a = stops[index - 1].number;
    const b = stops[index].number;
    if (a !== null && b !== null) {
      if (b > a) {
        ups += 1;
      } else if (b < a) {
        downs += 1;
      }
    }
  }
  return downs > ups ? -1 : 1;
};

/**
 * One closed stepper press: the next stop index, one stop in VALUE
 * direction. Entering the ramp from unset (index -1), increase starts at
 * the value-low end and decrease at the value-high end; the ramp pins at
 * its ends.
 *
 * @param {Array<object>} stops
 *   The space's stops (valueSpaceFor).
 * @param {number} index
 *   The current stop index, -1 for unset.
 * @param {number} direction
 *   +1 or -1, in value terms.
 *
 * @return {?number}
 *   The next index, or null when the press moves nothing (empty space,
 *   or already pinned at the end).
 */
export const stepStop = (stops, index, direction) => {
  if (!stops.length) {
    return null;
  }
  const orient = valueOrient(stops);
  const lowEnd = orient === 1 ? 0 : stops.length - 1;
  const highEnd = orient === 1 ? stops.length - 1 : 0;
  const next =
    index === -1
      ? direction > 0
        ? lowEnd
        : highEnd
      : Math.max(0, Math.min(stops.length - 1, index + direction * orient));
  return next === index ? null : next;
};

/**
 * Magnetic capture at settle: the stop a settled value VALUE-matches
 * (blessed-state equality: same unit, parse-normalized, never
 * converted). Typing '.5rem' IS a valid way to reach the minted stop:
 * the settle commits `ref` (the first colliding token's reference, or
 * the literal stop's canonical spelling) unless the user detached via
 * the provenance menu; the detach flag stays the widget's, per-settle,
 * deliberately not sticky.
 *
 * @param {Array<object>} stops
 *   The space's stops (valueSpaceFor).
 * @param {*} value
 *   The settled value (typed text, a scrub's landing, a stepper's).
 *
 * @return {?{stop: object, token: ?object, ref: string}}
 *   Null when no stop matches; the settle commits the literal.
 */
export const magneticCapture = (stops, value) => {
  const stop = stops.find((candidate) => valuesEqual(candidate.value, value));
  if (!stop) {
    return null;
  }
  const token = stop.tokens[0] ?? null;
  return { stop, token, ref: token?.ref ?? stop.ref };
};

/**
 * The stop and token a COMMITTED reference wears: how a stored
 * 'var(--space-gutter)' renders as its display value with the blessed
 * chrome, twin included (unlike magneticCapture, the variable names
 * exactly one token). A committed literal never matches here: a
 * literal already in the map stays a literal until touched.
 *
 * @param {Array<object>} stops
 *   The space's stops (valueSpaceFor).
 * @param {*} value
 *   The committed value.
 *
 * @return {?{stop: object, token: object}}
 */
export const referenceMatch = (stops, value) => {
  const variable = referenceVariable(value);
  if (variable === null) {
    return null;
  }
  const ref = `var(${variable})`;
  for (const stop of stops) {
    const token = stop.tokens.find((candidate) => candidate.ref === ref);
    if (token) {
      return { stop, token };
    }
  }
  return null;
};

/**
 * What a shown value WEARS: the display face plus the token behind it —
 * the ref→display read every face shares (the DimensionField's input,
 * the box-model diagram's side cells).
 *
 * A committed reference the space knows answers its exact stop and token
 * (referenceMatch: the variable names one twin, never the collision
 * default); a reference outside the space but known to the roster still
 * resolves its display (blessed, but stopless); an unknown reference
 * keeps its raw spelling (the face degrades honestly); a literal is its
 * own face and wears nothing.
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {{stops: Array<object>}} space
 *   The property's value space (valueSpaceFor).
 * @param {*} value
 *   The committed value.
 * @param {string} condition
 *   The editing compound.
 *
 * @return {{face: string, token: ?object, stop: ?object}}
 */
export const wearing = (
  guide,
  materials,
  space,
  value,
  condition = 'default',
) => {
  const text = typeof value === 'string' ? value : '';
  const variable = referenceVariable(text);
  if (variable === null) {
    return { face: text, token: null, stop: null };
  }
  const worn = referenceMatch(space.stops, text);
  if (worn) {
    return { face: worn.stop.value, token: worn.token, stop: worn.stop };
  }
  const resolved = displayAt(guide, materials, text, condition);
  return resolved === null
    ? { face: text, token: null, stop: null }
    : {
        face: resolved,
        token: referenceToken(guide, materials, text, variable),
        stop: null,
      };
};
