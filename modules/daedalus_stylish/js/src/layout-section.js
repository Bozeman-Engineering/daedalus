import { h, Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { parseKey } from './style-guide.js';
import { createMatcher } from './selector-matcher.js';
import {
  COLUMNS_VARIANT,
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
  gapValueOps,
  rootField,
  addColumnPlan,
  removalPlan,
} from './layout-widget.js';
import { compoundLabel } from './condition-cascade.js';
import { axisVocabulary } from './variant-scope.js';
import { buildStack, stackAt } from './style-stack.js';
import { ALIGNMENT_ICONS } from './widgets/lib/alignment-icons.js';
import { ChoiceRow } from './widgets/lib/choice-row.js';
import { styleTarget } from './widgets/lib/use-highlight.js';
import { addressKey, withheldAt } from './write-address.js';

/**
 * The layout widget's inspector surface (mode + share, and the Arrange
 * fold-in).
 *
 * Two dedicated basic-mode sections over layout-widget.js's pure facts —
 * deliberately NOT ControlWidgets (controls are one-part/style-only by
 * contract; this surface writes structure and multi-key batches): the
 * container's Layout section (Stack | Row | Columns segment, mode-scoped
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
 * declaration lookup) and the container root's layer stack (buildStack,
 * the same model a control model carries, the root's tag from the tree
 * keying the skin's base:{tag} block), so a value the worn row
 * variant supplies wears that variant's hue on the row's mark and the
 * row menu opens it at the delivering condition.
 *
 * Under a write address the rows also carry the address's grant
 * judgement, `withheld` (property → reason): the rows keep the
 * container's vocabulary (rootField over `selected`: a grant is a yes or
 * no, not a different scale) but the write lands at the address, whose
 * landing declaration may not grant what the container's does (a `> *`
 * rule lands on the declaration's `children` node, which grants the
 * flex-item family and no gap or alignment axis), and the server judges
 * the write there. The judge is the roster's, write-address.js
 * withheldAt, the one the pipeline (controls.js `withheld`) and the
 * Advanced roster use, so a withheld row renders disabled with the same
 * reason as its title. Empty without an address.
 */
const rootFacts = (
  source,
  guide,
  styleMap,
  selected,
  variants,
  writeAddress,
  properties,
) => {
  const blockTypes = source.getBlockTypes?.() ?? {};
  const sectionLayouts = source.getSectionLayouts?.() ?? {};
  const tree = source.getTree?.() ?? null;
  const axes = axisVocabulary(
    guide,
    selected,
    blockTypes,
    sectionLayouts,
    source.getLayout?.() ?? [],
  );
  // Under a write address the rows' editable layer is that rule's
  // ancestor layer in the container's stack (the inspector's rows read
  // the same way), so a value the container's own entry shadows wears
  // the overridden mark instead of reading as the row's own.
  const stack = buildStack({
    guide,
    map: styleMap,
    key: selected,
    variants,
    axes,
    blockTypes,
    sectionLayouts,
    tree,
    editable: writeAddress,
  });
  const withheld = writeAddress
    ? withheldAt({
        guide,
        address: writeAddress,
        properties,
        blockTypes,
        sectionLayouts,
        tree,
      })
    : {};
  return { blockTypes, sectionLayouts, stack, withheld };
};

/**
 * The mode-scoped alignment rows: how the container arranges its
 * contents on each screen axis (the folded-in Arrange control), each a
 * ChoiceRow over the axis property at the section's write target
 * (`target`: the container root, or the ancestor rule the panel's
 * target chip names, whose every matching container then arranges the
 * same way; the stack the rows read is the container's, `selected`,
 * with the rule's layer editable). The container
 * is a flex box at every pose (a column by declaration, a row under
 * either worn variant), so every mode speaks flex and a pick writes its
 * axis property alone (alignOps): Stack shows both screen axes
 * (align-items across, justify-content along the column), Row both
 * (justify-content along the line with the Spread stop, align-items
 * across it) and Columns only the cross axis (align-items on the flex
 * row).
 *
 * The vocabulary is axis-relative (one value set faced per screen
 * axis), so the axis table rides in as the row's `fallback` and a guide
 * constraint on the property displaces it; the surface is the spine's
 * (three icon faces read as chips). The row's value and lit state are
 * the axis property's read off the root's layer stack at the pose
 * (stackAt over rootFacts' stack, the Gap row's read: alignmentState
 * lights the winner, so a default-block centre lights at Narrow and a
 * worn variant's alignment lights in its hue, with the custom honesty
 * rule), the same read the row's mark and menu tell their story from;
 * the pick is this section's own batch path at the compound (alignOps),
 * so the row hands the gesture back (`onPick`) rather than committing a
 * lone property; the field contract it also speaks routes through the
 * same batch for the one shape that could ever earn it. Under a write
 * address an axis the address's landing does not grant renders disabled
 * with the roster's reason (rootFacts' `withheld`), the vocabulary still
 * the container's.
 */
const AlignmentRows = ({
  source,
  selected,
  target,
  writeAddress,
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
  const axes = alignmentAxes(state.mode);
  const { blockTypes, sectionLayouts, stack, withheld } = rootFacts(
    source,
    guide,
    styleMap,
    selected,
    variants,
    writeAddress,
    axes.map((axis) => axis.property),
  );
  return (
    <Fragment>
      {axes.map((axis) => {
        const read = stackAt(stack, axis.property, condition, conditions);
        const axisState = alignmentState(read, axis);
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
            target,
            axis,
            value,
            condition,
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
            source={source}
            highlight={styleTarget(target)}
            condition={condition}
            label={Drupal.t(axis.label)}
            property={axis.property}
            value={read.winner?.value ?? null}
            definition={definition}
            constraints={constraints}
            stack={stack}
            read={read}
            disabled={Boolean(withheld[axis.property])}
            disabledTitle={withheld[axis.property] ?? null}
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
            onPreview={(properties) =>
              Object.entries(properties).forEach(([property, value]) =>
                source.preview(target, condition, property, value),
              )
            }
            onRelease={(properties) =>
              source.previewRelease(target, condition, properties)
            }
            onCommit={(properties) => commit(properties[axis.property] ?? null)}
          />
        );
      })}
    </Fragment>
  );
};

/**
 * The gap row, offered in both modes: the shared ChoiceRow (the one
 * basic-mode row every single-property control renders) over the gap
 * at the section's write target (the container root, or the ancestor
 * rule the panel's target chip names). The tick strip died here: its
 * faces went value-only with the vocabulary (`1rem 1.5rem 2rem ...`) and
 * overflowed the sidebar, and the field the pencil used to reveal is the
 * row itself. The vocabulary is the value-space spine's (guide + the
 * root's resolved gap constraints + gap's one built-in table,
 * widget-values.js FALLBACKS) and the surface is the spine's answer
 * too: a scale-constrained gap renders chips or the well over the
 * minted stops with detent scrub on the grip, each pick committing its
 * var() reference; an open gap renders the free field with sticky
 * magnets at the built-in rem stops, a settled value matching a minted
 * token capturing the reference. The row's value is what the container
 * root's layer stack delivers at the pose (stackAt over rootFacts'
 * stack: the root's own gap, the worn arrangement variant's, the skin's,
 * the container declaration's `gap` floor), lit in its layer's hue, and
 * a null winner is honestly unset; a closed row minting nothing hides
 * (the choice row's rule).
 *
 * The field speaks this section's own batch path instead of the widget
 * contract: gapValueOps IS the row's primitive (the gap alone on the
 * root, in both modes: the container is a flex box by declaration, so
 * the gap acts the moment it lands), and previews mirror the same batch
 * so the canvas paints the real gesture mid-scrub.
 *
 * The row's origin is the container root's (rootFacts: the root's
 * layer stack, the same model a control model carries), so a gap the
 * worn row variant supplies wears that variant's hue on the grip, and
 * the row menu opens it at the delivering condition. Under a write
 * address whose landing does not grant gap the row renders disabled
 * with the roster's reason (rootFacts' `withheld`), the vocabulary
 * still the container's.
 */
const GapRow = ({
  source,
  selected,
  target,
  writeAddress,
  styleMap,
  variants,
  guide,
  materials,
  condition,
  conditions,
  onPickCondition,
  onOpenDocument,
}) => {
  const { blockTypes, sectionLayouts, stack, withheld } = rootFacts(
    source,
    guide,
    styleMap,
    selected,
    variants,
    writeAddress,
    ['gap'],
  );
  const gap = stackAt(stack, 'gap', condition, conditions);
  const { definition, constraints } = rootField(
    guide,
    selected,
    'gap',
    blockTypes,
    sectionLayouts,
  );
  const batchFor = (value) => gapValueOps(target, value, condition);
  return (
    <ChoiceRow
      guide={guide}
      materials={materials}
      source={source}
      highlight={styleTarget(target)}
      condition={condition}
      label={Drupal.t('Gap')}
      property="gap"
      value={gap.winner?.value ?? null}
      definition={definition}
      constraints={constraints}
      stack={stack}
      read={gap}
      disabled={Boolean(withheld.gap)}
      disabledTitle={withheld.gap ?? null}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={(properties) =>
        batchFor(properties.gap ?? null).forEach((operation) => {
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
    />
  );
};

/**
 * The axes the Layout section CLAIMS while it is mounted.
 *
 * The Stack | Row | Columns segment is the surface of the container_layout
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
 * The section renders only for a container selection whose children
 * form an arrangeable flow; the key is the selection's own (a rule key
 * never answers). Which facets of that selection mount it is the host's
 * call (the inspector mounts it for the root and under a shell-part
 * drill, where the owner's panel keeps its structural sections, never
 * under an instance drill or a rule facet). The gate is a pure read so
 * the host deciding whether to MOUNT the section (the inspector handing
 * it to BasicControls as the Layout group's host) answers exactly as
 * the section itself does.
 *
 * @return {?{parsed: object, children: Array<object>}}
 *   The parsed container key and its child entries, or null when the
 *   section does not apply.
 */
export const layoutSectionTarget = (source, selected) => {
  const parsed = parseKey(selected);
  if (!parsed || parsed.kind !== 'container' || parsed.selector) {
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
 * carrying both alignment axes and the gap row; Row is the worn row
 * variant (natural-width children, wrapping) with both alignment axes,
 * the main one carrying Spread; Columns is the worn columns variant with
 * the strip, the presets, the count stepper and the cross-axis
 * alignment row; Custom is the honest state for hand-written
 * layout styles, where clicking Stack, Row or Columns IS the explicit takeover
 * gesture (no remove button; the hint points at code view). Posed where
 * the worn variant stacks, the segment still reads Columns (the wear),
 * the sub-line names the variant's decision, and the rows are the
 * stack's at that compound (no strip, no presets; the stepper is
 * structural and stays with the wear).
 *
 * One Layout header: this section is ALSO the host of the control
 * roster's Layout group (BasicControls' `leading` slot). `children` are
 * the group's bound widgets (Spacing, Width), rendered after the
 * structural rows: structure first, then the bound controls, under the
 * one header. A host that returns null would swallow its children, so
 * the inspector mounts it as a host only when layoutSectionTarget
 * answers.
 *
 * Two keys: the STRUCTURAL gestures (the column strip's grows, the
 * stepper's add and remove) act on the selected container's children,
 * `selected`; the layout PROPERTY rows (the Stack | Row | Columns wear, the
 * alignment axes, the gap) read and write at the panel's write target,
 * `writeAddress` (an ancestor rule the target chip names, whose every
 * matching container then arranges the same way) when one is set, else
 * the container root. The property rows keep the container's vocabulary
 * under an address and judge the GRANT at the address (rootFacts'
 * `withheld`, the roster's reason): a row the address's landing does not
 * grant is disabled, never offered for the server to refuse.
 */
export const LayoutSection = ({
  source,
  guide,
  materials,
  selected,
  styleMap,
  variants = [],
  condition = 'default',
  writeAddress = null,
  onOpenDocument = null,
  onPickCondition = null,
  children: bound = null,
}) => {
  const gate = layoutSectionTarget(source, selected);
  if (!gate) {
    return null;
  }
  const { parsed, children } = gate;
  const target = writeAddress ? addressKey(writeAddress) : selected;
  const conditions = guide?.conditions ?? {};
  const state = arrangementState(
    styleMap,
    target,
    variants,
    condition,
    conditions,
  );
  const childKeys = children.map((child) => child.key);
  // The wear each mode button reflects, at every pose (Columns posed
  // where it stacks still reads Columns: the segment is the wear).
  const wornRow = state.mode !== 'custom' && state.wearing === ROW_VARIANT;
  const wornColumns =
    state.mode !== 'custom' && state.wearing === COLUMNS_VARIANT;
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

  const flipLabels = {
    stack: Drupal.t('Stacked the container'),
    row: Drupal.t('Arranged as a row'),
    columns: Drupal.t('Arranged as columns'),
  };
  const flip = (mode) => {
    const operations = modeOps(target, state, mode);
    if (operations.length) {
      source
        .commitBatch({ operations, label: flipLabels[mode] })
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
      // The child's minted type word (the tree node's `_element.type`).
      const nodeTree = source.getTree?.() ?? null;
      const label =
        (Array.isArray(nodeTree)
          ? createMatcher(nodeTree).facts(removal.key)?.type
          : null) ?? Drupal.t('Element');
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
          {modeButton('row', Drupal.t('Row'), wornRow)}
          {modeButton('columns', Drupal.t('Columns'), wornColumns)}
          {state.mode === 'custom' && (
            /* No prose inside the section; the reason rides the pill's
               title, the Segments primitive's own idiom for Custom. */
            <span
              className="daedalus-stylish-inspector__segment daedalus-ui-segment daedalus-ui-segment--static daedalus-stylish-inspector__segment--custom daedalus-stylish-inspector__layout-custom-pill"
              title={Drupal.t(
                'Hand-written layout styles are set on this container. Edit them in code view; picking Stack, Row or Columns replaces them.',
              )}
            >
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
      {state.mode === 'columns' && childKeys.length > 1 && (
        <ProportionStrip
          source={source}
          styleMap={styleMap}
          childKeys={childKeys}
          condition={condition}
          conditions={conditions}
        />
      )}
      {state.mode === 'columns' && (
        <PresetChips
          source={source}
          styleMap={styleMap}
          containerUuid={parsed.uuid}
          childKeys={childKeys}
          condition={condition}
          conditions={conditions}
        />
      )}
      {wornColumns && (
        <div className="daedalus-stylish-inspector__widget-row">
          <span className="daedalus-stylish-inspector__widget-label">
            <span className="daedalus-stylish-inspector__widget-axis">
              {Drupal.t('Columns')}
            </span>
          </span>
          <span className="daedalus-stylish-inspector__widget-value">
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
          </span>
        </div>
      )}
      {state.mode !== 'custom' && (
        <Fragment>
          <AlignmentRows
            source={source}
            selected={selected}
            target={target}
            writeAddress={writeAddress}
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
            target={target}
            writeAddress={writeAddress}
            styleMap={styleMap}
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
    parsed.selector ||
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
  if (parentState.mode !== 'columns') {
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
