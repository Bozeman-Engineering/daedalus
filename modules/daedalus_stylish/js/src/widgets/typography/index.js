import { h } from 'preact';
import { controlWidgets } from '../../controls.js';
import { textGate } from '../../widget-values.js';
import { referenceVariable } from '../../value-space.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * typography: the type scale over font-family, font-size, weight and
 * line-height.
 *
 * Four independent choice rows (ChoiceRow), each writing only its own
 * property (the spacing-split precedent: a size gesture never touches
 * weight, per "never reset what a click didn't say"). Each row's
 * vocabulary is its value space: the guide's constraint when one closes
 * or shapes the property (the theme's type scale IS the size row, its
 * weight palette the weight row, the skin's font stacks the Font row),
 * the property's built-in table (widget-values.js FALLBACKS) standing
 * as the detented fallback otherwise. Size and line-height are
 * dimensions, weight a number (variable fonts make 550 legal), so each
 * row is the scrubbable dimension field while its space is open; a
 * closed scale earns chips or the well per the spine (a type scale's
 * rem faces outgrow the chip budget, so the strict Size row is the
 * well, its grip still scrubbing the stops detent to detent), and the
 * surface is decided per property, never per site: a closed Size
 * beside an unbound Weight field.
 *
 * The Font row leads. font-family is text-shaped with no built-in
 * table, so the row exists only where the guide binds it (daedalus_stylish's own
 * site declaration binds it to the `font` scale, so it is the skin's
 * stacks wherever a skin mints them, and absent on a site with no skin,
 * like the Width row over an unminted scale; rowHidden). Each stop
 * faces by token name (body, heading, mono) and WEARS its own stack
 * through the row's faceStyle seam: the literal display value the
 * offer carries (`stop.display`, the minted stack), never the token's
 * var(), since the panel sits outside the token scope and could not
 * resolve it. A stop whose minted value is itself a reference (a skin's
 * bridge token, `body: var(--font-sans)`) wears no face for the same
 * reason. A pick commits the reference (`var(--font-heading)`) as every
 * closed row does.
 *
 * An unset row is never blank: the ghost beneath it is the row's own
 * (ChoiceRow reads it from its origin, the same derivation that colours
 * the mark), so the value the skin supplies shows where the mark says
 * "from the active skin". A text block's root IS its tag element, so
 * the skin's `base:p` delivers its font-size and line-height, a
 * heading's `base:hN` its family and weight too; the widget passes no
 * placeholder, and the well, the field and the chips wear the resting
 * value each in their own posture.
 *
 * Gated on the selection carrying real text (textGate at registration,
 * shared with text_alignment): the universal block kind sanctions
 * typography on EVERY block, but only the canvas knows an image block has
 * no text — the pipeline hides the whole control there unless a value is
 * already set (registry.offered()).
 */
const familyFace = (stop) =>
  typeof stop.display === 'string' &&
  stop.display !== '' &&
  referenceVariable(stop.display) === null
    ? { fontFamily: stop.display }
    : null;

const ROWS = [
  { label: 'Font', property: 'font-family', faceStyle: familyFace },
  { label: 'Size', property: 'font-size', faceStyle: null },
  { label: 'Weight', property: 'font-weight', faceStyle: null },
  { label: 'Line height', property: 'line-height', faceStyle: null },
];

const Typography = ({
  model,
  guide,
  materials,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => (
  <div className={widgetClasses('typography', false)}>
    {ROWS.map(({ label, property, faceStyle }) => (
      <ChoiceRow
        key={property}
        guide={guide}
        materials={materials}
        condition={model.condition}
        label={Drupal.t(label)}
        property={property}
        value={model.values[property]}
        definition={model.propertyDefinitions?.[property] ?? {}}
        constraints={model.constraints?.[property] ?? {}}
        entry={model.entry}
        provenance={model.provenance}
        faceStyle={faceStyle}
        onPickCondition={onPickCondition}
        onOpenDocument={onOpenDocument}
        onPreview={onPreview}
        onRelease={onRelease}
        onCommit={onCommit}
      />
    ))}
  </div>
);

controlWidgets.register('typography', Typography, { applicable: textGate });
