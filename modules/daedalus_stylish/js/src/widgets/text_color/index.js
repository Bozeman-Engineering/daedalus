import { h } from 'preact';
import { controlWidgets } from '../../controls.js';
import { textGate } from '../../widget-values.js';
import {
  gradientStops,
  textGradientBatch,
  textGradientState,
  textPick,
} from '../../widget-gradients.js';
import { spaceClosed } from '../../value-space.js';
import { useGradientChip } from '../../gradient-chip.js';
import { useGesture } from '../lib/use-gesture.js';
import { useColorWell } from '../lib/color-well.js';
import { originFor } from '../../choice-row-model.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * text_color: one swatch well over the color + text-gradient recipe trio
 * (color, background-image, background-clip).
 *
 * The color half is the shared color well (lib/color-well.js
 * useColorWell) over `color`: its stops, face, color lane and mint row
 * are the well's own anatomy, and every write it produces (a grid pick,
 * a settled picker literal, a minted reference) comes back through one
 * pick door, mapped here by textPick over the trio. Gradient-kind
 * swatches offer AFTER the color stops, gated on background-image's OWN
 * constraints (gradientStops), and the well tells the mapping which
 * table a pick came from.
 *
 * Gradient text is a direct widget batch, not a shipped variant: a
 * gradient stop's pick commits the whole recipe (background-image
 * gradient + background-clip: text + color: transparent) in ONE op
 * (textGradientBatch), the state read recognizes exactly that recipe
 * (textGradientState, keyword case-insensitive), and unpick clears every
 * property the pick wrote. A color pick over the recipe or over foreign
 * occupancy is the explicit replacement (textPick). Partial recipes,
 * media/layered images, foreign background-clip values, and the
 * background widget's own gradient under an unrelated text color all
 * render the honest custom pill (the cross-widget collision read, since
 * gradient text repurposes the element's background).
 *
 * The gradient lane (gradient-chip.js, the shared wiring) opens the
 * GradientBuilder for custom authoring (its commits become the
 * 3-property recipe batch, previews painting the whole recipe so the
 * canvas answers mid-drag) or, when background-image holds exactly one
 * gradient-swatch var(), the edit-in-place swatch session. Custom
 * authoring hides while background-image's value space is closed
 * (spaceClosed) unless that committed swatch reference makes the session
 * the offer.
 *
 * The row is the ordinary basic row: the leading provenance ring
 * (`.daedalus-stylish-inspector__mark`, hued through `--daedalus-stylish-origin` from the
 * choice row's originFor over the color half, `color`), the row label
 * (model.label, the one source the single-row widgets share), then the
 * well at the row's end; the hidden native picker rides the row too.
 * The same originFor read hands the well its ghost: while `color` is
 * unset, the well wears the resting value's paint muted (a skin's
 * `color: var(--color-accent)`, the Base value at a pose) instead of
 * the slashed blank, so the mark's "from the active skin" and the well
 * beneath it tell one story.
 *
 * Gated on the selection carrying real text (textGate at registration,
 * shared with text_alignment and typography): the universal block kind
 * sanctions typography on EVERY block, but only the canvas knows an image
 * block has no text; the pipeline hides the whole control there unless a
 * value is already set (registry.offered()).
 */
const TextColor = ({
  model,
  guide,
  materials,
  swatches,
  source,
  onPreview,
  onCommit,
}) => {
  const { values, invalid, stage, pick } = useGesture(
    model,
    onPreview,
    onCommit,
  );
  const imageStops = gradientStops(
    guide,
    materials,
    model.constraints?.['background-image'],
  );
  const image =
    typeof values['background-image'] === 'string'
      ? values['background-image']
      : '';
  const gradient = useGradientChip({
    guide,
    materials,
    committed: image,
    value: image,
    constraints: model.constraints?.['background-image'] ?? null,
    source,
    canMint: Boolean(swatches?.canCreate),
    onPreview: (value) => {
      // The optimistic paint covers the WHOLE gesture: a gradient behind
      // opaque text previews as nothing like the commit, so the recipe's
      // three properties stage together per builder input.
      Object.entries(textGradientBatch(value)).forEach(([property, part]) =>
        onPreview(property, part),
      );
    },
    onCommit: (value) => pick(textGradientBatch(value)),
  });
  const gradientOffered =
    !spaceClosed(guide, model.constraints?.['background-image'] ?? null) ||
    gradient.swatch !== null;
  // The row's origin (the mark's hue and story) and, beneath an unset
  // well, its ghost: one read for both, so they never disagree.
  const origin = model.provenance
    ? originFor({
        guide,
        materials,
        condition: model.condition,
        property: 'color',
        entry: model.entry,
        provenance: model.provenance,
      })
    : null;
  const {
    well,
    input,
    popover: palette,
  } = useColorWell({
    model,
    property: 'color',
    guide,
    materials,
    values,
    stage,
    onPreview,
    state: (colorStops) =>
      textGradientState(guide, materials, values, colorStops, imageStops),
    stops: imageStops,
    onPick: (stop, gradientPick) => pick(textPick(values, stop, gradientPick)),
    lanes: gradientOffered ? [gradient.lane] : [],
    canMint: Boolean(swatches?.canCreate),
    source,
    ghost: origin?.ghost ?? null,
  });
  return (
    <div className={widgetClasses('text-color', invalid)}>
      <div
        className="daedalus-stylish-inspector__widget-row"
        style={origin?.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
      >
        <span
          className="daedalus-stylish-inspector__mark daedalus-ui-dot"
          title={origin?.title}
          aria-hidden="true"
        />
        <span className="daedalus-stylish-inspector__widget-axis">{model.label}</span>
        {well}
        {/* The hidden picker rides the row (positioned), not the popover:
            the lane closes the popover before it opens the chooser, and
            the chooser's events must still land on a mounted input. */}
        {input}
      </div>
      {palette}
      {gradientOffered && gradient.popover}
    </div>
  );
};

controlWidgets.register('text_color', TextColor, { applicable: textGate });
