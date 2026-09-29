import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { IconButton } from './icon-button.js';
import { fetchUsage, revertVariant } from './variants-api.js';
import { failureText, gesture } from './gesture-transport.js';
import { respondedMaterial } from './material-gestures.js';
import { WearBar } from './wear-bar.js';
import {
  entryRefs,
  captureEntries,
  hasCustomStyles,
  findVariant,
  isOrphaned,
  isShipped,
} from './variant-scope.js';
import { doorRequest, entriesToOps, structuralChunks } from './code/paste-door.js';

const SaveVariantIcon = () => (
  <svg
    viewBox="0 0 256 256"
    width="16"
    height="16"
    fill="currentColor"
    aria-hidden="true"
  >
    <path d="M219.31,72,184,36.69A15.86,15.86,0,0,0,172.69,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V83.31A15.86,15.86,0,0,0,219.31,72ZM168,208H88V152h80Zm40,0H184V152a16,16,0,0,0-16-16H88a16,16,0,0,0-16,16v56H48V48H172.69L208,83.31ZM160,72a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h56A8,8,0,0,1,160,72Z" />
  </svg>
);

const CopyStylesIcon = () => (
  <svg
    viewBox="0 0 256 256"
    width="16"
    height="16"
    fill="currentColor"
    aria-hidden="true"
  >
    <path d="M216,32H88a8,8,0,0,0-8,8V80H40a8,8,0,0,0-8,8V216a8,8,0,0,0,8,8H168a8,8,0,0,0,8-8V176h40a8,8,0,0,0,8-8V40A8,8,0,0,0,216,32ZM160,208H48V96H160Zm48-48H176V88a8,8,0,0,0-8-8H96V48H208Z" />
  </svg>
);

const PasteStylesIcon = () => (
  <svg
    viewBox="0 0 256 256"
    width="16"
    height="16"
    fill="currentColor"
    aria-hidden="true"
  >
    <path d="M200,32H163.74a47.92,47.92,0,0,0-71.48,0H56A16,16,0,0,0,40,48V216a16,16,0,0,0,16,16H200a16,16,0,0,0,16-16V48A16,16,0,0,0,200,32Zm-72,0a32,32,0,0,1,32,32H96A32,32,0,0,1,128,32Zm72,184H56V48H82.75A47.93,47.93,0,0,0,80,64v8a8,8,0,0,0,8,8h80a8,8,0,0,0,8-8V64a47.93,47.93,0,0,0-2.75-16H200Z" />
  </svg>
);

/**
 * The header's element style gestures, icon-sized.
 *
 * Save as variant / Copy CSS / Paste CSS live in the Inspector's header
 * action cluster, next to the host's pop-out control. Save acts on the
 * SELECTED element (`selected`: a variant is saved from an element's
 * stack, never from a rule) and needs the create grant, a capturable
 * look and a wired doorway; copy and paste act at the styling TARGET
 * (`target`, the key the panel writes at: the element, or the ancestor
 * rule the target chip names). The Inspector renders this in all three
 * modes for the element subject of a stylable selection; the save icon
 * is the ONE save-as-variant doorway, entering the editing session in
 * its creating posture everywhere.
 *
 * One clipboard, the system's, carrying dialect text. Copy CSS reads the
 * target's own entries printed as flat dialect rules by the server
 * (source.readCss: daedalus_blueprint_api's read route in css mode,
 * `#<uuid>` as the id, the element's words as a comment, its wear as a
 * comment line) into the clipboard through a promise-backed
 * ClipboardItem, so the write stays inside the user's gesture while the
 * print is fetched (Safari accepts a promise for the item's data;
 * Chrome's transient activation covers the fetch). What the AI says,
 * what the designer copies and what they paste are one text. Paste CSS
 * reads the system clipboard (navigator.clipboard.readText, the
 * browser's one-time permission prompt) into the spine's parse door at
 * the write target (paste-door.js: a copied `#id { }` block re-roots
 * onto the element, anything else reads under the target itself) and
 * lands the entries as one additive update_style batch, one history
 * entry: a pasted block sets what it names and unsets nothing (clearing
 * is a row's ×), and travels own styles only (a variant-worn treatment
 * travels by applying the variant). Basic and Advanced have no island to
 * paste into, so the header button is the clipboard's door there; in
 * code mode ⌘V inside an island is the same door.
 *
 * Success feedback rides the gesture's own tooltip ("CSS copied" flashes
 * in place of the label; the eye is already on the button); failures go
 * to the edit-mode messenger (source.notify), which has room for a
 * server corrective's sentence.
 */
export const StyleActions = ({
  source,
  guide,
  selected,
  target,
  styleMap,
  config,
  onSaveVariant = null,
}) => {
  const [busy, setBusy] = useState(false);
  // The transient tooltip feedback: {key: 'copy'|'paste', text} or null.
  const [flash, setFlash] = useState(null);
  const flashTimer = useRef(null);
  const flashTooltip = (key, text) => {
    window.clearTimeout(flashTimer.current);
    setFlash({ key, text });
    flashTimer.current = window.setTimeout(() => setFlash(null), 1800);
  };
  useEffect(() => () => window.clearTimeout(flashTimer.current), []);

  const layout = source.getLayout ? source.getLayout() : [];
  const capturable =
    Object.keys(captureEntries(styleMap, selected, layout)).length > 0;
  const canCopy =
    entryRefs(styleMap?.[target]).length > 0 ||
    hasCustomStyles(styleMap, target);

  const copy = () => {
    // The print's own failure is the source's to report (the leader's
    // messenger, the satellite's settle); only a clipboard refusal after
    // the text arrived is phrased here.
    let printed = false;
    const text = source.readCss(target).then((css) => {
      printed = true;
      return css;
    });
    const written =
      typeof ClipboardItem === 'undefined'
        ? text.then((css) => navigator.clipboard.writeText(css))
        : navigator.clipboard.write([
            new ClipboardItem({
              'text/plain': text.then(
                (css) => new Blob([css], { type: 'text/plain' }),
              ),
            }),
          ]);
    written
      .then(() => flashTooltip('copy', Drupal.t('CSS copied')))
      .catch((error) => {
        if (printed) {
          source.notify?.(
            'error',
            failureText(error) || Drupal.t('The CSS could not be copied.'),
          );
        }
      });
  };

  const paste = () => {
    setBusy(true);
    navigator.clipboard
      .readText()
      .then(
        (text) => {
          if (!text || text.trim() === '') {
            flashTooltip('paste', Drupal.t('Nothing to paste'));
            return null;
          }
          // The door reads under the write target; a copied `#id { }`
          // block re-roots onto the element the target sits on.
          const request = doorRequest(text, structuralChunks(text), {
            mapKey: target,
            rootKey: target.split('::')[0],
            condition: 'default',
            guide,
          });
          return source.parseCss(request.key, request.css).then((answer) => {
            const errors = Array.isArray(answer?.errors) ? answer.errors : [];
            if (errors.length) {
              // The header has no island to place a corrective on a line:
              // the messenger carries the first one.
              source.notify?.(
                'error',
                [errors[0].message, errors[0].hint].filter(Boolean).join(' '),
              );
              return null;
            }
            const { operations, count } = entriesToOps(
              answer?.entries,
              answer?.wear,
            );
            if (!operations.length) {
              flashTooltip('paste', Drupal.t('Nothing to paste'));
              return null;
            }
            const label = Drupal.t('Pasted @count declarations', {
              '@count': count,
            });
            return source
              .commitBatch({ operations, label })
              .then(() => flashTooltip('paste', label));
          });
        },
        (error) => {
          // The clipboard refused the read (permission denied, no
          // clipboard access in this context).
          source.notify?.(
            'error',
            failureText(error) || Drupal.t('The clipboard could not be read.'),
          );
        },
      )
      .catch(() => {
        // The door's transport and the op queue reported their own
        // failures (the leader's messenger, the satellite's settle).
      })
      .finally(() => setBusy(false));
  };

  return (
    <Fragment>
      {config?.canCreate && capturable && onSaveVariant && (
        <IconButton
          label={Drupal.t('Save as variant')}
          disabled={busy}
          onClick={() => onSaveVariant()}
        >
          <SaveVariantIcon />
        </IconButton>
      )}
      {canCopy && (
        <IconButton
          label={Drupal.t('Copy CSS')}
          tooltip={flash?.key === 'copy' ? flash.text : null}
          show={flash?.key === 'copy'}
          disabled={busy}
          onClick={copy}
        >
          <CopyStylesIcon />
        </IconButton>
      )}
      <IconButton
        label={Drupal.t('Paste CSS')}
        tooltip={flash?.key === 'paste' ? flash.text : null}
        show={flash?.key === 'paste'}
        disabled={busy}
        onClick={paste}
      >
        <PasteStylesIcon />
      </IconButton>
    </Fragment>
  );
};

/**
 * The shipped-state sentence: modified/defaults-changed.
 *
 * The state comes computed from the server (the one place that can compare
 * the variant against its provider's disk file: the usage route's per-open
 * management read); the client only phrases it. Only the ACTIONABLE
 * states render a sentence — they carry the revert button. The unmodified
 * state ("shipped, as the provider ships it", the distro-lock steady
 * state) renders nothing: no banner, no row marker — shipped-ness surfaces
 * only where it gates a gesture (the row menu's read-only hint, the fork).
 */
const shippedText = (management) => {
  if (management.shipped_state === 'defaults_changed') {
    return Drupal.t('The @module module ships new defaults for this variant.', {
      '@module': management.provider,
    });
  }
  return Drupal.t('Modified from the defaults shipped by the @module module.', {
    '@module': management.provider,
  });
};

/**
 * Revert-to-shipped-defaults with its loud confirm (shared by the
 * variant area's notice button and the library card).
 *
 * Re-reads the management facts at decision time (the live use count and
 * the shipping module; the earlier read can drift mid-session), asks
 * with them on screen, then reverts: an ordinary revisioned save whose
 * styles are the provider's current disk file. The caller hands the
 * result to `source.applyResponse()`.
 *
 * @param {object} variant
 *   The variant record.
 *
 * @return {Promise<?object>}
 *   The revert result ({usages, materials}), or null when declined.
 */
export const confirmRevertToDefaults = (variant) =>
  fetchUsage(variant.id).then(async ({ usages, provider }) => {
    const question = Drupal.formatPlural(
      usages,
      'Revert "@label" to the defaults shipped by the @module module? This updates 1 use across the site.',
      'Revert "@label" to the defaults shipped by the @module module? This updates @count uses across the site.',
      { '@label': variant.label, '@module': provider },
    );
    if (
      !(await Drupal.Daedalus.dialog.confirm({
        message: question,
        confirmLabel: Drupal.t('Revert'),
      }))
    ) {
      return null;
    }
    return revertVariant(variant.id);
  });

/**
 * The management facts of the worn SHIPPED variants, per selection.
 *
 * The shipped-state notice needs what only the usage route knows (whether
 * a shipped variant diverged from its provider's disk copy); the roster
 * record carries the origin alone. One read per worn shipped variant,
 * refreshed when the worn set or the records change, and only when the
 * viewer can act on it (the update grant is also the route's permission,
 * so a viewer without it is never asked to fetch what it could not use).
 *
 * @return {Object<string, object>}
 *   Management payloads keyed by variant id, for the reads that answered.
 */
const useWornManagement = (worn, enabled, variants) => {
  const [management, setManagement] = useState({});
  const ids = worn.map((variant) => variant.id).join('\n');
  useEffect(() => {
    setManagement({});
    if (!enabled || !worn.length) {
      return undefined;
    }
    let alive = true;
    worn.forEach((variant) => {
      fetchUsage(variant.id).then(
        (body) =>
          alive &&
          setManagement((previous) => ({ ...previous, [variant.id]: body })),
        () => {},
      );
    });
    return () => {
      alive = false;
    };
  }, [ids, enabled, variants]);
  return management;
};

/**
 * The inspector's variant area (lifecycle + copy/paste, built around the
 * wear bar).
 *
 * Renders first in the scrolling body in every mode of the styling
 * place (basic, advanced and code: wear is a fact of the subject, not
 * of the editor), at the styling TARGET (`target`, the key the wearings are
 * read and every wear gesture lands at: the selected element, or the
 * rule the target chip names, whose every match wears what is applied
 * there). All gestures speak through props:
 * spine ops go through the state source so the leader stays the single
 * writer; the gesture endpoints are fetched directly by either host
 * (site-wide content, not session state) and their responses handed to
 * `source.applyResponse()`, whose materials delta re-renders every
 * consumer of the store in both windows. The variants arrive as the
 * store's records (`variants`), the roster itself as `materials` (the
 * ownership reads need the stencils beside the variants).
 *
 * The area's pieces:
 * - The WEARING ROWS (wear-bar.js): the worn rows (minus the axes a
 *   mounted section claims for the current mode, `claimedAxes`), the add
 *   row (the variant picker) and every per-row gesture (swap, Outline,
 *   Re-apply, Reset overrides, Detach, Remove, the editing-session
 *   doorway, Variant settings…) — one component across all three modes
 *   (the code tree carries no wearing chrome of its own; page-view.js),
 *   so the control surface is one thing wherever it renders.
 * - Ownership and shipped-state notices, one line per worn variant that
 *   carries a story (an orphaned stencil owner, a modified shipped
 *   variant with its Revert-to-defaults action).
 * - Gesture outcomes ride source.notify to the edit-mode messenger:
 *   the header's StyleActions gestures speak through the same spine, so
 *   there is one feedback surface either way.
 *
 * There is deliberately NO update-variant doorway here anymore: a
 * variant is edited in the editing session (its own document,
 * pending-until-Push; the wearing rows are the doorway), which retired
 * the old "Update a variant…" select.
 *
 * `readOnly` (a target that can wear nothing: a part or the children,
 * whose landing is no element scope): `target` is then the selected
 * element and the area stays mounted, since its wearings anchor every
 * "from <variant>" mark on the part's rows, but carries no wear
 * gesture: the shipped-state lines keep their notice and lose the
 * revert button, and the wear bar takes its read-only posture
 * (wear-bar.js).
 */
export const VariantArea = ({
  source,
  guide,
  materials,
  target,
  styleMap,
  variants,
  blockTypes,
  sectionLayouts,
  config,
  claimedAxes = [],
  condition = 'default',
  readOnly = false,
  outline = null,
  onFlash = null,
  onOpenDocument = null,
  onUpdateFromElement = null,
  onVariantSettings = null,
}) => {
  const [busy, setBusy] = useState(false);

  const entry = styleMap?.[target];
  // The structured read (entryRefs): every reference is {variant, fragment}
  // — '' the root wearing, a slot path a fragment distributed by a
  // composition apply. The notices below are per-variant uniform: a child
  // carrying a distributed fragment reads exactly like a root wearer.
  const references = entryRefs(entry).map((ref) => ref.variant);
  const worn = references
    .map((reference) => findVariant(variants, reference))
    .filter(Boolean);
  const management = useWornManagement(
    worn.filter(isShipped),
    Boolean(config?.canUpdate),
    variants,
  );

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  // Revert to defaults: when a worn shipped variant diverged from
  // its provider's disk file (modified) or the disk moved on
  // (defaults-changed), revert restores the disk styles as a regular
  // revisioned save, behind the same loud use count as push. No re-attach:
  // the element already references the variant.
  const revert = (variant) => {
    run(() =>
      confirmRevertToDefaults(variant).then((result) => {
        if (result) {
          source.applyResponse(result);
          source.notify?.(
            'status',
            Drupal.t('Reverted "@label" to its built-in defaults.', {
              '@label':
                respondedMaterial(result, 'variant', variant.id)?.label ??
                variant.label,
            }),
          );
        }
        return null;
      }),
    );
  };

  return (
    <div className="daedalus-stylish-inspector__variant-area">
      {/* The "Styled by the variant …" provenance notice used to render
          here; it is panel-level now (inspector.js), where it also covers
          drilled parts and code mode. */}
      {/* Ownership honesty, one line per worn ORPHANED variant: an owner
          the roster no longer holds (deleted, or no longer discovered) is
          flagged loudly, since the variant offers nowhere but this wearer
          keeps rendering. A living owner says nothing here: the wear row's
          facts and the library card already name it. */}
      {worn.map((variant) => {
        if (variant.owned_by == null || !isOrphaned(materials, variant)) {
          return null;
        }
        return (
          <div
            key={`owned-${variant.id}`}
            className="daedalus-stylish-inspector__variant-owned daedalus-stylish-inspector__variant-owned--orphaned"
          >
            {Drupal.t(
              '"@label" belonged to a stencil that no longer exists; it is offered nowhere, but this element keeps its look.',
              { '@label': variant.label },
            )}
          </div>
        );
      })}
      {/* The shipped-state lines, one per worn shipped variant
          whose per-open management read answered an actionable state
          (they carry the revert button); the steady unmodified state and
          a read still in flight render nothing. */}
      {worn.map((variant) => {
        const facts = management[variant.id];
        if (!facts?.shipped_state || facts.shipped_state === 'unmodified') {
          return null;
        }
        return (
          <div
            key={variant.id}
            className={`daedalus-stylish-inspector__variant-shipped daedalus-stylish-inspector__variant-shipped--${facts.shipped_state}`}
          >
            <span>{shippedText(facts)}</span>
            {!readOnly && (
              <button
                type="button"
                className="daedalus-stylish-inspector__variant-button daedalus-ui-pill-button"
                disabled={busy}
                onClick={() => revert(variant)}
              >
                {Drupal.t('Revert to defaults')}
              </button>
            )}
          </div>
        );
      })}
      {/* The wearing rows: the worn rows, the add row (the variant
          picker) and every per-row gesture; one component across all
          three modes (the code tree carries no wearing chrome of its
          own). */}
      <WearBar
        source={source}
        guide={guide}
        materials={materials}
        target={target}
        styleMap={styleMap}
        variants={variants}
        blockTypes={blockTypes}
        sectionLayouts={sectionLayouts}
        config={config}
        claimedAxes={claimedAxes}
        condition={condition}
        readOnly={readOnly}
        outline={outline}
        onFlash={onFlash}
        onOpenDocument={onOpenDocument}
        onUpdateFromElement={onUpdateFromElement}
        onVariantSettings={onVariantSettings}
      />
    </div>
  );
};
