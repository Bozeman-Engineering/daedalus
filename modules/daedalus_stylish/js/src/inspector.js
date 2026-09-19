import { h, Fragment } from 'preact';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import {
  parseKey,
  declarationForKey,
  bundleForKey,
  buildSections,
  isInstancePartKey,
  KIND_LABELS,
} from './style-guide.js';
import { usePalettePopover } from './palette-picker.js';
import { AiOfferToggle } from './ai-offer.js';
import { gradientStops } from './widget-gradients.js';
import { GradientGlyph, useGradientChip } from './gradient-chip.js';
import { partLabel } from './parts.js';
import { ChipRow } from './chip-row.js';
import { StyledAt } from './styled-at.js';
import { VariantDocumentView } from './code/element-view.js';
import { PageCodeView } from './code/page-view.js';
import { StyleActions, VariantArea } from './variants.js';
import { LibraryView } from './library.js';
import { PaletteView } from './palette.js';
import { AnimationsView } from './animations.js';
import { pageWearer } from './library-model.js';
import { BasicControls } from './basic-controls.js';
import {
  LayoutSection,
  ColumnSection,
  LAYOUT_SECTION_CLAIMS,
  layoutSectionTarget,
} from './layout-section.js';
import { uiMode as uiModeStore } from './ui-mode.js';
import { codeScope as codeScopeStore } from './code-scope.js';
import {
  applicableVariants,
  axisLabel,
  axisVocabulary,
  captureScope,
  declaredDefaultEntry,
  effectiveEntry,
  entryRefs,
  findVariant,
  locked,
  propertySuppliers,
  saveStyles,
  shadowedProperties,
  skinDefaultEntry,
  stencilProvenance,
  suppliedValue,
} from './variant-scope.js';
import { ELEMENT_LAYER, layerColor, variantLayerId } from './code/layers.js';
import {
  captureRelativeTable,
  documentSlotEntry,
  documentValueWinner,
  updateFromElementLines,
  wearingOf,
} from './code/variant-document.js';
import {
  normalizePlace,
  selectionMove,
  reanchorSession,
  sessionFocuses,
} from './surface-state.js';
import {
  framePosture,
  mintConsent,
  parkTransition,
  resumeTransition,
  sessionHeader,
  switcherRoster,
  resumeChip,
} from './session-frame-model.js';
import { rootKindForScope } from './slot-key.js';
import { vectorCondition, conditionPose } from './editing-at.js';
import { valueAtPose } from './condition-cascade.js';
import { restingValue } from './choice-row-model.js';
import {
  conditionText,
  defaultsAtPose,
  rowMenu,
  rowOrigin,
  setsAt,
  tokenItems,
} from './provenance-model.js';
import { useMenu } from './menu.js';
import { parseAnimationTrigger } from './motion-triggers-model.js';
import { fetchUsage } from './variants-api.js';
import {
  dismissLine,
  payloadRows,
  restoreLine,
  saveSession,
  sessionCount,
  stageIslandLines,
  stageLine,
  stageValue,
  updateSession,
} from './pending.js';
import { PushBar, commitSession } from './push-bar.js';
import { outlineGesture } from './wear-bar-model.js';
import { findNode, pageNodes, subtreeNodes } from './code/page-model.js';
import { DimensionField } from './widgets/lib/dimension-field.js';
import { TextField } from './widgets/lib/text-field.js';
import { useColorField } from './widgets/lib/color-field.js';
import {
  displayAt,
  magneticCapture,
  valueSpaceFor,
  wearing,
} from './value-space.js';
import { useRowHighlight } from './widgets/lib/use-highlight.js';
import { firstMediaUuid } from './media-token.js';
import { MediaButton } from './media-button.js';
import {
  autoOpen,
  marksFor,
  markSection,
  sectionOpen,
} from './section-open.js';
import { PanelClose } from './panel-close.js';
import { useConcealed } from './reveal.js';

/**
 * Middle-truncates an identity string, keeping both ends readable.
 *
 * Selectable keys front-load their kind prefix and end in the UUID's (or
 * field name's) tail, the two halves a human compares, so the ellipsis
 * eats the middle instead of CSS eating the end.
 */
const middleTruncate = (text, max = 28) =>
  !text || text.length <= max
    ? text
    : `${text.slice(0, Math.ceil((max - 1) / 2))}…${text.slice(-Math.floor((max - 1) / 2))}`;

/**
 * Whether a property row is dimension-shaped: the DimensionField's
 * territory, whatever the constraints say (the field's value space
 * absorbs a closing scale/allowed vocabulary as its closed render).
 */
const dimensionRow = (row) =>
  row.definition?.type === 'dimension' || row.definition?.type === 'number';

/**
 * Picks the editor SHAPE for a property row; the value space decides
 * the rest.
 *
 * Every row is a field over its property's one value space
 * (value-space.js valueSpaceFor: constraints, then the materials store,
 * then the property's built-in vocabulary, the same derivation the
 * basic-mode rows read), and the space's KIND, never the enforcement
 * mode or the definition, decides whether the input is closed (a select
 * of the stops plus the honest extra option for an off-vocabulary
 * committed value) or open (free entry with the stops as the stop
 * picker's offer, a settle capturing a matching stop's reference). What varies
 * per shape is the editor around that input:
 * - dimension / number: the numeric field (scrub label, expressions,
 *   steppers; dimension-field.js), which derives the space itself.
 * - media (definition `media: true`, the url('media:<uuid>') token
 *   spine): the `{}` grid over the row's gradient swatches, the gradient
 *   chip, the media library chip, beside the input.
 * - color: the color field (widgets/lib/color-field.js): the select of
 *   the space's stops as faces, and on an open space the popover's color
 *   lane to the native picker (any spelling brought to hex) and free text
 *   with magnetic capture, plus save-as-swatch under the grant.
 * - text (keywords, families, shorthands, the wide tier): the input
 *   alone; a keyword definition's keywords ride as the stop picker, and
 *   free entry stays for the var() / calc() / CSS-wide keywords the
 *   server accepts on every keyword property.
 * The server validates every commit regardless, so client shaping is UX,
 * not enforcement.
 */
const controlFor = (row) => {
  if (dimensionRow(row)) {
    return { kind: 'dimension' };
  }
  if (row.definition?.media) {
    return { kind: 'media' };
  }
  if (row.definition?.type === 'color') {
    return { kind: 'color' };
  }
  return { kind: 'text' };
};

/**
 * One editable property row (the preview/settle split).
 *
 * Typing/dragging previews per input event — the page's <style> tag only,
 * nothing queued. The commit fires on settle (blur, Enter, or a select
 * change): one update_style op through the op queue. A failed commit keeps
 * the typed value in the input (typing stays, tempstore at last-good)
 * and marks the row invalid; the preview reverts to the authoritative state
 * when the op's rejection releases it. An empty value commits null — unset.
 *
 * Provenance (provenance-model.js rowOrigin): the row's shown value has
 * ONE origin, the layer that sets it at the condition delivering it at
 * the pose. It renders as the mark in the row's leading slot (a
 * dimension row's grip, any other row's ring), quiet when the value is
 * the own layer's at this pose and hued in the origin's layer color
 * otherwise (`--daedalus-stylish-origin` on the row; the hover title tells the
 * story), and as the row's right-click menu (rowMenu through the shared
 * menu primitive): the origin in words, the doorways (drive the canvas
 * to the delivering condition, open the supplying variant there, unset
 * to let the beaten layer return, edit the skin), every other condition
 * carrying a value, then the token items the dimension field's value
 * space offers (follow a design-system stop or freeze the literal).
 * The face tells inherited from overriding: a ghost with a hued mark is
 * another layer's or condition's value, a committed face with a hued
 * mark is this layer's on top of the beaten one. The ghost is the row's
 * resting value (choice-row-model.js restingValue, the one derivation
 * the basic rows read as their origin's `ghost`): the map's delivering
 * block at the pose, else the defaults tier's, raw, handed to the input
 * as its placeholder only while the own layer commits nothing at the
 * exact compound.
 *
 * Every row is also a canvas-highlight consumer (useRowHighlight):
 * hovering it tints its property's invisible geometry on the canvas
 * (padding bands, margin's outside bands, gap strips), focus holds the
 * tint through an edit, and painterless properties no-op silently —
 * wired ONCE here, so every property group participates for free.
 *
 * Inside the editing session (`sheet`, the Sections-level document
 * context) the row writes the edited variant's OWN document (four controls,
 * one loud mode): its value is the variant's at this row's slot
 * (the entry arrives as the document projection, session overlaid; it
 * is the row's `own` layer there), settling stages a pending line
 * through the session instead of committing an element op (a set, a
 * null unset, or — settling back on the variant's stored value — the
 * staged line's dismiss), and typing previews on this wearer only while
 * the document wins the property there (no canvas lies; unsets preview
 * nothing). Pending rows wear the focus chrome with a dismiss mark
 * (`pendingMark`), and a document value a higher layer beats wears the
 * winner's hue (the overridden posture) with the menu's doorway to
 * where the winner is edited. Skin placeholders stay away: everything
 * shown IS the variant.
 */
const PropertyWidget = ({
  row,
  entry,
  guide,
  materials,
  condition,
  own = null,
  ownLayer = ELEMENT_LAYER,
  suppliers = null,
  shadowed = null,
  skinEntry = null,
  declaredEntry = null,
  declaredScope = null,
  labelOf,
  valueOf,
  source,
  highlightKey,
  highlightSub,
  media,
  swatches = null,
  sheet = null,
  pendingMark = null,
  onOpenDocument = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition,
}) => {
  // The row's value at the pose (condition-cascade valueAtPose, the one
  // read): the own layer's block at the compound is the committed value,
  // any other delivering block (another condition's, a worn variant's)
  // the inherited ghost below.
  const atPose = valueAtPose(row.values, condition, guide?.conditions);
  const committed =
    atPose?.condition === condition && setsAt(own, condition, row.property)
      ? atPose.value
      : '';
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  // The last value this row staged as a wearer preview for the document:
  // reverts and unsets release it (nothing pending owns it anymore).
  const lastPreviewed = useRef(null);
  const highlight = useRowHighlight(source, highlightKey, highlightSub, [
    row.property,
  ]);
  // The defaults tier's delivering block at the pose (the same cascade
  // read as the map's): the skin's, else the element's declaration's
  // own. Provenance the row renders (the origin's sentence and hue),
  // never a value layer the widgets edit; the ghost reads the same tier
  // through restingValue below.
  const defaults = defaultsAtPose(
    skinEntry,
    declaredEntry,
    row.property,
    condition,
    guide?.conditions,
  );
  // The map's inherited block at this pose: the cascade-winning block
  // that still applies at the editing compound (base always, a subset
  // compound when posed at "narrow+dark") or a worn variant's block at
  // the compound itself, a block the row does not own; the closed
  // select names it on its unset option. Editing never touches it;
  // writes land at the posed compound and override it there only.
  const inherited = committed === '' ? atPose : null;
  // The ghost beneath an uncommitted row: the one resting derivation
  // (the map's delivering block at the pose over the defaults tier's,
  // raw), gated behind the own layer's commit at the exact compound.
  const ghost =
    committed === ''
      ? restingValue({
          guide,
          condition,
          property: row.property,
          entry,
          provenance: { skinEntry, declaredEntry },
        })
      : null;
  const editing = draft !== null && draft.trim() !== '';
  // The session's overridden read: a document value — stored, staged,
  // or a pending removal — a higher layer beats on this wearer is not
  // visible on the canvas here; the origin names the winner and the
  // menu's doorway navigates to where it is edited.
  const winner =
    sheet && (committed !== '' || editing || pendingMark)
      ? sheet.winner(condition, row.property)
      : null;
  // The origin speaks display values, never var() spellings (the field's
  // own ref→display read, at the editing pose); the posture reads the
  // tables, so faces are all the sentences need.
  const faceOf = (value) =>
    value == null
      ? value
      : (displayAt(guide, materials, value, condition) ?? String(value));
  const origin = rowOrigin({
    property: row.property,
    values: row.values.map((entry) => ({
      ...entry,
      value: faceOf(entry.value),
    })),
    atPose: atPose ? { ...atPose, value: faceOf(atPose.value) } : null,
    condition,
    conditions: guide?.conditions ?? {},
    own,
    ownLayer,
    suppliers,
    shadowed,
    defaults: defaults ? { ...defaults, value: faceOf(defaults.value) } : null,
    skinEditUrl: guide?.skinEditUrl ?? null,
    declaredScope,
    winner,
    labelOf,
    valueOf: (reference, at, property) =>
      faceOf(valueOf(reference, at, property)),
  });

  // A new authoritative value (a settled commit, an AI edit, a condition
  // switch) supersedes the draft; a failed commit leaves committed
  // untouched, so the typed value survives for correction.
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [committed, condition]);

  const shown = draft ?? committed;
  const asStyleValue = (value) => (value.trim() === '' ? null : value.trim());

  const control = controlFor(row);
  // The row's value space (ONE per property, both modes): its kind picks
  // the input (closed select / open entry), its stops are the offer and
  // the capture targets. Dimension rows derive it inside the field.
  const space =
    control.kind === 'dimension'
      ? null
      : valueSpaceFor(
          guide,
          materials,
          row.property,
          row.definition,
          row.constraints,
          condition,
        );
  const closed = space !== null && space.kind === 'closed';

  const releaseTyped = () => {
    if (lastPreviewed.current !== null) {
      onRelease(row.property, lastPreviewed.current);
      lastPreviewed.current = null;
    }
  };

  const preview = (value) => {
    setDraft(value);
    const styleValue = asStyleValue(value);
    if (sheet) {
      // Preview honesty on the document surface: unsets preview nothing
      // (the variant delivers until Push — an emptied input releases
      // what the row already staged), and sets land on this wearer only
      // while the document wins the property here.
      if (styleValue === null) {
        releaseTyped();
        return;
      }
      if (sheet.winner(condition, row.property) !== null) {
        return;
      }
      lastPreviewed.current = styleValue;
    }
    onPreview(row.property, styleValue);
  };

  const settle = (value) => {
    if (draft === null && value === committed) {
      return;
    }
    // Magnetic capture on a detented space (the DimensionField's doctrine
    // for every other shape): a settled value that value-matches a stop
    // commits the stop's reference — typing a swatch's hex IS reaching
    // the swatch, and so is picking it in the stop menu. A closed space
    // commits the select's ref as is; a free space has nothing to catch.
    const settled = asStyleValue(value);
    const captured =
      space?.kind === 'detented' && settled !== null
        ? magneticCapture(space.stops, settled)
        : null;
    const next = captured ? captured.ref : settled;
    if (sheet) {
      // The document settle half: no ops, no tempstore — the settled
      // value stages against the variant's stored baseline (stageValue:
      // a set, a null unset, or a revert's dismiss) and the Push/Discard
      // bar commits. A revert or an unset leaves no staged line to own
      // the typed preview, so it releases here; a staged set's preview
      // is owned by the Inspector's preview reconciler from now on.
      sheet.stage(condition, row.property, next);
      const stored = sheet.baselineOf(condition, row.property);
      const baseline =
        stored === undefined || stored === null ? null : String(stored).trim();
      if (next === null || next === baseline) {
        releaseTyped();
      }
      setDraft(null);
      setInvalid(false);
      return;
    }
    if (next === asStyleValue(committed)) {
      // Nothing to persist — release the staged preview and fall back to
      // the committed value.
      onRelease(row.property, next);
      setDraft(null);
      setInvalid(false);
      return;
    }
    onCommit(row.property, next).then(
      () => setInvalid(false),
      (error) => {
        if (error?.abandoned) {
          // Cancelled, not failed (a declined detach, a superseded op):
          // fall back to the committed value instead of marking invalid.
          setDraft(null);
        } else {
          setInvalid(true);
        }
      },
    );
  };

  // The media row's `{}` grid: the row's gradient swatches as faces (the
  // faces a plain stop list cannot paint), the same constraint-honest derivation
  // the basic-mode wells read (gradientStops over background-image's own
  // constraints), the popover floating under the chip over the rows
  // below. An insert-style consumer: no lit stop, a pick commits the
  // var() reference exactly like typing it would.
  const mediaPaletteStops =
    control.kind === 'media'
      ? gradientStops(guide, materials, row.constraints ?? null)
      : [];
  const mediaPalette = usePalettePopover({
    stops: mediaPaletteStops,
    onPick: (stop) => {
      if (stop) {
        preview(stop.value);
        settle(stop.value);
      }
    },
  });
  // The media chip's face: the shown value's first token layer, answered
  // from the same client uuid → URL table the preview transform reads. A
  // token-free or unanswerable value shows the generic image glyph.
  const mediaThumbnail =
    control.kind === 'media'
      ? (media?.urls?.[firstMediaUuid(shown) ?? ''] ?? null)
      : null;
  // The gradient popover wiring, shared with the basic-mode color
  // widgets (gradient-chip.js): the open/close state, the edit-in-place
  // gate (a committed value that is exactly one gradient-swatch var()
  // opens the update session over the swatch's OWN value instead of
  // degrading), the builder over anything else, and the mint seam. Media
  // rows only: a '' committed keeps the gate off elsewhere, and the
  // opener/popover vnodes render nowhere.
  const gradientChip = useGradientChip({
    guide,
    materials,
    committed: control.kind === 'media' ? committed : '',
    value: shown,
    constraints: row.constraints ?? null,
    source,
    canMint: Boolean(swatches?.canCreate),
    onPreview: preview,
    onCommit: settle,
  });
  const gradientSwatch = control.kind === 'media' ? gradientChip.swatch : null;
  // The color field (color-field.js): the color row's whole input
  // cluster over the row's value space (faces in the select, the color
  // lane's hidden picker and free text on an open space, the honest
  // extra face on a closed one), its settles speaking this row's
  // settle() like every other
  // input (a captured or picked reference, the literal as typed, '' for
  // unset). Color rows only: a null space mounts nothing.
  const colorField = useColorField({
    space: control.kind === 'color' ? space : null,
    value: shown,
    guide,
    materials,
    condition,
    label: row.label,
    placeholder: ghost,
    invalid,
    canMint: Boolean(swatches?.canCreate),
    source,
    onPreview: preview,
    onSettle: settle,
  });
  // Custom gradient authoring is admissible exactly while the space is
  // open (the builder writes literals a closed space rejects); the
  // edit-in-place session over a committed swatch reference stays
  // offered regardless, since it rewrites the swatch, not this value.
  const chipOffered =
    control.kind === 'media' && (!closed || gradientSwatch !== null);
  // The row face wears the swatch's LABEL instead of the truncated
  // var(--swat…) spelling (the full var() rides the hover title) while
  // the input is not focused (the text field owns the swap); a draft is
  // already the user's own text.
  const faceText =
    gradientSwatch !== null && draft === null ? gradientSwatch.label : null;
  const classes = [
    'daedalus-stylish-inspector__property',
    invalid ? 'daedalus-stylish-inspector__property--invalid' : '',
    pendingMark ? 'daedalus-stylish-inspector__property--pending' : '',
  ]
    .filter(Boolean)
    .join(' ');
  // The row menu (the one provenance surface, both halves): the origin's
  // doorways dispatch here by id, the "Also set at" entries drive the
  // canvas, and the token items are the dimension field's over the
  // row's own value space (the field settles literals as literals, so
  // its "Custom value" escape is honest there; the other shapes'
  // settle recaptures every stop match, so they offer no token menu).
  // Built on open, never per render; with nothing to show the native
  // menu rides through.
  const menu = useMenu();
  const onDoorway = (doorway) => {
    switch (doorway.id) {
      case 'drive':
        onPickCondition(doorway.condition);
        break;
      case 'document':
        onOpenDocument?.(doorway.reference, doorway.condition);
        break;
      case 'unset':
        settle('');
        break;
      case 'skin':
        window.open(doorway.url, '_blank', 'noopener');
        break;
      case 'winner':
        sheet.onWinner(doorway.winner);
        break;
      default:
        break;
    }
  };
  const onRowContextMenu = (event) => {
    const tokenSpace =
      control.kind === 'dimension'
        ? valueSpaceFor(
            guide,
            materials,
            row.property,
            row.definition,
            row.constraints,
            condition,
          )
        : null;
    const tokens = tokenSpace
      ? tokenItems(guide, materials, condition, tokenSpace, shown, {
          onPreview: preview,
          onSettle: settle,
        })
      : [];
    const spec = rowMenu({
      origin,
      tokens,
      face: tokenSpace
        ? wearing(guide, materials, tokenSpace, shown, condition).face || shown
        : shown,
      onDoorway,
      onCondition: onPickCondition,
    });
    if (spec && menu.open({ x: event.clientX, y: event.clientY, ...spec })) {
      event.preventDefault();
    }
  };
  // A dimension row's label lives INSIDE the field (the scrubbable label
  // is the label), and the field carries several interactive controls a
  // <label> could not honestly wrap — a div holds the line instead; the
  // color field's cluster (face trigger, hidden picker, text) is the
  // same case.
  const Line =
    control.kind === 'dimension' || control.kind === 'color' ? 'div' : 'label';

  return (
    <div
      className={classes}
      style={origin.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
      onContextMenu={onRowContextMenu}
      {...highlight}
    >
      <Line className="daedalus-stylish-inspector__property-line">
        {/* The leading slot: a dimension row's grip IS the mark (the
            field renders it); any other row rests a ring here, so the
            label column never moves with the provenance. */}
        {control.kind !== 'dimension' && (
          <span
            className="daedalus-stylish-inspector__mark daedalus-ui-dot"
            title={origin.title}
            aria-hidden="true"
          />
        )}
        {control.kind !== 'dimension' && (
          <span className="daedalus-stylish-inspector__property-label">{row.label}</span>
        )}
        {control.kind === 'dimension' && (
          /* The numeric field (dimension-field.js): controlled on
             `shown` so its previews echo back through the draft; its
             onSettle speaks the same settle() as text (release when
             unchanged, one commit otherwise) and emits the commit
             spelling (a reference on capture, the literal otherwise).
             The guide + condition let the field derive the property's
             value space and resolve reference displays at the editing
             pose. Its grip is this row's provenance mark: the origin's
             hue rides the row's --daedalus-stylish-origin, the story its title. */
          <DimensionField
            label={row.label}
            value={shown}
            property={row.property}
            definition={row.definition ?? {}}
            constraints={row.constraints ?? {}}
            guide={guide}
            materials={materials}
            condition={condition}
            invalid={invalid}
            placeholder={ghost}
            gripTitle={origin.title}
            onPreview={preview}
            onSettle={settle}
          />
        )}
        {control.kind !== 'dimension' && (
          <span className="daedalus-stylish-inspector__input-group">
            {/* The media row's palette chip: the shared grid popover
                behind it (wired above), closed spaces included (the grid
                shows exactly the curated gradient entries). */}
            {control.kind === 'media' && mediaPaletteStops.length > 0 && (
              <button
                {...mediaPalette.triggerProps}
                type="button"
                className="daedalus-stylish-inspector__palette-picker"
                title={Drupal.t('Insert a palette value')}
              >
                {'{}'}
              </button>
            )}
            {/* The gradient opener (the media chip's sibling on <image>
                rows): toggles the gradient popover below the row. A pure
                client widget over the row's value, so it renders in the
                satellite too — no canPick gate; the space's closedness
                is its only gate (chipOffered). */}
            {chipOffered && (
              <button
                type="button"
                className={`daedalus-stylish-inspector__gradient-opener${gradientChip.expanded ? ' daedalus-stylish-inspector__gradient-opener--open' : ''}`}
                title={
                  gradientSwatch
                    ? Drupal.t('Edit the swatch this value references')
                    : Drupal.t('Build a gradient')
                }
                aria-expanded={gradientChip.expanded}
                onClick={() =>
                  gradientChip.expanded
                    ? gradientChip.close()
                    : gradientChip.open()
                }
              >
                <GradientGlyph />
              </button>
            )}
            {/* The media picker chip (media-button.js, the color swatch's
                precedent): opens the media library; the pick lands
                entirely through the source (table extension, instant
                staged preview, ordinary update_style commit), so this row
                just re-renders when the committed map arrives. Without a
                library URL the chip stays away and the row degrades
                honestly to free text; a closed space (stops only) never
                offers a media pick it would reject. */}
            {control.kind === 'media' && !closed && media?.canPick && (
              <MediaButton
                thumbnail={mediaThumbnail}
                onClick={() => media.onPick(row.property)}
              />
            )}
            {control.kind === 'color' && colorField.field}
            {control.kind !== 'color' && (
              /* The text-shape input (widgets/lib/text-field.js, the
                 DimensionField's twin, shared with the basic rows): the
                 space's kind picks the closed select or the open input
                 with the stop picker's chevron; a settle emits the
                 commit spelling (a captured stop's reference, the
                 literal otherwise) into this row's settle(). */
              <TextField
                label={row.label}
                value={shown}
                space={space}
                placeholder={ghost}
                inherited={inherited}
                face={faceText}
                title={gradientSwatch ? committed.trim() : undefined}
                onPreview={preview}
                onSettle={settle}
              />
            )}
          </span>
        )}
        {pendingMark && (
          <button
            type="button"
            className="daedalus-stylish-inspector__pending-mark"
            title={
              pendingMark.unset
                ? Drupal.t(
                    'Pending removal from the variant, committed by Push. Click to dismiss; the stored value returns.',
                  )
                : Drupal.t(
                    'Pending change to the variant, committed by Push. Click to dismiss; the stored value returns.',
                  )
            }
            onClick={() => sheet.dismiss(pendingMark.id)}
          >
            {'↺'}
          </button>
        )}
      </Line>
      {/* The gradient popover (gradient-chip.js): the edit-in-place
          swatch session over a gradient-swatch reference, the builder
          over anything else, both wired through this row's own
          preview/settle pair. */}
      {control.kind === 'media' && mediaPalette.popover}
      {control.kind === 'color' && colorField.popover}
      {chipOffered && gradientChip.popover}
    </div>
  );
};

/**
 * The property-group sections for the selected element.
 *
 * A projection of the style guide YAML against the current style map:
 * sections per declared property group, values via the blueprint mirror,
 * widgets writing through the state source. `entry` arrives RESOLVED
 * (effectiveEntry):
 * an on-variant element's widgets populate with the variant's values,
 * and each row's provenance mark says where its value comes from (the
 * own entry, the supplier and shadow tables and the skin entry travel
 * WHOLE to the rows: the row reads them at the condition delivering its
 * value, which the pose alone cannot name). `reference` keeps an
 * on-variant element whose values the library cannot resolve from
 * claiming "nothing stylable".
 *
 * Inside the editing session (`sheet` + `pending`, the Inspector's
 * document context and the slot's pending table) `entry` arrives as the
 * DOCUMENT PROJECTION instead — the variant's own sheet at this slot,
 * session overlaid — and the rows write the document: settles stage
 * pending lines, the projection is the rows' own layer (the element's
 * tables and the skin stay away), pending rows wear the focus chrome,
 * and a value a higher layer beats wears the winner's hue with a
 * doorway to where the winner is edited.
 *
 * Each section is a native <details> whose `open` prop is CONTROLLED:
 * Preact pushes it to the DOM only when it changes between renders. The
 * prop is the section-open model's answer, `mark ?? auto`: on arrival
 * the auto signals decide (sections with committed values open, a
 * pending line or a skin-supplied value on a visible row holds a section
 * open, empty ones sit collapsed); within one selection the user's own
 * summary clicks are recorded as marks under `anchor` (the styling
 * target) and win, so a hand-opened group survives its last value's
 * deletion and a hand-closed one stays closed when a value lands. Marks
 * are folded INTO the prop so it mirrors the DOM at all times; when
 * `anchor` moves on, the marks read as stale and the auto state returns.
 * The mark is taken from the summary CLICK, not the toggle event (which
 * fires for programmatic opens too): the click's default is prevented,
 * so `details.open` at click time is the current state and the intended
 * state is its negation, pushed to the DOM by the prop change alone (a
 * native toggle racing the push would undo it; see the handler).
 */
const Sections = ({
  guide,
  materials,
  parsed,
  entry,
  reference,
  own,
  shadowed,
  suppliers,
  skinEntry,
  declaredEntry = null,
  declaredScope = null,
  labelOf,
  valueOf,
  bundle,
  condition,
  source,
  highlightKey,
  highlightSub,
  media,
  anchor = null,
  swatches = null,
  sheet = null,
  pending = null,
  onOpenDocument = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition,
}) => {
  const declaration = declarationForKey(guide, parsed, bundle);
  const sections = buildSections(guide, declaration, entry);
  // The user's open/closed marks, recorded under the anchor they were
  // made at; marks from another anchor read as none.
  const [openState, setOpenState] = useState(null);
  const marks = marksFor(openState, anchor);
  // The defaults tier's value delivered for a row at the pose (the skin's
  // over the declaration's, through the cascade): the section auto-open
  // and Replay signals; the rows read the two entries themselves.
  const defaultsValueFor = (row) =>
    defaultsAtPose(
      skinEntry,
      declaredEntry,
      row.property,
      condition,
      guide?.conditions,
    )?.value ?? null;
  // The rows' own layer: the element's map entry, or in a session the
  // document projection (everything shown IS the variant), whose hue is
  // the edited variant's.
  const rowsOwn = sheet ? entry : own;
  const rowsOwnLayer = sheet ? variantLayerId(reference) : ELEMENT_LAYER;

  // A row's pending mark on the document surface: the staged line — set
  // or unset — at the editing compound (the slot's pending table,
  // condition-keyed).
  const pendingFor = (row) =>
    sheet ? (pending?.[condition]?.[row.property] ?? null) : null;

  // The Motion section's Replay: the same affordance the basic Motion
  // trigger control renders, in the section header, only while the
  // selection's effective animation-trigger at the pose binds a trigger
  // (the value the row shows: the compound's own block, the inheriting
  // block, else the defaults tier's, read as the runtime's grammar reads
  // it), so a section with nothing to re-arm shows no pill. Re-arming
  // happens on the canvas through the source (the satellite relays an
  // intent).
  const replayable = (section, rows) => {
    if (section.id !== 'motion') {
      return false;
    }
    const row = rows.find(
      (candidate) => candidate.property === 'animation-trigger',
    );
    if (!row) {
      return false;
    }
    const value =
      valueAtPose(row.values, condition, guide?.conditions)?.value ??
      defaultsValueFor(row);
    return value != null && parseAnimationTrigger(value) !== null;
  };

  // A row whose value space is closed AND empty (a scale minting nothing
  // on the active skin, while constraints are law) hides, whatever its
  // shape: there is nothing legal to offer, so an inert control would
  // only claim otherwise. A committed value is the exception — a set
  // value always stays visible and unsettable — and so is a pending line
  // (its dismiss surface must render).
  const hiddenFor = (row) => {
    if (row.values.length || pendingFor(row) !== null) {
      return false;
    }
    const space = valueSpaceFor(
      guide,
      materials,
      row.property,
      row.definition,
      row.constraints,
      condition,
    );
    return space.kind === 'closed' && space.stops.length === 0;
  };

  return (
    <Fragment>
      {sections.map((section) => {
        const rows = section.rows.filter((row) => !hiddenFor(row));
        return (
          rows.length > 0 && (
            <details
              key={section.id}
              className="daedalus-ui-details daedalus-stylish-inspector__section"
              open={sectionOpen(
                autoOpen(section, rows, {
                  pendingFor,
                  defaultsValueFor,
                  sheet,
                }),
                marks,
                section.id,
              )}
            >
              <summary
                className="daedalus-stylish-inspector__section-title"
                onClick={(event) => {
                  // The native toggle is suppressed: the controlled prop is
                  // the one writer of details.open. On a real click Preact's
                  // microtask re-render lands BEFORE the browser's own
                  // summary activation (microtasks run between listeners of
                  // a trusted event), so the pushed state would be toggled
                  // straight back by the activation that follows.
                  event.preventDefault();
                  setOpenState(
                    markSection(
                      openState,
                      anchor,
                      section.id,
                      !event.currentTarget.parentElement.open,
                    ),
                  );
                }}
              >
                <span className="daedalus-stylish-inspector__section-label">
                  {section.label ?? Drupal.t('Other')}
                </span>
                {replayable(section, rows) && (
                  <button
                    type="button"
                    className="daedalus-stylish-inspector__section-action daedalus-ui-pill-button"
                    title={Drupal.t(
                      'Play the animation again on the canvas, as if the element had just scrolled into view.',
                    )}
                    onClick={(event) => {
                      // The pill is its own activation: neither the summary's
                      // mark handler above nor the native toggle may run.
                      event.preventDefault();
                      event.stopPropagation();
                      source.replayMotion(highlightKey, highlightSub);
                    }}
                  >
                    {Drupal.t('Replay')}
                  </button>
                )}
              </summary>
              <div className="daedalus-stylish-inspector__section-body">
                {rows.map((row) => (
                  <PropertyWidget
                    key={row.property}
                    row={row}
                    entry={entry}
                    guide={guide}
                    materials={materials}
                    condition={condition}
                    own={rowsOwn}
                    ownLayer={rowsOwnLayer}
                    suppliers={sheet ? null : suppliers}
                    shadowed={sheet ? null : shadowed}
                    skinEntry={sheet ? null : skinEntry}
                    declaredEntry={sheet ? null : declaredEntry}
                    declaredScope={declaredScope}
                    labelOf={labelOf}
                    valueOf={valueOf}
                    source={source}
                    highlightKey={highlightKey}
                    highlightSub={highlightSub}
                    media={media}
                    swatches={swatches}
                    sheet={sheet}
                    pendingMark={pendingFor(row)}
                    onOpenDocument={sheet ? null : onOpenDocument}
                    onPreview={onPreview}
                    onRelease={onRelease}
                    onCommit={onCommit}
                    onPickCondition={onPickCondition}
                  />
                ))}
              </div>
            </details>
          )
        );
      })}
      {!sections.length && !reference && (
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t(
            'The style guide declares nothing stylable for this element.',
          )}
        </div>
      )}
    </Fragment>
  );
};

/**
 * The editing session's frame: the mode's chrome (four controls, one
 * loud mode).
 *
 * The loudest possible mode: the purple frame around the panel body, a
 * header that says in words what is being edited and how far it reaches
 * (back/park arrow, posture label, document chip + switcher, blast
 * line), the existing document machinery as the body (children), and
 * the Push/Discard bar as the exits. session-frame-model.js decides
 * everything decidable without a DOM (header identity, switcher roster,
 * the mint-time consent);
 * this view renders, translates, and dispatches. The blast line speaks
 * the session's impact count (`usages` — the Inspector fetches it once
 * per session entry and shares it with the push bar), so the site-wide
 * consequence is on screen the moment the mode mounts. In the creating
 * posture the header carries the draft's identity instead: the name
 * input and, on a multi-axis scope, the axis pick (moved here from the
 * retired draft document header): one frame, one cadence, for create
 * and update. `consent` (mintConsent facts, or null) renders as the
 * "Belongs to <stencil>" line: a draft anchored inside a stencil
 * placement is born as that stencil's own switchable look, said out
 * loud BEFORE commit instead of silently, and Unlink is the opt-out
 * (`linked: false` on the session; one-way, since re-linking is just
 * discarding nothing: the Materials surface's Assign gesture covers
 * regret).
 */
const SessionFrame = ({
  guide,
  library,
  session,
  editing,
  entry,
  axes,
  vocabulary = [],
  usages = null,
  consent = null,
  onSession,
  onPark,
  onSwitch,
  children,
}) => {
  const header = sessionHeader(session, library, guide);
  const creating = header.posture === 'creating';
  // The document switcher: the selection's other worn documents, so a
  // designer working two axes hops without exit-and-rejump. Switching
  // resolves the open session through the ordinary prompt first (the
  // displacement queue's business); the retired slim layer-picker rows'
  // duty lives in this one control.
  const roster = creating ? [] : switcherRoster(entry, library, editing, axes);
  const current = roster.find((row) => row.current) ?? null;
  const blast = creating
    ? Drupal.t('Captured from this element · saving wears it here')
    : usages === null
      ? Drupal.t('@axis variant · changes affect every use', {
          '@axis': header.axisLabel,
        })
      : Drupal.formatPlural(
          usages,
          '@axis variant · worn in 1 place site-wide · changes affect every use',
          '@axis variant · worn in @count places site-wide · changes affect every use',
          { '@axis': header.axisLabel },
        );
  return (
    <div className="daedalus-stylish-session">
      <div className="daedalus-stylish-session__header">
        <div className="daedalus-stylish-session__top">
          <button
            type="button"
            className="daedalus-stylish-session__back"
            title={Drupal.t(
              'Park this session: the staged work keeps, the panel returns. Resume from the chip.',
            )}
            onClick={onPark}
          >
            {'←'}
          </button>
          <span className="daedalus-stylish-session__posture">
            {creating
              ? Drupal.t('New shared style')
              : Drupal.t('Editing shared style')}
          </span>
          {!creating && (
            <span className="daedalus-stylish-session__document">
              <span
                className="daedalus-stylish-session__dot daedalus-ui-dot"
                style={{
                  background: layerColor(
                    variantLayerId(editing ?? session.reference),
                  ),
                }}
              />
              {roster.length > 1 ? (
                <select
                  className="daedalus-stylish-session__switcher daedalus-ui-input daedalus-ui-input--select"
                  value={current?.reference ?? ''}
                  title={Drupal.t(
                    'Switch to another document the selection wears; the open session resolves through the ordinary prompt first.',
                  )}
                  onChange={(event) => {
                    if (event.target.value !== '') {
                      onSwitch(event.target.value);
                    }
                  }}
                >
                  {current === null && (
                    <option value="" hidden>
                      {header.label}
                    </option>
                  )}
                  {roster.map((row) => (
                    <option key={row.reference} value={row.reference}>
                      {`${row.label} · ${axisLabel(guide, row.axis)}`}
                    </option>
                  ))}
                </select>
              ) : (
                <span className="daedalus-stylish-session__label">{header.label}</span>
              )}
            </span>
          )}
        </div>
        {creating && (
          /* The draft row: the new document's identity on its own line
             under the posture label, so the name and the axis pick never
             widen the top row past the panel (the axis label's natural
             width, plus the name's minimum, overflowed it there). The
             name flexes, the axis keeps its natural width and may wrap
             beneath the name at a narrow panel. */
          <div className="daedalus-stylish-session__draft">
            <input
              type="text"
              className="daedalus-stylish-session__name daedalus-ui-input"
              placeholder={Drupal.t('Name…')}
              value={session.label}
              onInput={(event) =>
                onSession({ ...session, label: event.target.value })
              }
            />
            {vocabulary.length > 1 && (
              <select
                className="daedalus-stylish-session__axis daedalus-ui-input daedalus-ui-input--select"
                value={session.axis}
                title={Drupal.t(
                  'The axis the new variant competes on; the captured rows follow the pick.',
                )}
                onChange={(event) =>
                  onSession({ ...session, axis: event.target.value })
                }
              >
                {vocabulary.map((name) => (
                  <option key={name} value={name}>
                    {axisLabel(guide, name)}
                  </option>
                ))}
              </select>
            )}
            {/* The roster's "when to reach for this look" sentence: the
                same description the AI lane writes and the Materials card
                shows, asked for at mint time so a human-made look is not
                born mute. Its own full-width line beneath the name/axis
                pair; optional, and hidden while the draft is withheld
                from the AI (no audience). */}
            {session.offered !== false && (
              <input
                type="text"
                className="daedalus-stylish-session__description daedalus-ui-input"
                placeholder={Drupal.t('When to reach for this look…')}
                title={Drupal.t(
                  'Optional: a sentence on when this look is the right pick. It shows on the Materials card and the AI reads it.',
                )}
                value={session.description ?? ''}
                onInput={(event) =>
                  onSession({ ...session, description: event.target.value })
                }
              />
            )}
            <AiOfferToggle
              className="daedalus-stylish-session__offer"
              offered={session.offered}
              onChange={(offered) => onSession({ ...session, offered })}
            />
          </div>
        )}
        {creating && consent && (
          <div className="daedalus-stylish-session__owner">
            <span
              className="daedalus-stylish-session__owner-label"
              title={Drupal.t(
                "The captured element sits inside this stencil's placement, so the new variant becomes one of its switchable looks, offered on its placements only.",
              )}
            >
              {Drupal.t('Belongs to @stencil', { '@stencil': consent.label })}
            </span>
            <button
              type="button"
              className="daedalus-stylish-session__unlink daedalus-ui-pill-button"
              title={Drupal.t(
                'Save as a general-purpose variant instead; assign it to a stencil later from Materials.',
              )}
              onClick={() => onSession({ ...session, linked: false })}
            >
              {Drupal.t('Unlink')}
            </button>
          </div>
        )}
        <div className="daedalus-stylish-session__blast">{blast}</div>
      </div>
      {children}
    </div>
  );
};

/**
 * The parked session's resume chip: the suspended mode as a visible
 * object.
 *
 * The bar survives surface switches, so a parked session needs a place
 * the user can see it. The chip renders on
 * both places whenever a session is parked (identity, the loud staged
 * count, and its own Resume/Discard), so the suspended session stays a
 * thing you can point at. Discard here is the explicit gesture, the
 * bar's own Discard in chip form: no prompt (prompts belong to the
 * IMPLICIT leave events).
 */
/**
 * The no-selection empty state, one for every posture that needs a
 * selection: the widget modes' body, and code mode's Element and
 * Subtree scopes (an anchored scope without an anchor is honestly
 * empty; it never falls through to the page).
 */
const SelectAnElement = ({ className = '' }) => (
  <div className={`daedalus-stylish-inspector__empty${className ? ` ${className}` : ''}`}>
    {Drupal.t('Select an element to style it.')}
  </div>
);

const ParkedChip = ({ chip, onResume, onDiscard }) => (
  <div className="daedalus-stylish-parked">
    <span className="daedalus-stylish-parked__label">
      {'✎ '}
      {chip.label || Drupal.t('New variant')}
    </span>
    <span className="daedalus-stylish-parked__count">
      {Drupal.formatPlural(
        chip.count,
        '1 staged change',
        '@count staged changes',
      )}
    </span>
    <button
      type="button"
      className="daedalus-stylish-parked__button daedalus-ui-pill-button"
      title={Drupal.t('Reopen the editing session exactly where it parked.')}
      onClick={onResume}
    >
      {Drupal.t('Resume')}
    </button>
    <button
      type="button"
      className="daedalus-stylish-parked__button daedalus-stylish-parked__button--discard daedalus-ui-pill-button"
      onClick={onDiscard}
    >
      {Drupal.t('Discard')}
    </button>
  </div>
);

/**
 * The draft document: the creating posture's session body.
 *
 * Saving as a variant DRAFTS a new variant document, pre-staged from the
 * live capture, so creation and update share one frame and one cadence
 * (pending until the bar commits). The draft's identity (name input and
 * axis pick) lives in the session frame's header; this body is the
 * capture rendered as dismissible pending facts — a dismissed row is
 * excluded from what Save commits (the element keeps that style as its
 * own), and restoring un-excludes it. The capture itself derives live on
 * every render, so element edits made while the draft is open stay
 * captured; the Push/Discard bar is the one commit surface — Save variant
 * creates the document and wears it.
 */
const DraftDocument = ({
  source,
  guide,
  styleMap,
  variants: library,
  blockTypes,
  sectionLayouts,
  session,
  onSession,
}) => {
  const layout = source.getLayout?.() ?? [];
  const capture = saveStyles(
    styleMap ?? {},
    session.anchor,
    layout,
    library,
    session.axis,
  );
  const rows = payloadRows(capture, parseKey(session.anchor)?.kind ?? null);
  const dismissed = new Set(session.dismissed);
  const toggle = (id) =>
    onSession(
      dismissed.has(id) ? restoreLine(session, id) : dismissLine(session, id),
    );
  // Part labels resolve through the captured element carrying the row:
  // captureRelativeTable inverted (relative → full map key), the capture
  // walk's own coverage.
  const table = captureRelativeTable(styleMap ?? {}, session.anchor, layout);
  const carrierByRelative = new Map(
    [...table].map(([key, relative]) => [relative, key]),
  );
  const partText = (group) => {
    const carrier = carrierByRelative.get(group.relative);
    return carrier
      ? partLabel(
          guide,
          carrier.split('::')[0],
          group.part,
          blockTypes,
          sectionLayouts,
          source.getMarkup?.(carrier.split('::')[0]),
        )
      : group.part;
  };
  const kindText = (group) =>
    group.kind === 'root'
      ? Drupal.t('Root')
      : group.kind === 'container'
        ? Drupal.t(KIND_LABELS.container)
        : group.kind === 'block'
          ? Drupal.t('Block')
          : group.kind === 'field'
            ? Drupal.t('Field')
            : Drupal.t('Slot');
  const toggleControl = (id, excludeTitle, restoreTitle) => (
    <button
      type="button"
      className="daedalus-stylish-draft__toggle"
      title={dismissed.has(id) ? restoreTitle : excludeTitle}
      onClick={() => toggle(id)}
    >
      {dismissed.has(id) ? '+' : '×'}
    </button>
  );
  return (
    <div className="daedalus-stylish-draft">
      {rows.length === 0 ? (
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t(
            'Nothing to capture: this element carries no styles of its own right now.',
          )}
        </div>
      ) : (
        rows.map((group) => (
          <div key={group.relative || 'root'} className="daedalus-stylish-draft__group">
            <div className="daedalus-stylish-document__header">
              <span className="daedalus-stylish-document__kind">{kindText(group)}</span>
              {group.detail && (
                <code className="daedalus-stylish-document__detail">{group.detail}</code>
              )}
              {group.kind === null && (
                <code className="daedalus-stylish-document__detail">
                  {group.relative}
                </code>
              )}
              {group.part !== null && (
                <span className="daedalus-stylish-draft__part">{partText(group)}</span>
              )}
            </div>
            {group.refs.map((ref) => (
              <div
                key={ref.id}
                className={`daedalus-stylish-draft__row${dismissed.has(ref.id) ? ' daedalus-stylish-draft__row--dismissed' : ''}`}
              >
                {toggleControl(
                  ref.id,
                  Drupal.t(
                    'Captured into the new variant. Click to exclude it; the new variant will not carry this reference.',
                  ),
                  Drupal.t(
                    'Excluded from the new variant. Click to include it again.',
                  ),
                )}
                <code className="daedalus-stylish-draft__fact">
                  {Drupal.t('wears @label', {
                    '@label':
                      findVariant(library, ref.variant)?.label ?? ref.variant,
                  })}
                </code>
              </div>
            ))}
            {group.lines.map((line) => (
              <div
                key={line.id}
                className={`daedalus-stylish-draft__row${dismissed.has(line.id) ? ' daedalus-stylish-draft__row--dismissed' : ''}`}
              >
                {toggleControl(
                  line.id,
                  Drupal.t(
                    'Captured into the new variant. Click to exclude it; the element keeps this style as its own.',
                  ),
                  Drupal.t(
                    'Excluded from the new variant. Click to include it again.',
                  ),
                )}
                {line.condition !== 'default' && (
                  <span className="daedalus-stylish-inspector__condition daedalus-ui-chip">
                    {conditionText(line.condition, guide?.conditions)}
                  </span>
                )}
                <code className="daedalus-stylish-draft__fact">{`${line.property}: ${line.value}`}</code>
              </div>
            ))}
          </div>
        ))
      )}
    </div>
  );
};

/**
 * The style inspector panel interior.
 *
 * A passive follower of the selection: renders property sections for
 * whatever element is selected, an empty state otherwise. Everything it
 * knows arrives through the state source so the same component renders
 * in-page (leader source) and in the pop-out window (satellite source);
 * the style guide payload is host configuration (drupalSettings), passed
 * down by the host wrappers. Edits flow back out through the source too:
 * preview() per input event, commit() on settle — the leader source runs
 * them directly, the satellite source relays them as intents.
 *
 * @param {object} props
 * @param {object} props.source
 *   The state source (see sources/leader-source.js for the interface).
 * @param {object} [props.guide]
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} [props.headerActions]
 *   Extra header buttons the host injects (e.g. the pop-out control) —
 *   host-specific chrome stays out of the shared component.
 * @param {boolean} [props.rail]
 *   Whether the host is a rail panel: only then does the header end with
 *   the panel close control (the in-page host passes it; the satellite
 *   window, outside any sidebar, passes nothing).
 * @param {object} [props.variants]
 *   The variant gesture gates (drupalSettings.daedalusStylish.variants):
 *   {canCreate, canUpdate}. Honest chrome only — the endpoints enforce.
 * @param {object} [props.swatches]
 *   The swatch gesture gates (drupalSettings.daedalusStylish.swatches):
 *   {canCreate}. Honest chrome for the gradient builder's save-as-swatch
 *   gesture — the mint route enforces.
 * @param {object} [props.animations]
 *   The animation gesture gates (drupalSettings.daedalusStylish.animations):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}. Honest chrome for
 *   the Materials › Animations cards; the provider and routes enforce.
 * @param {?object} [props.previewWindow]
 *   The responsive preview payload
 *   (drupalSettings.daedalusUi.responsivePreview, {url, scope}) —
 *   host configuration like the guide, so the satellite window (whose
 *   page carries no daedalus_ui settings) honestly passes none and
 *   the chip row's width menu drops its preview-window tail there.
 */
export const Inspector = ({
  source,
  guide = {},
  headerActions = null,
  rail = false,
  variants = {},
  swatches = {},
  animations = {},
  previewWindow = null,
}) => {
  const [selected, setSelected] = useState(source.getSelected());
  const [styleMap, setStyleMap] = useState(source.getStyleMap());
  // The materials roster (tokens, swatches, variants, stencils as the
  // store holds them): read through the source like the style map, and
  // re-read on every store change (a mint, edit or delete in this window,
  // the paired window, or an AI turn), so every stop row, face, completion
  // and lint below derives from the same roster the canvas delivers.
  const [materials, setMaterials] = useState(source.getMaterials());
  useEffect(() => {
    const unsubscribe = source.onMaterialsChange(setMaterials);
    setMaterials(source.getMaterials());
    return unsubscribe;
  }, [source]);
  // The variant records: the panel-level read the effective-styles
  // resolver needs, so on-variant elements show their variant's values
  // everywhere (widgets, layer dots), not only in the variant area. One
  // list per roster identity, so a delta (a save-as, a push, a rename, a
  // delete, here or in the paired window) re-renders every consumer and
  // nothing else re-derives it.
  const library = useMemo(() => materials.ofKind('variant'), [materials]);
  // The selection's part facet: a part selector under the selected
  // element, or null. Spine state read through the source (the tree's part
  // rows and canvas part clicks commit it; any element-only selection
  // gesture clears it); the panel just renders parent · part.
  const [selectedPart, setSelectedPart] = useState(source.getSelectedPart());
  // The canvas state vector and the selection-scoped force state: the two
  // inputs the editing compound derives from (the inspector follows the
  // canvas; it holds no condition of its own). Both are
  // source-owned (the vector lives in daedalus_ui's top-bar facade,
  // the force state in the leader source); optional chaining keeps older
  // sources honest — without them the compound degrades to 'default'.
  const [vector, setVector] = useState(source.getCanvasVector?.() ?? null);
  const [forceState, setForceState] = useState(
    source.getForceState?.() ?? null,
  );
  // The place state (four controls, one loud mode): {place, editing},
  // place one of surface-state.js's places (styles, the host map
  // rendered per mode; materials, the catalog); editing the mounted
  // session's edited document reference, or null. Source-owned (so both
  // hosts agree and it survives every mode switch untouched); the panel
  // projects from it per render (normalizePlace, and the honest wearing
  // projection below: an editing reference the selection does not wear
  // renders as no document, never a dangling write target). setPlace
  // routes through the source; the intent ref bridges the satellite's
  // echo gap so the leave-prompt never reads an in-flight session entry
  // as leaving.
  const [placeValue, setPlaceValue] = useState(
    source.getPlace?.() ?? { place: 'styles', editing: null },
  );
  const editIntent = useRef(null);
  const setPlace = (place, editing = null) => {
    const next = { place, editing };
    if (source.setPlace) {
      source.setPlace(next);
    } else {
      setPlaceValue(next);
    }
  };
  // The inspector mode: ONE three-state enum, basic (curated
  // controls), advanced (raw property groups + style-target rows), code
  // (the islands CSS editor). Module-scoped and localStorage-backed so the
  // leader page and the satellite window share it; the leader entry point
  // mirrors it onto the Show-menu style-target visibility. Writes go
  // through the store, never local state, so both windows always agree.
  // A mode is a preference over the styling place, so picking one always
  // lands there: the chrome row lights exactly one of its four buttons.
  const [mode, setMode] = useState(uiModeStore.get());
  useEffect(() => uiModeStore.subscribe(setMode), []);
  // The code view's reading scope (element / subtree / page): the mode's
  // sibling preference (code-scope.js), read here and written through the
  // store so both windows agree; its segments exist only in code mode.
  const [scope, setScope] = useState(codeScopeStore.get());
  useEffect(() => codeScopeStore.subscribe(setScope), []);
  // The rendered place: the stored value normalized to the vocabulary
  // (stale values, the deleted pill row's values and the old
  // code-only surfaces, land home on styles). The wearerless rule is
  // total: the editing session renders in wearer
  // context — guide declarations, parts, honesty reads, and canvas
  // previews all resolve through a concrete element — so a variant with
  // no wearer on this page gets no wearerless editor; the library card's
  // Edit styles disables honestly until something wears it. The way in
  // is applying it, not a second path.
  const shownPlace = normalizePlace(placeValue.place);
  const editing = placeValue.editing;
  const pickMode = (next) => {
    uiModeStore.set(next);
    if (shownPlace !== 'styles') {
      setPlace('styles', editing);
    }
  };
  // The pending/push engine's one active session (pending.js shapes):
  // pending edits for an edited variant document (update sessions — the
  // session body stages into them) or a save-as-variant capture
  // expressed as pending lines (save sessions). Panel state, not source
  // state: the session is this window's editing posture, committed
  // through direct endpoints either host can call; the PushBar below the
  // body is its one commit surface, and it survives every mode switch
  // untouched. The parked flag sits beside it (session-frame-model.js
  // park/resume): a parked session keeps its staged work behind the
  // resume chip while the synced `editing` is cleared and the mode
  // dismounts in every window.
  const [pending, setPending] = useState(null);
  const [parked, setParked] = useState(false);
  // Belt: the parked flag describes a held session; it never outlives
  // one (every discard path resets it, this catches strays).
  useEffect(() => {
    if (!pending && parked) {
      setParked(false);
    }
  }, [pending, parked]);
  // The update session's site-wide impact ("worn in N places"): ONE
  // fetch per session entry, held here because two mode surfaces speak
  // the number — the frame's blast line and the push bar's impact text —
  // and each fetching its own copy meant a duplicate GET per entry
  // (an accepted debt, now lifted). Keyed on the session's
  // reference, so it survives park/resume; null while unanswered. The
  // leave-prompt below keeps its own decision-time fetch: the choice may
  // come long after entry and its number must be fresh when it is made.
  const usageReference = pending?.kind === 'update' ? pending.reference : null;
  const [sessionUsages, setSessionUsages] = useState(null);
  useEffect(() => {
    setSessionUsages(null);
    if (usageReference === null) {
      return undefined;
    }
    let alive = true;
    fetchUsage(usageReference).then(
      ({ usages }) => alive && setSessionUsages(usages),
      () => alive && setSessionUsages(null),
    );
    return () => {
      alive = false;
    };
  }, [usageReference]);
  // Whether a session has anything the leave-prompt must be loud about:
  // staged lines for an update, a named non-empty capture for a save.
  // Anything short of that discards silently — there is no consequence.
  const committableSession = (session, layout) =>
    session.kind === 'update'
      ? sessionCount(session) > 0
      : session.label.trim() !== '' &&
        sessionCount(
          session,
          saveStyles(
            styleMap ?? {},
            session.anchor,
            layout,
            library,
            session.axis,
          ),
        ) > 0;
  // A session waiting behind the leave-prompt: opening a new session over
  // an open committable one never clobbers it silently — the old session
  // resolves through the prompt first, then the queued one installs. A
  // parked session unparks into the same lane: requesting a new session
  // over it resolves the parked work through the prompt too, never a
  // silent clobber behind the chip.
  const queuedSession = useRef(null);
  const replaceSession = (next, layout) => {
    setParked(false);
    if (pending && committableSession(pending, layout)) {
      queuedSession.current = next;
    } else {
      setPending(next);
    }
  };
  // Entering the editing session (the doorways' shared half): the synced
  // `editing` reference mounts the mode in every window (the surface
  // underneath passes through untouched, the session is an OVERLAY) and
  // the session installs through the displacement lane. The intent ref
  // bridges the satellite's editing echo gap, so the leave-prompt never
  // reads its own in-flight entry as leaving.
  const enterSession = (reference, next) => {
    editIntent.current = reference;
    setPlace(shownPlace, reference);
    replaceSession(next, source.getLayout?.() ?? []);
  };
  // The update doorways (wearing-row body, overridden-by badge, the
  // library card's Edit styles, the header switcher): one openDocument
  // vocabulary. Entering the edited document's own open session (parked
  // included) re-enters it instead of minting a fresh one. A doorway may
  // name the condition to open AT (a posed wear surface: the wear bar's
  // sub-line, the layout segment's "stacks at Narrow"): the session has
  // no condition of its own, its rows read and write at the canvas's
  // compound, so opening at a condition drives the canvas there first,
  // and only when it is not already there (re-posing an equal compound
  // would move a width the designer set inside the band).
  const openDocument = (reference, at = null) => {
    if (at !== null && at !== condition) {
      driveCanvas(at);
    }
    if (pending !== null && sessionFocuses(pending, reference)) {
      editIntent.current = reference;
      setParked(false);
      setPlace(shownPlace, reference);
      return;
    }
    enterSession(reference, updateSession(reference, selected));
  };
  // The save-as-variant doorway (the header's save icon, all three
  // modes): the creating posture, the same session frame over the
  // draft's document, pre-staged from the selection's capture. The
  // capture derives live; the session only holds the name, the axis
  // pick, and the exclusions, and the Push/Discard bar is its commit
  // surface. A draft's frame mounts off the session itself, `editing`
  // stays null (a draft has no document reference until Push mints one);
  // an open editing session's reference clears first, resolving it
  // through the ordinary displacement prompt. The draftIntent ref
  // bridges the satellite's echo gap on that clear, exactly like
  // editIntent bridges an update entry.
  const draftIntent = useRef(false);
  const startSaveSession = () => {
    draftIntent.current = true;
    setPlace(shownPlace, null);
    if (pending?.kind === 'save' && pending.anchor === selected) {
      // The doorway re-entered with this element's draft already open
      // (or parked): just return to it.
      setParked(false);
      return;
    }
    const layout = source.getLayout?.() ?? [];
    const vocabulary = axisVocabulary(
      guide,
      selected,
      source.getBlockTypes(),
      source.getSectionLayouts(),
      layout,
    );
    replaceSession(saveSession(selected, vocabulary[0] ?? 'style'), layout);
  };
  // Parking (the session header's back arrow) and resuming (the chip):
  // park clears the synced `editing` (the mode dismounts everywhere,
  // the places return) while the session stays panel state behind the
  // parked flag. Park is the quiet exit (the tweak-an-element-then-
  // resume workflow); nothing is asked. Resume remounts exactly where it
  // parked: an update session restores `editing` from its own reference,
  // a draft resumes with `editing` still null. Resuming after the
  // selection stopped wearing the document resolves through the ordinary
  // leave-prompt: honest, never silent.
  const parkSession = () => {
    const next = parkTransition();
    setParked(next.parked);
    setPlace(shownPlace, next.editing);
  };
  const resumeSession = () => {
    const next = resumeTransition(pending);
    setParked(next.parked);
    editIntent.current = next.editing;
    setPlace(shownPlace, next.editing);
  };
  // "Update from this element" (the wearing rows' menu): the retired
  // update dropdown re-expressed in the pending lane — the wearer's own
  // diff against the variant pre-stages as dismissible pending lines and
  // the editing session opens on the document, the whole staged extent
  // in place. Push commits, per line dismissal prunes. Staging into the
  // document's own open session MERGES (stageLine upserts); any other
  // open session resolves through the leave-prompt first.
  const updateFromElement = (reference) => {
    const layout = source.getLayout?.() ?? [];
    const lines = updateFromElementLines(
      styleMap ?? {},
      selected,
      reference,
      library,
      layout,
    );
    const merge = pending !== null && sessionFocuses(pending, reference);
    const base = merge
      ? reanchorSession(pending, selected)
      : updateSession(reference, selected);
    const next = lines.reduce(
      (session, line) => stageLine(session, line),
      base,
    );
    if (merge) {
      editIntent.current = reference;
      setParked(false);
      setPlace(shownPlace, reference);
      setPending(next);
    } else {
      enterSession(reference, next);
    }
  };
  // Which variant's lifecycle card is unfolded on the Materials place.
  // Panel state like the pending session (each window browses its own
  // library face); the place itself is the chrome row's business.
  const [libraryCard, setLibraryCard] = useState(null);
  // Which catalog section the Materials place shows: 'variants',
  // 'palette' or 'animations'. The Palette and Animations faces live
  // WITHIN Materials rather than as further places: the chrome row
  // answers "what am I looking at" with the styling place's modes and the
  // Materials toggle, and Materials IS the mode-independent
  // catalog of site-wide reusable styling content — swatches and
  // animations are the same species as variants at value scope, so they
  // section the catalog instead of growing the place vocabulary. Panel
  // state like the open card (each window browses its own catalog), so
  // satellite parity needs no surface-sync change.
  const [catalogSection, setCatalogSection] = useState('variants');
  // "Variant settings…" (the wearing rows' menu, every mode): flip to
  // the Materials surface with that variant's card open. Cards key by
  // record id, the one reference identity.
  const openVariantSettings = (reference) => {
    setLibraryCard(reference);
    setCatalogSection('variants');
    setPlace('materials', editing);
  };
  // "Edit styles" (the library card): into the editing session on the
  // variant, anchored on a wearer of it on this page (pageWearer: the
  // selection when it wears it, else a root wearer from the map — the
  // document renders in wearer context). Selecting first, then entering:
  // the selection-move rule sees a selection that wears the edited
  // document, so the jump sticks.
  const editStylesFromCard = (variant) => {
    const wearer = pageWearer(styleMap ?? {}, variant, selected);
    if (!wearer) {
      return;
    }
    if (wearer.key !== selected) {
      source.select?.(wearer.key);
    }
    openDocument(wearer.reference);
  };
  // "Apply to selection" (the library card): the browse-first doorway —
  // the same instant apply_variant the styling place's variant picker speaks,
  // aimed from the catalog at the current selection. The card computes
  // its own doorway state (library-model.js applyDoorway) from the offer
  // below; this is just the op, promise-returned so the card's busy
  // guard rides it.
  const applyFromCard = (variant) =>
    source.applyVariant(
      selected,
      variant.id,
      Drupal.t('Applied variant @label', {
        '@label': variant.label ?? variant.id,
      }),
    );
  // Outline Variant — ONE toggled gesture in the focus treatment: the
  // coverage outline on the canvas plus the matching row marks in the
  // page elements panel (both derive from the same paint spec in
  // canvas-paint.js), toggled from the wear bar's row menu or the library
  // card through the one outlineGesture object (form-blindness handled
  // there once). Held here, not in the bar, so the outline survives mode
  // switches; the unmount guard clears whatever this panel painted. The
  // row hover flash composes in as a pulsing second layer. Layers carry
  // no color: focus chrome wears --daedalus-focus; per-variant hues
  // keep identity duty only.
  const [outlined, setOutlined] = useState(null);
  const [flash, setFlash] = useState(null);
  const outline = outlineGesture(outlined, setOutlined);
  useEffect(() => {
    const paintLayer = (reference, isFlash) =>
      reference === null
        ? { id: ELEMENT_LAYER, variant: null, flash: isFlash }
        : {
            id: variantLayerId(reference),
            variant: reference,
            label: findVariant(library, reference)?.label ?? reference,
            flash: isFlash,
          };
    const layers = [];
    if (outlined) {
      layers.push(paintLayer(outlined, false));
    }
    if (flash && !(flash.variant !== null && outline.isOn(flash.variant))) {
      layers.push(paintLayer(flash.variant, true));
    }
    source.setCanvasPaint?.(layers.length ? { layers } : null);
  }, [outlined, flash, library]);
  useEffect(() => () => source.setCanvasPaint?.(null), []);
  // The canvas is part of the mode chrome: session entry auto-enables
  // Outline Variant over the session's document (the blast radius is
  // visible where the user is already looking); exit (park, push,
  // discard, any editing clear) restores whatever was outlined before.
  // Identity hue on the canvas, per the settled decision: the outline
  // speaks the variant's own color (outlineGesture unchanged); the mode
  // purple frames the panel only. A draft session outlines nothing (its
  // document has no wearers until Push mints it), and a mid-session
  // manual toggle is the user's to keep: this effect moves only on
  // `editing` edges.
  const priorOutlined = useRef(undefined);
  useEffect(() => {
    if (editing !== null) {
      if (priorOutlined.current === undefined) {
        priorOutlined.current = outlined;
      }
      setOutlined(editing);
      return;
    }
    if (priorOutlined.current !== undefined) {
      setOutlined(priorOutlined.current);
      priorOutlined.current = undefined;
    }
  }, [editing]);
  // Gesture outcomes (statuses and failures alike) ride source.notify to
  // the edit-mode messenger, the page builder's one notification spine
  // (toast + sidebar history; the satellite broadcasts to the leader).
  // The old inline message line is gone; only in-context correction
  // surfaces (mint error, push-bar under-bar line) stay inline.
  // The header's action cluster is secondary chrome: hidden at clean
  // density until the pointer nears.
  const actionsRef = useConcealed();
  // Leaving a session with pending edits prompts Push/Discard (the
  // pending engine's honesty net, re-keyed to the mode): an update
  // session leaves when the selection stops wearing its variant (a move
  // to a non-wearer, a detach/remove/swap here or in the satellite), the
  // synced `editing` moves to a DIFFERENT document (the header switcher;
  // one session per document), or `editing` clears without a park (a
  // deliberate exit, like the overridden-by badge's jump to the
  // element's own layer). A save session leaves when the selection walks out of
  // its anchor's subtree (moving WITHIN the capture is review, not
  // leaving) or a real document mounts the mode: the draft holds the
  // panel only while `editing` is null. A queued replacement
  // (update-from-element, save-as, or a doorway over an open session)
  // displaces either kind. A PARKED session is suspended, not being
  // left: the effect stands down until it resumes or a replacement
  // unparks it into the displacement lane. The session detaches
  // immediately — the bar drops and a second change cannot double-prompt
  // — and the dialog decides whether the snapshot commits. Nothing
  // committable (no staged lines; an unnamed or empty capture) discards
  // silently: there is no consequence to be loud about. A
  // wearer-to-wearer selection move re-anchors instead (surface-state.js
  // reanchorSession): the staged lines are variant-relative, only the
  // preview context travels.
  const promptBusy = useRef(false);
  useEffect(() => {
    if (!pending || parked || promptBusy.current) {
      return;
    }
    const layout = source.getLayout?.() ?? [];
    const displaced = queuedSession.current !== null;
    let leaving;
    if (pending.kind === 'update') {
      const wears =
        Boolean(selected) &&
        Boolean(wearingOf(styleMap?.[selected], pending.reference));
      if (!displaced && editIntent.current !== null) {
        if (editing !== editIntent.current) {
          // Our own entry intent is still in flight (the satellite
          // echo); the session is arriving, not leaving.
          return;
        }
        editIntent.current = null;
      }
      const editingConflict =
        editing === null || !sessionFocuses(pending, editing);
      leaving = displaced || !wears || editingConflict;
      if (!leaving && selected !== pending.anchor) {
        setPending((previous) => reanchorSession(previous, selected));
      }
    } else {
      if (!displaced && draftIntent.current) {
        if (editing !== null) {
          // The draft's own editing clear is still echoing back (the
          // satellite gap): the session is arriving, not leaving.
          return;
        }
        draftIntent.current = false;
      }
      leaving =
        displaced ||
        !selected ||
        (selected !== pending.anchor &&
          !findNode(
            subtreeNodes(
              pageNodes(layout, source.getRootUuid()),
              pending.anchor,
            ),
            selected,
          )) ||
        editing !== null;
    }
    if (!leaving) {
      return;
    }
    const session = pending;
    setPending(null);
    if (
      session.kind === 'update' &&
      editing !== null &&
      sessionFocuses(session, editing)
    ) {
      // The mode dismounts with its session: an `editing` reference that
      // still names the departed document clears with it (a wearing
      // dropped by detach/remove/swap fires no selection move, so the
      // selection-move rule cannot be the one to clear it). A doorway's
      // NEW reference (the displacement case) names the next document
      // and stays.
      setPlace(shownPlace, null);
    }
    const installQueued = () => {
      if (queuedSession.current !== null) {
        setPending(queuedSession.current);
        queuedSession.current = null;
      }
    };
    if (!committableSession(session, layout)) {
      installQueued();
      return;
    }
    promptBusy.current = true;
    const ask =
      session.kind === 'update'
        ? // The loud count rides the prompt too: the impact is on screen
          // before the choice, even when the bar has scrolled out of mind.
          fetchUsage(session.reference)
            .catch(() => ({ usages: null }))
            .then(({ usages }) => {
              const label =
                findVariant(library, session.reference)?.label ??
                session.reference;
              return Drupal.Daedalus.dialog.confirm({
                title: Drupal.t('Pending variant changes'),
                message: Drupal.formatPlural(
                  sessionCount(session),
                  'Push 1 pending change to "@label"?',
                  'Push @count pending changes to "@label"?',
                  { '@label': label },
                ),
                detail:
                  usages === null
                    ? Drupal.t(
                        'Every piece of content wearing the variant updates.',
                      )
                    : // The mode's unit is "places" (the blast line's word);
                      // the Materials card keeps the catalog's
                      // "pieces of content".
                      Drupal.formatPlural(
                        usages,
                        'This restyles 1 place site-wide.',
                        'This restyles @count places site-wide.',
                      ),
                confirmLabel: Drupal.t('Push'),
                cancelLabel: Drupal.t('Discard'),
              });
            })
        : Drupal.Daedalus.dialog.confirm({
            title: Drupal.t('Unsaved variant'),
            message: Drupal.t('Save the captured styles as "@label"?', {
              '@label': session.label.trim(),
            }),
            detail: Drupal.t(
              'Discarding leaves every element exactly as it looks.',
            ),
            confirmLabel: Drupal.t('Save variant'),
            cancelLabel: Drupal.t('Discard'),
          });
    ask
      .then((confirmed) => {
        if (!confirmed) {
          return null;
        }
        // A refused push is the op queue's source's report (reportFailure on
        // the leader, the satellite's settle); nothing to redo here.
        return commitSession({
          source,
          guide,
          session,
          variants: library,
          styleMap,
        }).catch(() => null);
      })
      .finally(() => {
        promptBusy.current = false;
        installQueued();
      });
  }, [pending, parked, selected, editing, styleMap]);

  // The selection-move rule (surface-state.js selectionMove): the
  // session is an overlay, so the
  // surface passes through untouched in every branch. An editing
  // session survives the move when the new selection wears the edited
  // document (the session re-anchors above), otherwise `editing` clears
  // and the mode exits (the leave-prompt effect above fires first when
  // pending work would be left behind — it watches the same wearing
  // fact).
  const movedFor = useRef(selected);
  useEffect(() => {
    if (movedFor.current === selected) {
      return;
    }
    movedFor.current = selected;
    const moved = selectionMove(
      { place: placeValue.place, editing },
      {
        entry: styleMap?.[selected],
        pending: pending ? sessionCount(pending) : 0,
      },
    );
    if (moved.editing !== editing) {
      setPlace(moved.place, moved.editing);
    }
  }, [selected, styleMap, library]);

  // Subscribe, THEN re-read: a broadcast can land in the gap between first
  // render and this effect (the satellite's hello/snapshot handshake
  // reliably does), and the source won't re-announce state it already
  // holds — the read closes the gap.
  useEffect(() => {
    const unsubscribers = [
      source.onSelectionChange((current) => setSelected(current)),
      source.onStyleMapChange((map) => setStyleMap(map)),
      source.onPartChange(({ part }) => setSelectedPart(part)),
      // The vector arrives as a snapshot object; spread it so a re-posed
      // axis always lands as a fresh reference (satellite broadcasts
      // already clone, the leader facade already copies — this is belt).
      source.onCanvasVectorChange?.((state) => setVector({ ...state })) ??
        (() => {}),
      source.onForceStateChange?.((state) => setForceState(state)) ??
        (() => {}),
      source.onPlaceChange?.((state) => setPlaceValue(state)) ?? (() => {}),
    ];
    setSelected(source.getSelected());
    setStyleMap(source.getStyleMap());
    setSelectedPart(source.getSelectedPart());
    setVector(source.getCanvasVector?.() ?? null);
    setForceState(source.getForceState?.() ?? null);
    setPlaceValue(
      source.getPlace?.() ?? { place: 'styles', editing: null },
    );
    return () => unsubscribers.forEach((unsubscribe) => unsubscribe());
  }, [source]);

  // The mode dies with its panel: the pending session is panel state,
  // so when THIS Inspector unmounts (popping out swaps the in-page
  // panel for its placeholder) any open session dies with it — and the
  // synced `editing` must not outlive it, or the next panel (the
  // satellite booting off the snapshot, or the in-page panel returning
  // on bye) mounts a half-mode: auto-outline on, no session, no exits.
  // The satellite-close half of the same rule lives in the popout
  // leader's bye handler, because a closing window runs no effect
  // cleanup. Read through the source, not closed-over state: cleanup
  // fires long after this render's values went stale.
  useEffect(
    () => () => {
      const held = source.getPlace?.();
      if (held && held.editing !== null) {
        source.setPlace?.({ place: held.place, editing: null });
      }
    },
    [source],
  );

  // Everything below the breadcrumb targets the drill: the part's full
  // sub-element key when a part is targeted, the selection itself otherwise.
  const target =
    selected && selectedPart ? `${selected}::${selectedPart}` : selected;
  const parsed = parseKey(target);
  // The mirror holds the ROOT entity's map — the one this panel reads and
  // writes. An entity or entity-field key naming a DIFFERENT entity styles
  // through that entity's own map, which this page's mirror/ops can't
  // reach: honest not-enabled state instead of a lying empty panel.
  // (Section, region, and block keys always belong to the root map.)
  const rootUuid = source.getRootUuid();
  const foreignEntity =
    parsed &&
    (parsed.form === 'entity' || parsed.form === 'field') &&
    rootUuid &&
    parsed.uuid !== rootUuid;
  // The kind-appropriate discriminator: inline block type for blocks,
  // layout plugin id for sections (bundleForKey()).
  const bundle = bundleForKey(
    parsed,
    source.getBlockTypes(),
    source.getSectionLayouts(),
  );
  // Basic mode's target rule: a drilled INSTANCE part (a minted text
  // element, resolved through the guide's instance namespaces — the one
  // resolution rule as a routing question) is content the user selected,
  // so the curated panel targets the drill itself and instance-row
  // selection and canvas opt+click produce the same panel. A drilled
  // SHELL part keeps the selection: its curated surface is the owner's
  // lift (element-bound bindings on the owner's panel, the image
  // pattern), so retargeting it would hide the very controls that style
  // it.
  const instanceDrill =
    Boolean(selectedPart) && isInstancePartKey(guide, parsed, bundle);
  const basicTarget = instanceDrill ? target : selected;
  // The axes the mounted sections claim for this mode: a section that
  // IS a wearing's surface (basic mode's Layout segment for
  // container_layout) owns that row, so the wear bar drops it; advanced
  // and code mount no such section and the row shows there. Aggregated
  // here from what actually mounts (the Layout section mounts in basic
  // outside an instance drill), never special-cased in the wear bar.
  const claimedAxes =
    mode === 'basic' && !instanceDrill ? LAYOUT_SECTION_CLAIMS : [];

  // The one effective-styles read ("a variant is a preset, not a
  // stylesheet"): the drill target's EFFECTIVE condition-keyed styles,
  // the root's variant references resolved through the library in
  // delivery order (the root scope's axis vocabulary). Widgets read resolved.entry so on-variant
  // elements populate like custom-styled ones; provenance renders as the
  // per-row mark and menu (the tables below), not a panel banner.
  const axes = axisVocabulary(
    guide,
    selected,
    source.getBlockTypes(),
    source.getSectionLayouts(),
    source.getLayout?.() ?? [],
  );
  const resolved = effectiveEntry(styleMap, target, library, axes);
  // The skin defaults tier's provenance source: what the active skin's site
  // defaults supply for this element (base tag under kind under refinement,
  // mirroring delivery). Rows render it as the skin ghost and the skin
  // origin; it never joins the editable entry. The
  // base:{tag} layer needs the drilled element's rendered tag, a fact only
  // the canvas DOM holds (a collapsed block IS its h2); the leader reads
  // it live and relays the selection's tags with every broadcast, so the
  // satellite's readCanvasTag answers the same tag for the selection.
  const targetTag = selected
    ? (source.readCanvasTag?.(selected, selectedPart) ?? null)
    : null;
  const skinEntry = skinDefaultEntry(
    guide,
    target,
    source.getBlockTypes(),
    source.getSectionLayouts(),
    targetTag,
  );
  // Beneath the skin's, the element's declaration's own defaults (the
  // box facts a block type, block plugin, or layout ships for its own
  // markup; code, served skin or no skin): the refinement scope alone,
  // named in the origin by the declaring scope, its one doorway inert.
  const declaredEntry = declaredDefaultEntry(
    guide,
    target,
    source.getBlockTypes(),
    source.getSectionLayouts(),
  );
  const declaredScope = captureScope(
    target,
    source.getBlockTypes(),
    source.getSectionLayouts(),
  );
  // The provenance tables the rows read whole (provenance-model.js
  // rowOrigin): the own (condition, property) tuples beating a worn
  // variant, and for every tuple a worn reference defines, the delivery
  // winner. References stay references; `labelOf` and `valueOf` below
  // resolve them where a row names one.
  const shadowed = shadowedProperties(styleMap, target, library, axes);
  const suppliers = propertySuppliers(styleMap, target, library, axes);
  const labelOf = (reference) =>
    findVariant(library, reference)?.label ?? reference;
  const valueOf = (reference, at, property) =>
    suppliedValue(styleMap, target, library, reference, at, property, axes);

  // The editing compound: derived from the canvas pose + force state,
  // never held. Writes land here, and the canonical spelling falls out of
  // the derivation (parts assemble in the payload's axis-declaration
  // order), so lookups against the stored map always agree.
  const condition = vectorCondition(vector, forceState, guide.conditions ?? {});
  // "Drive the canvas there": pose the vector and force state so the
  // clicked condition renders — and, because the compound derives from the
  // pose, so writes land at it. Axes the condition does not name reset to
  // default; the force state routes through the source (selection-scoped,
  // leader-stamped), the vector through the top-bar facade.
  const driveCanvas = (name) => {
    const pose = conditionPose(name, guide.conditions ?? {});
    source.setForceState?.(pose.state);
    source.setCanvasVector?.(pose.vector);
  };
  const handlePreview = (property, value) =>
    source.preview(target, condition, property, value);
  const handleRelease = (property, value) =>
    source.previewRelease(target, condition, { [property]: value });
  // Editing never detaches (the layered entry model): the op writes an
  // override layer and the references stay. The overriding origin on the
  // row is the honest indication the old detach warning used to be.
  const handleCommit = (property, value) =>
    source.commit({
      key: target,
      condition,
      properties: { [property]: value },
      label: Drupal.t('Styled @property', { '@property': property }),
    });
  // The media rows' render inputs, bundled once per render: the uuid → URL
  // table (thumbnails; the leader reads its live object, the satellite its
  // last broadcast), the honest can-open gate, and the pick trigger bound
  // to the current target and condition. The source owns the whole pick
  // delivery; optional chaining keeps older sources (tests, embeds) safe.
  const media = {
    urls: source.getMediaUrls?.() ?? {},
    canPick: !!source.canPickMedia?.(),
    onPick: (property) =>
      source.pickMedia?.({
        key: target,
        condition,
        property,
        label: Drupal.t('Styled @property', { '@property': property }),
      }),
  };

  // The session frame's posture for this render (session-frame-model.js
  // framePosture): 'editing' mounts the frame over the variant document,
  // 'creating' over the draft, 'parked' renders the resume chip, null
  // renders plain places, including an update session whose `editing`
  // echo is still in flight (nothing mounts anchorless).
  const posture = framePosture(pending, editing, parked);
  // The creating posture's mint-time consent chip: the draft's anchor
  // sits inside a stencil placement, so the new variant will be born as
  // that stencil's own switchable look. session-frame-model.js
  // mintConsent is the ONE read behind the chip and commitSession's
  // stencil send, so what the chip says is what Save commits; null
  // renders no chip (unlinked, no provenance, or not a draft). The
  // label resolves through the store's stencil record, the same in both
  // windows.
  const consent =
    posture === 'creating'
      ? mintConsent(
          pending,
          stencilProvenance(pending.anchor, source.getLayout?.() ?? []),
          materials,
        )
      : null;
  // The edited variant document: the honest wearing projection — the
  // synced `editing` counts only while the selection actually wears it
  // (wearingOf; a detached/removed/stale reference renders as no
  // document, never a dangling write target) — plus the edit gates up
  // front: a locked variant is read-only (the distro lock as the record's
  // updatable flag says it; a shipped variant opens under the developer
  // gate), and without the update grant there is no pending lane to
  // stage into.
  const editedWearing =
    selected && Boolean(parsed) && !foreignEntity && editing
      ? wearingOf(styleMap?.[selected], editing)
      : null;
  const editedVariant = editedWearing ? findVariant(library, editing) : null;
  const documentEditable =
    editedVariant !== null &&
    !locked(editedVariant) &&
    Boolean(variants?.canUpdate);
  // The winner doorway's navigation: the wearer's own layer wins → exit
  // the session onto the styling place, where that value lives (a
  // deliberate exit; the leave-prompt resolves pending work first);
  // another variant wins → switch the session to its document.
  const navigateToWinner = (winner) => {
    if (winner?.variant == null) {
      setPlace('styles', null);
    } else {
      openDocument(winner.variant);
    }
  };

  // The widget modes' document context (the editing session's Sections
  // rendering): `stage` settles ONE value against the variant's stored
  // baseline (pending.js stageValue — a set, a null unset, or a revert's
  // dismiss), `winner` is the wearer-cascade honesty read behind preview
  // gating and the overridden origin (the element's own layer always
  // wins; among worn references the delivery winner). The doorway minted
  // the session at entry (one session per edited document); a settle
  // re-mints only when the open session no longer matches this document
  // and anchor.
  const widgetSheet =
    mode !== 'code' &&
    posture === 'editing' &&
    editedWearing &&
    documentEditable
      ? {
          reference: editedWearing.variant,
          label: editedVariant.label ?? editedWearing.variant,
          fragment: editedWearing.fragment,
          styles: editedVariant.value ?? {},
          session:
            pending?.kind === 'update' &&
            pending.reference === editedWearing.variant
              ? pending
              : null,
          stage: (slot, conditionName, property, value) =>
            setPending((previous) => {
              const open =
                previous?.kind === 'update' &&
                previous.reference === editedWearing.variant &&
                previous.anchor === selected;
              const next = stageValue(
                open
                  ? previous
                  : updateSession(editedWearing.variant, selected),
                editedVariant.value ?? {},
                slot,
                conditionName,
                property,
                value,
              );
              // Do not mint an empty session for a settle that staged nothing
              // (a revert with no session open).
              return !open && next.lines.length === 0 ? previous : next;
            }),
          dismiss: (id) =>
            setPending((previous) =>
              previous ? dismissLine(previous, id) : previous,
            ),
          winner: (targetKey, conditionName, property) =>
            documentValueWinner(
              styleMap?.[targetKey],
              propertySuppliers(styleMap, targetKey, library, axes),
              editedWearing.variant,
              conditionName,
              property,
            ),
        }
      : null;
  // The Sections-level slot projection: the drill target's slice of the
  // variant's document (the wearing fragment + the drilled part's
  // relative key), session overlaid — what the rows read, plus the
  // pending table their chrome renders.
  const sheetContext = widgetSheet
    ? (() => {
        const slot =
          widgetSheet.fragment + (selectedPart ? `::${selectedPart}` : '');
        const projection = documentSlotEntry(
          widgetSheet.styles,
          slot,
          widgetSheet.session,
        );
        return {
          slot,
          entry: projection.entry,
          pending: projection.pending,
          stage: (conditionName, property, value) =>
            widgetSheet.stage(slot, conditionName, property, value),
          dismiss: widgetSheet.dismiss,
          winner: (conditionName, property) =>
            widgetSheet.winner(target, conditionName, property),
          baselineOf: (conditionName, property) =>
            widgetSheet.styles?.[slot]?.[conditionName]?.[property],
          onWinner: navigateToWinner,
        };
      })()
    : null;
  // The code mode's document context (VariantDocumentView): the whole
  // document over the variant's own key space; islands stage whole
  // (slot, condition) diffs (stageIslandLines), the session opening on
  // first staging exactly like the widget lane.
  const codeDocument =
    mode === 'code' &&
    posture === 'editing' &&
    editedWearing &&
    documentEditable
      ? {
          reference: editedWearing.variant,
          label: editedVariant.label ?? editedWearing.variant,
          styles: editedVariant.value ?? {},
          session:
            pending?.kind === 'update' &&
            pending.reference === editedWearing.variant
              ? pending
              : null,
          rootKind: rootKindForScope(editedVariant.scope ?? null),
          stage: (relative, conditionName, lines) =>
            setPending((previous) => {
              const open =
                previous?.kind === 'update' &&
                previous.reference === editedWearing.variant &&
                previous.anchor === selected;
              // No session and nothing settled: do not mint an empty session
              // (the bar would appear on every blur under a fresh document).
              if (!open && !lines.length) {
                return previous;
              }
              return stageIslandLines(
                open
                  ? previous
                  : updateSession(editedWearing.variant, selected),
                relative,
                conditionName,
                lines,
              );
            }),
          dismiss: (id) =>
            setPending((previous) =>
              previous ? dismissLine(previous, id) : previous,
            ),
        }
      : null;

  // The document preview reconciler: staged session lines within the
  // wearing's slice preview on this wearer — exactly the honesty rule
  // the rows apply while typing (values only, and only where the
  // document wins delivery; unsets preview nothing) — and RELEASE when
  // their line leaves the session (dismissed, discarded, pushed,
  // re-typed to baseline elsewhere) or stops winning. Centralized here
  // so every exit route cleans up, whichever surface staged the line;
  // the rows own only their in-flight keystrokes. Widget modes only: the
  // code islands manage their own staged previews.
  const sheetPreviews = useRef(new Map());
  useEffect(() => {
    const desired = new Map();
    if (widgetSheet?.session && selected) {
      widgetSheet.session.lines.forEach(
        ({ relative, condition: lineCondition, property, value }) => {
          if (value === null || !relative.startsWith(widgetSheet.fragment)) {
            return;
          }
          const rel = relative.slice(widgetSheet.fragment.length);
          if (rel !== '' && !rel.startsWith('::')) {
            return;
          }
          const targetKey = selected + rel;
          if (widgetSheet.winner(targetKey, lineCondition, property) !== null) {
            return;
          }
          desired.set(`${targetKey}|${lineCondition}|${property}`, {
            key: targetKey,
            condition: lineCondition,
            property,
            value,
          });
        },
      );
    }
    sheetPreviews.current.forEach((previous, id) => {
      const next = desired.get(id);
      if (!next || next.value !== previous.value) {
        source.previewRelease(previous.key, previous.condition, {
          [previous.property]: previous.value,
        });
      }
    });
    desired.forEach((line, id) => {
      const previous = sheetPreviews.current.get(id);
      if (!previous || previous.value !== line.value) {
        source.preview(line.key, line.condition, line.property, line.value);
      }
    });
    sheetPreviews.current = desired;
  }, [pending, mode, selected, editing, parked, styleMap, library]);
  useEffect(
    () => () => {
      sheetPreviews.current.forEach((previous) => {
        source.previewRelease(previous.key, previous.condition, {
          [previous.property]: previous.value,
        });
      });
    },
    [],
  );

  const stylable = Boolean(parsed) && !foreignEntity;

  // The library card's "Apply to selection" offer: the selection's
  // applicable set — the same scope/ownership/tag gates the wear bar's
  // axis dropdowns speak — empty without a stylable selection.
  // Membership is the doorway's fit answer (library-model.js
  // applyDoorway).
  const applyOffer =
    selected && stylable
      ? applicableVariants(
          materials,
          selected,
          source.getBlockTypes(),
          source.getSectionLayouts(),
          source.getLayout?.() ?? [],
          source.readCanvasTag?.(selected, null) ?? null,
        )
      : [];
  // The selection's stencil provenance, for the doorway's honest unfit
  // titles ("belongs to stencil X" beats a vague scope shrug): the same
  // read the offer filter already made inside applicableVariants.
  const applyProvenance =
    selected && stylable
      ? stencilProvenance(selected, source.getLayout?.() ?? [])
      : null;

  // The element breadcrumb: the drill target's identity line, shared by
  // the styling place's three modes. The key chip is a copy affordance:
  // its visible text is middle-truncated (both ends of the key stay
  // readable), a click puts the FULL compound key on the clipboard, and a
  // drag-selection click is not a copy, the user is taking a substring.
  const [copiedKey, setCopiedKey] = useState(false);
  const copyKey = () => {
    if (window.getSelection?.().toString()) {
      return;
    }
    navigator.clipboard.writeText(target).then(() => {
      setCopiedKey(true);
      window.setTimeout(() => setCopiedKey(false), 1200);
    });
  };
  const breadcrumb = (
    <div className="daedalus-stylish-inspector__element">
      <button
        type="button"
        className={`daedalus-stylish-inspector__key${copiedKey ? ' daedalus-stylish-inspector__key--copied' : ''}`}
        title={
          copiedKey
            ? Drupal.t('Copied')
            : `${target}\n${Drupal.t('Click to copy')}`
        }
        onClick={copyKey}
      >
        <code>{middleTruncate(selected)}</code>
      </button>
      {selectedPart && (
        <Fragment>
          <span
            className="daedalus-stylish-inspector__crumb-separator"
            aria-hidden="true"
          >
            ·
          </span>
          <span className="daedalus-stylish-inspector__part" title={selectedPart}>
            {partLabel(
              guide,
              selected,
              selectedPart,
              source.getBlockTypes(),
              source.getSectionLayouts(),
              source.getMarkup?.(selected),
            )}
          </span>
          {/* Re-selecting the bare key clears the part facet (the
              spine's "key without an explicit part" invariant), so every
              part surface drops the drill together. */}
          <button
            type="button"
            className="daedalus-stylish-inspector__part-clear daedalus-ui-chip daedalus-ui-chip--muted"
            title={Drupal.t('Back to the whole element')}
            onClick={() => source.select(selected)}
          >
            ×
          </button>
        </Fragment>
      )}
    </div>
  );

  // The session frame mounts OVER the places (the creating posture, or
  // the editing posture once the honest wearing projection anchors it);
  // the Materials toggle waits while it is mounted; the mode is not a
  // place to navigate away from, Push/Discard/park are the exits. A parked
  // session leaves the places up and renders the resume chip instead.
  const frameMounted =
    posture === 'creating' || (posture === 'editing' && Boolean(editedWearing));
  const draftVocabulary =
    pending?.kind === 'save'
      ? axisVocabulary(
          guide,
          pending.anchor,
          source.getBlockTypes(),
          source.getSectionLayouts(),
          source.getLayout?.() ?? [],
        )
      : [];
  const parkedChip =
    posture === 'parked'
      ? resumeChip(
          pending,
          library,
          pending.kind === 'save'
            ? saveStyles(
                styleMap ?? {},
                pending.anchor,
                source.getLayout?.() ?? [],
                library,
                pending.axis,
              )
            : null,
        )
      : null;

  return (
    <div
      className={`daedalus-stylish-inspector${frameMounted ? ' daedalus-stylish-inspector--session' : ''}`}
    >
      {/* daedalus-ui-panel__* opt into daedalus_ui's shared fixed-header/
          scrolling-body panel anatomy; inert in the satellite window,
          which renders outside the sidebar rail. */}
      <div className="daedalus-stylish-inspector__header daedalus-ui-panel__header">
        <h3>{Drupal.t('Styles')}</h3>
        <div
          className="daedalus-stylish-inspector__actions"
          data-daedalus-ui-conceal="clean"
          ref={actionsRef}
        >
          {/* The element style gestures (save / copy / paste), icon-sized:
              one doorway across all three modes — whole element, stylable
              selection — so presence still means applicability. Save
              opens a pending save session on the Push/Discard bar. */}
          {selected && !selectedPart && stylable && (
            <StyleActions
              source={source}
              guide={guide}
              selected={selected}
              styleMap={styleMap}
              variants={library}
              blockTypes={source.getBlockTypes()}
              sectionLayouts={source.getSectionLayouts()}
              config={variants}
              onSaveVariant={startSaveSession}
            />
          )}
          {headerActions}
          {/* The header grammar's close, always last: the in-page host
              passes rail; the satellite host passes nothing. */}
          {rail && <PanelClose />}
        </div>
      </div>
      {/* The chrome row, fixed under the title in every posture: the
          inspector's four controls answer four questions and this row
          holds the two panel-global ones. The styling modes (left) are
          a preference over the styling place; the Materials toggle
          (right) is the other place, the catalog. Exactly one of the four
          is lit: a mode click always lands in the styling place,
          Materials always in the catalog. Code mode's scope and the
          styling place's pose (the chip row) render below, each only
          where its question exists. */}
      <div className="daedalus-stylish-inspector__chrome">
        <div
          className="daedalus-stylish-inspector__ui-mode daedalus-ui-segments"
          role="group"
          aria-label={Drupal.t('Styling mode')}
        >
          <button
            type="button"
            className={`daedalus-stylish-inspector__ui-mode-button daedalus-ui-segment${shownPlace === 'styles' && mode === 'basic' ? ' daedalus-ui-segment--active' : ''}`}
            aria-pressed={shownPlace === 'styles' && mode === 'basic'}
            onClick={() => pickMode('basic')}
          >
            {Drupal.t('Basic')}
          </button>
          <button
            type="button"
            className={`daedalus-stylish-inspector__ui-mode-button daedalus-ui-segment${shownPlace === 'styles' && mode === 'advanced' ? ' daedalus-ui-segment--active' : ''}`}
            aria-pressed={shownPlace === 'styles' && mode === 'advanced'}
            onClick={() => pickMode('advanced')}
          >
            {Drupal.t('Advanced')}
          </button>
          {/* Reachable without a selection too: code mode then shows the
              page tree (the widget modes need an element); an unstylable
              selection shows its honest empty state. */}
          <button
            type="button"
            className={`daedalus-stylish-inspector__ui-mode-button daedalus-ui-segment daedalus-stylish-inspector__ui-mode-button--code${shownPlace === 'styles' && mode === 'code' ? ' daedalus-ui-segment--active' : ''}`}
            title={Drupal.t('Edit as CSS')}
            aria-pressed={shownPlace === 'styles' && mode === 'code'}
            onClick={() => pickMode('code')}
          >
            {/* The braces glyph (phosphor "brackets-curly"): the code
                segment's identity, replacing the old text glyph. */}
            <svg
              viewBox="0 0 256 256"
              width="13"
              height="13"
              fill="currentColor"
              aria-hidden="true"
            >
              <path d="M43.18,128a29.78,29.78,0,0,1,8,10.26c4.8,9.9,4.8,22,4.8,33.74,0,24.31,1,36,24,36a8,8,0,0,1,0,16c-17.48,0-29.32-6.14-35.2-18.26-4.8-9.9-4.8-22-4.8-33.74,0-24.31-1-36-24-36a8,8,0,0,1,0-16c23,0,24-11.69,24-36,0-11.72,0-23.84,4.8-33.74C50.68,38.14,62.52,32,80,32a8,8,0,0,1,0,16C57,48,56,59.69,56,84c0,11.72,0,23.84-4.8,33.74A29.78,29.78,0,0,1,43.18,128ZM240,120c-23,0-24-11.69-24-36,0-11.72,0-23.84-4.8-33.74C205.32,38.14,193.48,32,176,32a8,8,0,0,0,0,16c23,0,24,11.69,24,36,0,11.72,0,23.84,4.8,33.74a29.78,29.78,0,0,0,8,10.26,29.78,29.78,0,0,0-8,10.26c-4.8,9.9-4.8,22-4.8,33.74,0,24.31-1,36-24,36a8,8,0,0,0,0,16c17.48,0,29.32-6.14,35.2-18.26,4.8-9.9,4.8-22,4.8-33.74,0-24.31,1-36,24-36a8,8,0,0,0,0-16Z" />
            </svg>
          </button>
        </div>
        {/* The Materials toggle: the catalog place, mode-independent,
            selection-free. The session frame replaces the places while
            it is mounted, so the toggle waits it out honestly (a mode
            click still re-bodies the session). */}
        <div className="daedalus-ui-segments">
          <button
            type="button"
            className={`daedalus-stylish-inspector__place daedalus-ui-segment${shownPlace === 'materials' ? ' daedalus-ui-segment--active' : ''}`}
            aria-pressed={shownPlace === 'materials'}
            disabled={frameMounted}
            onClick={() => setPlace('materials', editing)}
          >
            {Drupal.t('Materials')}
          </button>
        </div>
      </div>
      {/* The pose: one chip row, the styling place in every mode,
          selection or not (the canvas axes are page-scoped; only the
          state chip needs a selection), and the editing session, which
          poses its previews the same way. On the widget modes the row
          picks where writes land; on the code tree it picks what the
          canvas previews (islands address their own conditions). The
          catalog poses nothing, so it renders no row. */}
      {(shownPlace === 'styles' || frameMounted) && (
        <ChipRow
          source={source}
          guide={guide}
          vector={vector}
          forceState={forceState}
          hasSelection={Boolean(selected)}
          previewWindow={previewWindow}
        />
      )}
      {frameMounted ? (
        /* The editing session: the mode's frame over the places: the
           purple chrome, the session header, and the existing document
           machinery as the body. Push/Discard below are the exits. */
        <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
          <SessionFrame
            guide={guide}
            library={library}
            session={pending}
            editing={editing}
            entry={styleMap?.[selected]}
            axes={axes}
            vocabulary={draftVocabulary}
            usages={sessionUsages}
            consent={consent}
            onSession={setPending}
            onPark={parkSession}
            onSwitch={openDocument}
          >
            {posture === 'creating' ? (
              <DraftDocument
                source={source}
                guide={guide}
                styleMap={styleMap}
                variants={library}
                blockTypes={source.getBlockTypes()}
                sectionLayouts={source.getSectionLayouts()}
                session={pending}
                onSession={setPending}
              />
            ) : !editedVariant ? (
              <div className="daedalus-stylish-inspector__empty">
                {Drupal.t(
                  "This variant is not among the site's materials (deleted or unpublished); there is no document to edit.",
                )}
              </div>
            ) : !documentEditable ? (
              <div className="daedalus-stylish-inspector__empty">
                {locked(editedVariant)
                  ? Drupal.t(
                      '"@label" is built-in and read-only. Copy it as your variant (Variant settings…) to change it.',
                      { '@label': editedVariant.label },
                    )
                  : Drupal.t('You cannot update variants.')}
              </div>
            ) : mode === 'code' ? (
              <VariantDocumentView
                source={source}
                guide={guide}
                materials={materials}
                selected={selected}
                styleMap={styleMap}
                variants={library}
                document={codeDocument}
                posed={condition}
                onPose={driveCanvas}
              />
            ) : (
              sheetContext && (
                <Fragment>
                  {/* The chips speak the VARIANT'S conditions at this
                    target's slot — the document being edited. */}
                  <StyledAt
                    entry={sheetContext.entry}
                    condition={condition}
                    onDrive={driveCanvas}
                  />
                  <Sections
                    guide={guide}
                    materials={materials}
                    parsed={parsed}
                    entry={sheetContext.entry}
                    reference={widgetSheet.reference}
                    own={styleMap?.[target]}
                    shadowed={shadowed}
                    suppliers={suppliers}
                    skinEntry={skinEntry}
                    declaredEntry={declaredEntry}
                    declaredScope={declaredScope}
                    labelOf={labelOf}
                    valueOf={valueOf}
                    bundle={bundle}
                    condition={condition}
                    source={source}
                    highlightKey={selected}
                    highlightSub={selectedPart}
                    anchor={target}
                    media={{ ...media, canPick: false }}
                    swatches={swatches}
                    sheet={sheetContext}
                    pending={sheetContext.pending}
                    onPreview={handlePreview}
                    onRelease={handleRelease}
                    onCommit={handleCommit}
                    onPickCondition={driveCanvas}
                  />
                </Fragment>
              )
            )}
          </SessionFrame>
        </div>
      ) : (
        <Fragment>
          {shownPlace === 'materials' ? (
            /* The Materials place: the catalog, mode-independent, site-wide
           content, no selection needed. Three sections, one place: the
           variant catalog, the Palette (the value catalog: user swatches
           over the read-only skin half) and the Animations (the motion
           catalog: named keyframes). */
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
              <div
                className="daedalus-stylish-inspector__catalog daedalus-ui-segments"
                role="group"
                aria-label={Drupal.t('Materials section')}
              >
                {[
                  ['variants', Drupal.t('Variants')],
                  ['palette', Drupal.t('Palette')],
                  ['animations', Drupal.t('Animations')],
                ].map(([section, label]) => (
                  <button
                    key={section}
                    type="button"
                    className={`daedalus-stylish-inspector__ui-mode-button daedalus-ui-segment${catalogSection === section ? ' daedalus-ui-segment--active' : ''}`}
                    aria-pressed={catalogSection === section}
                    onClick={() => setCatalogSection(section)}
                  >
                    {label}
                  </button>
                ))}
              </div>
              {catalogSection === 'palette' ? (
                <PaletteView
                  guide={guide}
                  materials={materials}
                  config={swatches}
                  source={source}
                />
              ) : catalogSection === 'animations' ? (
                <AnimationsView
                  guide={guide}
                  materials={materials}
                  config={animations}
                  source={source}
                />
              ) : (
                <LibraryView
                  guide={guide}
                  materials={materials}
                  config={variants}
                  styleMap={styleMap}
                  selected={selected}
                  offer={applyOffer}
                  provenance={applyProvenance}
                  card={libraryCard}
                  onCard={setLibraryCard}
                  outline={outline}
                  onApply={applyFromCard}
                  onEditStyles={editStylesFromCard}
                  source={source}
                />
              )}
            </div>
          ) : mode === 'code' ? (
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
              {/* The styling place in code mode: the stylesheet read at a
              scope. The scope segments open the body (code mode's one
              extra control; the widget modes have no scope), then the
              islands tree projected by that scope, selection synced with
              the canvas both ways through the source (the selected element
              mounts the full ElementIslands body, so focused editing is
              selection, not a separate place). Element and Subtree stay
              clickable without a selection and show the shared empty
              state; Page always prints the whole filtered tree. The tree
              renders its own chrome, so the element breadcrumb yields. */}
              <div
                className="daedalus-stylish-inspector__scope daedalus-ui-segments"
                role="group"
                aria-label={Drupal.t('Code scope')}
              >
                {[
                  ['element', Drupal.t('Element')],
                  ['subtree', Drupal.t('Subtree')],
                  ['page', Drupal.t('Page')],
                ].map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    className={`daedalus-stylish-inspector__scope-button daedalus-ui-segment${scope === value ? ' daedalus-ui-segment--active' : ''}`}
                    aria-pressed={scope === value}
                    onClick={() => codeScopeStore.set(value)}
                  >
                    {label}
                  </button>
                ))}
              </div>
              {scope !== 'page' && !selected ? (
                <SelectAnElement />
              ) : (
                <PageCodeView
                  source={source}
                  guide={guide}
                  materials={materials}
                  selected={selected}
                  selectedPart={selectedPart}
                  styleMap={styleMap}
                  variants={library}
                  scope={scope}
                  posed={condition}
                  onPose={driveCanvas}
                />
              )}
            </div>
          ) : selected ? (
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
              {breadcrumb}
              {!stylable ? (
                <div className="daedalus-stylish-inspector__empty">
                  {Drupal.t('Styling is not enabled for this element.')}
                </div>
              ) : (
                <Fragment>
                  {/* No panel-level provenance banner: the layer dots on the
                  worn rows and widget values ARE the provenance display
                  — each dot's tooltip says where its value comes from,
                  in the layer's stable hue; a variant-supplied dot
                  navigates to that variant's document. */}
                  {/* Where styles already live, in both widget modes: chips
                  drive the canvas there (the chip row above is where the
                  canvas stands; posing IS switching the condition). */}
                  <StyledAt
                    entry={resolved.entry}
                    condition={condition}
                    onDrive={driveCanvas}
                  />
                  {/* Variant gestures target the WHOLE element (a
                  variant's sub-element rules travel inside it), so the
                  area yields while a part is drilled. */}
                  {!selectedPart && (
                    <VariantArea
                      source={source}
                      guide={guide}
                      materials={materials}
                      selected={selected}
                      styleMap={styleMap}
                      variants={library}
                      blockTypes={source.getBlockTypes()}
                      sectionLayouts={source.getSectionLayouts()}
                      config={variants}
                      claimedAxes={claimedAxes}
                      condition={condition}
                      outline={outline}
                      onFlash={setFlash}
                      onOpenDocument={openDocument}
                      onUpdateFromElement={updateFromElement}
                      onVariantSettings={openVariantSettings}
                    />
                  )}
                  {mode === 'basic' ? (
                    /* Basic: the target's declared controls, reading and
                   writing at the editing compound the canvas is posed at
                   — the one write rule, same as advanced. The target is
                   basicTarget: the drilled INSTANCE part when one is
                   selected (its namespace's controls, bound to the part
                   key — the selected paragraph styles, never its owner),
                   the selection otherwise (a drilled shell part keeps
                   the owner's panel, whose element: bindings address it
                   — the lift pattern). The layout widget's two sections
                   stay selection-scoped (they write the BLOCK's
                   structure), so they yield while an instance part is
                   targeted, the way the variant area yields for any
                   drill; their property rows read and write at the same
                   compound, their segment is a wear surface whose posed
                   sub-line opens the variant's document at that
                   condition: dedicated sections, not ControlWidgets,
                   because they write structure and multi-key batches.
                   The Column section leads; the Layout section is the
                   HOST of the roster's Layout group (BasicControls'
                   leading slot, mounted only where its gate passes), so
                   a container reads one Layout header at the top of the
                   panel: structure first, the bound controls after, the
                   roster sections beneath. */
                    <Fragment>
                      {!instanceDrill && (
                        <ColumnSection
                          source={source}
                          guide={guide}
                          selected={selected}
                          styleMap={styleMap}
                          variants={library}
                          condition={condition}
                        />
                      )}
                      <BasicControls
                        source={source}
                        guide={guide}
                        materials={materials}
                        selected={basicTarget}
                        styleMap={styleMap}
                        variants={library}
                        condition={condition}
                        swatches={swatches}
                        onPickCondition={driveCanvas}
                        onOpenDocument={openDocument}
                        leading={
                          !instanceDrill &&
                          layoutSectionTarget(source, selected)
                            ? {
                                layout: (
                                  <LayoutSection
                                    source={source}
                                    guide={guide}
                                    materials={materials}
                                    selected={selected}
                                    styleMap={styleMap}
                                    variants={library}
                                    condition={condition}
                                    onOpenDocument={openDocument}
                                    onPickCondition={driveCanvas}
                                  />
                                ),
                              }
                            : {}
                        }
                      />
                    </Fragment>
                  ) : (
                    /* Advanced: raw property-group sections at the editing
                   compound the canvas is posed at (the header's chip row
                   replaces the old free condition picker). */
                    <Sections
                      guide={guide}
                      materials={materials}
                      parsed={parsed}
                      entry={resolved.entry}
                      reference={resolved.reference}
                      own={styleMap?.[target]}
                      shadowed={shadowed}
                      suppliers={suppliers}
                      skinEntry={skinEntry}
                      declaredEntry={declaredEntry}
                      declaredScope={declaredScope}
                      labelOf={labelOf}
                      valueOf={valueOf}
                      bundle={bundle}
                      condition={condition}
                      source={source}
                      highlightKey={selected}
                      highlightSub={selectedPart}
                      anchor={target}
                      media={media}
                      swatches={swatches}
                      onOpenDocument={openDocument}
                      onPreview={handlePreview}
                      onRelease={handleRelease}
                      onCommit={handleCommit}
                      onPickCondition={driveCanvas}
                    />
                  )}
                </Fragment>
              )}
            </div>
          ) : (
            <SelectAnElement className="daedalus-ui-panel__body" />
          )}
        </Fragment>
      )}
      {/* The Push/Discard bar: the pending engine's one commit surface,
          the session's exits, pinned under the frame in every mode. A
          parked session renders the resume chip instead of a surviving
          bar. */}
      {frameMounted && (
        <PushBar
          source={source}
          guide={guide}
          styleMap={styleMap}
          variants={library}
          session={pending}
          usages={sessionUsages}
          onClose={() => {
            // The bar's exits dismount the whole mode: the session
            // closes AND the synced `editing` clears, so the frame and
            // the auto-outline never outlive what they framed.
            setPending(null);
            setParked(false);
            setPlace(shownPlace, null);
          }}
        />
      )}
      {parkedChip && (
        <ParkedChip
          chip={parkedChip}
          onResume={resumeSession}
          onDiscard={() => {
            setPending(null);
            setParked(false);
          }}
        />
      )}
    </div>
  );
};
