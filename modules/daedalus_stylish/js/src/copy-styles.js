/**
 * The styles clipboard: copy a target's own styles, paste them at another.
 *
 * Two hotkeys (index.js registers them under daedalus_ui's dispatcher,
 * mod+alt+c and mod+alt+v in the selection context) and one slot between
 * them. Copy reads the styling target's own document out of the style
 * map, the same document the code view and a variant capture read: the
 * target's entry and every entry beneath it at an element-relative key
 * ('' the target itself, '::.cls' a part, '::> pb-text' a rule under an
 * element, a rule's continuations by descent), each as its condition
 * blocks plus the root references it wears. Paste rebases those relatives
 * under the new target (continuedKey, the one key continuation) and
 * emits the spine's own ops, one update_style per (key, condition) and
 * one apply_variant per reference, as one atomic batch under one history
 * label, so a paste is one undo. Nothing here is a second write path:
 * the batch is the code view's paste batch (entriesToOps) and the server
 * validates every op at its address exactly as it does the inspector's.
 *
 * Additive, like the code view's paste: a pasted document sets what it
 * names and unsets nothing, so the target keeps every property the copy
 * did not carry. What stays behind on copy: instance parts ('::.s-xx',
 * identity classes minted per content instance, which reach nothing on
 * another host) and fragment references (a composition's distributed
 * wear, owned by the composition that placed it), the capture walk's own
 * rules. What is skipped at paste, counted and told: a property the
 * target's landing does not grant on a closed-world (strict) site, where
 * the server would refuse the whole batch for it, and a variant whose
 * scope does not pair with its new key (scopeMatches, the offer rule the
 * picker filters by). On open and guarded sites every property goes to
 * the server, whose verdict is the one that counts.
 *
 * The slot is this window's: the leader alone resolves keys (a press in
 * the satellite is forwarded to the leader and judged there), so one
 * clipboard per editing page covers both windows, surviving selection
 * changes and page-tree moves until the page unloads. The toasts land
 * on the leader's messenger, where the hand that pressed the key is
 * looking when the panel is in-page; a satellite user sees the paste
 * land through the snapshot the leader broadcasts.
 */
import { entriesToOps } from './code/paste-door.js';
import { notify } from './notify.js';
import {
  bundleForKey,
  declarationForKey,
  isPlainObject,
  parseKey,
  propertiesClosed,
  propertyGranted,
} from './style-guide.js';
import {
  continuedKey,
  elementEntries,
  entryRefs,
  findVariant,
  isInstanceRelative,
  relativeUnder,
  scopeMatches,
} from './variant-scope.js';
import { SUFFIX } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/** @type {?{entries: object, wear: object}} */
let clipboard = null;

/**
 * The SUBJECT's key, what copy and paste act on: the selected element's
 * own key when no facet is seated, else the facet's {key, selector} as a
 * map key (`key::selector`). Copy and paste key the subject, never the
 * write address: the clipboard carries one element's own document, and
 * a paste lands it where the rows read.
 *
 * @param {?string} selected
 *   The committed selection's key (source.getSelected()).
 * @param {?{key: string, selector: ?string}} target
 *   The subject facet (source.getTarget()), or null for the element.
 *
 * @return {?string}
 *   The key, or null without a selection.
 */
export const targetKeyOf = (selected, target) => {
  if (!selected) {
    return null;
  }
  if (!target?.key) {
    return selected;
  }
  return target.selector ? `${target.key}${SUFFIX}${target.selector}` : target.key;
};

/**
 * Reads a key's own document out of the style map.
 *
 * `entries` is elementEntries()' shape (relative → condition blocks,
 * overrides only) minus the instance parts; `wear` is relative → the
 * variant ids the entry at that relative wears at its root ('' fragment
 * references, application order). A relative appears in `wear` only
 * when it wears something, so a pure-reference entry (no overrides of
 * its own) still travels as its wear.
 *
 * @param {object} map
 *   The style map (source.getStyleMap()).
 * @param {string} key
 *   An element or rule key.
 *
 * @return {{entries: Object<string, object>, wear: Object<string, string[]>}}
 */
export const documentOf = (map, key) => {
  const entries = {};
  Object.entries(elementEntries(map, key)).forEach(([relative, entry]) => {
    if (!isInstanceRelative(relative)) {
      entries[relative] = structuredClone(entry);
    }
  });
  const wear = {};
  const addWear = (relative, entry) => {
    if (isInstanceRelative(relative)) {
      return;
    }
    const refs = entryRefs(entry)
      .filter((ref) => ref.fragment === '')
      .map((ref) => ref.variant);
    if (refs.length) {
      wear[relative] = refs;
    }
  };
  addWear('', map?.[key]);
  Object.entries(map ?? {}).forEach(([mapKey, entry]) => {
    const relative = relativeUnder(key, mapKey);
    if (relative !== null) {
      addWear(relative, entry);
    }
  });
  return { entries, wear };
};

const declarationCount = (entry) =>
  Object.values(entry).reduce(
    (count, values) =>
      count + (isPlainObject(values) ? Object.keys(values).length : 0),
    0,
  );

/**
 * How many styles a document carries: its declarations plus its
 * references, the number the toasts speak.
 *
 * @param {{entries: object, wear: object}} copied
 *
 * @return {number}
 */
export const documentSize = (copied) =>
  Object.values(copied.entries).reduce(
    (count, entry) => count + declarationCount(entry),
    0,
  ) + Object.values(copied.wear).reduce((count, refs) => count + refs.length, 0);

/**
 * Plans a paste: the batch that lands a document at a target key.
 *
 * @param {{entries: object, wear: object}} copied
 *   A copied document (documentOf).
 * @param {string} targetKey
 *   The key to paste at (targetKeyOf), an element or a rule.
 * @param {object} context
 *   - guide: the style guide payload (the enforcement read, the landing
 *     walk on a strict site).
 *   - blockTypes, sectionLayouts: the uuid tables the landing and the
 *     scope pairing resolve by (source.getBlockTypes(),
 *     source.getSectionLayouts()).
 *   - variants: the roster's variant records (materials.ofKind('variant')),
 *     so a reference's scope can be paired before the send; a reference
 *     the roster does not carry is sent as it is, for the server to judge.
 *
 * @return {{operations: object[], count: number, skipped: {properties: number, variants: number}}}
 *   The batch (update_style ops first, then apply_variant, the shape
 *   commitBatch takes), how many styles it lands, and how many the
 *   plan left out, per kind.
 */
export const pastePlan = (
  copied,
  targetKey,
  { guide = {}, blockTypes = {}, sectionLayouts = {}, variants = [] } = {},
) => {
  const closed = propertiesClosed(guide);
  const entries = [];
  const wear = [];
  const skipped = { properties: 0, variants: 0 };
  Object.entries(copied.entries).forEach(([relative, entry]) => {
    const key = continuedKey(targetKey, relative);
    if (key === null) {
      skipped.properties += declarationCount(entry);
      return;
    }
    const parsed = parseKey(key);
    const landing = closed
      ? declarationForKey(
          guide,
          parsed,
          bundleForKey(parsed, blockTypes, sectionLayouts),
        )
      : null;
    Object.entries(entry).forEach(([condition, values]) => {
      if (!isPlainObject(values)) {
        return;
      }
      const properties = {};
      Object.entries(values).forEach(([property, value]) => {
        if (landing !== null && !propertyGranted(landing, property)) {
          skipped.properties += 1;
          return;
        }
        properties[property] = value;
      });
      if (Object.keys(properties).length) {
        entries.push({ key, condition, properties });
      }
    });
  });
  Object.entries(copied.wear).forEach(([relative, refs]) => {
    const key = continuedKey(targetKey, relative);
    if (key === null) {
      skipped.variants += refs.length;
      return;
    }
    refs.forEach((variant) => {
      const record = findVariant(variants, variant);
      if (
        record !== null &&
        !scopeMatches(record.scope, key, blockTypes, sectionLayouts)
      ) {
        skipped.variants += 1;
        return;
      }
      wear.push({ key, variant });
    });
  });
  const { operations, count } = entriesToOps(entries, wear);
  return { operations, count: count + wear.length, skipped };
};

/**
 * The copy hotkey's action: the styling target's document into the slot.
 *
 * @param {object} source
 *   The leader state source.
 *
 * @return {boolean}
 *   False without a selection (the press propagates); true otherwise,
 *   an empty target included (the toast is the answer).
 */
export const copyStyles = (source) => {
  const key = targetKeyOf(source.getSelected(), source.getTarget());
  if (key === null) {
    return false;
  }
  const copied = documentOf(source.getStyleMap(), key);
  const size = documentSize(copied);
  if (!size) {
    notify(
      'warning',
      Drupal.t('Nothing to copy: the selection has no styles of its own.'),
    );
    return true;
  }
  clipboard = copied;
  notify(
    'status',
    Drupal.formatPlural(size, 'Copied 1 style.', 'Copied @count styles.'),
  );
  return true;
};

/**
 * The paste hotkey's action: the slot's document at the styling target.
 *
 * The batch failure (a refused value, a scope the client could not
 * pair, the network) is reported by the source's own op failure report,
 * so the rejection is not phrased again here.
 *
 * @param {object} source
 *   The leader state source.
 * @param {object} guide
 *   The style guide payload.
 *
 * @return {boolean}
 *   False without a selection; true otherwise.
 */
export const pasteStyles = (source, guide) => {
  const key = targetKeyOf(source.getSelected(), source.getTarget());
  if (key === null) {
    return false;
  }
  if (clipboard === null) {
    notify('warning', Drupal.t('Nothing to paste: copy styles first.'));
    return true;
  }
  // The one gate: style of the target's element, as every other write
  // control of the inspector asks before it mounts.
  if (!source.capable(key, 'style')) {
    notify('warning', Drupal.t('Nothing pasted: this element cannot be styled here.'));
    return true;
  }
  const plan = pastePlan(clipboard, key, {
    guide,
    blockTypes: source.getBlockTypes(),
    sectionLayouts: source.getSectionLayouts(),
    variants: source.getMaterials().ofKind('variant'),
  });
  const skipped = plan.skipped.properties + plan.skipped.variants;
  if (!plan.operations.length) {
    notify(
      'warning',
      Drupal.t('Nothing pasted: none of the copied styles apply here.'),
    );
    return true;
  }
  source
    .commitBatch({ operations: plan.operations, label: Drupal.t('Paste styles') })
    .then(() => {
      const landed = Drupal.formatPlural(
        plan.count,
        'Pasted 1 style.',
        'Pasted @count styles.',
      );
      notify(
        'status',
        skipped
          ? `${landed} ${Drupal.formatPlural(
              skipped,
              '1 style that does not apply here was skipped.',
              '@count styles that do not apply here were skipped.',
            )}`
          : landed,
      );
    })
    .catch(() => {
      // The op failure report lands on the leader page.
    });
  return true;
};
