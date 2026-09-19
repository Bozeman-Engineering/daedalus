import { h, Fragment, cloneElement } from 'preact';
import {
  compoundOperations,
  controlModels,
  controlSections,
  controlWidgets,
  groupControls,
} from './controls.js';
import { isVariantRef } from './variant-scope.js';
import { styleTarget } from './widgets/lib/use-highlight.js';
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
 * instance-row selection and canvas opt+click land here identically). Each
 * widget receives its model (see controls.js) and three write handlers
 * targeting the bound element's full key at the EDITING COMPOUND the
 * canvas pose derives (`condition`; 'default' when unposed) — the one
 * write rule: edits land at the posed compound in every mode, through
 * the same state-source preview/commit path the raw property rows use —
 * a control is a fourth writer against the one validator, never a new
 * mutation surface.
 *
 * Widget component contract (Task-5 implementations plug in here):
 *   ({ model, guide, layout, styleMap, swatches, source, onPreview,
 *   onRelease, onCommit, onPickCondition, onOpenDocument }) => vnode
 * - model.values: owned property → committed value at the editing
 *   compound (model.condition echoes which).
 * - model.provenance: the bound target's origin inputs ({own, suppliers,
 *   shadowed, skinEntry, declaredEntry, declaredScope, labelOf,
 *   valueOf}, controls.js), with
 *   model.entry the resolved condition-keyed entry behind the values: a
 *   property row (widgets/lib/choice-row.js) derives its origin from
 *   them (provenance-model.js rowOrigin), the hued mark in its leading
 *   slot and the row menu's doorways. Read-only, like the values.
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
 *   only to model.key, per "write locally, read contextually".
 * - styleMap: the root entity's style map, read-only. Exists for the
 *   compound gesture's safety guards — committed values on ANOTHER key
 *   (the position widget checks the parent has no committed display
 *   before its grid upgrade; the canvas cannot tell chosen from
 *   defaulted). Never a write surface, and a widget's OWN values still
 *   come from model.values.
 * - swatches: the swatch gesture gates (drupalSettings.daedalusStylish.swatches,
 *   {canCreate}): honest chrome for the gradient chip's save-as-swatch
 *   gesture (gradient-chip.js canMint); the mint route enforces.
 * - source: the state source, for the reads and transient chrome only a
 *   host can answer — the canvas placeholder read (source.readCanvas)
 *   and the property-keyed canvas highlight (source.setCanvasHighlight),
 *   both host-aware (the satellite proxies or degrades). NEVER a write
 *   path:
 *   gestures still go through onPreview/onRelease/onCommit below, so
 *   every widget write keeps the one label/companion/batch discipline
 *   this pipeline owns.
 * - onPreview(property, value, key?): stage a live preview (null = unset),
 *   per input event; nothing queued. `key` defaults to the bound target;
 *   a compound gesture (below) passes its companion's key so the parent's
 *   mid-gesture display: grid paints optimistically like any preview.
 * - onRelease(properties, key?): release staged previews without
 *   committing (property → last staged value).
 * - onCommit(properties, companions?, label?): one update_style op for
 *   the whole gesture — a multi-property widget (a border = width +
 *   style) commits atomically. `label` names the op in the undo history
 *   when the widget's story is richer than the default "Styled
 *   <control>" (the position widget's compound upgrade names the parent
 *   it re-arranged); omitted, the pipeline labels it. `companions` is the
 *   compound gesture's path, the ONE sanctioned exception to "write
 *   locally": changes on OTHER keys ([{key, properties, condition?}], the
 *   position widget's parent grid upgrade) that ride the same mutate()
 *   batch, so both land or neither, under ONE undo label; a companion
 *   without its own condition lands at the editing compound like the
 *   widget's change (the whole gesture writes where the canvas is posed);
 *   the source releases every touched key's staged preview when the
 *   batch settles.
 *   Returns the op promise; it rejects on failure, so the widget catches
 *   and shows its invalid state (the op failure report lands on the page,
 *   the preview honestly reverts, companions included).
 * - onPickCondition(condition): drive the canvas to a condition (the
 *   Inspector's driveCanvas: pose the vector and force state so the
 *   compound renders and becomes the write target). The row menu's "Go
 *   to <condition>" doorway and its "Also set at" entries; a satellite
 *   intent like every canvas drive. Null when the host cannot drive.
 * - onOpenDocument(reference, condition): open a variant's editing
 *   session, driving the canvas to `condition` first when it names one
 *   (the Inspector's openDocument): the row menu's "Edit <variant> at
 *   <condition>…" doorway on a variant-supplied or overriding row. Null
 *   when the host has no session surface.
 *
 * Sections, not per-control headers: the renderable controls group under
 * the guide's section roster (controlSections, the ControlSection enum:
 * Text, Surface, Layout, Motion) in roster order, one header per
 * non-empty section (groupControls), controls in declaration order
 * beneath it. No control carries a label line of its own; every row a
 * widget renders is self-labelled (a single-row widget takes
 * model.label as its row label, a multi-row widget labels its rows).
 *
 * `leading` ({sectionId: vnode}) lets the host supply a section's
 * element itself: the inspector hands the container's dedicated Layout
 * section (layout-section.js: Stack | Columns, alignment rows, Gap) in
 * as the Layout group's host, so a container reads ONE Layout header
 * with structure first and the bound controls (Spacing, Width) after,
 * instead of a structural Layout section beside a roster Layout section.
 * The host vnode is cloned with `more` (the header's hasMore chip row,
 * null when none) and the group's widgets as its children; it renders
 * even when no control binds into its section and it LEADS the panel,
 * ahead of every roster section (groupControls' keep), since structure
 * is what an element's panel opens on. A host must render a section:
 * hand one in only when its own gate passes (layoutSectionTarget), or
 * its children vanish with it.
 *
 * Honesty chrome lives here, not in the widgets: the section header
 * carries one muted chip per control with a hasMore hint (owned styles
 * at compounds OTHER than the posed one, or a variant reference the
 * library cannot resolve), naming the control ("Typography has more").
 * The pose is the write target, so the way to those styles is posing the
 * canvas there — the Styled at chips and the header's chip row, both
 * present in basic — not a mode switch; the hint keeps a control with
 * only elsewhere-posed values from reading as unstyled (and keeps it
 * offered). A RESOLVED
 * variant reference is no longer a hasMore case: the models arrive with
 * the variant's values populated (controlModels reads through the
 * effective-styles resolver), and the variant area's worn rows name the
 * variant. A binding whose widget has no registered component renders
 * nothing, never a raw-group fallback (the curated-surface promise), and
 * an element with no renderable controls states that plainly, unless a
 * variant reference is doing the styling (the variant area's worn rows
 * already explain that state).
 *
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
  leading = {},
}) => {
  // The bound target's rendered tag, for the skin defaults' base:{tag}
  // layer: the leader reads the canvas, the satellite answers from the
  // relayed selection tags (the same split every canvas-chrome consumer
  // makes of a model key, styleTarget).
  const readTag = (elementKey) => {
    const { key, sub } = styleTarget(elementKey);
    return source.readCanvasTag?.(key, sub) ?? null;
  };
  const models = controlModels(
    guide,
    selected,
    source.getBlockTypes(),
    styleMap,
    source.getSectionLayouts(),
    variants,
    source.getLayout?.() ?? [],
    condition,
    readTag,
  );
  // One read per render, shared by every control: the context describes
  // the SELECTION's surroundings, and controls all hang off the selection.
  const layout = source.getLayoutContext();
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
        {Drupal.t('This element has no style options.')}
      </div>
    );
  }

  const hasMoreTitle = Drupal.t(
    'This element also has styles at other conditions than the canvas is posed at (or from a variant this panel cannot resolve). Pose the canvas there (the Styled at chips, or the chips above) to see and edit them.',
  );

  const moreChips = (group) =>
    group.models
      .filter((model) => model.hasMore)
      .map((model) => (
        <span
          key={model.id}
          className="daedalus-stylish-inspector__control-section-more daedalus-ui-chip daedalus-ui-chip--muted"
          title={hasMoreTitle}
        >
          {Drupal.t('@control has more', { '@control': model.label })}
        </span>
      ));

  const renderWidget = (model) => {
    const Widget = controlWidgets.get(model.widget);
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
        onPreview={(property, value, key = model.key) =>
          source.preview(key, condition, property, value)
        }
        onRelease={(properties, key = model.key) =>
          source.previewRelease(key, condition, properties)
        }
        onCommit={(properties, companions = null, label = null) => {
          const undoLabel =
            label ?? Drupal.t('Styled @control', { '@control': model.label });
          // Every change lands at the editing compound: the widget's
          // own, and any companion that does not name a condition of
          // its own (spread order lets an explicit one win).
          const changes = [
            { key: model.key, condition, properties },
            ...(Array.isArray(companions) ? companions : []).map(
              (companion) => ({ condition, ...companion }),
            ),
          ];
          // Editing never detaches (the layered entry model): the op
          // writes an override layer and any references stay; the
          // inspector's shadowed-property badges show the state. The
          // compound path: companion ops on other keys join the
          // widget's own in ONE atomic batch under one undo label.
          if (Array.isArray(companions) && companions.length) {
            return source.commitBatch({
              operations: compoundOperations(changes),
              label: undoLabel,
            });
          }
          return source.commit({
            key: model.key,
            condition,
            properties,
            label: undoLabel,
          });
        }}
      />
    );
  };

  return (
    <Fragment>
      {groupControls(renderable, controlSections(guide), hosted).map(
        (group) => {
          const chips = moreChips(group);
          const widgets = group.models.map(renderWidget);
          const host = group.id === null ? null : leading[group.id];
          if (host) {
            // The host's own header carries the title; the chips ride its
            // `more` slot and the bound widgets follow its own rows.
            return cloneElement(
              host,
              { key: group.id, more: chips.length ? chips : null },
              ...widgets,
            );
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
                  {chips}
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
