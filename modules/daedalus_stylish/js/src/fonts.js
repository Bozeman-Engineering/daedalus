import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { deleteFont, fetchFontUsage, uploadFontFile } from './fonts-api.js';
import {
  respondedMaterial,
  saveMaterial,
  updateMaterial,
} from './material-gestures.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { fontSpelling } from './materials.js';
import { AiOfferToggle, isOffered } from './ai-offer.js';
import {
  duplicateFace,
  faceDraft,
  faceLabel,
  facesOf,
  fontCardGestures,
  fontRows,
} from './fonts-model.js';

/**
 * The specimen line every face and card shows: a pangram in the family,
 * at the face's own weight and style, so the tab shows what a stack
 * naming the family renders as (the delivered @font-face, the compiled
 * file's or the session's delivery, is what the browser draws with).
 */
const SPECIMEN = 'The quick brown fox jumps over the lazy dog 0123456789';

/**
 * The faces list: one row per face, its label and its specimen.
 *
 * Read-only on the card (a font's faces are its files; changing them is
 * a re-mint) and the drafts' summary on the mint card.
 */
const FaceList = ({ faces, spelling }) => (
  <ul className="daedalus-stylish-fonts__faces">
    {faces.map((face) => (
      <li
        key={`${face.weight}/${face.style}`}
        className="daedalus-stylish-fonts__face"
      >
        <span className="daedalus-ui-chip daedalus-stylish-fonts__face-label">
          {faceLabel(face)}
        </span>
        <span
          className="daedalus-stylish-fonts__specimen"
          style={{
            fontFamily: spelling ?? undefined,
            fontWeight: face.weight,
            fontStyle: face.style,
          }}
        >
          {SPECIMEN}
        </span>
      </li>
    ))}
  </ul>
);

/**
 * The lifecycle card, inline under its open list row.
 *
 * The animation card's twin for the type material: rename, the
 * description and the AI opt-out (update_material, kind font), the
 * faces with their specimens, the live use count, and delete via the
 * shared dialog. Locked fonts (the distro lock, the record's `updatable`
 * flag) render read-only. The gesture roster is data (fonts-model.js
 * fontCardGestures): presence means permitted AND applicable. The record
 * is the store's (the prop follows every delta); the management facts
 * beyond it (the live reference count, the shipped facts) are one
 * per-open read of the usage route, refreshed after each gesture and
 * again at the delete prompt. The delete stays a route whose response
 * carries the materials delta, handed to source.applyResponse so the
 * store learns it in both windows.
 */
const FontCard = ({ font, config, source, onCard }) => {
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState(null);
  const [descriptionDraft, setDescriptionDraft] = useState(null);
  const [progress, setProgress] = useState(null);
  const [management, setManagement] = useState(null);

  const reference = font.id;
  const gestures = fontCardGestures(font, config, management);
  const spelling = fontSpelling(font);
  const faces = Array.isArray(font.value?.faces) ? font.value.faces : [];

  useEffect(() => {
    let alive = true;
    fetchFontUsage(reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [reference, font]);

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  const update = (changes, label) =>
    run(() => updateMaterial(source, { kind: 'font', id: reference, ...changes }, label).then(() => null));

  const rename = (value) => {
    const label = value.trim();
    setDraft(null);
    if (label === '' || label === font.label) {
      return;
    }
    update(
      { label },
      Drupal.t('Renamed the font !label', { '!label': font.label }),
    );
  };

  const describe = (value) => {
    const description = value.trim();
    setDescriptionDraft(null);
    if (description === (font.description ?? '')) {
      return;
    }
    update(
      { description },
      Drupal.t('Described the font !label', { '!label': font.label }),
    );
  };

  // Delete, always safely: for a named font the dialog is ONE action,
  // "Remove the family from N stacks, then delete" (the batch: every
  // stack naming it loses the family, then the entity goes; the client
  // drives the returned core batch URL). There is no "delete anyway".
  const destroy = () => {
    run(() =>
      fetchFontUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: Drupal.t('Delete font'),
          message: Drupal.t('Delete "!label"?', { '!label': font.label }),
          detail:
            usages === 0
              ? Drupal.t('Nothing names it; it is deleted immediately.')
              : Drupal.formatPlural(
                  usages,
                  'Remove the family from 1 font-family stack, then delete: that place falls back to its next family, then the font is deleted.',
                  'Remove the family from @count font-family stacks, then delete: those places fall back to their next family, then the font is deleted.',
                ),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Remove and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteFont(reference).then(async (result) => {
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
            Drupal.t('Deleted "!label".', { '!label': font.label }),
          );
          return null;
        });
      }),
    );
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-fonts-card">
      <div className="daedalus-stylish-library-card__name">
        {gestures.rename ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input"
            value={draft ?? font.label}
            disabled={busy}
            title={Drupal.t(
              'Rename the font. Its family and identity never change, so every stack naming it follows.',
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
          <span className="daedalus-stylish-library-card__label">{font.label}</span>
        )}
        <code
          className="daedalus-stylish-palette-card__variable daedalus-stylish-fonts-card__spelling"
          title={Drupal.t('The family a font-family stack names.')}
        >
          {spelling}
        </code>
        {management?.provider && (
          <span
            className="daedalus-stylish-library-card__scope"
            title={Drupal.t('The module whose fonts file ships this font.')}
          >
            {management.provider}
          </span>
        )}
      </div>
      {/* The AI opt-out (ai-offer.js): the toggle when the card can
          update (update_material, kind font), a chip when read-only and
          withheld; the description shows only while offered. */}
      {gestures.edit ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={isOffered(font)}
          disabled={busy}
          onChange={(next) =>
            update(
              { offered: next },
              next
                ? Drupal.t('Offered the font !label to the AI', {
                    '!label': font.label,
                  })
                : Drupal.t('Kept the font !label from the AI', {
                    '!label': font.label,
                  }),
            )
          }
        />
      ) : (
        !isOffered(font) && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Kept from the AI: it leaves the brief and the schema but still loads and applies.',
            )}
          >
            {Drupal.t('not offered to the AI')}
          </span>
        )
      )}
      {isOffered(font) &&
        (gestures.edit ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__description daedalus-ui-input"
            placeholder={Drupal.t('When to reach for it (optional)…')}
            value={descriptionDraft ?? font.description ?? ''}
            disabled={busy}
            onInput={(event) => setDescriptionDraft(event.target.value)}
            onBlur={(event) => describe(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                describe(event.target.value);
              }
            }}
          />
        ) : (
          font.description && (
            <p className="daedalus-stylish-library-card__description">
              {font.description}
            </p>
          )
        ))}
      <div className="daedalus-stylish-library-card__meta">
        <span>
          {management === null
            ? Drupal.t('counting uses…')
            : management.usages === 0
              ? Drupal.t('named by nothing')
              : Drupal.formatPlural(
                  management.usages,
                  'named in 1 place',
                  'named in @count places',
                )}
        </span>
      </div>
      <FaceList faces={faces} spelling={spelling} />
      {progress && (
        <div className="daedalus-stylish-library-card__progress">
          {Drupal.t('Rewriting… !percent%', {
            '!percent': progress.percentage ?? '0',
          })}
        </div>
      )}
      {gestures.delete && (
        <div className="daedalus-stylish-library-card__actions">
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--delete"
            disabled={busy}
            onClick={destroy}
          >
            {Drupal.t('Delete…')}
          </button>
        </div>
      )}
    </div>
  );
};

/**
 * The mint card: the Fonts face's "New font" gesture, from files.
 *
 * Files first: each woff2 or woff picked uploads through the bytes door
 * (fonts-api.js uploadFontFile, the daedalus_ui file lane narrowed to
 * the font extensions) and lands as a face draft carrying the uuid the
 * upload answered, with the weight and style the file's name suggests
 * (fonts-model.js guessFace) open to correction. Then the label (the
 * family defaults to it), an optional family, the description and the
 * AI opt-out; Save submits the same save_material op (kind font) the AI
 * submits, naming the files by uuid. The op queue lands the response's
 * materials delta on the store before the promise resolves (the leader
 * relays it to the satellite), so the family joins the Font row's
 * chips in both windows and the leader's font delivery loads its faces
 * before any reload. A draft repeating another's weight and style is
 * refused here before the round trip (the validator's
 * DUPLICATE_FONT_FACE rule).
 */
const MintCard = ({ source, onClose }) => {
  const [label, setLabel] = useState('');
  const [family, setFamily] = useState('');
  const [description, setDescription] = useState('');
  // The AI opt-out (ai-offer.js): born offered, the default; the mint
  // sends the key only when off.
  const [offered, setOffered] = useState(true);
  const [drafts, setDrafts] = useState([]);
  const [uploading, setUploading] = useState(0);
  const [minting, setMinting] = useState(false);
  const nextKey = useRef(1);

  const duplicate = duplicateFace(drafts);

  const pick = (event) => {
    const files = Array.from(event.target.files ?? []);
    // The input resets so the same file can be picked again after a
    // removal.
    event.target.value = '';
    files.forEach((file) => {
      setUploading((count) => count + 1);
      gesture(source, () => uploadFontFile(file))
        .then((upload) => {
          const key = nextKey.current++;
          setDrafts((current) => [...current, faceDraft(upload, key)]);
        })
        .catch(() => null)
        .finally(() => setUploading((count) => count - 1));
    });
  };

  const change = (key, patch) =>
    setDrafts((current) =>
      current.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)),
    );
  const remove = (key) =>
    setDrafts((current) => current.filter((draft) => draft.key !== key));

  const mint = () => {
    setMinting(true);
    const name = label.trim();
    saveMaterial(
      source,
      {
        kind: 'font',
        label: name,
        family:
          family.trim() === '' || family.trim() === name
            ? undefined
            : family.trim(),
        faces: facesOf(drafts),
        description: description.trim() === '' ? undefined : description.trim(),
        offered: offered ? undefined : false,
      },
      Drupal.t('Added the font !label', { '!label': name }),
    )
      .then((record) => {
        source.notify?.(
          'status',
          Drupal.t('Added the !label font; name it in a stack as !spelling.', {
            '!label': record.label,
            '!spelling': fontSpelling(record) ?? record.id,
          }),
        );
        onClose();
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the card stays open for a correction.
      })
      .finally(() => setMinting(false));
  };

  const blocked =
    minting ||
    uploading > 0 ||
    label.trim() === '' ||
    drafts.length === 0 ||
    duplicate !== -1;
  const blockedTitle =
    drafts.length === 0
      ? Drupal.t('Add at least one font file.')
      : duplicate !== -1
        ? Drupal.t('Two faces share a weight and style; give each face its own.')
        : label.trim() === ''
          ? Drupal.t('Name the font.')
          : Drupal.t(
              "Mint the font: a self-hosted family that joins the site's materials; any stack can name it.",
            );

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-fonts-mint">
      <label className="daedalus-stylish-fonts-mint__files">
        <span>{Drupal.t('Font files (woff2, woff)')}</span>
        <input
          type="file"
          className="daedalus-stylish-fonts-mint__input"
          accept=".woff2,.woff,font/woff2,font/woff"
          multiple
          aria-label={Drupal.t('Font files')}
          disabled={minting}
          onChange={pick}
        />
      </label>
      {uploading > 0 && (
        <div className="daedalus-stylish-library-card__progress">
          {Drupal.formatPlural(uploading, 'Uploading 1 file…', 'Uploading @count files…')}
        </div>
      )}
      {drafts.length > 0 && (
        <ul className="daedalus-stylish-fonts-mint__drafts">
          {drafts.map((draft, index) => (
            <li
              key={draft.key}
              className={`daedalus-stylish-fonts-mint__draft${index === duplicate ? ' daedalus-stylish-fonts-mint__draft--duplicate' : ''}`}
            >
              <span
                className="daedalus-stylish-fonts-mint__file"
                title={draft.name}
              >
                {draft.name}
              </span>
              <input
                type="text"
                className="daedalus-ui-input daedalus-stylish-fonts-mint__weight"
                aria-label={Drupal.t('Face weight')}
                title={Drupal.t('The weight: 100 to 900, a keyword, or a range like "100 900" for a variable font.')}
                value={draft.weight}
                onInput={(event) => change(draft.key, { weight: event.target.value })}
              />
              <select
                className="daedalus-ui-input daedalus-stylish-fonts-mint__style"
                aria-label={Drupal.t('Face style')}
                value={draft.style}
                onChange={(event) => change(draft.key, { style: event.target.value })}
              >
                <option value="normal">{Drupal.t('normal')}</option>
                <option value="italic">{Drupal.t('italic')}</option>
              </select>
              <button
                type="button"
                className="daedalus-ui-chip daedalus-stylish-fonts-mint__remove"
                title={Drupal.t('Drop this face.')}
                onClick={() => remove(draft.key)}
              >
                {Drupal.t('remove')}
              </button>
            </li>
          ))}
        </ul>
      )}
      <input
        type="text"
        className="daedalus-stylish-library-card__label daedalus-ui-input"
        aria-label={Drupal.t('Font label')}
        placeholder={Drupal.t('Font name…')}
        value={label}
        onInput={(event) => setLabel(event.target.value)}
      />
      <input
        type="text"
        className="daedalus-ui-input daedalus-stylish-fonts-mint__family"
        aria-label={Drupal.t('Font family')}
        placeholder={Drupal.t('Family name, when it differs from the label (optional)…')}
        title={Drupal.t('The name font-family stacks reference; the label unless you say otherwise.')}
        value={family}
        onInput={(event) => setFamily(event.target.value)}
      />
      {offered && (
        <input
          type="text"
          className="daedalus-ui-input daedalus-stylish-fonts-mint__description"
          placeholder={Drupal.t('When to reach for it (optional)…')}
          value={description}
          onInput={(event) => setDescription(event.target.value)}
        />
      )}
      <AiOfferToggle offered={offered} onChange={setOffered} />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-fonts-mint__save"
          disabled={blocked}
          title={blockedTitle}
          onClick={mint}
        >
          {Drupal.t('Save font')}
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
 * The Fonts face: the catalog's type section.
 *
 * The animations face's sibling for the type material: site-wide
 * content, no selection needed. Search, the flat font list with cards
 * inline, and the mint card. Every row is the materials store's record
 * (the roster this window was handed; a mint, edit or delete in either
 * window re-renders it through the store's delta): nothing fetches a
 * list, and the facts a card needs beyond its record are its own
 * per-open read.
 */
export const FontsView = ({ materials, config, source }) => {
  const [search, setSearch] = useState('');
  const [card, setCard] = useState(null);
  const [minting, setMinting] = useState(false);

  const fonts = materials.ofKind('font');
  const rows = fontRows(fonts, { search });

  return (
    <div className="daedalus-stylish-library daedalus-stylish-fonts">
      <div className="daedalus-stylish-library__controls">
        <input
          type="search"
          className="daedalus-stylish-library__search daedalus-ui-input"
          placeholder={Drupal.t('Search fonts…')}
          value={search}
          onInput={(event) => setSearch(event.target.value)}
        />
        {Boolean(config?.canCreate) && !minting && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-palette__mint-open daedalus-stylish-fonts__mint-open"
            title={Drupal.t(
              "Mint a new font from woff2 or woff files: a self-hosted family that joins the site's materials; any stack can name it.",
            )}
            onClick={() => setMinting(true)}
          >
            {Drupal.t('+ New font')}
          </button>
        )}
      </div>
      {minting && <MintCard source={source} onClose={() => setMinting(false)} />}
      {rows.length === 0 ? (
        <div className="daedalus-stylish-inspector__empty">
          {fonts.length === 0
            ? Drupal.t(
                "No fonts exist yet. Mint one here from its files; minted fonts join the site's materials and any font-family stack can name them.",
              )
            : Drupal.t('No fonts match.')}
        </div>
      ) : (
        <div className="daedalus-stylish-library__list">
          {rows.map((row) => {
            const record = fonts.find(
              (candidate) => candidate.id === row.reference,
            );
            return (
              <Fragment key={row.reference}>
                <button
                  type="button"
                  className={`daedalus-stylish-library__row${card === row.reference ? ' daedalus-stylish-library__row--open' : ''}`}
                  data-font={row.reference}
                  aria-expanded={card === row.reference}
                  onClick={() =>
                    setCard(card === row.reference ? null : row.reference)
                  }
                >
                  <span
                    className="daedalus-stylish-library__name"
                    style={{ fontFamily: row.spelling ?? undefined }}
                  >
                    {row.label}
                  </span>
                  <span
                    className="daedalus-ui-chip daedalus-stylish-library__axis"
                    title={Drupal.t('The faces this font ships.')}
                  >
                    {row.faces.join(' · ')}
                  </span>
                  {row.shipped && (
                    <span
                      className="daedalus-ui-chip daedalus-stylish-library__shipped"
                      title={Drupal.t(
                        'Shipped by a module; read-only. Mint your own font to get an editable one.',
                      )}
                    >
                      {Drupal.t('shipped')}
                    </span>
                  )}
                </button>
                {card === row.reference && record && (
                  <FontCard
                    font={record}
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
    </div>
  );
};
