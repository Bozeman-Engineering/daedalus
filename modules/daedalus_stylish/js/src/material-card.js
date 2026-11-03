import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  deleteMaterial,
  fetchMaterialUsage,
  renameMaterial,
  revertMaterial,
} from './skin-api.js';
import { updateMaterial } from './material-gestures.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { AiOfferToggle } from './ai-offer.js';
import { Face } from './face.js';
import { SchemePairEditor, tokenEditor } from './token-editors.js';
import { KeyframesSession } from './keyframes-editor.js';
import { FaceList, FacesEditor, facesBlocked } from './faces-editor.js';
import {
  copySeed,
  editablePair,
  facesOf,
  keyframesSummary,
  materialCardGestures,
  mergedPair,
  storedFaceDraft,
} from './skin-model.js';

/**
 * The one card for the skin's three material kinds, and the row that
 * opens it.
 *
 * A row shows what a material is without opening: a preview where one is
 * honest (a color or gradient token's face; a font's name in its own
 * family), the name, and the kind's value summary (a token's value and
 * its scheme half, an animation's step count, a font's face count). No
 * origin chip: who shipped a material only matters once its card is
 * open, where the card says it in a sentence and shows Rename and Delete
 * disabled with the reason. Click opens the card inline under the row.
 *
 * The card is the variant card's twin for the materials that live on the
 * skin: the spelling (the reference a style is written as), the
 * description, the origin sentence, the AI-offer toggle, the live use
 * count, the token's untouched conditions, and the kind's value slot (a
 * token's shape editor with the scheme pair, the keyframes session, the
 * faces editor), then the actions. The editable facts (value, steps,
 * faces, description, offered) are update_material ops through the
 * source's batch commit, the ops the AI submits; the identity gestures
 * (rename, revert to the shipped record, delete) stay routes whose
 * response carries the materials delta, handed to source.applyResponse
 * so the store learns it in both windows. On a consuming site a write
 * lands in the skin's site layer over the package's record, so the value
 * editor is open on every material the account may update, while rename
 * and delete are the site's own on site-minted materials only
 * (materialCardGestures reads the lock from the management read).
 *
 * A token's value editor previews live: every tick lands the merged
 * draft on the token scope lane through the source's previewMaterial
 * seam, so every use on the canvas answers while the store keeps the
 * committed value; cancel, close or unmount releases it, and a commit
 * hands the canvas to the store's delivery. The card reads the record
 * from the store (the row follows every delta); the management facts
 * beyond it (the live reference count, the layer(s) holding the
 * material, the lock, the shipped state, the provider) are one per-open
 * read of the usage route, refreshed after each gesture and again at
 * every prompt.
 */

/**
 * The kind's words: what an edit changes, what a use is called.
 */
const WORDS = {
  token: {
    noun: () => Drupal.t('value'),
    edit: () => Drupal.t('Edit value'),
    copy: () => Drupal.t('Copy to new token'),
    none: () => Drupal.t('used by nothing'),
    some: (count) =>
      Drupal.formatPlural(count, 'used in 1 place', 'used in @count places'),
    deleteTitle: () => Drupal.t('Delete token'),
  },
  animation: {
    noun: () => Drupal.t('steps'),
    edit: () => Drupal.t('Edit keyframes'),
    copy: () => Drupal.t('Copy to new animation'),
    none: () => Drupal.t('worn by nothing'),
    some: (count) =>
      Drupal.formatPlural(count, 'worn in 1 place', 'worn in @count places'),
    deleteTitle: () => Drupal.t('Delete animation'),
  },
  font: {
    noun: () => Drupal.t('faces'),
    edit: () => Drupal.t('Edit faces'),
    copy: () => null,
    none: () => Drupal.t('named by nothing'),
    some: (count) =>
      Drupal.formatPlural(count, 'named in 1 place', 'named in @count places'),
    deleteTitle: () => Drupal.t('Delete font'),
  },
};

/**
 * A row's value summary: a token's display value, an animation's step
 * count, a font's face count.
 *
 * @param {object} row
 *   A skinSections() row.
 *
 * @return {string}
 */
export const rowSummary = (row) => {
  if (row.kind === 'animation') {
    return Drupal.formatPlural(row.offsets.length, '1 step', '@count steps');
  }
  if (row.kind === 'font') {
    return Drupal.formatPlural(row.faces.length, '1 face', '@count faces');
  }
  return row.value;
};

/**
 * The origin sentence an open card states, or null for the site's own
 * material (nothing shipped it; it needs no sentence).
 *
 * The provider is the management read's; before it lands (or without a
 * grant to read it) the sentence names a module. The revert tail joins
 * only while the revert gesture is live, so the sentence never promises a
 * gesture the actions do not offer.
 *
 * @param {object} row
 *   A skinSections() row.
 * @param {?object} management
 *   The usage read, or null.
 * @param {{revert: boolean}} gestures
 *   materialCardGestures().
 *
 * @return {?string}
 */
export const originSentence = (row, management, gestures) => {
  if (!row.shipped) {
    return null;
  }
  const noun = WORDS[row.kind].noun();
  const head = management?.provider
    ? Drupal.t('Shipped by !provider. Your !noun layers over it.', {
        '!provider': management.provider,
        '!noun': noun,
      })
    : Drupal.t('Shipped by a module. Your !noun layers over it.', {
        '!noun': noun,
      });
  return gestures.revert
    ? `${head} ${Drupal.t('Revert to defaults restores the shipped one.')}`
    : head;
};

/**
 * Why a shown identity gesture is disabled right now (materialCardGestures'
 * `locks`), in the card's words.
 *
 * @param {'rename'|'delete'} action
 * @param {?string} lock
 *   'shipped' or 'in-use'.
 * @param {?object} management
 *
 * @return {?string}
 */
export const lockReason = (action, lock, management) => {
  if (lock === null || lock === undefined) {
    return null;
  }
  const provider = management?.provider ?? Drupal.t('its module');
  if (lock === 'in-use') {
    return Drupal.t(
      'In use: deleting it needs the "delete skin materials in use" permission.',
    );
  }
  return action === 'rename'
    ? Drupal.t("Shipped by !provider; rename it in the module's skins file.", {
        '!provider': provider,
      })
    : Drupal.t('Shipped by !provider; withhold it from the AI instead.', {
        '!provider': provider,
      });
};

/**
 * The use line: the live count in the kind's words.
 *
 * @param {string} kind
 * @param {?object} management
 *
 * @return {string}
 */
export const usageText = (kind, management) => {
  if (management === null) {
    return Drupal.t('counting uses…');
  }
  return management.usages === 0
    ? WORDS[kind].none()
    : WORDS[kind].some(management.usages);
};

/**
 * The row: preview, name, value summary. A button (the card toggle).
 *
 * @param {object} props
 * @param {object} props.row
 *   A skinSections() row.
 * @param {boolean} props.open
 * @param {string} [props.schemeLabel]
 *   The guide's scheme axis label (the dark half's tooltip).
 * @param {Function} props.onToggle
 */
export const MaterialRow = ({ row, open, schemeLabel = null, onToggle }) => (
  <button
    type="button"
    className={`daedalus-stylish-skin__row daedalus-stylish-skin__row--${row.kind}${open ? ' daedalus-stylish-skin__row--open' : ''}`}
    title={row.spelling ?? undefined}
    aria-expanded={open}
    data-material={`${row.kind}:${row.reference}`}
    {...{ [`data-${row.kind}`]: row.reference }}
    onClick={onToggle}
  >
    {row.paint && (
      <Face
        className="daedalus-stylish-skin__row-face"
        paint={row.value}
        paintDark={row.valueDark}
      />
    )}
    <span
      className="daedalus-stylish-skin__row-name"
      style={row.kind === 'font' ? { fontFamily: row.spelling ?? undefined } : undefined}
    >
      {row.name}
    </span>
    <span className="daedalus-stylish-skin__row-value">{rowSummary(row)}</span>
    {row.kind === 'token' && row.valueDark !== null && (
      <span
        className="daedalus-stylish-skin__row-value daedalus-stylish-skin__row-value--dark"
        title={Drupal.t('The value under the !scheme scheme.', {
          '!scheme': schemeLabel ?? '',
        })}
      >
        {row.valueDark}
      </span>
    )}
  </button>
);

/**
 * The card. See the module docblock.
 *
 * @param {object} props
 * @param {object} props.row
 *   The open row (skinSections()), its record the store's.
 * @param {object} props.guide
 * @param {object} props.materials
 * @param {object} props.config
 *   The skin material gates (drupalSettings.daedalusStylish.skinMaterials).
 * @param {object} props.source
 * @param {Function} props.onCard
 *   Moves the open card: the renamed id, or null to close.
 * @param {Function} props.onCopy
 *   "Copy to new {kind}": hands the mint seed (skin-model.js copySeed).
 */
export const MaterialCard = ({
  row,
  guide,
  materials,
  config,
  source,
  onCard,
  onCopy,
}) => {
  const { record, kind } = row;
  const reference = record.id;
  const [busy, setBusy] = useState(false);
  // The rename: null closed; a string is the typed draft.
  const [nameDraft, setNameDraft] = useState(null);
  // The token's value editor: null closed; a string is the default draft.
  const [valueDraft, setValueDraft] = useState(null);
  const [darkDraft, setDarkDraft] = useState(null);
  // The animation's and the font's editing session.
  const [editing, setEditing] = useState(false);
  const [faceDrafts, setFaceDrafts] = useState([]);
  const [uploading, setUploading] = useState(0);
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
  const gestures = materialCardGestures(record, config, management);
  const words = WORDS[kind];
  const description =
    typeof record.description === 'string' ? record.description : '';
  const offered = record.offered !== false;

  // The token's editor by its scale's shape, and its pair split.
  const Editor = kind === 'token' ? tokenEditor(guide, record.group ?? null, row.shape) : null;
  const pair = kind === 'token' ? editablePair(guide, record.value) : null;
  const faces = kind === 'font' && Array.isArray(record.value?.faces) ? record.value.faces : [];

  useEffect(() => {
    if (!canManage) {
      return undefined;
    }
    let alive = true;
    fetchMaterialUsage(kind, reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [kind, reference, record, canManage]);

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
      mergedPair(record.value, { draft: next, darkDraft: nextDark, scheme: pair.scheme }),
    );
  };

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  const update = (changes, label) =>
    run(() =>
      updateMaterial(source, { kind, id: reference, ...changes }, label)
        .then(() => null)
        .catch(() => {
          // The op queue's source reported it; the editor stays open.
        }),
    );

  const commitValue = () => {
    const committed = mergedPair(record.value, {
      draft: valueDraft,
      darkDraft,
      scheme: pair.scheme,
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

  const commitFaces = () => {
    run(() =>
      updateMaterial(
        source,
        { kind: 'font', id: reference, faces: facesOf(faceDrafts) },
        Drupal.t('Updated the faces of the font !id', { '!id': reference }),
      )
        .then(() => {
          setEditing(false);
          source.notify?.(
            'status',
            Drupal.t('Updated the faces of !id; every stack naming it follows.', {
              '!id': reference,
            }),
          );
          return null;
        })
        .catch(() => {
          // The op queue's source reported it; the editor stays open.
        }),
    );
  };

  const commitDescription = (value) => {
    const next = value.trim();
    setDescriptionDraft(null);
    if (next === description) {
      return;
    }
    update(
      { description: next },
      Drupal.t('Described !id', { '!id': reference }),
    );
  };

  const rename = (value) => {
    const label = value.trim();
    setNameDraft(null);
    if (label === '' || label === record.label) {
      return;
    }
    run(() =>
      renameMaterial(kind, reference, label).then(async (response) => {
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
      fetchMaterialUsage(kind, reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Revert to defaults'),
          message: Drupal.t(
            'Drop the site\'s changes to !id and use what its module ships?',
            { '!id': reference },
          ),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new skin revision carrying the shipped record; nothing uses it right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new skin revision carrying the shipped record; this restyles 1 place site-wide.',
                  'A new skin revision carrying the shipped record; this restyles @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Revert'),
        });
        if (!confirmed) {
          return null;
        }
        return revertMaterial(kind, reference).then((response) => {
          source.applyResponse(response);
          source.notify?.(
            'status',
            Drupal.t('!id wears the shipped record again.', { '!id': reference }),
          );
          return null;
        });
      }),
    );
  };

  // Delete, always safely: for a used material the dialog is ONE action,
  // "Rewrite N references, then delete" (the batch the server stages, the
  // client drives). There is no "delete anyway".
  const destroy = () => {
    run(() =>
      fetchMaterialUsage(kind, reference).then(async ({ usages }) => {
        const rewrite = {
          token: [
            'Rewrite 1 reference to its literal value, then delete: the place keeps its current look, then the token is deleted.',
            'Rewrite @count references to their literal value, then delete: every place keeps its current look, then the token is deleted.',
          ],
          animation: [
            'Rewrite 1 reference to none, then delete: that place stops animating, then the animation is deleted.',
            'Rewrite @count references to none, then delete: those places stop animating, then the animation is deleted.',
          ],
          font: [
            'Remove the family from 1 font-family stack, then delete: that place falls back to its next family, then the font is deleted.',
            'Remove the family from @count font-family stacks, then delete: those places fall back to their next family, then the font is deleted.',
          ],
        }[kind];
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: words.deleteTitle(),
          message: Drupal.t('Delete !id?', { '!id': reference }),
          detail:
            usages === 0
              ? Drupal.t('Nothing uses it; it is deleted immediately.')
              : Drupal.formatPlural(usages, rewrite[0], rewrite[1]),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Rewrite and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteMaterial(kind, reference).then(async (result) => {
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

  const openEditor = () => {
    if (kind === 'token') {
      setValueDraft(pair.draft);
      setDarkDraft(pair.darkDraft);
      return;
    }
    if (kind === 'font') {
      setFaceDrafts(faces.map((face, index) => storedFaceDraft(face, index + 1)));
    }
    setEditing(true);
  };
  const editingNow = kind === 'token' ? valueDraft !== null : editing;
  const origin = originSentence(row, management, gestures);
  const facesHold = kind === 'font' ? facesBlocked(faceDrafts, uploading) : null;

  return (
    <div
      className={`daedalus-stylish-library-card daedalus-stylish-material-card daedalus-stylish-material-card--${kind}`}
      data-material={`${kind}:${reference}`}
    >
      <div className="daedalus-stylish-library-card__name">
        {nameDraft !== null ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input daedalus-stylish-material-card__name-input"
            aria-label={Drupal.t('New name')}
            value={nameDraft}
            disabled={busy}
            autoFocus
            title={Drupal.t(
              'The new name. Every reference to the material is respelled to follow it.',
            )}
            onInput={(event) => setNameDraft(event.target.value)}
            onBlur={(event) => rename(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                rename(event.target.value);
              } else if (event.key === 'Escape') {
                setNameDraft(null);
              }
            }}
          />
        ) : (
          <span
            className="daedalus-stylish-library-card__label"
            style={kind === 'font' ? { fontFamily: row.spelling ?? undefined } : undefined}
          >
            {row.name}
          </span>
        )}
        {row.spelling && (
          <code
            className="daedalus-stylish-material-card__spelling"
            title={Drupal.t('The reference a style is written as.')}
          >
            {row.spelling}
          </code>
        )}
      </div>
      {origin !== null && (
        <div className="daedalus-stylish-material-card__origin">{origin}</div>
      )}
      {!gestures.editValue && (
        <div className="daedalus-stylish-material-card__reason">
          {Drupal.t(
            "Your account cannot update skin materials; the skin is read-only here.",
          )}
        </div>
      )}
      {gestures.offer ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={offered}
          disabled={busy}
          label={Drupal.t('Offer on rows and to the AI')}
          title={Drupal.t(
            'Off, the rows leave it out of their offer and the AI is not told about it; it still applies wherever it is referenced.',
          )}
          onChange={(next) =>
            update(
              { offered: next },
              next
                ? Drupal.t('Offered !id', { '!id': reference })
                : Drupal.t('Withheld !id', { '!id': reference }),
            )
          }
        />
      ) : (
        !offered && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Off the rows and kept from the AI; its reference still resolves.',
            )}
          >
            {Drupal.t('not offered')}
          </span>
        )
      )}
      {gestures.editDescription ? (
        <textarea
          className="daedalus-ui-input daedalus-stylish-material-card__description"
          rows={2}
          placeholder={Drupal.t('Describe when to reach for it…')}
          value={descriptionDraft ?? description}
          disabled={busy}
          onInput={(event) => setDescriptionDraft(event.target.value)}
          onBlur={(event) => commitDescription(event.target.value)}
        />
      ) : (
        description !== '' && (
          <div className="daedalus-stylish-material-card__description-text">
            {description}
          </div>
        )
      )}
      {canManage && (
        <div className="daedalus-stylish-library-card__meta">
          <span>{usageText(kind, management)}</span>
        </div>
      )}
      {/* The kind's value slot: the editor while editing, the closed
          anatomy otherwise (a token's value is its row's; an animation
          shows its steps in one line, a font its faces with a specimen). */}
      {kind === 'token' && valueDraft !== null && (
        <div className="daedalus-stylish-material-card__editor">
          {pair.conditions.length > 0 && (
            <div className="daedalus-stylish-material-card__conditions">
              {Drupal.t('The !conditions entries are kept as they are.', {
                '!conditions': pair.conditions.join(', '),
              })}
            </div>
          )}
          <SchemePairEditor
            guide={guide}
            materials={materials}
            Editor={Editor}
            draft={valueDraft}
            darkDraft={darkDraft}
            scheme={pair.scheme}
            exclude={row.spelling}
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
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__save"
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
              className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__cancel"
              disabled={busy}
              onClick={() => {
                restore();
                setValueDraft(null);
              }}
            >
              {Drupal.t('Cancel')}
            </button>
          </div>
        </div>
      )}
      {kind === 'animation' &&
        (editing ? (
          <KeyframesSession
            animation={record}
            guide={guide}
            materials={materials}
            source={source}
            onClose={() => setEditing(false)}
          />
        ) : (
          <code className="daedalus-stylish-material-card__summary">
            {keyframesSummary(record.value)}
          </code>
        ))}
      {kind === 'font' &&
        (editing ? (
          <div className="daedalus-stylish-material-card__editor">
            <FacesEditor
              drafts={faceDrafts}
              onDrafts={setFaceDrafts}
              onUploading={setUploading}
              source={source}
              disabled={busy}
            />
            <div className="daedalus-stylish-library-card__actions">
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__save"
                disabled={busy || facesHold !== null}
                title={
                  facesHold ??
                  Drupal.t(
                    'Save the faces; every stack naming this family draws with them.',
                  )
                }
                onClick={commitFaces}
              >
                {Drupal.t('Save faces')}
              </button>
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__cancel"
                disabled={busy}
                onClick={() => setEditing(false)}
              >
                {Drupal.t('Cancel')}
              </button>
            </div>
          </div>
        ) : (
          <FaceList faces={faces} spelling={row.spelling} />
        ))}
      {!editingNow && (
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
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__edit"
                disabled={busy}
                title={Drupal.t(
                  'Edit it; saving restyles every place referencing this material.',
                )}
                onClick={openEditor}
              >
                {words.edit()}
              </button>
            )}
            {gestures.copy && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__copy"
                disabled={busy}
                title={Drupal.t(
                  'Start a new one from this one: the same value under a name of your own, yours to rename and delete.',
                )}
                onClick={() => onCopy(copySeed(record))}
              >
                {words.copy()}
              </button>
            )}
            {gestures.revert && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__revert"
                disabled={busy}
                title={Drupal.t(
                  "Drop the site's changes; the record the module ships shows through.",
                )}
                onClick={revert}
              >
                {Drupal.t('Revert to defaults')}
              </button>
            )}
            {(gestures.rename || gestures.locks.rename !== null) && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-material-card__rename"
                disabled={busy || !gestures.rename}
                title={
                  lockReason('rename', gestures.locks.rename, management) ??
                  Drupal.t(
                    'Rename it. The name is the tail of its reference, so every reference is respelled.',
                  )
                }
                onClick={() => setNameDraft(row.name)}
              >
                {Drupal.t('Rename')}
              </button>
            )}
            {(gestures.delete || gestures.locks.delete !== null) && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--delete daedalus-stylish-material-card__delete"
                disabled={busy || !gestures.delete}
                title={lockReason('delete', gestures.locks.delete, management) ?? undefined}
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
