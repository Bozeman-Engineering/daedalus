import { h, Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { parseKey, KIND_LABELS } from './style-guide.js';
import {
  ROW_VARIANT,
  CONTAINER_LAYOUT_AXIS,
  containerChildren,
  parentContainerOf,
  arrangementState,
  alignmentAxes,
  alignmentState,
  alignOps,
  childGrows,
  percentLabels,
  dragPair,
  growOps,
  presetDefs,
  presetActive,
  presetPlan,
  modeOps,
  gapState,
  gapValueOps,
  ownAt,
  rootField,
  addColumnPlan,
  removalPlan,
} from './layout-widget.js';
import { compoundLabel } from './condition-cascade.js';
import { axisVocabulary, effectiveEntry } from './variant-scope.js';
import { provenanceFor } from './provenance-model.js';
import { ALIGNMENT_ICONS } from './widgets/lib/alignment-icons.js';
import { ChoiceRow } from './widgets/lib/choice-row.js';
import { styleTarget } from './widgets/lib/use-highlight.js';

/**
 * The layout widget's inspector surface (mode + share, and the Arrange
 * fold-in).
 *
 * Two dedicated basic-mode sections over layout-widget.js's pure facts —
 * deliberately NOT ControlWidgets (controls are one-part/style-only by
 * contract; this surface writes structure and multi-key batches): the
 * container's Layout section (Stack | Columns segment, mode-scoped
 * alignment rows, proportion strip, count stepper, generated presets,
 * the gap row in both modes) and the child-of-a-row Column section
 * (the parent's strip with this segment highlighted and draggable — the
 * same writes — plus the hop to the parent). The Layout section is also
 * the HOST of the control roster's Layout group (BasicControls'
 * `leading` slot): the bound Spacing and Width controls render inside
 * it, after the structural rows, so a container carries one Layout
 * header.
 *
 * The pose doctrine, both halves. The segment is a WEAR surface: it
 * reflects the worn arrangement reference at every pose and the flip
 * never carries a condition; when the wear's own posed blocks decide a
 * different arrangement at the canvas's compound (a Columns wear
 * stacking at Narrow) a sub-line says so ("Columns stacks at Narrow")
 * and is the doorway into the variant's editing session at that
 * condition. Every other row is a PROPERTY surface rendered for the
 * EFFECTIVE arrangement at the pose, reading and writing at the editing
 * compound (`condition`, the vector-derived compound BasicControls
 * reads; synced state, so the satellite renders the same by data). All canvas column chrome retires behind this, and the whole
 * container-arranges-contents story lives here (the standalone Arrange
 * control retired into these rows): arrangement is styling, styling's one
 * surface is the inspector, and with every write funneled through here
 * shadowing wars are structurally impossible.
 *
 * Everything arrives through the state source, so the same components
 * render in-page and in the satellite window: layout tree and style map
 * are broadcast state, drag previews ride the ordinary preview intents,
 * and the batches go through commitBatch — the satellite relays them to
 * the leader's one op queue.
 */

/** The uuid mint for structural ops (add_section honors client uuids). */
const mintWith = (source) => () =>
  source.generateUuid ? source.generateUuid() : crypto.randomUUID();

/**
 * The draggable proportion strip: one segment per child, live percents,
 * dividers snapping to the simple-ratio scale, a drag preserving the
 * pair's combined weight. Shared verbatim by the Layout and Column
 * sections (the Column section highlights the selected child's segment) —
 * one component, one write path.
 */
const ProportionStrip = ({
  source,
  styleMap,
  childKeys,
  highlight = -1,
  condition,
  conditions,
}) => {
  // The in-flight drag's weights, or null: previews go to the canvas
  // through the source; the strip itself re-renders from this local echo.
  const [drag, setDrag] = useState(null);
  const committed = childGrows(styleMap, childKeys, condition, conditions);
  const grows = drag ?? committed;
  const labels = percentLabels(grows);

  // The drag lifecycle rides the continuous-drag spine
  // (Drupal.Daedalus.drag): capture, frame pacing, and every
  // terminator (Escape, blur, lost capture, context menu) come from
  // drag.begin, so a release commits and any other death aborts. The
  // satellite window loads its own copy of the drag library (a
  // daedalus_stylish/satellite dependency): the spine's window-level terminators
  // must watch the window the strip lives in.
  const startDrag = (leftIndex) => (event) => {
    const handle = event.currentTarget;
    const left = handle.previousElementSibling;
    const right = handle.nextElementSibling;
    if (!left || !right) {
      return;
    }
    // Measure before begin() so no move can race the capture; everything
    // here is closure-local, so a refused gesture leaks nothing.
    const pairLeft = left.getBoundingClientRect().left;
    const pairRight = right.getBoundingClientRect().right;
    const base = childGrows(styleMap, childKeys, condition, conditions);
    const pairKeys = [childKeys[leftIndex], childKeys[leftIndex + 1]];
    let current = base;
    const releasePreview = (key, index) => {
      source.previewRelease(key, condition, {
        'flex-grow': String(current[leftIndex + index]),
      });
    };

    Drupal.Daedalus.drag.begin(handle, event, {
      marks: [[handle, 'daedalus-stylish-inspector__layout-handle--drag']],
      onMove: (moveEvent) => {
        const span = pairRight - pairLeft;
        if (span <= 0) {
          return;
        }
        current = dragPair(
          base,
          leftIndex,
          (moveEvent.clientX - pairLeft) / span,
        );
        setDrag(current);
        [leftIndex, leftIndex + 1].forEach((index) => {
          source.preview(
            childKeys[index],
            condition,
            'flex-grow',
            String(current[index]),
          );
        });
      },
      onEnd: ({ reason }) => {
        setDrag(null);
        if (reason !== 'up') {
          // An abort: release both staged previews so the canvas snaps
          // back to the committed values. Release only removes a still-
          // matching staged value, so an untouched key is a no-op.
          pairKeys.forEach(releasePreview);
          return;
        }
        const operations = growOps(
          pairKeys,
          styleMap,
          [current[leftIndex], current[leftIndex + 1]],
          condition,
          conditions,
        );
        // A pair member whose drag settled back on its committed value
        // gets no op: release its staged preview instead of leaving it
        // pinned.
        pairKeys.forEach((key, index) => {
          if (!operations.some((operation) => operation.key === key)) {
            releasePreview(key, index);
          }
        });
        if (operations.length) {
          source
            .commitBatch({ operations, label: Drupal.t('Resized columns') })
            .catch(() => {});
        }
      },
    });
  };

  return (
    <div className="daedalus-stylish-inspector__layout-strip">
      {childKeys.map((key, index) => (
        <Fragment key={key}>
          {index > 0 && (
            <div
              className="daedalus-stylish-inspector__layout-handle"
              title={Drupal.t('Drag to resize')}
              onPointerDown={startDrag(index - 1)}
            />
          )}
          <div
            className={`daedalus-stylish-inspector__layout-segment${index === highlight ? ' daedalus-stylish-inspector__layout-segment--highlight' : ''}`}
            style={{ flexGrow: grows[index] }}
          >
            {labels[index]}
          </div>
        </Fragment>
      ))}
    </div>
  );
};

/**
 * The generated preset chips for the current count: each chip draws its
 * recipe as a mini strip, higher-count chips mint the missing columns.
 */
const PresetChips = ({
  source,
  styleMap,
  containerUuid,
  childKeys,
  condition,
  conditions,
}) => {
  const grows = childGrows(styleMap, childKeys, condition, conditions);
  return (
    <div className="daedalus-stylish-inspector__layout-chips">
      {presetDefs(childKeys.length).map((def) => {
        const active = presetActive(grows, def.weights);
        const mints = def.weights.length - childKeys.length;
        return (
          <button
            key={def.weights.join('-')}
            type="button"
            className={`daedalus-stylish-inspector__layout-chip${active ? ' daedalus-stylish-inspector__layout-chip--active' : ''}`}
            title={
              mints > 0
                ? Drupal.t('Adds @count more column(s)', { '@count': mints })
                : def.weights
                    .map((weight) => Math.round(weight * 100) / 100)
                    .join(' / ')
            }
            onClick={() => {
              const { operations } = presetPlan({
                containerUuid,
                childKeys,
                map: styleMap,
                weights: def.weights,
                generateUuid: mintWith(source),
                condition,
                conditions,
              });
              if (operations.length) {
                source
                  .commitBatch({
                    operations,
                    label: Drupal.t('Applied a column preset'),
                  })
                  .catch(() => {});
              }
            }}
          >
            <span className="daedalus-stylish-inspector__layout-chip-strip">
              {def.weights.map((weight, index) => (
                <span key={index} style={{ flexGrow: weight }} />
              ))}
            </span>
          </button>
        );
      })}
    </div>
  );
};

/**
 * What every root-bound row of this section reads its value-space and
 * origin facts from: the blueprint's type rosters (the root's
 * declaration lookup), the root's RESOLVED condition-keyed entry (the
 * value the row was read from) and its origin inputs (provenanceFor,
 * the same assembly a control model carries), so a value the worn row
 * variant supplies wears that variant's hue on the row's mark and the
 * row menu opens it at the delivering condition.
 */
const rootFacts = (source, guide, styleMap, selected, variants) => {
  const blockTypes = source.getBlockTypes?.() ?? {};
  const sectionLayouts = source.getSectionLayouts?.() ?? {};
  const axes = axisVocabulary(
    guide,
    selected,
    blockTypes,
    sectionLayouts,
    source.getLayout?.() ?? [],
  );
  const { entry } = effectiveEntry(styleMap, selected, variants, axes);
  const provenance = provenanceFor(
    guide,
    styleMap,
    selected,
    variants,
    axes,
    blockTypes,
    sectionLayouts,
    source.readCanvasTag?.(selected, null) ?? null,
  );
  return { blockTypes, sectionLayouts, entry, provenance };
};

/**
 * The mode-scoped alignment rows: how the container arranges its
 * contents on each screen axis (the folded-in Arrange control), each a
 * ChoiceRow over the axis property on the container root. Stack shows
 * both axes over the grid vocabulary — the first pick on a plain stack
 * rides in with the canonical display: grid upgrade as one compound
 * commit (alignOps) — and Columns shows only the cross axis
 * (align-items on the flex row).
 *
 * The vocabulary is axis-relative (one value set faced per screen
 * axis), so the axis table rides in as the row's `fallback` and a guide
 * constraint on the property displaces it; the surface is the spine's
 * (three icon faces read as chips). The row's lit state is
 * alignmentState (the root's own value at the compound, the custom
 * honesty rule) and the pick is this section's own batch path, so the
 * row hands the gesture back (`onPick`) rather than committing a lone
 * property; the field contract it also speaks routes through the same
 * batch for the one shape that could ever earn it.
 */
const AlignmentRows = ({
  source,
  selected,
  styleMap,
  state,
  variants,
  guide,
  materials,
  condition,
  conditions,
  onPickCondition,
  onOpenDocument,
}) => {
  const { blockTypes, sectionLayouts, entry, provenance } = rootFacts(
    source,
    guide,
    styleMap,
    selected,
    variants,
  );
  return (
    <Fragment>
      {alignmentAxes(state.mode).map((axis) => {
        const axisState = alignmentState(styleMap, selected, axis, condition);
        const { definition, constraints } = rootField(
          guide,
          selected,
          axis.property,
          blockTypes,
          sectionLayouts,
        );
        const commit = (value) => {
          const operations = alignOps(
            styleMap,
            selected,
            axis,
            value,
            state.mode,
            condition,
            conditions,
          );
          return operations.length
            ? source.commitBatch({
                operations,
                label: Drupal.t('Aligned the container contents'),
              })
            : Promise.resolve();
        };
        return (
          <ChoiceRow
            key={axis.property}
            guide={guide}
            materials={materials}
            condition={condition}
            label={Drupal.t(axis.label)}
            property={axis.property}
            value={ownAt(styleMap, selected, axis.property, condition)}
            definition={definition}
            constraints={constraints}
            entry={entry}
            provenance={provenance}
            fallback={axis.stops}
            icons={
              ALIGNMENT_ICONS[
                axis.orientation === 'horizontal'
                  ? 'justify-content'
                  : 'align-items'
              ]
            }
            state={axisState}
            onPick={(stop) => {
              commit(stop ? stop.value : null).catch(() => {});
            }}
            onPickCondition={onPickCondition}
            onOpenDocument={onOpenDocument}
            onPreview={(property, value) =>
              source.preview(selected, condition, property, value)
            }
            onRelease={(properties) =>
              source.previewRelease(selected, condition, properties)
            }
            onCommit={(properties) => commit(properties[axis.property] ?? null)}
            highlight={{ source, ...styleTarget(selected) }}
          />
        );
      })}
    </Fragment>
  );
};

/**
 * The gap row, offered in both modes: the shared ChoiceRow (the one
 * basic-mode row every single-property control renders) over the
 * container root's gap. The tick strip died here: its faces went
 * value-only with the vocabulary (`1rem 1.5rem 2rem ...`) and
 * overflowed the sidebar, and the field the pencil used to reveal is the
 * row itself. The vocabulary is the value-space spine's (guide + the
 * root's resolved gap constraints + gap's one built-in table,
 * widget-values.js FALLBACKS) and the surface is the spine's answer
 * too: a scale-constrained gap renders chips or the well over the
 * minted stops with detent scrub on the grip, each pick committing its
 * var() reference; an open gap renders the free field with sticky
 * magnets at the built-in px stops, a settled value matching a minted
 * token capturing the reference. The committed root gap at the compound
 * is the row's value; what the layer stack delivers at the pose
 * (gapState) is its ghost, so nothing written reads as nothing set and a
 * closed row minting nothing hides (the choice row's rule).
 *
 * The field speaks this section's own batch path instead of the widget
 * contract: gapValueOps IS the row's primitive, so a settled value
 * carries the stack upgrade exactly like an alignment pick does.
 * Previews mirror the whole batch, upgrade included, so the canvas
 * paints the real gesture mid-scrub rather than an inert gap.
 *
 * The row's origin is the container root's (rootFacts: provenanceFor
 * over the root's resolved entry, the same assembly a control model
 * carries), so a gap the worn row variant supplies wears that variant's
 * hue on the grip, and the row menu opens it at the delivering
 * condition.
 */
const GapRow = ({
  source,
  selected,
  styleMap,
  state,
  variants,
  guide,
  materials,
  condition,
  conditions,
  onPickCondition,
  onOpenDocument,
}) => {
  const gap = gapState(styleMap, selected, variants, condition, conditions);
  const { blockTypes, sectionLayouts, entry, provenance } = rootFacts(
    source,
    guide,
    styleMap,
    selected,
    variants,
  );
  const { definition, constraints } = rootField(
    guide,
    selected,
    'gap',
    blockTypes,
    sectionLayouts,
  );
  const batchFor = (value) =>
    gapValueOps(styleMap, selected, value, state.mode, condition, conditions);
  return (
    <ChoiceRow
      guide={guide}
      materials={materials}
      condition={condition}
      label={Drupal.t('Gap')}
      property="gap"
      value={gap.raw}
      placeholder={gap.ghost}
      definition={definition}
      constraints={constraints}
      entry={entry}
      provenance={provenance}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={(property, value) =>
        batchFor(value).forEach((operation) => {
          Object.entries(operation.properties).forEach(([name, next]) => {
            source.preview(operation.key, condition, name, next);
          });
        })
      }
      onRelease={(properties) =>
        batchFor(properties.gap).forEach((operation) => {
          source.previewRelease(operation.key, condition, operation.properties);
        })
      }
      onCommit={(properties) =>
        source.commitBatch({
          operations: batchFor(properties.gap),
          label:
            properties.gap === null
              ? Drupal.t('Reset the gap')
              : Drupal.t('Set the gap'),
        })
      }
      highlight={{ source, ...styleTarget(selected) }}
    />
  );
};

/**
 * The axes the Layout section CLAIMS while it is mounted.
 *
 * The Stack | Columns segment is the surface of the container_layout
 * wearing, so a worn row on that axis would be a second surface over one
 * reference, shadowing it. The inspector aggregates the
 * claims of the sections it mounts for the current mode and hands them
 * to the wear bar (wornRows drops the claimed axes); the wear bar never
 * special-cases a section, and the row comes back in the modes that
 * mount no such surface.
 */
export const LAYOUT_SECTION_CLAIMS = [CONTAINER_LAYOUT_AXIS];

/**
 * The Layout section's gate: the container the selection arranges.
 *
 * The section renders only for a container selection (no drilled part,
 * no children pseudo-part) whose children form an arrangeable flow. The
 * gate is a pure read so the host deciding whether to MOUNT the section
 * (the inspector handing it to BasicControls as the Layout group's
 * host) answers exactly as the section itself does.
 *
 * @return {?{parsed: object, children: Array<object>}}
 *   The parsed container key and its child entries, or null when the
 *   section does not apply.
 */
export const layoutSectionTarget = (source, selected) => {
  const parsed = parseKey(selected);
  if (!parsed || parsed.kind !== 'container' || parsed.sub || parsed.children) {
    return null;
  }
  const children = containerChildren(source.getLayout?.() ?? [], parsed.uuid);
  return children === null ? null : { parsed, children };
};

/**
 * The container's Layout section.
 *
 * Self-gating (layoutSectionTarget): renders only for a container
 * selection whose children form an arrangeable flow. Stack is the
 * zero-entries state (a container is just a div until you say otherwise)
 * carrying both alignment axes and the gap row; Columns is the worn row
 * variant with the strip, the presets, the count stepper and the
 * cross-axis alignment row; Custom is the honest state for hand-written
 * layout styles, where clicking Stack/Columns IS the explicit takeover
 * gesture (no remove button; the hint points at code view). Posed where
 * the worn variant stacks, the segment still reads Columns (the wear),
 * the sub-line names the variant's decision, and the rows are the
 * stack's at that compound (no strip, no presets; the stepper is
 * structural and stays with the wear).
 *
 * One Layout header: this section is ALSO the host of the control
 * roster's Layout group (BasicControls' `leading` slot). `more` is the
 * header's hasMore chip row for the group's controls and `children` are
 * the group's bound widgets (Spacing, Width), rendered after the
 * structural rows: structure first, then the bound controls, under the
 * one header. A host that returns null would swallow its children, so
 * the inspector mounts it as a host only when layoutSectionTarget
 * answers.
 */
export const LayoutSection = ({
  source,
  guide,
  materials,
  selected,
  styleMap,
  variants = [],
  condition = 'default',
  onOpenDocument = null,
  onPickCondition = null,
  more = null,
  children: bound = null,
}) => {
  const target = layoutSectionTarget(source, selected);
  if (!target) {
    return null;
  }
  const { parsed, children } = target;
  const conditions = guide?.conditions ?? {};
  const state = arrangementState(
    styleMap,
    selected,
    variants,
    condition,
    conditions,
  );
  const childKeys = children.map((child) => child.key);
  const worn = state.mode !== 'custom' && state.wearing !== null;
  // The posed sub-line's vocabulary: the variant's label from its record,
  // the pose as the compound's site label (compoundLabel, the one
  // spelling the wear bar's sub-line reads too).
  const viaLabel = state.via
    ? (variants.find((variant) => variant.id === state.via.variant)?.label ??
      state.via.variant)
    : null;
  const poseLabel = state.via
    ? compoundLabel(state.via.condition, conditions)
    : null;

  const flip = (mode) => {
    const operations = modeOps(selected, state, mode);
    if (operations.length) {
      source
        .commitBatch({
          operations,
          label:
            mode === 'row'
              ? Drupal.t('Arranged as columns')
              : Drupal.t('Stacked the container'),
        })
        .catch(() => {});
    }
  };

  const modeButton = (mode, label, active) => (
    <button
      type="button"
      className={`daedalus-stylish-inspector__segment daedalus-ui-segment${active ? ' daedalus-ui-segment--active' : ''}`}
      aria-pressed={active}
      onClick={() => flip(mode)}
    >
      {label}
    </button>
  );

  const removal = removalPlan(children);
  const remove = async () => {
    if (!removal) {
      return;
    }
    if (!removal.empty) {
      const label = Drupal.t(
        KIND_LABELS[parseKey(removal.key)?.kind] ?? 'Element',
      );
      const confirmed = await Drupal.Daedalus.dialog.confirm({
        message: Drupal.t('Remove "@label" and its content?', {
          '@label': label,
        }),
        confirmLabel: Drupal.t('Remove'),
        danger: true,
      });
      if (!confirmed) {
        return;
      }
    }
    source
      .commitBatch({
        operations: removal.operations,
        label: Drupal.t('Removed a column'),
      })
      .catch(() => {});
  };

  return (
    <section className="daedalus-stylish-inspector__control-section daedalus-stylish-inspector__layout">
      <div className="daedalus-stylish-inspector__control-section-header daedalus-stylish-inspector__layout-header">
        <h3 className="daedalus-stylish-inspector__control-section-title">
          {Drupal.t('Layout')}
        </h3>
        {more}
        <span
          className="daedalus-stylish-inspector__segments daedalus-ui-segments"
          role="group"
          aria-label={Drupal.t('Layout mode')}
        >
          {modeButton(
            'stack',
            Drupal.t('Stack'),
            state.mode !== 'custom' && state.wearing === null,
          )}
          {modeButton('row', Drupal.t('Columns'), worn)}
          {state.mode === 'custom' && (
            <span className="daedalus-stylish-inspector__segment daedalus-ui-segment daedalus-ui-segment--static daedalus-stylish-inspector__segment--custom daedalus-stylish-inspector__layout-custom-pill">
              {Drupal.t('Custom')}
            </span>
          )}
        </span>
      </div>
      {state.via && (
        /* The wear's own decision at this pose, and the doorway into its
           document at that condition (the wear bar's sub-line twin). */
        <button
          type="button"
          tabIndex={-1}
          className="daedalus-stylish-inspector__layout-pose"
          disabled={!onOpenDocument}
          title={
            onOpenDocument
              ? Drupal.t(
                  'Edit what "@label" sets at @pose. Its wearing stays as it is; the variant owns its own conditions.',
                  { '@label': viaLabel, '@pose': poseLabel },
                )
              : undefined
          }
          onClick={
            onOpenDocument
              ? () => onOpenDocument(state.via.variant, state.via.condition)
              : undefined
          }
        >
          {Drupal.t('@label stacks at @pose', {
            '@label': viaLabel,
            '@pose': poseLabel,
          })}
        </button>
      )}
      {state.mode === 'custom' && (
        <div className="daedalus-stylish-inspector__layout-note daedalus-stylish-inspector__layout-note--custom">
          {Drupal.t(
            'Hand-written layout styles are set on this container. Edit them in code view; picking Stack or Columns replaces them.',
          )}
        </div>
      )}
      {state.mode === 'row' && childKeys.length > 1 && (
        <ProportionStrip
          source={source}
          styleMap={styleMap}
          childKeys={childKeys}
          condition={condition}
          conditions={conditions}
        />
      )}
      {state.mode === 'row' && (
        <PresetChips
          source={source}
          styleMap={styleMap}
          containerUuid={parsed.uuid}
          childKeys={childKeys}
          condition={condition}
          conditions={conditions}
        />
      )}
      {worn && (
        <div className="daedalus-stylish-inspector__widget-row">
          <span className="daedalus-stylish-inspector__widget-axis">
            {Drupal.t('Columns')}
          </span>
          <span className="daedalus-stylish-inspector__layout-stepper">
            <button
              type="button"
              className="daedalus-stylish-inspector__layout-step"
              aria-label={Drupal.t('Remove the last column')}
              disabled={!removal}
              onClick={remove}
            >
              −
            </button>
            <span className="daedalus-stylish-inspector__layout-count">
              {childKeys.length}
            </span>
            <button
              type="button"
              className="daedalus-stylish-inspector__layout-step"
              aria-label={Drupal.t('Add a column')}
              onClick={() => {
                const { operations } = addColumnPlan(
                  parsed.uuid,
                  mintWith(source),
                );
                source
                  .commitBatch({
                    operations,
                    label: Drupal.t('Added a column'),
                  })
                  .catch(() => {});
              }}
            >
              +
            </button>
          </span>
        </div>
      )}
      {state.mode !== 'custom' && (
        <Fragment>
          <AlignmentRows
            source={source}
            selected={selected}
            styleMap={styleMap}
            state={state}
            variants={variants}
            guide={guide}
            materials={materials}
            condition={condition}
            conditions={conditions}
            onPickCondition={onPickCondition}
            onOpenDocument={onOpenDocument}
          />
          <GapRow
            source={source}
            selected={selected}
            styleMap={styleMap}
            state={state}
            variants={variants}
            guide={guide}
            materials={materials}
            condition={condition}
            conditions={conditions}
            onPickCondition={onPickCondition}
            onOpenDocument={onOpenDocument}
          />
        </Fragment>
      )}
      {bound}
    </section>
  );
};

/**
 * The Column section for a direct child of a row container.
 *
 * Self-gating: renders only when the selection (container OR block) sits
 * directly in a container whose EFFECTIVE arrangement at the canvas's
 * compound is a row (worn Columns that stack at this pose show no strip
 * here either) and not in Custom. The strip is the parent's, this
 * child's segment highlighted, and drags commit the exact same writes
 * the parent's own strip fires, at the same compound.
 */
export const ColumnSection = ({
  source,
  guide,
  selected,
  styleMap,
  variants = [],
  condition = 'default',
}) => {
  const parsed = parseKey(selected);
  if (
    !parsed ||
    parsed.sub ||
    parsed.children ||
    (parsed.kind !== 'container' && parsed.kind !== 'block')
  ) {
    return null;
  }
  const layout = source.getLayout?.() ?? [];
  const parent = parentContainerOf(layout, selected);
  if (!parent) {
    return null;
  }
  const conditions = guide?.conditions ?? {};
  const parentState = arrangementState(
    styleMap,
    parent.key,
    variants,
    condition,
    conditions,
  );
  if (parentState.mode !== 'row') {
    return null;
  }
  const childKeys = (containerChildren(layout, parent.uuid) ?? []).map(
    (child) => child.key,
  );
  if (childKeys.length < 2) {
    return null;
  }

  return (
    <section className="daedalus-stylish-inspector__control-section daedalus-stylish-inspector__layout">
      <div className="daedalus-stylish-inspector__control-section-header">
        <h3 className="daedalus-stylish-inspector__control-section-title">
          {Drupal.t('Column')}
        </h3>
      </div>
      <div className="daedalus-stylish-inspector__layout-note">
        {Drupal.t('Column @n of @count · drag the dividers to resize', {
          '@n': parent.index + 1,
          '@count': parent.count,
        })}
      </div>
      <ProportionStrip
        source={source}
        styleMap={styleMap}
        childKeys={childKeys}
        highlight={parent.index}
        condition={condition}
        conditions={conditions}
      />
      <button
        type="button"
        className="daedalus-stylish-inspector__layout-link"
        onClick={() => source.select(parent.key)}
      >
        {Drupal.t('↑ Select the row container')}
      </button>
    </section>
  );
};
