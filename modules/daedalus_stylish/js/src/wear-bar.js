import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { useMenu } from './menu.js';
import { layerColor, variantLayerId } from './code/layers.js';
import {
  applicableVariants,
  axisVocabulary,
  entryRefs,
  findVariant,
  locked,
  rankVariants,
} from './variant-scope.js';
import { updateFromElementLines } from './code/variant-document.js';
import {
  overriddenWearing,
  refusalText,
  staleReason,
  wearAtPose,
  wearOrigin,
  wearRowHeader,
  wearRowLabel,
  wearRowMenu,
  wornProperties,
  wornRows,
} from './wear-bar-model.js';
import { usePopover } from './popover.js';
import { VariantPicker } from './variant-picker.js';
import { setOpener } from './apply-variant-hotkey.js';
import { highlightClaimsFor } from './highlight-claims.js';
import { lineTints as lineTintsStore } from './line-tints.js';
import { compoundLabel } from './condition-cascade.js';

/**
 * The wearing rows: the styling place's variant control (four controls,
 * one loud mode).
 *
 * One row per WORN reference of the selection (wornRows: axis-declaration
 * order, claimed axes yield, stale references after), each row dot · axis
 * label · variant label. Clicking a worn row's body is a DOORWAY into
 * the editing session on that document (`onOpenDocument`): a variant is
 * edited in the session, never by flipping this place's write target.
 * The variant label is the SWAP TRIGGER: it opens the variant picker
 * preset to the row's axis. Offering is one gesture, the ADD ROW ("Apply
 * variant"), which opens the same picker over every axis: the
 * applicable, fit-ranked offer grouped by axis, searchable, worn
 * variants marked (variant-picker.js). A pick is an ordinary
 * apply_variant op (instant cadence, tempstore net, which is why the
 * rows live here) that dresses ONE axis: the reference replaces its
 * same-axis predecessor in place and everything else survives. So the
 * row count tracks what the element wears, never the vocabulary size.
 * References the store's records cannot resolve render as stale rows,
 * menu-detachable like everything else, and say WHY (staleReason): a
 * variant the style guide withholds under the site's current law names
 * the refused values in its title and menu header, the guide's
 * `withheld` verdict; any other stale reference is gone (deleted or
 * unpublished). Color = identity everywhere: a
 * worn row's dot wears its layer's stable hue (the same hue the code
 * view's line tints speak).
 *
 * The rows are a WEAR surface under the pose doctrine: a wear is
 * unconditioned by construction (the material owns its WHEN), so posing
 * the canvas never retargets a row. Posed away from default, each worn
 * row instead SAYS what its variant contributes there (wearAtPose: the
 * variant's own blocks the compound activates, "Lift at Hover:
 * transform…", or "Fade in sets nothing at Hover"), and the doorway
 * enters the editing session at that compound, where the document's
 * property rows read and write the posed block.
 *
 * A worn row whose declarations the element's OWN values hide is
 * OVERRIDDEN (overriddenWearing: the layer rule's honesty half, on the
 * stale row's idiom): the row carries a modifier class and a sub-line
 * naming the lost declarations ("padding-inline overridden by your
 * padding"; the match is grain-aware, so a band's own padding is seen
 * masking its width inset), and Reset overrides is the row's one ACTION
 * beside it, dropping the element's own values where they shadow the
 * variant so it shows through again. The mark lives here, where the
 * wearing is chosen; the widget rows' overriding badge reads the same
 * shadow table.
 *
 * A worn row a COMPOSITION put here (wearOrigin: the reference carries
 * `via`, the name of the composition variant whose apply nested it onto
 * this element) says so on the same idiom: a modifier class and a
 * sub-line "via Card Left" (the origin's label, its machine name when
 * the records cannot resolve it). It is derived data: switching or
 * removing that composition reaps this wearing, and applying a variant
 * here takes the axis over. Saying it is the whole change; the row's
 * gestures (the menu's Detach, Remove, Edit) apply unchanged.
 *
 * Every other per-row gesture lives in the right-click menu
 * (wear-bar-model.js computes the roster, headed by the variant's
 * identity facts: axis, weight, description): Outline Variant (the one
 * toggled coverage gesture — wearers outline on the canvas AND mark in
 * the page elements panel; outlineGesture, shared with the library
 * card), Variant tints (code view only — the global line-tints
 * preference), Re-apply, Detach, Remove (remove_variant), Update from
 * this element (the wearer's diff pre-staged as dismissible pending
 * lines in the document's session, Push commits), Edit variant styles
 * (the session doorway, for every resolved wearing), and Variant
 * settings… (the library card). The picker offers variants only; the
 * undress stays here.
 *
 * One popover (usePopover) serves every trigger: the add row and each
 * swap trigger point the hook's triggerRef at themselves as they open,
 * so placement, dismissal and focus return address the trigger that
 * opened it, and the picker floats under that trigger on the shared
 * surface, over the rows below.
 *
 * All ops speak through the state source (leader or satellite), and view
 * states live with their owners: the place state at the source,
 * outline/flash at the Inspector (they survive mode switches).
 * Save-as-variant is the header's save icon, which opens a pending save
 * session on the same Push/Discard bar; the fork ("Copy as my variant")
 * lives on the library card that Variant settings… opens.
 */

/** How long an applied variant's highlight stays painted after the swap. */
const APPLY_HOLD_MS = 1200;

export const WearBar = ({
  source,
  guide,
  materials,
  selected,
  styleMap,
  variants,
  blockTypes,
  sectionLayouts,
  config,
  claimedAxes = [],
  condition = 'default',
  codeView = false,
  outline = null,
  onFlash = null,
  onOpenDocument = null,
  onUpdateFromElement = null,
  onVariantSettings = null,
}) => {
  const [busy, setBusy] = useState(false);
  // The row context menu (the shared primitive); a selection move closes
  // it: its row may have scrolled away or changed meaning.
  const menu = useMenu();
  useEffect(() => {
    menu.close();
  }, [selected]);
  // Hover flash is transient chrome; never leave it behind on unmount.
  useEffect(() => () => onFlash?.(null), []);
  // The variant line tints preference (the row menu's global toggle):
  // subscribed so the checkmark follows changes from any window.
  const [tints, setTints] = useState(lineTintsStore.get());
  useEffect(() => lineTintsStore.subscribe(setTints), []);

  const library = variants ?? [];
  const entry = styleMap?.[selected];
  const refs = entryRefs(entry);
  const layout = source.getLayout ? source.getLayout() : [];
  const vocabulary = axisVocabulary(
    guide,
    selected,
    blockTypes,
    sectionLayouts,
    layout,
  );
  const { rows, stale } = wornRows(entry, library, vocabulary, claimedAxes);
  // The offer: scope-filtered (ownership and tag gates included), shipped
  // variants included (applying a shipped variant is fine; only pushing
  // into one is locked), fit-ranked for the picker's order and hints.
  const selectedTag = selected
    ? (source.readCanvasTag?.(selected, null) ?? null)
    : null;
  const ranked = rankVariants(
    applicableVariants(
      materials,
      selected,
      blockTypes,
      sectionLayouts,
      layout,
      selectedTag,
    ),
    selected,
    layout,
  );

  const labelOf = (reference) =>
    findVariant(library, reference)?.label ?? reference;
  // The pose: the canvas's editing compound, as site vocabulary. Default
  // poses nothing (every block but default is off), so the rows carry no
  // sub-line and the doorway names no condition.
  const posed = condition !== 'default';
  const poseLabel = compoundLabel(condition, guide?.conditions);
  // The doorway: the editing session on the row's document, at the
  // canvas's pose when it is posed (the session's rows then address the
  // posed block; the wear itself stays where it is).
  const open = (reference) =>
    onOpenDocument?.(reference, posed ? condition : null);
  // Busy state only: every gesture here rides the op queue, whose source
  // reports its failure (reportFailure on the leader, the satellite's
  // settle).
  const run = (work) => {
    setBusy(true);
    return work().finally(() => setBusy(false));
  };

  // Applying dresses ONE axis: the reference replaces its same-axis
  // predecessor in place and everything else — overrides, parts,
  // children, the other axes' references — survives, layering over it (the
  // layered entry model).
  const apply = (machineName) => {
    run(() =>
      source
        .applyVariant(
          selected,
          machineName,
          Drupal.t('Applied variant @label', {
            '@label': labelOf(machineName),
          }),
        )
        .catch(() => {
          // The op queue's source reports the failure.
        }),
    );
  };

  // Detach: the explicit unlink — one reference's delivered styles
  // materialize into the element's own overrides and the reference drops;
  // the look stays, the following stops.
  const detach = (reference) => {
    run(() =>
      source
        .detachVariant(
          selected,
          reference,
          Drupal.t('Detached variant @label', { '@label': labelOf(reference) }),
        )
        .catch(() => {
          // The op queue's source reports the failure.
        }),
    );
  };

  // Remove: the undress — remove_variant drops the reference WITHOUT
  // materializing (the op the layout widget always had; the inspector
  // finally exposes it), so the element falls back to whatever the layers
  // beneath deliver.
  const remove = (reference) => {
    run(() =>
      source
        .commitBatch({
          operations: [
            { op: 'remove_variant', key: selected, variant: reference },
          ],
          label: Drupal.t('Removed variant @label', {
            '@label': labelOf(reference),
          }),
        })
        .catch(() => {
          // The op queue's source reports the failure.
        }),
    );
  };

  // Reset overrides (the overridden row's action): drop the element's own
  // values shadowing this wearing (wear-bar-model.js computes the exact
  // unsets), so it follows fully.
  const reset = (reference, ops) => {
    run(() =>
      source
        .commitBatch({
          operations: ops,
          label: Drupal.t('Reset overrides of @label', {
            '@label': labelOf(reference),
          }),
        })
        .catch(() => {
          // The op queue's source reports the failure.
        }),
    );
  };

  // The menu's view mapping: wearRowMenu() owns which items appear; this
  // table owns how each renders and what it runs (the menu closes before
  // any item runs).
  const menuItems = (reference, isStale) => {
    const worn = isStale ? null : findVariant(library, reference);
    const wornRef = refs.find((ref) => ref.variant === reference) ?? {
      variant: reference,
      fragment: '',
    };
    const views = {
      outline: (item) => ({
        label: Drupal.t('Outline Variant'),
        checked: item.checked,
        hint: Drupal.t('canvas + elements panel'),
        title: Drupal.t(
          'Outline every element wearing this variant on the canvas and in the page elements panel until toggled off.',
        ),
        run: () => outline?.toggle(reference),
      }),
      tints: (item) => ({
        label: Drupal.t('Variant tints'),
        checked: item.checked,
        hint: Drupal.t('color lines by variant'),
        title: Drupal.t(
          "Wash every variant-supplied line in its variant's color across the code view. A display preference for all variants, not just this one.",
        ),
        run: () => lineTintsStore.set(item.checked ? 'off' : 'on'),
      }),
      update: () => ({
        label: Drupal.t('Update from this element'),
        hint: Drupal.t('stage the differences'),
        title: Drupal.t(
          "Stage this element's own changes as pending updates to the variant: review and dismiss them in the editing session, then Push to restyle every wearer.",
        ),
        run: () => onUpdateFromElement?.(reference),
      }),
      reapply: () => ({
        label: Drupal.t('Re-apply'),
        title: Drupal.t(
          'Resolve this variant against the current structure again: children added since it was applied get dressed where its fragments are not yet worn.',
        ),
        run: () => apply(reference),
      }),
      detach: () => ({
        label: Drupal.t('Detach'),
        hint: Drupal.t('keep styles, stop following'),
        title: Drupal.t(
          "Keep these styles as this element's own and stop following the variant.",
        ),
        run: () => detach(reference),
      }),
      remove: () => ({
        label: Drupal.t('Remove'),
        hint: Drupal.t('back to defaults'),
        title: Drupal.t(
          'Take the variant off without keeping its styles; whatever the remaining layers deliver shows instead.',
        ),
        run: () => remove(reference),
      }),
      'edit-full': () => ({
        label: Drupal.t('Edit variant styles'),
        title: Drupal.t(
          "Edit this variant's whole document, in context of this wearer. Edits are pending until pushed.",
        ),
        run: () => open(reference),
      }),
      settings: () => ({
        label: Drupal.t('Variant settings…'),
        title: Drupal.t(
          "Open this variant's card in Materials: rename, revisions, usage, duplicate, delete.",
        ),
        run: () => onVariantSettings?.(reference),
      }),
    };
    return wearRowMenu({
      stale: isStale,
      worn,
      ref: wornRef,
      codeView,
      outlined: outline?.isOn(reference) ?? false,
      tints: tints === 'on',
      canUpdate: Boolean(config?.canUpdate),
      // Updatable = a wired doorway, an editable variant (not locked: the
      // record's updatable flag, the distro lock as the provider stamps
      // it), and an actual diff to stage: presence means the gesture
      // will do something.
      updatable:
        Boolean(onUpdateFromElement) &&
        worn !== null &&
        !locked(worn) &&
        updateFromElementLines(
          styleMap ?? {},
          selected,
          reference,
          library,
          layout,
        ).length > 0,
      canEditFull: Boolean(onOpenDocument),
      hasSettings: Boolean(onVariantSettings),
    }).map((item) => (item.separator ? item : views[item.type](item)));
  };

  // A stale row's header is its reason when the guide withholds the
  // variant (the refused values, the fix's provenance); a gone reference
  // has no record to speak for and no header.
  const staleHeader = (reference) => {
    const reason = staleReason(guide, reference);
    return reason.withheld
      ? Drupal.t('Withheld by the style guide: @refusals', {
          '@refusals': refusalText(reason.refusals),
        })
      : null;
  };
  const openMenu = (event, reference, isStale = false) => {
    event.preventDefault();
    menu.open({
      x: event.clientX,
      y: event.clientY,
      header: isStale
        ? staleHeader(reference)
        : wearRowHeader(findVariant(library, reference), guide, materials),
      items: menuItems(reference, isStale),
    });
  };

  // The hover flash carries only the reference (null = the element
  // layer); the Inspector composes the full paint layer — forms, label,
  // dedupe against the toggled outline — in one place. Beside the flash,
  // a row claims the canvas highlight for the properties its wearing
  // writes on the element (the same property-keyed claim stack every
  // panel row shares), so hovering Width paints the inset bands exactly
  // as scrubbing padding does; an apply holds that claim for a beat so
  // the swap is seen landing before the pointer moves.
  const claims = source?.setCanvasHighlight ? highlightClaimsFor(source) : null;
  const rowOwners = useRef(new Map());
  const holdTimer = useRef(null);
  const ownerFor = (axis) => {
    if (!rowOwners.current.has(axis)) {
      rowOwners.current.set(axis, { axis });
    }
    return rowOwners.current.get(axis);
  };
  const claimRow = (axis, ref) => {
    const properties = wornProperties(library, ref);
    if (claims && properties.length) {
      claims.claim(ownerFor(axis), { key: selected, sub: null, properties });
    }
  };
  const releaseRow = (axis) => claims?.release(ownerFor(axis));
  const holdRow = (axis, ref) => {
    if (!claims) {
      return;
    }
    clearTimeout(holdTimer.current);
    claimRow(axis, ref);
    holdTimer.current = setTimeout(() => releaseRow(axis), APPLY_HOLD_MS);
  };
  useEffect(
    () => () => {
      clearTimeout(holdTimer.current);
      for (const owner of rowOwners.current.values()) {
        claims?.release(owner);
      }
    },
    [],
  );
  const rowHoverProps = (axis, ref) => ({
    onMouseEnter: () => {
      onFlash?.({ variant: ref.variant });
      claimRow(axis, ref);
    },
    onMouseLeave: () => {
      onFlash?.(null);
      releaseRow(axis);
    },
  });

  // The picker's popover posture: one hook for every trigger. A trigger
  // points triggerRef at itself as it opens (so an outside press, Escape
  // and the pick's focus return all address the opener), and a second
  // press on the open trigger closes. A selection move closes it: the
  // offer it showed belonged to another element.
  const picker = usePopover();
  const [pickerAxis, setPickerAxis] = useState(null);
  useEffect(() => {
    picker.setOpen(false);
  }, [selected]);
  const showPicker = (trigger, axis) => {
    picker.triggerRef.current = trigger;
    setPickerAxis(axis);
    picker.setOpen(true);
  };
  const openPicker = (trigger, axis) => {
    // An apply in flight holds the offer closed (its result is what the
    // next open should show); the triggers stay enabled rather than
    // disabled so the pick's focus return is not blurred away.
    if (busy) {
      return;
    }
    if (picker.open && picker.triggerRef.current === trigger) {
      picker.setOpen(false);
      return;
    }
    showPicker(trigger, axis);
  };
  const expanded = (axis) => picker.open && pickerAxis === axis;
  // The apply-variant hotkey (shift+v, registered at leader boot in
  // index.js) reaches the picker through the opener seam
  // (apply-variant-hotkey.js): this bar installs its opener on mount and
  // clears it on unmount, so the key does nothing while no bar is
  // mounted (the event propagates). The opener behaves as a press on the
  // add row: same trigger for dismissal and focus return, every axis,
  // and the same refusals (an apply in flight, an empty offer, when the
  // add row is disabled). It reads the latest render through a ref, as
  // the seam holds one function for the bar's lifetime.
  const addRow = useRef(null);
  const hotkeyOpen = useRef(null);
  hotkeyOpen.current = () => {
    const trigger = addRow.current;
    if (busy || !trigger || trigger.disabled) {
      return false;
    }
    showPicker(trigger, null);
    return true;
  };
  useEffect(() => {
    setOpener(() => hotkeyOpen.current());
    return () => setOpener(null);
  }, []);
  // A pick: close, return focus, THEN act (the PaletteGrid order), and
  // hold the landing row's highlight so the swap is seen landing.
  const pick = (machineName) => {
    picker.setOpen(false);
    picker.triggerRef.current?.focus();
    apply(machineName);
    holdRow(findVariant(library, machineName)?.group ?? 'style', {
      variant: machineName,
      fragment: '',
    });
  };
  // The row's name: its axis, or its owner for a stencil-owned wearing
  // (wearRowLabel; the swap trigger's title follows it).
  const rowLabel = (axis, ref) =>
    wearRowLabel(axis, findVariant(library, ref.variant), {
      guide,
      vocabulary,
      materials,
      singleAxisLabel: Drupal.t('Variant'),
    });

  return (
    <div className="daedalus-stylish-wear-bar">
      {rows.map(({ axis, ref }) => {
        const applied = ref.variant;
        // The posed sub-line: what the worn variant contributes at the
        // canvas's compound (its own blocks the pose activates), or that
        // it sets nothing there. The line is the same doorway as the row
        // body.
        const atPose = posed
          ? wearAtPose(ref, library, condition, guide?.conditions)
          : null;
        // The overridden state: the element's own values hiding what this
        // wearing sets (grain-aware), named in a sub-line with Reset
        // overrides as the row's action.
        const overridden = overriddenWearing(
          styleMap ?? {},
          selected,
          applied,
          library,
        );
        // The origin: the composition whose apply distributed this
        // wearing here (`via`), named in a sub-line; a switch of that
        // composition reaps the wearing, so the row says who owns it.
        const origin = wearOrigin(ref, library);
        return (
          <div
            key={axis}
            className={`daedalus-stylish-wear-bar__row${atPose ? ' daedalus-stylish-wear-bar__row--posed' : ''}${overridden ? ' daedalus-stylish-wear-bar__row--overridden' : ''}${origin ? ' daedalus-stylish-wear-bar__row--via' : ''}`}
            {...rowHoverProps(axis, ref)}
            onContextMenu={(event) => openMenu(event, applied)}
          >
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-wear-bar__target"
              disabled={!onOpenDocument}
              title={
                onOpenDocument
                  ? posed
                    ? Drupal.t(
                        'Edit the variant "@label" at @pose: its own document, pending until pushed. Right-click for actions.',
                        { '@label': labelOf(applied), '@pose': poseLabel },
                      )
                    : Drupal.t(
                        'Edit the variant "@label" — its own document, pending until pushed. Right-click for actions.',
                        { '@label': labelOf(applied) },
                      )
                  : undefined
              }
              onClick={onOpenDocument ? () => open(applied) : undefined}
            >
              <span
                className="daedalus-stylish-wear-bar__dot daedalus-ui-dot"
                style={{ background: layerColor(variantLayerId(applied)) }}
              />
              <span className="daedalus-stylish-wear-bar__label">
                {rowLabel(axis, ref)}
              </span>
            </button>
            <button
              type="button"
              className="daedalus-stylish-wear-bar__variant"
              aria-haspopup="listbox"
              aria-expanded={expanded(axis)}
              title={
                ref.fragment !== ''
                  ? Drupal.t(
                      'Styled as part of the composition variant "@label". Applying a variant here replaces that on this element only.',
                      { '@label': labelOf(applied) },
                    )
                  : Drupal.t('Swap the @axis variant', {
                      '@axis': rowLabel(axis, ref),
                    })
              }
              onClick={(event) => openPicker(event.currentTarget, axis)}
            >
              <span className="daedalus-stylish-wear-bar__variant-label">
                {labelOf(applied)}
              </span>
              <span className="daedalus-stylish-wear-bar__chevron" aria-hidden="true">
                ▾
              </span>
            </button>
            {origin && (
              <span
                className="daedalus-stylish-wear-bar__via"
                title={Drupal.t(
                  'Applied by the composition variant "@via": switching or removing that composition removes this wearing too. Apply a variant here yourself to take it over.',
                  { '@via': origin.label },
                )}
              >
                {Drupal.t('via @via', { '@via': origin.label })}
              </span>
            )}
            {atPose && (
              <button
                type="button"
                tabIndex={-1}
                className={`daedalus-stylish-wear-bar__pose${atPose.applies ? '' : ' daedalus-stylish-wear-bar__pose--nothing'}`}
                disabled={!onOpenDocument}
                title={
                  onOpenDocument
                    ? Drupal.t(
                        'Edit what "@label" sets at @pose. Its wearing stays as it is; the variant owns its own conditions.',
                        { '@label': labelOf(applied), '@pose': poseLabel },
                      )
                    : undefined
                }
                onClick={onOpenDocument ? () => open(applied) : undefined}
              >
                {atPose.applies ? (
                  <Fragment>
                    <span className="daedalus-stylish-wear-bar__pose-text">
                      {Drupal.t('@label at @pose:', {
                        '@label': labelOf(applied),
                        '@pose': poseLabel,
                      })}
                    </span>
                    <code className="daedalus-stylish-wear-bar__pose-declarations">
                      {Object.entries(atPose.declarations)
                        .map(([property, value]) => `${property}: ${value}`)
                        .join('; ')}
                    </code>
                  </Fragment>
                ) : (
                  <span className="daedalus-stylish-wear-bar__pose-text">
                    {Drupal.t('@label sets nothing at @pose', {
                      '@label': labelOf(applied),
                      '@pose': poseLabel,
                    })}
                  </span>
                )}
              </button>
            )}
            {overridden && (
              <div className="daedalus-stylish-wear-bar__overridden">
                <span
                  className="daedalus-stylish-wear-bar__overridden-text"
                  title={Drupal.t(
                    'This element\'s own @own hides what "@label" sets for @lost; the variant is still worn, its values do not show through.',
                    {
                      '@own': overridden.by.join(', '),
                      '@label': labelOf(applied),
                      '@lost': overridden.lost.join(', '),
                    },
                  )}
                >
                  {Drupal.t('@lost overridden by your @own', {
                    '@lost': overridden.lost.join(', '),
                    '@own': overridden.by.join(', '),
                  })}
                </span>
                <button
                  type="button"
                  className="daedalus-stylish-wear-bar__reset"
                  disabled={busy}
                  title={Drupal.t(
                    "Drop this element's own values where they shadow the variant, so its styles show through again.",
                  )}
                  onClick={() => reset(applied, overridden.ops)}
                >
                  {Drupal.t('Reset overrides')}
                </button>
              </div>
            )}
          </div>
        );
      })}
      {/* References the library cannot resolve: no axis, no swap, just
          the raw reference and, for a variant the style guide withholds,
          the reason (the refused values in the title and the menu
          header). Right-click for the resolution-free gestures (Outline,
          Detach, Remove); a stale layer opens no session. */}
      {stale.map((ref) => {
        const reason = staleReason(guide, ref.variant);
        // A stale wearing keeps its origin fact: the composition that
        // put it here still reaps it on a switch.
        const origin = wearOrigin(ref, library);
        return (
          <div
            key={ref.variant}
            className={`daedalus-stylish-wear-bar__row daedalus-stylish-wear-bar__row--stale${reason.withheld ? ' daedalus-stylish-wear-bar__row--withheld' : ''}${origin ? ' daedalus-stylish-wear-bar__row--via' : ''}`}
            {...rowHoverProps(ref.variant, ref)}
            onContextMenu={(event) => openMenu(event, ref.variant, true)}
          >
            <span className="daedalus-stylish-wear-bar__target" aria-disabled="true">
              <span
                className="daedalus-stylish-wear-bar__dot daedalus-ui-dot"
                style={{ background: layerColor(variantLayerId(ref.variant)) }}
              />
              <span className="daedalus-stylish-wear-bar__label">
                {Drupal.t('Variant')}
              </span>
            </span>
            <code
              className="daedalus-stylish-wear-bar__stale-ref"
              title={
                reason.withheld
                  ? Drupal.t(
                      "This variant is withheld by the style guide: its values fail the site's current enforcement, so it is neither offered nor delivered until they are fixed. @refusals. Right-click for actions.",
                      { '@refusals': refusalText(reason.refusals) },
                    )
                  : Drupal.t(
                      "This variant is not among the site's materials (deleted or unpublished). Right-click for actions.",
                    )
              }
            >
              {ref.variant}
            </code>
            {reason.withheld && (
              <span className="daedalus-stylish-wear-bar__stale-reason">
                {Drupal.t('withheld by the style guide')}
              </span>
            )}
            {origin && (
              <span
                className="daedalus-stylish-wear-bar__via"
                title={Drupal.t(
                  'Applied by the composition variant "@via": switching or removing that composition removes this wearing too. Apply a variant here yourself to take it over.',
                  { '@via': origin.label },
                )}
              >
                {Drupal.t('via @via', { '@via': origin.label })}
              </span>
            )}
          </div>
        );
      })}
      {/* The add row: the one apply gesture, over every axis. Disabled,
          not hidden, when nothing applies, so the gesture stays where the
          eye expects it. */}
      <button
        type="button"
        ref={addRow}
        className="daedalus-stylish-wear-bar__add"
        aria-haspopup="listbox"
        aria-expanded={expanded(null)}
        disabled={ranked.length === 0}
        title={
          ranked.length === 0
            ? Drupal.t('No variants apply to this element')
            : undefined
        }
        onClick={(event) => openPicker(event.currentTarget, null)}
      >
        <span className="daedalus-stylish-wear-bar__add-plus" aria-hidden="true">
          +
        </span>
        <span>{Drupal.t('Apply variant')}</span>
      </button>
      {picker.open &&
        picker.portal(
          <VariantPicker
            ranked={ranked}
            vocabulary={vocabulary}
            refs={refs}
            guide={guide}
            materials={materials}
            axis={pickerAxis}
            onPick={pick}
          />,
        )}
    </div>
  );
};
