import { h, Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { saveMaterial, updateMaterial } from './material-gestures.js';
import { documentLayerId, layerColor } from './code/layers.js';
import {
  sessionCount,
  sessionStyles,
  skinPushOperations,
  stagesLines,
} from './pending.js';
import { skinScopeLabel } from './provenance-model.js';
import { mintConsent } from './session-frame-model.js';
import {
  axisVocabulary,
  findVariant,
  saveStyles,
  shadowClearingOps,
  blueprintProvenance,
} from './variant-scope.js';

/**
 * The Push/Discard bar: the pending engine's one commit surface.
 *
 * Rendered panel-level by the Inspector whenever a pending session is
 * open, in every mode — pending edits survive mode switches, so their
 * commit surface must too. One loud identical bar for both session kinds
 * (the two-commit-cadences mitigation: wherever a variant is the write
 * target, the same bar says so):
 *
 * - An UPDATE session (an edited document with staged lines) shows the target
 *   variant — its identity dot and label — the pending count, and the
 *   site-wide impact from the usage table ("restyles N places" — the
 *   mode's unit, the blast line's word; the Inspector fetches the count
 *   once at session entry and passes it down, so the consequence is on
 *   screen BEFORE commit). Push update commits ONE master push of the staged
 *   lines merged over the variant's current styles: one new revision,
 *   every wearer restyles, workspaces stage it.
 * - A SAVE session (save-as-variant's staged capture as pending lines)
 *   shows the draft's identity — its name (or the honest placeholder
 *   until it has one; the name and axis are taken in the session
 *   frame's header, never here), states the
 *   impact honestly (a new variant has no wearers; this element will
 *   follow it), and Save variant commits the capture then re-attaches
 *   through the op spine.
 * - A SKIN session (the active skin's document at one scope, pending.js
 *   skinSession) shows the scope in the skin hue, the pending count and
 *   the site-wide impact in words (a default restyles every element of
 *   its scope). Push update commits the staged lines as update_default
 *   patches, one per (key, condition), in one batch (pending.js
 *   skinPushOperations): one new revision of the skin, every element of
 *   the scope restyles, workspaces stage it. Then the source refreshes
 *   the skin's delivery (leader-source.js refreshDefaults: the skin's
 *   defaults as the panel reads them and the recompiled CSS on the
 *   canvas), so the pushed look lands without a reload.
 *
 * The variant commits are material gesture ops (save_material /
 * update_material, kind variant: the ops the AI submits) and the skin
 * commit is the style op the AI submits (update_default), all through
 * the source's batch commit,
 * carrying the client's own capture as `key` + `styles` (the walk that
 * drives the live preview, so the shadow-clearing ops read the same
 * walk); the satellite runs them through the leader, so the leader stays
 * the single writer of staged maps and of materials. A failure toasts
 * through the messenger (the op queue's source reports it, in whichever
 * window made the gesture) and the session stays open for a correction.
 */

/**
 * Commits a pending session through the material gesture family.
 *
 * The one commit routine the bar's buttons AND the Inspector's
 * leaving-prompt share, so "Push" means the same thing wherever it is
 * said. An update session is one master push (update_material with
 * `styles` and no source key: the merged payload validates against the
 * variant's own scope); the op queue lands the new revision's record on
 * the store in both windows before the promise resolves, so every panel
 * resolves it; then the anchor's shadow clearing: own overrides equal to
 * what the variant now stores retire (the overrides-only rule), so
 * pushed values show through instead of hiding behind identical shadows.
 * A save session is save_material over the pruned capture (the minted
 * record joins the store the same way), then the re-attach batch,
 * apply_variant plus the shadow-clearing unsets retiring captured
 * overrides the variant now covers, atomically through the op spine; a
 * failed re-attach leaves the element detached-but-identical and retry
 * self-heals (the op queue's source reports the failure).
 *
 * A skin session is one batch of update_default ops (pending.js
 * skinPushOperations) followed by the source's defaults refresh, so the
 * panel and the canvas read the pushed defaults before the promise
 * resolves; it resolves with the batch's response payload.
 *
 * @return {Promise<object>}
 *   The written variant's record (a skin session: the batch payload);
 *   rejects with the op queue's Error after its source reported it, so
 *   the caller settles only its own state.
 */
export const commitSession = ({
  source,
  guide,
  session,
  variants,
  styleMap,
}) => {
  const library = variants ?? [];
  const tree = source.getTree?.() ?? [];
  if (session.kind === 'skin') {
    return source
      .commitBatch({
        operations: skinPushOperations(session),
        label: Drupal.t('Pushed skin defaults for !scope', {
          '!scope': skinScopeLabel(session.scope, guide),
        }),
      })
      .then((payload) =>
        // The delivery refresh is the landing, not the commit: a refresh
        // that fails leaves the push committed and reports through the
        // transport's own notice, so the session still closes.
        Promise.resolve(source.refreshDefaults?.())
          .catch(() => null)
          .then(() => payload),
      );
  }
  if (session.kind === 'update') {
    const variant = findVariant(library, session.reference);
    return updateMaterial(
      source,
      {
        kind: 'variant',
        id: session.reference,
        styles: sessionStyles(session, { documentStyles: variant?.value ?? {} }),
      },
      Drupal.t('Pushed changes into !label', {
        '!label': variant?.label ?? session.reference,
      }),
    ).then((updated) => {
      // The anchor follows what it just pushed: own overrides now equal
      // to the variant's values retire (competitor-safe, the exact save
      // re-attach rule), so an update-from-element diff lands as "this
      // element follows the variant again" instead of leaving identical
      // shadows. A differing override is a live override and stays. A
      // rule anchor clears the same way over its own entry and its
      // continuations by descent (captureSources at a rule key). The
      // pushed variant is the record the response's delta carried: the
      // shape the layer math speaks.
      const clearing = shadowClearingOps(
        styleMap ?? {},
        session.anchor,
        tree,
        updated,
        library,
      );
      if (!clearing.length) {
        return updated;
      }
      return source
        .commitBatch({
          operations: clearing,
          label: Drupal.t('Pushed changes into !label', {
            '!label': updated.label,
          }),
        })
        .catch(() => {
          // The op queue's source reports the failure; identical shadows
          // are harmless and a later push self-heals.
        })
        .then(() => updated);
    });
  }
  const anchor = session.anchor;
  const vocabulary = axisVocabulary(
    guide,
    anchor,
    source.getBlockTypes(),
    source.getSectionLayouts(),
    tree,
  );
  const capture = saveStyles(
    styleMap ?? {},
    anchor,
    tree,
    library,
    session.axis,
  );
  // Mint-time ownership consent: the session header's "belongs to
  // <blueprint>" chip and this send read the ONE fact
  // (session-frame-model.js mintConsent), so what the chip says is what
  // Save commits. The anchor's placement provenance rides the save only
  // while the session is linked; unlinked (or no provenance) sends
  // nothing and the variant is born general-purpose.
  const consent = mintConsent(
    session,
    blueprintProvenance(anchor, tree),
    source.getMaterials(),
  );
  return saveMaterial(
    source,
    {
      kind: 'variant',
      label: session.label.trim(),
      // The draft's description rides the mint (the roster face the
      // Materials card shows and the AI reads); an empty one is omitted so
      // the server's own default holds.
      description: (session.description ?? '').trim() || undefined,
      // The AI opt-out rides the mint only when off: offered is the server's
      // default, so the key is sent only to withhold (ai-offer.js).
      offered: session.offered === false ? false : undefined,
      key: anchor,
      styles: sessionStyles(session, { capture }),
      // The degenerate single-axis vocabulary omits the pick and the server
      // defaults to the built-in "style" axis, mirroring the payload's own
      // fold default. `group` is the record's group: the axis.
      group: vocabulary.length > 1 ? session.axis : undefined,
      owned_by: consent?.blueprint ?? undefined,
    },
    Drupal.t('Saved variant !label', { '!label': session.label.trim() }),
  ).then((minted) =>
    source
      .commitBatch({
        operations: [
          { op: 'apply_variant', key: anchor, variant: minted.id },
          ...shadowClearingOps(styleMap ?? {}, anchor, tree, minted, library),
        ],
        label: Drupal.t('Applied variant !label', { '!label': minted.label }),
      })
      .catch(() => {
        // The op queue's source reports the failure; the element is left
        // detached-but-identical and retry self-heals.
      })
      .then(() => minted),
  );
};

/**
 * The bar itself.
 *
 * @param {object} props
 *   - source, guide, styleMap, variants: the Inspector's usual wiring
 *     (variants = the store's variant records).
 *   - session: the open pending session (pending.js shapes).
 *   - usages: the update session's wearer count (the Inspector's one
 *     fetch per session entry, shared with the frame's blast line), or
 *     null while unanswered.
 *   - onClose: closes the session (Discard and a successful commit alike).
 */
export const PushBar = ({
  source,
  guide,
  styleMap,
  variants,
  session,
  usages = null,
  onClose,
}) => {
  const [busy, setBusy] = useState(false);
  const library = variants ?? [];
  const tree = source.getTree?.() ?? [];
  // The two line-staging kinds share the bar's posture (a pushed
  // document); the draft alone needs a name before it can commit.
  const update = stagesLines(session);
  const skin = session.kind === 'skin';
  const worn =
    session.kind === 'update' ? findVariant(library, session.reference) : null;
  // A save session's pending set derives LIVE: the capture is what Push
  // would commit right now, mid-session element edits included.
  const capture = update
    ? null
    : saveStyles(styleMap ?? {}, session.anchor, tree, library, session.axis);
  const count = sessionCount(session, capture);

  const commit = () => {
    if (busy || count === 0 || (!update && !session.label.trim())) {
      return;
    }
    setBusy(true);
    commitSession({ source, guide, session, variants: library, styleMap })
      .then(() => onClose())
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader,
        // the satellite's settle); the session stays open for a correction.
        setBusy(false);
      });
  };

  const impactText = () => {
    if (skin) {
      // A default's blast radius is its scope: every element of it, on
      // every page, with no usage table to count (the scope IS the count).
      return Drupal.t('skin default · restyles every !scope site-wide', {
        '!scope': skinScopeLabel(session.scope, guide),
      });
    }
    if (!update) {
      return Drupal.t('new variant · this element will follow it');
    }
    if (usages === null) {
      return Drupal.t('site-wide impact loading…');
    }
    // The mode's unit is "places" (the blast line's word); the Materials
    // card keeps "pieces of content".
    return Drupal.formatPlural(
      usages,
      'restyles 1 place',
      'restyles @count places',
    );
  };

  return (
    <div className="daedalus-stylish-push-bar">
      <div className="daedalus-stylish-push-bar__row">
        {update ? (
          <Fragment>
            <span
              className="daedalus-stylish-push-bar__dot daedalus-ui-dot"
              style={{
                background: layerColor(documentLayerId(session.reference)),
              }}
            />
            <span className="daedalus-stylish-push-bar__name">
              {skin
                ? skinScopeLabel(session.scope, guide)
                : (worn?.label ?? session.reference)}
            </span>
          </Fragment>
        ) : (
          <Fragment>
            <span className="daedalus-stylish-push-bar__dot daedalus-ui-dot daedalus-stylish-dot--draft" />
            <span className="daedalus-stylish-push-bar__name">
              {session.label.trim() || Drupal.t('New variant')}
            </span>
          </Fragment>
        )}
        <button
          type="button"
          className="daedalus-stylish-push-bar__commit"
          disabled={busy || count === 0 || (!update && !session.label.trim())}
          title={
            !update && !session.label.trim()
              ? Drupal.t('Name the variant in the session header first.')
              : undefined
          }
          onClick={commit}
        >
          {update ? Drupal.t('Push update') : Drupal.t('Save variant')}
        </button>
        <button
          type="button"
          className="daedalus-stylish-inspector__variant-button daedalus-ui-pill-button"
          disabled={busy}
          onClick={onClose}
        >
          {Drupal.t('Discard')}
        </button>
        <span className="daedalus-stylish-push-bar__impact">
          {Drupal.formatPlural(
            count,
            '1 pending change',
            '@count pending changes',
          )}
          {' · '}
          {impactText()}
        </span>
      </div>
    </div>
  );
};
