import { compoundLabel, entryAtPose } from './condition-cascade.js';
import {
  ELEMENT_LAYER,
  SKIN_COLOR,
  layerColor,
  variantLayerId,
} from './code/layers.js';
import {
  displayAt,
  magneticCapture,
  valuesEqual,
  wearing,
} from './value-space.js';
import {
  captureScope,
  declaredDefaultEntry,
  findVariant,
  propertySuppliers,
  shadowedProperties,
  skinDefaultEntry,
  suppliedValue,
} from './variant-scope.js';

/**
 * @file
 * The property row's provenance model (pure; the rows render it).
 *
 * One origin per row: the value a row shows has exactly one provenance,
 * a (layer, condition) pair: the condition that delivers the value at
 * the pose (the cascade read, condition-cascade.js valueAtPose) and the
 * layer that sets it AT THAT DELIVERING CONDITION (the own entry, a worn
 * variant, the skin, or the element's declaration). Reading the layer
 * tables at the delivering condition, never the posed one, is what keeps
 * the WHO when a value crosses a condition (a variant setting padding at
 * Base, viewed at Medium, is still the variant's).
 *
 * The defaults tier has two suppliers and one posture: the active skin's
 * defaults (data, the skin form is their doorway) over the declaration's
 * own defaults (code: a block type, block plugin, or layout shipping the
 * box facts its markup needs; one inert doorway). Both render as the defaults
 * ghost and the skin hue; the origin sentence names which one supplies,
 * and the delivering condition when it is not the pose. The tier is
 * read through the same cascade as every other layer (defaultsAtPose,
 * condition-cascade.js entryAtPose): the printer emits both tiers by
 * condition order, so a skin or declared `default` block delivers at
 * every pose and the lens must say so at every pose.
 *
 * The origin renders as ONE mark in the row's leading slot (a scrub
 * row's grip, any other row's ring), quiet when the value is the
 * element's own at this pose, hued otherwise, and as ONE right-click
 * menu on the row: the origin in words as the header, the doorways
 * (drive the canvas, open the variant, unset, edit the skin, go to the
 * winner), the other conditions carrying a value, then the token items
 * the dimension field used to open on its own. Everything here is
 * data-in/data-out for node-test backpressure; the row binds handlers.
 */

/**
 * A condition key as display vocabulary: 'default' is Base, singles wear
 * their site label, compound parts join with ' + ' ("Narrow + Dark").
 */
export const conditionText = (name, conditions) =>
  name === 'default' ? Drupal.t('Base') : compoundLabel(name, conditions);

/**
 * A declaring scope as display vocabulary: the captured scope in words
 * ("image block type", "system_menu_block block plugin", "layout_onecol
 * layout", "container kind"), the name the origin sentences give a
 * declared default's supplier. Declarations carry no human label (the
 * guide serves them by machine name, as the validator and the AI
 * explain name them), so the scope itself is the honest name. A kind
 * names itself for the elements whose scope IS the kind (the entity, an
 * unrefined section, an unresolved bundle): under the ownership law a
 * kind declares defaults for the markup it renders (a composed kind's
 * root, its declared parts). A refined element names its refinement,
 * whose effective declaration carries the kind's defaults merged
 * beneath its own (the read in variant-scope.js declaredDefaultEntry
 * layers them the same way). Null for a base tag or an unparsed scope.
 */
export const declarationLabel = (scope) => {
  const pos = typeof scope === 'string' ? scope.indexOf(':') : -1;
  if (pos === -1) {
    return null;
  }
  const id = scope.slice(pos + 1);
  switch (scope.slice(0, pos)) {
    case 'kind':
      return Drupal.t('@id kind', { '@id': id });
    case 'block_type':
      return Drupal.t('@id block type', { '@id': id });
    case 'block_plugin':
      return Drupal.t('@id block plugin', { '@id': id });
    case 'layout':
      return Drupal.t('@id layout', { '@id': id });
    default:
      return null;
  }
};

/**
 * A key's provenance inputs, assembled once per key for every row over
 * it: the layer tables rowOrigin() reads whole, plus the two reference
 * resolvers the sentences call on demand.
 *
 * The one assembly behind every basic-mode row's origin (controls.js
 * models carry it as `provenance`; the Layout section's gap row builds
 * its own over the container root). The tables are the variant-scope
 * reads the Inspector's advanced rows consume: the element's OWN entry
 * (a part binding's own part entry), the delivery-winning supplier per
 * (condition, property), the own tuples shadowing a worn variant, the
 * active skin's defaults for the key (a part key resolves its `::.part`
 * relative in the skin defaults, so a part binding reads the skin's
 * part layer), and beneath them the element's declaration's own
 * defaults for the key with the captured scope that names it. Pure
 * data (no display vocabulary is built here): rowOrigin() speaks the
 * scope through declarationLabel(). References stay
 * references; `labelOf` and `valueOf` resolve them where a row names
 * one.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} styleMap
 *   The root entity's style map.
 * @param {string} key
 *   The bound target's full style-map key (root or `::.part` form).
 * @param {Array<object>} variants
 *   The variant records (materials.ofKind('variant')).
 * @param {?Array} axes
 *   The scope's axis vocabulary (variant-scope.js axisVocabulary).
 * @param {object} [blockTypes]
 *   Component uuid → block type, for the skin defaults' scope capture.
 * @param {object} [sectionLayouts]
 *   Section uuid → layout id, likewise.
 * @param {?string} [tag]
 *   The target element's rendered tag (the skin's base:{tag} layer), or
 *   null when no canvas read answers it.
 *
 * @return {{own: ?object, suppliers: object, shadowed: object, skinEntry: ?object, declaredEntry: ?object, declaredScope: ?string, labelOf: Function, valueOf: Function}}
 */
export const provenanceFor = (
  guide,
  styleMap,
  key,
  variants = [],
  axes = null,
  blockTypes = {},
  sectionLayouts = {},
  tag = null,
) => ({
  own: styleMap?.[key] ?? null,
  suppliers: propertySuppliers(styleMap, key, variants, axes),
  shadowed: shadowedProperties(styleMap, key, variants, axes),
  skinEntry: skinDefaultEntry(guide, key, blockTypes, sectionLayouts, tag),
  declaredEntry: declaredDefaultEntry(guide, key, blockTypes, sectionLayouts),
  declaredScope: captureScope(key, blockTypes, sectionLayouts),
  labelOf: (reference) => findVariant(variants, reference)?.label ?? reference,
  valueOf: (reference, condition, property) =>
    suppliedValue(
      styleMap,
      key,
      variants,
      reference,
      condition,
      property,
      axes,
    ),
});

/**
 * The defaults tier's origin for a property at a pose: the skin's
 * delivering block, else the declaration's, each read through the
 * cascade (condition-cascade.js entryAtPose). The delivery order stated
 * once: the declared tier prints entirely BEFORE the skin's tiers at
 * equal specificity, so any skin block reaching the pose beats any
 * declared block reaching it, whatever their conditions. The one read
 * behind the defaults ghost beneath an unset row, the section
 * auto-open signal, and rowOrigin()'s `defaults` input.
 *
 * @param {?object} skinEntry
 *   The active skin's condition-keyed entry for the key (variant-scope.js
 *   skinDefaultEntry), or null.
 * @param {?object} declaredEntry
 *   The element's declaration's own entry (declaredDefaultEntry), or
 *   null.
 * @param {string} property
 * @param {string} condition
 *   The editing compound (the pose).
 * @param {object} [conditions]
 *   The condition table (guide.conditions).
 *
 * @return {?{layer: string, condition: string, value: string}}
 *   layer 'skin' or 'declared', the delivering condition and its value;
 *   null when neither tier reaches the pose.
 */
export const defaultsAtPose = (
  skinEntry,
  declaredEntry,
  property,
  condition,
  conditions = {},
) => {
  const skin = entryAtPose(skinEntry, property, condition, conditions);
  if (skin) {
    return { layer: 'skin', ...skin };
  }
  const declared = entryAtPose(declaredEntry, property, condition, conditions);
  return declared ? { layer: 'declared', ...declared } : null;
};

/**
 * Whether a condition-keyed entry sets a property at a condition (a
 * null value is an unset, not a value).
 */
export const setsAt = (entry, condition, property) => {
  const block = entry?.[condition];
  return (
    block !== null &&
    block !== undefined &&
    typeof block === 'object' &&
    !Array.isArray(block) &&
    block[property] !== undefined &&
    block[property] !== null
  );
};

const quiet = (layer) => ({
  posture: 'own',
  layer,
  reference: null,
  color: null,
  title: undefined,
  header: null,
  doorways: [],
});

const driveDoorway = (condition, conditions) => ({
  id: 'drive',
  label: Drupal.t('Go to @condition', {
    '@condition': conditionText(condition, conditions),
  }),
  hint: Drupal.t('drives the canvas'),
  condition,
});

const documentDoorway = (reference, label, condition, conditions) => ({
  id: 'document',
  label: Drupal.t('Edit @label at @condition…', {
    '@label': label,
    '@condition': conditionText(condition, conditions),
  }),
  hint: Drupal.t('opens the variant'),
  reference,
  condition,
});

const skinDoorway = (url) =>
  url
    ? [
        {
          id: 'skin',
          label: Drupal.t('Edit skin…'),
          hint: Drupal.t('opens the skin form'),
          url,
        },
      ]
    : [];

// The declared tier's one doorway is inert: a declaration is code beside
// its templates, nothing on the site edits it. It exists so the menu
// still opens on a declared value (the menu primitive refuses an empty
// list) and says why there is nothing to do.
const declaredDoorway = () => ({
  id: 'declared',
  label: Drupal.t('Declared in code'),
  hint: Drupal.t('edited beside the templates'),
  disabled: true,
  reason: Drupal.t('A declaration ships with its module; the site has no form for it.'),
});

/**
 * A property row's origin: who supplies the shown value, and where.
 *
 * @param {object} input
 * @param {string} input.property
 *   The row's property.
 * @param {Array<{condition: string, value: string}>} input.values
 *   The row's set values (buildSections shape, the RESOLVED entry on the
 *   element surface, the document projection in a session).
 * @param {?{condition: string, value: string}} input.atPose
 *   valueAtPose(values, condition, conditions): the delivering block.
 * @param {string} input.condition
 *   The editing compound (the pose).
 * @param {object} input.conditions
 *   The condition table (guide.conditions), for display vocabulary.
 * @param {?object} input.own
 *   The own layer's condition-keyed entry: the element's map entry on
 *   the element surface, the document's slot projection in a session.
 * @param {string} input.ownLayer
 *   The own layer's id (layers.js): ELEMENT_LAYER, or the edited
 *   variant's layer in a session.
 * @param {?object} input.suppliers
 *   propertySuppliers(): condition → property → reference, whole.
 * @param {?object} input.shadowed
 *   shadowedProperties(): condition → property → reference, whole. The
 *   read is grain-aware (an own `padding` beats a worn `padding-inline`),
 *   so the overriding badge can name a variant that spells the beaten
 *   sides on another grain; valueOf then has no same-named value to
 *   quote and the sentence drops its "beneath" clause.
 * @param {?{layer: string, condition: string, value: string}} input.defaults
 *   defaultsAtPose(): the defaults tier's delivering block at the pose
 *   (layer 'skin' or 'declared', the skin-over-declared arbitration
 *   already made), or null.
 * @param {?string} input.skinEditUrl
 *   guide.skinEditUrl: the active skin's edit form, null when none.
 * @param {?string} input.declaredScope
 *   The element's captured scope (variant-scope.js captureScope), the
 *   declaring scope a declared value's sentences name through
 *   declarationLabel(); null when unknown.
 * @param {?{variant: ?string}} input.winner
 *   In a session, sheet.winner(condition, property) for a shown value:
 *   the higher layer beating the document here; null otherwise.
 * @param {Function} input.labelOf
 *   reference → the variant's label.
 * @param {Function} input.valueOf
 *   (reference, condition, property) → the value that variant supplies
 *   there (variant-scope.js suppliedValue), undefined when none.
 *
 * @return {{posture: string, layer: ?string, reference: ?string, color: ?string, title: ?string, header: ?string, doorways: Array<object>, alsoAt: Array<object>}}
 *   posture: 'own' (set here at this pose, or nothing anywhere: no
 *   chrome), 'inherited' (another condition's or another layer's value
 *   shows), 'overriding' (own at pose beats a variant or the defaults
 *   tier), 'overridden' (a session value a higher layer beats). layer
 *   names the origin's layer: the own layer, a variant's, 'skin', or
 *   'declared' (the element's declaration's own defaults, presented in
 *   the skin's posture and hue behind one inert doorway: it is code). A defaults
 *   sentence names the delivering condition when it is not the pose
 *   ("block from the image block type declaration at Base"), the own
 *   layer's "!value from @condition" idiom. color is the
 *   origin's hue (the beaten layer's when overriding, the winner's when
 *   overridden), null when quiet. title is the mark's hover sentence,
 *   header the menu's (the same). doorways carry {id, label, hint} plus
 *   the datum the row's handler needs (condition, reference, url,
 *   winner); an inert one carries {disabled: true, reason} and no datum,
 *   so the menu opens on the sentence alone. alsoAt lists every OTHER
 *   condition carrying a value
 *   ({condition, label, value}), the delivering one excluded.
 */
export const rowOrigin = ({
  property,
  values = [],
  atPose = null,
  condition,
  conditions = {},
  own = null,
  ownLayer = ELEMENT_LAYER,
  suppliers = null,
  shadowed = null,
  defaults = null,
  skinEditUrl = null,
  declaredScope = null,
  winner = null,
  labelOf = (reference) => reference,
  valueOf = () => undefined,
}) => {
  const skinValue = defaults?.layer === 'skin' ? defaults.value : null;
  const declared = defaults?.layer === 'declared' ? defaults.value : null;
  const declaration = declarationLabel(declaredScope) ?? Drupal.t('element');
  // The defaults sentences' "at @condition" tail: the delivering
  // condition when it differs from the pose, nothing when it is the pose.
  const from =
    defaults && defaults.condition !== condition
      ? { '@condition': conditionText(defaults.condition, conditions) }
      : null;
  const alsoAt = values
    .filter((entry) => entry.condition !== atPose?.condition)
    .map((entry) => ({
      condition: entry.condition,
      label: conditionText(entry.condition, conditions),
      value: entry.value,
    }));
  const origin = (() => {
    if (winner) {
      const label = winner.variant == null ? null : labelOf(winner.variant);
      const sentence = label
        ? Drupal.t('Overridden by @label on this page', { '@label': label })
        : Drupal.t('Overridden by this element on this page');
      return {
        posture: 'overridden',
        layer:
          winner.variant == null
            ? ELEMENT_LAYER
            : variantLayerId(winner.variant),
        reference: winner.variant ?? null,
        color: layerColor(
          winner.variant == null
            ? ELEMENT_LAYER
            : variantLayerId(winner.variant),
        ),
        title: sentence,
        header: sentence,
        doorways: [
          {
            id: 'winner',
            label: label
              ? Drupal.t('Edit @label…', { '@label': label })
              : Drupal.t('Go to element'),
            hint: label
              ? Drupal.t('opens the variant')
              : Drupal.t('where the winning value lives'),
            winner,
          },
        ],
      };
    }
    if (!atPose) {
      if (skinValue != null) {
        const sentence = from
          ? Drupal.t('!value from the active skin at @condition', {
              '!value': skinValue,
              ...from,
            })
          : Drupal.t('!value from the active skin', { '!value': skinValue });
        return {
          posture: 'inherited',
          layer: 'skin',
          reference: null,
          color: SKIN_COLOR,
          title: sentence,
          header: sentence,
          doorways: skinDoorway(skinEditUrl),
        };
      }
      if (declared != null) {
        // The declaration's own default: the defaults posture and hue,
        // the supplier named, the inert doorway (a declaration is code).
        const sentence = from
          ? Drupal.t('!value from the @declaration declaration at @condition', {
              '!value': declared,
              '@declaration': declaration,
              ...from,
            })
          : Drupal.t('!value from the @declaration declaration', {
              '!value': declared,
              '@declaration': declaration,
            });
        return {
          posture: 'inherited',
          layer: 'declared',
          reference: null,
          color: SKIN_COLOR,
          title: sentence,
          header: sentence,
          doorways: [declaredDoorway()],
        };
      }
      return quiet(null);
    }
    const at = atPose.condition;
    const { value } = atPose;
    if (setsAt(own, at, property)) {
      if (at !== condition) {
        const sentence = Drupal.t('!value from @condition', {
          '!value': value,
          '@condition': conditionText(at, conditions),
        });
        return {
          posture: 'inherited',
          layer: ownLayer,
          reference: null,
          color: layerColor(ownLayer),
          title: sentence,
          header: sentence,
          doorways: [driveDoorway(at, conditions)],
        };
      }
      const beaten = shadowed?.[at]?.[property] ?? null;
      if (beaten) {
        const label = labelOf(beaten);
        const beneath = valueOf(beaten, at, property);
        const sentence =
          beneath != null
            ? Drupal.t("Your !value overrides @label's !beneath", {
                '!value': value,
                '@label': label,
                '!beneath': beneath,
              })
            : Drupal.t('Your !value overrides @label', {
                '!value': value,
                '@label': label,
              });
        return {
          posture: 'overriding',
          layer: variantLayerId(beaten),
          reference: beaten,
          color: layerColor(variantLayerId(beaten)),
          title: sentence,
          header: sentence,
          doorways: [
            {
              id: 'unset',
              label: Drupal.t('Unset'),
              hint:
                beneath != null
                  ? Drupal.t("@label's !beneath returns", {
                      '@label': label,
                      '!beneath': beneath,
                    })
                  : Drupal.t("@label's value returns", { '@label': label }),
            },
            documentDoorway(beaten, label, at, conditions),
          ],
        };
      }
      if (skinValue != null) {
        const sentence = from
          ? Drupal.t(
              "Your !value overrides the active skin's !skin at @condition",
              { '!value': value, '!skin': skinValue, ...from },
            )
          : Drupal.t("Your !value overrides the active skin's !skin", {
              '!value': value,
              '!skin': skinValue,
            });
        return {
          posture: 'overriding',
          layer: 'skin',
          reference: null,
          color: SKIN_COLOR,
          title: sentence,
          header: sentence,
          doorways: [
            {
              id: 'unset',
              label: Drupal.t('Unset'),
              hint: Drupal.t("the skin's !skin returns", {
                '!skin': skinValue,
              }),
            },
            ...skinDoorway(skinEditUrl),
          ],
        };
      }
      if (declared != null) {
        const sentence = from
          ? Drupal.t(
              "Your !value overrides the @declaration declaration's !declared at @condition",
              {
                '!value': value,
                '@declaration': declaration,
                '!declared': declared,
                ...from,
              },
            )
          : Drupal.t(
              "Your !value overrides the @declaration declaration's !declared",
              {
                '!value': value,
                '@declaration': declaration,
                '!declared': declared,
              },
            );
        return {
          posture: 'overriding',
          layer: 'declared',
          reference: null,
          color: SKIN_COLOR,
          title: sentence,
          header: sentence,
          doorways: [
            {
              id: 'unset',
              label: Drupal.t('Unset'),
              hint: Drupal.t(
                "the @declaration declaration's !declared returns",
                { '@declaration': declaration, '!declared': declared },
              ),
            },
          ],
        };
      }
      return quiet(ownLayer);
    }
    const supplier = suppliers?.[at]?.[property] ?? null;
    if (supplier) {
      const label = labelOf(supplier);
      const sentence =
        at === condition
          ? Drupal.t('!value from @label', { '!value': value, '@label': label })
          : Drupal.t('!value from @label at @condition', {
              '!value': value,
              '@label': label,
              '@condition': conditionText(at, conditions),
            });
      return {
        posture: 'inherited',
        layer: variantLayerId(supplier),
        reference: supplier,
        color: layerColor(variantLayerId(supplier)),
        title: sentence,
        header: sentence,
        doorways: [
          documentDoorway(supplier, label, at, conditions),
          ...(at === condition ? [] : [driveDoorway(at, conditions)]),
        ],
      };
    }
    if (at !== condition) {
      // A value no table attributes (a reference the library cannot
      // resolve), delivered from another condition: the WHERE still
      // holds.
      const sentence = Drupal.t('!value from @condition', {
        '!value': value,
        '@condition': conditionText(at, conditions),
      });
      return {
        posture: 'inherited',
        layer: ownLayer,
        reference: null,
        color: layerColor(ownLayer),
        title: sentence,
        header: sentence,
        doorways: [driveDoorway(at, conditions)],
      };
    }
    return quiet(ownLayer);
  })();
  return { ...origin, alsoAt };
};

/**
 * A token item's tooltip: the reference's condition-keyed resolutions,
 * so twins colliding here show where they honestly separate ("Dark:
 * 1.25rem"). Undefined when the token is one value everywhere.
 */
const resolutionTitle = (guide, materials, ref, condition) => {
  const conditions = guide?.conditions ?? {};
  const base = displayAt(guide, materials, ref, condition);
  const rows = Object.keys(conditions)
    .map((name) => ({
      label: conditions[name]?.label ?? name,
      value: displayAt(guide, materials, ref, name),
    }))
    .filter((row) => row.value !== null && row.value !== base);
  return rows.length
    ? rows.map((row) => `${row.label}: ${row.value}`).join(' · ')
    : undefined;
};

const tokenLabel = (guide, token) =>
  token.scale === null
    ? token.name
    : `${guide?.tokenScales?.[token.scale]?.label ?? token.scale} · ${token.name}`;

/**
 * The token half of provenance: the shown value's collision set (checked
 * on the worn token) and, where the space leaves the literal legal
 * (`escape`), the explicit "Custom value" detach.
 *
 * Offered only while the face sits on a stop or wears a token: a closed
 * space's stop is the worn one or the value-match (a scrub preview
 * echoing back, a legacy literal); an open detented space's the worn one
 * or the value-equal stop, a blessed-but-stopless reference still
 * offering the escape. A free space has no provenance to steer.
 *
 * @param {?object} guide
 * @param {?object} materials
 * @param {string} condition
 *   The editing compound (reference displays resolve here).
 * @param {?object} space
 *   The property's value space (value-space.js valueSpaceFor).
 * @param {string} value
 *   The shown value (the committed spelling or the draft echo).
 * @param {object} options
 * @param {boolean} [options.escape]
 *   Whether "Custom value" is offered; defaults to the detented kind.
 * @param {Function} options.onPreview
 *   (text) — paint a display value.
 * @param {Function} options.onSettle
 *   (text) — commit a spelling (a reference on a token pick, the face
 *   on the escape).
 *
 * @return {Array<object>}
 *   Menu items ({label, hint, title, checked, run}, a separator before
 *   the escape), [] when there is nothing to steer.
 */
export const tokenItems = (
  guide,
  materials,
  condition,
  space,
  value,
  { escape = space?.kind === 'detented', onPreview, onSettle } = {},
) => {
  if (!space || space.kind === 'free') {
    return [];
  }
  const { face, token, stop } = wearing(
    guide,
    materials,
    space,
    value,
    condition,
  );
  const anchor =
    space.kind === 'closed'
      ? (stop ?? magneticCapture(space.stops, face)?.stop ?? null)
      : (stop ??
        space.stops.find((candidate) => valuesEqual(candidate.value, face)) ??
        null);
  if (
    space.kind === 'closed'
      ? !anchor?.tokens?.length
      : anchor === null && token === null
  ) {
    return [];
  }
  const items = (anchor?.tokens ?? []).map((candidate) => ({
    label: tokenLabel(guide, candidate),
    hint: Drupal.t('follows the design system'),
    title: resolutionTitle(guide, materials, candidate.ref, condition),
    checked: token?.ref === candidate.ref,
    run: () => {
      onPreview(anchor.value);
      onSettle(candidate.ref);
    },
  }));
  if (escape) {
    if (items.length) {
      items.push({ separator: true });
    }
    items.push({
      label: Drupal.t('Custom value'),
      hint: Drupal.t('frozen, ignores skin changes'),
      checked: token === null,
      run: () => {
        // The explicit literal escape, per-settle: the shown value
        // commits as itself and stops tracking the system; any later
        // capture re-attaches.
        onPreview(face);
        onSettle(face);
      },
    });
  }
  return items;
};

/**
 * The row's right-click menu: the origin's doorways, the "Also set at"
 * group, then the token items, separated.
 *
 * @param {object} input
 * @param {?object} input.origin
 *   rowOrigin(), or null for a row with no origin story (a basic-mode
 *   field row before it carries one).
 * @param {Array<object>} input.tokens
 *   tokenItems().
 * @param {string} [input.face]
 *   The shown display value: the header when the origin has none to
 *   tell and the tokens are the whole menu.
 * @param {Function} [input.onDoorway]
 *   (doorway) — the row dispatches by doorway.id.
 * @param {Function} [input.onCondition]
 *   (condition) — drive the canvas to an "Also set at" entry.
 *
 * @return {?{header: ?string, items: Array<object>}}
 *   menu.js useMenu().open() spec parts, or null when there is nothing
 *   to show (the native menu rides through).
 */
export const rowMenu = ({
  origin = null,
  tokens = [],
  face = '',
  onDoorway = null,
  onCondition = null,
}) => {
  const groups = [];
  const doorways = (origin?.doorways ?? []).map((doorway) =>
    doorway.disabled
      ? {
          label: doorway.label,
          hint: doorway.hint,
          checked: null,
          disabled: true,
          reason: doorway.reason,
        }
      : {
          label: doorway.label,
          hint: doorway.hint,
          checked: null,
          run: () => onDoorway?.(doorway),
        },
  );
  if (doorways.length) {
    groups.push(doorways);
  }
  const alsoAt = origin?.alsoAt ?? [];
  if (alsoAt.length) {
    groups.push([
      { label: Drupal.t('Also set at'), checked: null, disabled: true },
      ...alsoAt.map((entry) => ({
        label: entry.label,
        hint: entry.value,
        checked: null,
        title: Drupal.t('Drive the canvas to @condition', {
          '@condition': entry.label,
        }),
        run: () => onCondition?.(entry.condition),
      })),
    ]);
  }
  if (tokens.length) {
    groups.push(tokens);
  }
  if (!groups.length) {
    return null;
  }
  return {
    header:
      origin?.header ??
      (tokens.length && face
        ? Drupal.t('Where should !value come from?', { '!value': face })
        : null),
    items: groups.flatMap((group, index) =>
      index ? [{ separator: true }, ...group] : group,
    ),
  };
};
