import {
  parseKey,
  declarationForKey,
  bundleForKey,
  isInstancePartKey,
  findElement,
  propertyGranted,
  resolvedConstraintsFor,
  definitionFor,
} from './style-guide.js';
import { PART_SELECTOR } from './parts.js';
import {
  canonicalSelector,
  splitKey,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import {
  addressDeclaration,
  addressKey,
  boxLabel,
  isAddress,
  ownerName,
  withheldReason,
} from './write-address.js';
import { axisVocabulary } from './variant-scope.js';
import {
  TIER_ELEMENT,
  TIER_VARIANT,
  buildStack,
  stackAt,
} from './style-stack.js';
import { createMatcher } from './selector-matcher.js';

/**
 * Basic-mode controls: the JS half of the control contract.
 *
 * A control is a curated binding the style guide declares on a kind, a
 * block type, a block plugin or a layout (controls: {name: {label,
 * widget, element}}): a ControlWidget plugin id plus the ORDERED LIST of
 * candidate addresses it may drive. `element` is served as a list
 * always (ClientStyleGuide normalizes the authored shorthand): each
 * entry a declared part (a block type's image frame, the text block's
 * link, the entity kind's layout root) or '' for the element ROOT, so
 * a root binding arrives as ['']. Instance namespaces (the spine's
 * `instances:` lane) declare controls too: root-bound only (['']),
 * projected onto each instance-part key (the selected paragraph) rather
 * than onto an owner. The PHP side vouches for every binding at guide
 * build (the widget exists, every candidate is '' or a part the
 * declaration's own elements tree declares, every candidate's property
 * groups cover the widget's owned properties); this module owns the
 * client half: the component registry keyed by plugin id, the address
 * rule that picks ONE candidate per owned property for the rows to read
 * (resolveCandidates) and ONE candidate for a gesture's set values to
 * write to as a group (resolveGroup), and the projection of bindings +
 * style map into render-ready models.
 *
 * Pure and preact-free on purpose: the projection runs under node in the
 * self-asserting test suite (tests/js/controls.test.mjs). Components live
 * in the registry as opaque values; basic-controls.js renders them.
 */

/**
 * Builds a control-widget component registry.
 *
 * The registry maps ControlWidget plugin ids ('position') to the
 * preact components that render them. Registration is last-write-wins so a
 * site bundle can override a stock widget's UI without a new plugin id.
 */
export const createControlWidgetRegistry = () => {
  const entries = new Map();
  return {
    /**
     * Registers the component for a widget plugin id.
     *
     * options.applicable is the widget's dynamic gate: the selection facts
     * as (self, parent, model) — self and parent each possibly null when
     * unknown; self-facts gates degrade to offering, while a
     * parent-context gate (position) may honestly require the parent to
     * be KNOWN — → whether the widget makes sense on the current canvas
     * selection (text_alignment on an element with text). `model` is the
     * control model being offered, for a gate that must know WHICH
     * element the control binds (the gap widget reads the bound part's
     * child count off self.partChildCount, the selection's own off
     * self.childCount when root-bound). Consulted only when the control
     * has nothing set (see offered()); omitted means always applicable.
     */
    register: (id, component, { applicable = null } = {}) => {
      entries.set(id, { component, applicable });
    },

    /**
     * The registered component, or null — a declared binding whose widget
     * has no JS component cannot render (it does NOT fall back to raw
     * rows; the curated-surface promise).
     */
    get: (id) => entries.get(id)?.component ?? null,

    has: (id) => entries.has(id),

    /**
     * The offer rule for a control model: guide-sanctioned (the model
     * exists at all) AND canvas-applicable, OR a value already set. The
     * value-set half lives HERE, not in the gates, so no widget can hide a
     * set value: any owned property a MAP layer (the element's own or a
     * worn variant's) delivers at the pose (styledAt), or styledElsewhere
     * (styling at OTHER compounds, or an unresolvable variant: the rows'
     * marks tell that story, and a hidden control would hide it) always
     * offers. A tier (skin or declared) delivering alone is a default,
     * not a set value, so it never forces a gated control open. Gated
     * off means the pipeline HIDES the control, never disables it.
     *
     * One rule outranks all of that: the part-presence gate. A control
     * is hidden absolutely when EVERY candidate of its binding is a part
     * the layout facts say is ABSENT from the canvas (the projection
     * stamps each candidate's `present` from self.parts[selector]; a
     * root candidate is always present, so a binding listing the root
     * can never hide this way): a binding on the unlinked text block's
     * anchor alone, the empty image block's picture; vector media is
     * NOT an absence: the part matches its svg form there, so its
     * controls stay offered. No value-set or styledElsewhere override: those
     * overrides belong to widget APPLICABILITY gates, heuristics about a
     * PRESENT element that a set value disproves, while presence is an
     * existence question a set value cannot disprove — the control's
     * every target is off the page, so the control projects nothing
     * (orphaned part styles stay reachable in the code view's
     * store-projection rows). Unknown presence (null context, no parts
     * fact for the selector — a satellite pre-broadcast) degrades to
     * offering, matching the gates' own unknown-context posture; only a
     * positive absence fact on every candidate hides.
     */
    offered: (id, model, layout) => {
      if (
        Array.isArray(model.candidates) &&
        model.candidates.every((candidate) => candidate.present === false)
      ) {
        return false;
      }
      const applicable = entries.get(id)?.applicable;
      if (!applicable || styledAt(model.reads) || model.styledElsewhere) {
        return true;
      }
      return applicable(layout?.self ?? null, layout?.parent ?? null, model);
    },
  };
};

/**
 * The per-window singleton registry the inspector renders from.
 *
 * Stock widgets register here at bundle load; alter-style extensions can
 * import and register before the panel mounts.
 */
export const controlWidgets = createControlWidgetRegistry();

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * An ELEMENT key's part-presence rule, over the selection context.
 *
 * `context.self.parts` is the mirror's per-declared-part presence map
 * for the selected element: only a positive absence is false, a missing
 * fact is unknown (null), and the root is always on the page.
 *
 * @param {?{self: ?{parts: Object<string, boolean>}}} context
 *
 * @return {Function}
 *   (element) → true | false | null.
 */
const contextPresence = (context) => {
  const parts = isPlainObject(context?.self?.parts) ? context.self.parts : {};
  return (element) =>
    element === ''
      ? true
      : parts[element] === false
        ? false
        : parts[element] === true
          ? true
          : null;
};

/**
 * A RULE key's part-presence rule, over the matcher.
 *
 * The elements a rule matches on the tree are the set its part
 * candidates paint on (`container:X::> pb-text .text-block-link` lands
 * where a matched text block renders the anchor), so a part is present
 * when ANY matched element carries it in its facts (selector-matcher.js
 * facts().parts: the annotation's declared parts, the hoisted ones and
 * the tagless ones included), absent when the set is known and none
 * does, and unknown (null) without a tree or when the tree does not
 * carry the owner (a satellite before its broadcast). The root is always
 * on the page.
 *
 * @param {?Array} tree
 *   The blueprint's lean node tree, or null when unknown.
 * @param {string} key
 *   The rule key (`{owner}::{selector}`).
 * @param {string} selector
 *   The rule's canonical selector.
 *
 * @return {Function}
 *   (element) → true | false | null.
 */
const rulePresence = (tree, key, selector) => {
  const unknown = (element) => (element === '' ? true : null);
  const owner = splitKey(key)?.root ?? null;
  if (!Array.isArray(tree) || owner === null) {
    return unknown;
  }
  const matcher = createMatcher(tree);
  if (!matcher.has(owner)) {
    return unknown;
  }
  const matched = matcher
    .matching(owner, selector)
    .map((match) => matcher.facts(match));
  return (element) =>
    element === ''
      ? true
      : matched.some((facts) => Object.hasOwn(facts.parts, element));
};

/**
 * Whether a stack read's winner is a MAP layer: the element's own entry
 * or a worn variant, the layers an author put there. A skin or declared
 * winner is a default beneath the map.
 */
const mapWinner = (read) =>
  read?.winner != null &&
  (read.winner.tier === TIER_ELEMENT || read.winner.tier === TIER_VARIANT);

/**
 * Whether any of a model's reads has a map-layer winner at the pose: the
 * registry's value-set test (offered), and any widget's "is anything of
 * mine set" question.
 *
 * @param {Object<string, {winner: ?object}>} reads
 *   A control model's `reads`.
 *
 * @return {boolean}
 */
export const styledAt = (reads) =>
  Object.values(reads ?? {}).some((read) => mapWinner(read));

/**
 * The values a widget shows: each owned property's WINNER at the pose,
 * whichever layer delivers it (the display rule: the shown value is
 * always the winner's, lit), keyed by property; a property no layer
 * delivers is absent. The base every widget gesture layers its draft
 * over (use-gesture.js), and the value each ChoiceRow lights.
 *
 * @param {Object<string, {winner: ?object}>} reads
 *   A control model's `reads`.
 *
 * @return {Object<string, string>}
 */
export const litValues = (reads) =>
  Object.fromEntries(
    Object.entries(reads ?? {})
      .filter(([, read]) => read?.winner != null)
      .map(([property, read]) => [property, read.winner.value]),
  );

/**
 * Whether the editable layer delivers a property at the EXACT pose: the
 * Unset verb's test (an unpick, an emptied field, the menu's doorway
 * remove what is written at the posed compound; when nothing is, there
 * is nothing to unset and a null write is refused rather than sent).
 *
 * @param {?{own: ?object}} read
 *   A stack read (stackAt).
 * @param {string} condition
 *   The editing compound (the pose).
 *
 * @return {boolean}
 */
export const ownAtPose = (read, condition) =>
  read?.own != null && read.own.condition === condition;

/**
 * The three steps of the address rule over a property set: each present
 * candidate's posed reads of the set, and the pick among them.
 *
 * The pick, in this order:
 * 1. the first candidate whose stack delivers ANY of the set from an
 *    AUTHORED layer (its own entry or a worn variant, the map layers);
 * 2. else the first candidate whose stack delivers any of the set at
 *    ANY tier (the skin, a declaration);
 * 3. else the first present candidate.
 * Authored outranks tier before list order counts because that is the
 * printer's cascade: a root Text color co-targets into the link at zero
 * weight, above the link's skin default and beneath the link's own or
 * worn values, so reading the root's authored value over the anchor's
 * skin value shows exactly what paints. Within a level, list order is
 * the declaration's preference (the link before the root: a background
 * on a linked block is a pill unless the band was authored).
 * resolveCandidates asks per property (a set of one: the row's read),
 * resolveGroup for a gesture's whole set (the write).
 */
const addressReadings = (present, properties, condition, conditions) =>
  present.map((candidate) => ({
    candidate,
    reads: properties.map((property) =>
      stackAt(candidate.stack, property, condition, conditions),
    ),
  }));
const pickAddress = (readings) =>
  readings.find(({ reads }) => reads.some(mapWinner)) ??
  readings.find(({ reads }) => reads.some((read) => read.winner !== null)) ??
  readings[0];

/**
 * The one address rule, per property: which of a binding's candidate
 * boxes each owned property READS from.
 *
 * A binding names an ORDERED list of candidate addresses (the text
 * block's Background: the link first, the root second) and renders one
 * row per owned property, never a second row for another box. This is
 * the rule that picks the box, and every consumer (the projection, the
 * presence chrome, the pipeline's routing of a removal) reads it from
 * here; no widget learns about parts. The three steps above, asked for
 * each property alone. The row writes where it reads: `keys[property]`
 * is the resolved candidate's style-map key, `stacks[property]` its
 * stack (the per-side and per-part readers take their layers from here)
 * and `reads[property]` its posed read (stackAt at the editing
 * compound, the same shape every widget already lights from, plus
 * `alsoOn`). A gesture's SET values do not write here property by
 * property: they land as a group (resolveGroup); only a null member (a
 * removal, which can only address the box the value lives on) routes
 * to `keys[property]`.
 *
 * `alsoOn` on a read is the honesty fact of the address rule: `{label,
 * value}` when the property carries an AUTHORED value on a candidate
 * other than the one it resolved to (the Button's link background over
 * a root band: the row shows the link's, `alsoOn` names the root's), so
 * the row's menu can say "Also set on element" and no painting value
 * is hidden silently; null otherwise. It rides the read because the
 * question is asked per property, at the row (provenance-model.js
 * originOf carries it into the origin, rowMenu prints it beside "Also
 * set at"). The label is the candidate's box label
 * (`candidate.label`, write-address.js boxLabel: the declared part
 * label, "element" for the root). A tier value on another candidate
 * never counts: the resolved authored value beats it in the cascade
 * too, so nothing is hidden.
 *
 * Presence: a candidate whose `present` is exactly `false` (the layout
 * facts say the part is absent from the canvas: the unlinked text
 * block's anchor) takes no part at all, neither read nor flagged, since
 * an absent part's entry paints nothing and the honesty rule covers
 * painting values (its orphan styles stay reachable in the code view).
 * Unknown presence (null, a satellite pre-broadcast) counts as present,
 * the registry's own posture. With no present candidate there is
 * nothing to address: every map is empty and the caller hides the
 * binding (offered).
 *
 * @param {Array<{element: string, key: string, label: string,
 *   present: ?boolean, stack: object}>} candidates
 *   The binding's candidates in declaration order: the part selector
 *   ('' for the root), the full style-map key, the box's label, the
 *   part's presence fact and the key's stack (buildStack).
 * @param {string[]} owned
 *   The widget's owned properties.
 * @param {string} condition
 *   The editing compound the reads are taken at ('default' unposed).
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for the cascade.
 *
 * @return {{keys: Object<string, string>, stacks: Object<string, object>,
 *   reads: Object<string, object>}}
 *   Per owned property the resolved key, stack and read (`{winner,
 *   beneath, own, alsoOn}`).
 */
export const resolveCandidates = (
  candidates,
  owned,
  condition,
  conditions = {},
) => {
  const present = candidates.filter(
    (candidate) => candidate.present !== false,
  );
  const keys = {};
  const stacks = {};
  const reads = {};
  if (present.length === 0) {
    return { keys, stacks, reads };
  }
  owned.forEach((property) => {
    const readings = addressReadings(
      present,
      [property],
      condition,
      conditions,
    );
    const resolved = pickAddress(readings);
    // Another candidate's authored value can only exist behind an
    // authored winner: had the resolved read been a tier or nothing,
    // level 1 would have picked the authored candidate instead.
    const other =
      readings.find(
        (reading) => reading !== resolved && mapWinner(reading.reads[0]),
      ) ?? null;
    keys[property] = resolved.candidate.key;
    stacks[property] = resolved.candidate.stack;
    reads[property] = {
      ...resolved.reads[0],
      alsoOn:
        other === null
          ? null
          : { label: other.candidate.label, value: other.reads[0].winner.value },
    };
  });
  return { keys, stacks, reads };
};

/**
 * The one address rule, for a gesture: the ONE box a gesture's set
 * values land on, as a group.
 *
 * Every multi-property gesture a basic widget commits is a recipe whose
 * values only mean anything together on one box (the gradient-text
 * trio: a clip needs the gradient behind its own glyphs; a media image
 * and the size, repeat and position seeded with it; a stroke's width,
 * style and color; a padding shorthand with its collapsed sides; the
 * motion trigger pair; the margin auto pair), and no gesture spans
 * boxes by intent (a typography row commits alone). Routing the members
 * one by one (`keys[property]`) would split a recipe across the boxes
 * its members happen to read from (the trio's color to the root that
 * authored a color, its gradient to the anchor that authored nothing),
 * so the pipeline asks here instead: the three steps above over the
 * whole set, and the answer is the key the WHOLE set writes to, the
 * resolved candidate's write key (`write`: its own key, or its rebased
 * key under the panel's write address). Null members are not part of
 * the set: a removal can only address the box the value lives on, and
 * routes to `keys[property]`.
 *
 * Only candidates that are present (the same presence rule as
 * resolveCandidates) and within the write address's reach (`write` not
 * null) take part. No such candidate, or an empty set, resolves null,
 * and the caller falls back to the binding's home.
 *
 * @param {Array<{key: string, write: ?string, present: ?boolean,
 *   stack: object}>} candidates
 *   The binding's candidates in declaration order (controlModels'
 *   `candidates`).
 * @param {string[]} properties
 *   The gesture's SET members (the properties it writes a value for).
 * @param {string} condition
 *   The editing compound the reads are taken at ('default' unposed).
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for the cascade.
 *
 * @return {?string}
 *   The write key the set lands on, or null when nothing can be
 *   addressed.
 */
export const resolveGroup = (
  candidates,
  properties,
  condition,
  conditions = {},
) => {
  const writable = candidates.filter(
    (candidate) => candidate.present !== false && candidate.write !== null,
  );
  if (writable.length === 0 || properties.length === 0) {
    return null;
  }
  return pickAddress(addressReadings(writable, properties, condition, conditions))
    .candidate.write;
};

/**
 * Builds the update_style operations for a multi-key widget gesture.
 *
 * A gesture spans keys when a removal riding beside its set values
 * lives on another box (write where you read: a gradient pick on a
 * linked block whose root wears an authored band clears the root's
 * background-color and places background-image on the anchor, the box
 * the set resolved to as a group) or when a section writes several
 * elements at once (the Layout section's takeover and column resize).
 * Every part must land as ONE batch, one undo label, all or nothing
 * (daedalus_blueprint's mutate() applies a batch atomically). Each change
 * is {key, condition?, properties}; the 'default' condition is omitted
 * from the wire op, mirroring the single-op commit path in
 * leader-source.js.
 *
 * @param {Array<{key: string, condition?: string, properties: object}>} changes
 *   The gesture's changes, the widget's own keys first.
 *
 * @return {Array<object>}
 *   update_style operations for commitBatch({operations, label}).
 */
export const compoundOperations = (changes) =>
  changes.map(({ key, condition = 'default', properties }) => {
    const operation = { op: 'update_style', key, properties };
    if (condition !== 'default') {
      operation.condition = condition;
    }
    return operation;
  });

/**
 * Projects an element's declared controls into render-ready models.
 *
 * Resolution follows the server's validation view: the effective
 * declaration for the key (block type with the universal block kind
 * layered underneath — declarationForKey), its controls: map in
 * declaration order. Each model joins the three data sources a control
 * needs: the binding (label, the candidate list), the widget definition
 * from the guide payload's controlWidgets table (owned properties; the
 * widget's label as the fallback), and each candidate's STYLE STACK
 * (the one client model of what styles a key and from where,
 * style-stack.js), then hands the candidates to the one address rule
 * (resolveCandidates) so every owned property means exactly one box.
 * Address knowledge lives here and in the pipeline's write routing
 * (basic-controls.js) only: no widget learns about parts.
 *
 * Value semantics (the write-path contract):
 * - `candidates` is the binding's address list in declaration order
 *   (the drilled shell part forced first, below), each `{element, key,
 *   write, present, stack}`: the part selector ('' for the root), the
 *   full style-map key (`${key}::${element}`, the plain selectable key
 *   for the root), the key a write to the box lands at (the key itself;
 *   under a write address the rebased key, or null beyond the
 *   address's reach), the part's presence fact (true, false, or null
 *   when the layout context does not know) and the key's layer stack
 *   (buildStack: the declared and skin tiers, the skin's base:{tag}
 *   block keyed by the candidate's tag from `tree` (the root's rendered
 *   tag, a part's declared tag), each of the ROOT's worn references at the candidate's
 *   relative position, the candidate's own entry). A part candidate's
 *   stack reads the part's layers and never the root's. Basic controls
 *   never render inside a variant-editing session, so no stack carries
 *   a session overlay.
 * - `home` is the first PRESENT candidate (`{element, key, stack}`),
 *   the binding's box for what has geometry and is not a write: the
 *   diagram probe (a rule has no geometry; the computed placeholders
 *   are the element's), the gesture identity, the gap gate's child
 *   count. Chrome that follows a write (the canvas highlight, the
 *   motion replay) addresses `keys[property]`, the write key. Null when
 *   no candidate is present, in which case the registry hides the
 *   binding (offered).
 * - `keys`, `stacks` and `reads` are per owned property, the address
 *   rule's answer (resolveCandidates): the candidate the property reads
 *   from and writes to, its stack (the per-side and per-part readers
 *   take their layers from `stacks[property]`: box-model.js
 *   sideProvenance, stroke-model.js strokeSides) and its posed read
 *   (stackAt at the EDITING COMPOUND, the condition the canvas pose
 *   derives, 'default' when unposed): `{winner, beneath, own}`. The
 *   display rule every widget follows: the shown value is the winner's,
 *   lit, whichever tier delivers it (a worn variant's, the skin's, a
 *   declaration's); the mark wears the winner's hue and its origin story
 *   (choice-row-model.js originFor over the read); the Unset verb
 *   exists only while `own` delivers at the exact pose (ownAtPose); a
 *   null winner is honestly unset. litValues(reads) projects the winners
 *   into the values map a widget's gesture layers its draft over. Write
 *   where you read, for the gesture: the pipeline routes a gesture's
 *   set values as a group to the one box the address rule answers for
 *   the set (resolveGroup over `candidates`) and each null member to
 *   `keys[property]` (a removal addresses the box the value lives on),
 *   so the one write rule stands (basic reads and writes exactly where
 *   advanced does, at the posed compound), a recipe never splits across
 *   boxes, and a write can never land on a box whose direct rule on
 *   another box hides it.
 * - `landing` is the group form of the address rule ON the model: the
 *   key a gesture's SET values land on as a group (resolveGroup over
 *   `candidates`, the home key when nothing is addressable). The
 *   pipeline routes every gesture's set through it, and a widget whose
 *   patch must read the landing box's OWN layer (the stroke patches'
 *   expansion layer: which spelling that layer carries) asks here, per
 *   write, since the landing follows the set (a width alone lands where
 *   the width reads; a whole stroke where any of its parts is
 *   authored, which can be another box than `keys['border-width']`).
 *   Removals alone have no group (each lands on `keys[property]`) and
 *   no caller asks for one.
 * - `constraints` carries, per owned property that has any, the
 *   RESOLVED candidate's guide constraints ({allowed, min, max, units,
 *   scale, stops}): its own node block (the elements entry for a part,
 *   the effective declaration for the root), else the site-wide
 *   constraint default (resolvedConstraintsFor — the offer vocabulary's
 *   lookup layer, so a site-level binding like font-size -> the type
 *   scale shapes stop rows everywhere without per-node authoring). This
 *   is the vocabulary channel: a guide's `scale:` tokens or `allowed:`
 *   list is the widget's stop vocabulary (widget-values.js stopsFor),
 *   built-in stops the no-binding fallback. Client-side input shaping
 *   only; the server validates every commit regardless.
 * - `granted` carries the subset of the widget's owned properties the
 *   resolved candidates actually grant (each property judged against
 *   the node it resolved to): the served node's grants (the roster's
 *   answer, a narrowed grant already resolved to names, propertyGranted)
 *   exclude the rest, and widgets curate their rows to this in every
 *   enforcement mode (offer-shaping, like constraints; strict makes it
 *   law server-side). Equal to the owned list when the targets grant the
 *   widget's whole family, and the same at every placement depth: a
 *   grant is a function of the address alone (placement curates offers
 *   and birth through the axis vocabulary, never legality).
 * - `propertyDefinitions` carries the guide's property definition for each
 *   owned property the group table defines ({type, negative, number,
 *   keywords}, any subset — definitionFor), the same type facts advanced
 *   rows get: a widget's dimension editor (the padding row's shorthand
 *   DimensionField, the box-model diagram its pencil reveals, the
 *   stroke row's width field) reads them for clamping and unit
 *   vocabulary. Input shaping only, like constraints; presence signals
 *   a definition exists.
 * - `condition` echoes the editing compound the reads were taken at, so
 *   layer-reading widgets (the box-model diagram and the stroke rows
 *   read `stacks` per side: box-model.js sideProvenance) address the
 *   same compound the pipeline writes.
 * - `styledElsewhere` flags styling the current pose does not show, on
 *   ANY present candidate: an owned property a MAP layer sets under a
 *   stored condition OTHER than the editing compound ('default' included
 *   when posed elsewhere: the compound of zero parts is just another
 *   compound), so a variant's conditions flag exactly like an element's
 *   own; or a variant reference the library cannot resolve (its styling
 *   exists but is unshowable: an unresolved reference layer). Its one
 *   job is the registry's offer rule (offered): a control with only
 *   elsewhere-posed styling stays offered instead of being gated off.
 *   What the pose hides is told per row, never per section: each row's
 *   mark and menu carry the origin (provenance-model.js originOf: the
 *   hollow mark, "Set at Hover, not here", the "Also set at" roster), and
 *   the address rule's second authored value on another box rides the
 *   read as `alsoOn` (resolveCandidates) into the menu's "Also set on".
 * - `section` is the basic-mode section the control files under: the
 *   binding's own `section:` override, else the widget definition's
 *   (the ControlWidget attribute's default), else null for a widget the
 *   payload does not define. The pipeline groups models by it in the
 *   guide's controlSections roster order (groupControls).
 * - `withheld` carries, per GRANTED property the write address cannot
 *   take, the roster's reason (write-address.js withheldReason): empty
 *   with no address. A widget renders such a property DISABLED with the
 *   reason as its title (the choice row's and segments' posture; no
 *   prose inside widgets), never hidden: hidden stays what the own key
 *   never grants.
 *
 * Under a WRITE ADDRESS (`writeAddress`, an ancestor rule `{key,
 * selector}` from the element's address set, write-address.js) the
 * rows stay the selected element's: every candidate's stack is still
 * built at the element's own key, with the address's ancestor layer as
 * the editable one (style-stack.js `editable`: the rule's existing
 * layer, or a virtual empty one at its delivery position), so the reads
 * do not move and only `own` (the Unset verb) answers for the address.
 * What moves is WHERE a property writes: every candidate REBASES to the
 * address, the facet's own candidate (the root, or the drilled part) to
 * the address itself, another candidate to the address continued by
 * descent (`container:Y::> pb-text .text-block-link`) when the grammar
 * admits it and the matcher confirms the rebased rule reaches that box
 * on the selected element; a candidate that cannot rebase is out of
 * the address's reach and takes no part in the write routing. The
 * address rule then picks among the rebased candidates by the same
 * reads, `keys[property]` is the resolved candidate's REBASED key, and
 * grants and constraints for it are judged at that key's landing
 * declaration (declarationForKey + propertyGranted /
 * resolvedConstraintsFor), not by the element against the panel key.
 * `granted` keeps the own key's answer (what the own key never grants
 * stays hidden); a granted property the address withholds (no candidate
 * of the binding within reach, or ungranted at the landing) is reported
 * in `withheld`. An address naming the facet's own key is the default
 * (null): today's model byte for byte.
 *
 * A targeted SHELL part (`targetSelector` is an owner's declared part
 * selector while `key` is the owner: the inspector's basicTarget keeps
 * the owner's panel for a shell target) filters and reorders: a binding whose
 * candidates contain the part renders with that part forced FIRST (the
 * drill is the one act that names a box explicitly, so the rows mean it
 * wherever it is present), and a binding whose candidates do not contain
 * it is dropped. None left, and the panel states it has no style
 * options. An instance drill never reaches this (its key IS the part;
 * `targetSelector` is ignored there).
 *
 * A control whose value is exactly `false` is the tombstone (a later
 * layer suppressing an inherited control) and is dropped silently:
 * suppressed, not malformed. Block-type tombstones arrive pre-resolved
 * (ClientStyleGuide ships effective declarations with the false kept in),
 * while kind declarations may carry raw `false` directly; the same
 * plain-object filter covers both.
 *
 * A SELECTOR key models the controls of the declaration the roster walk
 * lands on (declarationForKey). An INSTANCE-part key (a sub key
 * resolving through the guide's site instance namespaces —
 * isInstancePartKey(), the one resolution rule as a routing question)
 * models the covering namespace's controls, each bound to the part key
 * ITSELF: an instance is content the user selected, so the curated
 * widgets style the selected paragraph, never its owner. Instance
 * controls are root-bound by definition (the server rejects a part
 * candidate on them — a namespace's instances are content and declare
 * no parts of their own), so a binding naming one is malformed here and
 * drops. A RULE key (`container:X::> pb-text`, a chain facet seated
 * from a code-mode row header) models the landing type's controls over
 * the container's rule, one thing at a time: a root candidate is the
 * rule key itself, a part candidate continues the chain by descent
 * (`container:X::> pb-text .text-block-link`), and a part's presence
 * is the matcher's answer over the rule's matched elements (present
 * when any of them carries the part in its facts, absent when the set
 * is known and none does, unknown without a tree), never the selection
 * context's. Shell sub keys (an owner's exact or pattern part)
 * land on element nodes, which declare no controls, so they model
 * nothing of their own — a shell part's curated surface is its owner's
 * lift (bindings on the owner's panel that list the part); unparseable
 * keys model nothing. Malformed
 * bindings (guide-build validation makes them unreachable from YAML,
 * but the payload is still input) are dropped rather than rendered
 * broken: `element` must be the served list, non-empty, every entry ''
 * or a part selector; a scalar or absent `element` is malformed here,
 * since the server serves lists only. Each selector is re-checked
 * against PART_SELECTOR because it becomes part of a style-map key —
 * same injection-surface rule as parts.js.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?string} key
 *   The panel's target key: the selected element's selectable key, or a
 *   drilled instance-part key ('block:{uuid}::.s-a3').
 * @param {object} blockTypes
 *   Component uuid → inline block type (source.getBlockTypes()).
 * @param {object} styleMap
 *   The root entity's style map (source.getStyleMap()).
 * @param {object} sectionLayouts
 *   Section uuid → layout plugin id (source.getSectionLayouts()).
 * @param {?Array<object>} variants
 *   The variant records (materials.ofKind('variant')) the stack reads
 *   references through.
 * @param {Array} layout
 *   The structural layout tree (source.getLayout()), the depth fact the
 *   axis vocabulary reads: nested sections drop root-only axes. Empty
 *   degrades to the ungated root posture.
 * @param {string} condition
 *   The editing compound the canvas pose derives (vectorCondition);
 *   'default' when unposed. The reads are taken here, and the
 *   pipeline's write handlers target it.
 * @param {?{self: ?{parts: Object<string, boolean>}}} [context]
 *   The selection facts (source.getLayoutContext()): `self.parts` is
 *   the per-declared-part presence map an ELEMENT key's candidates'
 *   `present` reads (false = absent, true = present, missing =
 *   unknown). Null when unknown (a satellite pre-broadcast): every part
 *   counts as present. A rule key ignores it (its presence is the
 *   matcher's, over `tree`).
 * @param {?string} [targetSelector]
 *   The targeted shell part's selector while one is targeted, else null
 *   (the spine's styling target on the owner's panel; a rule key is
 *   addressed through `key` itself, never through this).
 * @param {?Array} [tree]
 *   The blueprint's lean node tree (source.getTree()): keys the
 *   candidates' ancestor tier, so a row's origin can name a container's
 *   rule reaching this element, and their skin base:{tag} layer (each
 *   candidate's tag from the tree). Null leaves both out.
 * @param {?{key: string, selector: string}} [writeAddress]
 *   The panel's write address: the styling target when it sits above
 *   the selection (an ancestor rule from the element's address set,
 *   the inspector's `address`), the writes landing at it; null, or the
 *   facet's own key, for the own key (see above).
 *
 * @return {Array<{id: string, label: string, widget: string,
 *   candidates: Array<{element: string, key: string, write: ?string,
 *   label: string, present: ?boolean, stack: object}>,
 *   home: ?{element: string, key: string, stack: object},
 *   landing: function(string[]): ?string,
 *   keys: Object<string, string>, stacks: Object<string, object>,
 *   reads: Object<string, object>, definition: ?object, condition: string,
 *   constraints: Object<string, object>,
 *   propertyDefinitions: Object<string, object>, granted: string[],
 *   withheld: Object<string, string>, styledElsewhere: boolean,
 *   section: ?string}>}
 */
export const controlModels = (
  guide,
  key,
  blockTypes = {},
  styleMap = {},
  sectionLayouts = {},
  variants = [],
  layout = [],
  condition = 'default',
  context = null,
  targetSelector = null,
  tree = null,
  writeAddress = null,
) => {
  const parsed = parseKey(key);
  if (!parsed) {
    return [];
  }
  const bundle = bundleForKey(parsed, blockTypes, sectionLayouts);
  // A selector key models the controls of the declaration the roster
  // walk LANDS on (declarationForKey): an INSTANCE part's covering
  // namespace, whose controls bind the part key itself; a rule key's
  // landing type (`> pb-text` lands on the text block type, so basic
  // mode renders the text block's own rows over the container's rule);
  // a tag's base declaration; the landing's `children` declaration node
  // (the guide's `> *` vocabulary, served by ClientStyleGuide; no panel
  // section of that name exists any more) for `> *`. A shell part key
  // (an owner's
  // exact/pattern part) lands on an element node, which declares no
  // controls, so it models nothing of its own: its curated surface is
  // the owner's lift.
  const instance =
    parsed.selector !== null && isInstancePartKey(guide, parsed, bundle);
  const declaration = declarationForKey(guide, parsed, bundle);
  const controls = declaration?.controls;
  if (!isPlainObject(controls)) {
    return [];
  }
  const definitions = guide?.controlWidgets ?? {};
  // The scope's axis vocabulary: passed into the effective-styles resolver
  // so cross-axis property overlaps resolve in DELIVERY order (axis
  // declaration order), matching the inspector and code-view lanes. Part
  // bindings share the root's vocabulary (axes are a scope fact).
  const axes = axisVocabulary(guide, key, blockTypes, sectionLayouts, layout);
  const conditions = isPlainObject(guide?.conditions) ? guide.conditions : {};
  // The shell drill: only an owner's panel (never an instance drill, whose
  // key already IS the part) filters and reorders by the drilled part.
  const drilled =
    !instance && typeof targetSelector === 'string' && targetSelector !== ''
      ? targetSelector
      : null;
  // A part's presence fact, three-valued: true when present, false only
  // on a positive absence, null when unknown; the root is always on the
  // page. An ELEMENT key reads the selection context (the mirror's
  // answer for the selected element); a RULE key reads the matcher: the
  // elements the rule matches on the tree, present when any carries the
  // part in its facts, absent when the set is known and none does,
  // unknown without a tree (a satellite before its broadcast) or when
  // the tree does not carry the owner.
  const presenceOf =
    parsed.selector !== null && !instance
      ? rulePresence(tree, key, parsed.selector)
      : contextPresence(context);
  // The candidate's declaration node: the part's entry anywhere in the
  // elements tree (parts nest: a menu's link sits under its item under
  // its list, and the server validates candidates over the same
  // depth-first find), the effective declaration itself for the root.
  // Constraints and grants resolve against it per property.
  const targetOf = (element) =>
    element === '' ? declaration : findElement(declaration, element);
  const validCandidate = (element) =>
    element === '' ? true : !instance && PART_SELECTOR.test(element);

  // The write address, when it is not the facet's own key: a rule key
  // has no address set (it IS a rule), and an address naming the panel's
  // own facet is the default. The facet's own candidate is the drilled
  // part on an owner's panel, the root otherwise (an instance drill's
  // key is the part, so its root candidate is the facet).
  const facetElement = drilled ?? '';
  const facetKey = drilled === null ? key : `${key}::${drilled}`;
  const address =
    isAddress(writeAddress) &&
    !(parsed.selector !== null && !instance) &&
    addressKey(writeAddress) !== facetKey
      ? writeAddress
      : null;
  const rootKey = splitKey(key)?.root ?? key;
  const matcher =
    address !== null && Array.isArray(tree) ? createMatcher(tree) : null;
  // A candidate's REBASED key under the address: the address itself for
  // the facet's own box, the address continued by descent for another
  // part when the grammar admits it and the rebased rule reaches that
  // part on the selected element (the matcher's answer; unknown without
  // a tree is out of reach), null when the box is beyond the address.
  const rebasedKey = (element) => {
    if (element === facetElement) {
      return addressKey(address);
    }
    if (element === '') {
      return null;
    }
    const selector = canonicalSelector(`${address.selector} ${element}`);
    if (
      selector === null ||
      matcher === null ||
      !matcher.has(address.key) ||
      !matcher.reaches(address.key, selector, rootKey, element)
    ) {
      return null;
    }
    return addressKey({ key: address.key, selector });
  };
  const addressTargets = new Map();
  const addressTargetOf = (writeKey) => {
    if (!addressTargets.has(writeKey)) {
      addressTargets.set(
        writeKey,
        addressDeclaration(
          guide,
          { key: writeKey, selector: null },
          blockTypes,
          sectionLayouts,
        ),
      );
    }
    return addressTargets.get(writeKey);
  };
  const owner = address === null ? null : ownerName(matcher, address.key);

  return Object.entries(controls)
    .filter(
      ([, control]) =>
        isPlainObject(control) &&
        typeof control.widget === 'string' &&
        control.widget !== '' &&
        Array.isArray(control.element) &&
        control.element.length > 0 &&
        control.element.every(
          (element) => typeof element === 'string' && validCandidate(element),
        ) &&
        // The drilled shell part must be one of the binding's boxes.
        (drilled === null || control.element.includes(drilled)),
    )
    .map(([name, control]) => {
      const definition = isPlainObject(definitions[control.widget])
        ? definitions[control.widget]
        : null;
      const owned = Array.isArray(definition?.properties)
        ? definition.properties
        : [];
      const elements =
        drilled === null
          ? control.element
          : [
              drilled,
              ...control.element.filter((element) => element !== drilled),
            ];
      // Each candidate's stack: floor, ancestor tier and rendered tag
      // included; the address rule takes its posed reads from these. A
      // part candidate of a rule key rides the rule's selector
      // (`container:X::> pb-text .text-block-link`: the part class
      // continues the chain by descent, a selector never carries a
      // second `::`). Its write key is its own key, or the rebased key
      // under the address (null beyond the address's reach).
      const candidates = elements.map((element) => {
        const elementKey =
          element === ''
            ? key
            : parsed.selector !== null
              ? `${key} ${element}`
              : `${key}::${element}`;
        const write = address === null ? elementKey : rebasedKey(element);
        // The candidate's stack is the ELEMENT's box; under the address
        // its editable layer is the rebased rule reaching that box (a
        // box beyond the address's reach keeps its own layer: it routes
        // no write, and its rows read disabled).
        const editable =
          address === null || write === null
            ? null
            : { key: address.key, selector: splitKey(write).selector };
        return {
          element,
          key: elementKey,
          write,
          // The box's words: the declared part label, "element" for the
          // root (the address rule's "Also set on", the reach reason).
          label: boxLabel(guide, key, element, blockTypes, sectionLayouts),
          present: presenceOf(element),
          stack: buildStack({
            guide,
            map: styleMap,
            key: elementKey,
            variants,
            axes,
            blockTypes,
            sectionLayouts,
            tree,
            editable,
          }),
        };
      });
      // Under an address only the candidates within its reach route a
      // write; none within reach leaves the reads to every candidate
      // (the rows still show the element) and withholds every property.
      const reachable = candidates.filter(
        (candidate) => candidate.write !== null,
      );
      const addressable = address !== null && reachable.length > 0;
      const resolved = resolveCandidates(
        addressable ? reachable : candidates,
        owned,
        condition,
        conditions,
      );
      const { stacks, reads } = resolved;
      const candidateFor = (property) =>
        candidates.find((candidate) => candidate.key === resolved.keys[property]) ??
        candidates[0];
      // Write where you read: the resolved candidate's key, rebased to the
      // address when one is set.
      const keys = Object.fromEntries(
        Object.keys(resolved.keys).map((property) => [
          property,
          addressable ? candidateFor(property).write : resolved.keys[property],
        ]),
      );
      const first =
        candidates.find((candidate) => candidate.present !== false) ?? null;
      const home =
        first === null
          ? null
          : { element: first.element, key: first.key, stack: first.stack };
      const targetFor = (property) => targetOf(candidateFor(property).element);

      // Constraints and grants resolve against the RESOLVED candidate's
      // declaration, with the site-wide constraint defaults as the base
      // layer beneath the node's own blocks. Only constrained properties
      // get a constraints entry, so widgets read presence; granted is the
      // roster's read (a narrowed grant offers only the granted names:
      // the spacing widget's padding row renders only the granted sides
      // from this), equal to owned wherever the targets grant the
      // widget's whole family (the guide build vouches that every
      // candidate covers its widget).
      const constraints = {};
      const propertyDefinitions = {};
      const granted = [];
      const withheld = {};
      owned.forEach((property) => {
        const target = targetFor(property);
        // Under an address the property's box is the rebased key's
        // landing: its constraints shape the row and its grant decides
        // whether the row may write there at all.
        const writeTarget = addressable
          ? addressTargetOf(keys[property])
          : target;
        const propertyConstraints = resolvedConstraintsFor(
          guide,
          writeTarget,
          property,
        );
        if (propertyConstraints) {
          constraints[property] = propertyConstraints;
        }
        const propertyDefinition = definitionFor(guide, property);
        if (propertyDefinition) {
          propertyDefinitions[property] = propertyDefinition;
        }
        if (!propertyGranted(target, property)) {
          return;
        }
        granted.push(property);
        if (address === null) {
          return;
        }
        if (!addressable) {
          withheld[property] = withheldReason('reach', {
            property,
            address,
            owner,
            part: candidateFor(property).label,
          });
        } else if (!propertyGranted(writeTarget, property)) {
          withheld[property] = withheldReason('grant', {
            property,
            address,
            owner,
          });
        }
      });

      // The map layers alone (each present candidate's own entry and its
      // worn references): what an author put there, as opposed to the
      // tiers beneath. The offer rule reads them; every value read is
      // over the whole stack. An unresolvable reference (the library does
      // not carry the variant, or has not arrived yet): the styling
      // exists but nothing here can show it, so the control stays
      // offered for it. Owned styling at any OTHER stored compound keeps
      // it offered too.
      const mapLayers = candidates
        .filter((candidate) => candidate.present !== false)
        .flatMap((candidate) =>
          candidate.stack.layers.filter(
            (layer) =>
              layer.tier === TIER_VARIANT || layer.tier === TIER_ELEMENT,
          ),
        );
      const styledElsewhere =
        mapLayers.some((layer) => !layer.resolved) ||
        mapLayers.some((layer) =>
          Object.entries(layer.styles).some(
            ([layerCondition, properties]) =>
              layerCondition !== condition &&
              owned.some((property) => properties[property] !== undefined),
          ),
        );

      return {
        id: name,
        label: control.label ?? definition?.label ?? control.widget,
        widget: control.widget,
        candidates,
        home,
        // The one address rule for a gesture's set, bound to this
        // binding's candidates: the pipeline and any own-layer reader
        // ask here, never resolveGroup themselves.
        landing: (properties) =>
          resolveGroup(candidates, properties, condition, conditions) ??
          home?.key ??
          null,
        keys,
        stacks,
        reads,
        definition,
        condition,
        constraints,
        propertyDefinitions,
        granted,
        withheld,
        styledElsewhere,
        section:
          typeof control.section === 'string' && control.section !== ''
            ? control.section
            : typeof definition?.section === 'string'
              ? definition.section
              : null,
      };
    });
};

/**
 * The reason a model's write address withholds any of some properties,
 * or null when it withholds none: the one read a row over several
 * properties makes (a stroke row over width, style and color; the
 * padding row over the shorthand and its sides; a well over a pair),
 * so the row disables whole with the first withheld property's reason
 * as its title rather than half-writing a gesture the address refuses
 * part of.
 *
 * @param {{withheld?: Object<string, string>}} model
 *   A control model.
 * @param {string[]} properties
 *   The properties the row writes.
 *
 * @return {?string}
 */
export const withheldReasonFor = (model, properties) => {
  const withheld = isPlainObject(model?.withheld) ? model.withheld : {};
  const property = (Array.isArray(properties) ? properties : []).find(
    (name) => typeof withheld[name] === 'string',
  );
  return property === undefined ? null : withheld[property];
};

/**
 * The basic-mode section roster the guide payload serves, in weight order.
 *
 * `guide.controlSections` is {id: {label, weight}} (ClientStyleGuide,
 * from the ControlSection enum); this returns it as an ordered list so
 * the pipeline renders headers in one order everywhere. An absent or
 * malformed roster is an empty list: nothing to file under, so every
 * control trails unheaded (groupControls).
 *
 * @return {Array<{id: string, label: string, weight: number}>}
 */
export const controlSections = (guide) => {
  const roster = guide?.controlSections;
  if (!isPlainObject(roster)) {
    return [];
  }
  return Object.entries(roster)
    .filter(([, section]) => isPlainObject(section))
    .map(([id, section]) => ({
      id,
      label: typeof section.label === 'string' ? section.label : id,
      weight: Number.isFinite(section.weight) ? section.weight : 0,
    }))
    .sort((a, b) => a.weight - b.weight);
};

/**
 * Groups control models under the section roster, in roster order.
 *
 * One group per section that has at least one model, in the roster's
 * order, each carrying its models in declaration order (the order the
 * models arrived in); a section with no models renders nothing, not an
 * empty header, unless `keep` names it: a section whose header a host
 * supplies with content of its own (the container's Layout section,
 * carrying structure beside the bound controls) renders even when no
 * control binds into it. Hosted sections LEAD: a host carries the
 * element's structure (Stack | Row | Columns, alignment, gap), and structure
 * reads first, ahead of every roster section, so a container opens on
 * its Layout section while a block, hosting nothing, keeps the plain
 * roster order (kept ids order among themselves by the roster; keep
 * never invents a group the roster lacks). Models
 * whose section the roster does not carry (null, or a name the server
 * never vouched for) trail after the roster in ONE unheaded group
 * (`label` null), in arrival order: the honest fallback for a payload
 * that lacks the roster, never a silent drop.
 *
 * @param {Array<{section: ?string}>} models
 *   Control models (controlModels), already filtered to the renderable.
 * @param {Array<{id: string, label: string}>} sections
 *   The roster (controlSections).
 * @param {Array<string>} keep
 *   Roster section ids a host supplies: they survive empty and lead.
 *
 * @return {Array<{id: string, label: ?string, models: Array<object>}>}
 */
export const groupControls = (models, sections, keep = []) => {
  const groups = sections.map((section) => ({
    id: section.id,
    label: section.label,
    models: [],
  }));
  const byId = new Map(groups.map((group) => [group.id, group]));
  const trailing = { id: null, label: null, models: [] };
  models.forEach((model) => {
    (byId.get(model.section) ?? trailing).models.push(model);
  });
  const hosted = groups.filter((group) => keep.includes(group.id));
  const rest = groups.filter(
    (group) => !keep.includes(group.id) && group.models.length > 0,
  );
  return [
    ...hosted,
    ...rest,
    ...(trailing.models.length > 0 ? [trailing] : []),
  ];
};
