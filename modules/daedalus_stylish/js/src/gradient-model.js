/**
 * The gradient builder's value grammar: parse-or-degrade, one layer edited.
 *
 * A background-image value is a comma-layered list of <image> values; the
 * builder edits exactly ONE gradient layer and preserves every other layer
 * verbatim (a media token, a second gradient, a var() reference — none of
 * them is ever re-serialized). Parsing is deliberately conservative: a
 * layer the model cannot represent faithfully answers null and the builder
 * degrades that layer to free text — the Custom-pill rule at value scope,
 * never a silent normalization. What the model DOES represent it
 * round-trips: type and repeating flag, the prelude as a raw string (an
 * angle, "to bottom right", "circle at center", "from 45deg" — the builder
 * only interprets the linear angle spelling, everything else passes
 * through untouched), and stops as {color, position} with the position
 * kept raw.
 */

/**
 * The gradient function head: optional repeating- prefix, the three types.
 */
const FUNCTION_HEAD = /^(repeating-)?(linear|radial|conic)-gradient\(/i;

/**
 * The linear angle spelling the builder's angle control can own.
 */
const DEG_ANGLE = /^(-?(?:\d+\.?\d*|\.\d+))deg$/i;

/**
 * Prelude recognition per type: whether a gradient's FIRST argument is the
 * prelude rather than a stop. Linear preludes are angles ("135deg",
 * ".5turn"), directions ("to bottom right"), or interpolation spaces
 * ("in oklch"); radial preludes carry shape/size keywords, a leading
 * dimension, or a position ("at center"); conic preludes start "from" or
 * "at". A first argument neither pattern nor stop grammar claims degrades
 * the whole layer (parseGradient answers null).
 */
const PRELUDE_FOR = {
  linear: /^(-?[\d.]|to\s|in\s)/i,
  radial:
    /^(circle\b|ellipse\b|closest-(side|corner)\b|farthest-(side|corner)\b|-?[\d.]|at\s|in\s)|\sat\s/i,
  conic: /^(from\s|at\s|in\s)/i,
};

/**
 * Splits a comma-layered value into its top-level layers, verbatim.
 *
 * Parenthesized groups keep their inner commas (function notation). Each
 * layer is trimmed but otherwise untouched — reassembly (buildValue) joins
 * with ', ', so unedited layers' CONTENT is byte-identical. Malformed
 * lists (unbalanced parentheses, an empty comma segment) answer null; the
 * empty string is zero layers.
 *
 * @param {string} value
 *   The whole property value.
 *
 * @return {?string[]}
 *   The layers, [] for an empty value, or null when unsplittable.
 */
export const splitLayers = (value) => {
  if (typeof value !== 'string') {
    return null;
  }
  const text = value.trim();
  if (text === '') {
    return [];
  }
  const layers = [];
  let depth = 0;
  let start = 0;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '(') {
      depth++;
    } else if (char === ')') {
      if (--depth < 0) {
        return null;
      }
    } else if (char === ',' && depth === 0) {
      const layer = text.slice(start, i).trim();
      if (layer === '') {
        return null;
      }
      layers.push(layer);
      start = i + 1;
    }
  }
  if (depth !== 0) {
    return null;
  }
  const last = text.slice(start).trim();
  if (last === '') {
    return null;
  }
  layers.push(last);
  return layers;
};

/**
 * Whether a layer is a gradient function (the builder's edit target).
 *
 * A var() layer is NOT a gradient here even when it references a gradient
 * swatch: the reference is opaque and the builder must not unfold it.
 */
export const isGradientLayer = (layer) => {
  if (typeof layer !== 'string') {
    return false;
  }
  const text = layer.trim();
  return FUNCTION_HEAD.test(text) && text.endsWith(')');
};

/**
 * The first gradient layer's index — the layer the builder edits — or -1.
 */
export const gradientTargetIndex = (layers) =>
  layers.findIndex(isGradientLayer);

/**
 * A stop argument's first value unit and the raw remainder.
 *
 * The unit is a function consumed to its balanced close or a run of
 * non-whitespace; the remainder (the position, possibly two-part) keeps
 * its own internal spelling.
 */
const firstUnit = (text) => {
  let depth = 0;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '(') {
      depth++;
    } else if (char === ')') {
      if (--depth < 0) {
        return null;
      }
    } else if (/\s/.test(char) && depth === 0) {
      return { unit: text.slice(0, i), rest: text.slice(i).trim() };
    }
  }
  return depth === 0 ? { unit: text, rest: '' } : null;
};

/**
 * One stop argument as {color, position}, or null when it is not one.
 *
 * The color is a hex, a complete function (var(), rgb(), color-mix(), …),
 * or a bare name; the position is whatever follows, raw. A leading number
 * or dimension is NOT a stop — that is an interpolation hint, a grammar
 * the model does not represent, so the layer degrades.
 */
const parseStop = (arg) => {
  const split = firstUnit(arg.trim());
  if (split === null || split.unit === '') {
    return null;
  }
  const { unit, rest } = split;
  const colorish =
    /^#[0-9a-fA-F]{3,8}$/.test(unit) ||
    /^[a-zA-Z-]+\(.*\)$/s.test(unit) ||
    /^[a-zA-Z]+$/.test(unit);
  if (!colorish) {
    return null;
  }
  return { color: unit, position: rest === '' ? null : rest };
};

/**
 * Parses one gradient layer into the builder's model, or degrades.
 *
 * @param {string} layer
 *   One layer (splitLayers output).
 *
 * @return {?{repeating: boolean, type: string, prelude: string,
 *   stops: Array<{color: string, position: ?string}>}}
 *   The model, or null when the layer's grammar is beyond it (the builder
 *   then edits the layer as free text — parse-or-degrade honesty).
 */
export const parseGradient = (layer) => {
  const text = typeof layer === 'string' ? layer.trim() : '';
  const head = text.match(FUNCTION_HEAD);
  if (!head || !text.endsWith(')')) {
    return null;
  }
  const args = splitLayers(text.slice(head[0].length, -1));
  if (args === null || args.length === 0) {
    return null;
  }
  const type = head[2].toLowerCase();
  let prelude = '';
  let stopArgs = args;
  // The prelude claim beats the stop grammar for keyword-led arguments
  // ("to bottom right", "circle at center" — their leading ident would
  // spuriously parse as a named color), but never claims an argument
  // whose first unit is a real color spelling (a hex or a function like
  // color-mix(), whatever its inner text mentions).
  const lead = firstUnit(args[0].trim());
  const colorLead =
    lead !== null && (lead.unit.startsWith('#') || lead.unit.includes('('));
  if (PRELUDE_FOR[type].test(args[0]) && !colorLead) {
    [prelude, ...stopArgs] = args;
  }
  const stops = [];
  for (const arg of stopArgs) {
    const stop = parseStop(arg);
    if (stop === null) {
      return null;
    }
    stops.push(stop);
  }
  if (stops.length === 0) {
    return null;
  }
  return { repeating: Boolean(head[1]), type, prelude, stops };
};

/**
 * Serializes the model back to one gradient layer.
 *
 * The exact inverse of parseGradient over everything it represents: the
 * prelude prints raw, stops print "color position". Only whitespace
 * spelling normalizes (single spaces, ', ' joins) — values never do.
 */
export const serializeGradient = ({ repeating, type, prelude, stops }) => {
  const parts = [];
  if (typeof prelude === 'string' && prelude.trim() !== '') {
    parts.push(prelude.trim());
  }
  stops.forEach(({ color, position }) => {
    parts.push(position ? `${color} ${position}` : color);
  });
  return `${repeating ? 'repeating-' : ''}${type}-gradient(${parts.join(', ')})`;
};

/**
 * Reassembles the whole value with one layer replaced (or prepended).
 *
 * The layer-honesty rule's write half: every layer except the target is
 * the splitLayers segment verbatim. Index -1 prepends the text as a new
 * first layer — the gradient-over-media stacking order (a scrim layer
 * paints above the photo behind it).
 *
 * @param {string[]} layers
 *   The current layers (splitLayers output).
 * @param {number} index
 *   The target layer, or -1 to prepend.
 * @param {string} text
 *   The target layer's new text.
 *
 * @return {string}
 */
export const buildValue = (layers, index, text) =>
  index === -1
    ? [text, ...layers].join(', ')
    : layers.map((layer, i) => (i === index ? text : layer)).join(', ');

/**
 * The linear angle the prelude spells, in degrees, or null.
 *
 * '' is the CSS default direction (to bottom = 180deg); only the "Ndeg"
 * spelling maps onto the angle control — every other prelude ("to top
 * right", ".25turn", "135deg in oklch") stays a raw prelude the angle
 * control must not claim (editing it would silently normalize).
 */
export const preludeAngle = (prelude) => {
  const text = typeof prelude === 'string' ? prelude.trim() : '';
  if (text === '') {
    return 180;
  }
  const match = text.match(DEG_ANGLE);
  return match ? Number(match[1]) : null;
};

/**
 * A fresh gradient for an empty or gradient-less value: a soft black
 * scrim, the builder's honest starting statement (visible over any
 * media layer beneath it, obviously placeholder over none).
 *
 * @param {?string} color
 *   The first stop's color; callers with a constrained vocabulary seed
 *   from it instead of the free default.
 * @param {?string} to
 *   The second stop's color.
 */
export const defaultGradient = (color = null, to = null) => ({
  repeating: false,
  type: 'linear',
  prelude: '180deg',
  stops: [
    { color: color ?? 'rgba(0, 0, 0, 0.5)', position: null },
    { color: to ?? 'rgba(0, 0, 0, 0)', position: null },
  ],
});
