/**
 * @file
 * The color field's pure half (color-field.js is the preact component):
 * the sRGB color parse every face-and-picker surface shares, the face a
 * color value wears over its value space, the palette grid's entries
 * for that space, and the save-as-swatch gate. No DOM, no preact,
 * node-tested (tests/js/color-field-model.test.mjs).
 *
 * One color editor for every color slot in the inspector: the advanced
 * color rows and the color swatch kind's value editor mount the same
 * field over a value space (value-space.js valueSpaceFor), and the basic
 * wells' color lane reads the same face and settle rule, so the offer,
 * the capture rule and the face read are derived once here and rendered
 * once there.
 */

import { facePair, inlineLiteral } from './materials.js';
import {
  magneticCapture,
  referenceVariable,
  valueSpaceFor,
  wearing,
} from './value-space.js';

/**
 * A color spelling as [r, g, b, a] (0-255, alpha 0-1), or null when the
 * spelling is beyond the parser: hex (3, 4, 6 and 8 digits) and the
 * rgb()/rgba() functions, legacy commas or modern slashes. A convenience
 * sampler shared by the gradient track's neighbor interpolation and the
 * native picker's face, not a color engine: named colors, hsl(), the
 * CSS Color 4 spaces and var() answer null and the caller degrades to a
 * neighbor's color (the gradient) or the browser's own parse (the
 * picker).
 *
 * @param {*} text
 *   A color spelling.
 *
 * @return {?number[]}
 */
export const parseColor = (text) => {
  const spelling = typeof text === 'string' ? text.trim() : '';
  const hex = spelling.match(
    /^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/,
  );
  if (hex) {
    const digits =
      hex[1].length <= 4
        ? hex[1]
            .split('')
            .map((c) => c + c)
            .join('')
        : hex[1];
    const parts = [];
    for (let i = 0; i < digits.length; i += 2) {
      parts.push(parseInt(digits.slice(i, i + 2), 16));
    }
    return [
      parts[0],
      parts[1],
      parts[2],
      parts.length === 4 ? parts[3] / 255 : 1,
    ];
  }
  const fn = spelling.match(
    /^rgba?\(\s*([\d.]+)\s*[,\s]\s*([\d.]+)\s*[,\s]\s*([\d.]+)\s*(?:[,/\s]\s*([\d.]+%?)\s*)?\)$/,
  );
  if (fn) {
    const alpha =
      fn[4] === undefined
        ? 1
        : fn[4].endsWith('%')
          ? Number(fn[4].slice(0, -1)) / 100
          : Number(fn[4]);
    return [Number(fn[1]), Number(fn[2]), Number(fn[3]), alpha];
  }
  return null;
};

/**
 * A parsed color back to a spelling: hex when opaque, rgba() when the
 * alpha carries information.
 *
 * @param {number[]} color
 *   [r, g, b, a] as parseColor answers.
 *
 * @return {string}
 */
export const formatColor = ([r, g, b, a]) =>
  a >= 1
    ? `#${[r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')}`
    : `rgba(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)}, ${Math.round(a * 1000) / 1000})`;

/**
 * The 6-digit hex the native color picker can wear for a face paint, or
 * null when the paint is beyond it.
 *
 * The picker's value grammar is exactly #rrggbb, so every other spelling
 * the server accepts (short hex, rgb(), a named color, oklch(), a var()
 * the roster resolved into one of those) has to be brought to it here:
 * the pure parse first (alpha dropped: the picker carries none, the text
 * keeps the truth), then the caller's resolver for what the parse cannot
 * read (the browser's own color parse, color-field.js browserHex). Null
 * means the picker cannot honestly represent the value; the field
 * disables it rather than nudging black over a legal color (the old
 * six-digit regex posture this replaces).
 *
 * @param {*} paint
 *   A face paint (materials.js paintValue: references already resolved).
 * @param {?Function} [resolve]
 *   Fallback parser: (spelling) => '#rrggbb' | null.
 *
 * @return {?string}
 */
export const pickerHex = (paint, resolve = null) => {
  const parsed = parseColor(paint);
  if (parsed !== null) {
    return formatColor([parsed[0], parsed[1], parsed[2], 1]);
  }
  const spelling = typeof paint === 'string' ? paint.trim() : '';
  if (spelling === '' || typeof resolve !== 'function') {
    return null;
  }
  const resolved = resolve(spelling);
  return typeof resolved === 'string' && /^#[0-9a-fA-F]{6}$/.test(resolved)
    ? resolved.toLowerCase()
    : null;
};

/**
 * What a color value WEARS over its value space: the face the field's
 * trigger paints, the text its input shows, and the kind that picks the
 * chrome and the gestures.
 *
 * Kinds, mutually exclusive:
 * - 'unset': nothing set; the slashed empty face.
 * - 'stop': a reference the space offers (value-space.js wearing); the
 *   stop's own paint pair, the display value as text, the token behind
 *   it for provenance.
 * - 'reference': a rostered token or swatch outside the space (a color
 *   token on a row whose scale binding excludes it): painted and
 *   displayed from record data, blessed but stopless.
 * - 'literal': an inline color the grammar calls literal (a hex, rgb(),
 *   a named color, a var() with a fallback tail): painted as itself
 *   through the one face-paint rule (nested references substituted), the
 *   spelling as text. The only kind the save-as-swatch gesture offers
 *   over, and the well's painted custom face in basic mode.
 * - 'unknown': a reference the roster lacks or an inline media value: the
 *   raw spelling as text and paint, the face degrading honestly.
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {{kind: string, stops: Array<object>}} space
 *   The property's value space (valueSpaceFor).
 * @param {*} value
 *   The shown (draft-layered) value.
 * @param {string} [condition]
 *   The editing compound.
 *
 * @return {{kind: string, spelling: string, text: string, paint: ?string,
 *   paintDark: ?string, stop: ?object, token: ?object}}
 *   spelling is the trimmed value as committed (a reference's var(), a
 *   literal's own text); text is what the input shows.
 */
export const colorFace = (
  guide,
  materials,
  space,
  value,
  condition = 'default',
) => {
  const text = typeof value === 'string' ? value.trim() : '';
  if (text === '') {
    return {
      kind: 'unset',
      spelling: '',
      text: '',
      paint: null,
      paintDark: null,
      stop: null,
      token: null,
    };
  }
  const worn = wearing(guide, materials, space, text, condition);
  if (worn.stop !== null) {
    return {
      kind: 'stop',
      spelling: text,
      text: worn.face,
      paint: worn.stop.paint ?? worn.stop.value,
      paintDark: worn.stop.paintDark ?? null,
      stop: worn.stop,
      token: worn.token,
    };
  }
  const pair = facePair(guide, materials, text);
  if (worn.token !== null) {
    return {
      kind: 'reference',
      spelling: text,
      text: worn.face,
      paint: pair.paint,
      paintDark: pair.paintDark,
      stop: null,
      token: worn.token,
    };
  }
  if (
    referenceVariable(text) === null &&
    inlineLiteral(materials, text) !== null
  ) {
    return {
      kind: 'literal',
      spelling: text,
      text,
      paint: pair.paint,
      paintDark: pair.paintDark,
      stop: null,
      token: null,
    };
  }
  return {
    kind: 'unknown',
    spelling: text,
    text,
    paint: text,
    paintDark: null,
    stop: null,
    token: null,
  };
};

/**
 * The palette grid's entries for a color space (palette-picker.js
 * PaletteGrid's stop shape: id, value, label, paint, paintDark, group)
 * and the lit entry.
 *
 * Every stop of the space as a face chip whose pick commits the stop's
 * reference spelling; the lit chip is the stop the value wears. A CLOSED
 * space appends the honest extra entry for a committed value the
 * vocabulary does not offer (the closed select's extra option, as a
 * face): visible, lit, replaceable by any pick and unset by its own, never
 * editable as text. An open space needs no extra: the value sits in the
 * text input.
 *
 * @param {{kind: string, stops: Array<object>}} space
 *   The value space.
 * @param {{kind: string, spelling: string, text: string, paint: ?string,
 *   paintDark: ?string, stop: ?object, token: ?object}} face
 *   colorFace's answer for the shown value.
 *
 * @return {{entries: Array<object>, selected: ?string}}
 */
export const gridEntries = (space, face) => {
  const entries = space.stops.map((stop) => ({
    id: stop.ref,
    value: stop.ref,
    label: stop.tokens.length ? stop.tokens[0].name : stop.value,
    paint: stop.paint ?? stop.value,
    paintDark: stop.paintDark ?? null,
    group: stop.group ?? null,
  }));
  if (face.stop !== null) {
    return { entries, selected: face.stop.ref };
  }
  if (space.kind === 'closed' && face.kind !== 'unset') {
    // The extra commits (and unsets) the value AS COMMITTED: a rostered
    // reference keeps its var() and is named by its material.
    entries.push({
      id: face.spelling,
      value: face.spelling,
      label: face.token !== null ? face.token.name : face.text,
      paint: face.paint,
      paintDark: face.paintDark,
      group: null,
      extra: true,
    });
    return { entries, selected: face.spelling };
  }
  return { entries, selected: null };
};

/**
 * Whether the save-as-swatch gesture renders: the grant (honest chrome;
 * the mint route enforces), an OPEN space (a closed one commits stops
 * only, and a swatch minted outside its vocabulary would never be
 * offered there), and a literal color to save (a reference already IS a
 * material; unknown and unset have nothing to mint).
 *
 * @param {{kind: string}} space
 * @param {{kind: string}} face
 * @param {boolean} canMint
 *
 * @return {boolean}
 */
export const mintOffered = (space, face, canMint) =>
  Boolean(canMint) && space.kind !== 'closed' && face.kind === 'literal';

/**
 * A literal's settle over a color space: what commits, and what to
 * preview first.
 *
 * The one rule behind every literal door (typed text, the native
 * picker's change event): an emptied spelling settles '' (unset); on a
 * detented space magnetic capture runs (value-space.js magneticCapture:
 * reaching a swatch's own hex IS reaching the swatch, so the commit is
 * the swatch's reference and the canvas previews the stop's display
 * value when the typed spelling differs from it); anything else commits
 * as typed, trimmed.
 *
 * @param {{kind: string, stops: Array<object>}} space
 *   The value space.
 * @param {string} text
 *   The settled spelling.
 *
 * @return {{spelling: string, preview: ?string}}
 *   spelling is the commit ('' for unset); preview is the stop value to
 *   paint before the commit when capture landed on a differently
 *   spelled stop, else null.
 */
export const literalSettle = (space, text) => {
  const trimmed = typeof text === 'string' ? text.trim() : '';
  if (trimmed === '') {
    return { spelling: '', preview: null };
  }
  if (space.kind === 'detented') {
    const capture = magneticCapture(space.stops, trimmed);
    if (capture) {
      return {
        spelling: capture.ref,
        preview: capture.stop.value !== trimmed ? capture.stop.value : null,
      };
    }
  }
  return { spelling: trimmed, preview: null };
};

/**
 * The color palette as a value space (the color swatch kind's editor:
 * a swatch's value has no property, so the space is the unconstrained
 * color offer, and authoring is never law-gated), minus one reference
 * the editor must not offer nor capture: a swatch cannot alias itself
 * (one var() cycle and the property is invalid everywhere it is used).
 * A stop whose collision set held the excluded reference drops it from
 * the set (and the stop when nothing remains), so a twin by value can
 * neither show nor capture the swatch's own spelling.
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {?string} [exclude]
 *   The style spelling to leave out ('var(--swatch-x)'), or null.
 *
 * @return {object}
 *   A value space (valueSpaceFor's shape).
 */
export const paletteSpace = (guide, materials, exclude = null) => {
  const space = valueSpaceFor(guide, materials, null, { type: 'color' }, null);
  if (exclude === null) {
    return space;
  }
  const stops = [];
  space.stops.forEach((stop) => {
    const tokens = stop.tokens.filter((token) => token.ref !== exclude);
    if (stop.tokens.length && !tokens.length) {
      return;
    }
    if (!stop.tokens.length && stop.ref === exclude) {
      return;
    }
    stops.push({
      ...stop,
      tokens,
      ref: tokens.length ? tokens[0].ref : stop.ref,
    });
  });
  return { ...space, stops, kind: stops.length ? space.kind : 'free' };
};
