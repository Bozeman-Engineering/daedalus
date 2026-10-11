/**
 * Materials roster fixtures for the node tests.
 *
 * Builds delivery-shape records (MaterialRecord::toArray(): every field
 * present, snake_case) exactly as the page-load envelope carries them, and
 * indexes them with daedalus_ui's createRoster, the same index the
 * store hands the inspector. Tests describe materials tersely (a token
 * by scale/name/value, a swatch by name/kind/value, a variant by id and
 * its facts, a blueprint by id, an animation by name/label/steps, a font
 * by name/label/family/faces) and read them through
 * the roster like production code does; nothing here is a production
 * shape of its own.
 */
import { createRoster } from '../../../daedalus_ui/js/edit_mode/materials/roster.js';

const base = {
  description: '',
  scope: null,
  applies: {},
  owned_by: null,
  published: true,
  weight: 0,
  icon: null,
  updatable: true,
  groups: [],
  favorite: false,
};

/**
 * A token record: id "{scale}/{name}", label the name, group the scale,
 * spelled var(--{prefix}-{name}) (the provider's StyleGuide::tokenVariable
 * derivation, prefix defaulting to the scale id).
 */
export const tokenRecord = (scale, name, value, prefix = scale, extra = {}) => ({
  ...base,
  kind: 'token',
  id: `${scale}/${name}`,
  label: String(name),
  value,
  group: scale,
  spellings: { style: `var(--${prefix}-${name})` },
  origin: 'shipped',
  ...extra,
});

/**
 * The `updatable` flag the provider stamps: the distro lock holds on a
 * shipped record (a consuming site) unless the test says otherwise (the
 * developer gate: shipped AND updatable).
 */
const updatableFor = (origin, updatable = null) => updatable ?? origin !== 'shipped';

/**
 * A swatch record: id the machine name, group the kind, spelled
 * var(--swatch-{name}) (the reserved namespace). `extra.origin` shipped
 * reads locked unless `extra.updatable` says otherwise.
 */
export const swatchRecord = (name, kind, value, label = name, extra = {}) => ({
  ...base,
  kind: 'swatch',
  id: name,
  label,
  value,
  group: kind,
  spellings: { style: `var(--swatch-${name})` },
  origin: 'user',
  updatable: updatableFor(extra.origin ?? 'user', extra.updatable),
  ...extra,
});

/**
 * A variant record: id the machine name, group the axis ('style' unless
 * given), value the element-relative style map, scope as saved, owner and
 * origin as given (a shipped origin reads locked unless `updatable` says
 * otherwise). `spellings` derive the provider's way: a style-axis
 * variant spells as its class; a non-style axis variant (an attribute on
 * its element's tag) carries no spelling here unless the test passes one
 * (the JS never spells variants from a fixture, it resolves references
 * by id).
 */
export const variantRecord = (id, { label = id, group = 'style', value = {}, scope = 'kind:block', owned_by = null, origin = 'user', updatable = null, description = '', weight = 0, spellings = null, offered = true } = {}) => ({
  ...base,
  kind: 'variant',
  id,
  label,
  description,
  value,
  group,
  spellings: spellings ?? (group === 'style' ? { class: id } : {}),
  scope,
  owned_by,
  weight,
  origin,
  updatable: updatableFor(origin, updatable),
  offered,
});

/**
 * An animation record: id the machine name, value the canonical steps
 * map (offset -> declarations), spelled motion-{name} in the animation
 * slot (the provider's StyleGuide::animationName derivation; the style
 * slot never spells an animation).
 */
export const animationRecord = (name, label, steps, { description = '', origin = 'user', updatable = null } = {}) => ({
  ...base,
  kind: 'animation',
  id: name,
  label,
  description,
  value: steps,
  group: null,
  spellings: { animation: `motion-${name}` },
  origin,
  updatable: updatableFor(origin, updatable),
});

/**
 * A font record: id the machine name, value {family, faces} (the
 * canonical faces [{weight, style, source: {file: uuid}}]), spelled as
 * the quoted family in the font slot (the provider's
 * StyleGuide::fontFamily derivation).
 */
export const fontRecord = (name, label, family, faces, { description = '', origin = 'user', updatable = null, offered = true } = {}) => ({
  ...base,
  kind: 'font',
  id: name,
  label,
  description,
  value: { family, faces },
  group: null,
  spellings: { font: `"${family.replace(/(["\\])/g, '\\$1')}"` },
  origin,
  updatable: updatableFor(origin, updatable),
  offered,
});

/**
 * A blueprint record: id, label, updatable (a content blueprint unless told
 * otherwise; file-shipped blueprints are read-only and never transfer
 * targets), block scope, its groups (filter categories, none unless
 * given) and whether it is a site favorite (not unless given).
 */
export const blueprintRecord = (id, { label = id, updatable = true, origin = null, groups = [], favorite = false } = {}) => ({
  ...base,
  kind: 'blueprint',
  id,
  label,
  value: null,
  group: null,
  spellings: { blueprint: id },
  blueprint_scope: 'block',
  updatable,
  origin: origin ?? (updatable ? 'user' : 'shipped'),
  groups,
  favorite,
});

/**
 * A roster over records (arrays flatten).
 */
export const roster = (...records) => createRoster(records.flat());

/**
 * A roster from the terse guide-shaped fixture the older tests spoke:
 * {tokenScales: {scale: {prefix}}, tokens: {scale: {name: value}},
 * swatches: [{machine_name, label, kind, value}]}. Tokens roster in
 * declared scale order then stored order (the provider's walk), swatches
 * in list order.
 */
export const materialsFrom = ({ tokenScales = {}, tokens = {}, swatches = [] } = {}) => roster(
  Object.entries(tokenScales).flatMap(([scale, declaration]) => Object.entries(tokens?.[scale] ?? {})
    .map(([name, value]) => tokenRecord(scale, name, value, declaration?.prefix ?? scale))),
  swatches.map((swatch) => swatchRecord(swatch.machine_name, swatch.kind, swatch.value, swatch.label ?? swatch.machine_name, swatch.origin ? { origin: swatch.origin } : {})),
);

/**
 * The empty roster: a page before its boot, or a site without materials.
 */
export const EMPTY_MATERIALS = createRoster([]);
