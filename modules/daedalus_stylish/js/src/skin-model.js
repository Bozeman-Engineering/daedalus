/**
 * The Skin tab's pure model: one model for the skin's three sections.
 *
 * The Design system place's Skin tab lists what the worn skin carries:
 * its tokens per scale, then its animations, then its fonts, one row and
 * one card per material whatever its kind (material-card.js). This module
 * owns everything the view (skin.js, material-card.js) can compute
 * without a DOM: the sections with their rows under one search, the one
 * card gesture roster as data (presence means permitted AND applicable;
 * a lock names why a shown gesture is withheld) over the record plus the
 * card's per-open management read (usages, layer, lock, shipped state),
 * the route path a kind's identity gestures take, the token value
 * grammar (the condition-honest editor split, the scheme pair, the name
 * grammar, the editor by shape with its mint seed), the keyframes grammar
 * (the offset twin of SkinAnimationsItem, the step track over the shared
 * track math, the per-step analysis, the live preview sheet), and the
 * font grammar (faces, the name a label derives, the stack a family
 * joins, the delivery trigger). Node-tested in skin-model.test.mjs.
 */

import { analyzeIsland, printIsland } from './code/island.js';
import { widePropertyNames } from './code/wide-tier.js';
import {
  isPlainObject,
  schemeCondition,
  tokenDisplayValue,
} from './style-guide.js';
import { mentionsMedia } from './media-token.js';
import {
  animationOptions,
  animationSpelling,
  fontOptions,
  fontSpelling,
  styleReference,
  tokenOptions,
} from './materials.js';
import { printKeyframes } from './printer.js';
import {
  insertStop,
  moveStop,
  positionPercent,
  removeStop,
  trackPercent,
} from './track-model.js';
import { isShipped } from './variant-scope.js';
import { defaultGradientValue } from './gradient-builder-model.js';

/**
 * The three kinds a skin carries, in the tab's section order.
 */
export const SKIN_KINDS = Object.freeze(['token', 'animation', 'font']);

/**
 * The route path of a skin material's identity gestures (usage, rename,
 * revert, delete): the skin's section, then the id.
 *
 * A token's roster id is "{scale}/{name}", two path segments on the wire
 * (tokens/{scale}/{name}: Drupal's router matches by outline, one
 * wildcard per parameter, so a slash inside one parameter never reaches
 * a route with a segment after it); an animation's and a font's id is
 * its name alone.
 *
 * @param {string} kind
 *   'token', 'animation' or 'font'.
 * @param {string} reference
 *   The roster id.
 *
 * @return {string}
 *   The path under the site root, no leading slash.
 */
export const materialRoute = (kind, reference) => {
  if (kind === 'token') {
    const slash = reference.indexOf('/');
    const scale = slash === -1 ? reference : reference.slice(0, slash);
    const name = slash === -1 ? '' : reference.slice(slash + 1);
    return `daedalus_stylish/skin/tokens/${encodeURIComponent(scale)}/${encodeURIComponent(name)}`;
  }
  if (kind === 'animation' || kind === 'font') {
    return `daedalus_stylish/skin/${kind}s/${encodeURIComponent(reference)}`;
  }
  throw new Error(`"${kind}" is not a skin material kind.`);
};

// --- Token values: the condition-honest editor split and the scheme pair. ---

/**
 * A token value split for the string editors (condition honesty).
 *
 * Shape editors edit ONE string. A plain string value edits whole; a
 * condition-keyed value edits its 'default' entry, with the other
 * condition names surfaced so the card can say honestly what the editor
 * is not touching (mergedValue reassembles them verbatim on commit,
 * never a silent flattening).
 *
 * @param {string|object} value
 *   The stored token value.
 *
 * @return {{draft: string, conditions: string[]}}
 *   The editor's draft and the condition names it leaves untouched.
 */
export const editableValue = (value) => {
  if (isPlainObject(value)) {
    return {
      draft: typeof value.default === 'string' ? value.default : '',
      conditions: Object.keys(value).filter(
        (condition) => condition !== 'default',
      ),
    };
  }
  return { draft: typeof value === 'string' ? value : '', conditions: [] };
};

/**
 * The editor's committed draft merged back into the stored value shape.
 *
 * editableValue's inverse: a string value becomes the draft; a
 * condition-keyed value keeps every other condition entry byte-identical
 * and replaces only 'default'.
 *
 * @param {string|object} value
 *   The stored token value the edit started from.
 * @param {string} draft
 *   The editor's committed string.
 *
 * @return {string|object}
 *   The value to store, the other conditions kept verbatim.
 */
export const mergedValue = (value, draft) =>
  isPlainObject(value) ? { ...value, default: draft } : draft;

/**
 * A token value split for per-scheme (light/dark) authoring.
 *
 * editableValue()'s scheme-aware sibling: the pair control edits TWO
 * strings, the default entry and the entry under the site's scheme
 * condition (schemeCondition(); the guide alone decides which name rides
 * the scheme axis, so compound keys like 'narrow+dark' never surface
 * here). `darkDraft` is null when the value carries no authorable scheme
 * entry (a plain string, an empty entry, or a guide without a scheme
 * axis: `scheme` null disables pair authoring entirely). `conditions`
 * lists only the entries the pair control is NOT touching.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string|object} value
 *   The stored token value.
 *
 * @return {{draft: string, darkDraft: ?string, scheme: ?string,
 *   conditions: string[]}}
 *   The pair control's drafts, the scheme condition, and the condition
 *   names the editor leaves untouched.
 */
export const editablePair = (guide, value) => {
  const scheme = schemeCondition(guide);
  const { draft, conditions } = editableValue(value);
  const entry =
    scheme !== null && isPlainObject(value) ? value[scheme] : undefined;
  return {
    draft,
    darkDraft: typeof entry === 'string' && entry !== '' ? entry : null,
    scheme,
    conditions: conditions.filter((condition) => condition !== scheme),
  };
};

/**
 * The pair control's committed drafts merged back into the stored shape.
 *
 * editablePair()'s inverse: 'default' becomes the draft, the scheme
 * entry becomes darkDraft, and every OTHER condition entry stays
 * byte-identical. A null darkDraft REMOVES the scheme entry, and when
 * only 'default' remains the value collapses to a plain string, so a
 * de-paired token paints a whole face again. With no scheme axis this is
 * exactly mergedValue().
 *
 * @param {string|object} value
 *   The stored token value the edit started from.
 * @param {{draft: string, darkDraft: ?string, scheme: ?string}} pair
 *   The pair control's committed drafts (editablePair()'s shape).
 *
 * @return {string|object}
 *   The value to store.
 */
export const mergedPair = (value, { draft, darkDraft, scheme }) => {
  const merged = mergedValue(value, draft);
  if (scheme === null || scheme === undefined) {
    return merged;
  }
  if (darkDraft === null) {
    if (!isPlainObject(merged) || merged[scheme] === undefined) {
      return merged;
    }
    const rest = Object.fromEntries(
      Object.entries(merged).filter(([name]) => name !== scheme),
    );
    const names = Object.keys(rest);
    return names.length === 1 && names[0] === 'default' ? rest.default : rest;
  }
  return isPlainObject(merged)
    ? { ...merged, [scheme]: darkDraft }
    : { default: merged, [scheme]: darkDraft };
};

/**
 * A material's layer as the roster record tells it: the package's or the
 * site's.
 *
 * The provider stamps origin per material (shipped when the skin's
 * package layer holds the name, user for a site-minted one and for every
 * material of the site's own skin), so a row knows which layer it lists
 * before any card opens. A site override OVER a package record keeps the
 * shipped origin: the row says "package" and the card's management read
 * (the usage route's `layer`: package, site or both) refines it.
 *
 * @param {object} record
 *   The material record (delivery shape).
 *
 * @return {string}
 *   'package' or 'site'.
 */
export const materialLayer = (record) =>
  isShipped(record) ? 'package' : 'site';

// --- The token name grammar and the editor by shape. ---

/**
 * The token name grammar: the client twin of StyleMapValidator::TOKEN_NAME.
 *
 * Lowercase letters, digits and inner hyphens ("4", "2xl", "accent-hover");
 * the name becomes the tail of the custom property. PHP is authoritative
 * (the op refuses INVALID_TOKEN_NAME); the mint card reads this to
 * withhold Save before the round trip.
 */
export const TOKEN_NAME = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

/**
 * Why a token name cannot be minted on a scale right now, or null.
 *
 * @param {string} name
 *   The typed name, trimmed by the caller.
 * @param {?string} scale
 *   The scale the mint targets; null when the server infers it from the
 *   value's shape (the roster cannot be asked then; the op refuses a
 *   taken name and the source reports it).
 * @param {object} materials
 *   The materials roster (a token id is "{scale}/{name}", so the same
 *   name on another scale is fine).
 *
 * @return {?string}
 *   'missing' (empty), 'invalid' (off the grammar), 'taken' (the active
 *   skin already mints it), or null when the name can go.
 */
export const tokenNameError = (name, scale, materials) => {
  if (name === '') {
    return 'missing';
  }
  if (!TOKEN_NAME.test(name)) {
    return 'invalid';
  }
  return scale !== null && materials.has('token', `${scale}/${name}`)
    ? 'taken'
    : null;
};

/**
 * The scale whose tokens are font-family stacks: the one the guide binds
 * font-family to at site level (the suite's `font` scale), or null when
 * nothing binds it. The client twin of SkinMaterialUsage::fontScales(): a
 * token on that scale is where the editor offers the minted families.
 *
 * @param {object} guide
 *   The style guide payload (constraintDefaults).
 *
 * @return {?string}
 *   The scale id, or null.
 */
export const fontScale = (guide) => {
  const scale = guide?.constraintDefaults?.['font-family']?.scale;
  return typeof scale === 'string' && scale !== '' ? scale : null;
};

/**
 * The value editor a token's scale mounts, by shape.
 *
 * A scale's shape names the editor its tokens edit through, on the card
 * and on the mint card alike: the color field for a color scale, the
 * gradient builder for a gradient scale, the stack editor with the minted
 * families for the scale the guide binds font-family to (fontScale), the
 * text editor for every other shape (a dimension, a number, a keyword, a
 * timing string: references included).
 *
 * @param {object} guide
 *   The guide payload (constraintDefaults names the font scale).
 * @param {?string} scale
 *   The token's scale id.
 * @param {?string} shape
 *   The scale's declared shape.
 *
 * @return {'color'|'gradient'|'stack'|'text'}
 *   The editor kind.
 */
export const tokenEditorKind = (guide, scale, shape) => {
  if (shape === 'color') {
    return 'color';
  }
  if (shape === 'gradient') {
    return 'gradient';
  }
  if (typeof scale === 'string' && scale === fontScale(guide)) {
    return 'stack';
  }
  return 'text';
};

/**
 * A scale's mint seed, by shape.
 *
 * The neutral wheel-ownable hex for a color scale (strict governs
 * consumers, never authors: the skin tab is the sanctioned extension
 * point strictness funnels people toward, so the seed never redirects to
 * a palette reference and the guide's enforcement is deliberately not
 * read), the builder's default gradient over the palette the materials
 * offer for a gradient scale, nothing for the rest (a dimension has no
 * honest default).
 *
 * @param {?string} shape
 *   The scale's declared shape.
 * @param {object} guide
 *   The guide payload.
 * @param {object} materials
 *   The materials roster the seed's palette reads from.
 *
 * @return {string}
 *   The seed value, '' for a shape with no honest default.
 */
export const tokenSeed = (shape, guide = {}, materials = null) => {
  if (shape === 'color') {
    return '#808080';
  }
  if (shape === 'gradient') {
    return defaultGradientValue(guide, materials);
  }
  return '';
};

/**
 * Whether a token's value paints a face chip: a color or a gradient.
 *
 * Both are CSS backgrounds a chip can wear (face.js Face paints the
 * display value as `background`); every other shape shows its text alone.
 *
 * @param {?string} shape
 *   The scale's declared shape.
 *
 * @return {boolean}
 *   Whether a face chip paints the value.
 */
export const tokenPaintsFace = (shape) =>
  shape === 'color' || shape === 'gradient';

// --- The card's gesture roster. ---

/**
 * The kinds "+ New" offers: a token when the guide declares a scale to
 * mint it on, an animation, a font; nothing without the create grant.
 *
 * @param {object} guide
 *   The style guide payload (tokenScales).
 * @param {object} config
 *   The skin material gates (drupalSettings.daedalusStylish.skinMaterials).
 *
 * @return {string[]}
 *   The mintable kinds, in menu order.
 */
export const mintableKinds = (guide, config = {}) => {
  if (!config?.canCreate) {
    return [];
  }
  return SKIN_KINDS.filter(
    (kind) =>
      kind !== 'token' || Object.keys(guide?.tokenScales ?? {}).length > 0,
  );
};

/**
 * The seed a "Copy to new {kind}" hands the mint card: the record's
 * value and description under no name, so the copy is a fresh material
 * the person names (a copy under the same name is the material itself).
 *
 * A token keeps its scale; an animation its steps. A font has no copy
 * (its faces are its files, and a skin holds one font per family), so
 * the card offers none and this answers null.
 *
 * @param {object} record
 *   The material record (delivery shape).
 *
 * @return {?{kind: string, scale?: string, value?: (string|object),
 *   steps?: object, description: string}}
 *   The mint card's seed, or null for a font.
 */
export const copySeed = (record) => {
  const description =
    typeof record?.description === 'string' ? record.description : '';
  if (record?.kind === 'token') {
    return {
      kind: 'token',
      scale: record.group ?? null,
      value: record.value,
      description,
    };
  }
  if (record?.kind === 'animation') {
    return {
      kind: 'animation',
      steps: isPlainObject(record.value) ? record.value : {},
      description,
    };
  }
  return null;
};

/**
 * The material card's gesture roster, as data, one rule for the three
 * kinds.
 *
 * A skin material is always editable into the skin's site layer (a
 * consuming site's write to a shipped record lands over the package's,
 * never in it), so the update grant alone opens the value, description
 * and offered editors: `update skin materials` IS the override grade,
 * and withholding it is how a locked tenant reads its skin. What the
 * package layer's lock refuses is the material's IDENTITY: rename and
 * delete are the site's own on a site-minted material (the record's
 * origin) and on every material where the lock is lifted (the management
 * read's `locked`: false on the site's own skin and under the developer
 * gate), never on a package record a consuming site only overrides.
 * Revert (the route that drops the site's record, so the package's shows
 * through: the unset gesture) follows the shipped state. Delete carries
 * the two permission grades: a referenced material needs the in-use
 * grade (its delete runs the rewrite-then-delete batch), an unreferenced
 * one either grade. Copy to new needs the create grant and a kind a copy
 * makes sense for (copySeed).
 *
 * `rename` and `delete` are true when the gesture is offered live.
 * `locks` says why a gesture the account holds a grant for is withheld
 * right now, so the card can show it disabled with the reason instead of
 * hiding it: 'shipped' (the package owns the identity; while the
 * management read is in flight the lock reads as holding), 'in-use' (a
 * referenced material under the plain delete grade). Null when the
 * gesture is live or the account has no grant for it at all (then it is
 * not shown).
 *
 * @param {object} record
 *   The material record (delivery shape).
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.skinMaterials):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}.
 * @param {?{usages: number, layer: string, locked: boolean,
 *   shipped_state: ?string}} management
 *   The card's per-open management read, or null while it is in flight
 *   or not readable: then the count reads as zero, the lock as holding,
 *   and revert waits for the state.
 *
 * @return {{editValue: boolean, editDescription: boolean, offer: boolean,
 *   rename: boolean, revert: boolean, delete: boolean, copy: boolean,
 *   locks: {rename: ?string, delete: ?string}}}
 *   The gestures the card offers, and the reason each lock holds.
 */
export const materialCardGestures = (
  record,
  config = {},
  management = null,
) => {
  const update = Boolean(config.canUpdate);
  const anyDelete = Boolean(config.canDelete) || Boolean(config.canDeleteInUse);
  const used = (management?.usages ?? 0) > 0;
  const shipped = isShipped(record);
  const lockedIdentity = shipped && management?.locked !== false;
  const deleteGrade = used ? Boolean(config.canDeleteInUse) : anyDelete;
  return {
    editValue: update,
    editDescription: update,
    offer: update,
    rename: update && !lockedIdentity,
    revert: update && shipped && management?.shipped_state === 'modified',
    delete: !lockedIdentity && deleteGrade,
    copy: Boolean(config.canCreate) && copySeed(record) !== null,
    locks: {
      rename: update && lockedIdentity ? 'shipped' : null,
      delete: !anyDelete
        ? null
        : lockedIdentity
          ? 'shipped'
          : !deleteGrade
            ? 'in-use'
            : null,
    },
  };
};

// --- Keyframes: the offset grammar, the step track, the analysis. ---

/**
 * The animation-* longhands a keyframe step may carry: the server's
 * validateAnimationSteps() admits the per-step easing only; every other
 * animation property (and the shorthand) describes the WEARER, not a
 * step, and is refused (INVALID_ANIMATION_STEP_PROPERTY).
 */
const STEP_ANIMATION_PROPERTIES = ['animation-timing-function'];

/**
 * Whether a property describes the element wearing the animation rather
 * than a step: the animation shorthand, every animation-* longhand but the
 * per-step easing, and timeline-trigger (the trigger of the animation, the
 * wearer's business exactly as animation-trigger is).
 *
 * @param {string} property
 *   The CSS property name.
 *
 * @return {boolean}
 *   Whether the property belongs to the wearer.
 */
const wearerProperty = (property) =>
  (property === 'animation' ||
    property === 'timeline-trigger' ||
    property.startsWith('animation-')) &&
  !STEP_ANIMATION_PROPERTIES.includes(property);

/**
 * The seed steps a fresh animation starts from: a fade, the canonical
 * demo, so the mint card's track and islands carry something honest
 * before the user touches them.
 */
export const SEED_STEPS = Object.freeze({
  '0%': { opacity: '0' },
  '100%': { opacity: '1' },
});

/**
 * A canonical offset: 'from' and 'to' fold to their percentages, a
 * percent renders trimmed (no trailing zeros, no dangling point), and
 * anything else (a keyword, a range, a comma list, over 100) is null.
 *
 * The client twin of SkinAnimationsItem::canonicalOffset() (PHP is
 * authoritative): the session writes canonical offsets so the steps it
 * commits are byte-identical to what the setter stores, and the track's
 * "N%" read (positionPercent) maps every canonical offset.
 *
 * @param {string} offset
 *   The offset as written.
 *
 * @return {?string}
 *   The canonical percent, or null.
 */
export const canonicalOffset = (offset) => {
  const text = String(offset ?? '')
    .trim()
    .toLowerCase();
  if (text === 'from') {
    return '0%';
  }
  if (text === 'to') {
    return '100%';
  }
  const match = text.match(/^(\d+(?:\.\d+)?|\.\d+)%$/);
  if (!match) {
    return null;
  }
  const number = Number(match[1]);
  if (number > 100) {
    return null;
  }
  const rendered = number.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
  return `${rendered === '' ? '0' : rendered}%`;
};

/**
 * A steps map canonicalized: offsets folded and sorted ascending.
 *
 * SkinAnimationsItem::canonicalizeSteps()'s twin: offsets that do not
 * canonicalize are kept verbatim and sort last (stably, by string), and
 * two spellings of one offset collapse with the later entry winning.
 *
 * @param {object} steps
 *   Offset -> declarations.
 *
 * @return {object}
 *   Canonical offset -> declarations, in offset order.
 */
export const canonicalizeSteps = (steps) => {
  const folded = {};
  Object.entries(isPlainObject(steps) ? steps : {}).forEach(
    ([offset, declarations]) => {
      folded[canonicalOffset(offset) ?? offset] = declarations;
    },
  );
  const keys = Object.keys(folded).sort((a, b) => {
    const left = canonicalOffset(a);
    const right = canonicalOffset(b);
    if (left === null || right === null) {
      return (
        (left === null ? 1 : 0) - (right === null ? 1 : 0) ||
        (a < b ? -1 : a > b ? 1 : 0)
      );
    }
    return parseFloat(left) - parseFloat(right);
  });
  return Object.fromEntries(keys.map((key) => [key, folded[key]]));
};

/**
 * The session's step track: one stop per step, canonical offsets as
 * positions, ascending.
 *
 * The keyframes editor's stops are `{position, declarations}` over the
 * generic track (track-model.js reads `position` only): an offset IS a
 * position, so every canonical offset maps onto the track math.
 *
 * @param {object} steps
 *   Offset -> declarations (stored shape).
 *
 * @return {Array<{position: string, declarations: object}>}
 *   The track's stops, in offset order.
 */
export const stepStops = (steps) =>
  Object.entries(canonicalizeSteps(steps)).map(([position, declarations]) => ({
    position,
    declarations: isPlainObject(declarations) ? declarations : {},
  }));

/**
 * The track's stops back as the stored steps map (stepStops' inverse):
 * position -> declarations, in track order.
 *
 * @param {Array<{position: string, declarations: object}>} stops
 *   The track's stops.
 *
 * @return {object}
 *   Offset -> declarations.
 */
export const stepsOf = (stops) =>
  Object.fromEntries(stops.map((stop) => [stop.position, stop.declarations]));

/**
 * Whether two steps maps commit the same thing (the session's dirty
 * read): canonical offsets, declarations compared by property and value.
 *
 * @param {object} a
 *   A steps map.
 * @param {object} b
 *   Another steps map.
 *
 * @return {boolean}
 *   Whether the two commit the same thing.
 */
export const sameSteps = (a, b) => {
  const left = canonicalizeSteps(a);
  const right = canonicalizeSteps(b);
  const offsets = Object.keys(left);
  if (offsets.length !== Object.keys(right).length) {
    return false;
  }
  return offsets.every((offset) => {
    const mine = isPlainObject(left[offset]) ? left[offset] : {};
    const theirs = isPlainObject(right[offset]) ? right[offset] : {};
    const properties = Object.keys(mine);
    return (
      Object.prototype.hasOwnProperty.call(right, offset) &&
      properties.length === Object.keys(theirs).length &&
      properties.every((property) => theirs[property] === mine[property])
    );
  });
};

/**
 * Whether a percent already has a step (two steps can never share an
 * offset: the validator's DUPLICATE_ANIMATION_OFFSET).
 *
 * @param {Array<{position: string, declarations: object}>} stops
 *   The track's stops.
 * @param {number} percent
 *   The percent to test.
 * @param {number} [except]
 *   A stop index to leave out (the moved step's own).
 *
 * @return {boolean}
 *   Whether another stop sits at the percent.
 */
const occupied = (stops, percent, except = -1) =>
  stops.some(
    (stop, index) =>
      index !== except && positionPercent(stop.position) === percent,
  );

/**
 * A drag (or numeric offset edit): the moved step lands at the clamped
 * percent, sorted, selected; null when another step already sits there
 * (an offset is one step; the gesture cannot merge two).
 *
 * @param {Array<{position: string, declarations: object}>} stops
 *   The track's stops.
 * @param {number} index
 *   The moved step's index.
 * @param {number} percent
 *   The percent it lands at.
 *
 * @return {?{stops: Array, selected: number}}
 *   The sorted stops and the selected index, or null.
 */
export const moveStep = (stops, index, percent) =>
  occupied(stops, trackPercent(percent), index)
    ? null
    : moveStop(stops, index, percent);

/**
 * A click on empty track: a new EMPTY step at the click (an empty step is
 * legal; the user types its declarations), sorted, selected; null when
 * the percent already has a step.
 *
 * @param {Array<{position: string, declarations: object}>} stops
 *   The track's stops.
 * @param {number} percent
 *   The clicked percent.
 *
 * @return {?{stops: Array, selected: number}}
 *   The sorted stops and the selected index, or null.
 */
export const insertStep = (stops, percent) =>
  occupied(stops, trackPercent(percent))
    ? null
    : insertStop(stops, { declarations: {} }, percent);

/**
 * A removal with the animation's floor: null below one step (an
 * animation needs at least one); otherwise the track model's selection
 * survival.
 *
 * @param {Array} stops
 *   The track's stops.
 * @param {number} index
 *   The removed step's index.
 * @param {number} selected
 *   The selected index before the removal.
 *
 * @return {?{stops: Array, selected: number}}
 *   The remaining stops and the surviving selection, or null.
 */
export const removeStep = (stops, index, selected) =>
  removeStop(stops, index, selected, 1);

/**
 * A step's island text: its declarations, one per line.
 *
 * @param {object} declarations
 *   Property -> value.
 *
 * @return {string}
 *   The island text.
 */
export const printStep = (declarations) =>
  printIsland(
    Object.entries(isPlainObject(declarations) ? declarations : {}).map(
      ([property, value]) => ({ property, value }),
    ),
  );

/**
 * The steps in one line, the card's closed anatomy: every step as
 * `offset { declarations }` joined by middots, declarations as the
 * printed step reads.
 *
 * @param {object} steps
 *   Offset -> declarations (stored shape).
 *
 * @return {string}
 *   '' when no step prints anything.
 */
export const keyframesSummary = (steps) =>
  stepStops(steps)
    .map((stop) => {
      const body = Object.entries(stop.declarations)
        .map(([property, value]) => `${property}: ${value}`)
        .join('; ');
      return `${stop.position} { ${body} }`;
    })
    .join(' · ');

/**
 * The group id the whole generated wide tier grants under on a step's
 * node: the tier is one tier, so one group carries every wide name.
 */
export const WIDE_TIER_SET = 'wide:*';

/**
 * The node a keyframe step validates against: the universal vocabulary.
 *
 * The server validates a step's declarations with EVERY group declared,
 * curated and generated alike (validateAnimationSteps(): a step is not
 * an element and grants nothing per kind), so the island analysis runs
 * over a node granting every property of every curated group the guide
 * serves plus, under WIDE_TIER_SET, every wide-tier name. No value
 * spaces: a step is not an address, so no roster binds it here.
 *
 * @param {object} guide
 *   The style guide payload (groups).
 *
 * @return {{grants: Object<string, string[]>}}
 *   The node the analysis runs over.
 */
export const stepDeclaration = (guide) => {
  const grants = {};
  Object.entries(isPlainObject(guide?.groups) ? guide.groups : {}).forEach(
    ([id, group]) => {
      grants[id] = Object.keys(
        isPlainObject(group?.properties) ? group.properties : {},
      );
    },
  );
  grants[WIDE_TIER_SET] = widePropertyNames();
  return { grants };
};

/**
 * Analyzes one step's island text: the island analysis under the
 * universal vocabulary, plus the keyframe-only rules.
 *
 * On top of analyzeIsland (recognition, the closed world, typed values,
 * token references): an animation-* property other than the per-step
 * easing, and timeline-trigger, are refused (INVALID_ANIMATION_STEP_PROPERTY:
 * the wearer's business, never a step's), and a media token is refused
 * (INVALID_STYLE_VALUE: the compiled keyframes record no media and the
 * validator refuses it), each marking its declaration invalid so a settle
 * drops it. A structural chunk (a nested rule, an at-rule) is an error
 * here rather than a door chunk: a step addresses no key on any page, so
 * there is no parse door to hand a block to. Diagnostics stay
 * CM6-lint-shaped.
 *
 * @param {string} text
 *   The island text.
 * @param {{guide: object, materials: object}} options
 *   The guide and the materials roster the analysis reads.
 *
 * @return {{declarations: Array<object>, diagnostics: Array<object>}}
 *   The parsed declarations and the CM6-shaped diagnostics.
 */
export const analyzeStep = (text, { guide, materials } = {}) => {
  const analysis = analyzeIsland(text, {
    guide,
    materials,
    declaration: stepDeclaration(guide),
  });
  const diagnostics = [...analysis.diagnostics];
  (analysis.structural ?? []).forEach((chunk) => {
    diagnostics.push({
      from: chunk.from,
      to: chunk.to,
      severity: 'error',
      message:
        'A keyframe step holds declarations only; a nested rule or at-rule has no place in a step.',
      code: 'INVALID_ANIMATION_STEP',
    });
  });
  analysis.declarations.forEach((record) => {
    if (!record.valid) {
      return;
    }
    if (wearerProperty(record.property)) {
      record.valid = false;
      diagnostics.push({
        from: record.from,
        to: record.from + record.property.length,
        severity: 'error',
        message: `"${record.property}" describes the element wearing the animation, not a step; a step may carry animation-timing-function only.`,
        code: 'INVALID_ANIMATION_STEP_PROPERTY',
      });
    } else if (mentionsMedia(record.value)) {
      record.valid = false;
      diagnostics.push({
        from: record.valueFrom,
        to: record.valueTo,
        severity: 'error',
        message:
          'A keyframe step cannot reference media; the compiled keyframes carry literal and token values only.',
        code: 'INVALID_STYLE_VALUE',
      });
    }
  });
  return {
    declarations: analysis.declarations,
    diagnostics: diagnostics.sort((a, b) => a.from - b.from),
  };
};

/**
 * A step's settled declarations: the buffer's valid declarations as a
 * map, later duplicates winning (how the printed step reads), invalid
 * lines held out (the server would refuse the whole save).
 *
 * @param {Array<object>} declarations
 *   analyzeStep()'s declarations.
 *
 * @return {{declarations: object, hasErrors: boolean}}
 *   The settled map and whether any line was held out.
 */
export const settledStep = (declarations) => {
  const settled = {};
  let hasErrors = false;
  declarations.forEach((record) => {
    if (record.valid) {
      settled[record.property] = record.value;
    } else {
      hasErrors = true;
    }
  });
  return { declarations: settled, hasErrors };
};

/**
 * The live preview sheet: every rostered animation's @keyframes, a
 * session's draft steps substituted for the animation it edits.
 *
 * The compiled skin file this page loaded predates any animation minted
 * or edited this session, so the leader prints the whole roster into a
 * <style> tag later in the document than the file's <link> (the last
 * `@keyframes` of a name in document order wins): a mint plays before any
 * reload, a settled session step plays on every wearer, and the
 * committed record supersedes the draft on the next store change.
 *
 * @param {object} materials
 *   The materials roster.
 * @param {Map<string, object>} drafts
 *   Animation id -> draft steps map (the sessions' live drafts).
 *
 * @return {string}
 *   The sheet text, '' when nothing prints.
 */
export const animationPreviewCss = (materials, drafts = new Map()) =>
  animationOptions(materials)
    .map((animation) =>
      printKeyframes(
        animation.ref,
        drafts.get(animation.name) ?? animation.steps,
      ),
    )
    .filter((css) => css !== '')
    .join('\n');

// --- Fonts: faces, the name, the stack, the delivery trigger. ---

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
 *   The label.
 */
export const faceLabel = (face) => {
  const weight = String(face?.weight ?? '400').trim() || '400';
  const style = String(face?.style ?? 'normal').trim() || 'normal';
  return style === 'normal' ? weight : `${weight} ${style}`;
};

/**
 * The font name a human label derives: the roster id's grammar.
 *
 * A font's name is the skin's roster id (lowercase letters, digits,
 * inner hyphens or underscores; SkinFontsItem::NAME on the server), while
 * what the person types on the mint card is the FAMILY ("Scope One"), so
 * the card derives the name: lowercased, every run outside the charset
 * an underscore, the ends trimmed. "Scope One" is scope_one.
 *
 * @param {string} label
 *   The family as typed.
 *
 * @return {string}
 *   The name, '' when nothing in the label survives the charset.
 */
export const fontName = (label) =>
  String(label ?? '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

/**
 * A font-family stack's components: split on the commas outside quotes,
 * trimmed, empties dropped.
 *
 * @param {string} stack
 *   The font-family value.
 *
 * @return {string[]}
 *   The components, as written.
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
 *
 * @param {string} component
 *   One stack component.
 *
 * @return {string}
 *   The family name.
 */
const familyOf = (component) => {
  const text = String(component ?? '').trim();
  const quoted = /^(["'])(.*)\1$/s.exec(text);
  return (quoted ? quoted[2] : text).replace(/\\(.)/g, '$1');
};

/**
 * A stack with a minted family added: the stack editor's family chip.
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
 *   The stack with the family first.
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
 * A convenience seed for the faces editor's draft row, never authority
 * (the user corrects it, the server canonicalizes): the usual words
 * (light, regular, semibold, bold, black, ...), a bare hundred (700), a
 * variable-font marker (a 100 to 900 range), and italic or oblique.
 *
 * @param {string} name
 *   The file's name.
 *
 * @return {{weight: string, style: string}}
 *   The guessed face.
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
 * A faces editor draft from an upload's answer.
 *
 * @param {{file: string, name: string, format: ?string, url: string}} upload
 *   The upload route's response.
 * @param {number} key
 *   The draft's stable identity in the editor's list.
 *
 * @return {{key: number, file: string, name: string, format: ?string,
 *   url: string, weight: string, style: string}}
 *   The draft, its face guessed from the file name.
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
 * A faces editor draft from a stored face: the card's "Edit faces"
 * opens on the font's own faces, each a draft carrying its file (the
 * uuid the record names; the stored name is not kept, so the row shows
 * the face's label as its name).
 *
 * @param {object} face
 *   A canonical face {weight, style, source: {file}}.
 * @param {number} key
 *   The draft's stable identity in the editor's list.
 *
 * @return {{key: number, file: string, name: string, format: ?string,
 *   url: ?string, weight: string, style: string}}
 *   The draft.
 */
export const storedFaceDraft = (face, key) => ({
  key,
  file: face?.source?.file ?? '',
  name: faceLabel(face),
  format: null,
  url: null,
  weight: String(face?.weight ?? '400').trim() || '400',
  style: String(face?.style ?? 'normal').trim() || 'normal',
});

/**
 * The op's faces from the editor's drafts: [{weight, style, source: {file}}]
 * (the typed source; a hosted kind is a later key beside `file`).
 *
 * @param {Array<object>} drafts
 *   The editor's face drafts.
 *
 * @return {Array<{weight: string, style: string, source: {file: string}}>}
 *   The op's faces.
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
 * the drafts as typed so the editor can refuse the save before the round
 * trip. Weight keywords fold as the server does.
 *
 * @param {Array<object>} drafts
 *   The editor's face drafts.
 *
 * @return {number}
 *   The index of the first draft repeating an earlier face, -1 for none.
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
 *   The signature.
 */
export const fontsSignature = (materials) =>
  JSON.stringify(
    fontOptions(materials).map((entry) => [
      entry.name,
      entry.family,
      entry.faces,
    ]),
  );

/**
 * A material's spelling, the reference a style is written as, by kind.
 *
 * @param {object} record
 *   The material record (delivery shape).
 *
 * @return {?string}
 *   The spelling, or null for an unknown kind.
 */
export const materialSpelling = (record) => {
  if (record?.kind === 'animation') {
    return animationSpelling(record);
  }
  if (record?.kind === 'font') {
    return fontSpelling(record);
  }
  return styleReference(record);
};

/**
 * A token record's display value, the row's text: the default entry of
 * a condition-keyed value, the string itself otherwise.
 *
 * @param {object} record
 *   The token record.
 *
 * @return {string}
 *   The display value.
 */
export const tokenValueText = (record) => tokenDisplayValue(record?.value);

// --- The sections and their rows. ---

/**
 * Whether a row matches the search needle: its name, its spelling, its
 * value text (a token's display value, a font's family) and its
 * description, case-insensitive.
 *
 * @param {object} row
 *   A section row.
 * @param {string} needle
 *   The search text, trimmed and lowercased.
 *
 * @return {boolean}
 *   Whether the row matches.
 */
const matches = (row, needle) =>
  needle === '' ||
  [
    row.name,
    row.spelling ?? '',
    row.value ?? '',
    row.family ?? '',
    row.description,
  ]
    .join('\n')
    .toLowerCase()
    .includes(needle);

/**
 * One token's row.
 *
 * @param {object} token
 *   A token option (materials.js tokenOptions).
 *
 * @return {object}
 *   The row.
 */
const tokenRow = (token) => ({
  kind: 'token',
  reference: token.record.id,
  name: token.name,
  spelling: token.ref,
  scale: token.scale,
  shape: token.shape,
  value: token.value,
  valueDark: token.valueDark !== token.value ? token.valueDark : null,
  paint: tokenPaintsFace(token.shape),
  description:
    typeof token.record.description === 'string'
      ? token.record.description
      : '',
  offered: token.record.offered !== false,
  shipped: isShipped(token.record),
  record: token.record,
});

/**
 * One animation's row: the canonical offsets in order are its anatomy.
 *
 * @param {object} animation
 *   An animation option (materials.js animationOptions).
 *
 * @return {object}
 *   The row.
 */
const animationRow = (animation) => ({
  kind: 'animation',
  reference: animation.record.id,
  name: animation.name,
  spelling: animation.ref,
  offsets: Object.keys(canonicalizeSteps(animation.steps)),
  description: animation.description,
  offered: animation.record.offered !== false,
  shipped: isShipped(animation.record),
  record: animation.record,
});

/**
 * One font's row: the face labels in stored order are its anatomy; the
 * row's name renders in the family (the spelling).
 *
 * @param {object} font
 *   A font option (materials.js fontOptions).
 *
 * @return {object}
 *   The row.
 */
const fontRow = (font) => ({
  kind: 'font',
  reference: font.record.id,
  name: font.name,
  spelling: font.spelling,
  family: font.family,
  faces: (Array.isArray(font.faces) ? font.faces : []).map(faceLabel),
  description: font.description,
  offered: font.record.offered !== false,
  shipped: isShipped(font.record),
  record: font.record,
});

/**
 * The Skin tab's sections: the tokens per scale, then the animations,
 * then the fonts, under one search.
 *
 * Roster order throughout (declared scale order, then authoring order =
 * display order). A section lists only while it has a row to list (a
 * scale no token is minted on, a kind the skin carries none of, or a
 * needle that matches nothing there), so the tab is what the skin holds
 * and nothing else. Every row carries its record (the card's subject: the
 * gestures, the live preview and the update op all take the record) and
 * the facts a row shows without opening: the name, the spelling, the
 * kind's value summary (a token's display value and its scheme half, an
 * animation's offsets, a font's face labels), whether a face chip is
 * honest, and the description and offered flag the card edits. No origin
 * fact renders on a row (the card says who shipped it); `shipped` rides
 * along for the card's lock.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {{search?: string}} filters
 *   The tab's filters: the search needle.
 *
 * @return {Array<{id: string, kind: string, label: string, scale: ?string,
 *   shape: ?string, rows: Array<object>}>}
 *   The sections that have a row to list, in roster order.
 */
export const skinSections = (guide, materials, { search = '' } = {}) => {
  const needle = search.trim().toLowerCase();
  const sections = [];
  const scales = new Map();
  tokenOptions(guide, materials).forEach((token) => {
    if (!scales.has(token.scale)) {
      scales.set(token.scale, {
        id: `scale:${token.scale}`,
        kind: 'token',
        label: token.scaleLabel,
        scale: token.scale,
        shape: token.shape,
        rows: [],
      });
    }
    const row = tokenRow(token);
    if (matches(row, needle)) {
      scales.get(token.scale).rows.push(row);
    }
  });
  sections.push(...scales.values());
  sections.push({
    id: 'animations',
    kind: 'animation',
    label: null,
    scale: null,
    shape: null,
    rows: animationOptions(materials)
      .map(animationRow)
      .filter((row) => matches(row, needle)),
  });
  sections.push({
    id: 'fonts',
    kind: 'font',
    label: null,
    scale: null,
    shape: null,
    rows: fontOptions(materials)
      .map(fontRow)
      .filter((row) => matches(row, needle)),
  });
  return sections.filter((section) => section.rows.length > 0);
};
