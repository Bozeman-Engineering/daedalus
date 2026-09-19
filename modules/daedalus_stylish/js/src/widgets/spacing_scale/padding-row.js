import { h, Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { valueSpaceFor, wearing } from '../../value-space.js';
import { rowMenu, tokenItems } from '../../provenance-model.js';
import { useMenu } from '../../menu.js';
import { PencilToggle } from '../lib/pencil.js';
import { DimensionField } from '../lib/dimension-field.js';
import { useFieldGesture } from '../lib/use-gesture.js';
import { BoxModelDiagram } from '../lib/box-model-diagram.js';
import { useDiagramHost } from '../lib/diagram-host.js';
import {
  SIDES,
  familyLonghand,
  grantedSides,
  sideLonghands,
  subsetCommit,
  uniformCommit,
  uniformFieldText,
  uniformState,
} from '../lib/box-model.js';

const PADDING_LONGHANDS = sideLonghands('padding', SIDES);

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The spacing widget's padding row: the shorthand field + ✎ + the
 * box-model diagram.
 *
 * The scrubbable DimensionField IS the row (the choice row's field posture: its
 * grip is the row's leading provenance slot and its scrub label the row
 * label; the ✎ rides the field's `tools` slot between label and value
 * column, so the value column keeps the panel's shared right edge),
 * writing the SHORTHAND `padding` over
 * the value-space spine — closed vocabularies render the stop select
 * with detent scrub, detented ones the free field with sticky magnets,
 * free ones today's field, and the row never has to know which. Every
 * settle collapses any own longhands back into the shorthand
 * (uniformCommit, so shorthand and authored longhands never coexist);
 * under a side-subset grant (a container's vertical rhythm) the field
 * writes the granted side longhands in one patch instead (subsetCommit),
 * and the field's shown value is judged over those sides alone.
 *
 * The ✎ survives here ONLY because it opens something the field cannot
 * be: the per-side box-model diagram. The old pencil-gate special case
 * (show the pencil even under a closed vocabulary, because the diagram
 * degrades to stop pickers where reveal fields would hide) is gone: the
 * diagram's side cells adapt to the same value space the field reads, so
 * there is nothing to gate — the pencil shows whenever the row does, and
 * a closed row with an EMPTY vocabulary hides whole unless a committed
 * value keeps it visible (the choice row's rule, choice-row-model.js rowHidden).
 *
 * The field's value derivation is longhand-aware (uniformState): four
 * equal sides show their one value however they are spelled, per-side
 * padding degrades the field to the honest shorthand/per-side text
 * (typing over it makes all sides equal — the diagram is the per-side
 * surface), and a whole-reference shorthand wears the blessed chrome
 * through the field's own ref→display read.
 *
 * Host seams (through the source, satellite-safe; lib/diagram-host.js
 * useDiagramHost, shared with the border ring): the diagram's computed
 * placeholders and center readout come from source.readCanvas (null
 * off-canvas — cells show '–'), and its onHighlight sides map to
 * padding longhands claimed on the per-source highlight claim stack
 * (highlight-claims.js), the arbitrated way into
 * source.setCanvasHighlight every row consumer shares.
 *
 * Right-click on the row opens the row menu (provenance-model.js
 * rowMenu) over the field's token items, the advanced rows' token half.
 * The shorthand row itself tells no origin story (four sides can have
 * four origins); the diagram's cells carry it, each in its supplying
 * layer's hue, from the model's provenance tables.
 */
export const PaddingRow = ({
  model,
  guide,
  materials,
  styleMap,
  source,
  onPreview,
  onRelease,
  onCommit,
}) => {
  const [open, setOpen] = useState(false);
  const menu = useMenu();

  // The offer is the served roster's answer, read whole: model.granted is
  // the widget's owned names the target grants, and the roster grants the
  // `padding` shorthand iff every side longhand is granted (the server's
  // family rule, VocabularyRosterBuilder), so the row reads the shorthand
  // by name and never decides it from the sides. A target whose grant
  // withholds the shorthand and a side subset (a container's vertical
  // rhythm; the horizontal inset is the width story) gets a row over
  // exactly the granted sides: the field writes the granted side longhands
  // in one patch (subsetCommit), its value is judged over those sides
  // alone, and the diagram renders the withheld sides inert. A target
  // granting the whole family keeps the shorthand row, at every placement
  // depth alike (a grant is a function of the address alone; placement
  // never enters it).
  const granted = model.granted;
  const offeredSides = grantedSides(granted, 'padding');
  const shorthandGranted = granted.includes('padding');

  // The two style layers the per-side logic reads, ORDER-PRESERVING:
  // the element's own values and the resolved (variant-layered) ones,
  // both at the EDITING COMPOUND (model.condition — where the pipeline's
  // writes land) — never model.values, which is rebuilt in
  // owned-property order.
  const ownEntry = styleMap?.[model.key];
  const own =
    isPlainObject(ownEntry) && isPlainObject(ownEntry[model.condition])
      ? ownEntry[model.condition]
      : null;
  const effective = isPlainObject(model.entry?.[model.condition])
    ? model.entry[model.condition]
    : null;

  const identity = JSON.stringify([model.key, model.condition, own, effective]);
  const host = useDiagramHost({
    source,
    model,
    open,
    longhands: PADDING_LONGHANDS,
    identity,
  });

  // The field maps the SHORTHAND's value space (it writes it); under a
  // side-subset grant it writes longhands, so its space is the first
  // granted side's, shorthand as fallback.
  const leadLonghand = offeredSides.length
    ? familyLonghand('padding', offeredSides[0])
    : null;
  const fieldConstraints = shorthandGranted
    ? model.constraints?.padding
    : ((leadLonghand ? model.constraints?.[leadLonghand] : undefined) ??
      model.constraints?.padding);
  const fieldDefinition =
    (shorthandGranted
      ? model.propertyDefinitions?.padding
      : ((leadLonghand
          ? model.propertyDefinitions?.[leadLonghand]
          : undefined) ?? model.propertyDefinitions?.padding)) ?? {};
  const fieldProperty =
    shorthandGranted || !leadLonghand ? 'padding' : leadLonghand;
  const space = valueSpaceFor(
    guide,
    materials,
    fieldProperty,
    fieldDefinition,
    fieldConstraints,
    model.condition,
  );

  const uniform = uniformState(own, effective, 'padding', offeredSides);
  const fieldText = uniformFieldText(
    uniform,
    own?.padding ?? effective?.padding,
  );

  // One settle's patch: the shorthand write with own longhands collapsed
  // (uniformCommit), or the granted side longhands under a subset grant.
  // The DimensionField hands over the COMMIT spelling (the reference on a
  // capture), so the patch carries refs where the guide minted them.
  const patchFor = (value) =>
    shorthandGranted
      ? uniformCommit(own, value)
      : subsetCommit(own, offeredSides, value);

  const field = useFieldGesture(
    fieldText,
    'padding',
    (name, value) => {
      Object.entries(patchFor(value)).forEach(([property, staged]) =>
        onPreview(property, staged),
      );
    },
    (properties) => onRelease(patchFor(properties.padding ?? null)),
    (properties) => onCommit(patchFor(properties.padding ?? null)),
  );

  // Nothing padding-shaped granted at all: no row (the widget's gap row
  // may still render on its own).
  if (!shorthandGranted && offeredSides.length === 0) {
    return null;
  }

  // A closed row whose vocabulary is EMPTY hides: there is nothing legal
  // to offer. A committed value is the exception — a set value always
  // stays visible and unsettable (the choice row's rowHidden / hiddenFor rule).
  if (space.kind === 'closed' && space.stops.length === 0 && !uniform.set) {
    return null;
  }

  const rowClasses = `daedalus-stylish-inspector__widget-row${field.invalid ? ' daedalus-stylish-inspector__widget-row--invalid' : ''}`;
  const onContextMenu = (event) => {
    const spec = rowMenu({
      origin: null,
      tokens: tokenItems(
        guide,
        materials,
        model.condition,
        space,
        field.shown,
        { onPreview: field.preview, onSettle: field.settle },
      ),
      face:
        wearing(guide, materials, space, field.shown, model.condition).face ||
        field.shown,
    });
    if (spec && menu.open({ x: event.clientX, y: event.clientY, ...spec })) {
      event.preventDefault();
    }
  };

  // The diagram's placeholder read: per-side computed longhands (the
  // host's probe; null in the satellite / when the target has no canvas
  // element — the diagram shows '–').
  const computed = host.probe
    ? Object.fromEntries(
        SIDES.map((side) => [
          side,
          host.probe.values[familyLonghand('padding', side)],
        ]),
      )
    : null;

  return (
    <Fragment>
      <div className={rowClasses} onContextMenu={onContextMenu}>
        <DimensionField
          label={Drupal.t('Padding')}
          value={field.shown}
          property={fieldProperty}
          definition={fieldDefinition}
          constraints={fieldConstraints ?? {}}
          guide={guide}
          materials={materials}
          condition={model.condition}
          invalid={field.invalid}
          tools={
            <PencilToggle
              open={open}
              onToggle={() => setOpen((current) => !current)}
              label={Drupal.t('Edit padding per side')}
              openTitle={Drupal.t('Hide the padding diagram')}
              closedTitle={Drupal.t('Edit each side of the padding')}
            />
          }
          onPreview={field.preview}
          onSettle={field.settle}
        />
      </div>
      {open && (
        <BoxModelDiagram
          guide={guide}
          materials={materials}
          condition={model.condition}
          own={own}
          effective={effective}
          provenance={model.provenance ?? null}
          computed={computed}
          center={host.center}
          family="padding"
          label={Drupal.t('Padding')}
          definitions={model.propertyDefinitions ?? {}}
          constraints={model.constraints ?? {}}
          sides={offeredSides}
          onPreview={onPreview}
          onRelease={onRelease}
          onCommit={onCommit}
          onHighlight={(sides) =>
            host.highlight(sides ? sideLonghands('padding', sides) : null)
          }
        />
      )}
    </Fragment>
  );
};
