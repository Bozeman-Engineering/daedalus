import { h } from 'preact';
import { controlWidgets } from '../../controls.js';
import { parseKey, KIND_LABELS } from '../../style-guide.js';
import {
  parentAlignment,
  positionAxes,
  positionGate,
  positionUpgrade,
} from '../../widget-values.js';
import { useGesture } from '../lib/use-gesture.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { ALIGNMENT_ICONS } from '../lib/alignment-icons.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * What the undo label and the disabled reasons call the surrounding
 * element.
 *
 * The nearest selectable ancestor's kind label when the context carries
 * one, a generic fallback otherwise. Untranslated kind vocabulary lives in
 * style-guide.js; the Drupal.t() wrap happens here, at the render site.
 */
const parentName = (parent) => {
  const kind = parseKey(parent?.key)?.kind;
  return KIND_LABELS[kind]
    ? Drupal.t(KIND_LABELS[kind])
    : Drupal.t('parent element');
};

/**
 * position: where a child sits inside its parent, both screen axes.
 *
 * The child half of the basic-mode Figma split (the container half is
 * the layout widget's alignment rows): two choice rows — vertical and
 * horizontal — over the child's whole positioning vocabulary, align-self
 * + justify-self. Which property means which axis, and whether a write
 * can take effect, comes from the parent context (positionAxes over
 * layout.parent); the widget is gated on that context being KNOWN
 * (positionGate at registration), because every row here is
 * parent-relative — a set value still offers through the registry's
 * value-set rule and renders as plain local writes.
 *
 * The vocabulary is axis-relative (one value set per property, faced
 * per screen axis), so each axis table rides in as the row's `fallback`
 * and a guide constraint on the property displaces it (twins keep their
 * icons); the surface is the spine's (three icon faces read as chips).
 *
 * Both axes are child-LOCAL writes because the canonical container upgrade
 * is grid (justify-self works on grid items, not flex items). Under a
 * plain block parent nothing places the child yet, so a pick rides the
 * compound gesture: the child's own property plus the parent's
 * display: grid in ONE atomic commit, one undo step — the single
 * sanctioned exception to "write locally, read contextually", disclosed
 * where the gesture lands, its undo label ("Positioned, gave <parent> a
 * grid layout"), and guarded by positionUpgrade
 * (the parent key must BE the layout parent box, and a committed
 * display — even 'block' — is never clobbered). When the upgrade is off
 * the table the rows render disabled with the reason on the row's hover
 * title, never silently broken; same for a flex parent's main axis,
 * which distributes children from the container side (its Layout
 * section). No prose lives inside the widget: a reason is a title, a
 * disclosure is a label.
 *
 * When the parent's align-items places this child and no local align-self
 * says otherwise, the align-self row wears that value as its ghost
 * (parentAlignment: the chip representing it marked, unlit, titled with
 * the value that applies) — the honesty rule extended to values the
 * widget does not own; picking a position here overrides it for this
 * element only.
 */
const Position = ({
  model,
  guide,
  materials,
  layout,
  styleMap,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const { values, invalid, pick } = useGesture(model, onPreview, onCommit);
  const parent = layout?.parent ?? null;
  const axes = positionAxes(parent);

  // The compound-upgrade decision: axes say the parent NEEDS the grid
  // upgrade (plain block flow); positionUpgrade says the companion write
  // is honest and safe. Committed display comes from the style map — the
  // canvas cannot tell a chosen display: block from the browser default.
  // The companion lands at the editing compound (the pipeline stamps it),
  // so the guard reads BOTH the compound's own display and the default
  // one beneath it: a deliberate display visible at the pose is never
  // clobbered, whichever layer commits it.
  const upgrading = axes.vertical.write === 'upgrade';
  const parentEntry = styleMap?.[parent?.key];
  const upgradable =
    upgrading &&
    positionUpgrade(
      parent,
      parentEntry?.[model.condition]?.display ?? parentEntry?.default?.display,
    );
  const companions = upgradable
    ? [{ key: parent.key, properties: { display: 'grid' } }]
    : null;

  const enabled = (axis) =>
    axis.write === 'local' || (axis.write === 'upgrade' && upgradable);

  // Why a row is inert: "the parent's job" when both rows are stuck (a
  // blocked upgrade, or a display mode that places nothing); the
  // Layout-section pointer when a flex parent owns exactly this axis.
  const writes = [axes.vertical.write, axes.horizontal.write];
  const blocked =
    (upgrading && !upgradable) || writes.every((write) => write === 'none');
  const reasonFor = (axis) => {
    if (enabled(axis)) {
      return null;
    }
    return blocked
      ? Drupal.t(
          "Positioning this element is the surrounding @parent's job: give it a flex or grid layout to unlock this row.",
          { '@parent': parentName(parent) },
        )
      : Drupal.t(
          "The surrounding @parent arranges its contents along this axis; change that with the @parent's Layout section.",
          { '@parent': parentName(parent) },
        );
  };

  // The resting value for container-driven alignment the child did not
  // write — only meaningful where align-self actually places the child
  // (a local axis under a flex/grid parent).
  const attributedFor = (axis) =>
    axis.property === 'align-self' && axis.write === 'local'
      ? (parentAlignment(parent, values['align-self'], axis.stops)?.value ??
        null)
      : null;

  const axisRow = (axis) => (
    <ChoiceRow
      key={axis.property}
      guide={guide}
      materials={materials}
      condition={model.condition}
      label={Drupal.t(axis.label)}
      property={axis.property}
      value={values[axis.property]}
      placeholder={attributedFor(axis)}
      definition={model.propertyDefinitions?.[axis.property] ?? {}}
      constraints={model.constraints?.[axis.property] ?? {}}
      entry={model.entry}
      provenance={model.provenance}
      fallback={axis.stops}
      icons={
        ALIGNMENT_ICONS[
          axis.orientation === 'horizontal' ? 'justify-content' : 'align-items'
        ]
      }
      disabled={!enabled(axis)}
      disabledTitle={reasonFor(axis)}
      onPick={(stop) => {
        // The companion rides PICKS only: unsetting a position is a
        // local statement and never re-arranges the parent. The undo
        // label discloses the compound where it happens.
        const riding = stop && axis.write === 'upgrade' ? companions : null;
        pick(
          { [axis.property]: stop ? stop.value : null },
          riding,
          riding
            ? Drupal.t('Positioned, gave @parent a grid layout', {
                '@parent': parentName(parent),
              })
            : null,
        );
      }}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={onPreview}
      onRelease={onRelease}
      onCommit={onCommit}
    />
  );

  return (
    <div className={widgetClasses('position', invalid)}>
      {axisRow(axes.vertical)}
      {axisRow(axes.horizontal)}
    </div>
  );
};

controlWidgets.register('position', Position, { applicable: positionGate });
