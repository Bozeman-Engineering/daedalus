import { h } from 'preact';
import { useState } from 'preact/hooks';
import { controlWidgets } from '../../controls.js';
import { normalizeValue, propertyStops } from '../../widget-values.js';
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
import { widgetClasses } from '../lib/widget-classes.js';

const BORDER_LONGHANDS = strokeSideLonghands(SIDES);

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * corners_border: Corners, Border, Outline and Outline offset.
 *
 * Corners writes the single border-radius property through the choice
 * row and owns nothing beyond it: the surface adapts to the guide's
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
 * uniformState over the own and resolved values at the editing
 * compound): a family one value covers on all four sides, however
 * spelled, faces as that value; a per-side family (a skin's
 * border-left, a ring edit) faces as the honest per-side text in the
 * width field and the custom face in the wells, and typing or picking
 * over it makes the sides equal, the padding row's rule. Its write is
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
 * Outline has no sides and no families: its row reads and writes the
 * four outline properties directly. The Outline offset row (a field
 * over outline-offset, negative-capable, the px steps as its detents)
 * renders only while the outline stroke or the offset itself is set:
 * an offset with no outline is inert, and a set value always stays
 * visible (the choice row's rule).
 *
 * The rows read the served grant by name (model.granted): a target
 * granting the border families gets the Border row, one granting the
 * outline properties gets the Outline rows, and no row decides its
 * offer from another's.
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

  // The two style layers the per-side logic reads, ORDER-PRESERVING:
  // the element's own values and the resolved (variant-layered) ones,
  // both at the EDITING COMPOUND (model.condition, where the pipeline's
  // writes land); model.values is rebuilt in owned-property order and
  // cannot say which spelling the own layer carries.
  const ownEntry = styleMap?.[model.key];
  const own =
    isPlainObject(ownEntry) && isPlainObject(ownEntry[model.condition])
      ? ownEntry[model.condition]
      : null;
  const effective = isPlainObject(model.entry?.[model.condition])
    ? model.entry[model.condition]
    : null;

  const borderStops = stopsOf(STROKES.border);
  const sides = strokeSides(own, effective, {
    suppliers: model.provenance?.suppliers ?? null,
    skinEntry: model.provenance?.skinEntry ?? null,
    declaredEntry: model.provenance?.declaredEntry ?? null,
    condition: model.condition,
    conditions: guide?.conditions ?? {},
  });
  const borderValues = Object.fromEntries(
    STROKE_PARTS.map((part) => {
      const name = STROKES.border[part];
      const text = uniformFieldText(
        sides.families[part],
        own?.[name] ?? effective?.[name],
      );
      return [name, text === '' ? null : text];
    }),
  );
  const borderWrite = (properties, shown) =>
    strokeUniformPatch(
      own,
      strokeWrite(properties, shown, STROKES.border, borderStops),
    );

  const outlineStops = stopsOf(STROKES.outline);
  const outlineValues = Object.fromEntries(
    STROKE_PARTS.map((part) => [
      STROKES.outline[part],
      normalizeValue(model.values[STROKES.outline[part]]) === null
        ? null
        : String(model.values[STROKES.outline[part]]).trim(),
    ]),
  );
  const outlineWrite = (properties, shown) =>
    strokeWrite(properties, shown, STROKES.outline, outlineStops);
  const outline = strokeState(outlineValues, STROKES.outline, outlineStops);
  const offsetOffered =
    granted.includes('outline-offset') &&
    (outline.set || normalizeValue(model.values['outline-offset']) !== null);

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
  const ringOffered = borderGranted && ringOpen;
  const host = useDiagramHost({
    source,
    model,
    open: ringOffered,
    longhands: BORDER_LONGHANDS,
    identity: JSON.stringify([model.key, model.condition, own, effective]),
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
  // whole stroke), folded through the three-family expansion. The empty
  // gesture is the diagram's blocked probe.
  const ringPatch = (bySide) =>
    strokeSidesPatch(
      own,
      Object.fromEntries(
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
      ),
    );
  // The cell face: the side's line (its style in its color, whoever
  // supplies them; the canvas's computed line where no layer does)
  // beside the width text.
  const ringFace = (cell) => {
    const parts = strokeParts('border', cell.side);
    const read = strokeSideValues(sides, cell.side, { ghosts: true });
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
        write={(properties, shown) =>
          strokeSidesPatch(own, {
            [cell.side]: strokeWrite(properties, shown, parts, stops),
          }) ?? {}
        }
      />
    );
  };

  return (
    <div className={widgetClasses('corners-border', false)}>
      <ChoiceRow
        guide={guide}
        materials={materials}
        condition={model.condition}
        label={Drupal.t('Corners')}
        property="border-radius"
        value={model.values['border-radius']}
        definition={model.propertyDefinitions?.['border-radius'] ?? {}}
        constraints={model.constraints?.['border-radius'] ?? {}}
        entry={model.entry}
        provenance={model.provenance}
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
          // The ring's door: the box-model diagram over the three border
          // families mounts beneath this row while the toggle is open.
          tools={
            <PencilToggle
              open={ringOpen}
              onToggle={() => setRingOpen((current) => !current)}
              label={Drupal.t('Edit border per side')}
              openTitle={Drupal.t('Hide the border diagram')}
              closedTitle={Drupal.t('Edit each side of the border')}
            />
          }
        />
      )}
      {ringOffered && (
        <BoxModelDiagram
          guide={guide}
          materials={materials}
          condition={model.condition}
          own={own}
          effective={effective}
          provenance={model.provenance ?? null}
          computed={ringComputed}
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
        />
      )}
      {offsetOffered && (
        <ChoiceRow
          guide={guide}
          materials={materials}
          condition={model.condition}
          label={Drupal.t('Outline offset')}
          property="outline-offset"
          value={model.values['outline-offset']}
          definition={model.propertyDefinitions?.['outline-offset'] ?? {}}
          constraints={model.constraints?.['outline-offset'] ?? {}}
          entry={model.entry}
          provenance={model.provenance}
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
