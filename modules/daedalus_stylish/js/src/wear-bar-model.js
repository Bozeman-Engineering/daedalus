/**
 * The wearing rows' pure model (the styling place; four controls, one
 * loud mode).
 *
 * The styling place is everything that styles THIS element, wearing
 * included: one row per WORN reference, and one apply gesture (the
 * variant picker, variant-picker-model.js) over the offer. Applying or
 * swapping a variant writes the element's map (instant cadence,
 * tempstore net, which is exactly why the rows live in the styling place and not
 * inside the editing session). Rows carry no focus duty and no
 * pseudo-row, and a variant is edited in the editing session (its own
 * document, pending-until-Push): a worn row's affordance there is a
 * DOORWAY into the session (the row body, the menu's edit entry; the
 * overridden-by badges enter the same way).
 * This module owns everything the view can compute without a DOM — the
 * row roster, the per-row menu roster, the variant-reach read behind
 * Re-apply, the overridden read and its reset-overrides op builder (a
 * masked wearing's row action), and the posed read (what a wearing
 * contributes at the canvas's compound; wearAtPose) — for node-test
 * backpressure (the line-menu.js pattern). The view only renders and
 * dispatches.
 */

import { axisLabel, entryRefs, findVariant } from './variant-scope.js';
import { fragmentStyles } from './slot-key.js';
import { appliesWithin, cascadeOrder } from './condition-cascade.js';
import { sharesGrain } from './widgets/lib/box-model.js';
import { stencilLabel } from './library-model.js';

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The condition blocks a wearing delivers to the worn element itself: the
 * root entry ('') of the wearing's slice (the variant's own root for a
 * root wearing, the fragment's root for a distributed one). Null when
 * the records cannot resolve the wearing.
 */
const wornRootStyles = (variants, ref) => {
  const worn = ref ? findVariant(variants ?? [], ref.variant) : null;
  if (!worn) {
    return null;
  }
  const styles = ref.fragment
    ? fragmentStyles(worn.value ?? {}, ref.fragment)
    : (worn.value ?? {});
  return isEntryObject(styles['']) ? styles[''] : {};
};

/**
 * The wearing rows' roster for an element: what it WEARS, nothing else.
 *
 * One row per worn reference the records resolve, in axis-declaration
 * order (the vocabulary's order; an axis the vocabulary no longer offers
 * appends after it, the axis-gone case: the reference still renders and
 * stays detachable). Offering is a gesture (the variant picker), so an
 * axis nothing is worn on has no row, and the row count tracks the
 * wearing, never the vocabulary size. A claimed axis has no row either:
 * a mounted section in the current mode owns that wearing's surface
 * (basic mode's Layout segment for container_layout), and one reference
 * gets one surface. References the records cannot resolve (deleted,
 * unpublished, a retired uuid spelling, or withheld by the style guide:
 * staleReason) hold no determinable axis and list separately as stale
 * rows; the store boots with the page, so there is no loading state to
 * gate on. Every row's `ref` is the entryRefs() item verbatim, so a
 * distributed nested wearing rides its origin (`via`) into the row
 * (wearOrigin names it).
 *
 * @param {?object} entry
 *   The element's style entry (the root map entry — variants live on base
 *   entries).
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {string[]} vocabulary
 *   The scope's axis vocabulary (axisVocabulary()).
 * @param {string[]} claimedAxes
 *   Axes a mounted section claims for the current mode.
 *
 * @return {{rows: Array<{axis: string, ref: {variant: string,
 *   fragment: string, via: ?string}}>, stale: Array<{variant: string,
 *   fragment: string, via: ?string}>}}
 */
export const wornRows = (entry, variants, vocabulary, claimedAxes = []) => {
  const refs = entryRefs(entry);
  const axisOf = (reference) => findVariant(variants, reference)?.group ?? null;
  const axes = [
    ...vocabulary,
    ...new Set(
      refs
        .map((ref) => axisOf(ref.variant))
        .filter(
          (axis) => typeof axis === 'string' && !vocabulary.includes(axis),
        ),
    ),
  ];
  const rows = [];
  for (const axis of axes) {
    const ref = refs.find((candidate) => axisOf(candidate.variant) === axis);
    if (ref && !claimedAxes.includes(axis)) {
      rows.push({ axis, ref });
    }
  }
  return {
    rows,
    stale: refs.filter((ref) => !findVariant(variants, ref.variant)),
  };
};

/**
 * Why a stale row is stale: withheld by the style guide, or gone.
 *
 * The records cannot resolve a reference for two reasons, and the row
 * says which. A variant the site's CURRENT law refuses is withheld from
 * the roster exactly as an inactive system's member is (not offered, not
 * delivered), and the guide payload names it under `withheld` with the
 * refusals the verdict found, {path, code, message} triples with paths
 * relative to the variant's styles: the provenance the wearer never had
 * ("default.border-radius: 0.5em is not a radius scale token"). A name
 * absent from the map is plainly gone: deleted, unpublished, or a
 * retired uuid spelling.
 *
 * @param {?object} guide
 *   The style guide payload (guide.withheld).
 * @param {string} reference
 *   The stale reference, verbatim as the entry stores it.
 *
 * @return {{withheld: boolean, refusals: Array<{path: string, code:
 *   string, message: string}>}}
 */
export const staleReason = (guide, reference) => {
  const refusals = guide?.withheld?.[reference];
  return Array.isArray(refusals) && refusals.length > 0
    ? { withheld: true, refusals }
    : { withheld: false, refusals: [] };
};

/**
 * The refusals as one line, "path: message; path: message".
 *
 * The reference refusal's own spelling (StyleMapValidator's
 * STYLE_VARIANT_NONCONFORMING message joins the same triples the same
 * way, a trailing period trimmed per message), so the row's title and
 * menu read as the server's refusal would.
 *
 * @param {Array<{path: string, message: string}>} refusals
 *
 * @return {string}
 */
export const refusalText = (refusals) =>
  refusals
    .map(
      ({ path, message }) => `${path}: ${String(message).replace(/\.$/, '')}`,
    )
    .join('; ');

/**
 * A worn row's ORIGIN: the composition that distributed this wearing.
 *
 * A reference carrying `via` is derived data, the composition's provenance:
 * the op handler stamped it when the named composition's apply nested
 * this variant onto the child, the same composition's same-axis replace
 * or remove reaps it, and a direct apply here writes a fresh reference
 * with no origin (the editor takes the axis over). The row says so on
 * the stale row's idiom (a modifier class, the origin in a sub-line), so
 * the editor can tell a wear the card put here from one they chose. The
 * origin is named by its record's label, or by the machine name when
 * the records cannot resolve it (a stale origin is inert: nothing reaps
 * by it, and the wear reads as the child's own, but the fact is still
 * shown verbatim rather than hidden). Null for a wearing with no origin.
 *
 * @param {?{variant: string, fragment: string, via: ?string}} ref
 *   The worn reference (entryRefs() shape).
 * @param {?Array<object>} variants
 *   The store's variant records.
 *
 * @return {?{via: string, label: string}}
 */
export const wearOrigin = (ref, variants) => {
  const via = typeof ref?.via === 'string' && ref.via !== '' ? ref.via : null;
  if (via === null) {
    return null;
  }
  return { via, label: findVariant(variants, via)?.label ?? via };
};

/**
 * Whether a variant's styles reach beyond one wearing's slice.
 *
 * The Re-apply gate's reach read: the slice a {variant, fragment} wearing
 * delivers to its element is the fragment's suffix space (its entry plus
 * '::' part entries — fragmentStyles' read); any style key outside it
 * lands on OTHER elements (a composition's children for a root wearing;
 * the root and sibling fragments for a fragment wearing). The Variants
 * surface renders the whole document either way — reach no longer gates
 * the way in, only the gestures that act on a composition's spread.
 *
 * @param {?object} styles
 *   The variant's element-relative styles.
 * @param {string} fragment
 *   The wearing's fragment ('' for a root wearing).
 *
 * @return {boolean}
 */
/**
 * The properties a wearing writes on the worn element itself: the
 * variant's root entry ('') across every condition, deduped, in
 * declaration order. What a wear-bar row hands the canvas highlight on
 * hover, so a width variant paints its inset the way a padding scrub
 * does (painterless properties tint nothing; the painter registry
 * decides). A fragment wearing reads its slice's root the same way.
 *
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?{variant: string, fragment: string}} ref
 *   The wearing, or null.
 *
 * @return {string[]}
 */
export const wornProperties = (variants, ref) => {
  const root = wornRootStyles(variants, ref);
  if (!root) {
    return [];
  }
  const properties = [];
  for (const declarations of Object.values(root)) {
    for (const property of Object.keys(declarations ?? {})) {
      if (!properties.includes(property)) {
        properties.push(property);
      }
    }
  }
  return properties;
};

/**
 * What a wearing contributes to the worn element AT a posed compound: the
 * posed half of the pose doctrine for wear surfaces.
 *
 * A wear is unconditioned by construction (the reference list is a
 * sibling of the entry's condition keys; the material owns its WHEN), so
 * posing the canvas never retargets a wear. What the pose changes is
 * which of the variant's OWN condition blocks apply: this read answers
 * "what does this wearing set at Hover" the way the printer's cascade
 * would deliver it: every root-slice block other than default whose
 * parts the compound covers (appliesWithin), merged in structural
 * cascade order (cascadeOrder, later wins per property). The default
 * block is the row itself and is not part of the answer, so an entrance
 * variant that carries default + reduced-motion "sets nothing at Hover".
 * At the default pose nothing but default applies, so nothing is posed.
 * The one primitive every posed wear surface reads through (the wear
 * bar's sub-line, the layout segment's effective arrangement).
 *
 * @param {?{variant: string, fragment: string}} ref
 *   The wearing, or null.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {string} compound
 *   The editing compound ('default', a single, or 'a+b').
 * @param {object} conditions
 *   The condition table (guide.conditions), for cascade order.
 *
 * @return {{applies: boolean, declarations: Object<string, string>}}
 *   `applies` when at least one property is set by a block the pose
 *   activates; `declarations` those properties with their cascade-winning
 *   values, in first-declared order. An unresolved wearing sets nothing.
 */
export const wearAtPose = (ref, variants, compound, conditions) => {
  const root = wornRootStyles(variants, ref);
  const posed = Object.keys(root ?? {}).filter(
    (condition) =>
      condition !== 'default' &&
      isEntryObject(root[condition]) &&
      appliesWithin(condition, compound),
  );
  const declarations = {};
  for (const condition of cascadeOrder(posed, conditions)) {
    Object.assign(declarations, root[condition]);
  }
  return { applies: Object.keys(declarations).length > 0, declarations };
};

export const reachesBeyond = (styles, fragment = '') =>
  Object.keys(styles ?? {}).some(
    (key) => key !== fragment && !key.startsWith(`${fragment}::`),
  );

/**
 * The element's own declarations shadowing ONE worn reference, tuple by
 * tuple: the one walk behind the overridden read and its reset patch.
 *
 * The element's own condition layers beat every worn reference, so each
 * own (condition, property) whose longhand grain meets a declaration the
 * reference makes at the same relative key is a shadow. The match is
 * grain-aware, not name-exact (box-model.js sharesGrain): an own
 * `padding` masks a worn `padding-inline` (the band's own padding over
 * its width inset), an own `padding-top` does not, exactly as the
 * cascade delivers. The walk covers the wearing's whole delivered slice:
 * the element's base entry and its '::' part entries, each read through
 * the wearing's own fragment (fragmentStyles — the read delivery uses).
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The wearing element's selectable key.
 * @param {string} reference
 *   The worn reference, verbatim as the entry stores it.
 * @param {?Array<object>} variants
 *   The store's variant records.
 *
 * @return {Array<{key: string, condition: string, property: string,
 *   masks: string[]}>}
 *   One tuple per shadowing own declaration, `masks` the worn
 *   declarations it hides in the variant's declaration order; [] when
 *   nothing shadows, the reference is not worn, or the records cannot
 *   resolve it.
 */
const shadowingTuples = (map, key, reference, variants) => {
  const ref = entryRefs(map?.[key]).find(
    (candidate) => candidate.variant === reference,
  );
  const variant = findVariant(variants, reference);
  if (!ref || !variant) {
    return [];
  }
  const delivered = fragmentStyles(variant.value, ref.fragment);
  const tuples = [];
  const ownKeys = [
    key,
    ...Object.keys(map ?? {}).filter((mapKey) => mapKey.startsWith(`${key}::`)),
  ];
  ownKeys.forEach((mapKey) => {
    const covered = delivered[mapKey.slice(key.length) || ''];
    const own = map?.[mapKey];
    if (!isEntryObject(covered) || !isEntryObject(own)) {
      return;
    }
    Object.entries(own).forEach(([condition, values]) => {
      const worn = covered[condition];
      if (
        condition === 'variants' ||
        !isEntryObject(values) ||
        !isEntryObject(worn)
      ) {
        return;
      }
      Object.keys(values).forEach((property) => {
        const masks = Object.keys(worn).filter(
          (declaration) =>
            worn[declaration] !== undefined &&
            sharesGrain(property, declaration),
        );
        if (masks.length) {
          tuples.push({ key: mapKey, condition, property, masks });
        }
      });
    });
  });
  return tuples;
};

/**
 * The update_style patch that retires a list of shadowing tuples: one op
 * per (key, condition) in first-seen order, every shadowing own property
 * unset. Nothing detaches and cross-property overrides stay: only the
 * values the reference actually covers go.
 */
const unsetOps = (tuples) => {
  const ops = [];
  tuples.forEach(({ key, condition, property }) => {
    let op = ops.find(
      (candidate) =>
        candidate.key === key &&
        (candidate.condition ?? 'default') === condition,
    );
    if (!op) {
      op = { op: 'update_style', key, properties: {} };
      if (condition !== 'default') {
        op.condition = condition;
      }
      ops.push(op);
    }
    op.properties[property] = null;
  });
  return ops;
};

/**
 * The update_style unsets that drop an element's own values shadowing ONE
 * worn reference (Reset overrides: the row follows its variant fully).
 *
 * Unsetting a shadow lets the variant's value show through (or a
 * later-delivered layer's — the cascade's business, not this gesture's).
 * The shadows are shadowingTuples()' (grain-aware: an own `padding` is
 * unset for a worn `padding-inline`; the band's own top and bottom
 * padding go with it, which is what "follow it fully" means).
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The wearing element's selectable key.
 * @param {string} reference
 *   The worn reference, verbatim as the entry stores it.
 * @param {?Array<object>} variants
 *   The store's variant records.
 *
 * @return {Array<object>}
 *   update_style operations, [] when nothing shadows.
 */
export const resetOverrideOps = (map, key, reference, variants) =>
  unsetOps(shadowingTuples(map, key, reference, variants));

/**
 * A worn row's OVERRIDDEN state: what the element's own values hide of
 * this wearing, and the row action that lets it show through again.
 *
 * The honesty half of the layer rule (own overrides beat worn variants,
 * LIVE semantics): a wearing whose declarations the element masks is
 * still worn, and the row says so where the wearing is chosen, on the
 * stale row's idiom (a modifier class, the reason in a sub-line). `lost`
 * names the worn declarations no longer showing (`padding-inline` under
 * an own `padding`), `by` the own properties hiding them, both deduped
 * in declaration order; `ops` is resetOverrideOps()' patch.
 * Reset overrides is the row's ACTION (wear-bar.js renders it beside the
 * sub-line): it moved out of the context menu with this read, so a
 * masked wearing is announced, never left behind a right-click.
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The wearing element's selectable key.
 * @param {string} reference
 *   The worn reference, verbatim as the entry stores it.
 * @param {?Array<object>} variants
 *   The store's variant records.
 *
 * @return {?{lost: string[], by: string[], ops: Array<object>}}
 *   null when nothing shadows (the row is plainly worn).
 */
export const overriddenWearing = (map, key, reference, variants) => {
  const tuples = shadowingTuples(map, key, reference, variants);
  if (!tuples.length) {
    return null;
  }
  return {
    lost: [...new Set(tuples.flatMap((tuple) => tuple.masks))],
    by: [...new Set(tuples.map((tuple) => tuple.property))],
    ops: unsetOps(tuples),
  };
};

/**
 * The one Outline Variant gesture over the Inspector's outlined state.
 *
 * Outline is a single toggled reference painted on the canvas AND marked
 * in the page elements panel, with two entry points — the wearing row
 * menu (toggling the worn reference) and the library card (toggling the
 * record id). Both speak through this object so the toggle is one
 * implementation: a reference IS a record id (one identity, one string),
 * so isOn() compares verbatim, and toggle() flips off when the reference
 * already outlines, on — replacing any other outline — when it does not.
 * Stale references (unresolved wearings) toggle by the same rule.
 *
 * @param {?string} outlined
 *   The held reference (Inspector state), or null.
 * @param {Function} setOutlined
 *   The state setter.
 *
 * @return {{reference: ?string, isOn: Function, toggle: Function}}
 */
export const outlineGesture = (outlined, setOutlined) => ({
  reference: outlined,
  isOn: (reference) =>
    outlined !== null && reference != null && outlined === reference,
  toggle: (reference) =>
    setOutlined(outlined !== null && outlined === reference ? null : reference),
});

/**
 * A wearing row's label: the name of the slot the row fills.
 *
 * A row is one axis's wearing, so it is labeled by its axis, except that
 * an OWNED reference is labeled by its owner:
 * a stencil's curated look sits on the style axis like any generic
 * container style, and what the wearer should read is "Card", not
 * "Style". Ownership stays the offer filter and the axis stays the
 * competition rule; only the name changes, exactly as the picker heads
 * the owner's group (variant-picker-model.js pickerGroups). Both
 * ownership lanes read alike (library-model.js stencilLabel: the roster
 * names a content stencil and a file-shipped one by one machine name,
 * the id standing in for an owner the roster lacks). A fragment wearing
 * of an owned composition reads its owner too: the reference is the same
 * record. When the scope offers ONE axis and this is it, the row says
 * "Variant" (the view's word: no Drupal.t here, so node tests import the
 * rule directly); an unresolved record (a stale row never reaches this)
 * falls back to the axis.
 *
 * @param {string} axis
 *   The row's axis.
 * @param {?object} worn
 *   The resolved variant record, or null.
 * @param {object} context
 * @param {?object} context.guide
 *   The style guide (axis labels).
 * @param {string[]} context.vocabulary
 *   The scope's axis vocabulary (axisVocabulary()).
 * @param {object} context.materials
 *   The materials roster (the owners' stencil records).
 * @param {string} context.singleAxisLabel
 *   The word for the one-axis case (the view's translated "Variant").
 *
 * @return {string}
 */
export const wearRowLabel = (
  axis,
  worn,
  { guide = null, vocabulary = [], materials, singleAxisLabel },
) => {
  const owner = worn?.owned_by ?? null;
  if (owner !== null) {
    return stencilLabel(owner, materials);
  }
  return vocabulary.length === 1 && axis === vocabulary[0]
    ? singleAxisLabel
    : axisLabel(guide, axis);
};

/**
 * The wearing row menu's headline: the variant's identity facts.
 *
 * The menu is where a worn variant explains itself past its label: the
 * axis it competes on, its owner when a stencil owns it ("owned by Card":
 * the row itself reads the owner's name, wearRowLabel, so the header is
 * where the axis and the owner are both said), its roster weight (the
 * order every list keeps), and its description (the when-to-reach-for-this
 * text the AI reads from the same record). Null for a stale row (no
 * record to speak for).
 *
 * @param {?object} worn
 *   The resolved variant record, or null.
 * @param {?object} guide
 *   The style guide (axis labels).
 * @param {object} materials
 *   The materials roster (the owners' stencil records).
 *
 * @return {?string}
 */
export const wearRowHeader = (worn, guide, materials) => {
  if (!worn) {
    return null;
  }
  const facts = [
    worn.label ?? worn.id,
    axisLabel(guide, worn.group ?? 'style'),
  ];
  if (worn.owned_by != null) {
    facts.push(`owned by ${stencilLabel(worn.owned_by, materials)}`);
  }
  if (typeof worn.weight === 'number' && worn.weight !== 0) {
    facts.push(`weight ${worn.weight}`);
  }
  const head = facts.join(' · ');
  return typeof worn.description === 'string' && worn.description !== ''
    ? `${head}: ${worn.description}`
    : head;
};

/**
 * The right-click menu roster for one wearing row, as data.
 *
 * The view maps types to labels and handlers (line-menu.js's contract).
 * Grouped per the agreed shape: view gestures (Outline Variant always —
 * canvas coverage in the focus treatment; Variant tints only in the code
 * view — it acts on the islands, meaningless in the widget modes, and is
 * the one GLOBAL toggle here, the line-tints preference every row states
 * identically), Re-apply for a root wearing of a composition, the
 * wearing pair (Detach; Remove — remove_variant finally exposed; Reset
 * overrides is the row's own action, overriddenWearing, not a menu
 * item), then the variant-object gestures (Update from this element when the
 * wearer's own values differ from the variant — the pre-staged entry
 * into an editing session on this document; the edit DOORWAY into the
 * editing session on this layer, for EVERY resolved wearing —
 * the session is the one place a variant is edited, single-slot
 * variants included; Variant settings… when a library surface is wired —
 * the fork ("Copy as my variant") lives on the library card it opens).
 * A STALE reference (the library cannot resolve it, gone or withheld
 * alike: staleReason) keeps only the resolution-free gestures: Outline,
 * Detach, and Remove — no document to jump to.
 *
 * @param {object} context
 * @param {boolean} context.stale
 * @param {?object} context.worn
 *   The resolved variant record (null for stale rows).
 * @param {{variant: string, fragment: string}} context.ref
 *   The worn reference.
 * @param {boolean} context.codeView
 *   Whether the rows render in the code view.
 * @param {boolean} context.outlined
 *   Whether this reference's coverage outline is on (the checkmark).
 * @param {boolean} context.tints
 *   Whether variant line tints are on (the global preference's
 *   checkmark; line-tints.js).
 * @param {boolean} context.canUpdate
 *   The update-variants grant (the pre-staged diff pushes site-wide).
 * @param {boolean} context.updatable
 *   Whether an update doorway is wired AND the wearer's own values
 *   actually differ from the variant (updateFromElementLines() has work;
 *   a locked variant, its record's `updatable` false, never is).
 * @param {boolean} context.canEditFull
 *   Whether the editing-session doorway is wired.
 * @param {boolean} context.hasSettings
 *   Whether a Variant-settings doorway is wired (the library card).
 *
 * @return {Array<{type: string, checked?: boolean} |
 *   {separator: true}>}
 */
export const wearRowMenu = ({
  stale,
  worn,
  ref,
  codeView,
  outlined,
  tints = false,
  canUpdate = false,
  updatable = false,
  canEditFull,
  hasSettings,
}) => {
  const items = [{ type: 'outline', checked: Boolean(outlined) }];
  if (stale) {
    items.push({ separator: true }, { type: 'detach' }, { type: 'remove' });
    return items;
  }
  if (codeView) {
    items.push({ type: 'tints', checked: Boolean(tints) });
  }
  if (ref.fragment === '' && reachesBeyond(worn?.value, '')) {
    items.push({ type: 'reapply' });
  }
  items.push({ separator: true }, { type: 'detach' }, { type: 'remove' });
  const lifecycle = [];
  if (canUpdate && updatable) {
    lifecycle.push({ type: 'update' });
  }
  if (canEditFull) {
    lifecycle.push({ type: 'edit-full' });
  }
  if (hasSettings) {
    lifecycle.push({ type: 'settings' });
  }
  if (lifecycle.length) {
    items.push({ separator: true }, ...lifecycle);
  }
  return items;
};
