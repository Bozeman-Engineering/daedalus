import { h, Fragment } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
  deleteSwatch,
  fetchSwatchRevisions,
  fetchSwatchUsage,
  renameSwatch,
  restoreSwatchRevision,
  revertSwatch,
} from './swatches-api.js';
import {
  respondedMaterial,
  saveMaterial,
  updateMaterial,
} from './material-gestures.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { schemeCondition } from './style-guide.js';
import { facePair, styleReference } from './materials.js';
import { Face } from './face.js';
import {
  editablePair,
  mergedPair,
  paletteFilters,
  paletteRows,
  skinGroups,
  swatchCardGestures,
} from './palette-model.js';
import { swatchKindAnatomy } from './swatch-kinds.js';
import { AiOfferToggle, isOffered } from './ai-offer.js';
import { RevisionList, revisionDate } from './revision-list.js';

/**
 * A swatch kind's display label from the guide payload.
 */
const kindLabel = (guide, kind) => guide?.swatchKinds?.[kind]?.label ?? kind;

/**
 * The per-scheme pair control around one kind's value editor.
 *
 * Mint and edit author a {default, scheme} pair through the SAME kind
 * editor mounted per face: the default editor always, and — when the
 * guide declares a scheme axis (schemeCondition; the arc-1 split-face
 * rule, single scheme-axis condition names only) — either the "add"
 * gesture or the scheme face's own editor with its remove chip. A fresh
 * scheme draft seeds from the default (the author darkens what exists,
 * not a neutral). Face labels appear only while the pair is split, so a
 * single-valued value keeps the editor anatomy it always had. Pure
 * drafts-in/drafts-out like the kind editors themselves: the caller owns
 * commit (mergedPair) and the save gesture.
 */
const SchemePairEditor = ({
  guide,
  materials,
  Editor,
  draft,
  darkDraft,
  scheme,
  exclude = null,
  onDraft,
  onDark,
}) => {
  const schemeLabel = guide?.conditions?.[scheme]?.label ?? scheme;
  return (
    <div className="daedalus-stylish-swatch-pair">
      {darkDraft !== null && (
        <div className="daedalus-stylish-swatch-pair__head">
          <span className="daedalus-stylish-swatch-pair__scheme">
            {scheme === 'dark'
              ? Drupal.t('Default (light)')
              : Drupal.t('Default')}
          </span>
        </div>
      )}
      <Editor
        value={draft}
        guide={guide}
        materials={materials}
        exclude={exclude}
        onPreview={onDraft}
        onCommit={onDraft}
      />
      {scheme !== null &&
        (darkDraft === null ? (
          <button
            type="button"
            className="daedalus-ui-chip daedalus-stylish-swatch-pair__add"
            title={Drupal.t(
              'Author a separate value for the @scheme scheme; the face shows both halves.',
              { '@scheme': schemeLabel },
            )}
            onClick={() => onDark(draft)}
          >
            {Drupal.t('+ @scheme value', { '@scheme': schemeLabel })}
          </button>
        ) : (
          <Fragment>
            <div className="daedalus-stylish-swatch-pair__head">
              <span className="daedalus-stylish-swatch-pair__scheme">{schemeLabel}</span>
              <button
                type="button"
                className="daedalus-ui-chip daedalus-stylish-swatch-pair__remove"
                title={Drupal.t(
                  'Drop the @scheme entry; the default value serves both schemes.',
                  { '@scheme': schemeLabel },
                )}
                onClick={() => onDark(null)}
              >
                {Drupal.t('remove')}
              </button>
            </div>
            <Editor
              value={darkDraft}
              guide={guide}
              materials={materials}
              exclude={exclude}
              onPreview={onDark}
              onCommit={onDark}
            />
          </Fragment>
        ))}
    </div>
  );
};

/**
 * The lifecycle card, inline under its open list row.
 *
 * The swatch OBJECT surface and its value editor in one card (unlike
 * variants, a swatch IS one value, so "edit the value" needs no session
 * machinery: commit restyles every use instantly — the live-link
 * decision): rename, the kind's value editor, the revision history with
 * restore, and delete via the shared dialog. Locked swatches (the distro
 * lock, the record's `updatable` flag: a shipped swatch on a consuming
 * site, where the importer owns their label and value) render read-only;
 * shipped ones offer "Revert to defaults" when the state is actionable.
 * The card's
 * gesture roster is data (palette-model.js swatchCardGestures): presence
 * means permitted AND applicable. The record is the store's (the prop
 * follows every delta); the management facts beyond it (the live
 * reference count, the shipped state against the current disk copy) are
 * one per-open read of the usage route, refreshed after each gesture and
 * again at every prompt. The value editor's commit is the update_material
 * op (kind swatch) through the source's batch commit, the op the AI
 * submits; the lifecycle gestures stay routes whose response carries the
 * materials delta, handed to source.applyResponse so the store learns it
 * in both windows. Either way the written record is read from the delta.
 */
const SwatchCard = ({ swatch, guide, materials, config, source, onCard }) => {
  const [busy, setBusy] = useState(false);
  // The rename draft: null follows the palette label; a string is typing.
  const [draft, setDraft] = useState(null);
  const [revisions, setRevisions] = useState(null);
  // The value editor: null closed; a string is the default working draft.
  const [valueDraft, setValueDraft] = useState(null);
  // The scheme face's working draft: null means no scheme entry (the pair
  // control's "add" gesture creates one; its remove chip returns here).
  const [darkDraft, setDarkDraft] = useState(null);
  // The delete batch's progress line: {percentage, message} while driving.
  const [progress, setProgress] = useState(null);
  // The management read: {usages, shipped_state, provider}, null in flight.
  const [management, setManagement] = useState(null);

  const reference = swatch.id;
  const gestures = swatchCardGestures(swatch, config, management);
  const anatomy = swatchKindAnatomy(swatch.group);
  const {
    draft: storedDraft,
    darkDraft: storedDark,
    scheme,
    conditions,
  } = editablePair(guide, swatch.value);

  // The management facts, per open and after every gesture that changes
  // the record (the prop's identity follows the store's delta).
  useEffect(() => {
    let alive = true;
    fetchSwatchUsage(reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [reference, swatch]);

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
    fetchSwatchRevisions(reference).then(
      (body) => alive && setRevisions(body.revisions ?? []),
      () => alive && setRevisions([]),
    );
    return () => {
      alive = false;
    };
  }, [reference, swatch]);

  const rename = (value) => {
    const label = value.trim();
    setDraft(null);
    if (label === '' || label === swatch.label) {
      return;
    }
    run(() =>
      renameSwatch(reference, label).then((response) => {
        source.applyResponse(response);
        source.notify?.(
          'status',
          Drupal.t('Renamed to "@label".', {
            '@label':
              respondedMaterial(response, 'swatch', reference)?.label ?? label,
          }),
        );
        return null;
      }),
    );
  };

  // The value editor's commit: the variant push's twin at value scope,
  // one revisioned save (update_material), every referencing element
  // restyles instantly. The pair control authors the default and scheme
  // entries; every OTHER condition entry reassembles verbatim (mergedPair,
  // the honesty rule). The op queue lands the delta before the promise
  // resolves (the satellite through the leader's relay), so nothing is
  // applied here.
  const commitValue = () => {
    const committed = mergedPair(swatch.value, {
      draft: valueDraft,
      darkDraft,
      scheme,
    });
    run(() =>
      updateMaterial(
        source,
        { kind: 'swatch', id: reference, value: committed },
        Drupal.t('Updated the swatch @label', { '@label': swatch.label }),
      )
        .then((record) => {
          setValueDraft(null);
          source.notify?.(
            'status',
            Drupal.t('Updated "@label"; everything referencing it follows.', {
              '@label': record.label,
            }),
          );
          return null;
        })
        .catch(() => {
          // The op queue's source reported it (reportFailure on the leader, the
          // satellite's settle); the editor stays open for a correction.
        }),
    );
  };

  const restore = (revision) => {
    run(() =>
      fetchSwatchUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Restore revision'),
          message: Drupal.t('Restore "@label" to its revision of @date?', {
            '@label': swatch.label,
            '@date': revisionDate(revision.created),
          }),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new revision carrying that content; nothing references the swatch right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new revision carrying that content; this restyles 1 place site-wide.',
                  'A new revision carrying that content; this restyles @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Restore'),
        });
        if (!confirmed) {
          return null;
        }
        return restoreSwatchRevision(reference, revision.revision_id).then(
          (response) => {
            source.applyResponse(response);
            source.notify?.(
              'status',
              Drupal.t('Restored "@label" from its revision of @date.', {
                '@label':
                  respondedMaterial(response, 'swatch', reference)?.label ??
                  swatch.label,
                '@date': revisionDate(revision.created),
              }),
            );
            return null;
          },
        );
      }),
    );
  };

  const revert = () => {
    run(() =>
      fetchSwatchUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Revert to defaults'),
          message: Drupal.t(
            'Revert "@label" to the defaults its module ships?',
            { '@label': swatch.label },
          ),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new revision carrying the shipped value; nothing references the swatch right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new revision carrying the shipped value; this restyles 1 place site-wide.',
                  'A new revision carrying the shipped value; this restyles @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Revert'),
        });
        if (!confirmed) {
          return null;
        }
        return revertSwatch(reference).then((response) => {
          source.applyResponse(response);
          source.notify?.(
            'status',
            Drupal.t('Reverted "@label" to its shipped defaults.', {
              '@label':
                respondedMaterial(response, 'swatch', reference)?.label ??
                swatch.label,
            }),
          );
          return null;
        });
      }),
    );
  };

  // Delete, always safely: the dialog is ONE action — for a referenced
  // swatch "Rewrite N references, then delete" (the batch: every use
  // keeps its current look as the literal value, then the entity goes;
  // the client drives the returned core batch URL). There is no "delete
  // anyway". danger:true wears the accent and autofocuses Cancel.
  const destroy = () => {
    run(() =>
      fetchSwatchUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: Drupal.t('Delete swatch'),
          message: Drupal.t('Delete "@label"?', { '@label': swatch.label }),
          detail:
            usages === 0
              ? Drupal.t('Nothing references it; it is deleted immediately.')
              : Drupal.formatPlural(
                  usages,
                  'Rewrite 1 reference to its literal value, then delete: the place keeps its current look, then the swatch is deleted. Nothing on any page changes.',
                  'Rewrite @count references to their literal value, then delete: every place keeps its current look, then the swatch is deleted. Nothing on any page changes.',
                ),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Rewrite and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteSwatch(reference).then(async (result) => {
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
          // store forgets the swatch in BOTH windows. The canvas custom
          // property stays: the rewrite gave every use the same literal look.
          source.applyResponse(result);
          onCard(null);
          source.notify?.(
            'status',
            Drupal.t('Deleted "@label".', { '@label': swatch.label }),
          );
          return null;
        });
      }),
    );
  };

  const Editor = anatomy?.Editor ?? null;
  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-palette-card">
      <div className="daedalus-stylish-library-card__name">
        {gestures.rename ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input"
            value={draft ?? swatch.label}
            disabled={busy}
            title={Drupal.t(
              'Rename the swatch. Its identity never changes, so every reference follows.',
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
          <span className="daedalus-stylish-library-card__label">{swatch.label}</span>
        )}
        <code
          className="daedalus-stylish-palette-card__variable"
          title={Drupal.t('The reference the swatch is written as.')}
        >
          {styleReference(swatch)}
        </code>
      </div>
      {/* The AI opt-out (ai-offer.js): the toggle when the card can update
          (update_material, kind swatch), a chip when read-only and
          withheld. */}
      {gestures.rename ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={isOffered(swatch)}
          disabled={busy}
          onChange={(next) =>
            run(() =>
              updateMaterial(
                source,
                { kind: 'swatch', id: reference, offered: next },
                next
                  ? Drupal.t('Offered the swatch @label to the AI', {
                      '@label': swatch.label,
                    })
                  : Drupal.t('Kept the swatch @label from the AI', {
                      '@label': swatch.label,
                    }),
              ).then(() => null),
            )
          }
        />
      ) : (
        !isOffered(swatch) && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Kept from the AI: it leaves the brief and the schema but its reference still resolves.',
            )}
          >
            {Drupal.t('not offered to the AI')}
          </span>
        )
      )}
      <div className="daedalus-stylish-library-card__meta">
        <span>
          {management === null
            ? Drupal.t('counting uses…')
            : management.usages === 0
              ? Drupal.t('used by nothing')
              : Drupal.formatPlural(
                  management.usages,
                  'used in 1 place',
                  'used in @count places',
                )}
        </span>
      </div>
      {valueDraft !== null && Editor ? (
        <div className="daedalus-stylish-palette-card__editor">
          {conditions.length > 0 && (
            /* Condition honesty: the pair control authors the default and
               scheme entries; every OTHER condition entry (width, motion,
               compounds) commits back verbatim. */
            <div className="daedalus-stylish-palette-card__conditions">
              {Drupal.t('The @conditions entries are kept as they are.', {
                '@conditions': conditions.join(', '),
              })}
            </div>
          )}
          <SchemePairEditor
            guide={guide}
            materials={materials}
            Editor={Editor}
            draft={valueDraft}
            darkDraft={darkDraft}
            scheme={scheme}
            exclude={styleReference(swatch)}
            onDraft={setValueDraft}
            onDark={setDarkDraft}
          />
          <div className="daedalus-stylish-library-card__actions">
            <button
              type="button"
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
              disabled={
                busy ||
                valueDraft.trim() === '' ||
                (darkDraft !== null && darkDraft.trim() === '')
              }
              title={Drupal.t(
                'Save the value; every place referencing this swatch restyles instantly.',
              )}
              onClick={commitValue}
            >
              {Drupal.t('Save value')}
            </button>
            <button
              type="button"
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
              disabled={busy}
              onClick={() => setValueDraft(null)}
            >
              {Drupal.t('Cancel')}
            </button>
          </div>
        </div>
      ) : (
        <Fragment>
          {gestures.revisions && (
            <RevisionList
              revisions={revisions}
              busy={busy}
              onRestore={restore}
            />
          )}
          {progress && (
            <div className="daedalus-stylish-library-card__progress">
              {Drupal.t('Rewriting… @percent%', {
                '@percent': progress.percentage ?? '0',
              })}
            </div>
          )}
          <div className="daedalus-stylish-library-card__actions">
            {gestures.editValue && Editor && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
                disabled={busy}
                title={Drupal.t(
                  'Edit the value; saving restyles every place referencing this swatch instantly.',
                )}
                onClick={() => {
                  setValueDraft(storedDraft);
                  setDarkDraft(storedDark);
                }}
              >
                {Drupal.t('Edit value')}
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
        </Fragment>
      )}
    </div>
  );
};

/**
 * The mint card: the palette face's "New swatch" gesture.
 *
 * Label, kind, and the kind's own value editor over a seeded draft (the
 * kind registry owns both): the same save_material op (kind swatch) as
 * the gradient builder's save-as-swatch, minus a source value: here the
 * palette is where the value is authored. The pair control can mint a
 * {default, scheme} pair from the start (mergedPair shapes the payload;
 * plain strings stay plain). The op queue lands the response's materials
 * delta on the store before the promise resolves (the leader relays it to
 * the satellite), so the swatch is in every picker of both windows and
 * its custom property resolves on the canvas before any reload.
 */
const MintCard = ({ guide, materials, source, onClose }) => {
  const kinds = Object.keys(guide?.swatchKinds ?? {});
  const [kind, setKind] = useState(kinds[0] ?? 'color');
  const [label, setLabel] = useState('');
  const [value, setValue] = useState(
    () => swatchKindAnatomy(kinds[0] ?? 'color')?.seed(guide, materials) ?? '',
  );
  // The scheme face's draft: null mints a plain string; a string mints
  // the {default, scheme} pair (the split face picks it up for free).
  const [darkValue, setDarkValue] = useState(null);
  // The AI opt-out (ai-offer.js): born offered, the default; the mint
  // sends the key only when off.
  const [offered, setOffered] = useState(true);
  const [minting, setMinting] = useState(false);

  const scheme = schemeCondition(guide);
  const anatomy = swatchKindAnatomy(kind);
  const Editor = anatomy?.Editor ?? null;

  const mint = () => {
    setMinting(true);
    saveMaterial(
      source,
      {
        kind: 'swatch',
        label: label.trim(),
        group: kind,
        value: mergedPair(value, {
          draft: value,
          darkDraft: darkValue,
          scheme,
        }),
        offered: offered ? undefined : false,
      },
      Drupal.t('Added the swatch @label', { '@label': label.trim() }),
    )
      .then((record) => {
        // Kind-aware confirmation ("Added the Rain gradient to the color
        // palette"): the messenger toast names what was made, not the
        // internal "mint" verb.
        source.notify?.(
          'status',
          Drupal.t('Added the @label @kind to the color palette.', {
            '@label': record.label,
            '@kind': kindLabel(guide, record.group).toLowerCase(),
          }),
        );
        onClose();
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the editor stays open for a correction.
      })
      .finally(() => setMinting(false));
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-palette-mint">
      <div className="daedalus-stylish-palette-mint__identity">
        <input
          type="text"
          className="daedalus-stylish-library-card__label daedalus-ui-input"
          placeholder={Drupal.t('Swatch name…')}
          value={label}
          onInput={(event) => setLabel(event.target.value)}
        />
        {kinds.length > 1 && (
          <select
            className="daedalus-ui-input daedalus-ui-input--select"
            value={kind}
            title={Drupal.t('Swatch kind')}
            onChange={(event) => {
              // A kind flip re-seeds the draft: the old kind's value means
              // nothing to the new kind's editor.
              setKind(event.target.value);
              setValue(
                swatchKindAnatomy(event.target.value)?.seed(guide, materials) ??
                  '',
              );
              setDarkValue(null);
            }}
          >
            {kinds.map((id) => (
              <option key={id} value={id}>
                {kindLabel(guide, id)}
              </option>
            ))}
          </select>
        )}
      </div>
      <AiOfferToggle offered={offered} onChange={setOffered} />
      {Editor ? (
        <SchemePairEditor
          guide={guide}
          materials={materials}
          Editor={Editor}
          draft={value}
          darkDraft={darkValue}
          scheme={scheme}
          onDraft={setValue}
          onDark={setDarkValue}
        />
      ) : (
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t('This kind has no editor in this build.')}
        </div>
      )}
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
          disabled={
            minting ||
            label.trim() === '' ||
            value.trim() === '' ||
            (darkValue !== null && darkValue.trim() === '')
          }
          title={Drupal.t(
            "Mint the swatch: a named value that joins the site's materials; the whole site can reference it.",
          )}
          onClick={mint}
        >
          {Drupal.t('Save swatch')}
        </button>
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
          disabled={minting}
          onClick={onClose}
        >
          {Drupal.t('Cancel')}
        </button>
      </div>
    </div>
  );
};

/**
 * The Palette face: the catalog's value section.
 *
 * The variants face's sibling at value scope — site-wide content, no
 * selection
 * needed: search, the kind filter, the flat swatch list with cards inline,
 * and the read-only skin section beneath (the curated half of the palette:
 * token values live on the active skin, and the palette never grows a
 * second skin editor). Both halves are the materials store's records (the
 * roster this window was handed; a mint, edit or delete in either window
 * re-renders it through the store's delta): nothing fetches a list, and
 * the facts a card needs beyond its record are its own per-open read.
 */
export const PaletteView = ({ guide, materials, config, source }) => {
  const [search, setSearch] = useState('');
  const [kind, setKind] = useState(null);
  const [card, setCard] = useState(null);
  const [minting, setMinting] = useState(false);

  const swatches = materials.ofKind('swatch');
  const filters = paletteFilters(swatches);
  const rows = paletteRows(swatches, { search, kind });
  const skin = skinGroups(guide, materials);
  // Skin-row dark legibility: valueDark rides skinGroups (the arc-1
  // schemeDisplayValue read), and the label names the guide's scheme
  // axis the same way SchemePairEditor does.
  const scheme = schemeCondition(guide);
  const schemeLabel = guide?.conditions?.[scheme]?.label ?? scheme;

  return (
    <div className="daedalus-stylish-library daedalus-stylish-palette">
      <div className="daedalus-stylish-library__controls">
        <input
          type="search"
          className="daedalus-stylish-library__search daedalus-ui-input"
          placeholder={Drupal.t('Search swatches…')}
          value={search}
          onInput={(event) => setSearch(event.target.value)}
        />
        {filters.kinds.length > 1 && (
          <select
            className="daedalus-stylish-library__filter daedalus-ui-input daedalus-ui-input--select"
            value={kind ?? ''}
            onChange={(event) =>
              setKind(event.target.value === '' ? null : event.target.value)
            }
          >
            <option value="">{Drupal.t('All kinds')}</option>
            {filters.kinds.map((id) => (
              <option key={id} value={id}>
                {kindLabel(guide, id)}
              </option>
            ))}
          </select>
        )}
        {Boolean(config?.canCreate) && !minting && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-palette__mint-open"
            title={Drupal.t(
              "Mint a new swatch: a named color or gradient that joins the site's materials; the whole site can reference it.",
            )}
            onClick={() => setMinting(true)}
          >
            {Drupal.t('+ New swatch')}
          </button>
        )}
      </div>
      {minting && (
        <MintCard
          guide={guide}
          materials={materials}
          source={source}
          onClose={() => setMinting(false)}
        />
      )}
      {rows.length === 0 ? (
        <div className="daedalus-stylish-inspector__empty">
          {swatches.length === 0
            ? Drupal.t(
                "No swatches exist yet. Mint one here, or save a gradient as a swatch from the builder; minted swatches join the site's materials.",
              )
            : Drupal.t('No swatches match.')}
        </div>
      ) : (
        <div className="daedalus-stylish-library__list">
          {rows.map((row) => {
            const record = swatches.find(
              (candidate) => candidate.id === row.reference,
            );
            return (
              <Fragment key={row.reference}>
                <button
                  type="button"
                  className={`daedalus-stylish-library__row${card === row.reference ? ' daedalus-stylish-library__row--open' : ''}`}
                  aria-expanded={card === row.reference}
                  onClick={() =>
                    setCard(card === row.reference ? null : row.reference)
                  }
                >
                  <Face
                    className="daedalus-stylish-palette__face"
                    {...facePair(guide, materials, record?.value ?? '')}
                  />
                  <span className="daedalus-stylish-library__name">{row.label}</span>
                  <span className="daedalus-ui-chip daedalus-stylish-library__axis">
                    {kindLabel(guide, row.kind)}
                  </span>
                  {row.shipped && (
                    <span
                      className="daedalus-ui-chip daedalus-stylish-library__shipped"
                      title={Drupal.t(
                        'Shipped by a module; read-only. Mint your own swatch to get an editable one.',
                      )}
                    >
                      {Drupal.t('shipped')}
                    </span>
                  )}
                </button>
                {card === row.reference && record && (
                  <SwatchCard
                    swatch={record}
                    guide={guide}
                    materials={materials}
                    config={config}
                    source={source}
                    onCard={setCard}
                  />
                )}
              </Fragment>
            );
          })}
        </div>
      )}
      {skin.length > 0 && (
        <div className="daedalus-stylish-palette__skin">
          <div className="daedalus-stylish-palette__skin-header">
            <span className="daedalus-stylish-palette__skin-title">
              {Drupal.t('From the skin')}
            </span>
            <span className="daedalus-stylish-palette__skin-honesty">
              {Drupal.t(
                'The active skin mints these materials; they are read-only here. Edit the skin to change them.',
              )}
            </span>
          </div>
          {skin.map(([scaleLabel, entries]) => (
            <div key={scaleLabel} className="daedalus-stylish-palette__scale">
              <div className="daedalus-stylish-palette__scale-label">{scaleLabel}</div>
              {entries.map((token) => {
                const dark =
                  token.valueDark !== token.value ? token.valueDark : null;
                return (
                  <div
                    key={token.ref}
                    className="daedalus-stylish-palette__token"
                    title={token.ref}
                  >
                    {token.shape === 'color' && (
                      <Face
                        className="daedalus-stylish-palette__face"
                        paint={token.value}
                        paintDark={dark}
                      />
                    )}
                    <span className="daedalus-stylish-palette__token-name">
                      {token.name}
                    </span>
                    <span className="daedalus-stylish-palette__token-value">
                      {token.value}
                    </span>
                    {dark !== null && (
                      <span
                        className="daedalus-stylish-palette__token-value daedalus-stylish-palette__token-value--dark"
                        title={Drupal.t('The value under the @scheme scheme.', {
                          '@scheme': schemeLabel,
                        })}
                      >
                        {dark}
                      </span>
                    )}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
