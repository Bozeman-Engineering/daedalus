import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * width: one choice row over the single width property, labelled by the
 * control (model.label, the one source the single-row widgets share,
 * since the section header carries no per-control line). The guide's
 * constraint vocabulary is the row's value space when the declaration
 * closes or shapes width; the fractional built-ins (widget-values.js
 * FALLBACKS.width) stand as the detented fallback. The surface is the
 * spine's: the dimension field while the space is open (magnetized to
 * the classic fractions, free entry covering the widths between them),
 * chips or the well over a closed measure scale, and no row at all when
 * a closed scale mints nothing (the law-gated hide).
 */
const Width = ({
  model,
  guide,
  materials,
  source,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => (
  <div className={widgetClasses('width', false)}>
    <ChoiceRow
      guide={guide}
      materials={materials}
      source={source}
      highlight={styleTarget(model.keys.width)}
      condition={model.condition}
      label={model.label}
      property="width"
      value={litValues(model.reads).width}
      definition={model.propertyDefinitions?.width ?? {}}
      constraints={model.constraints?.width ?? {}}
      stack={model.stacks.width}
      read={model.reads.width}
      disabled={Boolean(model.withheld?.['width'])}
      disabledTitle={model.withheld?.['width'] ?? null}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={onPreview}
      onRelease={onRelease}
      onCommit={onCommit}
    />
  </div>
);

controlWidgets.register('width', Width);
