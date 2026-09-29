import { h, Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { valueSpaceFor, wearing } from '../../value-space.js';
import { withheldReasonFor } from '../../controls.js';
import { sidesSentence, tokenItems } from '../../provenance-model.js';
import { stackAt } from '../../style-stack.js';
import { normalizeValue } from '../../widget-values.js';
import { PencilToggle } from '../lib/pencil.js';
import { DimensionField } from '../lib/dimension-field.js';
import { Mark } from '../lib/mark.js';
import { useFieldGesture } from '../lib/use-gesture.js';
import { useRowMenu } from '../lib/use-row-menu.js';
import { BoxModelDiagram } from '../lib/box-model-diagram.js';
import { useDiagramHost } from '../lib/diagram-host.js';
import { ownLayerAt, writeLayer } from '../lib/write-layer.js';
import {
  SIDES,
  familyLonghand,
  grantedSides,
  sideLonghands,
  sidesOrigin,
  sidesPatch,
  subsetCommit,
  uniformCommit,
  uniformFieldText,
  uniformState,
} from '../lib/box-model.js';

const PADDING_LONGHANDS = sideLonghands('padding', SIDES);

/**
 * The spacing widget's padding row: the shorthand field + ✎ + the
 * box-model diagram.
 *
 * The scrubbable DimensionField IS the row (the choice row's field posture: the
 * row's mark leads its scrub label, which is the row label; the ✎ rides
 * the field's `tools` slot between label and value column, so the
 * value column keeps the panel's shared right edge),
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
 * The field's value derivation is longhand-aware (uniformState, the
 * per-side read of the model's stacks): four equal sides show their one
 * value however they are spelled and whichever layer delivers them (a
 * skin's padding is lit like an own one, the display rule), per-side
 * padding degrades the field to the honest shorthand/per-side text
 * (typing over it makes all sides equal — the diagram is the per-side
 * surface), and a whole-reference shorthand wears the blessed chrome
 * through the field's own ref→display read. The emptied field commits
 * the unset only while the own layer spells the padding at the exact
 * pose (useFieldGesture's `own`); over a lower layer's value it
 * releases, since there is nothing here to remove.
 *
 * Host seams (through the source, satellite-safe; lib/diagram-host.js
 * useDiagramHost, shared with the border ring): the diagram's computed
 * placeholders and center readout come from source.readCanvas at the
 * home box (null off-canvas — cells show '–'), and its onHighlight
 * sides map to padding longhands claimed at the field's write key (the
 * shorthand's, or the first granted side's under a subset grant) on the
 * per-source highlight claim stack (highlight-claims.js), the
 * arbitrated way into source.setCanvasHighlight every row consumer
 * shares.
 *
 * Right-click on the row opens the shared row menu (lib/use-row-menu.js)
 * over the field's token items, the advanced rows' token half; the
 * menu's doorways are per property, and four sides can have four
 * origins, so the shorthand row offers none (the diagram's cells are
 * the per-side surface).
 *
 * The row's mark (lib/mark.js) still speaks for the set: its origin is
 * derived from the per-side provenance (box-model.js sidesOrigin over
 * the offered sides): one delivering layer at one pose-relation across
 * the set sides gives the mark that layer's hue, filled when the block
 * is the posed compound; a split gives the topmost layer's hue, hollow,
 * and the sentence (provenance-model.js sidesSentence) names which sides
 * come from where and points at the diagram; no set side reads the
 * topmost author's hue hollow ("Set at Hover, not here") or the resting
 * ring ("Not set").
 *
 * Under a write address that withholds the shorthand or any offered
 * side (controls.js withheldReasonFor) the row renders DISABLED with
 * the roster's reason as the field's title and no pencil: the family
 * writes as one patch, so no side may be written where another is
 * refused. What the own key never grants stays hidden (the grant read
 * above), never disabled.
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
  const disabledTitle = withheldReasonFor(model, [
    ...(shorthandGranted ? ['padding'] : []),
    ...sideLonghands('padding', offeredSides),
  ]);
  const disabled = disabledTitle !== null;

  // The write layer the patches expand and collapse, read PER WRITE
  // (lib/write-layer.js): the own values at the editing compound of the
  // box THAT WRITE lands on, its set members at the model's landing, a
  // removal alone at the retired name's key. The reads are the model's
  // per-property stacks (every layer, the per-side read's input).
  const ownFor = (write) => writeLayer(styleMap, model, write);
  const conditions = guide?.conditions ?? {};

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

  // The ring's host: the probe at the home box, the highlight at the
  // field's write key (the family's, the same address the field's own
  // gesture writes at).
  const host = useDiagramHost({
    source,
    model,
    open,
    property: fieldProperty,
    longhands: PADDING_LONGHANDS,
  });

  const space = valueSpaceFor(
    guide,
    materials,
    fieldProperty,
    fieldDefinition,
    fieldConstraints,
    model.condition,
  );

  const uniform = uniformState(
    model.stacks,
    'padding',
    offeredSides,
    model.condition,
    conditions,
  );
  const fieldText = uniformFieldText(
    uniform,
    stackAt(model.stacks.padding, 'padding', model.condition, conditions)
      .winner?.value,
  );
  // The mark's origin over the offered sides (sidesOrigin's facts, the
  // sentence over them); the uniform value's face leads the sentence
  // when the sides agree.
  const sidesFacts = sidesOrigin(
    model.stacks,
    'padding',
    offeredSides,
    model.condition,
    conditions,
  );
  const sentence = sidesSentence(sidesFacts, {
    pose: model.condition,
    conditions,
    value:
      uniform.uniform !== null
        ? wearing(guide, materials, space, uniform.uniform, model.condition)
            .face || uniform.uniform
        : null,
  });
  const origin = { ...sidesFacts, title: sentence, header: sentence };
  // The own layer spelling the offered sides at the exact pose (the
  // shorthand or any offered side's longhand), each name read on the
  // box its null would land on: the emptied field's unset has something
  // to remove.
  const ownAtPose = ['padding', ...sideLonghands('padding', offeredSides)].some(
    (name) =>
      normalizeValue(
        ownLayerAt(styleMap, model.keys[name], model.condition)?.[name],
      ) !== null,
  );

  // The field's write: the shorthand, or the granted side longhands
  // under a subset grant; its own layer is the box that write lands on.
  const fieldWrite = (value) =>
    shorthandGranted
      ? { padding: value }
      : Object.fromEntries(
          sideLonghands('padding', offeredSides).map((name) => [name, value]),
        );

  // One settle's patch: the shorthand write with own longhands collapsed
  // (uniformCommit), or the granted side longhands under a subset grant.
  // The DimensionField hands over the COMMIT spelling (the reference on a
  // capture), so the patch carries refs where the guide minted them.
  const patchFor = (value) => {
    const own = ownFor(fieldWrite(value));
    return shorthandGranted
      ? uniformCommit(own, value)
      : subsetCommit(own, offeredSides, value);
  };

  // The ring's patch: the family's per-side expansion (sidesPatch) over
  // the layer the sides' writes land on. The empty gesture is the
  // diagram's blocked probe: it writes nothing, so it asks the layer
  // where a side's write would land (the four longhands as the set).
  const ringPatch = (bySide) => {
    const write = Object.fromEntries(
      Object.entries(bySide ?? {}).map(([side, value]) => [
        familyLonghand('padding', side),
        value,
      ]),
    );
    const own = Object.keys(write).length
      ? ownFor(write)
      : ownLayerAt(styleMap, model.landing(PADDING_LONGHANDS), model.condition);
    return sidesPatch(own, bySide, 'padding');
  };

  const field = useFieldGesture(
    fieldText,
    'padding',
    (properties) => onPreview(patchFor(properties.padding ?? null)),
    (properties) => onRelease(patchFor(properties.padding ?? null)),
    (properties) => onCommit(patchFor(properties.padding ?? null)),
    ownAtPose ? fieldText : null,
  );
  // The row menu over the field's token items alone: the doorways are
  // per property, and four sides can have four origins (the mark's
  // sentence names the split; the diagram is the per-side surface).
  const onContextMenu = useRowMenu({
    origin: null,
    tokens: () =>
      tokenItems(guide, materials, model.condition, space, field.shown, {
        onPreview: field.preview,
        onSettle: field.settle,
      }),
    face: () =>
      wearing(guide, materials, space, field.shown, model.condition).face ||
      field.shown,
  });

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
          mark={<Mark origin={origin} />}
          disabled={disabled}
          disabledTitle={disabledTitle}
          tools={
            disabled ? null : (
              <PencilToggle
                open={open}
                onToggle={() => setOpen((current) => !current)}
                label={Drupal.t('Edit padding per side')}
                openTitle={Drupal.t('Hide the padding diagram')}
                closedTitle={Drupal.t('Edit each side of the padding')}
              />
            )
          }
          onPreview={field.preview}
          onSettle={field.settle}
        />
      </div>
      {open && !disabled && (
        <BoxModelDiagram
          guide={guide}
          materials={materials}
          condition={model.condition}
          stacks={model.stacks}
          computed={computed}
          bases={host.probe?.bases ?? null}
          center={host.center}
          family="padding"
          label={Drupal.t('Padding')}
          patchFor={ringPatch}
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
