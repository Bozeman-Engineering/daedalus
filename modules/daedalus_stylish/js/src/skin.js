import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  chooseSkin,
  copySkin,
  fetchSkinRevisions,
  fetchSkins,
  restoreSkinRevision,
  revertSkin,
} from './skin-api.js';
import {
  copyLabel,
  copySources,
  restoreScope,
  skinCardGestures,
  skinCardReadable,
  skinCardRows,
  wornSkin,
} from './skin-card-model.js';
import { saveMaterial } from './material-gestures.js';
import { gesture } from './gesture-transport.js';
import { schemeCondition } from './style-guide.js';
import { animationSpelling, fontSpelling } from './materials.js';
import { AiOfferToggle } from './ai-offer.js';
import { RevisionList, revisionDate } from './revision-list.js';
import { useMenu } from './menu.js';
import { SchemePairEditor, tokenEditor } from './token-editors.js';
import { KeyframesEditor, keyedStops } from './keyframes-editor.js';
import { FacesEditor, facesBlocked } from './faces-editor.js';
import { MaterialCard, MaterialRow } from './material-card.js';
import {
  SEED_STEPS,
  editablePair,
  facesOf,
  fontName,
  mergedPair,
  mintableKinds,
  skinSections,
  stepsOf,
  tokenNameError,
  tokenSeed,
} from './skin-model.js';

/**
 * A refused member's reason, in the card's words.
 */
export const reasonText = (row) => {
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
 * The skin card, over the Skin tab: the look the site wears.
 *
 * The lifecycle card for the skin itself: the worn skin's identity and
 * where the site stands with it (shipped by a module with the site's
 * changes a layer over it, or the site's own), the design system's other
 * member skins with "wear" on those the system may wear and why not on
 * the rest, copy as my skin (the distro lock's fork gesture and the
 * first-skin door in one: any skin on the site, picked from a source
 * select that preselects the worn one, copied as a skin with no provider
 * carrying the merged look into the ACTIVE design system; a site's own
 * unit with no default takes its first copy as its default_skin and
 * wears it at once), the revision history with restore, and the site
 * layer's revert ("Use shipped skin"). Every gesture that changes what
 * the site wears is a route whose response carries the materials delta,
 * handed to source.applyResponse so both windows' stores learn the
 * swapped or restored material set, then the skin's delivery refreshes
 * (source.refreshDefaults: the defaults map for the panel's stacks and
 * the recompiled CSS on the canvas), since the worn defaults changed
 * with it; a copy that wore nothing (`worn` false) only refetches the
 * read, since choosing is then the next gesture.
 *
 * The card has one management read (the skins route: skin-card-model.js
 * reads everything off it), fetched at mount, on open and after every
 * gesture, never off the guide payload: which skin is worn changes with
 * a choice and a copy adds a member. The read admits every grade that
 * touches the skin, so a material editor sees which skin they edit; the
 * gestures are the administer grade's alone (each restyles the whole
 * site), and without it the card is the worn skin's name and standing.
 */
const SkinCard = ({ skin: skinConfig, source }) => {
  const [read, setRead] = useState(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [revisions, setRevisions] = useState(null);
  // The copy draft: null closed; {source, label} is picking and typing.
  const [copyDraft, setCopyDraft] = useState(null);
  // Bumped after every gesture: the read and the history refetch.
  const [generation, setGeneration] = useState(0);

  const worn = wornSkin(read);
  const rows = skinCardRows(read);
  const gestures = skinCardGestures(read, skinConfig);
  const wornName = worn?.machine_name ?? null;
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

  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

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
          'The whole site restyles to its tokens, animations, fonts and defaults. The skin worn now keeps its revisions and can be worn again.',
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
          'A new skin revision with an empty site layer: every material the site added or changed and every default it set goes; the whole site restyles.',
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
    <div className="daedalus-stylish-skin-card">
      <button
        type="button"
        className={`daedalus-stylish-skin-card__open${open ? ' daedalus-stylish-skin-card__open--open' : ''}`}
        aria-expanded={open}
        title={Drupal.t(
          'The skin the site wears: its standing, the other skins of the design system, its revisions.',
        )}
        onClick={() => setOpen(!open)}
      >
        <span className="daedalus-stylish-skin-card__wearing">
          {Drupal.t('Wearing')}
        </span>
        <span className="daedalus-stylish-skin-card__name">
          {worn === null ? Drupal.t('no skin') : worn.label}
        </span>
        <span className="daedalus-stylish-skin-card__more">
          {gestures.revisions || gestures.copy
            ? Drupal.t('revisions · copy as mine')
            : Drupal.t('standing')}
        </span>
      </button>
      {open && (
        <div className="daedalus-stylish-library-card daedalus-stylish-skin-card__body">
          {worn !== null && (
            <div className="daedalus-stylish-library-card__name">
              <span className="daedalus-stylish-library-card__label">
                {worn.label}
              </span>
              <code
                className="daedalus-stylish-material-card__spelling"
                title={Drupal.t('The machine name the skins lane syncs by.')}
              >
                {worn.machine_name}
              </code>
            </div>
          )}
          <div className="daedalus-stylish-skin-card__reason">
            {worn === null
              ? read.design_system == null
                ? Drupal.t('No design system is active, so nothing is worn.')
                : rows.length === 0
                  ? Drupal.t(
                      "The active design system has no skin yet. Copy one in below, any skin on the site: it becomes the design system's default and the site wears it.",
                    )
                  : Drupal.t(
                      'The active design system wears no skin: nothing it names is a published, conforming member.',
                    )
              : worn.provider == null
                ? Drupal.t('A skin this site made; every edit lands on it directly.')
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
            <div className="daedalus-stylish-skin-card__members">
              <div className="daedalus-stylish-skin__section-label">
                {Drupal.t('Skins of this design system')}
              </div>
              {rows.map((row) => (
                <div
                  key={row.reference}
                  className={`daedalus-stylish-skin-card__member${row.worn ? ' daedalus-stylish-skin-card__member--worn' : ''}`}
                  data-skin={row.reference}
                >
                  <span className="daedalus-stylish-skin-card__member-name">
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
                      className="daedalus-ui-chip daedalus-stylish-skin-card__wear"
                      disabled={busy}
                      title={Drupal.t(
                        'Wear this skin: the whole site restyles to its materials and defaults.',
                      )}
                      onClick={() => choose(row)}
                    >
                      {Drupal.t('wear')}
                    </button>
                  ) : row.reason !== null ? (
                    <span
                      className="daedalus-stylish-skin-card__member-reason"
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
            <div className="daedalus-stylish-skin-card__copy">
              <div className="daedalus-stylish-skin-card__copy-identity">
                <select
                  className="daedalus-ui-input daedalus-ui-input--select daedalus-stylish-skin-card__copy-source"
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
                  className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-card__copy-save"
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
                    className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-card__copy-open"
                    disabled={busy}
                    title={Drupal.t(
                      'Copy a skin as one of your own into the active design system: no module owns the copy, so every material and default on it is yours to change; it carries the look as it shows on the site now. Pick any skin on the site; the one worn is picked first.',
                    )}
                    onClick={() => draftCopy(sources[0].skins[0].reference)}
                  >
                    {Drupal.t('Copy as my skin')}
                  </button>
                )}
                {gestures.revert && (
                  <button
                    type="button"
                    className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-card__revert"
                    disabled={busy}
                    title={Drupal.t(
                      'Drop every material and default this site added or changed; the skin wears what its module ships, whole.',
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
 * The token mint card: "+ New" › Token, and "Copy to new token".
 *
 * The scale (the guide's declared scales; a token is minted on one), the
 * name (the custom property's tail, checked against the token grammar
 * and the roster before the round trip), the scale shape's editor over a
 * seeded draft with the scheme pair, the description and the offered
 * flag: the same save_material op (kind token) the AI submits, through
 * the source's batch commit. A copy seeds the scale, the value and the
 * description from the copied token (skin-model.js copySeed) and leaves
 * the name to the person. The op queue lands the response's materials
 * delta on the store before the promise resolves, so the token is a stop
 * on every basic row bound to its scale, in every picker of both windows,
 * and its custom property resolves on the canvas before any reload.
 */
const TokenMintCard = ({ guide, materials, source, seed = null, onClose }) => {
  const scales = Object.entries(guide?.tokenScales ?? {}).map(
    ([id, declaration]) => ({
      id,
      label: declaration?.label ?? id,
      shape: declaration?.shape ?? null,
    }),
  );
  const seededScale =
    seed?.scale && scales.some((entry) => entry.id === seed.scale)
      ? seed.scale
      : (scales[0]?.id ?? '');
  const seededPair = seed ? editablePair(guide, seed.value) : null;
  const [scale, setScale] = useState(seededScale);
  const [name, setName] = useState('');
  const [value, setValue] = useState(() =>
    seededPair
      ? seededPair.draft
      : tokenSeed(
          scales.find((entry) => entry.id === seededScale)?.shape,
          guide,
          materials,
        ),
  );
  const [darkValue, setDarkValue] = useState(seededPair?.darkDraft ?? null);
  const [description, setDescription] = useState(seed?.description ?? '');
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
    <div className="daedalus-stylish-library-card daedalus-stylish-skin-mint daedalus-stylish-skin-mint--token">
      <div className="daedalus-stylish-skin-mint__identity">
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
            setValue(tokenSeed(next?.shape, guide, materials));
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
          className="daedalus-stylish-library-card__label daedalus-ui-input daedalus-stylish-skin-mint__name"
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
        <div className="daedalus-stylish-skin-mint__error">
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
        className="daedalus-ui-input daedalus-stylish-material-card__description"
        rows={2}
        placeholder={Drupal.t('Describe when to reach for this token…')}
        value={description}
        onInput={(event) => setDescription(event.target.value)}
      />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-mint__save"
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
 * The animation mint card: "+ New" › Animation, and "Copy to new
 * animation".
 *
 * Name, description, the offered flag and the keyframes editor over the
 * seed steps (a fade, or the copied animation's steps): the same
 * save_material op (kind animation) the AI submits. No canvas preview
 * here: nothing wears an animation before it exists. The op queue lands
 * the response's materials delta on the store before the promise
 * resolves (the leader relays it to the satellite), so the animation
 * completes on every motion row of both windows and the leader's preview
 * sheet carries its @keyframes before any reload.
 */
const AnimationMintCard = ({ guide, materials, source, seed = null, onClose }) => {
  const [label, setLabel] = useState('');
  const [description, setDescription] = useState(seed?.description ?? '');
  const [offered, setOffered] = useState(true);
  const [stops, setStops] = useState(() => keyedStops(seed?.steps ?? SEED_STEPS));
  const [selected, setSelected] = useState(0);
  const [lint, setLint] = useState(() => new Map());
  const [minting, setMinting] = useState(false);
  const latest = useRef(stops);
  latest.current = stops;

  const invalid = [...lint.values()].some(Boolean);
  const noteErrors = (key, hasErrors) =>
    setLint((current) => {
      const next = new Map(current);
      next.set(key, hasErrors);
      return next;
    });

  const mint = () => {
    setMinting(true);
    saveMaterial(
      source,
      {
        kind: 'animation',
        label: label.trim(),
        description: description.trim() === '' ? undefined : description.trim(),
        offered: offered ? undefined : false,
        steps: stepsOf(latest.current),
      },
      Drupal.t('Added the animation !label', { '!label': label.trim() }),
    )
      .then((record) => {
        source.notify?.(
          'status',
          Drupal.t('Added the !label animation; wear it as !spelling.', {
            '!label': record.label,
            '!spelling': animationSpelling(record) ?? record.id,
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
    <div className="daedalus-stylish-library-card daedalus-stylish-skin-mint daedalus-stylish-skin-mint--animation">
      <input
        type="text"
        className="daedalus-stylish-library-card__label daedalus-ui-input daedalus-stylish-skin-mint__name"
        placeholder={Drupal.t('Animation name…')}
        aria-label={Drupal.t('Animation name')}
        value={label}
        onInput={(event) => setLabel(event.target.value)}
      />
      <input
        type="text"
        className="daedalus-ui-input daedalus-stylish-skin-mint__description"
        placeholder={Drupal.t('What it is for (optional)…')}
        value={description}
        onInput={(event) => setDescription(event.target.value)}
      />
      <AiOfferToggle offered={offered} onChange={setOffered} />
      <KeyframesEditor
        guide={guide}
        materials={materials}
        stops={stops}
        selected={selected}
        onStops={(next) => {
          const resolved =
            typeof next === 'function' ? next(latest.current) : next;
          latest.current = resolved;
          setStops(resolved);
        }}
        onSelect={setSelected}
        onErrors={noteErrors}
      />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-mint__save"
          disabled={minting || invalid || label.trim() === ''}
          title={Drupal.t(
            "Mint the animation: named keyframes on the active skin; any element can wear it.",
          )}
          onClick={mint}
        >
          {Drupal.t('Save animation')}
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
 * The font mint card: "+ New" › Font, from files.
 *
 * The faces editor (files in, drafts out), then the label (what the
 * person calls the family; the font's NAME on the skin derives from it,
 * skin-model.js fontName), an optional family spelled differently, the
 * description and the offered flag; Save submits the same save_material
 * op (kind font) the AI submits onto the active skin, naming the files
 * by uuid. The op queue lands the response's materials delta on the
 * store before the promise resolves, so the family joins the stack
 * editor's chips in both windows and the leader's font delivery loads
 * its faces before any reload.
 */
const FontMintCard = ({ source, onClose }) => {
  const [label, setLabel] = useState('');
  const [family, setFamily] = useState('');
  const [description, setDescription] = useState('');
  const [offered, setOffered] = useState(true);
  const [drafts, setDrafts] = useState([]);
  const [uploading, setUploading] = useState(0);
  const [minting, setMinting] = useState(false);

  const mint = () => {
    setMinting(true);
    const name = label.trim();
    saveMaterial(
      source,
      {
        kind: 'font',
        label: fontName(name),
        family: family.trim() === '' ? name : family.trim(),
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
        // The op queue's source reported it; the card stays open.
      })
      .finally(() => setMinting(false));
  };

  const hold = facesBlocked(drafts, uploading);
  const blocked = minting || hold !== null || fontName(label) === '';
  const blockedTitle =
    hold ??
    (fontName(label) === ''
      ? Drupal.t('Name the font.')
      : Drupal.t(
          'Mint the font: a self-hosted family on the active skin; any stack can name it.',
        ));

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-skin-mint daedalus-stylish-skin-mint--font">
      <FacesEditor
        drafts={drafts}
        onDrafts={setDrafts}
        onUploading={setUploading}
        source={source}
        disabled={minting}
      />
      <input
        type="text"
        className="daedalus-stylish-library-card__label daedalus-ui-input daedalus-stylish-skin-mint__name"
        aria-label={Drupal.t('Font label')}
        placeholder={Drupal.t('Font name…')}
        value={label}
        onInput={(event) => setLabel(event.target.value)}
      />
      <input
        type="text"
        className="daedalus-ui-input daedalus-stylish-skin-mint__family"
        aria-label={Drupal.t('Font family')}
        placeholder={Drupal.t('Family name, when it differs from the label (optional)…')}
        title={Drupal.t('The name font-family stacks reference; the label unless you say otherwise.')}
        value={family}
        onInput={(event) => setFamily(event.target.value)}
      />
      <input
        type="text"
        className="daedalus-ui-input daedalus-stylish-skin-mint__description"
        placeholder={Drupal.t('When to reach for it (optional)…')}
        value={description}
        onInput={(event) => setDescription(event.target.value)}
      />
      <AiOfferToggle offered={offered} onChange={setOffered} />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-skin-mint__save"
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
 * A section's heading: a scale's label as declared, or the two kinds
 * that are not scales with a hint saying what their names are referenced
 * as.
 *
 * @param {{kind: string, label: ?string}} section
 *   A skinSections() section.
 *
 * @return {{label: string, hint: ?string}}
 */
export const sectionHeading = (section) => {
  if (section.kind === 'animation') {
    return { label: Drupal.t('Animations'), hint: Drupal.t('what motion-* names') };
  }
  if (section.kind === 'font') {
    return { label: Drupal.t('Fonts'), hint: Drupal.t('what a family stack names') };
  }
  return { label: section.label ?? '', hint: null };
};

/**
 * The kind picker's words: what "+ New" offers.
 *
 * @param {string} kind
 *
 * @return {string}
 */
export const kindLabel = (kind) =>
  ({
    token: Drupal.t('Token'),
    animation: Drupal.t('Animation'),
    font: Drupal.t('Font'),
  })[kind];

/**
 * The Skin tab: what the worn skin carries, edited where it is listed.
 *
 * The Design system place's first tab, site-wide content, no selection
 * needed: the skin card over everything (the look the site wears, its
 * revisions, copy as mine, the site layer's revert), one search over the
 * skin and one "+ New" with a kind picker (a token on a scale, an
 * animation, a font), then the tokens per scale, the Animations and the
 * Fonts, each row opening the one material card (material-card.js).
 * Everything here is the materials store's records (the roster this
 * window was handed; a mint, edit or delete in either window re-renders
 * it through the store's delta): nothing fetches a list, and the facts a
 * card needs beyond its record are its own per-open read.
 *
 * @param {object} props
 * @param {object} props.skinMaterials
 *   The skin material gesture gates
 *   (drupalSettings.daedalusStylish.skinMaterials).
 * @param {object} props.skin
 *   The skin gesture gates (drupalSettings.daedalusStylish.skin): the
 *   skin card's lifecycle is the administer grade's.
 */
export const SkinView = ({
  guide,
  materials,
  skinMaterials: config = {},
  skin: skinConfig = {},
  source,
}) => {
  const [search, setSearch] = useState('');
  // The open card, as "{kind}:{id}".
  const [card, setCard] = useState(null);
  // The mint card: null closed; {kind, seed} open.
  const [minting, setMinting] = useState(null);
  const menu = useMenu();

  const sections = skinSections(guide, materials, { search });
  const kinds = mintableKinds(guide, config);
  const skinReadable = skinCardReadable(config, skinConfig);
  const scheme = schemeCondition(guide);
  const schemeLabel = guide?.conditions?.[scheme]?.label ?? scheme;
  const empty = sections.length === 0;

  const openPicker = (event) => {
    menu.open({
      anchor: event.currentTarget,
      header: Drupal.t('New on the skin'),
      items: kinds.map((kind) => ({
        label: kindLabel(kind),
        run: () => setMinting({ kind, seed: null }),
      })),
    });
  };

  const copyTo = (seed) => {
    if (seed === null) {
      return;
    }
    setCard(null);
    setMinting({ kind: seed.kind, seed });
  };

  if (empty && search.trim() === '' && kinds.length === 0 && !skinReadable) {
    return (
      <div className="daedalus-stylish-skin">
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t(
            'No skin is worn, so there is nothing to list. A design system with a skin brings its tokens, animations and fonts here.',
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="daedalus-stylish-skin">
      {skinReadable && <SkinCard skin={skinConfig} source={source} />}
      <div className="daedalus-stylish-skin__toolbar">
        <input
          type="search"
          className="daedalus-stylish-skin__search daedalus-ui-input"
          placeholder={Drupal.t('Search the skin…')}
          aria-label={Drupal.t('Search the skin')}
          value={search}
          onInput={(event) => setSearch(event.target.value)}
        />
        {kinds.length > 0 && minting === null && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-skin__new"
            title={Drupal.t(
              'Mint a new material on the skin: a token on one of its scales, an animation, or a font from files.',
            )}
            onClick={openPicker}
          >
            {Drupal.t('+ New ▾')}
          </button>
        )}
      </div>
      {minting?.kind === 'token' && (
        <TokenMintCard
          guide={guide}
          materials={materials}
          source={source}
          seed={minting.seed}
          onClose={() => setMinting(null)}
        />
      )}
      {minting?.kind === 'animation' && (
        <AnimationMintCard
          guide={guide}
          materials={materials}
          source={source}
          seed={minting.seed}
          onClose={() => setMinting(null)}
        />
      )}
      {minting?.kind === 'font' && (
        <FontMintCard source={source} onClose={() => setMinting(null)} />
      )}
      {empty ? (
        <div className="daedalus-stylish-inspector__empty">
          {search.trim() === ''
            ? Drupal.t(
                'The skin carries nothing yet. "+ New" mints a token, an animation or a font on it.',
              )
            : Drupal.t('Nothing on the skin matches.')}
        </div>
      ) : (
        sections.map((section) => (
          <div
            key={section.id}
            className={`daedalus-stylish-skin__section daedalus-stylish-skin__section--${section.kind}`}
            data-section={section.id}
          >
            <div className="daedalus-stylish-skin__section-label">
              {sectionHeading(section).label}
              {sectionHeading(section).hint !== null && (
                <span className="daedalus-stylish-skin__section-hint">
                  {sectionHeading(section).hint}
                </span>
              )}
            </div>
            {section.rows.map((row) => {
              const id = `${row.kind}:${row.reference}`;
              const open = card === id;
              return (
                <Fragment key={id}>
                  <MaterialRow
                    row={row}
                    open={open}
                    schemeLabel={schemeLabel}
                    onToggle={() => setCard(open ? null : id)}
                  />
                  {open && (
                    <MaterialCard
                      row={row}
                      guide={guide}
                      materials={materials}
                      config={config}
                      source={source}
                      onCard={(next) =>
                        setCard(next === null ? null : `${row.kind}:${next}`)
                      }
                      onCopy={copyTo}
                    />
                  )}
                </Fragment>
              );
            })}
          </div>
        ))
      )}
    </div>
  );
};
