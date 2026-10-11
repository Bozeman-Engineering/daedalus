import { h, Fragment, cloneElement } from 'preact';
import {
  compoundOperations,
  controlModels,
  controlSections,
  controlWidgets,
  groupControls,
} from './controls.js';
import { isVariantRef } from './variant-scope.js';
import { MEDIA_PICK_PLACEHOLDER } from './media-library.js';
// Side-effect import: registers the stock widgets (background,
// corners_border, elevation, gap, motion_trigger, position,
// spacing_scale, text_alignment, text_color, typography, width) before
// any host renders a control.
import './widgets/index.js';

/**
 * The basic-mode control surface: declared controls over the style map.
 *
 * Renders the target's controls: bindings as their registered widget
 * components — the curated projection basic mode exists for. `selected`
 * is the panel's TARGET key: the selection root, or a drilled
 * instance-part key ('block:{uuid}::.s-a3'), whose covering namespace's
 * controls bind the part itself (the inspector's basicTarget rule —
 * instance-row selection and canvas opt+click land here identically).
 * `targetSelector` is the targeted SHELL part's selector while one is
 * targeted (null otherwise): the projection then renders only the bindings
 * that list the part, with the part forced first (controls.js
 * controlModels).
 * Each widget receives its model (see controls.js) and three write
 * handlers that route every gesture through ONE rule (`route` below):
 * the gesture's SET values land together on the one box the address
 * rule answers for them as a group (model.landing, controls.js
 * resolveGroup over the model's candidates: the first box where any of
 * them is authored, else the first where any is delivered at a tier,
 * else the first present), and each NULL member (a removal) lands on the box the
 * value lives on (model.keys[property], the row's read), all at the
 * EDITING COMPOUND the canvas pose derives (`condition`; 'default'
 * when unposed). That is the one write rule: edits land at the posed
 * compound in every mode, through the same state-source preview/commit
 * path the raw property rows use, so a control is a fourth writer
 * against the one validator, never a new mutation surface. Every
 * multi-property gesture
 * a basic widget commits is a recipe (a gradient-text trio, a media
 * image with its seeded companions, a stroke, a padding collapse, a
 * trigger pair) whose values only mean anything on one box, which is
 * why the set never routes property by property. A widget never names
 * a key.
 *
 * Widget component contract (Task-5 implementations plug in here):
 *   ({ model, guide, layout, styleMap, swatches, source, onPreview,
 *   onRelease, onCommit, onPickCondition, onOpenDocument }) => vnode
 * - model.home: the binding's first present box ({element, key,
 *   stack}), for everything that is not a property (the canvas
 *   highlight and replay target, the gesture identity);
 *   model.landing(set): the key a gesture's set values land on as a
 *   group (the box a patch's own-layer expansion reads, asked per
 *   write); model.keys and
 *   model.stacks: owned property → the key and layer stack
 *   (style-stack.js) of the box the property resolved to; and
 *   model.reads: owned property → its read at the editing compound
 *   (stackAt: the winner, the layers beneath, the own layer's block;
 *   model.condition echoes which compound). The display rule: a widget
 *   shows each property's WINNER, lit, whichever layer delivers it
 *   (controls.js litValues), a property row (widgets/lib/choice-row.js)
 *   derives its origin from its read (choice-row-model.js originFor),
 *   the hued mark in its leading slot and the row menu's doorways, and
 *   the Unset gesture exists only while the own layer delivers at the
 *   exact compound (controls.js ownAtPose). Read-only.
 * - model.constraints: owned property → the bound target's guide
 *   constraints ({allowed, min, max, units, scale, stops}), only where
 *   any exist. A `scale:` constraint's minted tokens or an `allowed:`
 *   list is the widget's stop vocabulary (widget-values.js stopsFor)
 *   with the built-in table as fallback — how a theme's palette or
 *   scale reaches basic mode. Input shaping only; the server still
 *   validates every commit.
 * - guide: the style guide payload, read-only; materials: the materials
 *   roster the stop rows derive from
 *   (tokens, swatches) the color widgets derive their stops from
 *   (widget-values.js propertyStops, over the property's one fallback).
 * - layout: the selection facts (source.getLayoutContext(); {self, parent}
 *   with self = {display, isContainer, childCount, hasText, parts,
 *   partChildCount} — parts being per-declared-part presence (the
 *   model's answer), partChildCount each present part's own child-box
 *   count (the canvas's), the fact an element-bound gate reads — and parent =
 *   the parent's computed display facts + nearest selectable ancestor
 *   key), or null when unknown. Read-only adaptation input: a widget may
 *   shape what it OFFERS from it (enable a cross axis, gate a row,
 *   attribute a parent-controlled value), but its gestures still write
 *   only where its rows read, per "write locally, read contextually".
 * - styleMap: the root entity's style map, read-only. The layer-reading
 *   widgets' seam (the box-model diagram and the stroke rows read their
 *   own entry's write layer from it). Never a write surface, and a
 *   widget's OWN values still come from model.reads.
 * - swatches: the swatch gesture gates (drupalSettings.daedalusStylish.swatches,
 *   {canCreate}): honest chrome for the gradient chip's save-as-swatch
 *   gesture (gradient-chip.js canMint); the mint route enforces.
 * - source: the state source, for the reads and transient chrome only a
 *   host can answer — the canvas placeholder read (source.readCanvas)
 *   and the property-keyed canvas highlight (source.setCanvasHighlight),
 *   both host-aware (the satellite proxies or degrades). NEVER a write
 *   path:
 *   gestures still go through onPreview/onRelease/onCommit below, so
 *   every widget write keeps the one label/batch discipline this
 *   pipeline owns.
 * - onPreview(properties): stage a live preview of the gesture (property
 *   → value, null = unset), per input event; nothing queued. The same
 *   shape as the release and the commit below and routed the same way
 *   (`route`), so a recipe widget's preview paints the whole recipe on
 *   the box the commit will land on, never a split mid-drag.
 * - onRelease(properties): release staged previews without committing
 *   (property → last staged value), routed like the commit below.
 * - onCommit(properties): the whole gesture as ONE undo step, labelled
 *   "Styled <control>". The set values route to one key and each null
 *   to its own (`route`): one key, one update_style op (a recipe, a
 *   border = width + style + color, commits atomically on one box);
 *   several keys (a removal beside the set on another box: a gradient
 *   pick clearing the root's band while the gradient lands on the
 *   anchor), one atomic mutate() batch through compoundOperations, so
 *   every part lands or none does. A widget writes only where its rows
 *   read: no gesture reaches a key no row of it reads (the pipeline
 *   has no path for one), and the source releases every touched key's
 *   staged preview when the op or batch settles.
 *   Returns the op promise; it rejects on failure, so the widget catches
 *   and shows its invalid state (the op failure report lands on the page,
 *   the preview honestly reverts).
 * - onPickMedia({property, label, companions}): open the media library
 *   for `property` and land the pick as ONE gesture. The property (as
 *   MEDIA_PICK_PLACEHOLDER until the selection fills it) and its set
 *   companions route as a group and each null companion to its own box
 *   (`route`, exactly as a commit's values do), and the routed changes
 *   go to the source (source.pickMedia), which fills the token, stages
 *   every change and commits one op or one atomic batch under `label`
 *   ("Styled <control>" when the widget passes none). The one door to
 *   the library from a widget: a widget names no key here either.
 * - onPickCondition(condition): drive the canvas to a condition (the
 *   Inspector's driveCanvas: pose the vector and force state so the
 *   compound renders and becomes the write target). The row menu's "Go
 *   to <condition>" doorway and its "Also set at" entries; a satellite
 *   intent like every canvas drive. Null when the host cannot drive.
 * - onOpenDocument(reference, condition, anchor): open a variant's
 *   editing session, driving the canvas to `condition` first when it
 *   names one (the Inspector's openDocument): the row menu's "Edit
 *   <variant> at <condition>…" doorway on a variant-supplied or
 *   overriding row. `anchor` names the wearer the session anchors at
 *   when it is not the host's wear key (the carrier rule of a variant
 *   an ancestor's rule wears; provenance-model.js documentDoorway),
 *   null otherwise. Null when the host has no session surface.
 *
 * Sections, not per-control headers: the renderable controls group under
 * the guide's section roster (controlSections, the ControlSection enum:
 * Layout, Text, Surface, Motion) in roster order, one header per
 * non-empty section (groupControls), controls in declaration order
 * beneath it. No control carries a label line of its own; every row a
 * widget renders is self-labelled (a single-row widget takes
 * model.label as its row label, a multi-row widget labels its rows).
 * There is never a second row for the same binding: a binding over
 * several boxes (the text block's link and root) renders one row per
 * owned property, reading the box the address rule picked, and a
 * second authored value on another box surfaces in that row's menu
 * ("Also set on element", the read's `alsoOn`), never through a second
 * labelled group.
 *
 * `leading` ({sectionId: vnode}) lets the host supply a section's
 * element itself: the inspector hands the container's dedicated Layout
 * section (layout-section.js: Stack | Row | Columns, alignment rows, Gap) in
 * as the Layout group's host, so a container reads ONE Layout header
 * with structure first and the bound controls (Spacing, Width) after,
 * instead of a structural Layout section beside a roster Layout section.
 * The host vnode is cloned with the group's widgets as its children; it
 * renders even when no control binds into its section and it LEADS the
 * panel, ahead of every roster section (groupControls' keep), since
 * structure is what an element's panel opens on. A host must name a
 * roster section (keep never invents a group the roster lacks) and must
 * render one: hand it in only when its own gate passes
 * (layoutSectionTarget), or its children vanish with it.
 *
 * No honesty chrome lives here: what the pose hides is every row's own
 * story (its mark and menu, provenance-model.js originOf: a hollow mark
 * in the supplier's hue for a value cascading in from another
 * condition or authored only elsewhere, "Set at Hover, not here", the
 * "Also set at" roster and "Also set on" box), and the section header
 * carries nothing about it. The pose is the write target, so the way
 * to other-pose styles is posing the canvas there (the pinned pose
 * group's chip row and its Styled at line, both present in basic), not
 * a mode switch; a control with only elsewhere-posed styling stays
 * offered for it (controls.js `styledElsewhere`, the registry's offer
 * rule). A RESOLVED variant reference arrives with the variant's values
 * lit (controlModels reads the layer stack, and a worn variant is a
 * layer of it), and the variant area's worn rows name the variant. A
 * binding whose widget has no registered component renders nothing,
 * never a raw-group fallback (the curated-surface promise), and an
 * element with no renderable controls states that plainly, unless a
 * variant reference is doing the styling (the variant area's worn rows
 * already explain that state).
 *
 */
/**
 * The panel: the target's control groups over the selection's layout
 * context, the hosted structural sections leading.
 *
 * `rule` marks a RULE key target (a chain facet seated from a code-mode
 * row header, `container:X::> pb-text`): the panel is then the rule's,
 * one thing at a time. Its rows are the landing declaration's over the
 * container's rule (controls.js controlModels), read with no layout
 * facts (a set of matched elements has no one display or text fact, so
 * the offer gates read unknown and offer) and with each part's presence
 * answered by the matcher over the rule's matched elements inside the
 * projection, never by the selection's context.
 *
 * `box` marks a rule target that is a generated box (`&::before`, a
 * part's `.quote::after`): no box declares controls, so the panel is
 * empty there by construction and says which modes style a box instead
 * of "no style options". The chip offers no box doorway in this mode;
 * a box arrives here from the Page elements tree, the satellite or a
 * mode switch.
 *
 * `writeAddress` is the panel's write address (`{key, selector}`, the
 * spine's address fact: a rule on an ancestor or on the selected key
 * itself, picked from the subject line's chip; null for the own key):
 * the rows stay the subject's and each property writes at its REBASED
 * key (controls.js controlModels), so the routing below needs no
 * change: `model.keys[property]` already names the address. A property
 * the address withholds arrives in `model.withheld` and its widget
 * renders it disabled with the reason.
 *
 * The empty state names the authoring gap it is: basic mode's surface
 * is the `controls:` someone declared for the landing node, and base
 * tags, generated boxes and `children` have none, so "no style options"
 * there says nothing about the vocabulary (advanced and code read the
 * property groups and style the same landing); the sentence points
 * there.
 */
export const BasicControls = ({
  source,
  guide,
  materials,
  selected,
  styleMap,
  variants = [],
  condition = 'default',
  swatches = {},
  onPickCondition = null,
  onOpenDocument = null,
  targetSelector = null,
  leading = {},
  rule = false,
  box = false,
  writeAddress = null,
}) => {
  // One read per render, shared by every control: the context describes
  // the SELECTION's surroundings, and controls all hang off the selection.
  // The projection reads the parts' presence from it; the offer gates
  // read the rest. A rule key has no one element's facts to read.
  const layout = rule ? null : source.getLayoutContext();
  const models = controlModels(
    guide,
    selected,
    source.getBlockTypes(),
    styleMap,
    source.getSectionLayouts(),
    variants,
    condition,
    layout,
    targetSelector,
    source.getTree?.() ?? null,
    writeAddress,
  );
  // Two filters, two reasons to not render: no registered component (the
  // curated-surface promise: no raw fallback), or gated off by the offer
  // rule (sanctioned but not canvas-applicable and nothing set), so the
  // control HIDES
  // (registry.offered() guarantees a set value is never hidden).
  const renderable = models.filter(
    (model) =>
      controlWidgets.has(model.widget) &&
      controlWidgets.offered(model.widget, model, layout),
  );

  // The sections a host supplies (the container's Layout section): they
  // lead the panel whether or not a control binds into them, since they
  // carry content of their own.
  const hosted = Object.keys(leading).filter((id) => leading[id]);

  if (!renderable.length && !hosted.length) {
    const entry = styleMap?.[selected];
    const onVariant = isVariantRef(entry);
    return onVariant ? null : (
      <div className="daedalus-stylish-inspector__empty">
        {box
          ? Drupal.t('Generated boxes are styled in Advanced or code mode.')
          : rule
            ? Drupal.t(
                'No basic controls are declared for this rule. Style it in Advanced or code mode.',
              )
            : Drupal.t(
                'No basic controls are declared for this element. Style it in Advanced or code mode.',
              )}
      </div>
    );
  }

  const renderControl = (model) => {
    const Widget = controlWidgets.get(model.widget);
    // The one routing rule. A gesture's SET values (every non-null
    // member) land on ONE key: the model's answer for the set as a
    // group (model.landing: controls.js resolveGroup over the model's
    // candidates, the write key already rebased to the panel's address,
    // the binding's home box when nothing is addressable). Each NULL
    // member (a removal) lands on the key its row reads
    // (model.keys[property]: a removal can only address the box the
    // value lives on), a property outside the owned set (none today) on
    // the home box. One change per key touched, in gesture order.
    const route = (properties) => {
      const entries = Object.entries(properties);
      const set = entries
        .filter(([, value]) => value !== null)
        .map(([property]) => property);
      const groupKey = model.landing(set);
      const changes = new Map();
      entries.forEach(([property, value]) => {
        const key =
          value === null
            ? (model.keys[property] ?? model.home.key)
            : groupKey;
        if (!changes.has(key)) {
          changes.set(key, {});
        }
        changes.get(key)[property] = value;
      });
      if (changes.size === 0) {
        changes.set(model.home.key, {});
      }
      return [...changes.entries()].map(([key, grouped]) => ({
        key,
        properties: grouped,
      }));
    };
    return (
      <Widget
        key={model.id}
        model={model}
        guide={guide}
        materials={materials}
        layout={layout}
        styleMap={styleMap}
        swatches={swatches}
        source={source}
        onPickCondition={onPickCondition}
        onOpenDocument={onOpenDocument}
        onPreview={(properties) =>
          route(properties).forEach(({ key, properties: grouped }) =>
            Object.entries(grouped).forEach(([property, value]) =>
              source.preview(key, condition, property, value),
            ),
          )
        }
        onRelease={(properties) =>
          route(properties).forEach(({ key, properties: grouped }) =>
            source.previewRelease(key, condition, grouped),
          )
        }
        onCommit={(properties) => {
          const undoLabel = Drupal.t('Styled !control', {
            '!control': model.label,
          });
          // Every change lands at the editing compound: the widget's
          // own, one per routed key.
          const changes = route(properties).map((change) => ({
            condition,
            ...change,
          }));
          // Editing never detaches (the layered entry model): the op
          // writes an override layer and any references stay; the
          // inspector's shadowed-property badges show the state. One
          // key, one op; several keys (a removal on another box beside
          // the set) join in ONE atomic batch under one undo label.
          if (changes.length > 1) {
            return source.commitBatch({
              operations: compoundOperations(changes),
              label: undoLabel,
            });
          }
          return source.commit({
            key: changes[0].key,
            condition,
            properties: changes[0].properties,
            label: undoLabel,
          });
        }}
        onPickMedia={({ property, label = null, companions = {} }) =>
          // The pick is a gesture like any other: the picked property (the
          // placeholder standing in for the token) and its companions
          // route through the one rule, and the source lands the routed
          // changes at the editing compound once the selection fills it.
          source.pickMedia?.({
            condition,
            property,
            label:
              label ??
              Drupal.t('Styled !control', { '!control': model.label }),
            changes: route({
              [property]: MEDIA_PICK_PLACEHOLDER,
              ...companions,
            }),
          })
        }
      />
    );
  };

  return (
    <Fragment>
      {groupControls(renderable, controlSections(guide), hosted).map(
        (group) => {
          const widgets = group.models.map(renderControl);
          const host = group.id === null ? null : leading[group.id];
          if (host) {
            // The host's own header carries the title; the bound widgets
            // follow its own rows.
            return cloneElement(host, { key: group.id }, ...widgets);
          }
          return (
            <section
              key={group.id ?? ''}
              className="daedalus-stylish-inspector__control-section"
            >
              {group.label !== null && (
                <div className="daedalus-stylish-inspector__control-section-header">
                  <h3 className="daedalus-stylish-inspector__control-section-title">
                    {group.label}
                  </h3>
                </div>
              )}
              {widgets}
            </section>
          );
        },
      )}
    </Fragment>
  );
};
