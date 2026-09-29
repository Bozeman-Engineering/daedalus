import { h } from 'preact';
import { useState } from 'preact/hooks';
import { controlWidgets, litValues, withheldReasonFor } from '../../controls.js';
import { normalizeValue, propertyStops } from '../../widget-values.js';
import { stackAt } from '../../style-stack.js';
import { valueSpaceFor } from '../../value-space.js';
import { colorFace } from '../../color-field-model.js';
import {
  STROKES,
  STROKE_PARTS,
  strokeParts,
  strokeSideLonghands,
  strokeSideValues,
  strokeSides,
  strokeSidesPatch,
  strokeState,
  strokeUniformPatch,
  strokeWrite,
} from '../lib/stroke-model.js';
import {
  SIDES,
  familyLonghand,
  grantedSides,
  uniformFieldText,
} from '../lib/box-model.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { StrokeRow, StyleGlyph } from '../lib/stroke-row.js';
import { BoxModelDiagram, cellText } from '../lib/box-model-diagram.js';
import { useDiagramHost } from '../lib/diagram-host.js';
import { PencilToggle } from '../lib/pencil.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';
import { ownLayerAt, writeLayer } from '../lib/write-layer.js';

const BORDER_LONGHANDS = strokeSideLonghands(SIDES);

/**
 * corners_border: Corners, Border, Outline and Outline offset.
 *
 * Corners writes the single border-radius property through the choice
 * row (lit from the stack's winner at the pose, controls.js litValues)
 * and owns nothing beyond it: the surface adapts to the guide's
 * value space (the theme's radius scale when one closes or shapes
 * border-radius, the built-in radius table, widget-values.js FALLBACKS,
 * as the detented fallback covering the radii between and beyond the
 * presets).
 *
 * Border and Outline are STROKE rows (lib/stroke-row.js: color well +
 * width field + style well over one stroke), each write passing through
 * the completion rule (stroke-model.js strokeWrite) so a part set on an
 * unset stroke lands a whole stroke in one op (style solid, the first
 * width stop, the color as picked) and an unset clears the whole
 * stroke, retiring only what it has set.
 *
 * The Border row is the SHORTHAND row over the three border families,
 * and its read and write are three families wide (the padding
 * invariant: shorthand and authored longhands never coexist on the own
 * layer). It reads the four sides through strokeSides (each family's
 * uniformState, the per-side read of the model's stacks at the editing
 * compound): a family one value covers on all four sides, however
 * spelled and whichever layer delivers it (a skin's border is lit like
 * an own one, the display rule), faces as that value; a per-side family
 * (a skin's border-left, a ring edit) faces as the honest per-side text
 * in the width field and the custom face in the wells, and typing or
 * picking over it makes the sides equal, the padding row's rule. Its
 * Unset exists while the own layer spells any part at the pose, the
 * side longhands included (stroke-row.js). Its write is
 * strokeUniformPatch: each family's shorthand with any own longhands
 * collapsed into it in the same op, so a ring-expanded border collapses
 * back the moment the row speaks. The ✎ on the row is the door to the
 * per-side RING, the box-model diagram mounted beneath the row over the
 * border-width family with the diagram's three seams filled for a
 * stroke: every cell gesture (a width scrub, an arrow step, an
 * Alt-click clear) is the side's stroke write through the completion
 * rule and strokeSidesPatch, so the first per-side edit expands all
 * three own shorthands into the twelve longhands in one op and an
 * evened-out border collapses back to the three; a cell faces as the
 * side's line glyph (its style, in its color) beside its width, the
 * computed line from the canvas where nothing styles the side; and the
 * side editor beneath the ring is the stroke row itself over the side's
 * three longhands. The ring's highlight claims a touched side's three
 * longhands together (one border band on the canvas paints for any of
 * them), through the host seams the padding ring shares
 * (lib/diagram-host.js).
 *
 * Outline has no sides and no families: its row reads the four outline
 * properties' winners at the pose and writes them directly. The Outline
 * offset row (a field over outline-offset, negative-capable, the px
 * steps as its detents) renders only while the outline stroke or the
 * offset itself is delivered: an offset with no outline is inert, and
 * a shown value always stays visible (the choice row's rule).
 *
 * The rows read the served grant by name (model.granted): a target
 * granting the border families gets the Border row, one granting the
 * outline properties gets the Outline rows, and no row decides its
 * offer from another's. Under a write address that withholds a row's
 * properties (controls.js withheldReasonFor: a stroke row judges its
 * three parts together, since a stroke writes whole) the row renders
 * DISABLED with the roster's reason as its title, the ring's door shut;
 * what the own key never grants stays hidden.
 *
 * No dynamic gate on purpose (the widget matrix): any box can carry
 * corners, a border and an outline, so sanction alone decides where the
 * control appears.
 */
const CornersBorder = ({
  model,
  guide,
  materials,
  styleMap,
  source = null,
  swatches = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const [ringOpen, setRingOpen] = useState(false);
  const granted = model.granted ?? [];
  const cornersWithheld = model.withheld?.['border-radius'] ?? null;
  const borderWithheld = withheldReasonFor(model, Object.values(STROKES.border));
  const outlineWithheld = withheldReasonFor(model, Object.values(STROKES.outline));
  const offsetWithheld = model.withheld?.['outline-offset'] ?? null;

  const stopsOf = (parts) =>
    Object.fromEntries(
      STROKE_PARTS.map((part) => [
        part,
        propertyStops(
          guide,
          materials,
          parts[part],
          model.propertyDefinitions?.[parts[part]],
          model.constraints?.[parts[part]],
        ),
      ]),
    );

  const lit = litValues(model.reads);
  const conditions = guide?.conditions ?? {};
  // The write layer a patch expands and collapses, read PER WRITE
  // (lib/write-layer.js): the own values at the editing compound of the
  // box THAT WRITE lands on (its set members at the model's landing, the
  // stroke unset at the retired name's key). The lit values are rebuilt
  // in owned-property order and cannot say which spelling the own layer
  // carries. The reads are the model's per-property stacks.
  const ownFor = (write) => writeLayer(styleMap, model, write);

  const borderStops = stopsOf(STROKES.border);
  const sides = strokeSides(model.stacks, model.condition, conditions);
  const borderValues = Object.fromEntries(
    STROKE_PARTS.map((part) => {
      const name = STROKES.border[part];
      const text = uniformFieldText(
        sides.families[part],
        stackAt(model.stacks[name], name, model.condition, conditions).winner
          ?.value,
      );
      return [name, text === '' ? null : text];
    }),
  );
  const borderWrite = (properties, shown) => {
    const write = strokeWrite(properties, shown, STROKES.border, borderStops);
    return strokeUniformPatch(ownFor(write), write);
  };

  const outlineStops = stopsOf(STROKES.outline);
  const outlineValues = Object.fromEntries(
    STROKE_PARTS.map((part) => [
      STROKES.outline[part],
      normalizeValue(lit[STROKES.outline[part]]) === null
        ? null
        : String(lit[STROKES.outline[part]]).trim(),
    ]),
  );
  const outlineWrite = (properties, shown) =>
    strokeWrite(properties, shown, STROKES.outline, outlineStops);
  const outline = strokeState(outlineValues, STROKES.outline, outlineStops);
  const offsetOffered =
    granted.includes('outline-offset') &&
    (outline.set || normalizeValue(lit['outline-offset']) !== null);

  const rowProps = {
    guide,
    materials,
    model,
    source,
    swatches,
    onPreview,
    onRelease,
    onCommit,
    onPickCondition,
    onOpenDocument,
  };

  // --- the ring's seams.
  const borderGranted = granted.includes('border-width');
  const ringOffered = borderGranted && borderWithheld === null && ringOpen;
  // The ring is offered only while the width shorthand is granted, so
  // the family's write key is the shorthand's: the highlight claims
  // there, the probe reads the home box.
  const host = useDiagramHost({
    source,
    model,
    open: ringOffered,
    property: 'border-width',
    longhands: BORDER_LONGHANDS,
  });
  const ringComputed = host.probe
    ? Object.fromEntries(
        SIDES.map((side) => [
          side,
          host.probe.values[familyLonghand('border-width', side)],
        ]),
      )
    : null;
  // A cell gesture's write: the side's width through the completion rule
  // (a scrub on a bare side lands solid too; a clear retires the side's
  // whole stroke), folded through the three-family expansion against
  // the layer the sides' writes land on. The empty gesture is the
  // diagram's blocked probe: it writes nothing, so it asks the layer
  // where a side's stroke would land (the twelve longhands as the set).
  const ringPatch = (bySide) => {
    const writes = Object.fromEntries(
      Object.entries(bySide ?? {}).map(([side, value]) => {
        const parts = strokeParts('border', side);
        return [
          side,
          strokeWrite(
            { [parts.width]: value },
            strokeSideValues(sides, side),
            parts,
            stopsOf(parts),
          ),
        ];
      }),
    );
    const written = Object.assign({}, ...Object.values(writes));
    const own = Object.keys(written).length
      ? ownFor(written)
      : ownLayerAt(styleMap, model.landing(BORDER_LONGHANDS), model.condition);
    return strokeSidesPatch(own, writes);
  };
  // The cell face: the side's line (its style in its color, whoever
  // supplies them; the canvas's computed line where no layer does)
  // beside the width text.
  const ringFace = (cell) => {
    const parts = strokeParts('border', cell.side);
    const read = strokeSideValues(sides, cell.side);
    const style =
      read[parts.style] ?? host.probe?.values[parts.style] ?? 'none';
    const color = read[parts.color] ?? host.probe?.values[parts.color] ?? null;
    const space = valueSpaceFor(
      guide,
      materials,
      parts.color,
      model.propertyDefinitions?.[parts.color] ?? null,
      model.constraints?.[parts.color] ?? null,
      model.condition,
    );
    const paint =
      color === null
        ? null
        : colorFace(guide, materials, space, color, model.condition).paint;
    return (
      <span className="daedalus-stylish-boxmodel__face">
        <StyleGlyph value={style} color={paint} />
        {cellText(cell)}
      </span>
    );
  };
  // The side editor: the stroke row over the side's three longhands,
  // its writes folded through the same expansion.
  const ringEditor = (cell, label) => {
    const parts = strokeParts('border', cell.side);
    const stops = stopsOf(parts);
    return (
      <StrokeRow
        {...rowProps}
        label={label}
        parts={parts}
        stops={stops}
        values={strokeSideValues(sides, cell.side)}
        write={(properties, shown) => {
          const write = strokeWrite(properties, shown, parts, stops);
          return strokeSidesPatch(ownFor(write), { [cell.side]: write }) ?? {};
        }}
      />
    );
  };

  return (
    <div className={widgetClasses('corners-border', false)}>
      <ChoiceRow
        guide={guide}
        materials={materials}
        source={source}
        highlight={styleTarget(model.keys['border-radius'])}
        condition={model.condition}
        label={Drupal.t('Corners')}
        property="border-radius"
        value={lit['border-radius']}
        definition={model.propertyDefinitions?.['border-radius'] ?? {}}
        constraints={model.constraints?.['border-radius'] ?? {}}
        stack={model.stacks['border-radius']}
        read={model.reads['border-radius']}
        disabled={cornersWithheld !== null}
        disabledTitle={cornersWithheld}
        onPickCondition={onPickCondition}
        onOpenDocument={onOpenDocument}
        onPreview={onPreview}
        onRelease={onRelease}
        onCommit={onCommit}
      />
      {borderGranted && (
        <StrokeRow
          {...rowProps}
          label={Drupal.t('Border')}
          parts={STROKES.border}
          stops={borderStops}
          values={borderValues}
          write={borderWrite}
          disabled={borderWithheld !== null}
          disabledTitle={borderWithheld}
          // The ring's door: the box-model diagram over the three border
          // families mounts beneath this row while the toggle is open.
          tools={
            borderWithheld !== null ? null : (
              <PencilToggle
                open={ringOpen}
                onToggle={() => setRingOpen((current) => !current)}
                label={Drupal.t('Edit border per side')}
                openTitle={Drupal.t('Hide the border diagram')}
                closedTitle={Drupal.t('Edit each side of the border')}
              />
            )
          }
        />
      )}
      {ringOffered && (
        <BoxModelDiagram
          guide={guide}
          materials={materials}
          condition={model.condition}
          stacks={model.stacks}
          computed={ringComputed}
          bases={host.probe?.bases ?? null}
          center={host.center}
          family="border-width"
          name="border"
          label={Drupal.t('Border')}
          patchFor={ringPatch}
          renderCellFace={ringFace}
          renderEditor={ringEditor}
          definitions={model.propertyDefinitions ?? {}}
          constraints={model.constraints ?? {}}
          sides={grantedSides(granted, 'border-width')}
          onPreview={onPreview}
          onRelease={onRelease}
          onCommit={onCommit}
          onHighlight={(touched) =>
            host.highlight(touched ? strokeSideLonghands(touched) : null)
          }
        />
      )}
      {granted.includes('outline-width') && (
        <StrokeRow
          {...rowProps}
          label={Drupal.t('Outline')}
          parts={STROKES.outline}
          stops={outlineStops}
          values={outlineValues}
          write={outlineWrite}
          disabled={outlineWithheld !== null}
          disabledTitle={outlineWithheld}
        />
      )}
      {offsetOffered && (
        <ChoiceRow
          guide={guide}
          materials={materials}
          source={source}
          highlight={styleTarget(model.keys['outline-offset'])}
          condition={model.condition}
          label={Drupal.t('Outline offset')}
          property="outline-offset"
          value={lit['outline-offset']}
          definition={model.propertyDefinitions?.['outline-offset'] ?? {}}
          constraints={model.constraints?.['outline-offset'] ?? {}}
          stack={model.stacks['outline-offset']}
          read={model.reads['outline-offset']}
          disabled={offsetWithheld !== null}
          disabledTitle={offsetWithheld}
          onPickCondition={onPickCondition}
          onOpenDocument={onOpenDocument}
          onPreview={onPreview}
          onRelease={onRelease}
          onCommit={onCommit}
        />
      )}
    </div>
  );
};

controlWidgets.register('corners_border', CornersBorder);
