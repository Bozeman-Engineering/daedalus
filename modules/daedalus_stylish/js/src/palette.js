import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  deleteSwatch,
  fetchSwatchRevisions,
  fetchSwatchUsage,
  renameSwatch,
  restoreSwatchRevision,
  revertSwatch,
} from './swatches-api.js';
import {
  deleteToken,
  fetchTokenUsage,
  renameToken,
  revertToken,
} from './tokens-api.js';
import {
  chooseSkin,
  copySkin,
  fetchSkinRevisions,
  fetchSkins,
  restoreSkinRevision,
  revertSkin,
} from './skins-api.js';
import {
  copyLabel,
  copySources,
  restoreScope,
  skinCardGestures,
  skinCardReadable,
  skinCardRows,
  wornSkin,
} from './skin-card-model.js';
import {
  respondedMaterial,
  saveMaterial,
  updateMaterial,
} from './material-gestures.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { schemeCondition } from './style-guide.js';
import { facePair, fontOptions, styleReference } from './materials.js';
import { addFamilyToStack, fontScale } from './fonts-model.js';
import { Face } from './face.js';
import {
  editablePair,
  mergedPair,
  paletteFilters,
  paletteRows,
  skinGroups,
  swatchCardGestures,
  tokenCardGestures,
  tokenNameError,
} from './palette-model.js';
import { swatchKindAnatomy } from './swatch-kinds.js';
import { colorSeed } from './swatch-kinds-model.js';
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
              'Author a separate value for the !scheme scheme; the face shows both halves.',
              { '!scheme': schemeLabel },
            )}
            onClick={() => onDark(draft)}
          >
            {Drupal.t('+ !scheme value', { '!scheme': schemeLabel })}
          </button>
        ) : (
          <Fragment>
            <div className="daedalus-stylish-swatch-pair__head">
              <span className="daedalus-stylish-swatch-pair__scheme">{schemeLabel}</span>
              <button
                type="button"
                className="daedalus-ui-chip daedalus-stylish-swatch-pair__remove"
                title={Drupal.t(
                  'Drop the !scheme entry; the default value serves both schemes.',
                  { '!scheme': schemeLabel },
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
          Drupal.t('Renamed to "!label".', {
            '!label':
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
        Drupal.t('Updated the swatch !label', { '!label': swatch.label }),
      )
        .then((record) => {
          setValueDraft(null);
          source.notify?.(
            'status',
            Drupal.t('Updated "!label"; everything referencing it follows.', {
              '!label': record.label,
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
          message: Drupal.t('Restore "!label" to its revision of !date?', {
            '!label': swatch.label,
            '!date': revisionDate(revision.created),
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
              Drupal.t('Restored "!label" from its revision of !date.', {
                '!label':
                  respondedMaterial(response, 'swatch', reference)?.label ??
                  swatch.label,
                '!date': revisionDate(revision.created),
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
            'Revert "!label" to the defaults its module ships?',
            { '!label': swatch.label },
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
            Drupal.t('Reverted "!label" to its shipped defaults.', {
              '!label':
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
          message: Drupal.t('Delete "!label"?', { '!label': swatch.label }),
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
            Drupal.t('Deleted "!label".', { '!label': swatch.label }),
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
                  ? Drupal.t('Offered the swatch !label to the AI', {
                      '!label': swatch.label,
                    })
                  : Drupal.t('Kept the swatch !label from the AI', {
                      '!label': swatch.label,
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
              {Drupal.t('The !conditions entries are kept as they are.', {
                '!conditions': conditions.join(', '),
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
              {Drupal.t('Rewriting… !percent%', {
                '!percent': progress.percentage ?? '0',
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
      Drupal.t('Added the swatch !label', { '!label': label.trim() }),
    )
      .then((record) => {
        // Kind-aware confirmation ("Added the Rain gradient to the color
        // palette"): the messenger toast names what was made, not the
        // internal "mint" verb.
        source.notify?.(
          'status',
          Drupal.t('Added the !label !kind to the color palette.', {
            '!label': record.label,
            '!kind': kindLabel(guide, record.group).toLowerCase(),
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
 * The value editor for the token shapes no swatch kind mounts.
 *
 * A token's scale declares its shape: a color scale mounts the color
 * kind's editor (the palette as a value space, the native picker, free
 * text), and every other shape (dimension, number, a font stack, a
 * shadow) edits as text, references included, under the same editor
 * contract ({value, onPreview, onCommit}): every keystroke previews, blur
 * settles. The server validates the shape on commit.
 */
const TextValueEditor = ({ value, onPreview, onCommit }) => (
  <input
    type="text"
    className="daedalus-ui-input daedalus-stylish-palette-token__input"
    aria-label={Drupal.t('Token value')}
    value={typeof value === 'string' ? value : ''}
    onInput={(event) => onPreview(event.target.value)}
    onBlur={(event) => onCommit(event.target.value)}
    onKeyDown={(event) => {
      if (event.key === 'Enter') {
        onCommit(event.target.value);
      }
    }}
  />
);

/**
 * The stack editor: the text editor with the minted families as chips.
 *
 * A token on the scale the guide binds font-family to (the suite's
 * `font` scale: fonts-model.js fontScale) holds a font-family stack, so
 * its editor offers every rostered font (materials.js fontOptions) as a
 * chip that adds the family to the stack (fonts-model.js
 * addFamilyToStack: alone on an empty stack, before a trailing generic,
 * else appended, never twice), wearing its own family. The value stays
 * the text (a generic, a theme family and a minted family all live in
 * one stack); a chip's add is a settle, as typing then blurring is.
 */
const FontStackEditor = ({ value, materials, onPreview, onCommit }) => {
  const stack = typeof value === 'string' ? value : '';
  const fonts = fontOptions(materials);
  return (
    <div className="daedalus-stylish-palette-token__stack">
      <TextValueEditor value={value} onPreview={onPreview} onCommit={onCommit} />
      {fonts.length > 0 && (
        <div
          className="daedalus-stylish-palette-token__families"
          role="group"
          aria-label={Drupal.t('Minted families')}
        >
          {fonts.map((font) => (
            <button
              key={font.name}
              type="button"
              className="daedalus-ui-chip daedalus-stylish-palette-token__family"
              data-font={font.name}
              style={{ fontFamily: font.spelling }}
              title={Drupal.t('Add !family to the stack.', {
                '!family': font.family,
              })}
              onClick={() => onCommit(addFamilyToStack(stack, font.spelling))}
            >
              {font.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

/**
 * The editor a token's scale mounts: the color kind's for color scales,
 * the stack editor with the minted families for the scale font-family
 * is bound to, the text editor for the rest.
 */
const tokenEditor = (guide, scale, shape) =>
  (shape === 'color' ? swatchKindAnatomy('color')?.Editor : null) ??
  (scale !== null && scale === fontScale(guide) ? FontStackEditor : null) ??
  TextValueEditor;

/**
 * A scale's mint seed: the color kind's neutral for a color scale,
 * nothing for the rest (a dimension has no honest default).
 */
const tokenSeed = (shape) => (shape === 'color' ? colorSeed() : '');

/**
 * Why a token name cannot go, phrased.
 */
const tokenNameText = (error) =>
  ({
    missing: Drupal.t('A token needs a name.'),
    invalid: Drupal.t(
      'Lowercase letters, digits and inner hyphens ("4", "2xl", "accent-hover"); the name becomes the tail of the custom property.',
    ),
    taken: Drupal.t('The active skin already mints that name on this scale.'),
  })[error] ?? '';

/**
 * The token card, inline under its open skin row.
 *
 * The swatch card's twin for the material that lives on the skin: the
 * token's editable facts (its value through the shape's editor with the
 * scheme pair, its description, its offered flag) are update_material
 * ops (kind token) through the source's batch commit, the ops the AI
 * submits; its identity gestures (rename, revert to the shipped record,
 * delete) stay routes whose response carries the materials delta, handed
 * to source.applyResponse so the store learns it in both windows. On a
 * consuming site a write lands in the skin's site layer over the
 * package's record, so the value editor is open on every token the
 * account may update, while rename and delete are the site's own on
 * site-minted tokens only (tokenCardGestures reads the lock from the
 * management read). "Use the shipped value" is the unset gesture: the
 * site's record over a package token goes and the package's shows
 * through, on the canvas too (the delivery re-stages what differs from
 * the compiled file).
 *
 * The value editor previews live: every tick lands the merged draft on
 * the token scope lane through the source's previewMaterial seam, so
 * every use on the canvas answers while the store keeps the committed
 * value; cancel, close or unmount releases it, and a commit hands the
 * canvas to the store's delivery. The card reads the record from the
 * store (the prop follows every delta); the management facts beyond it
 * (the live reference count, the layer(s) holding the token, the lock,
 * the shipped state) are one per-open read of the usage route, refreshed
 * after each gesture and again at every prompt. Without a management
 * grant the read is not made and the card says why it is read-only.
 */
const TokenCard = ({ token, guide, materials, config, source, onCard }) => {
  const { record } = token;
  const reference = record.id;
  const [busy, setBusy] = useState(false);
  // The rename draft: null follows the token's name; a string is typing.
  const [nameDraft, setNameDraft] = useState(null);
  // The value editor: null closed; a string is the default working draft.
  const [valueDraft, setValueDraft] = useState(null);
  const [darkDraft, setDarkDraft] = useState(null);
  // The description draft: null follows the record; a string is typing.
  const [descriptionDraft, setDescriptionDraft] = useState(null);
  const [progress, setProgress] = useState(null);
  const [management, setManagement] = useState(null);
  // Whether the token scope lane wears a draft that must release.
  const previewed = useRef(false);
  // The record the release names: the latest, not the mount's.
  const latest = useRef(record);
  latest.current = record;

  const canManage = Boolean(
    config?.canUpdate || config?.canDelete || config?.canDeleteInUse,
  );
  const gestures = tokenCardGestures(record, config, management);
  const Editor = tokenEditor(guide, record.group ?? null, token.shape);
  const {
    draft: storedDraft,
    darkDraft: storedDark,
    scheme,
    conditions,
  } = editablePair(guide, record.value);
  const locked = management?.locked !== false;

  useEffect(() => {
    if (!canManage) {
      return undefined;
    }
    let alive = true;
    fetchTokenUsage(reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [reference, record, canManage]);

  const restore = () => {
    if (previewed.current) {
      previewed.current = false;
      source.previewMaterial(latest.current, null);
    }
  };
  // Unmount IS close: whatever the card previewed restores.
  useEffect(() => restore, []);

  const stageDraft = (next, nextDark) => {
    previewed.current = true;
    source.previewMaterial(
      record,
      mergedPair(record.value, { draft: next, darkDraft: nextDark, scheme }),
    );
  };

  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  const update = (changes, label) =>
    run(() =>
      updateMaterial(source, { kind: 'token', id: reference, ...changes }, label)
        .then(() => null)
        .catch(() => {
          // The op queue's source reported it; the editor stays open.
        }),
    );

  const commitValue = () => {
    const committed = mergedPair(record.value, {
      draft: valueDraft,
      darkDraft,
      scheme,
    });
    run(() =>
      updateMaterial(
        source,
        { kind: 'token', id: reference, value: committed },
        Drupal.t('Updated the token !id', { '!id': reference }),
      )
        .then(() => {
          // The committed value supersedes the preview: the store's
          // delivery carries it to the canvas from here.
          previewed.current = false;
          setValueDraft(null);
          source.notify?.(
            'status',
            Drupal.t('Updated !id; everything referencing it follows.', {
              '!id': reference,
            }),
          );
          return null;
        })
        .catch(() => {
          // The op queue's source reported it; the editor stays open for
          // a correction, its preview still worn.
        }),
    );
  };

  const cancelValue = () => {
    restore();
    setValueDraft(null);
  };

  const commitDescription = (value) => {
    const description = value.trim();
    setDescriptionDraft(null);
    if (description === token.description) {
      return;
    }
    update(
      { description },
      Drupal.t('Described the token !id', { '!id': reference }),
    );
  };

  const rename = (value) => {
    const label = value.trim();
    setNameDraft(null);
    if (label === '' || label === token.name) {
      return;
    }
    run(() =>
      renameToken(reference, label).then(async (response) => {
        if (!response.renamed && response.batch_url) {
          setProgress({ percentage: '0' });
          try {
            await driveBatch(response.batch_url, setProgress);
          } finally {
            setProgress(null);
          }
        }
        // The delta moves the record (the old id out, the renamed one
        // in); the card follows the new id.
        source.applyResponse(response);
        onCard(response.id);
        source.notify?.(
          'status',
          Drupal.t('Renamed !id to !next.', {
            '!id': reference,
            '!next': response.id,
          }),
        );
        return null;
      }),
    );
  };

  const revert = () => {
    run(() =>
      fetchTokenUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Use the shipped value'),
          message: Drupal.t(
            'Drop the site\'s changes to !id and use what its module ships?',
            { '!id': reference },
          ),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new skin revision carrying the shipped record; nothing references the token right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new skin revision carrying the shipped record; this restyles 1 place site-wide.',
                  'A new skin revision carrying the shipped record; this restyles @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Use shipped value'),
        });
        if (!confirmed) {
          return null;
        }
        return revertToken(reference).then((response) => {
          source.applyResponse(response);
          source.notify?.(
            'status',
            Drupal.t('!id wears the shipped value again.', { '!id': reference }),
          );
          return null;
        });
      }),
    );
  };

  const destroy = () => {
    run(() =>
      fetchTokenUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: Drupal.t('Delete token'),
          message: Drupal.t('Delete !id?', { '!id': reference }),
          detail:
            usages === 0
              ? Drupal.t('Nothing references it; it is deleted immediately.')
              : Drupal.formatPlural(
                  usages,
                  'Rewrite 1 reference to its literal value, then delete: the place keeps its current look, then the token is deleted. Nothing on any page changes.',
                  'Rewrite @count references to their literal value, then delete: every place keeps its current look, then the token is deleted. Nothing on any page changes.',
                ),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Rewrite and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteToken(reference).then(async (result) => {
          if (!result.deleted && result.batch_url) {
            setProgress({ percentage: '0' });
            try {
              await driveBatch(result.batch_url, setProgress);
            } finally {
              setProgress(null);
            }
          }
          source.applyResponse(result);
          onCard(null);
          source.notify?.(
            'status',
            Drupal.t('Deleted !id.', { '!id': reference }),
          );
          return null;
        });
      }),
    );
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-palette-card daedalus-stylish-palette-token-card">
      <div className="daedalus-stylish-library-card__name">
        {gestures.rename ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input"
            value={nameDraft ?? token.name}
            disabled={busy}
            title={Drupal.t(
              'Rename the token. The name is the tail of its custom property, so every reference is respelled.',
            )}
            onInput={(event) => setNameDraft(event.target.value)}
            onBlur={(event) => rename(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                rename(event.target.value);
              }
            }}
          />
        ) : (
          <span className="daedalus-stylish-library-card__label">{token.name}</span>
        )}
        <code
          className="daedalus-stylish-palette-card__variable"
          title={Drupal.t('The reference the token is written as.')}
        >
          {token.ref}
        </code>
      </div>
      {/* Why the card withholds what it withholds: the account's grants,
          then the package's lock on a shipped token's identity. */}
      {!gestures.editValue && (
        <div className="daedalus-stylish-palette-token-card__reason">
          {Drupal.t(
            'Your account cannot update tokens; the skin\'s values are read-only here.',
          )}
        </div>
      )}
      {gestures.editValue && token.layer === 'package' && locked && (
        <div className="daedalus-stylish-palette-token-card__reason">
          {management?.layer === 'both'
            ? Drupal.t(
                'The site\'s value over the one !provider ships; its name and the token itself belong to the package.',
                { '!provider': management?.provider ?? '' },
              )
            : Drupal.t(
                'Shipped by !provider: an edit lands as the site\'s value over it; its name and the token itself belong to the package.',
                { '!provider': management?.provider ?? '' },
              )}
        </div>
      )}
      {gestures.offer ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={token.offered}
          disabled={busy}
          label={Drupal.t('Offer on rows and to the AI')}
          title={Drupal.t(
            'Off, the basic rows bound to this scale leave it out of their stops and the AI is not told about it; it still applies wherever it is referenced.',
          )}
          onChange={(next) =>
            update(
              { offered: next },
              next
                ? Drupal.t('Offered the token !id', { '!id': reference })
                : Drupal.t('Withheld the token !id', { '!id': reference }),
            )
          }
        />
      ) : (
        !token.offered && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Off the basic rows and kept from the AI; its reference still resolves.',
            )}
          >
            {Drupal.t('not offered')}
          </span>
        )
      )}
      {gestures.editDescription ? (
        <textarea
          className="daedalus-ui-input daedalus-stylish-palette-token-card__description"
          rows={2}
          placeholder={Drupal.t('Describe when to reach for this token…')}
          value={descriptionDraft ?? token.description}
          disabled={busy}
          onInput={(event) => setDescriptionDraft(event.target.value)}
          onBlur={(event) => commitDescription(event.target.value)}
        />
      ) : (
        token.description !== '' && (
          <div className="daedalus-stylish-palette-token-card__description-text">
            {token.description}
          </div>
        )
      )}
      {canManage && (
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
      )}
      {valueDraft !== null ? (
        <div className="daedalus-stylish-palette-card__editor">
          {conditions.length > 0 && (
            <div className="daedalus-stylish-palette-card__conditions">
              {Drupal.t('The !conditions entries are kept as they are.', {
                '!conditions': conditions.join(', '),
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
            exclude={token.ref}
            onDraft={(next) => {
              setValueDraft(next);
              stageDraft(next, darkDraft);
            }}
            onDark={(next) => {
              setDarkDraft(next);
              stageDraft(valueDraft, next);
            }}
          />
          <div className="daedalus-stylish-library-card__actions">
            <button
              type="button"
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-token-card__save"
              disabled={
                busy ||
                valueDraft.trim() === '' ||
                (darkDraft !== null && darkDraft.trim() === '')
              }
              title={Drupal.t(
                'Save the value; every place referencing this token restyles.',
              )}
              onClick={commitValue}
            >
              {Drupal.t('Save value')}
            </button>
            <button
              type="button"
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
              disabled={busy}
              onClick={cancelValue}
            >
              {Drupal.t('Cancel')}
            </button>
          </div>
        </div>
      ) : (
        <Fragment>
          {progress && (
            <div className="daedalus-stylish-library-card__progress">
              {Drupal.t('Rewriting… !percent%', {
                '!percent': progress.percentage ?? '0',
              })}
            </div>
          )}
          <div className="daedalus-stylish-library-card__actions">
            {gestures.editValue && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-token-card__edit"
                disabled={busy}
                title={Drupal.t(
                  'Edit the value; the canvas previews every change and saving restyles every place referencing this token.',
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
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-token-card__revert"
                disabled={busy}
                title={Drupal.t(
                  'Drop the site\'s value; the one the module ships shows through.',
                )}
                onClick={revert}
              >
                {Drupal.t('Use shipped value')}
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
 * The token mint card: the skin section's "New token" gesture.
 *
 * The scale (the guide's declared scales; a token is minted on one), the
 * name (the custom property's tail, checked against the token grammar
 * and the roster before the round trip), the scale shape's editor over a
 * seeded draft with the scheme pair, the description and the offered
 * flag: the same save_material op (kind token) the AI submits, through
 * the source's batch commit. The op queue lands the response's materials
 * delta on the store before the promise resolves, so the token is a stop
 * on every basic row bound to its scale, in every picker of both windows,
 * and its custom property resolves on the canvas before any reload.
 */
const TokenMintCard = ({ guide, materials, source, onClose }) => {
  const scales = Object.entries(guide?.tokenScales ?? {}).map(
    ([id, declaration]) => ({
      id,
      label: declaration?.label ?? id,
      shape: declaration?.shape ?? null,
    }),
  );
  const [scale, setScale] = useState(scales[0]?.id ?? '');
  const [name, setName] = useState('');
  const [value, setValue] = useState(() => tokenSeed(scales[0]?.shape));
  const [darkValue, setDarkValue] = useState(null);
  const [description, setDescription] = useState('');
  const [offered, setOffered] = useState(true);
  const [minting, setMinting] = useState(false);

  const scheme = schemeCondition(guide);
  const shape = scales.find((entry) => entry.id === scale)?.shape ?? null;
  const Editor = tokenEditor(guide, scale, shape);
  const nameError = tokenNameError(name.trim(), scale, materials);

  const mint = () => {
    setMinting(true);
    saveMaterial(
      source,
      {
        kind: 'token',
        group: scale,
        label: name.trim(),
        value: mergedPair(value, { draft: value, darkDraft: darkValue, scheme }),
        description: description.trim() === '' ? undefined : description.trim(),
        offered: offered ? undefined : false,
      },
      Drupal.t('Minted the token !scale/!name', {
        '!scale': scale,
        '!name': name.trim(),
      }),
    )
      .then((record) => {
        source.notify?.(
          'status',
          Drupal.t('Added the !name token to the !scale scale.', {
            '!name': record.label,
            '!scale':
              scales.find((entry) => entry.id === record.group)?.label ??
              record.group,
          }),
        );
        onClose();
      })
      .catch(() => {
        // The op queue's source reported it; the card stays open.
      })
      .finally(() => setMinting(false));
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-palette-mint daedalus-stylish-palette-token-mint">
      <div className="daedalus-stylish-palette-mint__identity">
        <select
          className="daedalus-ui-input daedalus-ui-input--select"
          value={scale}
          title={Drupal.t('The scale the token is minted on')}
          aria-label={Drupal.t('Scale')}
          onChange={(event) => {
            const next = scales.find((entry) => entry.id === event.target.value);
            setScale(event.target.value);
            // A scale flip re-seeds the draft: the old shape's value means
            // nothing to the new shape's editor.
            setValue(tokenSeed(next?.shape));
            setDarkValue(null);
          }}
        >
          {scales.map((entry) => (
            <option key={entry.id} value={entry.id}>
              {entry.label}
            </option>
          ))}
        </select>
        <input
          type="text"
          className="daedalus-stylish-library-card__label daedalus-ui-input"
          placeholder={Drupal.t('Token name…')}
          aria-label={Drupal.t('Token name')}
          value={name}
          title={
            nameError !== null && name.trim() !== ''
              ? tokenNameText(nameError)
              : Drupal.t('The tail of the custom property: --!scale-name', {
                  '!scale': guide?.tokenScales?.[scale]?.prefix ?? scale,
                })
          }
          onInput={(event) => setName(event.target.value)}
        />
      </div>
      {nameError !== null && name.trim() !== '' && (
        <div className="daedalus-stylish-palette-token-mint__error">
          {tokenNameText(nameError)}
        </div>
      )}
      <AiOfferToggle
        offered={offered}
        label={Drupal.t('Offer on rows and to the AI')}
        title={Drupal.t(
          'Off, the basic rows bound to this scale leave it out of their stops and the AI is not told about it; it still applies wherever it is referenced.',
        )}
        onChange={setOffered}
      />
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
      <textarea
        className="daedalus-ui-input daedalus-stylish-palette-token-card__description"
        rows={2}
        placeholder={Drupal.t('Describe when to reach for this token…')}
        value={description}
        onInput={(event) => setDescription(event.target.value)}
      />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-token-mint__save"
          disabled={
            minting ||
            scale === '' ||
            nameError !== null ||
            value.trim() === '' ||
            (darkValue !== null && darkValue.trim() === '')
          }
          title={Drupal.t(
            "Mint the token: a named value on the active skin's scale; the rows bound to the scale offer it and the whole site can reference it.",
          )}
          onClick={mint}
        >
          {Drupal.t('Save token')}
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
 * A refused member's reason, in the card's words.
 */
const reasonText = (row) => {
  switch (row.reason) {
    case 'unpublished':
      return Drupal.t('unpublished');
    case 'nonconforming':
      return Drupal.t('lacks !names', {
        '!names': Object.entries(row.lacks ?? {})
          .map(([scale, names]) => `${scale}: ${names.join(', ')}`)
          .join('; '),
      });
    case 'not-member':
      return Drupal.t('not a member');
    default:
      return Drupal.t('cannot be worn');
  }
};

/**
 * The skin card, over the skin section: the look the site wears.
 *
 * The lifecycle card for the skin itself, the variant card's twin for
 * the look: the worn skin's identity and where the site stands with it
 * (shipped by a module with the site's changes a layer over it, or the
 * site's own), the design system's other member skins with "wear" on
 * those the system may wear and why not on the rest, copy as my skin
 * (the distro lock's fork gesture and the first-skin door in one: any
 * skin on the site, picked from a source select that preselects the
 * worn one, copied as a skin with no provider carrying the merged look
 * into the ACTIVE design system; a site's own unit with no default takes
 * its first copy as its default_skin and wears it at once), the revision
 * history with restore, and the site layer's revert ("Use shipped
 * skin"). Every gesture that changes what the site wears is a route
 * whose response carries the materials delta, handed to
 * source.applyResponse so both windows' stores learn the swapped or
 * restored token set, then the skin's delivery refreshes
 * (source.refreshDefaults: the defaults map for the panel's stacks and
 * the recompiled CSS on the canvas), since the worn defaults changed
 * with it; a copy that wore nothing (`worn` false) only refetches the
 * read, since choosing is then the next gesture.
 *
 * The card has one management read (the skins route: skin-card-model.js
 * reads everything off it), fetched at mount, on open and after every
 * gesture, never off the guide payload: which skin is worn changes with
 * a choice and a copy adds a member. The read admits every grade that
 * touches the skin, so a token editor sees which skin they edit; the
 * gestures are the administer grade's alone (each restyles the whole
 * site), and without it the card is the worn skin's name and standing.
 */
const SkinCard = ({ tokens: tokenConfig, skin: skinConfig, source }) => {
  const [read, setRead] = useState(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [revisions, setRevisions] = useState(null);
  // The copy draft: null closed; {source, label} is picking and typing
  // (the source's reference and the label draft).
  const [copyDraft, setCopyDraft] = useState(null);
  // Bumped after every gesture: the read and the history refetch.
  const [generation, setGeneration] = useState(0);

  const worn = wornSkin(read);
  const rows = skinCardRows(read);
  const gestures = skinCardGestures(read, skinConfig);
  const wornName = worn?.machine_name ?? null;
  // The copy's sources, the worn skin first when one is worn.
  const sources = copySources(read);
  const sourceOf = (reference) =>
    sources
      .flatMap((group) => group.skins)
      .find((skin) => skin.reference === reference) ?? null;

  useEffect(() => {
    let alive = true;
    fetchSkins().then(
      (body) => alive && setRead(body),
      () => alive && setRead(null),
    );
    return () => {
      alive = false;
    };
  }, [open, generation]);

  // The revision history, fetched while the card is open and can restore.
  useEffect(() => {
    if (!open || !gestures.revisions || wornName === null) {
      setRevisions(null);
      return undefined;
    }
    let alive = true;
    fetchSkinRevisions(wornName).then(
      (body) => alive && setRevisions(body.revisions ?? []),
      () => alive && setRevisions([]),
    );
    return () => {
      alive = false;
    };
  }, [open, generation, wornName, gestures.revisions]);

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  // A write that changes what the site wears: the delta into both
  // windows' stores, then the skin's delivery, then a fresh read.
  const landed = async (response) => {
    source.applyResponse(response);
    await Promise.resolve(source.refreshDefaults?.());
    setGeneration((count) => count + 1);
  };

  const choose = (row) => {
    run(async () => {
      const confirmed = await Drupal.Daedalus.dialog.confirm({
        title: Drupal.t('Wear this skin'),
        message: Drupal.t('Wear the "!label" skin?', { '!label': row.label }),
        detail: Drupal.t(
          'The whole site restyles to its tokens and defaults. The skin worn now keeps its revisions and can be worn again.',
        ),
        confirmLabel: Drupal.t('Wear'),
      });
      if (!confirmed) {
        return null;
      }
      await landed(await chooseSkin(row.reference));
      source.notify?.(
        'status',
        Drupal.t('The site wears "!label".', { '!label': row.label }),
      );
      return null;
    });
  };

  // Opens the copy draft on a source: the label re-seeds from it (the
  // mint's kind-flip rule: the old source's derived label means nothing
  // to the new one).
  const draftCopy = (reference) => {
    const picked = sourceOf(reference);
    setCopyDraft(
      picked === null ? null : { source: reference, label: copyLabel(picked) },
    );
  };

  const copy = (draft) => {
    const label = draft.label.trim();
    run(async () => {
      const response = await copySkin(
        draft.source,
        label === '' ? null : label,
      );
      setCopyDraft(null);
      if (response.worn === true) {
        // The first skin of a site's own design system: the unit took it
        // as its default and the site wears it now.
        await landed(response);
        source.notify?.(
          'status',
          Drupal.t('Copied as "!label"; the site wears it.', {
            '!label': response.label,
          }),
        );
        return null;
      }
      setGeneration((count) => count + 1);
      source.notify?.(
        'status',
        Drupal.t('Copied as "!label"; wear it to see it.', {
          '!label': response.label,
        }),
      );
      return null;
    });
  };

  const restore = (revision) => {
    run(async () => {
      const confirmed = await Drupal.Daedalus.dialog.confirm({
        title: Drupal.t('Restore revision'),
        message: Drupal.t('Restore "!label" to its revision of !date?', {
          '!label': worn.label,
          '!date': revisionDate(revision.created),
        }),
        detail:
          restoreScope(worn.locked) === 'site'
            ? Drupal.t(
                "A new revision carrying that revision's site layer over what the module ships now; the whole site restyles.",
              )
            : Drupal.t(
                'A new revision carrying that content; the whole site restyles.',
              ),
        confirmLabel: Drupal.t('Restore'),
      });
      if (!confirmed) {
        return null;
      }
      await landed(await restoreSkinRevision(wornName, revision.revision_id));
      source.notify?.(
        'status',
        Drupal.t('Restored "!label" from its revision of !date.', {
          '!label': worn.label,
          '!date': revisionDate(revision.created),
        }),
      );
      return null;
    });
  };

  const revert = () => {
    run(async () => {
      const confirmed = await Drupal.Daedalus.dialog.confirm({
        danger: true,
        title: Drupal.t('Use the shipped skin'),
        message: Drupal.t(
          'Drop every change this site made to "!label" and wear what !provider ships?',
          { '!label': worn.label, '!provider': worn.provider },
        ),
        detail: Drupal.t(
          'A new skin revision with an empty site layer: every token the site added or changed and every default it set goes; the whole site restyles.',
        ),
        confirmLabel: Drupal.t('Use shipped skin'),
      });
      if (!confirmed) {
        return null;
      }
      await landed(await revertSkin(wornName));
      source.notify?.(
        'status',
        Drupal.t('"!label" wears what !provider ships again.', {
          '!label': worn.label,
          '!provider': worn.provider,
        }),
      );
      return null;
    });
  };

  if (read === null) {
    return null;
  }

  return (
    <div className="daedalus-stylish-palette-skin">
      <button
        type="button"
        className={`daedalus-stylish-palette-skin__open${open ? ' daedalus-stylish-palette-skin__open--open' : ''}`}
        aria-expanded={open}
        title={Drupal.t(
          'The skin the site wears: its standing, the other skins of the design system, its revisions.',
        )}
        onClick={() => setOpen(!open)}
      >
        <span className="daedalus-stylish-palette-skin__wearing">
          {Drupal.t('Wearing')}
        </span>
        <span className="daedalus-stylish-palette-skin__name">
          {worn === null ? Drupal.t('no skin') : worn.label}
        </span>
        {worn !== null &&
          (worn.provider != null ? (
            <span
              className="daedalus-ui-chip daedalus-stylish-library__shipped"
              title={Drupal.t(
                "Shipped by !provider. The site's additions and changes are a layer over it.",
                { '!provider': worn.provider },
              )}
            >
              {Drupal.t('shipped')}
            </span>
          ) : (
            <span
              className="daedalus-ui-chip daedalus-stylish-palette-skin__own"
              title={Drupal.t(
                'A skin this site made; every edit lands on it directly.',
              )}
            >
              {Drupal.t('yours')}
            </span>
          ))}
      </button>
      {open && (
        <div className="daedalus-stylish-library-card daedalus-stylish-palette-card daedalus-stylish-palette-skin-card">
          {worn !== null && (
            <div className="daedalus-stylish-library-card__name">
              <span className="daedalus-stylish-library-card__label">
                {worn.label}
              </span>
              <code
                className="daedalus-stylish-palette-card__variable"
                title={Drupal.t('The machine name the skins lane syncs by.')}
              >
                {worn.machine_name}
              </code>
            </div>
          )}
          <div className="daedalus-stylish-palette-skin-card__reason">
            {worn === null
              ? read.design_system == null
                ? Drupal.t(
                    'No design system is active, so nothing is worn.',
                  )
                : rows.length === 0
                  ? Drupal.t(
                      'The active design system has no skin yet. Copy one in below, any skin on the site: it becomes the design system\'s default and the site wears it.',
                    )
                  : Drupal.t(
                      'The active design system wears no skin: nothing it names is a published, conforming member.',
                    )
              : worn.provider == null
                ? Drupal.t(
                    'A skin this site made; every edit lands on it directly.',
                  )
                : worn.site_layer
                  ? Drupal.t(
                      "Shipped by !provider; the site's additions and changes are a layer over it, and an update to the package shows through.",
                      { '!provider': worn.provider },
                    )
                  : Drupal.t(
                      'Shipped by !provider; the site has changed nothing on it.',
                      { '!provider': worn.provider },
                    )}
          </div>
          {rows.length > 1 && (
            <div className="daedalus-stylish-palette-skin-card__members">
              <div className="daedalus-stylish-palette__scale-label">
                {Drupal.t('Skins of this design system')}
              </div>
              {rows.map((row) => (
                <div
                  key={row.reference}
                  className={`daedalus-stylish-palette-skin-card__member${row.worn ? ' daedalus-stylish-palette-skin-card__member--worn' : ''}`}
                  data-skin={row.reference}
                >
                  <span className="daedalus-stylish-palette-skin-card__member-name">
                    {row.label}
                  </span>
                  {row.isDefault && (
                    <span
                      className="daedalus-ui-chip"
                      title={Drupal.t(
                        "The design system's default: worn when the site has chosen nothing.",
                      )}
                    >
                      {Drupal.t('default')}
                    </span>
                  )}
                  {row.worn ? (
                    <span className="daedalus-stylish-library-card__revision-current">
                      {Drupal.t('worn')}
                    </span>
                  ) : row.choosable && gestures.choose ? (
                    <button
                      type="button"
                      className="daedalus-ui-chip daedalus-stylish-palette-skin-card__wear"
                      disabled={busy}
                      title={Drupal.t(
                        'Wear this skin: the whole site restyles to its tokens and defaults.',
                      )}
                      onClick={() => choose(row)}
                    >
                      {Drupal.t('wear')}
                    </button>
                  ) : row.reason !== null ? (
                    <span
                      className="daedalus-stylish-palette-skin-card__member-reason"
                      title={Drupal.t(
                        'The design system may not wear this skin right now.',
                      )}
                    >
                      {reasonText(row)}
                    </span>
                  ) : null}
                </div>
              ))}
            </div>
          )}
          {gestures.revisions && (
            <RevisionList
              revisions={revisions}
              busy={busy}
              onRestore={restore}
            />
          )}
          {copyDraft !== null ? (
            <div className="daedalus-stylish-palette-skin-card__copy">
              <div className="daedalus-stylish-palette-skin-card__copy-identity">
                <select
                  className="daedalus-ui-input daedalus-ui-input--select daedalus-stylish-palette-skin-card__copy-source"
                  value={copyDraft.source}
                  disabled={busy}
                  title={Drupal.t(
                    'The skin to copy: any skin on the site, grouped by design system.',
                  )}
                  aria-label={Drupal.t('Copy source')}
                  onChange={(event) => draftCopy(event.target.value)}
                >
                  {sources.map((group) => (
                    <optgroup
                      key={group.system ?? ''}
                      label={
                        group.system === null
                          ? Drupal.t('Global skins')
                          : group.label
                      }
                    >
                      {group.skins.map((skin) => (
                        <option key={skin.reference} value={skin.reference}>
                          {skin.worn
                            ? Drupal.t('!label (worn)', { '!label': skin.label })
                            : skin.label}
                        </option>
                      ))}
                    </optgroup>
                  ))}
                </select>
                <input
                  type="text"
                  className="daedalus-stylish-library-card__label daedalus-ui-input"
                  aria-label={Drupal.t('Copy label')}
                  value={copyDraft.label}
                  disabled={busy}
                  onInput={(event) =>
                    setCopyDraft({ ...copyDraft, label: event.target.value })
                  }
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      copy({ ...copyDraft, label: event.target.value });
                    }
                  }}
                />
              </div>
              <div className="daedalus-stylish-library-card__actions">
                <button
                  type="button"
                  className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-skin-card__copy-save"
                  disabled={busy}
                  title={Drupal.t(
                    "A new skin of your own carrying the picked skin's look as it shows on the site, a member of the active design system. A design system with no default takes it as its default and wears it; otherwise wear it to see it.",
                  )}
                  onClick={() => copy(copyDraft)}
                >
                  {Drupal.t('Copy')}
                </button>
                <button
                  type="button"
                  className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
                  disabled={busy}
                  onClick={() => setCopyDraft(null)}
                >
                  {Drupal.t('Cancel')}
                </button>
              </div>
            </div>
          ) : (
            (gestures.copy || gestures.revert) && (
              <div className="daedalus-stylish-library-card__actions">
                {gestures.copy && (
                  <button
                    type="button"
                    className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-skin-card__copy-open"
                    disabled={busy}
                    title={Drupal.t(
                      "Copy a skin as one of your own into the active design system: no module owns the copy, so every token and default on it is yours to change; it carries the look as it shows on the site now. Pick any skin on the site; the one worn is picked first.",
                    )}
                    onClick={() => draftCopy(sources[0].skins[0].reference)}
                  >
                    {Drupal.t('Copy as my skin')}
                  </button>
                )}
                {gestures.revert && (
                  <button
                    type="button"
                    className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-palette-skin-card__revert"
                    disabled={busy}
                    title={Drupal.t(
                      "Drop every token and default this site added or changed; the skin wears what its module ships, whole.",
                    )}
                    onClick={revert}
                  >
                    {Drupal.t('Use shipped skin')}
                  </button>
                )}
              </div>
            )
          )}
        </div>
      )}
    </div>
  );
};

/**
 * The Palette face: the catalog's value section.
 *
 * The variants face's sibling at value scope: site-wide content, no
 * selection needed: search, the kind filter, the flat swatch list with
 * cards inline, and the skin section beneath (the curated half of the
 * palette: the active skin's tokens per scale, each a row with its card,
 * and the mint that extends a scale; the skin is edited where it is
 * listed). Both halves are the materials store's records (the roster
 * this window was handed; a mint, edit or delete in either window
 * re-renders it through the store's delta): nothing fetches a list, and
 * the facts a card needs beyond its record are its own per-open read.
 *
 * @param {object} props
 * @param {object} props.swatches
 *   The swatch gesture gates (drupalSettings.daedalusStylish.swatches).
 * @param {object} props.tokens
 *   The token gesture gates (drupalSettings.daedalusStylish.tokens).
 * @param {object} props.skin
 *   The skin gesture gates (drupalSettings.daedalusStylish.skin): the
 *   skin card's lifecycle is the administer grade's.
 */
export const PaletteView = ({
  guide,
  materials,
  swatches: config = {},
  tokens: tokenConfig = {},
  skin: skinConfig = {},
  source,
}) => {
  const [search, setSearch] = useState('');
  const [kind, setKind] = useState(null);
  const [card, setCard] = useState(null);
  const [minting, setMinting] = useState(false);
  // The open token card (by roster id) and the token mint card: the skin
  // section browses on its own, so a swatch card and a token card may be
  // open together.
  const [tokenCard, setTokenCard] = useState(null);
  const [mintingToken, setMintingToken] = useState(false);

  const swatches = materials.ofKind('swatch');
  const filters = paletteFilters(swatches);
  const rows = paletteRows(swatches, { search, kind });
  const skin = skinGroups(guide, materials);
  const canMintToken =
    Boolean(tokenConfig?.canCreate) &&
    Object.keys(guide?.tokenScales ?? {}).length > 0;
  // The skin card mounts for every grade that touches the skin.
  const skinReadable = skinCardReadable(tokenConfig, skinConfig);
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
      {(skin.length > 0 || canMintToken || skinReadable) && (
        <div className="daedalus-stylish-palette__skin">
          <div className="daedalus-stylish-palette__skin-header">
            <span className="daedalus-stylish-palette__skin-title">
              {Drupal.t('From the skin')}
            </span>
            <span className="daedalus-stylish-palette__skin-honesty">
              {Drupal.t(
                'The active skin\'s tokens, per scale. Editing one restyles everything that references it.',
              )}
            </span>
            {skinReadable && (
              <SkinCard
                tokens={tokenConfig}
                skin={skinConfig}
                source={source}
              />
            )}
            {canMintToken && !mintingToken && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-palette__mint-token-open"
                title={Drupal.t(
                  "Mint a new token on one of the skin's scales; the rows bound to the scale offer it and the whole site can reference it.",
                )}
                onClick={() => setMintingToken(true)}
              >
                {Drupal.t('+ New token')}
              </button>
            )}
          </div>
          {mintingToken && (
            <TokenMintCard
              guide={guide}
              materials={materials}
              source={source}
              onClose={() => setMintingToken(false)}
            />
          )}
          {skin.map(([scaleLabel, entries]) => (
            <div key={scaleLabel} className="daedalus-stylish-palette__scale">
              <div className="daedalus-stylish-palette__scale-label">{scaleLabel}</div>
              {entries.map((token) => {
                const dark =
                  token.valueDark !== token.value ? token.valueDark : null;
                const open = tokenCard === token.record.id;
                return (
                  <Fragment key={token.record.id}>
                    <button
                      type="button"
                      className={`daedalus-stylish-palette__token${open ? ' daedalus-stylish-palette__token--open' : ''}`}
                      title={token.ref}
                      aria-expanded={open}
                      data-token={token.record.id}
                      onClick={() =>
                        setTokenCard(open ? null : token.record.id)
                      }
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
                          title={Drupal.t('The value under the !scheme scheme.', {
                            '!scheme': schemeLabel,
                          })}
                        >
                          {dark}
                        </span>
                      )}
                      {token.layer === 'package' && (
                        <span
                          className="daedalus-ui-chip daedalus-stylish-library__shipped"
                          title={Drupal.t(
                            'Shipped by a module. An edit lands as the site\'s value over it; the name stays the package\'s.',
                          )}
                        >
                          {Drupal.t('shipped')}
                        </span>
                      )}
                      {!token.offered && (
                        <span
                          className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
                          title={Drupal.t(
                            'Off the basic rows and kept from the AI; its reference still resolves.',
                          )}
                        >
                          {Drupal.t('not offered')}
                        </span>
                      )}
                    </button>
                    {open && (
                      <TokenCard
                        token={token}
                        guide={guide}
                        materials={materials}
                        config={tokenConfig}
                        source={source}
                        onCard={setTokenCard}
                      />
                    )}
                  </Fragment>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
