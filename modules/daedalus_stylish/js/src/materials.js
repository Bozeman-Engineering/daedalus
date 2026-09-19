/**
 * The materials store as the inspector reads it: tokens, swatches and
 * animations projected into the shapes the pickers, wells, faces,
 * completions and lint consume.
 *
 * The roster (`materials`, a daedalus_ui materials/roster.js index over
 * the delivery-shape records) arrives through the state source: the
 * leader reads Drupal.Daedalus.Materials, the satellite holds its own
 * index over the same envelope and the relayed deltas. It is passed
 * beside `guide` into every pure derivation here and downstream, never
 * read from a global, so the node tests build one from records
 * (tests/js/materials-fixture.mjs) exactly as the store would. `guide`
 * still owns the DECLARATIONS a record's `group` joins against: a token
 * scale's label/shape/keywords (guide.tokenScales) and a swatch kind's
 * label (guide.swatchKinds) are vocabulary facts no record carries.
 *
 * Spelling lives on the record: a token's or swatch's `spellings.style`
 * is the committable var() reference as the value grammar spells it, and
 * an animation's `spellings.animation` is the keyframes ident a motion
 * value wears (motion-{name}), so nothing here wraps var() or parses a
 * prefix. Whether a value IS such a reference is the grammar's answer
 * (resolveValue / references over the roster, in the slot the property
 * asks: the style slot for var() values, the animation slot for a
 * keyframes name), never a regex over the spelling.
 */

import {
  references,
  resolveValue,
  KIND,
  SLOT,
} from '../../../daedalus_ui/js/edit_mode/materials/grammar.js';
import {
  isPlainObject,
  schemeDisplayValue,
  tokenDisplayValue,
} from './style-guide.js';

/**
 * A record's committable style spelling ('var(--swatch-brand_red)'), or
 * null for a record with none in the style slot.
 */
export const styleReference = (record) => {
  const spelling = record?.spellings?.[SLOT.STYLE];
  return typeof spelling === 'string' && spelling !== '' ? spelling : null;
};

/**
 * The custom property a token's or swatch's style spelling delivers as
 * ('--swatch-brand_red'), read back from the spelling: the one place the
 * client needs the bare property name is the token scope element's
 * inline delivery extension (the leader source), and the prompt's PHP
 * reader (MaterialsSchema::customProperty) reads it the same way.
 */
export const customProperty = (record) => {
  const spelling = styleReference(record);
  return spelling !== null &&
    spelling.startsWith('var(') &&
    spelling.endsWith(')')
    ? spelling.slice(4, -1).trim()
    : null;
};

/**
 * One token record as a picker/completion entry.
 */
const tokenEntry = (guide, record) => {
  const declaration = guide?.tokenScales?.[record.group];
  return {
    scale: record.group,
    scaleLabel:
      isPlainObject(declaration) && declaration.label
        ? declaration.label
        : record.group,
    shape: isPlainObject(declaration) ? (declaration.shape ?? null) : null,
    name: record.label,
    ref: styleReference(record),
    value: tokenDisplayValue(record.value),
    valueDark: schemeDisplayValue(guide, record.value),
    record,
  };
};

/**
 * One swatch record as a picker/completion entry.
 */
const swatchEntry = (guide, record) => ({
  name: record.id,
  label: record.label !== '' ? record.label : record.id,
  kind: record.group,
  kindLabel: guide?.swatchKinds?.[record.group]?.label ?? record.group,
  ref: styleReference(record),
  value: tokenDisplayValue(record.value),
  valueDark: schemeDisplayValue(guide, record.value),
  record,
});

/**
 * Every rostered token, optionally shape-filtered, roster order.
 *
 * Roster order is the provider's: declared scale order, then the skin's
 * authoring order within a scale (the picker order). Each entry joins its
 * scale's declaration (label, shape) from the guide; a token whose scale
 * the guide does not declare (a theme mismatch the server classifies
 * elsewhere) keeps its group as the label and no shape, so a shape filter
 * excludes it and an unfiltered read still lists it.
 *
 * @param {?object} guide
 *   The style guide payload (tokenScales; conditions for the scheme pair).
 * @param {object} materials
 *   The materials roster.
 * @param {?string[]} shapes
 *   Scale shapes to include (e.g. ['dimension', 'number']); null for all.
 *
 * @return {Array<{scale: string, scaleLabel: string, shape: ?string,
 *   name: string, ref: string, value: string, valueDark: ?string,
 *   record: object}>}
 */
export const tokenOptions = (guide, materials, shapes = null) =>
  materials
    .ofKind(KIND.TOKEN)
    .map((record) => tokenEntry(guide, record))
    .filter(
      (entry) =>
        entry.ref !== null &&
        (!Array.isArray(shapes) || shapes.includes(entry.shape)),
    );

/**
 * One scale's tokens as picker/completion entries, roster order; [] for
 * an undeclared scale or one minting nothing.
 */
export const scaleTokens = (guide, materials, scale) =>
  tokenOptions(guide, materials).filter((entry) => entry.scale === scale);

/**
 * Every rostered swatch, optionally kind-filtered, roster order (label).
 *
 * `value` is the display form (a condition map shows its 'default' entry)
 * and `valueDark` the scheme pair (schemeDisplayValue; null when
 * single-valued): faces paint from this data, never from var() resolution,
 * because the panel DOM may sit outside the token scope.
 *
 * @param {?object} guide
 *   The style guide payload (swatchKinds; conditions for the scheme pair).
 * @param {object} materials
 *   The materials roster.
 * @param {?string[]} kinds
 *   Swatch kind ids to include (e.g. ['color']); null for all.
 *
 * @return {Array<{name: string, label: string, kind: string,
 *   kindLabel: string, ref: string, value: string, valueDark: ?string,
 *   record: object}>}
 */
export const swatchOptions = (guide, materials, kinds = null) =>
  materials
    .ofKind(KIND.SWATCH)
    .map((record) => swatchEntry(guide, record))
    .filter(
      (entry) =>
        entry.ref !== null &&
        (!Array.isArray(kinds) || kinds.includes(entry.kind)),
    );

/**
 * The token or swatch a style value IS, as a picker entry, or null.
 *
 * The grammar's whole-value read: exactly one material reference (no
 * fallback, whitespace tolerated) resolves to its record; a literal, a
 * compound, an inline media value or a reference the roster lacks answers
 * null, and the caller paints the raw spelling honestly.
 *
 * @return {?object}
 *   A tokenOptions/swatchOptions entry (kind told apart by `record.kind`).
 */
export const materialOf = (guide, materials, value) => {
  // The var keyword is case-insensitive per spec (custom property NAMES
  // are not), and committed values arrive in whatever spelling an AI or
  // advanced-mode write chose; the grammar's whole-value read is exact,
  // so the head canonicalizes here and the name stays as written.
  const spelled =
    typeof value === 'string' ? value.trim().replace(/^var\(/i, 'var(') : value;
  const resolved = resolveValue(spelled, SLOT.STYLE, materials);
  if (resolved.kind === KIND.TOKEN) {
    return tokenEntry(guide, resolved.record);
  }
  if (resolved.kind === KIND.SWATCH) {
    return swatchEntry(guide, resolved.record);
  }
  return null;
};

/**
 * A record's committable keyframes-name spelling ('motion-fade_in'), or
 * null for a record with none in the animation slot.
 */
export const animationSpelling = (record) => {
  const spelling = record?.spellings?.[SLOT.ANIMATION];
  return typeof spelling === 'string' && spelling !== '' ? spelling : null;
};

/**
 * One animation record as a picker/completion entry: `ref` is the
 * keyframes ident a motion value commits, `steps` the offset map the
 * material delivers as (record data, never a face the row could paint:
 * an animation has no display value the way a token has one, so its
 * label is what every face shows beside the spelling).
 */
const animationEntry = (record) => ({
  name: record.id,
  label: record.label !== '' ? record.label : record.id,
  ref: animationSpelling(record),
  steps: isPlainObject(record.value) ? record.value : {},
  description: typeof record.description === 'string' ? record.description : '',
  record,
});

/**
 * Every rostered animation as a picker/completion entry, roster order.
 *
 * Roster order is the provider's (the published animations by label,
 * the compiled keyframes file's own order). The guide declares nothing
 * an animation joins against (no scale, no kind): the record carries its
 * label, spelling and steps whole, so only the roster is read.
 *
 * @param {object} materials
 *   The materials roster.
 *
 * @return {Array<{name: string, label: string, ref: string,
 *   steps: object, description: string, record: object}>}
 */
export const animationOptions = (materials) =>
  materials
    .ofKind(KIND.ANIMATION)
    .map((record) => animationEntry(record))
    .filter((entry) => entry.ref !== null);

/**
 * The animation a keyframes name IS, as a picker entry, or null.
 *
 * materialOf's twin in the animation slot: the grammar's whole-value
 * read of ONE keyframes name (a component of animation-name or the name
 * layer of the animation shorthand, never the whole list value, which
 * keyframes-names.js splits). A theme's own keyframes name, an animation
 * keyword, or a motion-{name} ident the roster lacks answers null.
 *
 * @param {object} materials
 *   The materials roster.
 * @param {string} name
 *   A keyframes name as written.
 *
 * @return {?object}
 *   An animationOptions entry.
 */
export const animationOf = (materials, name) => {
  const resolved = resolveValue(name, SLOT.ANIMATION, materials);
  return resolved.kind === KIND.ANIMATION
    ? animationEntry(resolved.record)
    : null;
};

/**
 * A style value that is an inline LITERAL to the grammar (a hex, a
 * keyword, a function, a compound, a var() with a fallback tail), trimmed,
 * or null for a material reference, an inline media/generate value, a
 * reference the roster lacks, or nothing.
 *
 * The one "is this a custom value" read behind the basic wells' painted
 * literal face and the color field's mint gate: whether a value is a
 * reference is the grammar's answer, never a regex here.
 *
 * @return {?string}
 */
export const inlineLiteral = (materials, value) => {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const spelled = value.trim().replace(/^var\(/i, 'var(');
  return resolveValue(spelled, SLOT.STYLE, materials).kind === KIND.LITERAL
    ? value.trim()
    : null;
};

/**
 * The gradient-kind swatch a value IS, or null: the edit-in-place gate
 * shared by the gradient chip, the media rows and the background widgets.
 *
 * @return {?object}
 *   The swatchOptions entry, or null for anything else (a literal
 *   gradient, a layered value, a color swatch, a token, an unknown name).
 */
export const gradientSwatchOf = (guide, materials, value) => {
  const entry = materialOf(guide, materials, value);
  return entry !== null &&
    entry.record.kind === KIND.SWATCH &&
    entry.kind === 'gradient'
    ? entry
    : null;
};

/**
 * A value with every material reference painted from roster data.
 *
 * The one face-paint rule wherever a palette value renders (gradient stop
 * colors, palette card faces, the color kind's editor face): each var()
 * inside the value that resolves to a token or swatch is substituted by
 * that record's display value, so a color swatch aliasing a token and a
 * gradient whose stops reference palette colors both paint without var()
 * resolution (the panel DOM may sit outside the token scope). References
 * the roster lacks stay verbatim and the face degrades honestly. The
 * scheme pass (`scheme` true) paints the dark half of a split face: the
 * value's own scheme entry when it carries one, and every reference from
 * the referenced record's scheme pair (falling back to its default, so an
 * unpaired reference paints alike on both halves).
 *
 * @param {?object} guide
 *   The style guide payload (conditions name the scheme axis).
 * @param {object} materials
 *   The materials roster.
 * @param {string|object} value
 *   A stored value: a plain string or a condition-keyed map.
 * @param {boolean} [scheme=false]
 *   Whether to paint the scheme half.
 *
 * @return {string}
 */
export const paintValue = (guide, materials, value, scheme = false) => {
  const base = scheme
    ? (schemeDisplayValue(guide, value) ?? tokenDisplayValue(value))
    : tokenDisplayValue(value);
  if (!base.includes('var(')) {
    return base;
  }
  const painted = new Map();
  references(base, SLOT.STYLE, materials).forEach((reference) => {
    if (reference.kind !== KIND.TOKEN && reference.kind !== KIND.SWATCH) {
      return;
    }
    const display = scheme
      ? schemeDisplayValue(guide, reference.record.value)
      : null;
    painted.set(
      reference.spelling,
      display ?? tokenDisplayValue(reference.record.value),
    );
  });
  if (painted.size === 0) {
    return base;
  }
  // Every reference the grammar found is a whole var(...) call as written;
  // substitute each call whose canonical spelling painted, fallbacks
  // included (a var(--x, red) paints as --x's value, the fallback being
  // what the browser would show only if the swatch were missing).
  return base.replace(
    /var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,[^()]*)?\)/g,
    (whole, name) => painted.get(`var(${name})`) ?? whole,
  );
};

/**
 * The face pair for one value (the stop-shape contract's paint/paintDark
 * at library scope): `paintDark` is non-null exactly when the scheme
 * pass paints differently, so a single-valued swatch keeps a whole face.
 *
 * @return {{paint: string, paintDark: ?string}}
 */
export const facePair = (guide, materials, value) => {
  const paint = paintValue(guide, materials, value);
  const dark = paintValue(guide, materials, value, true);
  return { paint, paintDark: dark !== paint ? dark : null };
};
