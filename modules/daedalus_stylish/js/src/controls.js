import {
  parseKey,
  declarationForKey,
  bundleForKey,
  isInstancePartKey,
  propertyGranted,
  resolvedConstraintsFor,
  definitionFor,
} from './style-guide.js';
import { PART_SELECTOR } from './parts.js';
import { axisVocabulary, effectiveEntry } from './variant-scope.js';
import { provenanceFor } from './provenance-model.js';

/**
 * Basic-mode controls: the JS half of the control contract.
 *
 * A control is a curated binding the style guide declares on a kind, a
 * block type or a layout (controls: {name: {label, widget, element}}): a
 * ControlWidget plugin id plus the target it drives — a declared part
 * when element is present (a block type's image frame, the entity kind's
 * layout root), the element ROOT when it is omitted. Instance namespaces
 * (the spine's `instances:` lane) declare controls too: root-bound only,
 * projected onto each instance-part key (the selected paragraph) rather
 * than onto an owner. The PHP side vouches for every binding at guide
 * build (the widget exists, the element — when present — is a part the
 * declaration's own elements tree declares, the bound target's property
 * groups cover the widget's owned properties);
 * this module owns the client half — the component registry keyed by
 * plugin id, and the projection of bindings + style map into
 * render-ready models.
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
     * set value: any owned value at the editing compound, or hasMore
     * (styling at OTHER compounds, or an unresolvable variant — hiding
     * the control would orphan its honesty hint) always offers. Gated
     * off means the pipeline HIDES the control, never disables it.
     *
     * One rule outranks all of that: the part-presence gate. An
     * element-bound control (model.element names a declared part) is
     * hidden absolutely when the layout facts say its bound part is
     * ABSENT from the canvas (self.parts[selector] === false — the
     * unlinked text block's anchor, the empty image block's picture;
     * vector media is NOT an absence: the part matches its svg form
     * there, so its controls stay offered). No value-set or
     * hasMore override: those overrides belong to widget APPLICABILITY
     * gates, heuristics about a PRESENT element that a set value
     * disproves, while presence is an existence question a set value
     * cannot disprove — the control's target is not on the page, so the
     * control projects nothing (orphaned part styles stay reachable in
     * the code view's store-projection rows). Unknown presence (null
     * context, no parts fact for the selector — a satellite
     * pre-broadcast) degrades to offering, matching the gates' own
     * unknown-context posture; only a positive absence fact hides.
     */
    offered: (id, model, layout) => {
      if (
        typeof model.element === 'string' &&
        layout?.self?.parts?.[model.element] === false
      ) {
        return false;
      }
      const applicable = entries.get(id)?.applicable;
      if (
        !applicable ||
        Object.keys(model.values).length > 0 ||
        model.hasMore
      ) {
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
 * Builds the update_style operations for a compound widget gesture.
 *
 * The one deliberate exception to "write locally, read contextually": a
 * gesture may carry companion changes on OTHER keys (the position widget's
 * parent display: grid upgrade) that must land with its own as ONE batch,
 * one undo label, all or nothing (daedalus_blueprint's mutate() applies a
 * batch atomically). Each change is {key, condition?, properties}; the
 * 'default' condition is omitted from the wire op, mirroring the single-op
 * commit path in leader-source.js.
 *
 * @param {Array<{key: string, condition?: string, properties: object}>} changes
 *   The gesture's changes, the widget's own bound key first.
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
 * needs: the binding (label, element), the widget definition from the
 * guide payload's controlWidgets table (owned properties; the widget's
 * label as the fallback), and the bound target's CURRENT values from the
 * style map.
 *
 * Value semantics (the write-path contract):
 * - `key` is the bound target's full style-map key: `${key}::${element}`
 *   for a part binding, the plain selectable key for a root binding
 *   (element omitted) — the control's preview/commit calls target it, the
 *   same update_style path the raw rows use.
 * - `values` carries only the properties the widget owns, read at the
 *   EDITING COMPOUND (the condition the canvas pose derives; 'default'
 *   when unposed) — the one write rule: basic reads and writes exactly
 *   where advanced does, at the posed compound. The
 *   bound target's entry is read through the effective-styles resolver
 *   (effectiveEntry): an on-variant element's controls populate with the
 *   variant's values, root and part bindings alike, because in edit mode
 *   a variant is a preset; the variant area's worn rows say where the
 *   values come from.
 * - `constraints` carries the bound TARGET's resolved guide constraints
 *   for each owned property that has any ({allowed, min, max, units,
 *   scale, stops}): the target's own node block, else the site-wide
 *   constraint default (resolvedConstraintsFor — the offer vocabulary's
 *   lookup layer, so a site-level binding like font-size -> the type
 *   scale shapes stop rows everywhere without per-node authoring). This
 *   is the vocabulary channel: a guide's `scale:` tokens or `allowed:`
 *   list is the widget's stop vocabulary (widget-values.js stopsFor),
 *   built-in stops the no-binding fallback. Client-side input shaping
 *   only; the server validates every commit regardless.
 * - `granted` carries the subset of the widget's owned properties the
 *   bound target actually grants: the served node's grants (the roster's
 *   answer, a narrowed grant already resolved to names, propertyGranted)
 *   exclude the rest, and widgets curate their rows to this in every
 *   enforcement mode (offer-shaping, like constraints; strict makes it
 *   law server-side). Equal to the owned list when the target grants the
 *   widget's whole family, and the same at every placement depth: a
 *   grant is a function of the address alone (placement curates offers
 *   and birth through the axis vocabulary, never legality).
 * - `propertyDefinitions` carries the guide's property definition for each
 *   owned property the group table defines ({type, negative, number,
 *   keywords}, any subset — definitionFor), the same type facts advanced
 *   rows get: a widget's precise editor (the spacing pencil reveal's
 *   DimensionField) reads them for clamping and unit vocabulary. Input
 *   shaping only, like constraints; presence signals a definition exists.
 * - `entry` carries the RESOLVED condition-keyed entry behind `values`
 *   (effectiveEntry's merge: variant layers with the own entry on top),
 *   order-preserving. `values` is rebuilt in owned-property order and
 *   drops nulls, which loses the declaration order a per-side consumer
 *   needs (the box-model diagram resolves shorthand-vs-longhand
 *   interplay by last-declaration-wins); such widgets read
 *   `entry[model.condition]` instead. Read-only, like styleMap.
 * - `condition` echoes the editing compound the values were read at, so
 *   layer-reading widgets (the box-model diagram) and companion guards
 *   address the same compound the pipeline writes.
 * - `hasMore` flags styles the current pose does not show: an owned
 *   property set under a condition OTHER than the editing compound
 *   ('default' included when posed elsewhere — the compound of zero
 *   parts is just another compound), checked against the RESOLVED entry
 *   so a variant's conditions flag exactly like an element's own; plus
 *   a variant reference the library cannot resolve (its styling exists
 *   but is unshowable). The surface renders it as an explicit hint,
 *   never silently and never by normalizing what it can't represent.
 *   Since the pose is the write target, the way to those styles is
 *   posing the canvas there (Styled at / the chip row), not a mode
 *   switch.
 * - `section` is the basic-mode section the control files under: the
 *   binding's own `section:` override, else the widget definition's
 *   (the ControlWidget attribute's default), else null for a widget the
 *   payload does not define. The pipeline groups models by it in the
 *   guide's controlSections roster order (groupControls).
 * - `provenance` carries the bound TARGET's origin inputs
 *   (provenance-model.js provenanceFor: {own, suppliers, shadowed,
 *   skinEntry, declaredEntry, declaredScope, labelOf, valueOf}), read
 *   for `key` itself, so a part binding's rows attribute the part's own
 *   entry, the variants' part layers and the skin's and the
 *   declaration's part defaults, never the root's. A row over
 *   one owned property derives its origin (rowOrigin) from these plus
 *   `entry`: who supplies the shown value and at which condition, the
 *   hued mark and the row menu's doorways. The skin's base:{tag} layer
 *   needs the target's rendered tag, a canvas fact the caller supplies
 *   through `readTag`; unknown degrades the layer away.
 *
 * A control whose value is exactly `false` is the tombstone (a later
 * layer suppressing an inherited control) and is dropped silently:
 * suppressed, not malformed. Block-type tombstones arrive pre-resolved
 * (ClientStyleGuide ships effective declarations with the false kept in),
 * while kind declarations may carry raw `false` directly; the same
 * plain-object filter covers both.
 *
 * An INSTANCE-part key (a sub key resolving through the guide's site
 * instance namespaces — isInstancePartKey(), the one resolution rule as
 * a routing question) models the covering namespace's controls, each
 * bound to the part key ITSELF: an instance is content the user
 * selected, so the curated widgets style the selected paragraph, never
 * its owner. Instance controls are root-bound by definition (the server
 * rejects an element key on them — a namespace's instances are content
 * and declare no parts of their own), so a binding carrying one is
 * malformed here and drops. Shell sub keys (an owner's exact or pattern
 * part), children pseudo-keys, and unparseable keys have no controls of
 * their own — a shell part's curated surface is its owner's lift
 * (element-bound bindings on the owner's panel). Malformed bindings
 * (guide-build validation makes them unreachable from YAML, but the
 * payload is still input) are dropped rather than rendered broken — an
 * ABSENT element key means the root, but a present non-string or empty
 * one is malformed, mirroring the server rule. The element selector is
 * re-checked against PART_SELECTOR because it becomes part of a
 * style-map key — same injection-surface rule as parts.js.
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
 *   The variant records (materials.ofKind('variant')) the resolver reads
 *   references through.
 * @param {Array} layout
 *   The structural layout tree (source.getLayout()), the depth fact the
 *   axis vocabulary reads: nested sections drop root-only axes. Empty
 *   degrades to the ungated root posture.
 * @param {string} condition
 *   The editing compound the canvas pose derives (vectorCondition);
 *   'default' when unposed. Values read here, and the pipeline's write
 *   handlers target it.
 * @param {Function} [readTag]
 *   (elementKey) → the bound target's rendered tag name (lowercase) or
 *   null: the skin defaults' base:{tag} layer key (source.readCanvasTag
 *   behind it in the panel; the default answers null, degrading the
 *   layer away).
 *
 * @return {Array<{id: string, label: string, widget: string,
 *   element: ?string, key: string, definition: ?object,
 *   values: Object<string, string>, entry: ?object, condition: string,
 *   constraints: Object<string, object>,
 *   propertyDefinitions: Object<string, object>, granted: string[],
 *   hasMore: boolean, section: ?string, provenance: object}>}
 *   element is null for a root binding.
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
  readTag = () => null,
) => {
  const parsed = parseKey(key);
  if (!parsed || parsed.children) {
    return [];
  }
  const bundle = bundleForKey(parsed, blockTypes, sectionLayouts);
  // A sub key models controls only as an INSTANCE part: the resolved
  // declaration is then the covering namespace's, and its controls bind
  // the part key itself. A shell sub key (owner exact/pattern part)
  // models nothing of its own — its curated surface is the owner's lift.
  const instance = parsed.sub !== null;
  if (instance && !isInstancePartKey(guide, parsed, bundle)) {
    return [];
  }
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

  return Object.entries(controls)
    .filter(
      ([, control]) =>
        isPlainObject(control) &&
        typeof control.widget === 'string' &&
        control.widget !== '' &&
        (control.element === undefined
          ? // Instance controls are root-bound: an element key on one is
            // malformed (the server rule), not a drill instruction.
            true
          : !instance &&
            typeof control.element === 'string' &&
            PART_SELECTOR.test(control.element)),
    )
    .map(([name, control]) => {
      const definition = isPlainObject(definitions[control.widget])
        ? definitions[control.widget]
        : null;
      const owned = Array.isArray(definition?.properties)
        ? definition.properties
        : [];
      const element = control.element ?? null;
      const elementKey = element === null ? key : `${key}::${element}`;
      // The read: the effective entry — an on-variant element resolves
      // its bound target's styles from the referenced variant (part
      // bindings resolve the variant's element-relative part styles the
      // same way).
      const { entry, variant, reference } = effectiveEntry(
        styleMap,
        elementKey,
        variants,
        axes,
      );

      // Constraints resolve against the bound TARGET's declaration (the
      // element's own elements entry for a part binding, the effective
      // declaration itself for a root one), with the site-wide constraint
      // defaults as the base layer beneath the node's own blocks. Only
      // constrained properties get an entry, so widgets read presence.
      const target =
        element === null
          ? declaration
          : isPlainObject(declaration?.elements?.[element])
            ? declaration.elements[element]
            : null;
      const constraints = {};
      const propertyDefinitions = {};
      owned.forEach((property) => {
        const propertyConstraints = resolvedConstraintsFor(
          guide,
          target,
          property,
        );
        if (propertyConstraints) {
          constraints[property] = propertyConstraints;
        }
        const propertyDefinition = definitionFor(guide, property);
        if (propertyDefinition) {
          propertyDefinitions[property] = propertyDefinition;
        }
      });

      // The GRANTED subset of the widget's owned properties, the
      // roster's read: a target whose grant is narrowed offers only the
      // granted names (the spacing widget's padding row renders only the
      // granted sides from this); equal to owned wherever the target
      // grants the widget's whole family (the guide build vouches that a
      // binding's target covers its widget).
      const granted = owned.filter((property) =>
        propertyGranted(target, property),
      );

      const values = {};
      // An unresolvable reference (the library does not carry the variant,
      // or has not arrived yet): the styling exists but nothing here can
      // show it, so the honesty hint stands in for the values.
      let hasMore = reference !== null && variant === null;
      if (isPlainObject(entry)) {
        const posed = isPlainObject(entry[condition]) ? entry[condition] : {};
        owned.forEach((property) => {
          if (posed[property] !== undefined && posed[property] !== null) {
            values[property] = String(posed[property]);
          }
        });
        // Owned styling at any OTHER compound flags the hint; the
        // unresolvable-reference flag survives (its styling stays
        // unshowable no matter what the own entry carries).
        hasMore =
          hasMore ||
          Object.entries(entry).some(
            ([entryCondition, properties]) =>
              entryCondition !== condition &&
              entryCondition !== 'variants' &&
              isPlainObject(properties) &&
              owned.some(
                (property) =>
                  properties[property] !== undefined &&
                  properties[property] !== null,
              ),
          );
      }

      return {
        id: name,
        label: control.label ?? definition?.label ?? control.widget,
        widget: control.widget,
        element,
        key: elementKey,
        definition,
        values,
        entry: isPlainObject(entry) ? entry : null,
        condition,
        constraints,
        propertyDefinitions,
        granted,
        hasMore,
        section:
          typeof control.section === 'string' && control.section !== ''
            ? control.section
            : typeof definition?.section === 'string'
              ? definition.section
              : null,
        provenance: provenanceFor(
          guide,
          styleMap,
          elementKey,
          variants,
          axes,
          blockTypes,
          sectionLayouts,
          readTag(elementKey),
        ),
      };
    });
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
 * element's structure (Stack | Columns, alignment, gap), and structure
 * reads first, ahead of every roster section, so a container opens on
 * its Layout section while a block, hosting nothing, keeps the plain
 * roster order (kept ids order among themselves by the roster). Models
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
