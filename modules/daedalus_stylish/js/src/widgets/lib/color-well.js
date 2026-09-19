import { propertyStops } from '../../widget-values.js';
import { spaceClosed, valueSpaceFor } from '../../value-space.js';
import { colorFace, literalSettle } from '../../color-field-model.js';
import { useColorLane } from '../../native-picker.js';
import { useMintRow } from '../../mint-row.js';
import { useSwatchWell } from './swatch-well.js';

/**
 * The color well: one swatch well over a color property's value space,
 * with the color lane and the mint row wired beneath it.
 *
 * The assembly every color-bearing basic widget mounts (text_color over
 * `color`, background over `background-color`, the stroke rows over a
 * stroke's color part), extracted at the third copy so the well's
 * anatomy exists once:
 * - The color half's stops are the property's own value space, the
 *   derivation the advanced row shares (widget-values.js propertyStops):
 *   a guide `scale:` closes the offer to that scale's minted tokens plus
 *   any `allowed:` literals beside them, a bare `allowed:` list IS the
 *   offer, and an unconstrained property offers the whole color
 *   palette, each stop committing its var() reference. The consumer's
 *   own stops (gradient kinds, the synthetic media stop) append AFTER
 *   them, so the popover's grid lists colors first.
 * - The face over the value space (color-field-model.js colorFace, the
 *   color field's read) is derived from the draft-layered value, so a
 *   staged picker color is what the well wears; the lane's hex and the
 *   mint gate derive from it.
 * - The color lane (native-picker.js useColorLane) opens the browser's
 *   color chooser over the property's value: the hidden picker's input
 *   events stage the draft (the well repaints its literal face live and
 *   the canvas previews, no commit), and its change event settles
 *   through the literal settle rule (literalSettle: a swatch's own hex
 *   captures the swatch) as the explicit replacement a stop pick is.
 *   Offered exactly while the property's space is OPEN (value-space.js
 *   spaceClosed): a closed space commits stops only, which the grid
 *   already offers.
 * - Save-as-swatch is the popover's mint row (mint-row.js useMintRow)
 *   under the gate the color field uses (mintOffered: the grant, an
 *   open space, a literal worn); the minted reference settles through
 *   the same pick door a stop takes.
 * - The well and its popover (swatch-well.js useSwatchWell) carry the
 *   consumer's lanes AFTER the color lane, and the mint row beneath.
 * - The ghost beneath an unset well is the consumer's `ghost`: the raw
 *   resting value its origin read carries (choice-row-model.js
 *   originFor's `ghost`, the same read that hues the row's mark), faced
 *   by the well at the model's condition. The state read never learns
 *   about it.
 *
 * Every write leaves through ONE door, `onPick(stop, appended)`: a grid
 * pick (the lit stop as null, the Segments contract), a settled literal
 * or a minted reference (`{value}` shaped, never appended), so the
 * consumer's own pick mapping (textPick, backgroundPick, a stroke's
 * completion rule) is the whole difference between two wells. The state
 * read stays the consumer's too (`state(colorStops)`: a two-property
 * twin needs the color stops the hook derives), since only the
 * consumer knows which properties the well represents.
 *
 * Pure client over payload data and the consumer's gesture, so it
 * renders in the satellite unchanged. The hidden picker input must be
 * mounted in a POSITIONED row outside the popover (the lane closes the
 * popover before it opens the chooser, and the chooser's events must
 * still land on a mounted input), which is why the consumer places it.
 *
 * @param {object} options
 * @param {object} options.model
 *   The widget model (constraints, propertyDefinitions, condition).
 * @param {string} options.property
 *   The color property the well represents.
 * @param {?object} options.guide
 *   The style guide payload.
 * @param {object} options.materials
 *   The materials roster.
 * @param {object} options.values
 *   The draft-layered values (useGesture's).
 * @param {Function} options.stage
 *   useGesture's stage: the color lane's input events stage
 *   `{[property]: hex}` through it.
 * @param {Function} options.onPreview
 *   The widget's preview handler, called with (property, value) for a
 *   settled literal's preview spelling.
 * @param {Function} options.state
 *   Called with the color stop table; returns the well's mapped state
 *   ({selected, custom, value, ...}, useSwatchWell's `state`).
 * @param {Array} [options.stops]
 *   The consumer's own stops, appended after the color stops.
 * @param {Function} options.onPick
 *   Called with (stop, appended): the picked stop (null for the lit
 *   stop's unpick, `{value}` for a literal or minted reference) and
 *   whether it came from the consumer's appended table.
 * @param {Array} [options.lanes]
 *   The consumer's lanes, after the color lane.
 * @param {boolean} [options.canMint]
 *   The save-as-swatch grant.
 * @param {?object} [options.source]
 *   The state source the mint commits through (required with canMint).
 * @param {?string} [options.customTitle]
 *   useSwatchWell's custom-face tooltip override.
 * @param {?string} [options.ghost]
 *   The RAW resting value beneath an unset well (originFor's `ghost`),
 *   or null.
 *
 * @return {{well: object, input: ?object, popover: ?object, closed:
 *   boolean, stops: Array, space: object, face: object}}
 *   The well button, the hidden picker input (null while the space is
 *   closed), the (open ? mounted : null) popover, whether the space is
 *   closed, the color stop table, the value space and colorFace's
 *   answer for the shown value.
 */
export const useColorWell = ({
  model,
  property,
  guide,
  materials,
  values,
  stage,
  onPreview,
  state,
  stops = [],
  onPick,
  lanes = [],
  canMint = false,
  source = null,
  customTitle = null,
  ghost = null,
}) => {
  const constraints = model.constraints?.[property] ?? null;
  const definition = model.propertyDefinitions?.[property] ?? null;
  const colorStops = propertyStops(
    guide,
    materials,
    property,
    definition,
    constraints,
  );
  const closed = spaceClosed(guide, constraints);
  const space = valueSpaceFor(
    guide,
    materials,
    property,
    definition,
    constraints,
    model.condition,
  );
  const face = colorFace(
    guide,
    materials,
    space,
    values[property],
    model.condition,
  );
  const mapped = state(colorStops);
  const settleLiteral = (text) => {
    const { spelling, preview } = literalSettle(space, text);
    if (spelling === '') {
      return;
    }
    if (preview !== null) {
      onPreview(property, preview);
    }
    onPick({ value: spelling }, false);
  };
  const colorLane = useColorLane({
    face,
    disabled: closed,
    onInput: (event) => stage({ [property]: event.target.value }),
    onChange: (event) => settleLiteral(event.target.value),
  });
  const mint = useMintRow({
    space,
    face,
    canMint,
    source,
    onSettle: (reference) => onPick({ value: reference }, false),
  });
  const { well, popover } = useSwatchWell({
    stops: [...colorStops, ...stops],
    state: mapped,
    custom: mapped.custom ? mapped.value : null,
    ghost,
    condition: model.condition,
    guide,
    materials,
    closed,
    onPick: (stop) => onPick(stop, stop !== null && stops.includes(stop)),
    lanes: [...(closed ? [] : [colorLane.lane]), ...lanes],
    below: mint.row,
    customTitle,
  });
  return {
    well,
    input: closed ? null : colorLane.input,
    popover,
    closed,
    stops: colorStops,
    space,
    face,
  };
};
