import { printMap, containerRootsCss } from './printer.js';
import { resolveMapMedia, resolveMediaValue } from './media-token.js';

/**
 * The live preview: shape declared in a <style> tag, values carried inline.
 *
 * The cascade design: the authoritative tag rides inside the
 * entity's markup, and the preview tag is appended to the END of the body,
 * so for identical selectors it wins the cascade during the gestures
 * between preview and op settle. On settle the op's response markup carries
 * the new authoritative tag, the staged change is released, and the preview
 * empties: a momentary double-definition with a deterministic winner and
 * zero reconciliation logic.
 *
 * Delivery is two-tier. The preview tag prints the merged map exactly as the
 * authoritative tag would (same @scope, @container and pseudo wrappers, same
 * claims-content selectors, same cascade order), except that every staged
 * (key, condition, property) with a live value prints `var(--daedalus-stylish-live-N)`
 * in place of the value. The value itself is written per gesture tick as
 * that custom property inline on the key's scope-root element
 * (`root.style.setProperty`). Custom properties inherit, so the compiled rule
 * resolves the literal at the real target with its condition wrappers
 * intact: condition honesty and cascade order are unchanged, and the printer
 * is untouched (a var() reference prints verbatim like any value). The sheet
 * re-renders only when MEMBERSHIP changes (a triple first staged, released,
 * or staged null; whole entries staged or released; the base map replaced);
 * a value change is one inline write.
 *
 * Why: WebKit treats any change to a <style> tag's text as a resolver
 * Reconstruct (StyleScope::updateActiveStyleSheets lands it as
 * ResolverUpdateType::Reconstruct): the author rule set is rebuilt from
 * every sheet on the page and every element is restyled, about 20 ms per
 * tick on a real page regardless of the sheet's size or rule kinds, while
 * Blink diffs rule sets and invalidates only what changed. An inline write
 * costs ~0.2 ms in both engines, so a scrub that delivers values inline is
 * as snappy in Safari as in Chrome.
 *
 * Values are written on the scope root only, never on part matches: part
 * elements can live inside a CKEditor editable whose innerHTML daedalus_edit
 * snapshots, and the root is outside every editable. A markup swap
 * (blueprint replaced) drops inline state, so baseChanged re-applies every
 * slot. A key whose root is not in the canvas still prints its shape; the
 * inline write is skipped and nothing throws.
 *
 * Staged changes are an overlay (key -> condition -> property -> value, null
 * meaning unset) holding LITERAL values, merged over the blueprint mirror's
 * map at print time. The overlay holds exactly the edits not yet settled: a
 * widget stages per tick, the commit path releases each staged property when
 * its op settles (either way; on success the mirror now carries the value,
 * on failure the preview honestly reverts to the authoritative state). A
 * release drops only still-matching values (a newer gesture staged during
 * the flight survives), so the commit path binds its release to what was
 * staged at dispatch (settling), never to the op's own spelling: magnetic
 * capture commits a reference over the literal it previewed, and that
 * literal is what settles. A
 * null stage is omission (the property drops out of the preview print, as a
 * sheet cannot un-apply the authoritative tag) and mints no slot. Whole-entry
 * stages from the code editor stay literal in the sheet: each keystroke
 * changes membership anyway, and that cadence tolerates one reconstruct.
 *
 * The page-container rule rides along: the merged map is checked for
 * container conditions and the backend's page-level width-band query root
 * is replicated (see containerRootsCss), so the first client-side "narrow"
 * tick previews against a real query container instead of silently doing
 * nothing until the op settles.
 */

const SLOT_PREFIX = '--daedalus-stylish-live-';

/**
 * Whether a staged value is live (carried inline through a slot).
 *
 * Mirrors the printer's own emit gate for a value: null is omission, and a
 * blank value would not print either, so neither mints a slot (a slot
 * whose inline value were blank would resolve var() to guaranteed-invalid
 * and unset the property instead of omitting it).
 */
const isLive = (value) =>
  value !== null && value !== undefined && String(value).trim() !== '';

/**
 * The scope-root key of a style-map key (the part suffix stripped), the
 * same slice printMap takes for the @scope prelude.
 */
const rootKeyOf = (key) => {
  const pos = key.indexOf('::');
  return pos === -1 ? key : key.slice(0, pos);
};

/**
 * @param {object} options
 * @param {object} options.guide
 *   The style guide (conditions, container roots).
 * @param {function(): object} options.getBaseMap
 *   The live authoritative style map (the blueprint mirror's).
 * @param {function(): object} [options.getMediaUrls]
 *   The media uuid -> URL table, read per write.
 * @param {function(string): ?Element} [options.resolveRoot]
 *   The scope-root lookup for a root key (computed-read's resolveRoot).
 *   Absent or answering null: the shape still prints, no inline write.
 */
export const createPreview = ({
  guide,
  getBaseMap,
  getMediaUrls,
  resolveRoot,
}) => {
  // key -> condition -> property -> literal value (null = unset in preview).
  const staged = {};
  // key -> whole style entry (null = key removed in preview): the code
  // editor's overlay. A settling buffer rewrites entire entries (all
  // conditions at once), so it stages by replacement; property
  // staging from widgets applies on top, being always the newer gesture.
  const stagedEntries = {};
  // key -> condition -> property -> slot name: the live custom property
  // each staged value rides. Minted the first time a triple is staged with
  // a live value, dropped when the triple is released or staged null.
  const slots = {};
  let nextSlot = 1;
  let tag = null;

  const hasStaged = () =>
    Object.keys(staged).length > 0 || Object.keys(stagedEntries).length > 0;

  const ensureTag = () => {
    if (!tag || !tag.isConnected) {
      tag = document.createElement('style');
      tag.setAttribute('data-daedalus-stylish-preview', '');
      document.body.appendChild(tag);
    }
    return tag;
  };

  const rootOf = (key) =>
    (typeof resolveRoot === 'function' ? resolveRoot(rootKeyOf(key)) : null) ??
    null;

  /**
   * Writes a slot's current literal inline on the key's scope root.
   *
   * Media tokens substitute per value (the same table read the sheet path
   * takes through resolveMapMedia), so a media pick previews inline too.
   */
  const applyValue = (key, condition, property) => {
    const root = rootOf(key);
    if (!root) {
      return;
    }
    const value = resolveMediaValue(
      staged[key][condition][property],
      getMediaUrls?.() ?? {},
    );
    root.style.setProperty(
      slots[key][condition][property],
      String(value).trim(),
    );
  };

  const mintSlot = (key, condition, property) => {
    ((slots[key] ??= {})[condition] ??= {})[property] =
      `${SLOT_PREFIX}${nextSlot}`;
    nextSlot += 1;
  };

  /**
   * Drops a triple's slot, removing its inline property from the root.
   *
   * @return {boolean}
   *   Whether a slot existed (a membership change for the sheet).
   */
  const dropSlot = (key, condition, property) => {
    const name = slots[key]?.[condition]?.[property];
    if (!name) {
      return false;
    }
    rootOf(key)?.style.removeProperty(name);
    delete slots[key][condition][property];
    if (!Object.keys(slots[key][condition]).length) {
      delete slots[key][condition];
    }
    if (!Object.keys(slots[key]).length) {
      delete slots[key];
    }
    return true;
  };

  const eachSlot = (visit) => {
    Object.entries(slots).forEach(([key, conditions]) => {
      Object.entries(conditions).forEach(([condition, properties]) => {
        Object.keys(properties).forEach((property) =>
          visit(key, condition, property),
        );
      });
    });
  };

  /**
   * The base map with the staged overlay applied (base is never mutated),
   * every slotted value printing as its var() reference.
   */
  const mergedMap = () => {
    const base = getBaseMap() ?? {};
    const merged = { ...base };
    // Entry replacements first (the code buffer's statement of whole keys),
    // then the per-property overlay on top.
    Object.entries(stagedEntries).forEach(([key, entry]) => {
      if (entry === null) {
        delete merged[key];
      } else {
        merged[key] = entry;
      }
    });
    Object.entries(staged).forEach(([key, conditions]) => {
      const entry = {
        ...(merged[key] && !Array.isArray(merged[key]) ? merged[key] : {}),
      };
      // Writes KEEP variant references (layered entries: overrides beat
      // variants; update_style no longer detaches). The printer skips the
      // reserved "variants" key, and the staged overrides printed here land
      // later in the document than the authoritative tag's variant CSS, so
      // the preview shows exactly the override-over-variant precedence the
      // server delivers.
      Object.entries(conditions).forEach(([condition, properties]) => {
        const values = {
          ...(entry[condition] &&
          typeof entry[condition] === 'object' &&
          !Array.isArray(entry[condition])
            ? entry[condition]
            : {}),
        };
        Object.entries(properties).forEach(([property, value]) => {
          const slot = slots[key]?.[condition]?.[property];
          if (slot) {
            values[property] = `var(${slot})`;
          } else if (value === null) {
            delete values[property];
          } else {
            values[property] = value;
          }
        });
        if (Object.keys(values).length) {
          entry[condition] = values;
        } else {
          delete entry[condition];
        }
      });
      if (Object.keys(entry).length) {
        merged[key] = entry;
      } else {
        delete merged[key];
      }
    });
    return merged;
  };

  /**
   * Prints the shape into the preview tag. Membership changes only.
   */
  const renderSheet = () => {
    if (!hasStaged()) {
      if (tag) {
        tag.textContent = '';
      }
      return;
    }
    // Media tokens in the sheet (whole-entry stages, base values) resolve as
    // a pre-print transform, mirroring the server print sites (the printer
    // pair stays dumb and equivalence-locked). The table is read per render
    // so a pick can extend it and re-stage.
    const merged = resolveMapMedia(mergedMap(), getMediaUrls?.() ?? {});
    let css = printMap(merged, guide?.conditions);
    const roots = containerRootsCss(merged, guide);
    if (roots !== '') {
      css = css === '' ? roots : `${css}\n${roots}`;
    }
    // Same text framing as the authoritative tag (CssStyleBackend wraps the
    // printed CSS in newlines).
    ensureTag().textContent = css === '' ? '' : `\n${css}\n`;
  };

  return {
    /**
     * Stages one property value (null = unset).
     *
     * A live value on an already-slotted triple is one inline write; the
     * first live value on a triple mints its slot and re-renders the sheet;
     * a null (or blank) value drops any slot and re-renders (omission).
     */
    stage: (key, condition, property, value) => {
      ((staged[key] ??= {})[condition] ??= {})[property] = value;
      if (!isLive(value)) {
        dropSlot(key, condition, property);
        renderSheet();
        return;
      }
      if (slots[key]?.[condition]?.[property]) {
        applyValue(key, condition, property);
        return;
      }
      mintSlot(key, condition, property);
      applyValue(key, condition, property);
      renderSheet();
    },

    /**
     * The values a commit releases once it settles: per property the op
     * carries, what is staged for it NOW (the gesture the op settles),
     * else the op's own value.
     *
     * Read at dispatch, never at settle: release() drops only
     * still-matching values so a newer gesture staged during the flight
     * survives, and a capture's commit spells a reference (var(--text-xs))
     * over the literal it previewed (0.8125rem), so releasing by the op's
     * spelling would strand the literal in the overlay for good.
     *
     * @param {string} key
     * @param {string} condition
     * @param {object} properties
     *   The op's property -> value map (null = unset).
     *
     * @return {object}
     *   Property -> the value to release, for the same properties.
     */
    settling: (key, condition, properties) =>
      Object.fromEntries(
        Object.entries(properties).map(([property, value]) => {
          const values = staged[key]?.[condition];
          return [
            property,
            values && property in values ? values[property] : value,
          ];
        }),
      ),

    /**
     * Releases staged properties once their op settled, dropping their
     * slots and re-rendering when anything was released.
     *
     * Only entries still holding the settled value are released: a user who
     * kept scrubbing while the op was in flight has re-staged the property,
     * and that newer preview must survive its predecessor's settlement.
     */
    release: (key, condition, properties) => {
      const conditions = staged[key];
      const values = conditions?.[condition];
      if (!values) {
        return;
      }
      let released = false;
      Object.entries(properties).forEach(([property, value]) => {
        if (property in values && values[property] === value) {
          delete values[property];
          dropSlot(key, condition, property);
          released = true;
        }
      });
      if (!Object.keys(values).length) {
        delete conditions[condition];
      }
      if (!Object.keys(conditions).length) {
        delete staged[key];
      }
      if (released) {
        renderSheet();
      }
    },

    /**
     * Stages whole-entry replacements (the code editor's overlay), literal
     * in the sheet.
     *
     * @param {object} entriesByKey
     *   Selectable key -> style entry, or null to preview the key removed.
     */
    stageEntries: (entriesByKey) => {
      Object.assign(stagedEntries, entriesByKey);
      renderSheet();
    },

    /**
     * Releases staged entry replacements and re-renders.
     */
    releaseEntries: (keys) => {
      keys.forEach((key) => delete stagedEntries[key]);
      renderSheet();
    },

    /**
     * Re-projects the overlay after the base map changed (mirror replaced):
     * every slot's value is written again (the markup swap dropped the
     * roots' inline state) and the sheet re-renders over the new base.
     */
    baseChanged: () => {
      if (!hasStaged()) {
        return;
      }
      eachSlot(applyValue);
      renderSheet();
    },
  };
};
