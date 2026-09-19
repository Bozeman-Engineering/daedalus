/**
 * The color widgets' gradient half: stop derivation and the multi-property
 * state reads that make the background and text_color ControlWidgets
 * gradient-capable. Pure and preact-free on purpose, the widget-values.js
 * posture: the honesty rules live here as node-testable logic
 * (tests/js/widget-gradients.test.mjs); the components render these states
 * and add no mapping of their own.
 *
 * Two-property honesty (the stroke-model strokeState precedent): a background is
 * one idea spelled across background-color and background-image, and text
 * gradients are one idea spelled across three properties (the recipe:
 * background-image gradient + background-clip: text + color: transparent,
 * all standard spellings). A stop lights only for the exact state its pick
 * writes; a compound the row cannot represent (a color AND an image, a
 * partial recipe, a media or layered or foreign value) is EXPLICITLY
 * custom, never "nothing set" and never silently normalized.
 *
 * The gradient chip is part of the widget anatomy, so a single gradient
 * layer the chip can own (a literal gradient function, or one var() naming
 * a gradient-kind swatch) is a REPRESENTED state even when no stop claims
 * it: `gradient` carries it and `custom` stays false. Occupancy by
 * anything else on background-image is the cross-widget collision the
 * custom pill reports honestly.
 *
 * The basic-mode media pick's seed model lives here too (MEDIA_COMPANIONS
 * + mediaCompanions): the pure half of the Background widget's media
 * gesture, beside the media stop it lands as.
 */

import { gradientFallback, normalizeValue } from './widget-values.js';
import { entryAtPose } from './condition-cascade.js';
import { mediaTokenUuid } from './media-token.js';
import { isGradientLayer, splitLayers } from './gradient-model.js';
import { resolvedConstraintsFor, propertyGranted } from './style-guide.js';
import { gradientSwatchOf, inlineLiteral } from './materials.js';

/**
 * The single gradient a committed background-image value holds, or null.
 *
 * Non-null exactly when the value is ONE layer the gradient chip can own:
 * a gradient function (any type, repeating included), or one var() naming
 * a gradient-kind swatch the materials roster carries. Layered values,
 * media tokens, url()s, color-kind or unknown var() references all answer
 * null; they are background-image occupancy the color widgets cannot
 * represent.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster.
 * @param {*} value
 *   The committed background-image value.
 *
 * @return {?string}
 *   The trimmed single-layer value, or null.
 */
export const singleGradient = (guide, materials, value) => {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const trimmed = value.trim();
  if (gradientSwatchOf(guide, materials, trimmed) !== null) {
    return trimmed;
  }
  const layers = splitLayers(trimmed);
  return layers !== null && layers.length === 1 && isGradientLayer(layers[0])
    ? layers[0]
    : null;
};

/**
 * One allowed entry as a gradient stop, or null when it is not one.
 *
 * The guide's EXACT spelling is the canonical write (allowed checks are
 * verbatim on both validators). A var() naming a gradient-kind swatch
 * paints from the swatch record's value and wears its label; a literal
 * gradient function paints as itself. Everything else in the list ('none',
 * a url() pattern, a color) is background-image vocabulary but not a
 * gradient stop, so it derives nothing here.
 */
const allowedGradientStop = (guide, materials, raw) => {
  const value = String(raw ?? '').trim();
  const swatch = gradientSwatchOf(guide, materials, value);
  if (swatch !== null) {
    return {
      id: swatch.ref,
      value,
      matches: [normalizeValue(value)],
      label: swatch.label,
      // The display form, straight from the record (a condition-keyed
      // value shows its default entry), the swatchOptions rule; the
      // scheme pair rides beside it for the split face, null when
      // single-valued. Literal gradients below never pair.
      paint: swatch.value,
      paintDark: swatch.valueDark,
    };
  }
  const layers = splitLayers(value);
  if (layers !== null && layers.length === 1 && isGradientLayer(layers[0])) {
    return {
      id: normalizeValue(value),
      value,
      matches: [normalizeValue(value)],
      label: value,
      paint: value,
      paintDark: null,
    };
  }
  return null;
};

/**
 * The gradient side of a color widget's stop vocabulary, constraint-honest.
 *
 * Rendered AFTER the color stops (paint from payload data, never var()
 * resolution), and gated on background-image's OWN constraints, not the
 * color property's: the same closed-space rules stopsFor encodes,
 * because a stop must never commit a value the server rejects.
 * - An `allowed:` list is the complete value space (verbatim): only its
 *   gradient-shaped entries (curated gradient-swatch var() spellings,
 *   literal gradient functions) become stops; swatch references outside
 *   the list never offer.
 * - A `scale:` constraint without an allowed list beside it closes the
 *   property to minted tokens, and no token scale shape maps onto images,
 *   so nothing gradient offers.
 * - Unconstrained rows offer every gradient-kind swatch as its style
 *   spelling, in roster (label) order (gradientFallback, the same tier
 *   an <image> row's value space reads).
 * Equivalent spellings collapse into the first occurrence. No swatches and
 * no curated entries means no gradient stops; committed values still read
 * through the state functions below.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} materials
 *   The materials roster.
 * @param {?{scale: string, allowed: Array<string>}} constraints
 *   background-image's guide constraints
 *   (model.constraints['background-image']), or null when unconstrained.
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, paint: string, paintDark: ?string}>}
 */
export const gradientStops = (guide, materials, constraints) => {
  const allowed = Array.isArray(constraints?.allowed)
    ? constraints.allowed
    : [];
  let stops;
  if (allowed.length) {
    stops = allowed
      .map((raw) => allowedGradientStop(guide, materials, raw))
      .filter((stop) => stop !== null);
  } else if (typeof constraints?.scale === 'string') {
    stops = [];
  } else {
    stops = gradientFallback(guide, materials);
  }
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
 * The committed media token as a synthetic image stop, or null.
 *
 * The basic-mode media posture: a background-image that IS exactly one
 * url('media:…') token (mediaTokenUuid) joins the Background widget's
 * image stop table as the one entry representing itself, so the EXISTING
 * machinery does the rest — backgroundState's stop match lights it, the
 * well wears it as the thumbnail face (wellFace's media kind, off the
 * `media` flag), and the grid's lit-chip toggle is the unpick, which
 * backgroundPick's corners rule resolves to clearing background-image
 * only: the pair contract. Collisions never reach the stop: a color
 * beside the token, a layered value, or the text recipe's occupancy all
 * fall through backgroundState's reads to the honest custom face exactly
 * as before.
 *
 * `paint` is the grid chip's face: the resolved thumbnail as a cover
 * background when the client URL table answers the uuid, the custom
 * face's checker otherwise (a lost file, a satellite before its first
 * broadcast). `thumbnail` carries the bare URL (or null) beside it so
 * the well can tell the two apart and wear the photo glyph honestly.
 *
 * @param {*} value
 *   The (draft-layered) background-image value.
 * @param {?Object<string, string>} urls
 *   The client media uuid -> URL table (source.getMediaUrls()).
 *
 * @return {?object}
 *   A widget-shape stop ({id, value, matches, label, paint, paintDark,
 *   group}, plus {media: true, thumbnail}), or null when the value is
 *   not exactly one media token.
 */
export const mediaImageStop = (value, urls = null) => {
  const uuid = mediaTokenUuid(value);
  if (uuid === null) {
    return null;
  }
  const url = urls?.[uuid];
  const thumbnail = typeof url === 'string' && url !== '' ? url : null;
  return {
    id: `media:${uuid}`,
    value: String(value).trim(),
    matches: [normalizeValue(value)],
    label: 'Media image',
    paint:
      thumbnail !== null
        ? `url("${thumbnail}") center / cover`
        : 'repeating-conic-gradient(rgba(127, 127, 127, 0.35) 0 25%, transparent 0 50%) 0 0 / 8px 8px',
    paintDark: null,
    group: 'Image',
    media: true,
    thumbnail,
  };
};

/**
 * The best-guess companions a basic-mode media pick seeds beside the
 * image token: without them the browser defaults (repeat, auto size)
 * tile the image, so the common case would always need an advanced-mode
 * follow-up. Seeded at pick time in the SAME one-op batch as the image;
 * from that moment they are ordinary map values with no special
 * identity, so advanced edits to them are ordinary edits (the
 * seed-at-pick decision, recipe recognition rejected).
 */
export const MEDIA_COMPANIONS = {
  'background-size': 'cover',
  'background-repeat': 'no-repeat',
  'background-position': 'center',
};

/**
 * Whether a layer already answers a property at the write condition:
 * the layer's delivering block at that pose (condition-cascade.js
 * entryAtPose). The write condition's own block answers directly; a
 * block at 'default' or at a subset compound ('narrow' when writing at
 * 'narrow+dark') answers too, because it cascades under the posed one
 * and a seed written there would bury it. A block at any OTHER
 * condition does not answer here: it neither delivers at the write
 * condition nor is buried by a seed written there.
 */
const answersCompanion = (entry, property, condition, conditions) =>
  normalizeValue(
    entryAtPose(entry, property, condition, conditions)?.value ?? null,
  ) !== null;

/**
 * The guide-sanctioned spelling of a companion value, or null.
 *
 * The seed filters to properties the guide sanctions for the key, never
 * assumes: the bound target's roster must grant the property (the strict
 * validator's STYLE_PROPERTY_NOT_DECLARED rule, propertyGranted), and
 * the value must survive the target's own constraints,
 * because a seed must never commit a value the server rejects. An
 * `allowed:` list is the complete value space: a normalized match adopts
 * the guide's EXACT spelling (allowed checks are verbatim on both
 * validators, the stopsFor rule), no match means no seed. A `scale:`
 * constraint without a match beside it closes the property to minted
 * tokens, and no keyword is one.
 */
const sanctionedCompanion = (guide, declaration, property, value) => {
  if (!propertyGranted(declaration, property)) {
    return null;
  }
  // The resolved read (node block, else the site-wide constraint
  // default): an authored default is law on granted properties, so the
  // seed must survive it exactly like a node's own block.
  const constraints = resolvedConstraintsFor(guide, declaration, property);
  const allowed = Array.isArray(constraints?.allowed)
    ? constraints.allowed
    : [];
  if (allowed.length) {
    const match = allowed.find(
      (raw) => normalizeValue(raw) === normalizeValue(value),
    );
    return match === undefined ? null : String(match).trim();
  }
  if (typeof constraints?.scale === 'string') {
    return null;
  }
  return value;
};

/**
 * The companion map a basic-mode media pick seeds beside the image.
 *
 * Pure seed model: the Background widget computes this at the media chip
 * click and threads it through the `source.pickMedia` payload, so the
 * leader merges it into the ONE update_style commit beside the token
 * ({[property]: token, ...companions}). Each MEDIA_COMPANIONS entry
 * seeds only when the resolved stack does not already answer it:
 * - The effective entry (own entry + worn variants; the widgets'
 *   effectiveEntry read, model.entry) delivers a value at the write
 *   condition through the cascade: seeding would silently bury a
 *   variant's or the element's own styling under an override.
 * - The active skin's defaults (skinDefaultEntry, condition-keyed like
 *   the entry) answer the same way; the skin is the weakest layer, so a
 *   seed over it would override site styling the pick never asked to
 *   change. A satellite reads the base:{tag} layer's tag from the
 *   leader's relayed selection tags; a part binding outside the drilled
 *   part degrades that layer away there (accepted minor over-seed).
 * - The guide sanctions the property for the bound target and the value
 *   survives its constraints (sanctionedCompanion); the companions are
 *   curated in the Background property group so the batch is
 *   strict-legal wherever background is declared, but the seed checks,
 *   never assumes.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?object} declaration
 *   The bound TARGET's effective declaration (the elements entry for a
 *   part binding), the sanction and constraint source.
 * @param {?object} entry
 *   The resolved condition-keyed entry behind the widget's values
 *   (model.entry), or null.
 * @param {?object} skinEntry
 *   The active skin's site-default entry for the key (skinDefaultEntry),
 *   or null.
 * @param {string} [condition]
 *   The editing compound the pick writes at (model.condition).
 *
 * @return {Object<string, string>}
 *   The companion properties to merge into the pick's commit, {} when
 *   the stack already answers everything (or sanctions nothing).
 */
export const mediaCompanions = (
  guide,
  declaration,
  entry,
  skinEntry = null,
  condition = 'default',
) => {
  const companions = {};
  const conditions = guide?.conditions ?? {};
  Object.entries(MEDIA_COMPANIONS).forEach(([property, value]) => {
    if (
      answersCompanion(entry, property, condition, conditions) ||
      answersCompanion(skinEntry, property, condition, conditions)
    ) {
      return;
    }
    const spelling = sanctionedCompanion(guide, declaration, property, value);
    if (spelling !== null) {
      companions[property] = spelling;
    }
  });
  return companions;
};

/**
 * The explicit-replacement nulls a basic-mode media pick carries.
 *
 * backgroundPick's replacement rule at the media gesture: a media pick's
 * represented state is the image alone, so a set background-color (and,
 * under the text recipe's occupancy, the recipe's set members) clears in
 * the SAME one-op batch as the token, never left as a silently buried
 * both-set compound the well would read custom. The widget merges this
 * under the seed into the `pickMedia` companions payload
 * ({...replacements, ...seed}); the maps never overlap, and the leader
 * already treats every companion value as a regular staged-and-
 * committed property (null = unset), so the nulls ride the existing
 * relay untouched. The seeded companions themselves are NOT cleared
 * here: replacing image A with image B keeps size/repeat/position
 * standing (a photo swap honors the art direction; the fresh seed skips
 * whatever is already answered).
 *
 * @param {Object<string, string>} values
 *   The widget's (draft-layered) values.
 * @param {?Object<string, string>} siblings
 *   The posed entry's properties (textRecipeOccupies), or null.
 *
 * @return {Object<string, ?string>}
 *   The replacement nulls to merge into the pick's commit, {} when
 *   nothing needs clearing.
 */
export const mediaPickReplacements = (values, siblings = null) => ({
  ...(normalizeValue(values['background-color']) !== null
    ? { 'background-color': null }
    : {}),
  ...(textRecipeOccupies(siblings)
    ? Object.fromEntries(
        ['color', 'background-clip']
          .filter((property) => normalizeValue(siblings?.[property]) !== null)
          .map((property) => [property, null]),
      )
    : {}),
});

/**
 * The raw committed compound for the custom pill (the strokeState shape):
 * every set part in the widget's owned-property order.
 */
const rawValue = (values, properties) =>
  properties
    .map((property) => values[property])
    .filter((part) => normalizeValue(part) !== null)
    .join(' ');

/**
 * Whether the text gradient recipe occupies the entry the background
 * widget reads its pair from.
 *
 * The recipe's marker is `background-clip: text`: with it in force EVERY
 * background (image and color alike) clips to the glyphs, so nothing the
 * background widget writes paints the element's surface. The widget does
 * not own background-clip; it reads the marker from the posed entry
 * (model.entry[model.condition], the resolver's merge) so the
 * cross-widget collision is visible to the pair-only state and pick.
 * Keyword case-insensitive, like the recipe reads (normalizeValue).
 *
 * @param {?Object<string, string>} siblings
 *   The posed entry's properties (the widget's owned pair included), or
 *   null when the entry has none.
 *
 * @return {boolean}
 */
export const textRecipeOccupies = (siblings) =>
  normalizeValue(siblings?.['background-clip']) === 'text';

/**
 * Maps the committed background-color + background-image pair onto the
 * background widget's stops.
 *
 * A two-property strokeState: color stops match background-color, gradient
 * stops match background-image, and a stop lights only when its property
 * is the ONLY one set, because a color behind an image is a compound no
 * single stop writes. A lone single-gradient image no stop claims (a
 * chip-built literal, a reference outside a closed vocabulary) is still a
 * represented state: `gradient` carries it, custom stays false. A lone
 * media, layered, or foreign image, an off-palette color, and every
 * both-set pair read as the explicit custom pill (a LONE off-palette
 * color that is an inline literal also rides as `literal`, so the well
 * paints it with the custom marker instead of the "?" face and its
 * color lane edits it in place); `gradient` reports the
 * background-image fact regardless, so the gradient lane always knows
 * what it holds. When the text recipe occupies the entry (siblings carry
 * background-clip: text) NOTHING here paints the element's surface, so
 * any set pair member reads custom too: the collision honesty the
 * decision demands, and no stop lights whose unpick would strand a
 * partial recipe. The media seed's companions (MEDIA_COMPANIONS:
 * size/repeat/position, riding in values since the widget owns them)
 * are deliberately NOT read: seeded or advanced-edited, they never
 * flip the well custom and never join the custom pill's raw compound —
 * the well faces on the pair alone, so the image token stays lit.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {Object<string, string>} values
 *   The widget's (draft-layered) values.
 * @param {Array<{id: string, value: string, matches: string[]}>} colorStops
 *   The color stop row (stopsFor over background-color).
 * @param {Array<{id: string, value: string, matches: string[]}>} imageStops
 *   The gradient stop row (gradientStops over background-image).
 * @param {?Object<string, string>} siblings
 *   The posed entry's properties (textRecipeOccupies), or null.
 *
 * @return {{selected: ?string, custom: boolean, value: ?string,
 *   gradient: ?string, literal: ?string}}
 *   value carries the raw committed compound for the custom pill;
 *   gradient the single gradient background-image holds (singleGradient);
 *   literal the lone inline color background-color holds when that is
 *   the whole custom state (the well's painted literal face and the
 *   color lane's edit target), null for every compound and for a
 *   reference the roster lacks (those keep the honest "?" face).
 */
export const backgroundState = (
  guide,
  materials,
  values,
  colorStops,
  imageStops,
  siblings = null,
) => {
  const color = normalizeValue(values['background-color']);
  const image = normalizeValue(values['background-image']);
  const gradient = singleGradient(guide, materials, values['background-image']);
  if (color === null && image === null) {
    return {
      selected: null,
      custom: false,
      value: null,
      gradient: null,
      literal: null,
    };
  }
  if (textRecipeOccupies(siblings)) {
    return {
      selected: null,
      custom: true,
      value: rawValue(values, ['background-color', 'background-image']),
      gradient,
      literal: null,
    };
  }
  if (image === null) {
    const stop = colorStops.find((candidate) =>
      candidate.matches.includes(color),
    );
    return stop
      ? {
          selected: stop.id,
          custom: false,
          value: null,
          gradient: null,
          literal: null,
        }
      : {
          selected: null,
          custom: true,
          value: rawValue(values, ['background-color']),
          gradient: null,
          literal: inlineLiteral(materials, values['background-color']),
        };
  }
  if (color === null) {
    const stop = imageStops.find((candidate) =>
      candidate.matches.includes(image),
    );
    if (stop || gradient !== null) {
      return {
        selected: stop?.id ?? null,
        custom: false,
        value: null,
        gradient,
        literal: null,
      };
    }
  }
  return {
    selected: null,
    custom: true,
    value: rawValue(values, ['background-color', 'background-image']),
    gradient,
    literal: null,
  };
};

/**
 * Maps the committed color + background-image + background-clip compound
 * onto the text_color widget's stops.
 *
 * With neither recipe property set this is the plain color row: color
 * stops match `color`, off-palette values read custom. With the EXACT
 * recipe committed (a single gradient on background-image,
 * background-clip: text, color: transparent, keyword case-insensitive)
 * the state is gradient text: a matching gradient stop lights, and a
 * gradient no stop claims stays represented through `gradient` (the chip),
 * custom false. ANY other occupancy of background-image or background-clip
 * is custom, a partial recipe included, and so is the background widget's
 * own gradient sitting under an unrelated text color: the cross-widget
 * collision read the decision demands.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {Object<string, string>} values
 *   The widget's (draft-layered) values.
 * @param {Array<{id: string, value: string, matches: string[]}>} colorStops
 *   The color stop row (stopsFor over color).
 * @param {Array<{id: string, value: string, matches: string[]}>} imageStops
 *   The gradient stop row (gradientStops over background-image).
 *
 * @return {{selected: ?string, custom: boolean, value: ?string,
 *   gradient: ?string, literal: ?string}}
 *   literal is the lone inline color `color` holds when that is the
 *   whole custom state (backgroundState's rule), null otherwise.
 */
export const textGradientState = (
  guide,
  materials,
  values,
  colorStops,
  imageStops,
) => {
  const color = normalizeValue(values.color);
  const image = normalizeValue(values['background-image']);
  const clip = normalizeValue(values['background-clip']);
  const gradient = singleGradient(guide, materials, values['background-image']);
  if (image === null && clip === null) {
    if (color === null) {
      return {
        selected: null,
        custom: false,
        value: null,
        gradient: null,
        literal: null,
      };
    }
    const stop = colorStops.find((candidate) =>
      candidate.matches.includes(color),
    );
    return stop
      ? {
          selected: stop.id,
          custom: false,
          value: null,
          gradient: null,
          literal: null,
        }
      : {
          selected: null,
          custom: true,
          value: rawValue(values, ['color']),
          gradient: null,
          literal: inlineLiteral(materials, values.color),
        };
  }
  if (gradient !== null && clip === 'text' && color === 'transparent') {
    const stop = imageStops.find((candidate) =>
      candidate.matches.includes(image),
    );
    return {
      selected: stop?.id ?? null,
      custom: false,
      value: null,
      gradient,
      literal: null,
    };
  }
  return {
    selected: null,
    custom: true,
    value: rawValue(values, ['color', 'background-image', 'background-clip']),
    gradient,
    literal: null,
  };
};

/**
 * The text gradient recipe as one atomic pick batch.
 *
 * The direct widget batch the decision chose over a shipped variant: a
 * gradient pick (or builder commit) writes all three properties in ONE
 * update_style op, and unpick clears every property the pick wrote, so
 * the compound never half-exists in the map. The value is whatever the
 * gesture carries: a gradient stop's var() reference or the builder's
 * serialized literal.
 *
 * @param {?string} value
 *   The background-image value to wear, or null to unpick.
 *
 * @return {Object<string, ?string>}
 *   The properties map for pick()/onCommit.
 */
export const textGradientBatch = (value) =>
  value === null
    ? { color: null, 'background-image': null, 'background-clip': null }
    : {
        color: 'transparent',
        'background-image': value,
        'background-clip': 'text',
      };

/**
 * The background widget's pick batch over the two-property pair.
 *
 * A pick writes the represented state its stop lights for, in ONE op: its
 * own property, plus a null on the other half when that half is set;
 * picking a stop over an occupied pair (a color over a gradient, a
 * gradient over a color, either over the custom pill) is the explicit
 * replacement Segments promises, and leaving the other half behind would
 * commit a both-set compound no stop represents. When the text recipe
 * occupies the entry (siblings carry background-clip: text) a pick also
 * nulls the recipe's set members color and background-clip: with the
 * marker in force nothing the pair holds paints the surface, so leaving
 * the recipe behind would commit an invisible half-compound (the clipped
 * background) no stop represents — the same explicit-replacement rule
 * textPick applies over the trio, per the collision-honesty decision.
 * Both gestures run the corners rule over the FAMILY — the pair plus
 * the media seed's companions (MEDIA_COMPANIONS): clear every set
 * member, never reset what a click didn't say (in the recipe state no
 * stop is lit, so unpick cannot fire there). A pick clears set
 * companions for the same reason it nulls the other pair half: a
 * stop's represented state carries no size/repeat/position, so leaving
 * them behind would commit a compound no stop represents — after image
 * -> color they sit inert in the map, after image -> gradient they
 * silently reshape it. Companions clear even when advanced-mode edited
 * since the seed (the unpick-clears-family decision, grown to picks by
 * the pick-clears-family decision): the family rides the background
 * the gesture just replaced.
 *
 * @param {Object<string, string>} values
 *   The widget's (draft-layered) values.
 * @param {?{value: string}} stop
 *   The picked stop, or null to unpick.
 * @param {boolean} gradient
 *   Whether the picked stop is a gradient stop (background-image); false
 *   means a color stop (background-color).
 * @param {?Object<string, string>} siblings
 *   The posed entry's properties (textRecipeOccupies), or null.
 *
 * @return {Object<string, ?string>}
 *   The properties map for pick()/onCommit.
 */
export const backgroundPick = (
  values,
  stop,
  gradient = false,
  siblings = null,
) => {
  const pair = ['background-color', 'background-image'];
  if (stop === null) {
    return Object.fromEntries(
      [...pair, ...Object.keys(MEDIA_COMPANIONS)]
        .filter((property) => normalizeValue(values[property]) !== null)
        .map((property) => [property, null]),
    );
  }
  const own = gradient ? 'background-image' : 'background-color';
  const other = gradient ? 'background-color' : 'background-image';
  return {
    [own]: stop.value,
    ...(normalizeValue(values[other]) !== null ? { [other]: null } : {}),
    ...Object.fromEntries(
      Object.keys(MEDIA_COMPANIONS)
        .filter((property) => normalizeValue(values[property]) !== null)
        .map((property) => [property, null]),
    ),
    ...(textRecipeOccupies(siblings)
      ? Object.fromEntries(
          ['color', 'background-clip']
            .filter((property) => normalizeValue(siblings?.[property]) !== null)
            .map((property) => [property, null]),
        )
      : {}),
  };
};

/**
 * The text_color widget's pick batch over the three-property trio.
 *
 * backgroundPick's semantics grown to the recipe: a pick writes the
 * represented state its stop lights for, in ONE op. A gradient stop's
 * pick IS the recipe batch (textGradientBatch: three positive writes, so
 * no phantom unsets arise). A color stop's pick writes color plus a null
 * on each OTHER trio property that is set: picking over the recipe, a
 * partial recipe, or the background widget's collision occupancy is the
 * explicit replacement Segments promises, because leaving
 * background-image or background-clip behind would commit a compound no
 * color stop represents. Unpick is the corners rule over all three:
 * clear every set property the widget owns, never reset what a click
 * didn't say.
 *
 * @param {Object<string, string>} values
 *   The widget's (draft-layered) values.
 * @param {?{value: string}} stop
 *   The picked stop, or null to unpick.
 * @param {boolean} gradient
 *   Whether the picked stop is a gradient stop (the recipe); false means
 *   a color stop (color).
 *
 * @return {Object<string, ?string>}
 *   The properties map for pick()/onCommit.
 */
export const textPick = (values, stop, gradient = false) => {
  const trio = ['color', 'background-image', 'background-clip'];
  if (stop === null) {
    return Object.fromEntries(
      trio
        .filter((property) => normalizeValue(values[property]) !== null)
        .map((property) => [property, null]),
    );
  }
  if (gradient) {
    return textGradientBatch(stop.value);
  }
  return {
    color: stop.value,
    ...(normalizeValue(values['background-image']) !== null
      ? { 'background-image': null }
      : {}),
    ...(normalizeValue(values['background-clip']) !== null
      ? { 'background-clip': null }
      : {}),
  };
};
