/**
 * Markup verification: the canvas observer, demoted to logging.
 *
 * The markup annotation (`_element.markup`, markup-index.js) is the
 * canonical instance model: presence, tags, and instance rows are all
 * decided server-side from entity data and delivered on every mirror
 * replacement. The DOM is a projection of that model, so the old
 * presence-scraping MutationObserver survives only as a VERIFIER: it
 * watches the canvas for mutations touching the modeled vocabulary
 * (declared part classes, minted instance classes) and logs any
 * disagreement between the DOM and the annotation through the suite's
 * debug logging channel. It re-renders nothing and answers no questions.
 *
 * Disagreements are not all bugs: mid-gesture content (typing before blur
 * commits, a client-side link preview) is legitimately ahead of the model
 * until its op round-trips, and editor chrome (CKEditor's fake-selection
 * clone) can wear content classes. The log line is the diagnostic, a
 * mismatch that persists across a mutation response is model drift; one
 * that resolves on commit is the gesture in flight.
 *
 * Everything is gated on the logger being enabled
 * (Drupal.Daedalus.logging.enable('daedalus_ui')), so the
 * observer costs one enabled-check per mutation batch when off.
 */

import { partPresence } from '../../blueprint/markup-index';
import { PART_SELECTOR } from './compose';

/**
 * The declaration-derived vocabulary: declared part classes plus namespace
 * prefixes ('.s-*' => 's-'), read once from the anatomy payload
 * (drupalSettings.daedalusUi.anatomy, static per page load) — part
 * classes and owner pattern parts from the elements trees across kinds,
 * block types, block plugins, and layouts, instance-namespace prefixes
 * from the payload's `instances` lane (the spine's `instances:`
 * declarations); `false` caches "nothing declared".
 *
 * @return {?{selector: string, classes: Set<string>, prefixes: string[]}}
 */
const PATTERN_PART = /^\.[A-Za-z0-9_-]*\*$/;
let vocabulary = null;
const declaredVocabulary = () => {
  if (vocabulary === null) {
    const classes = new Set();
    const prefixes = [];
    const walk = (declaration) => {
      for (const [selector, child] of Object.entries(
        declaration?.elements ?? {},
      )) {
        if (PART_SELECTOR.test(selector)) {
          classes.add(selector.slice(1));
          walk(child);
        } else if (PATTERN_PART.test(selector)) {
          prefixes.push(selector.slice(1, -1));
          walk(child);
        }
      }
    };
    const anatomy = drupalSettings.daedalusUi?.anatomy ?? {};
    for (const group of [
      anatomy.kinds,
      anatomy.blockTypes,
      anatomy.blockPlugins,
      anatomy.layouts,
    ]) {
      Object.values(group ?? {}).forEach(walk);
    }
    for (const pattern of Object.keys(anatomy.instances ?? {})) {
      if (PATTERN_PART.test(pattern)) {
        prefixes.push(pattern.slice(1, -1));
      }
    }
    vocabulary =
      classes.size === 0 && prefixes.length === 0
        ? false
        : {
            selector: [...classes].map((name) => `.${name}`).join(', '),
            classes,
            prefixes,
          };
  }
  return vocabulary || null;
};

/**
 * The modeled classes an element wears (declared parts + instances).
 */
const modeledClasses = (element, terms) =>
  [...element.classList].filter(
    (name) =>
      terms.classes.has(name) ||
      terms.prefixes.some(
        (prefix) => name.startsWith(prefix) && name.length > prefix.length,
      ),
  );

/**
 * DOM presence of one selector inside an owner, ownership-honored: a match
 * inside a nested selectable is that owner's business.
 */
const domPresence = (owner, selector) =>
  owner.matches(selector) ||
  [...owner.querySelectorAll(selector)].some(
    (match) => match.closest('[data-selectable]') === owner,
  );

/**
 * Verifies one owner's modeled classes against the annotation, logging
 * disagreements.
 */
const verifyOwner = (owner, terms, log) => {
  const key = owner.getAttribute('data-selectable');
  const seen = new Set();
  // Every declared part is checked both ways (a modeled-present part the
  // canvas dropped is as much a finding as a stray one); instance classes
  // join from what the owner's subtree actually wears.
  const candidates = [...terms.classes];
  for (const element of owner.querySelectorAll('[class]')) {
    if (element.closest('[data-selectable]') !== owner) {
      continue;
    }
    modeledClasses(element, terms).forEach((name) => candidates.push(name));
  }
  for (const name of candidates) {
    if (seen.has(name)) {
      continue;
    }
    seen.add(name);
    const selector = `.${name}`;
    const modeled = partPresence(key, selector);
    if (modeled === null) {
      continue;
    }
    const rendered = domPresence(owner, selector);
    if (rendered !== modeled) {
      log(
        'verify',
        `${key} ${selector}: annotation says ${modeled ? 'present' : 'absent'}, canvas shows ${rendered ? 'present' : 'absent'} (mid-gesture drift or model bug)`,
      );
    }
  }
};

/**
 * Starts the verification observer (idempotent).
 *
 * Roots at the canvas stage, which wraps the page but not the editing
 * chrome; the body fallback keeps a stageless page honest. Mutations are
 * rAF-coalesced and verified per affected owning element.
 */
let observer = null;
export const initMarkupVerification = () => {
  if (observer) {
    return;
  }
  const logger = Drupal.Daedalus.logger('daedalus_ui');
  let pending = new Set();
  let queued = false;
  observer = new MutationObserver((mutations) => {
    if (!logger.enabled) {
      return;
    }
    for (const mutation of mutations) {
      const target =
        mutation.target instanceof Element
          ? mutation.target
          : mutation.target.parentElement;
      const owner = target?.closest('[data-selectable]');
      if (owner) {
        pending.add(owner);
      }
    }
    if (pending.size === 0 || queued) {
      return;
    }
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      const owners = pending;
      pending = new Set();
      const terms = declaredVocabulary();
      if (!terms) {
        return;
      }
      owners.forEach((owner) => {
        if (owner.isConnected) {
          verifyOwner(owner, terms, logger.log);
        }
      });
    });
  });
  observer.observe(
    document.getElementById('daedalus-ui-stage') ?? document.body,
    {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['class'],
    },
  );
};
