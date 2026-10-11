import { h, Fragment } from 'preact';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import {
  parseKey,
  declarationForKey,
  bundleForKey,
  buildSections,
  autoOpen,
  isInstancePartKey,
} from './style-guide.js';
import { usePalettePopover } from './palette-picker.js';
import { AiOfferToggle } from './ai-offer.js';
import { gradientStops } from './widget-gradients.js';
import { GradientGlyph, useGradientChip } from './gradient-chip.js';
import { PART_SELECTOR, selectorLabel } from './parts.js';
import { pseudoOf } from './computed-read.js';
import { ChipRow } from './chip-row.js';
import { StyledAt } from './styled-at.js';
import { SubjectLine } from './subject-line.js';
import { VariantDocumentView } from './code/element-view.js';
import { PageCodeView } from './code/page-view.js';
import { StyleActions, VariantArea } from './variants.js';
import { LibraryView } from './library.js';
import { PaletteView } from './palette.js';
import { AnimationsView } from './animations.js';
import { FontsView } from './fonts.js';
import { LawView } from './law.js';
import { lawScopeFor } from './law-model.js';
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
import { mountDoors } from './inspector-doors.js';
import {
  applicableVariants,
  axisLabel,
  axisVocabulary,
  captureScope,
  entryRefs,
  findVariant,
  locked,
  saveStyles,
  blueprintProvenance,
  subtreeSlotTable,
} from './variant-scope.js';
import {
  ELEMENT_LAYER,
  documentLayerId,
  isSkinDocument,
  lawDocumentFocus,
  layerColor,
  skinDocumentScope,
  variantLayerId,
} from './code/layers.js';
import {
  TIER_VARIANT,
  buildStack,
  deliveredEntry,
  editableLayer,
  offerTag,
  skinScopesAt,
  stackAt,
} from './style-stack.js';
import {
  captureRelativeTable,
  documentSlotEntry,
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
import { formatSlotKey, rootKindForScope } from './slot-key.js';
import { createMatcher } from './selector-matcher.js';
import { Portal } from './popover.js';
import { vectorCondition, conditionPose } from './editing-at.js';
import {
  conditionText,
  originOf,
  skinScopeLabel,
  tokenItems,
} from './provenance-model.js';
import { parseAnimationTrigger } from './motion-triggers-model.js';
import { fetchUsage } from './variants-api.js';
import {
  dismissLine,
  payloadRows,
  restoreLine,
  saveSession,
  sessionCount,
  skinSession,
  stageIslandLines,
  stageLine,
  stageValue,
  stagesLines,
  updateSession,
} from './pending.js';
import { PushBar, commitSession } from './push-bar.js';
import { outlineGesture } from './wear-bar-model.js';
import { findNode, pageNodes, subtreeNodes } from './code/page-model.js';
import { DimensionField } from './widgets/lib/dimension-field.js';
import { Mark } from './widgets/lib/mark.js';
import { TextField } from './widgets/lib/text-field.js';
import { useColorField } from './widgets/lib/color-field.js';
import {
  displayAt,
  magneticCapture,
  valueSpaceFor,
  wearing,
} from './value-space.js';
import { imageSlot } from './widget-values.js';
import { styleTarget, useRowHighlight } from './widgets/lib/use-highlight.js';
import { useRowMenu } from './widgets/lib/use-row-menu.js';
import { firstMediaUuid } from './media-token.js';
import { MediaButton } from './media-button.js';
import { MEDIA_PICK_PLACEHOLDER } from './media-library.js';
import {
  isCollapsed,
  toggle,
} from '../../../daedalus_ui/js/outline/collapse.js';
import { CollapseControls } from '../../../daedalus_ui/js/outline/collapse-controls.js';
import { PanelClose } from './panel-close.js';
import { useConcealed } from './reveal.js';
import {
  addressKey,
  addressSet,
  isAddress,
  sameAddress,
  withheldAt,
} from './write-address.js';

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
 *   spine): the media library chip beside the input, and on a free
 *   <image> slot (widget-values.js imageSlot) the `{}` grid over the
 *   row's gradient swatches and the gradient chip too; a keyword-typed
 *   media property (content) keeps its keywords as the stop picker and
 *   gets the library chip alone, the offer following the type.
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
 * The row reads the style stack (style-stack.js stackAt, the ONE read
 * every row makes): the shown value is always the WINNER's at the pose,
 * lit, whichever layer delivers it (the element's own block, one from
 * another condition, a worn variant's, the active skin's, a
 * declaration's); a null winner is honestly unset. Provenance
 * (provenance-model.js originOf) is the projection of that read: the
 * mark in the row's leading slot (widgets/lib/mark.js: hued in the
 * supplying layer's color, filled when the winner's block is the posed
 * compound, hollow when it cascades in from another condition, the
 * faint resting ring when nothing is authored; a square while the row
 * slides; the hover title tells the story), and the row's right-click
 * menu (the shared row menu,
 * widgets/lib/use-row-menu.js, the one every property row opens): the
 * origin in words, the doorways (drive the canvas to the delivering
 * condition, open the supplying variant there, edit at an ancestor's
 * rule, edit on the element again when a write address is seated and
 * the element's own value wins, unset to let the beaten layer return,
 * edit the skin), every
 * other condition carrying a value, then the token items the dimension
 * field's value space offers (follow a design-system stop or freeze
 * the literal). The
 * verb is Unset only while the editable layer delivers at the exact
 * compound; otherwise typing or picking writes an own override at the
 * pose over whatever delivers. No ghost exists: nothing rests beneath a
 * row, a row shows what the page shows.
 *
 * Every row is also a canvas-highlight consumer (useRowHighlight):
 * hovering it tints its property's invisible geometry on the canvas
 * (padding bands, margin's outside bands, gap strips), focus holds the
 * tint through an edit, and painterless properties no-op silently —
 * wired ONCE here, so every property group participates for free.
 *
 * Inside the editing session (`sheet`, the Sections-level document
 * context) the row writes the edited variant's OWN document (four controls,
 * one loud mode): the stack's editable layer is the document at this
 * row's slot (session overlaid), settling stages a pending line through
 * the session instead of committing an element op (a set, a null unset,
 * or — settling back on the variant's stored value — the staged line's
 * dismiss), and typing previews on this wearer only while the document
 * wins the property there (no canvas lies; unsets preview nothing).
 * Pending rows wear the focus chrome with a dismiss mark
 * (`pendingMark`), and a document value a higher layer beats (the
 * wearer's own entry, a higher reference) wears the winner's hue
 * (overridden) with the menu's doorway to where the winner is edited.
 *
 * The row's handlers speak the PAIR shape, `onPreview(property, value)`,
 * `onRelease(property, value)`, `onCommit(property, value)`: one row is
 * one property, and the source's `preview(key, condition, property,
 * value)` takes exactly that. This is the one pair-shaped preview seam
 * in the panel, by contract: the widget contract below it
 * (basic-controls.js, widgets/lib/use-gesture.js) is the OBJECT shape,
 * `onPreview(properties)`, because a widget's gesture routes several
 * properties as one group. The boundary is here; nothing under this
 * seam hands a pair to a widget, and this row is never ported to the
 * object shape.
 */
const PropertyWidget = ({
  row,
  stack,
  guide,
  materials,
  condition,
  source,
  highlightKey,
  highlightSelector,
  media,
  swatches = null,
  sheet = null,
  pendingMark = null,
  disabled = false,
  disabledTitle = null,
  onOpenDocument = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition,
}) => {
  const conditions = guide?.conditions ?? {};
  // The one read: the winner at the pose, the layers beneath, and what
  // the editable layer says.
  const read = stackAt(stack, row.property, condition, conditions);
  const editable = editableLayer(stack);
  // The lit value, the winner's; '' when nothing delivers.
  const lit = read.winner?.value ?? '';
  // The editable layer's value at the EXACT compound: the value Unset
  // removes and a same-valued settle releases against; null when the
  // editable layer sets nothing here (its other conditions do not count:
  // writes land at the posed compound).
  const ownAtPose =
    read.own !== null && read.own.condition === condition
      ? read.own.value
      : null;
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  // The last value this row staged as a wearer preview for the document:
  // reverts and unsets release it (nothing pending owns it anymore).
  const lastPreviewed = useRef(null);
  const highlight = useRowHighlight(source, highlightKey, highlightSelector, [
    row.property,
  ]);
  const editing = draft !== null && draft.trim() !== '';
  // Whether a layer above the editable one wins here (a session: the
  // wearer's own entry or a higher reference beats the document; a
  // write address: the element's own block beats the rule): the canvas
  // will not show what this row stages, so previews stay off.
  const overridden = read.winner !== null && read.winner.layer > editable;
  // The origin speaks display values, never var() spellings (the field's
  // own ref→display read, at the editing pose).
  const faceOf = (value) =>
    value == null
      ? value
      : (displayAt(guide, materials, value, condition) ?? String(value));
  const origin = originOf(read, {
    stack,
    property: row.property,
    pose: condition,
    conditions,
    guide,
    blockTypes: source.getBlockTypes?.() ?? {},
    sectionLayouts: source.getSectionLayouts?.() ?? {},
    faceOf,
    staged: editing || pendingMark !== null,
  });

  // A new authoritative value (a settled commit, an AI edit, a condition
  // switch) supersedes the draft; a failed commit leaves the lit value
  // untouched, so the typed value survives for correction.
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [lit, condition]);

  const shown = draft ?? lit;
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
      if (overridden) {
        return;
      }
      lastPreviewed.current = styleValue;
    }
    onPreview(row.property, styleValue);
  };

  const settle = (value) => {
    if (draft === null && value === lit) {
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
    if (next === asStyleValue(ownAtPose ?? '')) {
      // Nothing to persist (the editable layer already says this here,
      // or says nothing and nothing was typed): release the staged
      // preview and fall back to the lit value.
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

  // The media row's gradient half rides the type, not the flag
  // (widget-values.js imageSlot): a free <image> slot (background-image)
  // offers the `{}` grid and the gradient opener beside the picker; a
  // keyword-typed media property (content, `""` / `none` / one token)
  // offers its keywords through the text field's stop picker and the
  // media picker alone, since the server refuses a gradient there.
  const gradients = control.kind === 'media' && imageSlot(row.definition);
  // The media row's `{}` grid: the row's gradient swatches as faces (the
  // faces a plain stop list cannot paint), the same constraint-honest derivation
  // the basic-mode wells read (gradientStops over background-image's own
  // constraints), the popover floating under the chip over the rows
  // below. An insert-style consumer: no lit stop, a pick commits the
  // var() reference exactly like typing it would.
  const mediaPaletteStops = gradients
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
  // gate (a lit value that is exactly one gradient-swatch var(), whichever
  // layer delivers it, opens the update session over the swatch's OWN
  // value instead of degrading: the swatch is a site-wide material), the
  // builder over anything else, and the mint seam. Free <image> rows
  // only: a '' keeps the gate off elsewhere, and the opener/popover
  // vnodes render nowhere.
  const gradientChip = useGradientChip({
    guide,
    materials,
    committed: gradients ? lit : '',
    value: shown,
    constraints: row.constraints ?? null,
    source,
    canMint: Boolean(swatches?.canCreate),
    onPreview: preview,
    onCommit: settle,
  });
  const gradientSwatch = gradients ? gradientChip.swatch : null;
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
    invalid,
    canMint: Boolean(swatches?.canCreate),
    source,
    disabled,
    disabledTitle,
    onPreview: preview,
    onSettle: settle,
  });
  // Custom gradient authoring is admissible exactly while the space is
  // open (the builder writes literals a closed space rejects); the
  // edit-in-place session over a committed swatch reference stays
  // offered regardless, since it rewrites the swatch, not this value.
  // A withheld row offers none of its doors.
  const chipOffered =
    gradients && !disabled && (!closed || gradientSwatch !== null);
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
    disabled ? 'daedalus-stylish-inspector__property--withheld' : '',
  ]
    .filter(Boolean)
    .join(' ');
  // The row menu (the one provenance surface, both halves; the shared
  // row menu every property row opens, widgets/lib/use-row-menu.js):
  // the origin's doorways and the "Also set at" entries dispatch there,
  // and the token items are the dimension field's over the row's own
  // value space (the field settles literals as literals, so its "Custom
  // value" escape is honest there; the other shapes' settle recaptures
  // every stop match, so they offer no token menu). Built on open,
  // never per render; with nothing to show the native menu rides
  // through. Unset is refused with the row: a withheld property removes
  // nothing at the address either.
  const tokenSpace = () =>
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
  const onRowContextMenu = useRowMenu({
    origin,
    source,
    onOpenDocument,
    onPickCondition,
    onUnset: () => {
      if (!disabled) {
        settle('');
      }
    },
    // The winner doorway is the session's (originOf emits it only while
    // the document is the editable layer), so its navigation is the
    // sheet's; the element doorway a write address emits rides the
    // hook's own dispatch through the source.
    onWinner: sheet ? (winner) => sheet.onWinner(winner) : null,
    tokens: () => {
      const space = tokenSpace();
      return space
        ? tokenItems(guide, materials, condition, space, shown, {
            onPreview: preview,
            onSettle: settle,
          })
        : [];
    },
    face: () => {
      const space = tokenSpace();
      return space
        ? wearing(guide, materials, space, shown, condition).face || shown
        : shown;
    },
  });
  // A dimension row's label lives INSIDE the field (the scrubbable label
  // is the label), and the field carries several interactive controls a
  // <label> could not honestly wrap — a div holds the line instead; the
  // color field's cluster (face trigger, hidden picker, text) is the
  // same case.
  const Line =
    control.kind === 'dimension' || control.kind === 'color' ? 'div' : 'label';
  // The dismiss control on a pending row, at the value cell's end.
  const pendingButton = pendingMark ? (
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
  ) : null;

  return (
    <div
      className={classes}
      style={origin.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
      onContextMenu={onRowContextMenu}
      {...highlight}
    >
      <Line className="daedalus-stylish-inspector__property-line">
        {/* The label cell: the row's mark (lib/mark.js: hue = the
            supplier, fill = at the pose, shape = a live slide) and the
            label. A dimension row hands the mark to the field, which
            lays the line's two cells itself and leads its scrub label
            with it; any other row rests it here, so the label cell
            never moves with the provenance. */}
        {control.kind !== 'dimension' && (
          <span className="daedalus-stylish-inspector__widget-label">
            <Mark origin={origin} />
            <span className="daedalus-stylish-inspector__property-label">{row.label}</span>
          </span>
        )}
        {control.kind === 'dimension' && (
          /* The numeric field (dimension-field.js): controlled on
             `shown` so its previews echo back through the draft; its
             onSettle speaks the same settle() as text (release when
             unchanged, one commit otherwise) and emits the commit
             spelling (a reference on capture, the literal otherwise).
             The guide + condition let the field derive the property's
             value space and resolve reference displays at the editing
             pose. The mark it leads with is this row's, over the same
             origin. */
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
            mark={<Mark origin={origin} />}
            disabled={disabled}
            disabledTitle={disabledTitle}
            // The pending dismiss rides the field's value cell, at its
            // end, as it does on the other lines below.
            trailing={pendingButton}
            onPreview={preview}
            onSettle={settle}
          />
        )}
        {control.kind !== 'dimension' && (
          <span className="daedalus-stylish-inspector__widget-value">
            <span className="daedalus-stylish-inspector__input-group">
              {/* The media row's palette chip: the shared grid popover
                  behind it (wired above), closed spaces included (the grid
                  shows exactly the curated gradient entries). */}
              {gradients && mediaPaletteStops.length > 0 && (
                <button
                  {...mediaPalette.triggerProps}
                  type="button"
                  className="daedalus-stylish-inspector__palette-picker"
                  title={disabled ? (disabledTitle ?? undefined) : Drupal.t('Insert a palette value')}
                  disabled={disabled}
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
              {control.kind === 'media' && !closed && !disabled && media?.canPick && (
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
                  face={faceText}
                  title={
                    disabled
                      ? (disabledTitle ?? undefined)
                      : gradientSwatch
                        ? lit.trim()
                        : undefined
                  }
                  disabled={disabled}
                  onPreview={preview}
                  onSettle={settle}
                />
              )}
            </span>
            {pendingButton}
          </span>
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
 * A projection of the style guide YAML against the drill target's style
 * STACK (style-stack.js): sections per declared property group plus a
 * row for every property any layer delivers (the stack's delivered
 * entry: a style that applies to the element shows, whichever tier
 * sets it), widgets writing through the state source. Each row reads
 * the stack itself (PropertyWidget: the winner at the pose, lit, its
 * origin as the mark and the menu). A worn reference keeps an
 * on-variant element whose values the library cannot resolve from
 * claiming "nothing stylable".
 *
 * Inside the editing session (`sheet` + `pending`, the Inspector's
 * document context and the slot's pending table) the stack's editable
 * layer is the DOCUMENT at this slot, session overlaid, and the rows
 * write it: settles stage pending lines, pending rows wear the focus
 * chrome, and a value a higher layer beats wears the winner's hue with
 * a doorway to where the winner is edited.
 *
 * Each section is a native <details> whose `open` prop is CONTROLLED:
 * Preact pushes it to the DOM only when it changes between renders. The
 * prop is the collapse model's answer (daedalus_ui/js/outline/collapse.js,
 * `explicit ?? default`) over this component's one posture, `folds`:
 * the rendered sections project into the model's flat outline per
 * render, every section foldable, each one's DEFAULT posture its arrival
 * state (style-guide.js autoOpen: sections whose rows show a value open,
 * a pending line on a visible row holds a section open, empty ones sit
 * collapsed), and the user's own summary clicks write explicit postures
 * (toggle) that win, so a hand-opened group survives its last value's
 * deletion and a hand-closed one stays closed when a value lands. The
 * collapse-all and expand-all pair at the list head (daedalus_ui's
 * shared control) writes every section's posture at once; it registers
 * no hotkeys. The head itself ("Sections" and the pair) is this
 * component's but does not scroll with the sections: it renders through
 * a Portal (popover.js) into `head`, the inspector's pinned head slot at
 * the end of its `__top` box, the one seat the pair takes in every mode
 * that folds (code mode's showing row fills the same slot), so a reader
 * scrolled deep into the sections can still fold them; `head` is null
 * on the inspector's first render, before its mount effect lifts the
 * node into state, and the head waits for it. The posture is folded
 * INTO the prop so it mirrors the DOM
 * at all times. The reset is a KEY, never a function: the parent keys
 * this component on the anchor (the styling target, `key` or
 * `key::part`), so a selection change remounts it with no explicit
 * postures and the arrival state returns. The toggle rides the summary
 * CLICK, not the toggle event (which fires for programmatic opens too):
 * the click's default is prevented, so the model's answer for the row
 * is the DOM's current state and its flip is pushed to the DOM by the
 * prop change alone (a native toggle racing the push would undo it; see
 * the handler).
 *
 * `sections` is the roster (style-guide.js buildSections: the declared
 * groups, plus a row for every property the stack delivers at any stored
 * condition, so a variant's or the skin's value on an ungranted property
 * still rows), built ONCE by the inspector and handed down, because the
 * inspector derives `withheld` from the same roster; two builds of one
 * roster would be a duplicate derivation that drifts.
 *
 * `withheld` (property → reason, write-address.js withheldAt over the
 * panel's write address; null for the own key) marks the rows the
 * address cannot take: each renders DISABLED with the reason as its
 * title, the row itself unchanged (the value still reads from this
 * element's stack), the same posture the basic widgets take.
 */
const Sections = ({
  guide,
  materials,
  sections,
  stack,
  condition,
  source,
  highlightKey,
  highlightSelector,
  media,
  swatches = null,
  sheet = null,
  pending = null,
  withheld = null,
  head = null,
  onOpenDocument = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition,
}) => {
  const worn = stack.layers.some((layer) => layer.tier === TIER_VARIANT);
  // The outline's posture (daedalus_ui/js/outline/collapse.js): the
  // explicit postures by section id, null for "every section at its
  // arrival state". Per mount: the parent keys this component on the
  // anchor, so a selection change starts it over.
  const [folds, setFolds] = useState(null);

  // A row's pending mark on the document surface: the staged line — set
  // or unset — at the editing compound (the slot's pending table,
  // condition-keyed).
  const pendingFor = (row) =>
    sheet ? (pending?.[condition]?.[row.property] ?? null) : null;

  // The Motion section's Replay: the same affordance the basic Motion
  // trigger control renders, in the section header, only while the
  // selection's animation-trigger at the pose binds a trigger (the value
  // the row shows: the stack's winner at the pose, read as the runtime's
  // grammar reads it), so a section with nothing to re-arm shows no
  // pill. Re-arming happens on the canvas through the source (the
  // satellite relays an intent).
  const replayable = (section, rows) => {
    if (section.id !== 'motion') {
      return false;
    }
    if (!rows.some((candidate) => candidate.property === 'animation-trigger')) {
      return false;
    }
    const value =
      stackAt(stack, 'animation-trigger', condition, guide?.conditions ?? {})
        .winner?.value ?? null;
    return value !== null && parseAnimationTrigger(value) !== null;
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

  // The rendered sections (a section whose every row hides renders
  // nothing) and their projection into the model's shape, per render: a
  // flat outline, every section foldable, its default posture the
  // arrival state.
  const shown = sections
    .map((section) => ({
      section,
      rows: section.rows.filter((row) => !hiddenFor(row)),
    }))
    .filter(({ rows }) => rows.length > 0);
  const outline = shown.map(({ section, rows }) => ({
    id: section.id,
    foldable: true,
    folded: !autoOpen(section, rows, { pendingFor }),
  }));

  return (
    <Fragment>
      {/* The list head: the word "Sections" and the outline's
          collapse-all and expand-all pair (daedalus_ui's shared control
          over this component's posture: each button disabled when its
          gesture would do nothing; no outline id, so the tooltips carry
          no key), rendered into the inspector's pinned head slot, never
          into this scrolling body (see the docblock). Only over a
          rendered section: the empty roster shows its own line below. */}
      {head && outline.length > 0 && (
        <Portal root={head}>
          <div className="daedalus-stylish-inspector__sections-head">
            <span className="daedalus-stylish-inspector__sections-word">
              {Drupal.t('Sections')}
            </span>
            <CollapseControls
              folds={folds}
              outline={outline}
              apply={(next) => setFolds(next)}
            />
          </div>
        </Portal>
      )}
      {shown.map(({ section, rows }, index) => {
        const node = outline[index];
        return (
          <details
            key={section.id}
            className="daedalus-ui-details daedalus-stylish-inspector__section"
            open={!isCollapsed(folds, node)}
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
                setFolds((current) => toggle(current, node));
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
                    // toggle handler above nor the native toggle may run.
                    event.preventDefault();
                    event.stopPropagation();
                    source.replayMotion(highlightKey, highlightSelector);
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
                  stack={stack}
                  guide={guide}
                  materials={materials}
                  condition={condition}
                  source={source}
                  highlightKey={highlightKey}
                  highlightSelector={highlightSelector}
                  media={media}
                  swatches={swatches}
                  sheet={sheet}
                  pendingMark={pendingFor(row)}
                  disabled={typeof withheld?.[row.property] === 'string'}
                  disabledTitle={withheld?.[row.property] ?? null}
                  onOpenDocument={onOpenDocument}
                  onPreview={onPreview}
                  onRelease={onRelease}
                  onCommit={onCommit}
                  onPickCondition={onPickCondition}
                />
              ))}
            </div>
          </details>
        );
      })}
      {!sections.length && !worn && (
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
 * "Belongs to <blueprint>" line: a draft anchored inside a blueprint
 * placement is born as that blueprint's own switchable look, said out
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
  // A skin session's document is the skin's at a scope: no switcher (the
  // roster is the selection's worn variants), no usage count (the scope
  // IS the blast radius), the skin hue on the dot.
  const skin = header.posture === 'skin';
  // The document switcher: the selection's other worn documents, so a
  // designer working two axes hops without exit-and-rejump. Switching
  // resolves the open session through the ordinary prompt first (the
  // displacement queue's business); the retired slim layer-picker rows'
  // duty lives in this one control.
  const roster =
    creating || skin ? [] : switcherRoster(entry, library, editing, axes);
  const current = roster.find((row) => row.current) ?? null;
  const blast = creating
    ? Drupal.t('Captured from this element · saving wears it here')
    : skin
      ? Drupal.t('Skin default · restyles every !scope site-wide', {
          '!scope': header.label,
        })
      : usages === null
        ? Drupal.t('!axis variant · changes affect every use', {
            '!axis': header.axisLabel,
          })
        : Drupal.formatPlural(
            usages,
            '!axis variant · worn in 1 place site-wide · changes affect every use',
            '!axis variant · worn in @count places site-wide · changes affect every use',
            { '!axis': header.axisLabel },
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
              : skin
                ? Drupal.t('Editing the skin')
                : Drupal.t('Editing shared style')}
          </span>
          {!creating && (
            <span className="daedalus-stylish-session__document">
              <span
                className="daedalus-stylish-session__dot daedalus-ui-dot"
                style={{
                  background: layerColor(
                    documentLayerId(editing ?? session.reference),
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
                "The captured element sits inside this blueprint's placement, so the new variant becomes one of its switchable looks, offered on its placements only.",
              )}
            >
              {Drupal.t('Belongs to !blueprint', { '!blueprint': consent.label })}
            </span>
            <button
              type="button"
              className="daedalus-stylish-session__unlink daedalus-ui-pill-button"
              title={Drupal.t(
                'Save as a general-purpose variant instead; assign it to a blueprint later from Materials.',
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
  // The lean node tree: null before the satellite's first broadcast (no
  // matcher then), the walks reading it as empty.
  const nodeTree = source.getTree?.() ?? null;
  const tree = nodeTree ?? [];
  const capture = saveStyles(
    styleMap ?? {},
    session.anchor,
    tree,
    library,
    session.axis,
  );
  const rows = payloadRows(capture, parseKey(session.anchor)?.kind ?? null);
  // The capture's slots back to the elements the capture walked
  // (subtreeSlotTable is saveStyles' own walk), so a group's header
  // reads its element's minted type off the tree through the matcher.
  const matcher = Array.isArray(nodeTree) ? createMatcher(nodeTree) : null;
  const keyOfSlot = new Map(
    Object.entries(subtreeSlotTable(session.anchor, tree)).map(
      ([key, segments]) => [formatSlotKey(segments), key],
    ),
  );
  const slotType = (relative) => {
    const pos = relative.indexOf('::');
    const slot = pos === -1 ? relative : relative.slice(0, pos);
    const key = slot === '' ? session.anchor : (keyOfSlot.get(slot) ?? null);
    return key === null ? null : (matcher?.facts(key)?.type ?? null);
  };
  const dismissed = new Set(session.dismissed);
  const toggle = (id) =>
    onSession(
      dismissed.has(id) ? restoreLine(session, id) : dismissLine(session, id),
    );
  // Part labels resolve through the captured element carrying the row:
  // captureRelativeTable inverted (relative → full map key), the capture
  // walk's own coverage.
  const table = captureRelativeTable(styleMap ?? {}, session.anchor, tree);
  const carrierByRelative = new Map(
    [...table].map(([key, relative]) => [relative, key]),
  );
  const partText = (group) => {
    const carrier = carrierByRelative.get(group.relative);
    return carrier
      ? selectorLabel(
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
      : group.kind === 'container' || group.kind === 'block'
        ? (slotType(group.relative) ?? Drupal.t('Element'))
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
                  {Drupal.t('wears !label', {
                    '!label':
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
 * @param {object} [props.tokens]
 *   The token gesture gates (drupalSettings.daedalusStylish.tokens):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}. Honest chrome for
 *   the Materials › Palette skin section's mint and cards; the op spine
 *   and the routes enforce.
 * @param {object} [props.animations]
 *   The animation gesture gates (drupalSettings.daedalusStylish.animations):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}. Honest chrome for
 *   the Materials › Animations cards; the provider and routes enforce.
 * @param {object} [props.fonts]
 *   The font gesture gates (drupalSettings.daedalusStylish.fonts):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}. Honest chrome for
 *   the Materials › Fonts cards and mint card; the provider and routes
 *   enforce.
 * @param {object} [props.skin]
 *   The skin gesture gates (drupalSettings.daedalusStylish.skin):
 *   {canEditDefaults, canAdminister}. Honest chrome for the skin
 *   session's pending lane (without the grant the frame mounts
 *   read-only, as a variant's does without "update variants") and for
 *   the Materials › Palette skin card's lifecycle gestures (the
 *   administer grade's alone); the op spine and the routes enforce.
 * @param {?object} [props.previewWindow]
 *   The preview-window opener {url, scope} (the entry point composes it
 *   from daedalusUi.responsivePreview.url and the session identity,
 *   daedalusUi.editSession.scope) — host configuration like the guide,
 *   so the satellite window (whose page carries no edit-mode settings)
 *   honestly passes none and the chip row's width menu drops its
 *   preview-window tail there.
 */
export const Inspector = ({
  source,
  guide: bootGuide = {},
  headerActions = null,
  rail = false,
  variants = {},
  swatches = {},
  tokens = {},
  animations = {},
  fonts = {},
  skin = {},
  previewWindow = null,
}) => {
  // The guide payload is the page's boot truth; its one live part is the
  // active skin's defaults, which a skin session's push rewrites
  // (leader-source.js refreshDefaults, the satellite off the
  // broadcast): the source's delivered map overlays the boot payload's
  // skinDefaults, so every stack below reads the pushed document as the
  // skin's. Null until a push has landed, when the boot payload stands.
  const [skinDefaults, setSkinDefaults] = useState(
    source.getSkinDefaults?.() ?? null,
  );
  useEffect(() => {
    const unsubscribe =
      source.onSkinDefaultsChange?.(setSkinDefaults) ?? (() => {});
    setSkinDefaults(source.getSkinDefaults?.() ?? null);
    return unsubscribe;
  }, [source]);
  const guide = useMemo(
    () =>
      skinDefaults === null ? bootGuide : { ...bootGuide, skinDefaults },
    [bootGuide, skinDefaults],
  );
  const [selected, setSelected] = useState(source.getSelected());
  const [styleMap, setStyleMap] = useState(source.getStyleMap());
  // The materials roster (tokens, swatches, variants, blueprints as the
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
  // The two styling facts, spine state read through the source. The
  // SUBJECT facet `target`: {key, selector} with key always the selected
  // key, or null for the bare element (the tree's part rows, canvas part
  // clicks and the code view's selector row headers seat one; any
  // selection gesture clears it). The WRITE ADDRESS `address`: {key,
  // selector} with key the selection or an ancestor, or null for the
  // subject's own key (the address switcher, provenance's "Edit at" and
  // the code view's rule headers seat one; any selection gesture and any
  // facet write clears it). The rows are always the subject's; the
  // address says only where a write lands. Neither is derived from the
  // other: a minted list item under "every list item in Welcome" keeps
  // its own rows, which no inverse of the rule could recover.
  const [target, setTarget] = useState(source.getTarget());
  const [spineAddress, setSpineAddress] = useState(source.getAddress());
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
  // lands there: the mode row lights exactly one of its four buttons.
  const [mode, setMode] = useState(uiModeStore.get());
  useEffect(() => uiModeStore.subscribe(setMode), []);
  // The code view's reading scope (element / subtree / page): the mode's
  // sibling preference (code-scope.js), read here and written through the
  // store so both windows agree; the code view's showing row is its one
  // control (page-view.js), so it renders only in code mode.
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
  // The head slot's node (the last pinned line of `__top`, rendered
  // below): a plain ref, lifted into state by a mount effect so the mode
  // view that fills it (the code view's showing row, the Sections' head)
  // re-renders its Portal once the node exists, instead of reading a ref
  // that was null on the render it was passed down. Never a callback
  // ref: a state write during commit queues a re-render in a microtask,
  // and preact's hooks flush a component's still-pending mount effects
  // before it re-renders, which pulled the source subscriptions below
  // ahead of daedalus_ui's canvas vector facade (attached by a later
  // behavior in the same attachBehaviors pass), so the chips never heard
  // a vector. A mount effect lands after paint, with the other mount
  // effects, and the write it makes re-renders after they all ran.
  const headRef = useRef(null);
  const [head, setHead] = useState(null);
  useEffect(() => {
    setHead(headRef.current);
  }, []);
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
  const committableSession = (session, tree) =>
    stagesLines(session)
      ? sessionCount(session) > 0
      : session.label.trim() !== '' &&
        sessionCount(
          session,
          saveStyles(
            styleMap ?? {},
            session.anchor,
            tree,
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
  const replaceSession = (next, tree) => {
    setParked(false);
    if (pending && committableSession(pending, tree)) {
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
    replaceSession(next, source.getTree?.() ?? []);
  };
  // The session's anchor is the wear key (wearKey below): the selected
  // element, or the rule the target names, which the panel must read as
  // its own key while the session is open (its rows read the anchor's
  // stack with the document as the editable layer, its previews land at
  // the anchor). Seating an anchor makes that so before a session
  // enters: a wearer elsewhere on the page (the library card's Edit
  // styles) is selected, a rule wearer is seated as the SUBJECT facet ON
  // its root (`select(root)` + `target(root, selector)`: the selection
  // write and the facet write each drop a standing address), and a rule
  // reached as a write ADDRESS is re-seated the same way: a session and
  // an address never coexist (style-stack.js), so the rule becomes the
  // selection's own facet and the panel reads the rule's own stack, the
  // rule inspected as a rule. The wear key string is the same before and
  // after, so the session anchors at it unchanged.
  const seatAnchor = (key) => {
    if (key === wearKey && address === null) {
      return;
    }
    const { key: root, selector } = styleTarget(key);
    if (root !== selected) {
      source.select(root);
    }
    if (selector !== null) {
      source.target(root, selector);
    }
  };
  // The update doorways (wearing-row body, overridden-by badge, the
  // library card's Edit styles, the header switcher, the rows' and the
  // code lines' skin doorway): one openDocument vocabulary, a document
  // being a variant's (its reference) or the skin's at a scope (the
  // skin document's reference, code/layers.js skinDocument, minting a
  // skin session). Entering the edited document's own open session
  // (parked included) re-enters it instead of minting a fresh one. A
  // doorway may
  // name the condition to open AT (a posed wear surface: the wear bar's
  // sub-line, the layout segment's "stacks at Narrow"): the session has
  // no condition of its own, its rows read and write at the canvas's
  // compound, so opening at a condition drives the canvas there first,
  // and only when it is not already there (re-posing an equal compound
  // would move a width the designer set inside the band). The anchor is
  // the wear key unless the doorway names another wearer (the library
  // card's wearer elsewhere on the page; a provenance row's carrier of
  // a variant an ancestor's rule wears).
  const openDocument = (reference, at = null, anchor = null) => {
    // A law document (the declared doorway's target, code/layers.js
    // lawDocument) is never a session: it opens the Law tab at the
    // declaration and leaves the canvas, the anchor and any open session
    // untouched.
    const law = lawDocumentFocus(reference);
    if (law !== null) {
      openLaw(law);
      return;
    }
    if (at !== null && at !== condition) {
      driveCanvas(at);
    }
    const wearer = anchor ?? wearKey;
    seatAnchor(wearer);
    if (pending !== null && sessionFocuses(pending, reference)) {
      editIntent.current = reference;
      setParked(false);
      setPlace(shownPlace, reference);
      return;
    }
    const scope = skinDocumentScope(reference);
    enterSession(
      reference,
      scope === null
        ? updateSession(reference, wearer)
        : skinSession(scope, wearer),
    );
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
    const tree = source.getTree?.() ?? [];
    const vocabulary = axisVocabulary(
      guide,
      selected,
      source.getBlockTypes(),
      source.getSectionLayouts(),
      tree,
    );
    replaceSession(saveSession(selected, vocabulary[0] ?? 'style'), tree);
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
  // "Update from this element" (the wearing rows' menu; "from this rule"
  // at a rule): the retired update dropdown re-expressed in the pending
  // lane — the wearer's own diff against the variant (the wear key's
  // entry and its continuations) pre-stages as dismissible pending lines
  // and the editing session opens on the document, the whole staged
  // extent in place. Push commits, per line dismissal prunes. Staging
  // into the document's own open session MERGES (stageLine upserts); any
  // other open session resolves through the leave-prompt first.
  const updateFromElement = (reference) => {
    const tree = source.getTree?.() ?? [];
    const lines = updateFromElementLines(
      styleMap ?? {},
      wearKey,
      reference,
      library,
      tree,
    );
    seatAnchor(wearKey);
    const merge = pending !== null && sessionFocuses(pending, reference);
    const base = merge
      ? reanchorSession(pending, wearKey)
      : updateSession(reference, wearKey);
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
  // library face); the place itself is the mode row's business.
  const [libraryCard, setLibraryCard] = useState(null);
  // Which catalog section the Materials place shows: 'variants',
  // 'palette', 'animations', 'fonts' or 'law'. The Palette, Animations
  // and Fonts faces live WITHIN Materials rather than as further places:
  // the mode row answers "what am I looking at" with the styling place's
  // modes and the Materials toggle, and Materials IS the mode-independent
  // catalog of site-wide reusable styling content — swatches, animations
  // and fonts are the same species as variants at value scope, so they
  // section the catalog instead of growing the place vocabulary. The Law
  // face is the read-only view of the design system's law (the style
  // guide, authored in files: law.js over the payload's law projection),
  // the catalog's last section because the law is site-wide content
  // too. Panel state like the open card (each window browses its own
  // catalog), so satellite parity needs no surface-sync change.
  const [catalogSection, setCatalogSection] = useState('variants');
  // The Law tab's focus: the declaring scope it shows and the property
  // whose rows it reveals, set by a declared doorway (a law document
  // through openDocument) or by the tab's own type picker; null follows
  // the selection to the selected element's type (law-model.js
  // lawScopeFor). A selection move drops it, so the tab follows again.
  const [lawFocus, setLawFocus] = useState(null);
  useEffect(() => {
    setLawFocus(null);
  }, [selected]);
  const openLaw = (focus) => {
    setLawFocus(focus);
    setCatalogSection('law');
    setPlace('materials', editing);
  };
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
  // styling target when it wears it, else an element root wearer, else
  // a rule wearer from the map — the document renders in wearer
  // context). The doorway seats the wearer first (seatAnchor: an
  // element is selected, a rule is targeted on its root), then enters:
  // the move rule sees a wear key that wears the edited document, so
  // the jump sticks.
  const editStylesFromCard = (variant) => {
    const wearer = pageWearer(styleMap ?? {}, variant, wearKey);
    if (!wearer) {
      return;
    }
    openDocument(wearer.reference, null, wearer.key);
  };
  // "Apply to selection" (the library card): the browse-first doorway —
  // the same instant apply_variant the styling place's variant picker speaks,
  // aimed from the catalog at the styling target (wearKey below: the
  // selected element, or the rule the target chip names, rendered on the
  // Materials place too). The card computes its own doorway state
  // (library-model.js applyDoorway) from the offer below; this is just
  // the op, promise-returned so the card's busy guard rides it.
  const applyFromCard = (variant) =>
    source.applyVariant(
      wearKey,
      variant.id,
      Drupal.t('Applied variant !label', {
        '!label': variant.label ?? variant.id,
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
  // The page elements panel's variant doorways (row-facts.js) lead here:
  // "Edit {variant}" is openDocument anchored on the row's element, with
  // no condition, exactly as a wearing row opens it; "Outline everything
  // wearing it" is the Outline Variant toggle above. Installed through
  // the doors seam (inspector-doors.js) for this mount's lifetime, in
  // whichever window this inspector stands; a ref keeps the two
  // functions current across renders, the wear bar's opener pattern.
  const panelDoors = useRef(null);
  panelDoors.current = {
    openDocument: (reference, anchor) => openDocument(reference, null, anchor),
    toggleOutline: (reference) => outline.toggle(reference),
  };
  useEffect(
    () =>
      mountDoors({
        openDocument: (reference, anchor) =>
          panelDoors.current.openDocument(reference, anchor),
        toggleOutline: (reference) =>
          panelDoors.current.toggleOutline(reference),
      }),
    [],
  );
  // The canvas is part of the mode chrome: session entry auto-enables
  // Outline Variant over the session's document (the blast radius is
  // visible where the user is already looking); exit (park, push,
  // discard, any editing clear) restores whatever was outlined before.
  // Identity hue on the canvas, per the settled decision: the outline
  // speaks the variant's own color (outlineGesture unchanged); the mode
  // purple frames the panel only. A draft session outlines nothing (its
  // document has no wearers until Push mints it), neither does a skin
  // session (the outline paints a variant's coverage off the map; a
  // scope's elements are no map fact), and a mid-session manual toggle
  // is the user's to keep: this effect moves only on `editing` edges.
  const priorOutlined = useRef(undefined);
  const outlinedDocument =
    editing !== null && !isSkinDocument(editing) ? editing : null;
  useEffect(() => {
    if (outlinedDocument !== null) {
      if (priorOutlined.current === undefined) {
        priorOutlined.current = outlined;
      }
      setOutlined(outlinedDocument);
      return;
    }
    if (priorOutlined.current !== undefined) {
      setOutlined(priorOutlined.current);
      priorOutlined.current = undefined;
    }
  }, [outlinedDocument]);
  // Gesture outcomes (statuses and failures alike) ride source.notify to
  // the edit-mode messenger, the page builder's one notification spine
  // (toast + sidebar history; the satellite broadcasts to the leader).
  // The old inline message line is gone; only in-context correction
  // surfaces (mint error, push-bar under-bar line) stay inline.
  // The header's action cluster is secondary chrome: hidden at clean
  // density until the pointer nears.
  const actionsRef = useConcealed();
  // Subscribe, THEN re-read: a broadcast can land in the gap between first
  // render and this effect (the satellite's hello/snapshot handshake
  // reliably does), and the source won't re-announce state it already
  // holds — the read closes the gap.
  useEffect(() => {
    const unsubscribers = [
      source.onSelectionChange((current) => setSelected(current)),
      source.onStyleMapChange((map) => setStyleMap(map)),
      source.onTargetChange(({ target: next, address: nextAddress }) => {
        setTarget(next);
        setSpineAddress(nextAddress ?? null);
      }),
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
    setTarget(source.getTarget());
    setSpineAddress(source.getAddress());
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

  // The subject facet every read here is keyed by: the spine's target
  // selector (a part, a tag tier, a chain on the selected element), null
  // for the root. The spine's one invariant makes the read direct: a
  // target's key is always the selected key, so the facet is the
  // target, never a derivation from where a write lands.
  const tree = source.getTree?.() ?? null;
  const targetSelector = selected ? (target?.selector ?? null) : null;
  // Everything below the subject line targets the drill: the selector's
  // full style key when one is targeted, the selection itself otherwise.
  const targetKey =
    selected && targetSelector ? `${selected}::${targetSelector}` : selected;
  const parsed = parseKey(targetKey);
  // Whether the selection may be styled at all: the spine's verdict on
  // the key (the source's capable(), daedalus_ui's one client gate), the
  // one answer for a template element on a plain host, a frozen
  // translation, a role without the style grant, or an element whose
  // style map this page cannot reach. No write control mounts without it,
  // in any mode; the document notice in the page elements panel explains
  // why, so this panel offers no portal of its own.
  const stylable =
    Boolean(selected) && Boolean(parsed) && source.capable(selected, 'style');
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
  // it. Any other facet (a chain such as `> pb-text` seated from a
  // code-mode row header, or a tag) is a RULE of the selection's own:
  // the panel targets the rule key and models the declaration the
  // roster walk lands on (controls.js controlModels), editing the rule,
  // one thing at a time; the selection's structural sections yield as
  // they do for an instance drill.
  const instanceDrill =
    Boolean(targetSelector) && isInstancePartKey(guide, parsed, bundle);
  const ruleFacet =
    Boolean(targetSelector) &&
    !instanceDrill &&
    !PART_SELECTOR.test(targetSelector);
  const basicTarget = instanceDrill || ruleFacet ? targetKey : selected;
  // The read subject: the ONE element the panel's rows read. 'element'
  // for the root facet (the selected element, also under an ancestor
  // rule reaching its root), 'part' for a part facet (the part element
  // inside it, instance or shell), null for a rule facet (a tag tier or
  // a chain: its matches are many, so the panel reads the rule itself,
  // floorless, style-stack.js subjectTag). The stack's base:{tag} tier
  // is the subject's tag from the tree; the one element-only gesture,
  // save as a variant (a variant is saved from an element's stack),
  // mounts for the element subject alone. Every other styling gesture
  // acts at the write target (writeKey below), whatever the subject.
  const subject = ruleFacet ? null : targetSelector ? 'part' : 'element';
  // The axes the mounted sections claim for this mode: a section that
  // IS a wearing's surface (basic mode's Layout segment for
  // container_layout) owns that row, so the wear bar drops it; advanced
  // and code mount no such section and the row shows there. Aggregated
  // here from what actually mounts (the Layout section mounts in basic
  // outside an instance drill or a rule facet), never special-cased in
  // the wear bar.
  const claimedAxes =
    mode === 'basic' && !instanceDrill && !ruleFacet
      ? LAYOUT_SECTION_CLAIMS
      : [];

  // The root scope's axis vocabulary: the delivery order the stack ranks
  // the root's worn references in.
  const axes = axisVocabulary(
    guide,
    selected,
    source.getBlockTypes(),
    source.getSectionLayouts(),
    source.getTree?.() ?? [],
  );
  // The session frame's posture for this render (session-frame-model.js
  // framePosture): 'editing' mounts the frame over the variant document,
  // 'creating' over the draft, 'parked' renders the resume chip, null
  // renders plain places, including an update session whose `editing`
  // echo is still in flight (nothing mounts anchorless).
  const posture = framePosture(pending, editing, parked);

  // The address set (write-address.js): the subject facet's addresses,
  // the chip's menu; and the one the rows write at this render,
  // `address`: the spine's write address when it names a key other than
  // the subject's own, null otherwise. The rows stay the SUBJECT's and
  // read its whole stack; the address decides which layer is the
  // editable one (stackArgs.editable), where every styling gesture
  // lands (writeKey: property writes, media picks, the wear bar, the
  // library card, the Layout rows, Replay, the row highlights and the
  // forced pose; copy and paste key the subject), and which properties
  // render disabled with the roster's reason (withheld). The set exists
  // for an element facet (root, part, tag); a chain facet is a rule of
  // its own with none. The address holds in every mode: a mode chooses
  // a projection of the rows, never whether an address may stand. In
  // code mode it is the rule island the selected element's body prints
  // lit (element-view.js, DevTools' rule: the chip and the island
  // headers are two readouts of one spine fact, and a seat from the chip
  // mounts the rule's island, a mintable one empty). An address the set
  // does not carry (the spine admits any ancestor; a programmatic seat
  // can name one the chip never offered) stands all the same, lit on the
  // chip with its rows withheld by reach (controls.js, withheldAt): no
  // surface moves the selection on its own. The one posture without an
  // address is an open variant session (its writes go to the document,
  // not a map key); seatAnchor re-seats a rule as the subject before a
  // session enters, dropping the address with the facet write.
  const own = { key: selected, selector: targetSelector };
  const addressable = stylable && posture !== 'editing';
  const addresses = addressable
    ? addressSet({
        guide,
        map: styleMap,
        key: selected,
        selector: targetSelector,
        tree,
        variants: library,
        axes,
        blockTypes: source.getBlockTypes(),
        sectionLayouts: source.getSectionLayouts(),
        markup: source.getMarkup?.(selected) ?? null,
      })
    : [];
  const address =
    addressable && isAddress(spineAddress) && !sameAddress(spineAddress, own)
      ? spineAddress
      : null;
  // Where a write lands: the address's map key, else the drill target.
  const writeKey = address ? addressKey(address) : targetKey;
  // The write target split for the canvas chrome (row highlights, the
  // Motion section's Replay): the selectable key plus the relative
  // selector, so a rule's rows paint and replay its every match.
  const highlightAt = writeKey
    ? styleTarget(writeKey)
    : { key: null, selector: null };
  // Where WEAR lands: the write target when its landing is an element
  // scope (an element, a tag tier, a type chain: variant-scope.js
  // captureScope, the scope the server pairs a reference at), else
  // nothing can be worn there (a part, the children) and the wear bar
  // shows the selected element's wearings read-only, the layers the
  // part's rows read through (VariantArea readOnly).
  const wearable =
    Boolean(parsed) &&
    captureScope(
      writeKey,
      source.getBlockTypes(),
      source.getSectionLayouts(),
    ) !== null;
  const wearKey = wearable ? writeKey : selected;

  // Leaving a session with pending edits prompts Push/Discard (the
  // pending engine's honesty net, re-keyed to the mode): an update
  // session leaves when the wear key stops wearing its variant (a
  // selection or target move to a non-wearer, a detach/remove/swap here
  // or in the satellite), the synced `editing` moves to a DIFFERENT
  // document (the header switcher; one session per document), or
  // `editing` clears without a park (a deliberate exit, like the
  // overridden-by badge's jump to the element's own layer). A save
  // session leaves when the selection walks out of its anchor's subtree
  // (moving WITHIN the capture is review, not leaving) or a real
  // document mounts the mode: the draft holds the panel only while
  // `editing` is null. A queued replacement (update-from-element,
  // save-as, or a doorway over an open session) displaces either kind.
  // A PARKED session is suspended, not being left: the effect stands
  // down until it resumes or a replacement unparks it into the
  // displacement lane. The session detaches immediately — the bar drops
  // and a second change cannot double-prompt — and the dialog decides
  // whether the snapshot commits. Nothing committable (no staged lines;
  // an unnamed or empty capture) discards silently: there is no
  // consequence to be loud about. A wearer-to-wearer move of the
  // selection or the target re-anchors instead (surface-state.js
  // reanchorSession): the staged lines are variant-relative, only the
  // preview context travels.
  const promptBusy = useRef(false);
  useEffect(() => {
    if (!pending || parked || promptBusy.current) {
      return;
    }
    const nodeTree = source.getTree?.() ?? [];
    const displaced = queuedSession.current !== null;
    let leaving;
    if (stagesLines(pending)) {
      const wears =
        pending.kind === 'skin'
          ? subjectSkinScopes.includes(pending.scope)
          : Boolean(wearKey) &&
            Boolean(wearingOf(styleMap?.[wearKey], pending.reference));
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
      if (!leaving && wearKey !== pending.anchor) {
        setPending((previous) => reanchorSession(previous, wearKey));
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
              pageNodes(nodeTree, source.getRootUuid()),
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
      stagesLines(session) &&
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
    if (!committableSession(session, nodeTree)) {
      installQueued();
      return;
    }
    promptBusy.current = true;
    const ask =
      session.kind === 'skin'
        ? Drupal.Daedalus.dialog.confirm({
            title: Drupal.t('Pending skin changes'),
            message: Drupal.formatPlural(
              sessionCount(session),
              'Push 1 pending change to the skin\'s !scope default?',
              'Push @count pending changes to the skin\'s !scope default?',
              { '!scope': skinScopeLabel(session.scope, guide) },
            ),
            detail: Drupal.t('This restyles every !scope site-wide.', {
              '!scope': skinScopeLabel(session.scope, guide),
            }),
            confirmLabel: Drupal.t('Push'),
            cancelLabel: Drupal.t('Discard'),
          })
        : session.kind === 'update'
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
                  'Push 1 pending change to "!label"?',
                  'Push @count pending changes to "!label"?',
                  { '!label': label },
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
            message: Drupal.t('Save the captured styles as "!label"?', {
              '!label': session.label.trim(),
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
  }, [pending, parked, wearKey, editing, styleMap]);

  // The move rule (surface-state.js selectionMove), keyed by the wear
  // key so a target move counts like a selection move: the session is
  // an overlay, so the surface passes through untouched in every
  // branch. An editing session survives the move when the new wear key
  // wears the edited document (the session re-anchors above), otherwise
  // `editing` clears and the mode exits (the leave-prompt effect above
  // fires first when pending work would be left behind — it watches the
  // same wearing fact).
  const movedFor = useRef(wearKey);
  useEffect(() => {
    if (movedFor.current === wearKey) {
      return;
    }
    movedFor.current = wearKey;
    const moved = selectionMove(
      { place: placeValue.place, editing },
      {
        entry: styleMap?.[wearKey],
        pending: pending ? sessionCount(pending) : 0,
        skinScopes: subjectSkinScopes,
      },
    );
    if (moved.editing !== editing) {
      setPlace(moved.place, moved.editing);
    }
  }, [wearKey, styleMap, library]);

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
  // The advanced row's handlers: the PAIR shape (property, value), the
  // row's own contract (PropertyWidget's docblock), never the widget
  // contract's object. `handlePreview` is the one pair-shaped preview in
  // the panel.
  const handlePreview = (property, value) =>
    source.preview(writeKey, condition, property, value);
  const handleRelease = (property, value) =>
    source.previewRelease(writeKey, condition, { [property]: value });
  // Editing never detaches (the layered entry model): the op writes an
  // override layer and the references stay. The overriding origin on the
  // row is the honest indication the old detach warning used to be.
  const handleCommit = (property, value) =>
    source.commit({
      key: writeKey,
      condition,
      properties: { [property]: value },
      label: Drupal.t('Styled !property', { '!property': property }),
    });
  // The media rows' render inputs, bundled once per render: the uuid → URL
  // table (thumbnails; the leader reads its live object, the satellite its
  // last broadcast), the honest can-open gate, and the pick trigger: ONE
  // change at the write key and condition, the property holding the
  // placeholder the selection fills (the advanced row seeds no
  // companions, basic-widget policy). The source owns the whole pick
  // delivery; optional chaining keeps older sources (tests, embeds) safe.
  const media = {
    urls: source.getMediaUrls?.() ?? {},
    canPick: !!source.canPickMedia?.(),
    onPick: (property) =>
      source.pickMedia?.({
        condition,
        property,
        label: Drupal.t('Styled !property', { '!property': property }),
        changes: [
          { key: writeKey, properties: { [property]: MEDIA_PICK_PLACEHOLDER } },
        ],
      }),
  };

  // The creating posture's mint-time consent chip: the draft's anchor
  // sits inside a blueprint placement, so the new variant will be born as
  // that blueprint's own switchable look. session-frame-model.js
  // mintConsent is the ONE read behind the chip and commitSession's
  // blueprint send, so what the chip says is what Save commits; null
  // renders no chip (unlinked, no provenance, or not a draft). The
  // label resolves through the store's blueprint record, the same in both
  // windows.
  const consent =
    posture === 'creating'
      ? mintConsent(
          pending,
          blueprintProvenance(pending.anchor, source.getTree?.() ?? []),
          materials,
        )
      : null;
  // The edited variant document: the honest wearing projection — the
  // synced `editing` counts only while the wear key actually wears it
  // (wearingOf at the anchor: the selected element, whose wearing a
  // part drill reads as a slice, or the rule the target names; a
  // detached/removed/stale reference renders as no document, never a
  // dangling write target) — plus the edit gates up front: a locked
  // variant is read-only (the distro lock as the record's updatable
  // flag says it; a shipped variant opens under the developer gate),
  // and without the update grant there is no pending lane to stage
  // into.
  const editedSkinScope = editing !== null ? skinDocumentScope(editing) : null;
  const editedWearing =
    stylable && editing && editedSkinScope === null
      ? wearingOf(styleMap?.[wearKey], editing)
      : null;
  const editedVariant = editedWearing ? findVariant(library, editing) : null;
  // The skin document's wearing fact: the scopes whose skin block the
  // read subject's stack carries (style-stack.js skinScopesAt: the
  // subject's base:{tag} block, the family kind's, the refinement's).
  // The skin's document at a scope is worn by every element of the
  // scope, so the session follows the selection from h2 to h2 and exits
  // on a paragraph, as a variant session follows its wearers. The slot
  // the rows read and write is the root position for a base block
  // (it styles the subject element itself) and the drilled relative key
  // for an element scope.
  const subjectSkinScopes =
    stylable
      ? skinScopesAt({
          key: targetKey,
          blockTypes: source.getBlockTypes(),
          sectionLayouts: source.getSectionLayouts(),
          tree,
        })
      : [];
  const skinWearing =
    editedSkinScope !== null && subjectSkinScopes.includes(editedSkinScope)
      ? {
          scope: editedSkinScope,
          slot: editedSkinScope.startsWith('base:')
            ? ''
            : targetSelector
              ? `::${targetSelector}`
              : '',
        }
      : null;
  // The edited document, whichever kind: its reference, its stored
  // styles (the diff baseline: a variant's element-relative styles, a
  // skin scope's defaults as the live guide carries them), the slot the
  // Sections-level rows read at the drill target (anchored at the
  // element, a variant's slot is the wearing fragment plus the drilled
  // part's relative key; anchored at a rule, the fragment alone: the
  // rule's matches ARE the variant's root, and the rows read the rule's
  // own stack), and the session mint for a first settle without one.
  const editedDocument = editedWearing
    ? {
        reference: editedWearing.variant,
        label: editedVariant?.label ?? editedWearing.variant,
        fragment: editedWearing.fragment,
        styles: editedVariant?.value ?? {},
        slot:
          editedWearing.fragment +
          (wearKey === selected && targetSelector ? `::${targetSelector}` : ''),
        rootKind: rootKindForScope(editedVariant?.scope ?? null),
        mint: () => updateSession(editedWearing.variant, wearKey),
      }
    : skinWearing
      ? {
          reference: editing,
          label: skinScopeLabel(skinWearing.scope, guide),
          fragment: '',
          styles: guide.skinDefaults?.[skinWearing.scope] ?? {},
          slot: skinWearing.slot,
          rootKind: rootKindForScope(skinWearing.scope),
          mint: () => skinSession(skinWearing.scope, wearKey),
        }
      : null;
  const documentEditable = editedWearing
    ? editedVariant !== null &&
      !locked(editedVariant) &&
      Boolean(variants?.canUpdate)
    : skinWearing !== null && Boolean(skin?.canEditDefaults);
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
  // dismiss); the wearer-cascade honesty read behind preview gating and
  // the overridden origin is the stack's (the session overlay makes the
  // document the editable layer, and any layer above it wins). The
  // doorway minted the session at entry (one session per edited
  // document); a settle re-mints only when the open session no longer
  // matches this document and anchor.
  const widgetSheet =
    mode !== 'code' &&
    posture === 'editing' &&
    editedDocument &&
    documentEditable
      ? {
          reference: editedDocument.reference,
          label: editedDocument.label,
          fragment: editedDocument.fragment,
          styles: editedDocument.styles,
          session:
            stagesLines(pending) &&
            pending.reference === editedDocument.reference
              ? pending
              : null,
          stage: (slot, conditionName, property, value) =>
            setPending((previous) => {
              const open =
                stagesLines(previous) &&
                previous.reference === editedDocument.reference &&
                previous.anchor === wearKey;
              const next = stageValue(
                open ? previous : editedDocument.mint(),
                editedDocument.styles,
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
        }
      : null;
  // The Sections-level slot projection: the drill target's slice of the
  // variant's document, session overlaid — what the rows read, plus the
  // pending table their chrome renders. Anchored at the element, the
  // slot is the wearing fragment plus the drilled part's relative key;
  // anchored at a rule, the fragment alone: the rule's matches ARE the
  // variant's root, and the rows read the rule's own stack.
  const sheetContext = widgetSheet
    ? (() => {
        const { slot } = editedDocument;
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
          baselineOf: (conditionName, property) =>
            widgetSheet.styles?.[slot]?.[conditionName]?.[property],
          onWinner: navigateToWinner,
        };
      })()
    : null;
  // The drill target's layer stack (style-stack.js buildStack): the ONE
  // model the advanced rows read, declared and skin tiers beneath the
  // root's worn references beneath the own entry, the skin's base layer
  // keyed by the read subject's tag from the tree (none for a rule
  // facet), and in an editing session the edited
  // document overlaid as the editable layer (the pending session's
  // staged lines included), or under a write address that rule's
  // ancestor layer as the editable one (a virtual empty layer until its
  // first write mints the entry). Built once per render; every row reads
  // it at its property and the pose (stackAt).
  const stackArgs = targetKey
    ? {
        guide,
        map: styleMap,
        key: targetKey,
        variants: library,
        axes,
        blockTypes: source.getBlockTypes(),
        sectionLayouts: source.getSectionLayouts(),
        tree,
        session: widgetSheet
          ? { reference: widgetSheet.reference, pending: widgetSheet.session }
          : null,
        editable: address,
      }
    : null;
  const stack = stackArgs ? buildStack(stackArgs) : null;
  // The same stack without its floor: the map's layers only (the root's
  // worn references and the own entry, the editing session's document
  // among them), which is what "where styles already live" means for
  // the Styled-at chips. The declared and skin tiers style the element
  // too, but they are not the map's conditions to drive the canvas to.
  // Under a write address the chips speak the map's conditions AT THE
  // ADDRESS (the rule the panel writes), so the stack is built at the
  // write key itself: a floorless build carries no ancestor tier and so
  // no editable layer for an address.
  const mapStack = !stackArgs
    ? null
    : address
      ? buildStack({
          guide,
          map: styleMap,
          key: writeKey,
          variants: library,
          axes: axisVocabulary(
            guide,
            address.key,
            source.getBlockTypes(),
            source.getSectionLayouts(),
            source.getTree?.() ?? [],
          ),
          blockTypes: source.getBlockTypes(),
          sectionLayouts: source.getSectionLayouts(),
          floor: false,
        })
      : buildStack({ ...stackArgs, floor: false });
  // The Advanced roster, built once: the declared groups, plus a row for
  // every property the stack delivers at any stored condition
  // (deliveredEntry, the exact winners per condition), so a variant's or
  // the skin's value on an ungranted property still rows. `Sections`
  // renders it and `withheld` is judged over it, one derivation for
  // both. (No hook memo: `stack` is rebuilt every render above, so a
  // dependency on it would never hold.)
  const sections = stack
    ? buildSections(
        guide,
        declarationForKey(guide, parsed, bundle),
        deliveredEntry(stack),
      )
    : null;
  // The properties the address withholds among the rows the Advanced
  // surface shows: each renders disabled with the roster's reason as its
  // title (write-address.js withheldAt). Null without an address:
  // today's rows byte for byte.
  const withheld =
    address && sections
      ? withheldAt({
          guide,
          address,
          properties: sections.flatMap((section) =>
            section.rows.map((row) => row.property),
          ),
          blockTypes: source.getBlockTypes(),
          sectionLayouts: source.getSectionLayouts(),
          tree,
        })
      : null;
  // The code mode's document context (VariantDocumentView): the whole
  // document over the variant's own key space; islands stage whole
  // (slot, condition) diffs (stageIslandLines), the session opening on
  // first staging exactly like the widget lane.
  const codeDocument =
    mode === 'code' &&
    posture === 'editing' &&
    editedDocument &&
    documentEditable
      ? {
          reference: editedDocument.reference,
          label: editedDocument.label,
          styles: editedDocument.styles,
          session:
            stagesLines(pending) &&
            pending.reference === editedDocument.reference
              ? pending
              : null,
          rootKind: editedDocument.rootKind,
          stage: (relative, conditionName, lines) =>
            setPending((previous) => {
              const open =
                stagesLines(previous) &&
                previous.reference === editedDocument.reference &&
                previous.anchor === wearKey;
              // No session and nothing settled: do not mint an empty session
              // (the bar would appear on every blur under a fresh document).
              if (!open && !lines.length) {
                return previous;
              }
              return stageIslandLines(
                open ? previous : editedDocument.mint(),
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
      // The honesty read per staged line: the line's key's stack with
      // the session overlaid (floorless for a variant document: the
      // tiers never beat it; with the floor for the skin's, whose
      // document IS a floor layer), read at the line's condition; a
      // layer above the document (the wearer's own entry, a higher
      // reference, for the skin's every map layer) means the canvas
      // would not show the line, so it never previews.
      const skinDoc = isSkinDocument(widgetSheet.reference);
      const beaten = (lineKey, lineCondition, property) => {
        const keyStack = buildStack({
          guide,
          map: styleMap,
          key: lineKey,
          variants: library,
          axes,
          blockTypes: source.getBlockTypes(),
          sectionLayouts: source.getSectionLayouts(),
          floor: skinDoc,
          tree: skinDoc ? tree : null,
          session: {
            reference: widgetSheet.reference,
            pending: widgetSheet.session,
          },
        });
        const read = stackAt(
          keyStack,
          property,
          lineCondition,
          guide?.conditions ?? {},
        );
        return read.winner !== null && read.winner.layer > editableLayer(keyStack);
      };
      widgetSheet.session.lines.forEach(
        ({ relative, condition: lineCondition, property, value }) => {
          if (value === null || !relative.startsWith(widgetSheet.fragment)) {
            return;
          }
          const rel = relative.slice(widgetSheet.fragment.length);
          if (rel !== '' && !rel.startsWith('::')) {
            return;
          }
          // A base block's document keys the subject element itself
          // (the drill target, a part's tag included); every other
          // document keys under the selected root.
          const lineKey =
            skinDoc && editedDocument.slot === '' && targetSelector
              ? targetKey + rel
              : selected + rel;
          if (beaten(lineKey, lineCondition, property)) {
            return;
          }
          desired.set(`${lineKey}|${lineCondition}|${property}`, {
            key: lineKey,
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

  // The library card's "Apply to selection" offer: the wear target's
  // applicable set (the same scope/ownership/tag gates the wear bar's
  // picker speaks, paired at the target's landing, the tag gate keyed
  // by the subject's tag from the tree or a tag rule's own, style-
  // stack.js offerTag), empty without a stylable selection. Membership
  // is the doorway's fit answer (library-model.js applyDoorway).
  const applyOffer =
    selected && stylable
      ? applicableVariants(
          materials,
          wearKey,
          source.getBlockTypes(),
          source.getSectionLayouts(),
          source.getTree?.() ?? [],
          offerTag(tree, wearKey),
        )
      : [];
  // The target's blueprint provenance, for the doorway's honest unfit
  // titles ("belongs to blueprint X" beats a vague scope shrug): the same
  // read the offer filter already made inside applicableVariants.
  const applyProvenance =
    selected && stylable
      ? blueprintProvenance(wearKey, source.getTree?.() ?? [])
      : null;

  // The subject line (subject-line.js): the selection pill, "at", the
  // address chip, WHAT is styled and AT which address, the first line of the
  // panel's sentence, pinned under the title in every mode of the
  // styling place and on the Materials place (the catalog's cards apply
  // at the write key, so the chip says where). The chip's menu is the
  // address set above (each pick a write address through
  // source.address), the own entry dropping the address, the way back
  // to the bare element when a facet stands, and the box doorways (a
  // facet seat) where the mode styles boxes.
  const subjectLine = selected ? (
    <SubjectLine
      guide={guide}
      source={source}
      selected={selected}
      targetKey={targetKey}
      tree={tree}
      own={own}
      addresses={addresses}
      address={address}
      blockTypes={source.getBlockTypes()}
      sectionLayouts={source.getSectionLayouts()}
      markup={source.getMarkup?.(selected) ?? null}
      boxes={mode !== 'basic'}
    />
  ) : null;

  // The wear bar (variants.js VariantArea): what the subject WEARS, the
  // first thing in the body in every mode of the styling place (wear is
  // a fact of the subject, not of the editor, so the code tree gets it
  // too). The wearings at the wear target (wearKey: the selected
  // element, a tag tier or chain seated on it, or the ancestor rule the
  // chip names), with every wear gesture landing there. Where nothing
  // can be worn (a part, the children) the area shows the selected
  // element's wearings read-only: they anchor every "from <variant>"
  // mark on the rows beneath, and its rows' one doorway selects that
  // wearer. The editing-session doorways anchor the session at the wear
  // key, an element or a rule alike (the document then previews on
  // every match), so they are wired at every wearable target and the
  // read-only posture alone withdraws them.
  const wearBar =
    selected && stylable ? (
      <VariantArea
        source={source}
        guide={guide}
        materials={materials}
        target={wearKey}
        styleMap={styleMap}
        variants={library}
        blockTypes={source.getBlockTypes()}
        sectionLayouts={source.getSectionLayouts()}
        config={variants}
        claimedAxes={claimedAxes}
        condition={condition}
        readOnly={!wearable}
        outline={outline}
        onFlash={setFlash}
        onOpenDocument={openDocument}
        onUpdateFromElement={updateFromElement}
        onVariantSettings={openVariantSettings}
      />
    ) : null;

  // The session frame mounts OVER the places (the creating posture, or
  // the editing posture once the honest wearing projection anchors it);
  // the Materials toggle waits while it is mounted; the mode is not a
  // place to navigate away from, Push/Discard/park are the exits. A parked
  // session leaves the places up and renders the resume chip instead.
  const frameMounted =
    posture === 'creating' || (posture === 'editing' && editedDocument !== null);
  const draftVocabulary =
    pending?.kind === 'save'
      ? axisVocabulary(
          guide,
          pending.anchor,
          source.getBlockTypes(),
          source.getSectionLayouts(),
          source.getTree?.() ?? [],
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
                source.getTree?.() ?? [],
                library,
                pending.axis,
              )
            : null,
          guide,
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
          {/* The element style gestures (save / Copy CSS / Paste CSS),
              icon-sized: one doorway across all three modes, for the
              element subject of a stylable selection alone (a variant is
              saved from an element, never from a part or a rule), so
              presence still means applicability. Save opens a pending
              save session on the Push/Discard bar; copy and paste act at
              the write target through the system clipboard. */}
          {selected && subject === 'element' && stylable && (
            <StyleActions
              source={source}
              guide={guide}
              selected={selected}
              target={writeKey}
              styleMap={styleMap}
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
      {/* The pinned rows under the title, the panel's sentence before its
          editor: the subject line (WHAT, AT which address) and the pose
          group (when: the chip row, and the Styled at line beneath it in
          the widget modes) are the element's facts and render in every
          mode; the mode row (HOW: Basic | Advanced | code, Materials)
          is the editor's control; and last the head, the listing's
          own line, a slot the mode view fills through a Portal with
          the controls that act on what scrolls beneath (code: the
          showing row, the lens with the collapse pair; advanced: the
          word "Sections" and the pair; basic: nothing, and the empty
          slot takes no room). The seam between pinned and scrolling is
          the head's bottom edge: a reader scrolled deep into the
          listing still reaches its lens and its pair (Tim, 2026-10-05),
          while the wear bar and the rows scroll in the body beneath. */}
      <div className="daedalus-stylish-inspector__top">
        {subjectLine}
        {/* The pose group: one chip row, the styling place in every mode,
            selection or not (the canvas axes are page-scoped; only the
            state chip needs a selection), and the editing session, which
            poses its previews the same way. On the widget modes the row
            picks where writes land; on the code tree it picks what the
            canvas previews (islands address their own conditions). Its
            second line, Styled at, is where styles already live at the
            write target, in basic and advanced (code mode enumerates
            every condition as an island): chips drive the canvas there,
            posing IS switching the condition. The catalog poses nothing,
            so it renders no group. */}
        {(shownPlace === 'styles' || frameMounted) && (
          <div className="daedalus-stylish-inspector__pose">
            <ChipRow
              source={source}
              guide={guide}
              vector={vector}
              forceState={forceState}
              hasSelection={Boolean(selected)}
              previewWindow={previewWindow}
            />
            {selected &&
              stylable &&
              mode !== 'code' &&
              (!frameMounted || sheetContext) && (
                <StyledAt
                  stack={mapStack}
                  condition={condition}
                  onDrive={driveCanvas}
                />
              )}
          </div>
        )}
        {/* The mode row: the inspector's four controls answer four
            questions and this row holds the two panel-global ones. The
            styling modes (left) are a preference over the styling place;
            the Materials toggle (right) is the other place, the catalog.
            Exactly one of the four is lit: a mode click always lands in
            the styling place, Materials always in the catalog. */}
        <div className="daedalus-stylish-inspector__mode-row">
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
        {/* The Materials place: the catalog, mode-independent,
            selection-free. A text button with a four-square glyph, not
            a fourth segment: the track means the styling mode and the
            catalog is not one. The session frame replaces the places
            while it is mounted, so the button waits it out honestly (a
            mode click still re-bodies the session). */}
        <button
          type="button"
          className={`daedalus-stylish-inspector__place${shownPlace === 'materials' ? ' daedalus-stylish-inspector__place--shown' : ''}`}
          aria-pressed={shownPlace === 'materials'}
          disabled={frameMounted}
          onClick={() => setPlace('materials', editing)}
        >
          <svg
            viewBox="0 0 12 12"
            width="12"
            height="12"
            fill="currentColor"
            aria-hidden="true"
          >
            <rect x="0" y="0" width="5" height="5" rx="1" />
            <rect x="7" y="0" width="5" height="5" rx="1" />
            <rect x="0" y="7" width="5" height="5" rx="1" />
            <rect x="7" y="7" width="5" height="5" rx="1" />
          </svg>
          {Drupal.t('Materials')}
        </button>
        </div>
        {/* The head slot: daedalus_ui's panel slot (empty, it takes no
            room, panel-controls.css), lent to the mode view as its
            Portal root; the mount effect above lifts the node into
            state. */}
        <div
          className="daedalus-stylish-inspector__head daedalus-ui-panel__slot"
          ref={headRef}
        />
      </div>
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
            ) : editedWearing && !editedVariant ? (
              <div className="daedalus-stylish-inspector__empty">
                {Drupal.t(
                  "This variant is not among the site's materials (deleted or unpublished); there is no document to edit.",
                )}
              </div>
            ) : !documentEditable ? (
              <div className="daedalus-stylish-inspector__empty">
                {skinWearing
                  ? Drupal.t('You cannot edit skin defaults.')
                  : locked(editedVariant)
                    ? Drupal.t(
                        '"!label" is built-in and read-only. Copy it as your variant (Variant settings…) to change it.',
                        { '!label': editedVariant.label },
                      )
                    : Drupal.t('You cannot update variants.')}
              </div>
            ) : mode === 'code' ? (
              <VariantDocumentView
                source={source}
                guide={guide}
                materials={materials}
                anchor={wearKey}
                styleMap={styleMap}
                variants={library}
                document={codeDocument}
                posed={condition}
                onPose={driveCanvas}
              />
            ) : (
              sheetContext && (
                /* The pose group's Styled at line above speaks the map's
                   conditions at this target, the edited document
                   overlaid as its layer. */
                <Sections
                  key={targetKey}
                  guide={guide}
                  materials={materials}
                  sections={sections}
                  stack={stack}
                  condition={condition}
                  source={source}
                  highlightKey={highlightAt.key}
                  highlightSelector={highlightAt.selector}
                  media={{ ...media, canPick: false }}
                  swatches={swatches}
                  sheet={sheetContext}
                  pending={sheetContext.pending}
                  head={head}
                  onOpenDocument={openDocument}
                  onPreview={handlePreview}
                  onRelease={handleRelease}
                  onCommit={handleCommit}
                  onPickCondition={driveCanvas}
                />
              )
            )}
          </SessionFrame>
        </div>
      ) : (
        <Fragment>
          {shownPlace === 'materials' ? (
            /* The Materials place: the catalog, mode-independent, site-wide
           content, no selection needed. Five sections, one place: the
           variant catalog, the Palette (the value catalog: user swatches
           over the skin's tokens), the Animations (the motion catalog:
           named keyframes), the Fonts (the type catalog: self-hosted
           families) and the Law (the guide, read-only, following the
           selection to its type). */
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
                  ['fonts', Drupal.t('Fonts')],
                  ['law', Drupal.t('Law')],
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
                  swatches={swatches}
                  tokens={tokens}
                  skin={skin}
                  source={source}
                />
              ) : catalogSection === 'animations' ? (
                <AnimationsView
                  guide={guide}
                  materials={materials}
                  config={animations}
                  source={source}
                />
              ) : catalogSection === 'fonts' ? (
                <FontsView materials={materials} config={fonts} source={source} />
              ) : catalogSection === 'law' ? (
                <LawView
                  guide={guide}
                  scope={
                    lawFocus?.scope ??
                    lawScopeFor({
                      key: selected,
                      blockTypes: source.getBlockTypes(),
                      sectionLayouts: source.getSectionLayouts(),
                      law: guide?.law ?? null,
                    })
                  }
                  following={lawFocus === null}
                  focus={lawFocus}
                  onScope={(scope) => setLawFocus({ scope, property: null })}
                />
              ) : (
                <LibraryView
                  guide={guide}
                  materials={materials}
                  config={variants}
                  styleMap={styleMap}
                  target={wearKey}
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
          ) : mode === 'code' && selected && !stylable ? (
            /* Code mode reads the same gate: an element the actor may not
               style mounts no stylesheet editor. */
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
              <div className="daedalus-stylish-inspector__empty">
                {Drupal.t('Styling is not enabled for this element.')}
              </div>
            </div>
          ) : mode === 'code' ? (
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
              {/* The styling place in code mode: the wear bar first (wear
              is a fact of the subject, not of the editor, so the bar
              opens the body in every mode), then the stylesheet read at
              the pinned scope: the islands tree projected by it,
              selection synced with the canvas both ways through the
              source (the selected element mounts the full ElementIslands
              body, so focused editing is selection, not a separate
              place). */}
              {wearBar}
              <PageCodeView
                source={source}
                guide={guide}
                materials={materials}
                selected={selected}
                targetSelector={targetSelector}
                address={address}
                styleMap={styleMap}
                variants={library}
                scope={scope}
                onScope={(value) => codeScopeStore.set(value)}
                empty={<SelectAnElement />}
                posed={condition}
                onPose={driveCanvas}
                onOpenDocument={openDocument}
                head={head}
              />
            </div>
          ) : selected ? (
            <div className="daedalus-stylish-inspector__body daedalus-ui-panel__body">
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
                  navigates to that variant's document. The wear bar
                  opens the body, as in every mode. */}
                  {wearBar}
                  {mode === 'basic' ? (
                    /* Basic: the target's declared controls, reading and
                   writing at the editing compound the canvas is posed at
                   — the one write rule, same as advanced. The target is
                   basicTarget: the drilled INSTANCE part when one is
                   selected (its namespace's controls, bound to the part
                   key — the selected paragraph styles, never its owner),
                   a rule facet's rule key (a chain or tag seated from
                   code mode: the landing declaration's rows over the
                   rule, one thing at a time), the selection otherwise (a
                   drilled shell part keeps the owner's panel, whose
                   element: bindings address it — the lift pattern). The
                   layout widget's two sections stay selection-scoped
                   for their STRUCTURAL gestures (they write the BLOCK's
                   structure), so they yield while an instance part or a
                   rule is targeted; the Layout section's property rows
                   (the wear segment, alignment, gap) read and write at
                   the panel's write address when the root facet has one
                   (an ancestor rule reaching the container: every
                   matching container arranges alike), else at the
                   root, at the same compound as every other row; the
                   segment is a wear surface whose posed sub-line opens
                   the variant's document at that condition: dedicated
                   sections, not ControlWidgets, because they write
                   structure and multi-key batches.
                   The Column section leads; the Layout section is the
                   HOST of the roster's Layout group (BasicControls'
                   leading slot, mounted only where its gate passes), so
                   a container reads one Layout header at the top of the
                   panel: structure first, the bound controls after, the
                   roster sections beneath. */
                    <Fragment>
                      {!instanceDrill && !ruleFacet && (
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
                        targetSelector={
                          instanceDrill || ruleFacet ? null : targetSelector
                        }
                        rule={ruleFacet}
                        box={ruleFacet && pseudoOf(targetSelector) !== null}
                        writeAddress={address}
                        styleMap={styleMap}
                        variants={library}
                        condition={condition}
                        swatches={swatches}
                        onPickCondition={driveCanvas}
                        onOpenDocument={openDocument}
                        leading={{
                          ...(!instanceDrill &&
                          !ruleFacet &&
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
                                    writeAddress={
                                      targetSelector === null ? address : null
                                    }
                                    onOpenDocument={openDocument}
                                    onPickCondition={driveCanvas}
                                  />
                                ),
                              }
                            : {}),
                        }}
                      />
                    </Fragment>
                  ) : (
                    /* Advanced: raw property-group sections at the editing
                   compound the canvas is posed at (the pinned pose group
                   replaces the old free condition picker). */
                    <Sections
                      key={writeKey}
                      guide={guide}
                      materials={materials}
                      sections={sections}
                      stack={stack}
                      condition={condition}
                      source={source}
                      highlightKey={highlightAt.key}
                      highlightSelector={highlightAt.selector}
                      withheld={withheld}
                      media={media}
                      swatches={swatches}
                      head={head}
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
