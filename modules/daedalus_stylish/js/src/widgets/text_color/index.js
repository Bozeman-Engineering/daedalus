import { h } from 'preact';
import {
  controlWidgets,
  litValues,
  ownAtPose,
  withheldReasonFor,
} from '../../controls.js';
import { textGate } from '../../widget-values.js';
import {
  gradientImageStop,
  gradientStops,
  textGradientBatch,
  textGradientState,
  textPick,
} from '../../widget-gradients.js';
import { spaceClosed } from '../../value-space.js';
import { useGradientChip } from '../../gradient-chip.js';
import { useGesture } from '../lib/use-gesture.js';
import { useColorWell } from '../lib/color-well.js';
import { useRowMenu } from '../lib/use-row-menu.js';
import { originFor } from '../../choice-row-model.js';
import { widgetClasses } from '../lib/widget-classes.js';
import { Mark } from '../lib/mark.js';

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
 * The row is the ordinary basic row: the leading mark (lib/mark.js
 * Mark: hue, fill at the pose and sentence from the
 * choice row's originFor over the color half's stack read, `color`),
 * the row label (model.label, the one source the single-row widgets
 * share), then the well at the row's end; the hidden native picker
 * rides the row too. The well wears the trio's WINNERS at the pose
 * (controls.js litValues over the model's reads: a skin's `color:
 * var(--color-accent)` lights its palette stop exactly as an own value
 * does), so the mark's "from the active skin" and the lit well beneath
 * it tell one story. The lit stop's unpick (the Unset gesture) exists
 * only while the element's own entry delivers the lit half at the pose
 * (ownAtPose); otherwise it does nothing, since a null write would
 * remove nothing. Right-click opens the shared row menu
 * (lib/use-row-menu.js) over the color half's origin: the doorways
 * every property row offers, its unset the trio's unpick.
 *
 * Gated on the selection carrying real text (textGate at registration,
 * shared with text_alignment and typography): the universal block kind
 * sanctions typography on EVERY block, but only the canvas knows an image
 * block has no text; the pipeline hides the whole control there unless a
 * value is already set (registry.offered()).
 *
 * The well disables whole, the reason on its title, when the panel's
 * write address withholds any of the trio (controls.js
 * withheldReasonFor): a text-gradient pick writes all three at once.
 */
const TextColor = ({
  model,
  guide,
  materials,
  swatches,
  source,
  onPreview,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const { values, invalid, stage, pick } = useGesture(
    model.home.key,
    litValues(model.reads),
    onPreview,
    onCommit,
  );
  const gradientRow = gradientStops(
    guide,
    materials,
    model.constraints?.['background-image'],
  );
  // A recipe over a lone gradient literal no offered stop claims lights
  // as its own synthetic stop (gradientImageStop, the Background
  // widget's posture): the well paints it and the grid's toggle is the
  // unpick of the whole recipe.
  const literalStop = gradientImageStop(
    guide,
    materials,
    values['background-image'],
    gradientRow,
  );
  const imageStops =
    literalStop === null ? gradientRow : [...gradientRow, literalStop];
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
    // The optimistic paint covers the WHOLE gesture: a gradient behind
    // opaque text previews as nothing like the commit, so the recipe's
    // three properties stage together per builder input.
    onPreview: (value) => onPreview(textGradientBatch(value)),
    onCommit: (value) => pick(textGradientBatch(value)),
  });
  const disabledTitle = withheldReasonFor(model, [
    'color',
    'background-image',
    'background-clip',
  ]);
  const disabled = disabledTitle !== null;
  const gradientOffered =
    !disabled &&
    (!spaceClosed(guide, model.constraints?.['background-image'] ?? null) ||
      gradient.swatch !== null);
  // The row's origin (the mark's hue and story) over the color half.
  const origin = originFor({
    guide,
    materials,
    condition: model.condition,
    property: 'color',
    stack: model.stacks.color,
    read: model.reads.color,
    blockTypes: source.getBlockTypes?.() ?? {},
    sectionLayouts: source.getSectionLayouts?.() ?? {},
  });
  // The lit half the Unset gesture would remove: the gradient recipe
  // while one is lit, the color otherwise.
  const unsettable = ownAtPose(
    model.reads[image === '' ? 'color' : 'background-image'],
    model.condition,
  );
  // The one pick door (the well's grid, its lane, the menu's unset): a
  // null stop is the unpick, refused while nothing own delivers at the
  // pose and with a withheld row.
  const choose = (stop, gradientPick = false) => {
    if (stop === null && (!unsettable || disabled)) {
      return;
    }
    pick(textPick(values, stop, gradientPick));
  };
  const onContextMenu = useRowMenu({
    origin,
    source,
    onOpenDocument,
    onPickCondition,
    onUnset: () => choose(null),
  });
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
    onPick: choose,
    lanes: gradientOffered ? [gradient.lane] : [],
    canMint: Boolean(swatches?.canCreate),
    source,
    disabled,
    disabledTitle,
  });
  return (
    <div className={widgetClasses('text-color', invalid)}>
      <div
        className="daedalus-stylish-inspector__widget-row"
        style={origin?.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
        onContextMenu={onContextMenu}
      >
        <span className="daedalus-stylish-inspector__widget-label">
          <Mark origin={origin} />
          <span className="daedalus-stylish-inspector__widget-axis">{model.label}</span>
        </span>
        <span className="daedalus-stylish-inspector__widget-value">
          {well}
          {/* The hidden picker rides the value cell (positioned), not the
              popover: the lane closes the popover before it opens the
              chooser, and the chooser's events must still land on a
              mounted input. */}
          {input}
        </span>
      </div>
      {palette}
      {gradientOffered && gradient.popover}
    </div>
  );
};

controlWidgets.register('text_color', TextColor, { applicable: textGate });
