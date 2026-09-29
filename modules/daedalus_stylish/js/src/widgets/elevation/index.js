import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * elevation: one choice row over the box-shadow property.
 *
 * Each stop writes a whole shadow value (the single-property pattern;
 * the pair machinery is not needed). The vocabulary is the row's value
 * space: the guide's constraint when one closes box-shadow (the theme's
 * shadow scale IS the row, the guide's exact spellings kept verbatim),
 * the built-in elevation presets (widget-values.js FALLBACKS) as the
 * fallback. Faces are the spine's: a shadow token faces by NAME
 * (sm/md/lg, never its CSS), the explicit none stop as "None", and the
 * surface follows the vocabulary's size (chips for a short scale, the
 * well beyond the chip budget). Shadows no stop represents (multi-shadow
 * stacks, rgba() spellings, inset) render as the explicit custom face,
 * per the honesty rule. The row owns the gesture (one property, one
 * value), so no pick mapping rides here.
 *
 * No dynamic gate on purpose (the widget matrix): any box can cast a
 * shadow, so sanction alone decides where the control appears.
 */
const Elevation = ({
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
  <div className={widgetClasses('elevation', false)}>
    <ChoiceRow
      guide={guide}
      materials={materials}
      source={source}
      highlight={styleTarget(model.keys['box-shadow'])}
      condition={model.condition}
      label={model.label}
      property="box-shadow"
      value={litValues(model.reads)['box-shadow']}
      definition={model.propertyDefinitions?.['box-shadow'] ?? {}}
      constraints={model.constraints?.['box-shadow'] ?? {}}
      stack={model.stacks['box-shadow']}
      read={model.reads['box-shadow']}
      disabled={Boolean(model.withheld?.['box-shadow'])}
      disabledTitle={model.withheld?.['box-shadow'] ?? null}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={onPreview}
      onRelease={onRelease}
      onCommit={onCommit}
    />
  </div>
);

controlWidgets.register('elevation', Elevation);
