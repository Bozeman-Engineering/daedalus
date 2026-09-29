import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { boundGapGate } from '../../widget-values.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * gap: one choice row over the bound element's gap, labelled by the
 * control (model.label, the single-row widgets' one label source).
 *
 * The page-level Gap control: daedalus_edit binds it on the entity kind to
 * the layout root daedalus_layout renders (`element: '.daedalus-layout-layout'`), so the
 * page spaces its root bands the way a container spaces its children;
 * the row's write lands at `entity:{uuid}::.daedalus-layout-layout`, the same
 * part-keyed address the AI writes, through the ordinary widget contract
 * (one op per pick, the field contract on the open surface). The value
 * space is gap's own (widget-values.js FALLBACKS.gap, the rem steps the
 * Layout section's row magnetizes to; a guide constraint on the part
 * displaces it), so the surface is whatever that space earns.
 *
 * The widget owns row-gap and column-gap beside gap (the PHP plugin's
 * declaration, so the guide holds the bound part to the whole family and
 * a longhand the AI wrote keeps the control offered through the value-set
 * override), but the row reads and writes the shorthand alone: one row,
 * one rhythm between bands.
 *
 * Gated on the BOUND element laying out more than one child box
 * (boundGapGate over self.partChildCount, the part's own children as the
 * canvas shows them): a gap between one band is a no-op a lay user
 * cannot see, so the row HIDES rather than disables. The presence gate
 * runs first (an entity with no sections renders no layout root, and the
 * model says so), a committed gap keeps the row whatever the canvas
 * says, and an unknown context degrades to offering.
 *
 * Not spacing_scale rebound to the part: the layout root has no inner
 * spacing story, so a Padding row there would be meaningless air inside a
 * box that only arranges.
 */
const Gap = ({ model, guide, materials, source, onPreview, onRelease, onCommit, onPickCondition = null, onOpenDocument = null }) => (
  <div className={widgetClasses('gap', false)}>
    <ChoiceRow
      guide={guide}
      materials={materials}
      source={source}
      highlight={styleTarget(model.keys.gap)}
      condition={model.condition}
      label={model.label}
      property="gap"
      value={litValues(model.reads).gap}
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
  </div>
);

controlWidgets.register('gap', Gap, {
  applicable: (self, parent, model) => boundGapGate(self, model?.home?.element || null),
});
