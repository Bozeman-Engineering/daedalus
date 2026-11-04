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

/**
 * The widget.
 *
 * @param {object} props
 *   The widget contract's props (basic-controls.js renderControl).
 * @param {object} props.model
 *   The control model (controls.js controlModels): the owned
 *   properties' keys, stacks and reads at the editing compound, the
 *   constraints, the grant and what the write address withholds.
 * @param {object} props.guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} props.materials
 *   The materials roster the stop vocabularies derive from.
 * @param {object} props.source
 *   The state source (leader or satellite).
 * @param {Function} props.onPreview
 *   `(properties)`: previews the set on the canvas, routed per key.
 * @param {Function} props.onRelease
 *   `(properties)`: releases the preview.
 * @param {Function} props.onCommit
 *   `(properties)`: commits the set through the op queue.
 * @param {?Function} [props.onPickCondition]
 *   Poses the canvas at a condition (the rows' "Also set at" entries).
 * @param {?Function} [props.onOpenDocument]
 *   Opens a variant's document (the row menu's doorway).
 * @param {object} [props.law]
 *   The law gate ({canView}, drupalSettings.daedalusStylish.law).
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
  law = {},
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
      disabled={Boolean(model.withheld?.width)}
      disabledTitle={model.withheld?.width ?? null}
      onPickCondition={onPickCondition}
      onOpenDocument={onOpenDocument}
      law={law}
      onPreview={onPreview}
      onRelease={onRelease}
      onCommit={onCommit}
    />
  </div>
);

controlWidgets.register('width', Width);
