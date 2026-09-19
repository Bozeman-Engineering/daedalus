import { h, Fragment } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
  copyVariant,
  deleteVariant,
  fetchRevisions,
  fetchUsage,
  releaseVariant,
  restoreRevision,
  transferVariant,
  updateVariantDetails,
} from './variants-api.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { respondedMaterial } from './material-gestures.js';
import { confirmRevertToDefaults } from './variants.js';
import { layerColor, variantLayerId } from './code/layers.js';
import {
  applyDoorway,
  cardGestures,
  libraryFilters,
  libraryRows,
  pageWearer,
  stencilTargets,
  variantOwnership,
} from './library-model.js';
import { useMenu } from './menu.js';
import { axisLabel, findVariant } from './variant-scope.js';
import { AiOfferToggle, isOffered } from './ai-offer.js';
import { RevisionList, revisionDate } from './revision-list.js';

/**
 * The lifecycle card, inline under its open list row.
 *
 * The variant OBJECT surface (never a style editor — "variants are layers
 * of one stylesheet"): the details (label and description), the revision
 * history with restore, usage
 * with the Outline toggle, duplicate, and delete via the shared dialog.
 * "Edit styles" is the one doorway back to the stylesheet: it enters
 * the editing session on the variant, anchored on a wearer of it on
 * THIS page (the wearer-derived vocabulary rule — the document renders
 * in wearer context; without one on this page the doorway degrades
 * honestly). The degraded state is the intended shape, not
 * a gap: there is no wearerless sheet editor. Editing an unworn variant
 * means wearing it first — which is exactly what "Apply to selection"
 * offers: the browse-first doorway, the same instant apply_variant the
 * styling place's variant picker speaks, aimed from the catalog at the
 * current selection (applyDoorway computes its honest states).
 * Locked variants (the distro lock, the record's `updatable` flag: a
 * shipped variant on a consuming site, where the importer owns their
 * label and styles) render the card read-only, with "Copy as my variant"
 * and, when the state is actionable, "Revert to defaults" in the editing
 * gestures' place; under the developer gate a shipped variant is
 * updatable and carries both rosters.
 *
 * The card's gesture roster is data (library-model.js cardGestures):
 * presence means permitted AND applicable. The record is the store's (the
 * prop follows every delta); the management facts beyond it (the live
 * wearer count, the shipped state against the current disk copy, the
 * shipping module) are one per-open read of the usage route, refreshed
 * after each gesture and again at every prompt. Every write's response
 * carries the materials delta, and source.applyResponse lands it on the
 * store in both windows.
 *
 * Stencil ownership renders honestly: an owned variant's card wears an
 * owned-by badge (orphaned owners flag loudly), the apply doorway's
 * unfit title names the owner when ownership is the reason, and the
 * ownership gestures (Release, Transfer/Assign) ride
 * daedalus_blueprint_stencil's routes behind the variant gates' `canOwn`
 * (the "save stencils" grant); the stencils themselves are the store's
 * records, the same in both windows.
 */
const VariantCard = ({
  variant,
  guide,
  materials,
  config,
  styleMap,
  selected,
  offer,
  provenance,
  outline,
  onApply,
  onEditStyles,
  onCard,
  source,
}) => {
  const [busy, setBusy] = useState(false);
  // The details drafts: null follows the record's field; a string is
  // typing. Each input commits on blur or Enter, one field per save.
  const [draft, setDraft] = useState(null);
  const [descriptionDraft, setDescriptionDraft] = useState(null);
  const [revisions, setRevisions] = useState(null);
  // The delete batch's progress line: {percentage, message} while driving.
  const [progress, setProgress] = useState(null);
  // The transfer target menu (the shared primitive, anchored under the
  // button).
  const menu = useMenu();
  // The management read: {usages, shipped_state, provider}, null in flight.
  const [management, setManagement] = useState(null);

  const reference = variant.id;
  const gestures = cardGestures(variant, config, management, materials);
  const ownership = variantOwnership(variant, materials);
  const offered = isOffered(variant);
  const worn = (management?.usages ?? 0) > 0;
  const wearer = pageWearer(styleMap, variant, selected);
  const doorway = applyDoorway(
    variant,
    styleMap,
    selected,
    offer,
    provenance,
    materials,
  );

  // The management facts, per open and after every gesture that changes
  // the record (the prop's identity follows the store's delta).
  useEffect(() => {
    let alive = true;
    fetchUsage(reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [reference, variant]);

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  // The revision history, fetched when the card can restore (an update
  // gesture, so the fetch gate mirrors the roster's).
  useEffect(() => {
    if (!gestures.revisions) {
      return undefined;
    }
    let alive = true;
    fetchRevisions(reference).then(
      (body) => alive && setRevisions(body.revisions ?? []),
      () => alive && setRevisions([]),
    );
    return () => {
      alive = false;
    };
  }, [reference, variant]);

  // The details gesture, one field at a time: a label commits when it
  // differs and is not empty (an empty label is the server's own
  // corrective; the input just snaps back), a description commits when it
  // differs (empty clears it).
  const commitDetails = (details, status) => {
    run(() =>
      updateVariantDetails(reference, details).then((response) => {
        source.applyResponse(response);
        source.notify?.('status', status);
        return null;
      }),
    );
  };
  const rename = (value) => {
    const label = value.trim();
    setDraft(null);
    if (label === '' || label === variant.label) {
      return;
    }
    commitDetails(
      { label },
      Drupal.t('Renamed to "@label".', { '@label': label }),
    );
  };
  const describe = (value) => {
    const description = value.trim();
    setDescriptionDraft(null);
    if (description === (variant.description ?? '')) {
      return;
    }
    commitDetails(
      { description },
      description === ''
        ? Drupal.t('Description cleared.')
        : Drupal.t('Description updated.'),
    );
  };

  // Apply to selection: the browse-first doorway — an ordinary
  // apply_variant on the current selection (the inspector wires the op;
  // the same-axis reference swaps, everything else layers over). The map
  // echo re-renders the card, so the doorway flips to its worn state and
  // Edit styles gains its wearer.
  const applySelection = () => {
    run(() =>
      onApply(variant)
        .then(() => {
          source.notify?.(
            'status',
            Drupal.t('Applied "@label" to the selected element.', {
              '@label': variant.label,
            }),
          );
          return null;
        })
        .catch(() => {
          // The op queue's source reports the failure.
        }),
    );
  };

  const restore = (revision) => {
    run(() =>
      fetchUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Restore revision'),
          message: Drupal.t('Restore "@label" to its revision of @date?', {
            '@label': variant.label,
            '@date': revisionDate(revision.created),
          }),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new revision carrying that content; nothing wears the variant right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new revision carrying that content; this restyles 1 piece of content site-wide.',
                  'A new revision carrying that content; this restyles @count pieces of content site-wide.',
                ),
          confirmLabel: Drupal.t('Restore'),
        });
        if (!confirmed) {
          return null;
        }
        return restoreRevision(reference, revision.revision_id).then(
          (response) => {
            source.applyResponse(response);
            source.notify?.(
              'status',
              Drupal.t('Restored "@label" from its revision of @date.', {
                '@label':
                  respondedMaterial(response, 'variant', reference)?.label ??
                  variant.label,
                '@date': revisionDate(revision.created),
              }),
            );
            return null;
          },
        );
      }),
    );
  };

  // Duplicate / Copy as my variant: one gesture, two names — a fork into
  // a site-owned editable copy (the distro lock's escape hatch on shipped
  // variants, a plain duplicate elsewhere). The new card opens.
  const duplicate = async () => {
    const label = await Drupal.Daedalus.dialog.prompt({
      message: Drupal.t('Name for your copy of "@label":', {
        '@label': variant.label,
      }),
      defaultValue: Drupal.t('@label (copy)', { '@label': variant.label }),
      confirmLabel: Drupal.t('Copy'),
    });
    if (label === null || label.trim() === '') {
      return;
    }
    run(() =>
      copyVariant(reference, label.trim()).then((response) => {
        source.applyResponse(response);
        // The copy is the one variant the response's delta upserts.
        const copy = respondedMaterial(response, 'variant');
        onCard(copy?.id ?? null);
        source.notify?.(
          'status',
          Drupal.t('Copied to "@label".', {
            '@label': copy?.label ?? label.trim(),
          }),
        );
        return null;
      }),
    );
  };

  const revert = () => {
    run(() =>
      confirmRevertToDefaults(variant).then((result) => {
        if (result) {
          source.applyResponse(result);
          source.notify?.(
            'status',
            Drupal.t('Reverted "@label" to its built-in defaults.', {
              '@label':
                respondedMaterial(result, 'variant', reference)?.label ??
                variant.label,
            }),
          );
        }
        return null;
      }),
    );
  };

  // Release: owned back to general-purpose (orphans too; that is the
  // cleanup gesture). Offering changes site-wide, wearers never do, and
  // the copy says both.
  const release = () => {
    run(() =>
      (async () => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Release variant'),
          message: ownership.orphaned
            ? Drupal.t('Release "@label" from its former stencil?', {
                '@label': variant.label,
              })
            : Drupal.t('Release "@label" from the stencil "@stencil"?', {
                '@label': variant.label,
                '@stencil': ownership.label,
              }),
          detail: Drupal.t(
            'It becomes general-purpose: offered wherever its scope fits, on any placement. Nothing wearing it changes.',
          ),
          confirmLabel: Drupal.t('Release'),
        });
        if (!confirmed) {
          return null;
        }
        return releaseVariant(reference).then((response) => {
          source.applyResponse(response);
          source.notify?.(
            'status',
            Drupal.t('Released "@label"; it is general-purpose now.', {
              '@label': variant.label,
            }),
          );
          return null;
        });
      })(),
    );
  };

  // Transfer / Assign: ownership to a content stencil, one atomic route
  // gesture (adopt-later when the variant was unowned). The target comes
  // off the popup menu; picking it IS the confirmation.
  const transferTo = (target) => {
    run(() =>
      transferVariant(reference, target.id).then((response) => {
        source.applyResponse(response);
        source.notify?.(
          'status',
          ownership
            ? Drupal.t('Transferred "@label" to the stencil "@stencil".', {
                '@label': variant.label,
                '@stencil': target.label,
              })
            : Drupal.t(
                'Assigned "@label" to the stencil "@stencil"; it offers only on its placements now.',
                { '@label': variant.label, '@stencil': target.label },
              ),
        );
        return null;
      }),
    );
  };

  const openTransferMenu = (event) => {
    menu.open({
      anchor: event.currentTarget,
      items: stencilTargets(variant, materials).map((target) => ({
        label: target.label,
        run: () => transferTo(target),
      })),
    });
  };

  // Delete, always safely: the dialog is ONE action, for a worn
  // variant "Detach N pieces of content, then delete" (the batch: every
  // wearer keeps its look as its own styles, then the entity goes; the
  // client drives the returned core batch URL). There is no "delete
  // anyway". danger:true wears the accent and autofocuses Cancel.
  const destroy = () => {
    run(() =>
      fetchUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: Drupal.t('Delete variant'),
          message: Drupal.t('Delete "@label"?', { '@label': variant.label }),
          detail:
            usages === 0
              ? Drupal.t('Nothing wears it; it is deleted immediately.')
              : Drupal.formatPlural(
                  usages,
                  'Detach 1 piece of content, then delete: the wearer keeps its current look as its own styles, then the variant is deleted. Nothing on any page changes.',
                  'Detach @count pieces of content, then delete: every wearer keeps its current look as its own styles, then the variant is deleted. Nothing on any page changes.',
                ),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Detach and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteVariant(reference).then(async (result) => {
          if (!result.deleted && result.batch_url) {
            setProgress({ percentage: '0' });
            try {
              await driveBatch(result.batch_url, setProgress);
            } finally {
              setProgress(null);
            }
          }
          // The response's materials delta is the removal (stated up front
          // for the batched path, performed by the batch just driven); the
          // store forgets the variant in BOTH windows, and every wearer's
          // row reads its own detached styles.
          source.applyResponse(result);
          onCard(null);
          source.notify?.(
            'status',
            Drupal.t('Deleted "@label".', { '@label': variant.label }),
          );
          return null;
        });
      }),
    );
  };

  return (
    <div className="daedalus-stylish-library-card">
      <div className="daedalus-stylish-library-card__name">
        {gestures.details ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input"
            value={draft ?? variant.label}
            disabled={busy}
            title={Drupal.t(
              'Rename the variant. Its identity never changes, so everything wearing it follows.',
            )}
            onInput={(event) => setDraft(event.target.value)}
            onBlur={(event) => rename(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                rename(event.target.value);
              }
            }}
          />
        ) : (
          <span className="daedalus-stylish-library-card__label">{variant.label}</span>
        )}
        {variant.scope && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__scope"
            title={Drupal.t('The scope the variant offers on.')}
          >
            {variant.scope}
          </span>
        )}
        <span
          className="daedalus-ui-chip daedalus-stylish-library-card__axis"
          title={Drupal.t(
            "The axis the variant competes on: applying it replaces the element's variant on this axis and keeps the others.",
          )}
        >
          {axisLabel(guide, variant.group ?? 'style')}
        </span>
        {typeof variant.weight === 'number' && variant.weight !== 0 && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__weight"
            title={Drupal.t(
              "The variant's weight: lists and pickers order by weight, then label.",
            )}
          >
            {Drupal.t('weight @weight', { '@weight': variant.weight })}
          </span>
        )}
        {ownership && (
          <span
            className={`daedalus-ui-chip daedalus-stylish-library-card__owner${ownership.orphaned ? ' daedalus-stylish-library-card__owner--orphaned' : ''}`}
            title={
              ownership.orphaned
                ? Drupal.t(
                    'Belonged to a stencil that no longer exists: offered nowhere, though everything wearing it keeps its look. Release it to make it general-purpose.',
                  )
                : Drupal.t(
                    'One of the stencil "@stencil"\'s switchable looks: offered only inside its placements.',
                    { '@stencil': ownership.label },
                  )
            }
          >
            {ownership.orphaned
              ? Drupal.t('owner gone')
              : Drupal.t('owned by @stencil', { '@stencil': ownership.label })}
          </span>
        )}
      </div>
      {/* The AI opt-out beside the description it silences: the toggle
          when the card can update (the details route, one revisioned
          save), a chip when read-only and withheld; the description shows
          only while it has that audience. */}
      {gestures.details ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={offered}
          disabled={busy}
          onChange={(next) =>
            commitDetails(
              { offered: next },
              next
                ? Drupal.t('Offered "@label" to the AI.', {
                    '@label': variant.label,
                  })
                : Drupal.t('Kept "@label" from the AI.', {
                    '@label': variant.label,
                  }),
            )
          }
        />
      ) : (
        !offered && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Kept from the AI: it leaves the brief and the schema but still applies wherever its scope fits.',
            )}
          >
            {Drupal.t('not offered to the AI')}
          </span>
        )
      )}
      {offered &&
        (gestures.details ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__description daedalus-ui-input"
            value={descriptionDraft ?? variant.description ?? ''}
            disabled={busy}
            placeholder={Drupal.t('When to reach for this look…')}
            title={Drupal.t(
              'When to reach for this variant: the same description the AI reads.',
            )}
            onInput={(event) => setDescriptionDraft(event.target.value)}
            onBlur={(event) => describe(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                describe(event.target.value);
              }
            }}
          />
        ) : (
          variant.description && (
            <p
              className="daedalus-stylish-library-card__description"
              title={Drupal.t(
                'When to reach for this variant: the same description the AI reads.',
              )}
            >
              {variant.description}
            </p>
          )
        ))}
      <div className="daedalus-stylish-library-card__meta">
        <span>
          {management === null
            ? Drupal.t('usage loading…')
            : management.usages === 0
              ? Drupal.t('worn by nothing')
              : Drupal.formatPlural(
                  management.usages,
                  'worn on 1 piece of content',
                  'worn on @count pieces of content',
                )}
        </span>
        {worn && outline && (
          <button
            type="button"
            className={`daedalus-ui-chip daedalus-stylish-library-card__outline${outline.isOn(reference) ? ' daedalus-stylish-library-card__outline--on' : ''}`}
            aria-pressed={outline.isOn(reference)}
            title={Drupal.t(
              'Outline every element wearing this variant on the canvas and in the page elements panel until toggled off.',
            )}
            onClick={() => outline.toggle(reference)}
          >
            {outline.isOn(reference)
              ? Drupal.t('✓ Outline Variant')
              : Drupal.t('Outline Variant')}
          </button>
        )}
      </div>
      {gestures.revisions && (
        <RevisionList revisions={revisions} busy={busy} onRestore={restore} />
      )}
      {progress && (
        <div className="daedalus-stylish-library-card__progress">
          {Drupal.t('Detaching… @percent%', {
            '@percent': progress.percentage ?? '0',
          })}
        </div>
      )}
      <div className="daedalus-stylish-library-card__actions">
        {onApply && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--apply"
            disabled={busy || doorway !== 'ready'}
            title={
              {
                ready: Drupal.t(
                  'Apply this variant to the selected element. It replaces the same-axis variant; overrides and other axes stay.',
                ),
                'no-selection': Drupal.t(
                  'Select an element on the canvas to apply it to.',
                ),
                worn: Drupal.t(
                  "The selected element already wears it. Re-apply lives on its wearing row's menu.",
                ),
                unfit: Drupal.t(
                  'The variant does not offer on the selected element.',
                ),
                'unfit-owned': Drupal.t(
                  'Belongs to the stencil "@stencil": it only offers inside that stencil\'s placements.',
                  { '@stencil': ownership?.label ?? '' },
                ),
                'unfit-orphaned': Drupal.t(
                  'Its owning stencil no longer exists, so it offers nowhere. Release it to make it general-purpose.',
                ),
              }[doorway]
            }
            onClick={applySelection}
          >
            {Drupal.t('Apply to selection')}
          </button>
        )}
        {gestures.editStyles && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--edit"
            disabled={busy || !wearer}
            title={
              wearer
                ? Drupal.t(
                    "Edit this variant's styles in context of a wearer on this page: a session pending until pushed.",
                  )
                : worn
                  ? Drupal.t(
                      'Nothing on this page wears it. Outline Variant finds wearers; open one of their pages to edit the styles in context.',
                    )
                  : Drupal.t(
                      'Nothing wears it yet, so there is no wearer to edit it in context of. Apply it somewhere first.',
                    )
            }
            onClick={() => onEditStyles(variant)}
          >
            {Drupal.t('Edit styles')}
          </button>
        )}
        {(gestures.duplicate || gestures.copy) && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
            disabled={busy}
            title={
              gestures.copy
                ? Drupal.t(
                    'Built-in variants are read-only; create your own editable copy.',
                  )
                : Drupal.t('Create an editable copy of this variant.')
            }
            onClick={duplicate}
          >
            {gestures.copy
              ? Drupal.t('Copy as my variant')
              : Drupal.t('Duplicate')}
          </button>
        )}
        {gestures.revert && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
            disabled={busy}
            onClick={revert}
          >
            {Drupal.t('Revert to defaults')}
          </button>
        )}
        {gestures.release && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
            disabled={busy}
            title={Drupal.t(
              'Make it general-purpose again: offered wherever its scope fits, on any placement.',
            )}
            onClick={release}
          >
            {Drupal.t('Release…')}
          </button>
        )}
        {gestures.transfer && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
            disabled={busy}
            title={
              ownership
                ? Drupal.t("Move it into another stencil's switchable looks.")
                : Drupal.t(
                    "Make it one of a stencil's switchable looks, offered only on that stencil's placements.",
                  )
            }
            onClick={openTransferMenu}
          >
            {ownership
              ? Drupal.t('Transfer to stencil…')
              : Drupal.t('Assign to stencil…')}
          </button>
        )}
        {gestures.delete && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--delete"
            disabled={busy}
            onClick={destroy}
          >
            {Drupal.t('Delete…')}
          </button>
        )}
      </div>
    </div>
  );
};

/**
 * The Materials variants face: search, filters, the flat list, cards
 * inline.
 *
 * Site-wide content, so it needs no selection; the list is the store's
 * variant records (the roster this window was handed; a mint, edit or
 * delete in either window re-renders it through the store's delta):
 * nothing fetches a list, and the facts a card needs beyond its record
 * (usage, the shipped state) are its own per-open read. Rows carry the
 * honesty chrome: the identity dot (the same stable hue the wear bar and
 * effective view speak), the axis chip, the shipped marker, and the
 * description's first line as the row's tooltip.
 */
export const LibraryView = ({
  guide,
  materials,
  config,
  styleMap,
  selected,
  offer,
  provenance,
  card,
  onCard,
  outline,
  onApply,
  onEditStyles,
  source,
}) => {
  const [search, setSearch] = useState('');
  const [axis, setAxis] = useState(null);
  const [scope, setScope] = useState(null);

  const library = materials.ofKind('variant');
  const filters = libraryFilters(library);
  const rows = libraryRows(library, { search, axis, scope });

  return (
    <div className="daedalus-stylish-library">
      <div className="daedalus-stylish-library__controls">
        <input
          type="search"
          className="daedalus-stylish-library__search daedalus-ui-input"
          placeholder={Drupal.t('Search variants…')}
          value={search}
          onInput={(event) => setSearch(event.target.value)}
        />
        {filters.axes.length > 1 && (
          <select
            className="daedalus-stylish-library__filter daedalus-ui-input daedalus-ui-input--select"
            value={axis ?? ''}
            onChange={(event) =>
              setAxis(event.target.value === '' ? null : event.target.value)
            }
          >
            <option value="">{Drupal.t('All axes')}</option>
            {filters.axes.map((name) => (
              <option key={name} value={name}>
                {axisLabel(guide, name)}
              </option>
            ))}
          </select>
        )}
        {filters.scopes.length > 1 && (
          <select
            className="daedalus-stylish-library__filter daedalus-ui-input daedalus-ui-input--select"
            value={scope ?? ''}
            onChange={(event) =>
              setScope(event.target.value === '' ? null : event.target.value)
            }
          >
            <option value="">{Drupal.t('All scopes')}</option>
            {filters.scopes.map((name) => (
              <option key={name} value={name}>
                {name}
              </option>
            ))}
          </select>
        )}
      </div>
      {rows.length === 0 ? (
        <div className="daedalus-stylish-inspector__empty">
          {library.length === 0
            ? Drupal.t(
                "No variants exist yet. Style an element and save its look as a variant; it joins the site's materials.",
              )
            : Drupal.t('No variants match.')}
        </div>
      ) : (
        <div className="daedalus-stylish-library__list">
          {rows.map((row) => (
            <Fragment key={row.reference}>
              <button
                type="button"
                className={`daedalus-stylish-library__row${card === row.reference ? ' daedalus-stylish-library__row--open' : ''}`}
                aria-expanded={card === row.reference}
                title={(row.offered && row.description) || undefined}
                onClick={() =>
                  onCard(card === row.reference ? null : row.reference)
                }
              >
                <span
                  className="daedalus-stylish-library__dot daedalus-ui-dot"
                  style={{
                    background: layerColor(variantLayerId(row.reference)),
                  }}
                />
                <span className="daedalus-stylish-library__name">{row.label}</span>
                <span className="daedalus-ui-chip daedalus-stylish-library__axis">
                  {axisLabel(guide, row.axis)}
                </span>
                {row.shipped && (
                  <span
                    className="daedalus-ui-chip daedalus-stylish-library__shipped"
                    title={Drupal.t(
                      'Built-in: provided by a module, read-only. Copy it as your variant to change it.',
                    )}
                  >
                    {Drupal.t('Built-in')}
                  </span>
                )}
              </button>
              {card === row.reference && (
                <VariantCard
                  variant={findVariant(library, row.reference)}
                  guide={guide}
                  materials={materials}
                  config={config}
                  styleMap={styleMap}
                  selected={selected}
                  offer={offer}
                  provenance={provenance}
                  outline={outline}
                  onApply={onApply}
                  onEditStyles={onEditStyles}
                  onCard={onCard}
                  source={source}
                />
              )}
            </Fragment>
          ))}
        </div>
      )}
    </div>
  );
};
