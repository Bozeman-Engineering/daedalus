/**
 * Pure variant-scope logic: the client mirror of the applicability rule.
 *
 * A scope is a styling ADDRESS printed ("kind:container", "layout:x",
 * "block_type:y", "block_plugin:z", "base:a"): the one grammar lives in
 * daedalus_blueprint's PHP Address value object, and PHP is authoritative
 * (StyleMapValidator::captureScope() captures an element's address,
 * scopeMatches() pairs through Address::pairsWith(); the server
 * re-validates every apply/push regardless); this mirror exists so
 * the pickers can filter to applicable variants and the gesture buttons
 * can enable honestly, without a round trip per selection. Variants are
 * materials: every `variants` argument here is the store's variant records
 * (materials.ofKind('variant'), the delivery shape: id, label, group = the
 * axis, value = the element-relative style map, scope, owned_by, origin),
 * and a reference resolves by record id alone (findVariant). Also
 * home to the element-entry extraction the save/push/copy gestures share:
 * a variant (and the style clipboard) stores element-RELATIVE keys ('' for
 * the root element, '::.class-name' for sub-elements), so applied to
 * element key E, relative key K's entry lands at map key E + K. Composition
 * capture (save/push from a section) extends the relative space with
 * slot-key paths: the staged layout tree translates child map entries
 * (container:{uuid}, block:{uuid}, block:{uuid}:{field}) into
 * type-qualified positional slot keys (see slot-key.js for the grammar).
 */
import {
  parseKey,
  bundleForKey,
  declarationForKey,
  isSectionNode,
  sectionChildNodes,
} from './style-guide.js';
import { formatSlotKey, fragmentStyles } from './slot-key.js';
import { INSTANCE_PART } from './parts.js';
import { sharesGrain } from './widgets/lib/box-model.js';

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Whether an element-relative key addresses an INSTANCE part ('::.s-xx').
 *
 * Instance styles are instance-bound (the ids are minted per content
 * instance), so the variant capture walk leaves them behind: carrying
 * them would make the variant inert on other elements, or a lie when an
 * unrelated element reuses the id. The server's matching gate is
 * StyleMapValidator::validateAt()'s STYLE_INSTANCE_PART_IN_VARIANT.
 */
const isInstanceRelative = (relative) =>
  typeof relative === 'string' &&
  relative.startsWith('::') &&
  INSTANCE_PART.test(relative.slice(2));

/**
 * Whether an element-relative key addresses the TAG TIER ('::p', '::ul::>').
 *
 * Tag tiers live in host maps only: a variant
 * carrying tag-relative styles is an explicit follow-up, and the server's
 * SlotKey charset (dot-required parts) rejects them in variant styles, so
 * the capture walk must leave them behind or the save itself would be
 * rejected. The sub is dotless and not the '>' children pseudo-part.
 */
const isTagRelative = (relative) => {
  if (typeof relative !== 'string' || !relative.startsWith('::')) {
    return false;
  }
  const sub = relative.endsWith('::>')
    ? relative.slice(2, -3)
    : relative.slice(2);
  return sub !== '' && !sub.startsWith('.') && sub !== '>';
};

/**
 * The structured variant references a style entry carries, in list order.
 *
 * The client half of the server's one read semantics
 * (StyleMapValidator::entryRefs): each well-formed item (a {variant,
 * fragment} object with a non-empty variant and a string fragment, the
 * ONE reference shape) reads as {variant, fragment, via}, the origin
 * being the item's "via" when it carries a non-empty one (the
 * composition variant whose apply distributed the reference) and null
 * otherwise. Anything else in the slot (the retired bare-string
 * shorthand included) is ignored, mirroring the server's emit-gate
 * posture toward malformed data. The origin rides the read so no
 * consumer that rewrites a list can drop it by accident; consumers that
 * only wear, count or order ignore it, and the capture strips it on
 * purpose (captureSources: a variant's nested list says what to
 * distribute, never who did). The stored order is the APPLICATION
 * order; delivery emits in axis-declaration order (orderedEntryRefs),
 * so where two axes overlap on a property the rendered winner is the
 * vocabulary order, not this list's.
 *
 * @return {Array<{variant: string, fragment: string, via: (string|null)}>}
 *   The references, [] when the entry carries none.
 */
export const entryRefs = (entry) => {
  if (!isEntryObject(entry) || !Array.isArray(entry.variants)) {
    return [];
  }
  const refs = [];
  entry.variants.forEach((reference) => {
    if (
      isEntryObject(reference) &&
      typeof reference.variant === 'string' &&
      reference.variant !== '' &&
      typeof reference.fragment === 'string'
    ) {
      refs.push({
        variant: reference.variant,
        fragment: reference.fragment,
        via:
          typeof reference.via === 'string' && reference.via !== ''
            ? reference.via
            : null,
      });
    }
  });
  return refs;
};

/**
 * The variants a style entry references, in application order.
 *
 * The reference-string projection of entryRefs() (the server's
 * entryVariants), for consumers that care WHICH variants an entry wears,
 * not which fragment — attachment checks, usage counting, worn rows. A
 * fragment reference counts as wearing its variant exactly like a root
 * reference does.
 *
 * @return {string[]}
 *   The references, [] when the entry carries none.
 */
export const entryVariants = (entry) =>
  entryRefs(entry).map((ref) => ref.variant);

/**
 * Whether a style entry references any variant.
 */
export const isVariantRef = (entry) => entryRefs(entry).length > 0;

/**
 * Whether a section uuid sits at NESTED depth in the layout tree.
 *
 * The client twin of ElementLocator::isNestedSection, with the same
 * degradation posture: an unknown placement (empty tree, uuid not found —
 * an out-of-date satellite, a non-layout host) reads as root, never
 * invents a depth. Nested is only a POSITIVE find below the top level.
 * The ONE depth read every depth-gated consumer shares, and depth gates
 * offers only (the axis vocabulary's root-only axes): a grant is a
 * function of the declaration alone, so nothing legality-shaped asks here.
 */
export const isNestedSectionUuid = (layout, uuid) => {
  const sections = Array.isArray(layout) ? layout : [];
  if (sections.some((section) => section?.uuid === uuid)) {
    return false;
  }
  return findSection(sections, uuid) !== null;
};

/**
 * The scope's axis vocabulary for an element, from the served roster.
 *
 * Every served root node carries the roster's `axes` (PHP:
 * VocabularyRoster::axes(): the effective vocabulary, the built-in
 * "style" axis first, then the scope's declared axes in declaration
 * order, each with its label, depth stamp, description and owned
 * properties; axisDescription() and axisOwns() read the two facts)
 * order, each with its label and depth stamp), so the lookup is
 * branchless; an unparseable key degrades to the universal single-axis
 * vocabulary. This order is the delivery order: pass it wherever an
 * `axes` argument is taken. Sub-element keys share their parent's
 * vocabulary (axes are a scope fact, and parts drill within the scope),
 * so callers pass the ROOT key.
 *
 * Depth enters here and only here (the served node is depth-blind: one
 * layout, one node, any placement): axes the roster stamps root-only
 * (`root_only`, the {axis: {depth: root}} qualifier resolved
 * server-side) drop from a section's vocabulary when the layout tree
 * (source.getLayout(); the satellite's arrives by broadcast) positively
 * places the section at NESTED depth. Soft semantics: this gates what is
 * OFFERED; an existing reference on a dropped axis stays the tolerated
 * axis-gone orphan row (renders, sorts last, detachable). No tree, or a
 * uuid the tree does not carry, degrades to the ungated root posture,
 * mirroring the server's isNestedSection.
 *
 * @return {string[]}
 *   The axis names, ['style'] at minimum.
 */
export const axisVocabulary = (
  guide,
  key,
  blockTypes = {},
  sectionLayouts = {},
  layout = [],
) => {
  const parsed = parseKey(typeof key === 'string' ? key.split('::')[0] : key);
  if (!parsed) {
    return ['style'];
  }
  const declaration = declarationForKey(
    guide,
    parsed,
    bundleForKey(parsed, blockTypes, sectionLayouts),
  );
  const axes = declaration?.axes;
  if (
    axes === null ||
    typeof axes !== 'object' ||
    Array.isArray(axes) ||
    !Object.keys(axes).length
  ) {
    return ['style'];
  }
  const names = Object.keys(axes);
  if (parsed.kind === 'container' && isNestedSectionUuid(layout, parsed.uuid)) {
    const filtered = names.filter((axis) => axes[axis]?.root_only !== true);
    return filtered.length ? filtered : ['style'];
  }
  return names;
};

/**
 * An axis machine name as a display label.
 *
 * Declared labels first (the guide's axisLabels map, aggregated from the
 * rosters' axis labels across every served root), then the
 * derived form: the capitalized machine name with underscores as spaces
 * ('style' → 'Style'). The one axis-label rule for every display consumer
 * (wear bar row, save-as-variant axis picker, library axis chip/filter).
 *
 * @return {string}
 *   The label.
 */
export const axisLabel = (guide, axis) =>
  guide?.axisLabels?.[axis] ??
  axis.charAt(0).toUpperCase() + axis.slice(1).replaceAll('_', ' ');

/**
 * The served axes of an element's root, or {} when none are served.
 *
 * The one read behind the axis-fact readers: the root declaration's
 * `axes` map as ClientStyleGuide serves it (axis => {label, root_only,
 * description, owns}). Sub-element keys resolve to their root.
 */
const servedAxes = (guide, key, blockTypes = {}, sectionLayouts = {}) => {
  const parsed = parseKey(typeof key === 'string' ? key.split('::')[0] : key);
  if (!parsed) {
    return {};
  }
  const axes = declarationForKey(
    guide,
    parsed,
    bundleForKey(parsed, blockTypes, sectionLayouts),
  )?.axes;
  return axes !== null && typeof axes === 'object' && !Array.isArray(axes)
    ? axes
    : {};
};

/**
 * An axis's description on an element's scope, or null when undescribed.
 *
 * The client twin of VocabularyRoster::axisDescriptions(): the axis's
 * meaning as the declaration wrote it for a designer.
 *
 * @return {string|null}
 *   The description.
 */
export const axisDescription = (
  guide,
  key,
  axis,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const description = servedAxes(guide, key, blockTypes, sectionLayouts)[axis]
    ?.description;
  return typeof description === 'string' && description !== ''
    ? description
    : null;
};

/**
 * The property names an axis owns on an element's scope, [] when none.
 *
 * The client twin of VocabularyRoster::axisOwns(): the properties the
 * axis's values write, which a wear of the axis stands in for.
 *
 * @return {string[]}
 *   The owned property names.
 */
export const axisOwns = (
  guide,
  key,
  axis,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const owns = servedAxes(guide, key, blockTypes, sectionLayouts)[axis]?.owns;
  return Array.isArray(owns)
    ? owns.filter((name) => typeof name === 'string' && name !== '')
    : [];
};

/**
 * An entry's structured references in DELIVERY order (orderedEntryRefs
 * mirror).
 *
 * The server's one delivery-order semantics, client-side: references sort
 * by their resolved variant's axis position in the vocabulary (`axes`, the
 * scope's effective vocabulary exactly as the served roster carries it on
 * every root node, the built-in "style" axis first). A fragment
 * reference orders by ITS VARIANT's axis exactly like a root reference:
 * the fragment is a lookup key into the variant, never an ordering fact.
 * WITHIN an axis the tiebreak is structural: root ('' fragment) references
 * deliver BEFORE fragment references (the composition pair — the
 * fragment's values are the captured overrides that already beat the
 * variant nested within it), list order among equals. Unplaceable
 * references (unresolved in the library, or an axis outside the
 * vocabulary) sort last in kind-then-list order, exactly as the server
 * keeps them. The server reads each VARIANT's own scope's vocabulary
 * (StyleMapValidator::orderedEntryRefs); the target's vocabulary passed
 * here agrees with it for every applicable reference, because "style" is
 * position zero in every vocabulary and a refined scope's declared axes ARE
 * the target declaration's. With no vocabulary, or fewer than two
 * references, the stored list passes through unchanged.
 *
 * @return {Array<{variant: string, fragment: string, via: (string|null)}>}
 *   entryRefs() items in the order delivery layers them (later beats
 *   earlier).
 */
export const orderedEntryRefs = (entry, variants, axes = null) => {
  const refs = entryRefs(entry);
  if (refs.length < 2 || !Array.isArray(axes) || !axes.length) {
    return refs;
  }
  const position = (ref) => {
    const axis = findVariant(variants, ref.variant)?.group;
    const index = typeof axis === 'string' ? axes.indexOf(axis) : -1;
    return index === -1 ? axes.length : index;
  };
  const kind = (ref) => (ref.fragment === '' ? 0 : 1);
  return refs
    .map((ref, index) => ({ ref, index }))
    .sort(
      (a, b) =>
        position(a.ref) - position(b.ref) ||
        kind(a.ref) - kind(b.ref) ||
        a.index - b.index,
    )
    .map(({ ref }) => ref);
};

/**
 * An entry's references in DELIVERY order, as reference strings.
 *
 * The reference-string projection of orderedEntryRefs(), for consumers
 * that care about the delivery order among variants, not the fragments.
 *
 * @return {string[]}
 *   The references in the order delivery layers them (later beats earlier).
 */
export const orderedEntryVariants = (entry, variants, axes = null) =>
  orderedEntryRefs(entry, variants, axes).map((ref) => ref.variant);

/**
 * An entry's own (non-reference) layers: the "variants" key stripped.
 */
const stripVariants = (entry) => {
  const { variants, ...own } = entry;
  return own;
};

/**
 * Captures an element's scope (its address, printed), mirroring the server.
 *
 * Blocks are scoped per block type when the component's inline block type
 * is known ("block_type:{bundle}"), per base block plugin when the
 * component is a plugin placement (the table's 'plugin:{base id}'
 * discriminator, "block_plugin:{plugin_id}"), sections per layout when the section's
 * layout id is known ("layout:{layout_id}") — ONE resolution rule at every
 * depth: a section's declaration IS its layout's declaration, nested or
 * top-level — each degrading to its kind exactly as the server does
 * without context; every other element scopes by kind. Sub-element keys
 * capture their parent's scope.
 *
 * @return {?string}
 *   The scope, or null when the key does not parse.
 */
export const captureScope = (key, blockTypes = {}, sectionLayouts = {}) => {
  const parsed = parseKey(key);
  if (!parsed) {
    return null;
  }
  if (parsed.kind === 'block') {
    const bundle = blockTypes?.[parsed.uuid] ?? null;
    if (typeof bundle === 'string' && bundle.startsWith('plugin:')) {
      return `block_plugin:${bundle.slice('plugin:'.length)}`;
    }
    return bundle ? `block_type:${bundle}` : 'kind:block';
  }
  if (parsed.kind === 'container') {
    const layoutId = sectionLayouts?.[parsed.uuid] ?? null;
    return layoutId ? `layout:${layoutId}` : 'kind:container';
  }
  return `kind:${parsed.kind}`;
};

// The bundle-like address families as [kind, refinement prefix] pairs:
// sections refine by layout, blocks by block type OR base block plugin
// (two refinements of one kind, listed as separate pairs; the two never
// pair with each other, and each pairs with the kind wildcard). The rule
// is Address::pairsWith()'s, the same one roster.js scopesPair() twins
// under the materials lock.
const SCOPE_FAMILIES = [
  ['kind:container', 'layout:'],
  ['kind:block', 'block_type:'],
  ['kind:block', 'block_plugin:'],
];

/**
 * Whether a variant scope matches an element (StyleMapValidator mirror).
 *
 * ONE family rule, exactly as the server's Address::pairsWith(): scopes pair only within their
 * family, and within a family the kind is the wildcard — refinements must
 * match exactly. "layout:twocol" matches only "layout:twocol",
 * "block_type:cta" only "block_type:cta"; a kind on either side matches
 * any scope of its family. The wildcard serves both declared kinds
 * ("kind:container" variants style what every layout shares) and degraded
 * capture (unresolved bundle/layout must not fail styles that resolved
 * context would accept).
 *
 * base:{tag} variants (tag treatments: an anchor variant is a link style)
 * match every element: whether an element renders as the tag is a
 * live-DOM fact the declaration-space families cannot express, so the tag
 * gate is soft enforcement at OFFER time (applicableVariants' tag filter)
 * — the stencil-ownership posture: offering filters, worn references
 * always render and stay detachable.
 */
export const scopeMatches = (
  scope,
  key,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const captured = captureScope(key, blockTypes, sectionLayouts);
  if (captured === null || typeof scope !== 'string') {
    return false;
  }
  if (captured === scope) {
    return true;
  }
  if (scope.startsWith('base:')) {
    return true;
  }
  return SCOPE_FAMILIES.some(([kind, refinement]) => {
    const inFamily = (s) => s === kind || s.startsWith(refinement);
    return (
      inFamily(captured) &&
      inFamily(scope) &&
      (captured === kind || scope === kind)
    );
  });
};

/**
 * The family kind beneath a refinement scope ('kind:container' under a
 * 'layout:' scope), or null for a scope that is not a refinement (a kind
 * itself, a base tag): the layer the defaults readers fold in under the
 * captured scope, in delivery rank order.
 */
const familyKindOf = (scope) =>
  SCOPE_FAMILIES.find(([, refinement]) => scope.startsWith(refinement))?.[0] ??
  null;

/**
 * The active skin's site-default entry for an element (the third layer).
 *
 * The provenance read behind the inspector's "from skin" rendering: the
 * guide payload carries the active skin's defaults (guide.skinDefaults,
 * {scope: {relative key: entry}}), and an element resolves its own scope
 * against them. Delivery layers base-tag rules first, then the family
 * kind's block, then the refinement's over it (the compiled file's rank
 * order), so the client mirrors that merge: kind values win over base
 * values, refinement values win over kind values. The result is the
 * weakest SKIN layer everywhere — every per-element value and every worn
 * variant beats a skin default in the cascade; only the declaration's
 * own defaults (declaredDefaultEntry) sit beneath it — so callers render
 * it as provenance (badges, placeholders), never merge it into the
 * effective entry the widgets edit.
 *
 * The base layer needs the caller to say what the target RENDERS AS: the
 * scope families are declaration-space (kind, block type, layout) while
 * base:{tag} rules deliver on bare markup, so only the live DOM knows
 * whether they hit this element (a collapsed heading block IS its h2; a
 * wrapped block's root is a div the base tier never touches). Callers
 * resolve the drilled element's tag from the canvas (leader-side, live;
 * satellite-side from the leader's relayed selection tags; null when
 * unknown, and the base layer then degrades away, never over-claims).
 * Base defaults are element-only ('' entries),
 * so the tag layer reads '' regardless of the key's part drill — the
 * drilled part element's own tag is what the caller resolves.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {string} key
 *   A full style-map key (root or '::.class' part form).
 * @param {object} [blockTypes]
 *   component uuid → inline block type, for scope capture.
 * @param {object} [sectionLayouts]
 *   section uuid → layout id, for scope capture.
 * @param {?string} [tag]
 *   The drilled target element's rendered tag name (lowercase), or null
 *   when unknown (no canvas read answers it): the base:{tag} layer key.
 *
 * @return {?object}
 *   The merged condition-keyed property map the skin supplies for this
 *   element, or null when the skin defaults nothing here.
 */
export const skinDefaultEntry = (
  guide,
  key,
  blockTypes = {},
  sectionLayouts = {},
  tag = null,
) => {
  const defaults = guide?.skinDefaults;
  if (!isEntryHost(defaults) || typeof key !== 'string') {
    return null;
  }
  const scope = captureScope(key, blockTypes, sectionLayouts);
  if (scope === null) {
    return null;
  }
  const pos = key.indexOf('::');
  const relative = pos === -1 ? '' : key.slice(pos);
  let entry = null;
  const baseScope =
    typeof tag === 'string' && tag !== '' ? `base:${tag}` : null;
  const layerCandidate = (layer) => {
    if (layer === null) {
      return null;
    }
    return layer === baseScope
      ? defaults[layer]?.['']
      : defaults[layer]?.[relative];
  };
  [baseScope, familyKindOf(scope), scope].forEach((layer) => {
    const candidate = layerCandidate(layer);
    if (isEntryHost(candidate)) {
      entry = entry ? mergeEntry(candidate, entry) : candidate;
    }
  });
  return entry;
};

/**
 * A declaration's own default entry for an element (beneath the skin).
 *
 * The declared defaults tier's provenance read: a declaration ships
 * `defaults` for the markup it owns (guide.declaredDefaults, {scope:
 * {relative key: entry}}, served always, skin or no skin): a block
 * type, block plugin, or layout refinement for its own scope, and a
 * kind for what the kind itself renders (a composed kind's root, any
 * kind's declared `::.part` elements). An element resolves its scope
 * against them the way skinDefaultEntry does: the family kind's block
 * first, the refinement's merged over it (the compiled file's rank
 * order, kind before refinement), so a refined section reads the
 * container kind's `display: grid` beneath its layout's own defaults,
 * and an element whose scope IS a kind (the entity, an unrefined
 * section, an unresolved bundle) reads the kind's alone. No base:{tag}
 * layer: a declaration's box facts key off its own markup, never the
 * rendered tag. Delivery prints the tier beneath the skin's at equal
 * specificity, so the caller layers the skin's entry OVER this one;
 * both are provenance, never merged into the entry the widgets edit.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {string} key
 *   A full style-map key (root or '::.class' part form).
 * @param {object} [blockTypes]
 *   component uuid → inline block type, for scope capture.
 * @param {object} [sectionLayouts]
 *   section uuid → layout id, for scope capture.
 *
 * @return {?object}
 *   The merged condition-keyed property map the declarations supply for
 *   this element, or null when none declares anything here.
 */
export const declaredDefaultEntry = (
  guide,
  key,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const defaults = guide?.declaredDefaults;
  if (!isEntryHost(defaults) || typeof key !== 'string') {
    return null;
  }
  const scope = captureScope(key, blockTypes, sectionLayouts);
  if (scope === null) {
    return null;
  }
  const pos = key.indexOf('::');
  const relative = pos === -1 ? '' : key.slice(pos);
  let entry = null;
  [familyKindOf(scope), scope].forEach((layer) => {
    const candidate = layer === null ? null : defaults[layer]?.[relative];
    if (isEntryHost(candidate)) {
      entry = entry ? mergeEntry(candidate, entry) : candidate;
    }
  });
  return entry;
};

/**
 * A plain-object gate for defaults payload pieces.
 */
const isEntryHost = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * An element's stencil provenance: the nearest self-or-ancestor stamp.
 *
 * The offering read for stencil-owned variants: instantiate_stencil stamps
 * the source stencil's machine name onto the placed ROOT only, and the
 * serializer surfaces it as the node's read-only `stencil` key (the same
 * stamp daedalus_layout's update-stencil verb reads). A placement is the whole
 * placed subtree, so every element under a stamped root inherits its
 * provenance — the walk carries the nearest ancestor's stamp down, and a
 * nested placement's own stamp shadows its parent's. Degradation mirrors
 * the depth rule's: no tree, or a uuid the tree does not carry, reads as
 * unprovenanced (null), never a guess.
 *
 * @param {string} key
 *   A selectable key (root or '::.class' part form).
 * @param {Array} layout
 *   The staged layout tree (style-guide.js layoutTree(); the satellite's
 *   arrives by broadcast).
 *
 * @return {?string}
 *   The stencil machine name, or null.
 */
export const stencilProvenance = (key, layout = []) => {
  const parsed = parseKey(typeof key === 'string' ? key.split('::')[0] : key);
  if (!parsed?.uuid) {
    return null;
  }
  let found = null;
  const walk = (nodes, inherited) => {
    for (const node of Array.isArray(nodes) ? nodes : []) {
      const stamp =
        typeof node?.stencil === 'string' && node.stencil !== ''
          ? node.stencil
          : inherited;
      if (node?.uuid === parsed.uuid) {
        found = stamp;
        return true;
      }
      if (walk(sectionChildNodes(node), stamp)) {
        return true;
      }
    }
    return false;
  };
  walk(layout, null);
  return found;
};

/**
 * Whether a variant's owning stencil is gone: the ONE orphan rule.
 *
 * A record carries its owner's id (`owned_by`), never an orphan flag: the
 * owner is orphaned exactly when the roster holds no stencil of that id
 * (deleted, or a file stencil discovery no longer serves). An orphaned
 * variant offers nowhere and its wearers keep rendering.
 *
 * @param {object} materials
 *   The materials roster.
 * @param {?object} variant
 *   A variant record.
 *
 * @return {boolean}
 */
export const isOrphaned = (materials, variant) =>
  variant?.owned_by != null && !materials.has('stencil', variant.owned_by);

/**
 * Whether a variant's ownership lets an element's picker offer it.
 *
 * Unowned variants (`owned_by` null) offer everywhere their scope matches.
 * OWNED variants offer only where the selection's provenance IS the owner
 * and the owner still exists — soft enforcement, exactly the depth
 * qualifier and axis-gone posture: this gates what is OFFERED, existing
 * references always render and stay detachable. Orphaned variants (owner
 * deleted) offer nowhere.
 */
const ownershipOffers = (materials, variant, provenance) => {
  if (variant?.owned_by == null) {
    return true;
  }
  return !isOrphaned(materials, variant) && variant.owned_by === provenance;
};

/**
 * Whether a variant's scope lets the selection's rendered tag offer it.
 *
 * The tag half of soft enforcement for base:{tag} variants: their scope
 * pairs with every element (scopeMatches), but the OFFER is gated on the
 * selection actually rendering as the tag — anchor variants offer only on
 * an element whose live root IS an <a> (a collapsed button block), h2
 * variants only on a rendered heading. The tag is a live-DOM fact the
 * leader canvas answers (readCanvasTag) and relays to the satellite with
 * the selection; null means unknown, and base variants then do not
 * offer, never over-offering. Element-scoped variants ignore the tag
 * entirely.
 */
const tagOffers = (variant, tag) =>
  !variant.scope.startsWith('base:') ||
  (typeof tag === 'string' && tag !== '' && variant.scope === `base:${tag}`);

/**
 * Filters the store's variants to those applicable to an element.
 *
 * Three gates: the scope family rule; the tag rule for base:{tag}
 * variants (offered only when the selection's rendered tag — resolved
 * from the live canvas by the caller — matches); and, for stencil-owned
 * variants, the ownership rule against the element's placement provenance
 * (the layout tree resolves it; without a tree owned variants simply do
 * not offer, the degraded posture that never over-offers). The offer is a
 * roster question (ownership needs the stencils beside the variants), so
 * the roster is the input, not a variant list.
 *
 * @param {object} materials
 *   The materials roster.
 *
 * @return {object[]}
 *   The applicable variant records, roster order.
 */
export const applicableVariants = (
  materials,
  key,
  blockTypes = {},
  sectionLayouts = {},
  layout = [],
  tag = null,
) => {
  const provenance = stencilProvenance(key, layout);
  return materials
    .ofKind('variant')
    .filter(
      (variant) =>
        typeof variant?.scope === 'string' &&
        scopeMatches(variant.scope, key, blockTypes, sectionLayouts) &&
        tagOffers(variant, tag) &&
        ownershipOffers(materials, variant, provenance),
    );
};

/**
 * Extracts an element's OVERRIDE style entries, element-relative.
 *
 * The shape save-as-variant, push, and the style clipboard all speak:
 * '' => the root element's entry, '::.class' => sub-element entries.
 * The extraction is overrides-only: a layered entry contributes its
 * own condition layers with the "variants" list stripped — the references
 * are not styles (resolve them through the library instead; see
 * effectiveElementMap()), and flattening their values in would bake
 * another variant's treatment into whatever consumes the capture. A
 * pure-reference entry contributes nothing.
 */
export const elementEntries = (map, key) => {
  const entries = {};
  const add = (relative, entry) => {
    if (!isEntryObject(entry)) {
      return;
    }
    const own = stripVariants(entry);
    if (Object.keys(own).length) {
      entries[relative] = own;
    }
  };
  add('', map?.[key]);
  Object.entries(map ?? {}).forEach(([mapKey, entry]) => {
    if (mapKey.startsWith(`${key}::`)) {
      add(mapKey.slice(key.length), entry);
    }
  });
  return entries;
};

/**
 * Whether an element carries custom (non-reference) styles.
 */
export const hasCustomStyles = (map, key) =>
  Object.keys(elementEntries(map, key)).length > 0;

const findSection = (sections, uuid) => {
  for (const section of Array.isArray(sections) ? sections : []) {
    if (section?.uuid === uuid) {
      return section;
    }
    for (const node of sectionChildNodes(section)) {
      if (isSectionNode(node)) {
        const found = findSection([node], uuid);
        if (found) {
          return found;
        }
      }
    }
  }
  return null;
};

const findComponent = (sections, uuid) => {
  for (const section of Array.isArray(sections) ? sections : []) {
    for (const node of sectionChildNodes(section)) {
      if (isSectionNode(node)) {
        const found = findComponent([node], uuid);
        if (found) {
          return found;
        }
        continue;
      }
      if (node?.uuid === uuid) {
        return node;
      }
    }
  }
  return null;
};

/**
 * Maps the staged subtree's selectable keys to slot-key segment paths.
 *
 * The translation table behind composition capture: walking the layout
 * tree (the blueprint's layout_builder__layout shape, or the pared
 * layoutTree() projection) from the given root element, every child the
 * slot grammar can address maps to its segments (formatSlotKey() turns
 * them into stored keys). Mirrors the server's apply-time resolution walk
 * (SubtreeSlotKeys) exactly:
 *
 * - Only inline blocks (block_type resolved) get block segments, and only
 *   they advance the per-bundle index — a non-inline placement is not
 *   addressable, so it is transparent to the captured keys and the count.
 * - A nested section node gets a container(n) segment (counted
 *   positionally among the flow's section nodes) and recursion opens its
 *   own children under the container's path.
 * - Only CONTAINER sections (a `children` list) carry a slot space: their
 *   children are one ordered flow the positional grammar can address. A
 *   multi-region section's children live in named slots the grammar no
 *   longer speaks, so it contributes no slots of its own (it is still
 *   addressable AS a child via container(n)).
 * - A block root maps to [] so its block:{uuid}:{field} keys translate to
 *   field(name) paths.
 *
 * Container and block roots only; every other root kind carries parts
 * only, so the table is empty and capture degrades to elementEntries().
 *
 * @return {object}
 *   Full map base keys → slot segment arrays for the root's subtree.
 */
export const subtreeSlotTable = (key, layout) => {
  const table = {};
  const addSection = (section, prefix) => {
    if (!Array.isArray(section?.children)) {
      return;
    }
    const counts = {};
    let containers = 0;
    section.children.forEach((node) => {
      if (isSectionNode(node)) {
        containers += 1;
        const containerPath = [
          ...prefix,
          { type: 'container', index: containers },
        ];
        if (node.uuid) {
          table[`container:${node.uuid}`] = containerPath;
        }
        addSection(node, containerPath);
        return;
      }
      const bundle = node?.block_type ?? null;
      if (!node?.uuid || !bundle) {
        return;
      }
      counts[bundle] = (counts[bundle] ?? 0) + 1;
      table[`block:${node.uuid}`] = [
        ...prefix,
        { type: 'block', name: bundle, index: counts[bundle] },
      ];
    });
  };
  const parsed = parseKey(key);
  if (!parsed || parsed.sub) {
    return table;
  }
  if (parsed.form === 'container') {
    const section = findSection(layout, parsed.uuid);
    if (section) {
      addSection(section, []);
    }
  } else if (parsed.form === 'block') {
    if (findComponent(layout, parsed.uuid)) {
      // A block root opens at its fields: the [] entry lets
      // block:{uuid}:{field} keys translate to field(name) paths.
      table[key] = [];
    }
  }
  return table;
};

/**
 * How many of a variant's SLOTS a target element resolves (the ranking).
 *
 * The picker's fit walk, in slot units ("fits 2 of 4 slots"): a slot is a
 * distinct addressed ELEMENT — the base path shared by a style key and
 * its '::' suffixes ('' and its parts are ONE slot, the root element; a
 * child's key and its part keys are that child's one slot). Counting
 * style keys here double-counted parts and condition splits, which is
 * how a fully-fitting variant could badge as partial. A slot RESOLVES
 * when the target's staged layout actually has its element — the root
 * ('' always resolves) or a slot present in subtreeSlotTable(). A
 * trailing field(name) segment rides its block's resolution (fields are
 * not in the layout tree; delivery-wise a field slot applies whenever
 * its block exists), so 'field(x)' on a plain block root resolves like
 * the root does. Unresolved slots are inert CSS, not breakage — the
 * picker ranks and badges, never hides.
 *
 * @return {{resolved: number, total: number}}
 *   Resolved and total slot counts (a variant with no styles is a full
 *   fit of zero).
 */
export const variantFit = (styles, key, layout = []) => {
  const resolvable = new Set(['']);
  Object.values(subtreeSlotTable(key, layout)).forEach((segments) => {
    resolvable.add(formatSlotKey(segments));
  });
  const slots = new Set();
  const resolved = new Set();
  Object.keys(styles ?? {}).forEach((styleKey) => {
    const pos = styleKey.indexOf('::');
    const base = pos === -1 ? styleKey : styleKey.slice(0, pos);
    slots.add(base);
    if (
      resolvable.has(base) ||
      resolvable.has(base.replace(/(?:^|\/)field\([a-z0-9_]+\)$/, ''))
    ) {
      resolved.add(base);
    }
  });
  return { resolved: resolved.size, total: slots.size };
};

/**
 * Ranks variants by fit against a target element (the picker order).
 *
 * Full resolves first, partial fits after by resolved count descending;
 * ties keep the incoming (label-sorted library) order — Array.prototype
 * .sort is stable. Nothing is ever dropped: partial application is
 * experimentation, and undo/redo is the net.
 *
 * @return {Array<{variant: object, resolved: number, total: number}>}
 *   One entry per variant, badge-ready.
 */
export const rankVariants = (variants, key, layout = []) =>
  (Array.isArray(variants) ? variants : [])
    .map((variant) => ({ variant, ...variantFit(variant?.value, key, layout) }))
    .sort((a, b) => {
      const fullA = a.resolved === a.total ? 1 : 0;
      const fullB = b.resolved === b.total ? 1 : 0;
      return fullB - fullA || b.resolved - a.resolved;
    });

/**
 * Merges two style entries, the winner's values taking precedence.
 *
 * Condition maps merge per property.
 */
export const mergeEntry = (winner, loser) => {
  const merged = { ...loser, ...winner };
  Object.keys(winner).forEach((condition) => {
    if (isEntryObject(winner[condition]) && isEntryObject(loser?.[condition])) {
      merged[condition] = { ...loser[condition], ...winner[condition] };
    }
  });
  return merged;
};

/**
 * Extracts an element's custom styles INCLUDING its staged subtree's.
 *
 * What the push gesture sends, and the base layer of the save-as payload
 * (saveStyles() folds the replaced same-axis values beneath this):
 * elementEntries() (the '' root and '::.class' parts, overrides-only:
 * the root's own references fold or stay per saveStyles' axis rule,
 * never as refs) plus every child map entry the slot grammar can
 * address, its key translated through subtreeSlotTable(). Children's
 * refs stay refs: a child's worn ROOT references travel verbatim in the
 * captured fragment's "variants" list (entry-shaped fragments; apply
 * re-distributes them, preserving the child's layer stack), while its
 * own condition layers travel overrides-only as ever and its fragment
 * references stay behind (see captureSources). Entries the grammar
 * cannot carry — non-inline placements, keys outside the subtree — stay
 * behind entirely, exactly as they stay out of the compiled selector
 * space, and so do INSTANCE parts ('::.s-xx'): identity classes are
 * minted per content instance, so their styles are instance-bound and
 * never travel in a variant (see captureSources).
 *
 * With no layout tree (an out-of-date satellite, a non-layout host) the
 * table is empty and this degrades to elementEntries() — today's
 * single-element capture.
 */
export const captureEntries = (map, key, layout = []) => {
  const sources = captureSources(map, key, layout);
  const entries = {};
  sources.direct.forEach(({ relative, entry }) => {
    entries[relative] = entries[relative]
      ? mergeEntry(entry, entries[relative])
      : entry;
  });
  sources.own.forEach(({ relative, entry }) => {
    entries[relative] = entry;
  });
  return entries;
};

/**
 * The capture walk with provenance: which map key fed which relative key.
 *
 * The shared walk under captureEntries() and shadowClearingOps(): the same
 * inclusion rules (the element's own root and parts overrides-only, the
 * staged subtree's slot-addressable children), each source kept separate
 * as {key, relative, entry} instead of merged — clearing needs the
 * CONCRETE key an override lives at, which the merged capture shape
 * discards. Buckets mirror the merge precedence: 'own' (root and parts)
 * over 'direct' (translated children). INSTANCE parts ('::.s-xx', minted
 * identity classes) stay behind in BOTH buckets: their styles are
 * instance-bound, so save/push never carry them into a variant, and,
 * symmetrically, shadow clearing never unsets them (the variant cannot
 * cover what it never captured).
 *
 * Children's refs stay refs: a child base entry's worn ROOT ('' fragment)
 * references are part of its look and travel as {variant, fragment} in
 * the captured entry's own "variants" list (entry-shaped fragments), so
 * the minted variant re-distributes them instead of baking their values
 * in: the layer story survives the capture, and a child wearing only
 * references still contributes a fragment. The origin the read carries
 * ("via", the composition that distributed the wear) is stripped on the
 * way in: a variant's nested list says what to distribute, never who
 * did, and the server's variant-styles lane rejects the key, so an
 * unstripped capture would refuse to save (the PHP twin, VariantCapture,
 * strips the same way). FRAGMENT (non-'') references never travel: they
 * are owned by the composition that distributed them. saveStyles() folds
 * them where the re-attach replaces them; they stay behind on the
 * element where it does not. References live on base entries; a part
 * entry's own layers stay overrides-only exactly like the root's.
 *
 * @return {{own: Array, direct: Array}}
 *   Source lists of {key, relative, entry}. 'direct' orders SHALLOW-FIRST
 *   (slot path depth, map order among equals): a captured parent precedes
 *   its children in the minted variant's styles, so apply distributes the
 *   parent's nested references (which may recurse over the child's
 *   subtree) before placing deeper fragment references, giving
 *   deterministic outcomes for captured nested compositions.
 */
export const captureSources = (map, key, layout = []) => {
  const own = Object.entries(elementEntries(map, key))
    .filter(
      ([relative]) => !isInstanceRelative(relative) && !isTagRelative(relative),
    )
    .map(([relative, entry]) => ({ key: key + relative, relative, entry }));
  const table = subtreeSlotTable(key, layout);
  const direct = [];
  Object.entries(map ?? {}).forEach(([mapKey, entry]) => {
    if (!isEntryObject(entry)) {
      return;
    }
    if (mapKey === key || mapKey.startsWith(`${key}::`)) {
      return;
    }
    const parsed = parseKey(mapKey);
    // Instance parts are instance-bound and tag tiers are host-map-only
    // (the server rejects both in variant styles), so
    // neither travels in a capture.
    if (
      !parsed ||
      (parsed.sub !== null &&
        (INSTANCE_PART.test(parsed.sub) || !parsed.sub.startsWith('.')))
    ) {
      return;
    }
    const overrides = stripVariants(entry);
    // References live on base entries only: part and children pseudo-part
    // entries travel overrides-only, like the root's parts.
    const refs =
      parsed.sub === null && !parsed.children
        ? entryRefs(entry)
            .filter((ref) => ref.fragment === '')
            .map(({ variant, fragment }) => ({ variant, fragment }))
        : [];
    if (!Object.keys(overrides).length && !refs.length) {
      return;
    }
    const basePos = mapKey.indexOf('::');
    const base = basePos === -1 ? mapKey : mapKey.slice(0, basePos);
    let segments = null;
    if (parsed.form === 'block_field') {
      const [, uuid, fieldName] = base.split(':');
      const parent = table[`block:${uuid}`];
      if (parent) {
        segments = [...parent, { type: 'field', name: fieldName }];
      }
    } else if (base in table) {
      segments = table[base];
    }
    if (segments === null) {
      return;
    }
    const travels = refs.length ? { variants: refs, ...overrides } : overrides;
    direct.push({
      key: mapKey,
      relative: formatSlotKey(segments, parsed.sub, parsed.children),
      entry: travels,
      depth: segments.length,
    });
  });
  direct.sort((a, b) => a.depth - b.depth);
  return { own, direct };
};

/**
 * The styles a save-as writes into the NEW variant (root-axis folding).
 *
 * Save means "save this LOOK", not "save my tweaks": the payload is the
 * capture (captureEntries, children's worn root references riding as
 * refs) with the values of every wearing the save's re-attach is about to
 * REPLACE folded beneath it. Folding happens exactly where a reference
 * stops delivering when the batch lands, and nowhere else; everywhere
 * else refs stay refs:
 *
 * - The ROOT's saved axis folds wholesale: minting this axis's
 *   replacement is what save-as IS, so every same-axis wearing at the
 *   capture root (root reference, fragment wearing, both halves of a
 *   worn pair) resolves through its own fragment (fragmentStyles, the
 *   exact read delivery uses) and bakes beneath the captured overrides,
 *   in delivery order (later-delivered values beat earlier beneath the
 *   overrides).
 * - A CHILD's same-axis ROOT wearing survives as the captured reference:
 *   distribution re-places it beside the fresh fragment reference (the
 *   composition pair spares it), so its values never fold: the child
 *   keeps following its variant through the new composition.
 * - A CHILD's same-axis FRAGMENT wearing folds: the re-attach replaces it
 *   (the fresh fragment reference takes the axis's fragment slot, and the
 *   replaced ROOT composition's fragments are group-stripped even where
 *   nothing was captured), so its values must not vanish from the look.
 *
 * CROSS-AXIS references stay exempt, never flattened: the root's stay on
 * the element (not captured), a child's root references travel as refs,
 * and baking axes together is exactly what the axis model forbids. A
 * child whose same-axis fragment wearing the re-attach does NOT touch
 * (nothing captured and not a fragment of the replaced composition)
 * contributes nothing and keeps following its own variant. Accepted cost
 * of the root fold: the new variant COPIES the replaced values rather
 * than following the variant they came from.
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The capture root's selectable key.
 * @param {Array} layout
 *   The staged layout tree (subtreeSlotTable's shape).
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {string} axis
 *   The axis the new variant competes on ('style' when the save skipped
 *   the pick, mirroring the server default).
 *
 * @return {object}
 *   The save payload: element-relative entries, captured overrides and
 *   references over the folded same-axis values.
 */
export const saveStyles = (
  map,
  key,
  layout = [],
  variants = [],
  axis = 'style',
) => {
  const entries = captureEntries(map, key, layout);
  const sameAxisRefs = (baseKey, fragmentOnly) =>
    entryRefs(map?.[baseKey])
      .map((ref) => ({ ref, resolved: findVariant(variants, ref.variant) }))
      .filter(
        ({ ref, resolved }) =>
          resolved !== null &&
          (resolved.group ?? 'style') === axis &&
          (!fragmentOnly || ref.fragment !== ''),
      );
  const fold = (base, worn) => {
    // Delivery within an axis is structural (roots before fragments,
    // list order among equals) and later delivery beats earlier, so fold
    // in REVERSE delivery order: the last-delivered values land first and
    // each earlier layer slots beneath what is already there (captured
    // overrides stay on top throughout).
    [
      ...worn.filter(({ ref }) => ref.fragment === ''),
      ...worn.filter(({ ref }) => ref.fragment !== ''),
    ]
      .reverse()
      .forEach(({ ref, resolved }) => {
        Object.entries(fragmentStyles(resolved.value, ref.fragment)).forEach(
          ([suffix, entry]) => {
            const relative = base + suffix;
            entries[relative] = entries[relative]
              ? mergeEntry(entries[relative], entry)
              : entry;
          },
        );
      });
  };
  // The root's same-axis wearings are replaced by the re-attach apply
  // unconditionally; the root-kind ones are the compositions whose
  // distributed fragments the group cleanup strips from the subtree.
  const rootWorn = sameAxisRefs(key, false);
  fold('', rootWorn);
  const replaced = rootWorn
    .filter(({ ref }) => ref.fragment === '')
    .map(({ resolved }) => resolved);
  const table = subtreeSlotTable(key, layout);
  const capturedBases = new Set(
    captureSources(map, key, layout).direct.map(({ key: mapKey }) => {
      const pos = mapKey.indexOf('::');
      return pos === -1 ? mapKey : mapKey.slice(0, pos);
    }),
  );
  Object.keys(map ?? {}).forEach((base) => {
    // References live on base entries only; the root's own space is done.
    if (base === key || base.includes('::')) {
      return;
    }
    // Only same-axis FRAGMENT wearings fold on children: a same-axis
    // ROOT wearing rides the capture as a reference instead.
    const worn = sameAxisRefs(base, true);
    if (!worn.length) {
      return;
    }
    // A child folds when the re-attach will strip its fragment wearing:
    // it is captured (the new variant holds its slot path, so
    // distribution replaces its same-axis fragment), or it carries a
    // fragment the replaced ROOT composition distributed (the same-axis
    // group cleanup strips those even where nothing was captured).
    const stripped = worn.some(({ resolved }) => replaced.includes(resolved));
    if (!capturedBases.has(base) && !stripped) {
      return;
    }
    const parsed = parseKey(base);
    let segments = null;
    if (parsed?.form === 'block_field') {
      const [, uuid, fieldName] = base.split(':');
      const parent = table[`block:${uuid}`];
      if (parent) {
        segments = [...parent, { type: 'field', name: fieldName }];
      }
    } else if (base in table) {
      segments = table[base];
    }
    if (segments === null || !segments.length) {
      return;
    }
    fold(formatSlotKey(segments), worn);
  });
  return entries;
};

/**
 * The styles a push writes into a variant (the layered-model payload).
 *
 * Pushing from an element WEARING the variant means "bake my tweaks in":
 * the capture (overrides-only values, children's worn root references
 * riding as refs) merges OVER the variant's current styles per relative
 * key, so properties the editor never touched survive the push (an
 * overrides-only replacement would gut them site-wide) while a captured
 * "variants" list replaces the fragment's nested list. Pushing from an
 * unattached element keeps the historic replacement semantics — the
 * variant becomes the captured treatment wholesale, which is also what
 * makes detach-edit-push remove a property from a variant.
 */
export const pushStyles = (variant, entry, captured) => {
  const attached = entryVariants(entry).some(
    (reference) => reference === variant?.id,
  );
  if (!attached) {
    return captured;
  }
  const merged = { ...(variant?.value ?? {}) };
  Object.entries(captured ?? {}).forEach(([relative, capturedEntry]) => {
    merged[relative] = isEntryObject(merged[relative])
      ? mergeEntry(capturedEntry, merged[relative])
      : capturedEntry;
  });
  return merged;
};

/**
 * The update_style unsets that retire overrides a variant now covers.
 *
 * After a save-as or push, every captured override is IN the variant: left
 * on the element it would keep shadowing the reference, so later pushes
 * from other use sites would never show here — the exact propagation break
 * the layered model exists to cure. These ops ride the re-attach batch
 * (apply_variant first, then the unsets), atomically through the op spine,
 * so a failed batch leaves the element detached-but-identical.
 *
 * An override is retired only when clearing it cannot change the look:
 * - its value equals what the variant now stores at the same relative key,
 *   condition, and property (a differing value is a live override and
 *   stays), and
 * - no OTHER reference in play defines the same property — a root
 *   reference on another axis (the same-axis one is replaced by the
 *   re-attach), or, for a captured child entry, the child's own
 *   CROSS-AXIS references, each read through ITS OWN fragment (a
 *   distributed {variant, fragment} reference delivers its fragment's
 *   styles, never the root's). A child's SAME-AXIS reference never
 *   competes: an unset is only proposed where the pushed variant holds the
 *   child's slot path, so the re-attach's apply is guaranteed to
 *   distribute a fresh fragment reference onto that child: a same-axis
 *   FRAGMENT wearing is replaced outright (newest application wins the
 *   axis), and a same-axis ROOT wearing, though it survives as the
 *   captured pair, delivers UNDER the fresh fragment's values (structural
 *   order), which are exactly the captured override being unset.
 *   References to the pushed variant itself never compete: they are
 *   exactly what the re-attach re-covers. Delivery order between
 *   overlapping variants is the vocabulary's, not ours to guess here, so
 *   overlap keeps the override (the warned cross-axis case).
 *
 * @return {Array<object>}
 *   update_style operations, empty when nothing shadows.
 */
export const shadowClearingOps = (map, key, layout, variant, variants) => {
  const isSame = (reference) => reference === variant?.id;
  const competitorStyles = (mapKey, relative) => {
    const styles = [];
    entryRefs(map?.[key])
      .filter((ref) => !isSame(ref.variant))
      .forEach((ref) => {
        const resolved = findVariant(variants, ref.variant);
        if (resolved && resolved.group !== variant?.group) {
          styles.push(resolved.value?.[ref.fragment + relative]);
        }
      });
    const pos = mapKey.indexOf('::');
    const childRoot = pos === -1 ? mapKey : mapKey.slice(0, pos);
    if (childRoot !== key) {
      const childRelative = mapKey.slice(childRoot.length);
      entryRefs(map?.[childRoot])
        .filter((ref) => !isSame(ref.variant))
        .forEach((ref) => {
          const resolved = findVariant(variants, ref.variant);
          if (resolved && resolved.group !== variant?.group) {
            styles.push(resolved.value?.[ref.fragment + childRelative]);
          }
        });
    }
    return styles.filter(isEntryObject);
  };

  const ops = [];
  const { own, direct } = captureSources(map, key, layout);
  [...direct, ...own].forEach(({ key: mapKey, relative, entry }) => {
    const covered = variant?.value?.[relative];
    if (!isEntryObject(covered)) {
      return;
    }
    const competitors = competitorStyles(mapKey, relative);
    const competes = (condition, property) =>
      competitors.some((styles) => styles[condition]?.[property] !== undefined);
    const keyOps = [];
    Object.entries(entry).forEach(([condition, values]) => {
      if (!isEntryObject(values)) {
        return;
      }
      const patch = {};
      Object.entries(values).forEach(([property, value]) => {
        if (
          covered[condition]?.[property] === value &&
          !competes(condition, property)
        ) {
          patch[property] = null;
        }
      });
      if (Object.keys(patch).length) {
        const op = { op: 'update_style', key: mapKey, properties: patch };
        if (condition !== 'default') {
          op.condition = condition;
        }
        keyOps.push(op);
      }
    });
    ops.push(...keyOps);
  });
  return ops;
};

/**
 * Projects element-relative entries onto an element's full map keys.
 */
export const entriesAtKey = (key, relativeEntries) => {
  const full = {};
  Object.entries(relativeEntries ?? {}).forEach(([relative, entry]) => {
    full[key + relative] = entry;
  });
  return full;
};

/**
 * Resolves a variant reference against the store's records.
 *
 * A {"variant": "..."} string resolves by record id, the machine name: the
 * one identity the roster speaks. A reference in any other form (the
 * retired uuid spelling a map may still carry) is unresolved, exactly like
 * a reference to an unpublished or deleted variant: it renders as a stale
 * wearing, detachable and removable, never as a document.
 *
 * @return {?object}
 *   The variant record, or null when the records do not carry it.
 */
export const findVariant = (variants, reference) =>
  (Array.isArray(variants) ? variants : []).find(
    (candidate) => candidate?.id === reference,
  ) ?? null;

/**
 * Whether a material record is module-shipped (its origin).
 *
 * A shipped variant, swatch or animation is the provider's design
 * vocabulary, hash-synced from disk. The origin gates the SHIPPED
 * gestures: "Copy as my variant" (the fork) and "Revert to defaults"
 * (whether the entity diverged from its disk copy is a management fact
 * the usage route answers per open, never a roster fact), and it is the
 * roster rows' `shipped` marker. It is NOT the lock: see locked().
 */
export const isShipped = (record) => record?.origin === 'shipped';

/**
 * The distro lock, client-side: whether a record is read-only.
 *
 * The record's `updatable` flag is the lock as the provider stamps it
 * (DaedalusStylishMaterialsProvider::updatable, over daedalus_blueprint's
 * DistroLock): a shipped material is locked on a consuming site and
 * writable once the developer gate (`$settings['daedalus_blueprint_developer']`)
 * lifts the lock, so a record can be both shipped and updatable. The
 * flag gates the EDITING gestures (details, revisions, edit styles,
 * duplicate, ownership, delete, the wear row's update doorway, the code
 * view's editability); locked variants are never offered as push targets
 * anywhere. Honest chrome only: the endpoints and the blueprint lane
 * enforce. A record without the flag reads locked (nothing to write).
 */
export const locked = (record) => record?.updatable !== true;

/**
 * Whether a variant holds distributed fragments (a composition).
 *
 * True when any style key carries a slot path — anything beyond the ''
 * root entry and its '::.class' parts. Applying such a variant distributes
 * {variant, fragment} references onto the resolved children, so a worn
 * root reference to one is what the re-apply gesture targets (the variant
 * area's Re-apply button, the page tree's section chrome).
 */
export const holdsFragments = (variant) =>
  Object.keys(variant?.value ?? {}).some(
    (relative) => relative !== '' && !relative.startsWith('::'),
  );

/**
 * The condition-keyed styles a map key EFFECTIVELY carries, with provenance.
 *
 * The edit-mode resolver ("a variant is a preset, not a stylesheet"): when
 * the key's element roots variant references, the entry resolves from each
 * referenced variant's element-relative styles ('' for the element itself,
 * '::.class' for its parts) in DELIVERY order (axis-declaration order when
 * the caller passes the scope's vocabulary (`axes`), the stored list order
 * without it; later references beat earlier ones), with the map's own entry
 * (the overrides layer) merged on top, through the store's variant
 * records. Without references the map's own entry passes
 * through. Inspector widgets, code mode, and the detach materialization all
 * read the element through this one projection, so what they show IS
 * what the element looks like.
 *
 * Every reference resolves through ITS OWN fragment (fragmentStyles — the
 * same read delivery uses): a root ('' fragment) reference reads the
 * variant's element-relative entries, a distributed {variant, fragment}
 * reference reads its fragment's entry and part entries. Slot paths a key's
 * own references do not name address OTHER elements — those elements carry
 * their own references — so a child key still resolves against its OWN
 * root's references only.
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   A full style-map key (root, '::.class' part, or block-field form).
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root element scope's axis vocabulary (the served roster's
 *   variant_axes), for delivery-order resolution; null keeps list order.
 *
 * @return {{entry: ?object, variant: ?object, reference: ?string}}
 *   `entry` is the effective condition-keyed styles (null when none),
 *   `reference` is the root's LAST raw reference string (the dominant
 *   layer) — the provenance flag: non-null means "from a variant", even
 *   when the records cannot resolve it (then `variant` is null) — and
 *   `variant` the last resolvable referenced variant's record for
 *   provenance copy.
 */
export const effectiveEntry = (map, key, variants, axes = null) => {
  if (typeof key !== 'string') {
    return { entry: null, variant: null, reference: null };
  }
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const refs = orderedEntryRefs(map?.[rootKey], variants, axes);
  const own = map?.[key];
  const ownEntry = isEntryObject(own) ? stripVariants(own) : null;
  if (!refs.length) {
    return { entry: ownEntry, variant: null, reference: null };
  }
  let entry = null;
  let variant = null;
  refs.forEach((ref) => {
    const resolved = findVariant(variants, ref.variant);
    if (resolved) {
      variant = resolved;
    }
    const styles = resolved?.value?.[ref.fragment + key.slice(rootKey.length)];
    if (isEntryObject(styles)) {
      entry = entry ? mergeEntry(styles, entry) : styles;
    }
  });
  if (ownEntry && Object.keys(ownEntry).length) {
    entry = entry ? mergeEntry(ownEntry, entry) : ownEntry;
  }
  return { entry, variant, reference: refs[refs.length - 1].variant };
};

/**
 * The element's map view with its variant references RESOLVED.
 *
 * Replaces a root entry's variant references with the referenced variants'
 * entries (projected onto the element's keys in delivery order: axis order
 * when the caller passes the scope's vocabulary, list order without it,
 * later references beating earlier ones, the entry's own override layers
 * merged on top), so a paste over an on-variant element can diff against
 * what the element actually looks like: the ops then null out resolved
 * properties the pasted styles don't carry. Override layers win at EVERY
 * projected key, not only the root: a part entry the map carries beside a
 * variant that also styles that part merges OVER the variant's part styles
 * (effectiveEntry's rule), so the code buffer prints the value the page
 * actually shows.
 *
 * Each reference projects ITS OWN fragment's suffix space (fragmentStyles:
 * the fragment's entry as '', its part entries as '::.class' — the keys
 * that concatenate onto the element's own key space), so a distributed
 * {variant, fragment} reference resolves exactly what it delivers. Slot
 * keys a reference's fragment does not name address OTHER elements
 * carrying their own references; naive concatenation would mint malformed
 * map keys, so they stay out of this view.
 */
export const effectiveElementMap = (map, key, variants, axes = null) => {
  const root = map?.[key];
  const refs = orderedEntryRefs(root, variants, axes);
  if (!refs.length) {
    return map ?? {};
  }
  const elementRelatives = {};
  refs.forEach((ref) => {
    const variant = findVariant(variants, ref.variant);
    Object.entries(fragmentStyles(variant?.value, ref.fragment)).forEach(
      ([relative, entry]) => {
        if (isEntryObject(entry)) {
          elementRelatives[relative] = elementRelatives[relative]
            ? mergeEntry(entry, elementRelatives[relative])
            : entry;
        }
      },
    );
  });
  const ownRoot = stripVariants(root);
  if (Object.keys(ownRoot).length) {
    elementRelatives[''] = elementRelatives['']
      ? mergeEntry(ownRoot, elementRelatives[''])
      : ownRoot;
  }
  const effective = { ...map };
  delete effective[key];
  Object.entries(entriesAtKey(key, elementRelatives)).forEach(
    ([fullKey, entry]) => {
      const own = isEntryObject(effective[fullKey])
        ? stripVariants(effective[fullKey])
        : {};
      effective[fullKey] = Object.keys(own).length
        ? mergeEntry(own, entry)
        : entry;
    },
  );
  return effective;
};

/**
 * The override tuples shadowing a worn variant's values (the badges).
 *
 * An entry's own condition layers beat every reference the element wears
 * (own beats worn), so each (condition, property) the OWN entry defines
 * whose longhand grain meets a declaration a referenced variant makes at
 * the same element-relative key is a SHADOW: the variant's value cannot
 * show through until the override is unset (or the reference detached).
 * The match is grain-aware, not name-exact (box-model.js sharesGrain):
 * an own `padding` shadows a worn `padding-inline` (the band's own
 * padding masks its width inset), an own `padding-top` does not, exactly
 * as the cascade delivers. Resolution reads the key exactly like
 * effectiveEntry(): the ROOT's references answer for the root and its
 * '::.class' parts, each reference's styles looked up through ITS OWN
 * fragment at the key's relative position — a child wearing a distributed
 * {variant, fragment} reference badges exactly like a root wearer; among
 * several defining references the delivery winner (orderedEntryRefs with
 * the vocabulary) is reported.
 *
 * @return {object}
 *   condition → {own property: reference} for every shadowing own
 *   tuple, {} when nothing shadows.
 */
export const shadowedProperties = (map, key, variants, axes = null) => {
  if (typeof key !== 'string') {
    return {};
  }
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const refs = orderedEntryRefs(map?.[rootKey], variants, axes);
  const own = map?.[key];
  if (!refs.length || !isEntryObject(own)) {
    return {};
  }
  const relative = key.slice(rootKey.length);
  const shadowed = {};
  Object.entries(stripVariants(own)).forEach(([condition, values]) => {
    if (!isEntryObject(values)) {
      return;
    }
    Object.keys(values).forEach((property) => {
      refs.forEach((ref) => {
        const styles = findVariant(variants, ref.variant)?.value?.[
          ref.fragment + relative
        ];
        const worn = isEntryObject(styles) ? styles[condition] : null;
        if (
          isEntryObject(worn) &&
          Object.keys(worn).some(
            (declaration) =>
              worn[declaration] !== undefined &&
              sharesGrain(property, declaration),
          )
        ) {
          (shadowed[condition] ??= {})[property] = ref.variant;
        }
      });
    });
  });
  return shadowed;
};

/**
 * The variant-supplied (condition, property) tuples at a key.
 *
 * The widget rows' layer-attribution read (the layer dots): for every
 * (condition, property) any worn reference defines at the key's relative
 * position, the DELIVERY-WINNING reference — orderedEntryRefs order, a
 * later reference beating an earlier one, exactly the cascade the compiled
 * stylesheet delivers. Reported regardless of the element's own entry: the
 * own layer always wins delivery, so the caller checks it first and reads
 * this table only for values the element does not set itself. Resolution
 * mirrors shadowedProperties(): the ROOT's references answer for the root
 * and its '::.class' parts, each reference's styles looked up through ITS
 * OWN fragment at the key's relative position.
 *
 * @return {object}
 *   condition → {property: reference} for every variant-defined tuple, {}
 *   when nothing is worn.
 */
export const propertySuppliers = (map, key, variants, axes = null) => {
  if (typeof key !== 'string') {
    return {};
  }
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const refs = orderedEntryRefs(map?.[rootKey], variants, axes);
  const relative = key.slice(rootKey.length);
  const suppliers = {};
  refs.forEach((ref) => {
    const styles = findVariant(variants, ref.variant)?.value?.[
      ref.fragment + relative
    ];
    if (!isEntryObject(styles)) {
      return;
    }
    Object.entries(styles).forEach(([condition, values]) => {
      if (condition === 'variants' || !isEntryObject(values)) {
        return;
      }
      Object.keys(values).forEach((property) => {
        (suppliers[condition] ??= {})[property] = ref.variant;
      });
    });
  });
  return suppliers;
};

/**
 * The value ONE worn reference supplies for a (condition, property) at a
 * key: the provenance model's "what returns on unset" read behind the
 * overriding row's menu. Resolution mirrors propertySuppliers(): the
 * ROOT's references answer for the root and its '::.class' parts, the
 * reference's styles looked up through ITS OWN fragment at the key's
 * relative position.
 *
 * @return {*}
 *   The supplied value, undefined when the reference is not worn there
 *   or sets nothing at that tuple.
 */
export const suppliedValue = (
  map,
  key,
  variants,
  reference,
  condition,
  property,
  axes = null,
) => {
  if (typeof key !== 'string') {
    return undefined;
  }
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const ref = orderedEntryRefs(map?.[rootKey], variants, axes).find(
    (candidate) => candidate.variant === reference,
  );
  if (!ref) {
    return undefined;
  }
  const styles = findVariant(variants, reference)?.value?.[
    ref.fragment + key.slice(rootKey.length)
  ];
  const block =
    isEntryObject(styles) && condition !== 'variants'
      ? styles[condition]
      : null;
  return isEntryObject(block) ? block[property] : undefined;
};
