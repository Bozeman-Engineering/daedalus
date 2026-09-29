import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { textGate } from '../../widget-values.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { ALIGNMENT_ICONS } from '../lib/alignment-icons.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * text_alignment: one choice row over text-align.
 *
 * The row's vocabulary is its value space: a guide constraint on
 * text-align narrows it to the guide's stops (twins keep their icons),
 * the built-in direction-relative table stands otherwise. The surface is
 * the spine's (value-space.js surfaceFor): four icon faces are chips,
 * so the row reads as the segmented alignment group; a skin minting a
 * longer vocabulary would earn the well without this widget changing.
 * The row owns the gesture (one property, one value), so no pick
 * mapping rides here.
 *
 * Gated on the selection carrying real text (textGate at registration):
 * the universal block kind sanctions text styling on EVERY block, but only
 * the canvas knows an image block has no text; the pipeline hides the
 * whole control there unless a value is already set (registry.offered()).
 *
 * Typically root-bound (the binding omits its element key), so writes land
 * on the selected element's own style-map entry, per the "write locally,
 * read contextually" frame. Aligning inline content needs no container
 * cooperation, so the row is always live. The adaptive cross axis
 * (align-self) moved to the position widget, which owns BOTH block axes:
 * this widget aligns the text inside the box; position moves the box.
 */
const TextAlignment = ({
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
  <div className={widgetClasses('text-alignment', false)}>
    <ChoiceRow
      guide={guide}
      materials={materials}
      source={source}
      highlight={styleTarget(model.keys['text-align'])}
      condition={model.condition}
      label={model.label}
      property="text-align"
      value={litValues(model.reads)['text-align']}
      definition={model.propertyDefinitions?.['text-align'] ?? {}}
      constraints={model.constraints?.['text-align'] ?? {}}
      stack={model.stacks['text-align']}
      read={model.reads['text-align']}
      icons={ALIGNMENT_ICONS['text-align']}
      disabled={Boolean(model.withheld?.['text-align'])}
      disabledTitle={model.withheld?.['text-align'] ?? null}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      onPreview={onPreview}
      onRelease={onRelease}
      onCommit={onCommit}
    />
  </div>
);

controlWidgets.register('text_alignment', TextAlignment, {
  applicable: textGate,
});
