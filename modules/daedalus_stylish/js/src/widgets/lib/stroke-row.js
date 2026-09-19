import { h, Fragment } from 'preact';
import { normalizeValue } from '../../widget-values.js';
import { valueSpaceFor, wearing } from '../../value-space.js';
import { rowMenu, tokenItems } from '../../provenance-model.js';
import { useMenu } from '../../menu.js';
import { usePopover } from '../../popover.js';
import { inlineLiteral } from '../../materials.js';
import { ghostFace, originFor, rowHidden } from '../../choice-row-model.js';
import { STROKE_PARTS, strokeState } from './stroke-model.js';
import { useColorWell } from './color-well.js';
import { wellFace } from './swatch-well-model.js';
import { ChoiceList } from './choice-row.js';
import { DimensionField } from './dimension-field.js';
import { useFieldGesture, useGesture } from './use-gesture.js';

/**
 * The stroke row: one basic-mode row over one STROKE (a border, an
 * outline, one border side), its three parts as three sub-controls in
 * the value column: `[color well][width field][style well]`.
 *
 * The scrubbable DimensionField over the width IS the row (the padding
 * row's posture: its grip is the row's leading provenance slot, its
 * scrub label the row label, and a consumer's tool (the Border row's ✎,
 * the door to the per-side ring) rides its `tools` slot); the color
 * well and the style well ride the field's `leading` and `trailing`
 * slots, so the three controls share ONE value column that keeps the
 * panel's shared right edge (inspector.css `.daedalus-stylish-stroke-row`). No
 * sub-control ever renders chips: the value column holds three
 * controls, so the style vocabulary rides a well however small it is
 * (the settled decision), and the width keeps the field whatever
 * surface its space would earn alone.
 *
 * Every write is a STROKE gesture, never a property write: the row
 * hands each sub-control's raw write (`{[property]: value}`, null for
 * unset) to the consumer's `write(properties, shown)` and commits what
 * comes back, so the completion rule (stroke-model.js strokeWrite: a
 * part set on an unset stroke lands solid plus the first width stop,
 * an unset clears the whole stroke) and any layer mapping beside it
 * (the Border row's strokeUniformPatch over own longhands) are the
 * consumer's, and the row stays one primitive over the shorthand
 * strokes and a ring side alike. `shown` is the row's draft-layered
 * values (useGesture over the consumer's `values`), so a completion
 * reads the stroke as the last gesture left it, before the round trip
 * settles. The color lane's live picker stages through the same
 * mapping, so the canvas wears the COMPLETED stroke while the chooser
 * is dragged (a color alone on an unset border paints nothing).
 *
 * The color well is the shared assembly (color-well.js useColorWell
 * over the stroke's color part: the palette or the guide's closed
 * offer, the picker lane, the mint row) with the stroke's color face
 * as its state (strokeState's per-part read: the lit stop, or the
 * painted literal face for an inline color). The style well is the
 * swatch well's posture over the style vocabulary (the built-in four
 * or the guide's `allowed:` list), each stop faced as the line style
 * itself; a set style the offer lacks draws as its own line when the
 * keyword paints one (groove, inset) and as the honest "?" face
 * otherwise (none, auto).
 *
 * Provenance per part: the field's grip (the row's leading mark) tells
 * the WIDTH's origin story (the ring cell's hue follows its width, the
 * same rule), and each well wears its own part's origin as the dot on
 * its corner and its hover sentence, so a skin-coloured, element-widened
 * border reads as two stories on one row. Each part's GHOST comes from
 * the same read as its mark (choice-row-model.js originFor's `ghost`:
 * the raw resting value, the skin's default or the Base value at a
 * pose): the width field's placeholder, the style well's muted line
 * glyph, the colour well's muted paint, so an unset part beneath a lit
 * mark is never blank. Right-click on a well opens
 * that part's row menu (the doorways: drive, open the supplying
 * document, unset, edit the skin); on the row it opens the width's,
 * with the width field's token items. Unset through any doorway is the
 * stroke unset (the whole stroke clears), the completion rule's inverse.
 *
 * Pure client over payload data and the widget contract, so it renders
 * in the satellite unchanged.
 *
 * @param {object} props
 * @param {?object} props.guide
 * @param {object} props.materials
 * @param {object} props.model
 *   The widget model (key, condition, entry, provenance, constraints,
 *   propertyDefinitions).
 * @param {string} props.label
 *   The row label.
 * @param {{width: string, style: string, color: string}} props.parts
 *   The stroke's property names (stroke-model.js strokeParts).
 * @param {{width: Array, style: Array, color: Array}} props.stops
 *   One stop row per part (propertyStops for each).
 * @param {Object<string, ?string>} props.values
 *   The stroke's COMMITTED values keyed by the parts' property names
 *   (the effective stroke at the pose; the uniform read for the Border
 *   row, a side's longhands for a ring editor).
 * @param {Function} props.write
 *   (properties, shown) → the update_style patch one gesture commits,
 *   or {} for a gesture that changes nothing.
 * @param {?object} [props.tools]
 *   The field's tools slot (the Border row's pencil).
 * @param {?object} [props.source]
 *   The state source (the mint row commits through it).
 * @param {?object} [props.swatches]
 *   The swatch grants ({canCreate}).
 * @param {Function} props.onPreview
 * @param {Function} props.onRelease
 * @param {Function} props.onCommit
 * @param {?Function} [props.onPickCondition]
 * @param {?Function} [props.onOpenDocument]
 */
export const StrokeRow = ({
  guide,
  materials,
  model,
  label,
  parts,
  stops,
  values,
  write,
  tools = null,
  source = null,
  swatches = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const gesture = useGesture(
    { key: `${model.key}::${parts.width}`, values },
    onPreview,
    onCommit,
  );
  const shown = gesture.values;
  const stroke = strokeState(shown, parts, stops);
  const menu = useMenu();

  const patchFor = (properties) => write(properties, shown);
  const commit = (properties) => {
    const patch = patchFor(properties);
    if (Object.keys(patch).length) {
      gesture.pick(patch);
    }
  };

  // The width field speaks the field contract through the stroke
  // mapping: preview per input, one commit on settle, the emptied field
  // clearing the whole stroke.
  const field = useFieldGesture(
    shown[parts.width] ?? '',
    parts.width,
    (property, value) => {
      Object.entries(patchFor({ [property]: value })).forEach(([name, next]) =>
        onPreview(name, next),
      );
    },
    (properties) => onRelease(patchFor(properties)),
    (properties) => {
      const patch = patchFor(properties);
      return Object.keys(patch).length ? onCommit(patch) : Promise.resolve();
    },
  );

  const widthDefinition = model.propertyDefinitions?.[parts.width] ?? {};
  const widthConstraints = model.constraints?.[parts.width] ?? {};
  const widthSpace = valueSpaceFor(
    guide,
    materials,
    parts.width,
    widthDefinition,
    widthConstraints,
    model.condition,
  );

  const origins = Object.fromEntries(
    STROKE_PARTS.map((part) => [
      part,
      model.provenance
        ? originFor({
            guide,
            materials,
            condition: model.condition,
            property: parts[part],
            entry: model.entry,
            provenance: model.provenance,
          })
        : null,
    ]),
  );
  const onDoorway = (part) => (doorway) => {
    switch (doorway.id) {
      case 'drive':
        onPickCondition?.(doorway.condition);
        break;
      case 'document':
        onOpenDocument?.(doorway.reference, doorway.condition);
        break;
      case 'unset':
        if (part === 'width') {
          field.settle('');
        } else {
          commit({ [parts[part]]: null });
        }
        break;
      case 'skin':
        window.open(doorway.url, '_blank', 'noopener');
        break;
      default:
        break;
    }
  };
  const openMenu = (event, part, tokens, face) => {
    const spec = rowMenu({
      origin: origins[part],
      tokens,
      face,
      onDoorway: onDoorway(part),
      onCondition: onPickCondition,
    });
    if (spec && menu.open({ x: event.clientX, y: event.clientY, ...spec })) {
      event.preventDefault();
      event.stopPropagation();
    }
  };

  const color = useColorWell({
    model,
    property: parts.color,
    guide,
    materials,
    values: shown,
    stage: (properties) => gesture.stage(patchFor(properties)),
    onPreview,
    state: () => ({
      ...stroke.parts.color,
      literal: stroke.parts.color.custom
        ? inlineLiteral(materials, stroke.parts.color.value)
        : null,
    }),
    onPick: (stop) => commit({ [parts.color]: stop ? stop.value : null }),
    canMint: Boolean(swatches?.canCreate),
    source,
    ghost: origins.color?.ghost ?? null,
  });
  const style = useStyleWell({
    stops: stops.style ?? [],
    state: stroke.parts.style,
    ghost: origins.style?.ghost ?? null,
    guide,
    materials,
    condition: model.condition,
    label: Drupal.t('@label style', { '@label': label }),
    onPick: (stop) => commit({ [parts.style]: stop ? stop.value : null }),
  });

  // A closed width space minting nothing hides the row unless the stroke
  // is set (the choice row's rule): there is nothing legal to offer.
  if (
    rowHidden(widthSpace, shown[parts.width], widthDefinition) &&
    !stroke.set
  ) {
    return null;
  }

  const invalid = field.invalid || gesture.invalid;
  const rowClasses = [
    'daedalus-stylish-inspector__widget-row',
    'daedalus-stylish-stroke-row',
    invalid ? 'daedalus-stylish-inspector__widget-row--invalid' : '',
  ]
    .filter(Boolean)
    .join(' ');
  const originStyle = (part) => ({
    '--daedalus-stylish-origin': origins[part]?.color ?? 'initial',
  });
  const onRowContextMenu = (event) =>
    openMenu(
      event,
      'width',
      tokenItems(guide, materials, model.condition, widthSpace, field.shown, {
        onPreview: field.preview,
        onSettle: field.settle,
      }),
      wearing(guide, materials, widthSpace, field.shown, model.condition)
        .face || field.shown,
    );

  return (
    <Fragment>
      <div
        className={rowClasses}
        style={originStyle('width')}
        onContextMenu={onRowContextMenu}
      >
        <DimensionField
          label={label}
          value={field.shown}
          property={parts.width}
          definition={widthDefinition}
          constraints={widthConstraints}
          guide={guide}
          materials={materials}
          condition={model.condition}
          placeholder={origins.width?.ghost ?? null}
          invalid={field.invalid}
          gripTitle={origins.width?.title}
          tools={tools}
          leading={
            <span
              className="daedalus-stylish-stroke-row__part daedalus-stylish-stroke-row__part--color"
              style={originStyle('color')}
              title={origins.color?.title}
              onContextMenu={(event) =>
                openMenu(event, 'color', [], stroke.parts.color.value ?? '')
              }
            >
              {color.well}
              {/* The hidden picker rides the well's positioned wrapper,
                  not the popover: the lane closes the popover before it
                  opens the chooser, and the chooser's events must still
                  land on a mounted input. */}
              {color.input}
            </span>
          }
          trailing={
            <span
              className="daedalus-stylish-stroke-row__part daedalus-stylish-stroke-row__part--style"
              style={originStyle('style')}
              title={origins.style?.title}
              onContextMenu={(event) =>
                openMenu(event, 'style', [], stroke.parts.style.value ?? '')
              }
            >
              {style.well}
            </span>
          }
          onPreview={field.preview}
          onSettle={field.settle}
        />
      </div>
      {color.popover}
      {style.popover}
    </Fragment>
  );
};

/**
 * The border-style keywords a line glyph can draw: the line itself in
 * that style. The rest (none, hidden, auto) paint no line the glyph
 * could show, so a set value among them wears the honest "?" face.
 */
const DRAWN_STYLES = [
  'solid',
  'dashed',
  'dotted',
  'double',
  'groove',
  'ridge',
  'inset',
  'outset',
];

/**
 * A line drawn in a border style, and optionally a color (the ring's
 * cell face paints the side's own color; the wells keep currentColor).
 */
export const StyleGlyph = ({ value, color = null }) => (
  <span
    className="daedalus-stylish-stroke-glyph"
    style={{ borderTopStyle: value, borderTopColor: color ?? undefined }}
    aria-hidden="true"
  />
);

/**
 * The keyword a value's line glyph draws, or null when it paints no
 * line (none, hidden, an unknown word).
 */
const drawnStyle = (value) => {
  const keyword = normalizeValue(value);
  return DRAWN_STYLES.includes(keyword) ? keyword : null;
};

/**
 * The style well + its popover: the swatch well's posture (a face
 * button wearing the current value, the offer behind it) over a line
 * style vocabulary. The face is the swatch well's own derivation
 * (swatch-well-model.js wellFace, the set value's drawable keyword as
 * its literal): the lit stop's line, the set value's own line when it
 * paints one the offer lacks, the "?" custom face otherwise, the muted
 * GHOST line when nothing is set but a resting style reaches the row
 * (the matching stop's, else the raw keyword's when it draws one; titled
 * "Unset; X applies"), the slashed empty face when unset with nothing
 * resting. The popover is the choice listbox (choice-row.js ChoiceList:
 * the roving keyboard story, the lit row unsets) with each row's line
 * drawn before its name. A hook, not a component, for the two-slot
 * reason: the well sits inline on the row while the popover floats over
 * the rows below.
 *
 * @param {object} options
 * @param {Array} options.stops
 *   The style vocabulary's stop table.
 * @param {{selected: ?string, custom: boolean, value: ?string}} options.state
 *   The style part's mapped state (stroke-model.js strokeState).
 * @param {?string} [options.ghost]
 *   The RAW resting style beneath an unset well (originFor's `ghost`),
 *   or null. Ignored while the state is set.
 * @param {?object} [options.guide]
 * @param {?object} [options.materials]
 * @param {string} [options.condition]
 *   The editing compound the ghost's title faces at.
 * @param {string} options.label
 * @param {Function} options.onPick
 */
const useStyleWell = ({
  stops,
  state,
  ghost = null,
  guide = null,
  materials = null,
  condition = 'default',
  label,
  onPick,
}) => {
  const { open, setOpen, triggerRef, triggerProps, portal } = usePopover();
  const face = wellFace(
    stops,
    { ...state, literal: state.custom ? drawnStyle(state.value) : null },
    ghost,
  );
  const lit = face.kind === 'stop' ? face.stop : null;
  const glyph = (() => {
    switch (face.kind) {
      case 'stop':
        return face.stop.value;
      case 'literal':
        return face.literal;
      case 'ghost':
        return face.stop !== null ? face.stop.value : drawnStyle(face.literal);
      default:
        return null;
    }
  })();
  const title = (() => {
    switch (face.kind) {
      case 'stop':
        return Drupal.t(lit.label);
      case 'literal':
      case 'custom':
        return Drupal.t(
          'A line style these choices do not offer is set: !value. Pick a style to replace it.',
          { '!value': state.value },
        );
      case 'ghost':
        return Drupal.t('Unset; !value applies', {
          '!value': ghostFace(guide, materials, ghost, condition),
        });
      default:
        return Drupal.t('Pick a line style');
    }
  })();
  const pickAndClose = (stop) => {
    setOpen(false);
    triggerRef.current?.focus();
    onPick(stop);
  };
  const well = (
    <button
      {...triggerProps}
      type="button"
      className={`daedalus-stylish-swatch-well daedalus-stylish-stroke-well daedalus-stylish-swatch-well--${face.kind}${open ? ' daedalus-stylish-swatch-well--open' : ''}`}
      aria-label={label}
      title={title}
    >
      {/* The ghost's glyph rides the same modifier class the swatch
          well's ghost paint does (inspector.css: a dashed ring, the line
          muted), so an unset well shows the style that applies without
          claiming one is set. */}
      {glyph !== null && <StyleGlyph value={glyph} />}
    </button>
  );
  const offer = stops.map((stop) => ({ ...stop, text: Drupal.t(stop.label) }));
  const popover = !open
    ? null
    : portal(
        <div className="daedalus-stylish-swatch-popover daedalus-stylish-choice-popover">
          <ChoiceList
            offer={offer}
            selected={lit?.id ?? null}
            label={label}
            glyph={(stop) => <StyleGlyph value={stop.value} />}
            onPick={pickAndClose}
          />
        </div>,
      );
  return { open, well, popover };
};
