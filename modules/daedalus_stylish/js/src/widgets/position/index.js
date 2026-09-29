import { h } from 'preact';
import { controlWidgets, litValues, withheldReasonFor } from '../../controls.js';
import { createMatcher } from '../../selector-matcher.js';
import {
  marginAxisValue,
  marginAxisWrite,
  normalizeValue,
  parentAlignment,
  positionAxes,
  positionGate,
} from '../../widget-values.js';
import { useGesture } from '../lib/use-gesture.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { ALIGNMENT_ICONS } from '../lib/alignment-icons.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * What the disabled reasons call the surrounding element.
 *
 * The nearest selectable ancestor's minted type word (the tree node's
 * `_element.type` through the matcher: "Section", "Container", a block
 * type's label) when the tree carries its node, a generic fallback
 * otherwise.
 */
const parentName = (matcher, parent) =>
  matcher?.facts(parent?.key)?.type ?? Drupal.t('parent element');

/**
 * position: where a child sits inside its parent, both screen axes.
 *
 * The child half of the basic-mode Figma split (the container half is
 * the layout widget's alignment rows): two choice rows, vertical and
 * horizontal, over the child's positioning vocabulary: align-self,
 * justify-self and the auto-margin pair. Which property means which
 * axis, and whether a write can take effect, comes from the parent
 * context (positionAxes over layout.parent); the widget is gated on that
 * context being KNOWN (positionGate at registration), because every row
 * here is parent-relative; a set value still offers through the
 * registry's value-set rule and renders as plain local writes.
 *
 * The vocabulary is axis-relative (one value set per property, faced
 * per screen axis), so each axis table rides in as the row's `fallback`
 * and a guide constraint on the property displaces it (twins keep their
 * icons); the surface is the spine's (three icon faces read as chips).
 *
 * Every write is child-LOCAL. A container parent is always a flex box by
 * the declared tier, so under a stack (a flex column) the vertical row
 * is the margin axis: a pick writes `auto` onto margin-top and/or
 * margin-bottom, the flex spec's per-child main-axis placement, with the
 * row's state read back from which sides are `auto` (marginAxisValue)
 * and the write trimmed to what the placement owns (marginAxisWrite: a
 * typed margin on the other side is left alone). The pair row hands the
 * choice row the delivering side's read and stack, so its provenance
 * mark and the Unset gesture speak for the side that carries the `auto`.
 * A row an axis reality takes off the table (a flex row's main axis, a
 * parent that places nothing) renders disabled with the reason on its
 * hover title, never silently broken, and so does a row whose property
 * (or margin pair) the panel's write address withholds (controls.js
 * withheldReasonFor). No prose lives inside the widget: a reason is a
 * title.
 *
 * When the parent's align-items places this child and no layer of the
 * child's own stack delivers align-self, the align-self row wears that
 * value as its attributed placeholder (parentAlignment: the chip
 * representing it marked, unlit, titled with the value that applies),
 * a value from OUTSIDE the child's stack, the one placeholder a row
 * keeps; picking a position here overrides it for this element only.
 */
const Position = ({
  model,
  guide,
  materials,
  layout,
  source,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const { values, invalid, pick } = useGesture(
    model.home.key,
    litValues(model.reads),
    onPreview,
    onCommit,
  );
  const parent = layout?.parent ?? null;
  const axes = positionAxes(parent);
  const nodeTree = source?.getTree?.() ?? null;
  const matcher = Array.isArray(nodeTree) ? createMatcher(nodeTree) : null;

  const enabled = (axis) => axis.write === 'local';

  // Why a row is inert: the parent places nothing when both rows are
  // stuck (a plain-flow display: a block wrapper around a field, or a
  // container whose hand-written display the code view set), and no
  // basic surface changes that (a container is always flex by
  // declaration, and the Layout section writes no display), so the
  // reason states the fact and prescribes no gesture; the
  // Layout-section pointer when a flex parent owns exactly this axis.
  const blocked = [axes.vertical, axes.horizontal].every(
    (axis) => axis.write === 'none',
  );
  const reasonFor = (axis) => {
    if (enabled(axis)) {
      return null;
    }
    return blocked
      ? Drupal.t(
          'The surrounding @parent lays out its contents in plain flow, so there is no position to pick for this element inside it.',
          { '@parent': parentName(matcher, parent) },
        )
      : Drupal.t(
          "The surrounding @parent arranges its contents along this axis; change that with the @parent's Layout section.",
          { '@parent': parentName(matcher, parent) },
        );
  };

  // The resting value for container-driven alignment the child did not
  // write, only meaningful where align-self actually places the child
  // (a local axis under a flex/grid parent).
  const attributedFor = (axis) =>
    axis.property === 'align-self' && axis.write === 'local'
      ? (parentAlignment(parent, values['align-self'], axis.stops)?.value ??
        null)
      : null;

  // The side of the margin pair whose `auto` delivers the row's state,
  // the side the row reads provenance and unsettability from, and whose
  // write key is the row's canvas address (the pair's identity,
  // `margin-block`, is no property the model keys): the first side
  // reading `auto`, the top side when neither does.
  const marginSide = (axis) =>
    axis.pair.find((side) => normalizeValue(values[side]) === 'auto') ??
    axis.pair[0];

  const axisRow = (axis) => {
    const pair = axis.pair ?? null;
    const side = pair ? marginSide(axis) : axis.property;
    const withheld = withheldReasonFor(model, pair ?? [axis.property]);
    const onPick = (stop) => {
      if (!pair) {
        pick({ [axis.property]: stop ? stop.value : null });
        return;
      }
      const write = marginAxisWrite(stop?.id ?? null, values);
      if (Object.keys(write).length) {
        pick(write);
      }
    };
    return (
      <ChoiceRow
        key={axis.property}
        guide={guide}
        materials={materials}
        source={source}
        highlight={styleTarget(model.keys[side])}
        condition={model.condition}
        label={Drupal.t(axis.label)}
        property={axis.property}
        value={pair ? marginAxisValue(values) : values[axis.property]}
        placeholder={attributedFor(axis)}
        definition={
          pair ? {} : (model.propertyDefinitions?.[axis.property] ?? {})
        }
        constraints={pair ? {} : (model.constraints?.[axis.property] ?? {})}
        stack={model.stacks[side]}
        read={model.reads[side]}
        fallback={axis.stops}
        icons={
          ALIGNMENT_ICONS[
            axis.orientation === 'horizontal' ? 'justify-content' : 'align-items'
          ]
        }
        disabled={!enabled(axis) || withheld !== null}
        disabledTitle={reasonFor(axis) ?? withheld}
        onPick={onPick}
        onPickCondition={onPickCondition}
        onOpenDocument={onOpenDocument}
        onPreview={onPreview}
        onRelease={onRelease}
        onCommit={onCommit}
      />
    );
  };

  return (
    <div className={widgetClasses('position', invalid)}>
      {axisRow(axes.vertical)}
      {axisRow(axes.horizontal)}
    </div>
  );
};

controlWidgets.register('position', Position, {
  applicable: positionGate,
});
