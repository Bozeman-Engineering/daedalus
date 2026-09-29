import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { layoutOwnsGap } from '../../layout-widget.js';
import { gapGate, normalizeValue } from '../../widget-values.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';
import { PaddingRow } from './padding-row.js';

/**
 * spacing_scale: the spacing scale over padding, plus a gap row when the
 * selection actually lays out sibling boxes.
 *
 * Two independent rows, each over its property's own value space
 * (widget-values.js FALLBACKS: the spacing steps behind padding, the gap
 * steps behind gap). Padding is always offered — every box has edges. It is a PaddingRow: the
 * scrubbable shorthand field over the value-space spine, with the ✎
 * revealing the box-model diagram where per-side longhand editing lives
 * (padding-row.js owns that story; the diagram's side cells scrub the
 * same value space, so no pencil gate exists). Gap is a ChoiceRow (the
 * surface adapting to gap's value space) and only exists
 * BETWEEN siblings, so its row is gated on
 * the selection being a container with more than one child box
 * (gapGate); elsewhere gap is a no-op a lay user cannot see, and the
 * row HIDES rather than disables. A shown value must always stay
 * visible, so a delivered gap (the stack's winner at the pose) keeps
 * the row whatever the canvas says; an unknown context (null self)
 * degrades to offering it. Each
 * row writes only its own property/properties — a padding gesture never
 * resets gap and vice versa, per "never reset what a click didn't say".
 *
 * On an arrangeable container the gap row YIELDS to the Layout section's
 * (layoutOwnsGap — the Layout section's own render gate), root gap set or
 * not: gap is part of the container-arranges-contents story, one surface
 * per container, and this row's root-keyed write is exactly the legacy
 * carrier the layout gestures clear. Honesty holds because the Layout
 * section's row reads the root as its fallback carrier, so a set value
 * stays visible there.
 */
const SpacingScale = ({
  model,
  guide,
  materials,
  layout,
  styleMap,
  source,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  // The grant-aware offer: the served roster's grant (model.granted, the
  // widget's owned names the target grants) can withhold gap or the whole
  // padding family here; gap rides the names only where the roster granted
  // it over row-gap and column-gap (the server's family rule).
  const gapGranted = model.granted.includes('gap');
  const lit = litValues(model.reads);
  // Gate on the DELIVERED gap so the surface never reshapes under the
  // pointer mid-gesture; the row leaves only once an unset settles.
  const gapSet = normalizeValue(lit.gap) !== null;
  const hasGapRow =
    gapGranted &&
    !layoutOwnsGap(source?.getLayout?.() ?? [], model.home.key) &&
    (gapSet || gapGate(layout?.self ?? null));

  return (
    <div className={widgetClasses('spacing', false)}>
      <PaddingRow
        model={model}
        guide={guide}
        materials={materials}
        styleMap={styleMap}
        source={source}
        onPreview={onPreview}
        onRelease={onRelease}
        onCommit={onCommit}
      />
      {hasGapRow && (
        <ChoiceRow
          guide={guide}
          materials={materials}
          source={source}
          highlight={styleTarget(model.keys.gap)}
          condition={model.condition}
          label={Drupal.t('Gap')}
          property="gap"
          value={lit.gap}
          definition={model.propertyDefinitions?.gap ?? {}}
          constraints={model.constraints?.gap ?? {}}
          stack={model.stacks.gap}
          read={model.reads.gap}
          disabled={Boolean(model.withheld?.gap)}
          disabledTitle={model.withheld?.gap ?? null}
          onPickCondition={onPickCondition}
          onOpenDocument={onOpenDocument}
          onPreview={onPreview}
          onRelease={onRelease}
          onCommit={onCommit}
        />
      )}
    </div>
  );
};

controlWidgets.register('spacing_scale', SpacingScale);
