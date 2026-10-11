/**
 * The Fonts face's pure model: the animations model's twin for the type
 * material.
 *
 * The catalog's fonts section is a flat, filterable list of the fonts the
 * materials store carries (the roster's font records, delivery shape)
 * with a lifecycle card per font and a mint card that builds faces from
 * uploaded files. This module owns everything the view (fonts.js) can
 * compute without a DOM: the row roster with its face summary and
 * shipped marker, the card's gesture roster as data (presence means
 * permitted AND applicable), the mint card's face drafts (the guess from
 * a file's name, the op's faces shape, the duplicate-face rule the
 * validator applies), the stack grammar the Font row's token editor
 * builds a font-family value with, and the delivery trigger the leader
 * re-reads the @font-face print on. The view only renders and
 * dispatches.
 */

import { fontOptions, fontSpelling } from './materials.js';
import { isShipped, locked } from './variant-scope.js';

/**
 * The generic family keywords a stack may end with: a minted family added
 * to such a stack goes BEFORE the generic (the generic is the browser's
 * last resort, never a family to fall back from).
 */
export const GENERIC_FAMILIES = Object.freeze([
  'serif',
  'sans-serif',
  'monospace',
  'cursive',
  'fantasy',
  'system-ui',
  'ui-serif',
  'ui-sans-serif',
  'ui-monospace',
  'ui-rounded',
  'math',
  'emoji',
  'fangsong',
  'inherit',
  'initial',
  'unset',
  'revert',
]);

/**
 * The weight a file name's token names, the font world's usual words.
 */
const WEIGHT_WORDS = Object.freeze({
  thin: '100',
  hairline: '100',
  extralight: '200',
  ultralight: '200',
  light: '300',
  regular: '400',
  normal: '400',
  book: '400',
  roman: '400',
  text: '400',
  medium: '500',
  semibold: '600',
  demibold: '600',
  bold: '700',
  extrabold: '800',
  ultrabold: '800',
  black: '900',
  heavy: '900',
});

/**
 * A face's label on a row or a list: the weight, then the style when it
 * is not the normal one ("400", "700 italic", "100 900" for a range).
 *
 * @param {object} face
 *   A canonical face {weight, style, source} (the server's shape).
 *
 * @return {string}
 */
export const faceLabel = (face) => {
  const weight = String(face?.weight ?? '400').trim() || '400';
  const style = String(face?.style ?? 'normal').trim() || 'normal';
  return style === 'normal' ? weight : `${weight} ${style}`;
};

/**
 * The fonts list's row roster: filtered, flagged, in roster order.
 *
 * The animationRows twin: the search needle matches label, machine name
 * or family, case-insensitive. `faces` is the face labels in stored
 * order (the row's anatomy line), `shipped` the record's origin (the
 * lock, `updatable`, is read at the card). Usage counts are management
 * facts the card fetches per open, so rows carry none.
 *
 * @param {?Array<object>} fonts
 *   The font records (roster order).
 * @param {{search?: string}} filters
 *
 * @return {Array<{reference: string, label: string, family: string,
 *   spelling: ?string, faces: string[], shipped: boolean}>}
 */
export const fontRows = (fonts, { search = '' } = {}) => {
  const needle = search.trim().toLowerCase();
  return (fonts ?? [])
    .filter(
      (font) =>
        needle === '' ||
        `${font.label ?? ''}\n${font.id ?? ''}\n${font.value?.family ?? ''}`
          .toLowerCase()
          .includes(needle),
    )
    .map((font) => ({
      reference: font.id,
      label: font.label !== '' ? font.label : font.id,
      family: typeof font.value?.family === 'string' ? font.value.family : '',
      spelling: fontSpelling(font),
      faces: (Array.isArray(font.value?.faces) ? font.value.faces : []).map(
        faceLabel,
      ),
      shipped: isShipped(font),
    }));
};

/**
 * The lifecycle card's gesture roster, as data.
 *
 * animationCardGestures' twin: presence means the gesture is permitted
 * AND applicable, so nothing on the card is a dead control. The lock
 * (`updatable` false: a shipped font on a consuming site, where the
 * importer owns its family, faces and label and every write refuses
 * server-side) withdraws the editing gestures. Delete carries the two
 * permission grades: a named font needs the in-use grade (its delete
 * runs the remove-the-family rewrite batch), an unnamed one either
 * grade. No revert: a font's disk copy is bytes, and a shipped font
 * never diverges on a consuming site (the lock refuses every write).
 *
 * @param {object} font
 *   The font record (delivery shape).
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.fonts):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}.
 * @param {?{usages: number}} management
 *   The card's per-open management read, or null while in flight; then
 *   the count reads as zero (the prompt re-reads the live count before
 *   anything runs).
 *
 * @return {{rename: boolean, edit: boolean, delete: boolean}}
 */
export const fontCardGestures = (font, config = {}, management = null) => {
  const editable = !locked(font);
  const used = (management?.usages ?? 0) > 0;
  return {
    rename: editable && Boolean(config.canUpdate),
    edit: editable && Boolean(config.canUpdate),
    delete:
      editable &&
      (used
        ? Boolean(config.canDeleteInUse)
        : Boolean(config.canDelete) || Boolean(config.canDeleteInUse)),
  };
};

/**
 * The scale whose tokens are font-family stacks: the one the guide binds
 * font-family to at site level (the suite's `font` scale), or null when
 * nothing binds it. The client twin of FontUsage::fontScales(): a token
 * on that scale is where the Palette's editor offers the minted
 * families.
 *
 * @param {object} guide
 *
 * @return {?string}
 */
export const fontScale = (guide) => {
  const scale = guide?.constraintDefaults?.['font-family']?.scale;
  return typeof scale === 'string' && scale !== '' ? scale : null;
};

/**
 * A font-family stack's components: split on the commas outside quotes,
 * trimmed, empties dropped.
 *
 * @param {string} stack
 *
 * @return {string[]}
 */
export const stackComponents = (stack) => {
  const components = [];
  let current = '';
  let quote = null;
  for (const char of String(stack ?? '')) {
    if (quote !== null) {
      current += char;
      if (char === quote) {
        quote = null;
      }
    } else if (char === '"' || char === "'") {
      quote = char;
      current += char;
    } else if (char === ',') {
      components.push(current);
      current = '';
    } else {
      current += char;
    }
  }
  components.push(current);
  return components.map((part) => part.trim()).filter((part) => part !== '');
};

/**
 * A component's family name: quotes stripped, escapes folded.
 */
const familyOf = (component) => {
  const text = String(component ?? '').trim();
  const quoted = /^(["'])(.*)\1$/s.exec(text);
  return (quoted ? quoted[2] : text).replace(/\\(.)/g, '$1');
};

/**
 * A stack with a minted family added: the Font row token editor's chip.
 *
 * An empty stack becomes the family alone; a stack already naming the
 * family (quoted or bare) is unchanged; otherwise the family joins the
 * stack in front of a trailing generic (`Georgia, serif` gains Inter
 * before serif, the generic staying the last resort) or at the end.
 *
 * @param {string} stack
 *   The current value.
 * @param {string} spelling
 *   The font's quoted family (the record's font spelling).
 *
 * @return {string}
 */
export const addFamilyToStack = (stack, spelling) => {
  const components = stackComponents(stack);
  const family = familyOf(spelling);
  if (components.some((component) => familyOf(component) === family)) {
    return components.join(', ');
  }
  const last = components[components.length - 1];
  if (
    last !== undefined &&
    GENERIC_FAMILIES.includes(familyOf(last).toLowerCase())
  ) {
    return [...components.slice(0, -1), spelling, last].join(', ');
  }
  return [...components, spelling].join(', ');
};

/**
 * The weight and style a font file's name suggests.
 *
 * A convenience seed for the mint card's face row, never authority (the
 * user corrects it, the server canonicalizes): the usual words
 * (light, regular, semibold, bold, black, ...), a bare hundred (700), a
 * variable-font marker (a 100 to 900 range), and italic or oblique.
 *
 * @param {string} name
 *   The file's name.
 *
 * @return {{weight: string, style: string}}
 */
export const guessFace = (name) => {
  const base = String(name ?? '')
    .replace(/\.[a-z0-9]+$/i, '')
    .toLowerCase();
  let weight = '400';
  let style = 'normal';
  base
    .split(/[^a-z0-9]+/)
    .filter((token) => token !== '')
    .forEach((token) => {
      let word = token;
      if (word.endsWith('italic') && word !== 'italic') {
        style = 'italic';
        word = word.slice(0, -'italic'.length);
      }
      if (word === 'italic' || word === 'oblique') {
        style = 'italic';
      } else if (word === 'variable' || word === 'vf') {
        weight = '100 900';
      } else if (/^[1-9]00$/.test(word)) {
        weight = word;
      } else if (Object.prototype.hasOwnProperty.call(WEIGHT_WORDS, word)) {
        weight = WEIGHT_WORDS[word];
      }
    });
  return { weight, style };
};

/**
 * A mint card face draft from an upload's answer.
 *
 * @param {{file: string, name: string, format: ?string, url: string}} upload
 *   The upload route's response.
 * @param {number} key
 *   The draft's stable identity in the card's list.
 *
 * @return {{key: number, file: string, name: string, format: ?string,
 *   url: string, weight: string, style: string}}
 */
export const faceDraft = (upload, key) => ({
  key,
  file: upload.file,
  name: upload.name,
  format: upload.format ?? null,
  url: upload.url,
  ...guessFace(upload.name),
});

/**
 * The op's faces from the card's drafts: [{weight, style, source: {file}}]
 * (the typed source; a hosted kind is a later key beside `file`).
 *
 * @param {Array<object>} drafts
 *
 * @return {Array<{weight: string, style: string, source: {file: string}}>}
 */
export const facesOf = (drafts) =>
  drafts.map((draft) => ({
    weight: String(draft.weight ?? '').trim() || '400',
    style: String(draft.style ?? '').trim() || 'normal',
    source: { file: draft.file },
  }));

/**
 * The first draft repeating an earlier draft's (weight, style), or -1.
 *
 * The client twin of the validator's DUPLICATE_FONT_FACE (one face per
 * weight and style; PHP is authoritative and folds keywords), read on
 * the drafts as typed so the card can refuse the mint before the round
 * trip. Weight keywords fold as the server does.
 *
 * @param {Array<object>} drafts
 *
 * @return {number}
 */
export const duplicateFace = (drafts) => {
  const seen = new Set();
  const fold = (draft) => {
    const face = facesOf([draft])[0];
    const weight = WEIGHT_WORDS[face.weight.toLowerCase()] ?? face.weight;
    return `${weight.replace(/\s+/g, ' ')}|${face.style.toLowerCase()}`;
  };
  return drafts.findIndex((draft) => {
    const key = fold(draft);
    if (seen.has(key)) {
      return true;
    }
    seen.add(key);
    return false;
  });
};

/**
 * The delivery trigger: the rostered fonts' identity and content as one
 * string, so the leader re-reads the @font-face print exactly when a
 * font joined, left or changed (a mint, an edit, a delete, this window's
 * or the paired one's), never on an unrelated store change.
 *
 * @param {object} materials
 *   The materials roster.
 *
 * @return {string}
 */
export const fontsSignature = (materials) =>
  JSON.stringify(
    fontOptions(materials).map((entry) => [
      entry.name,
      entry.family,
      entry.faces,
    ]),
  );
