/**
 * @file
 * Selection-scoped force state: DevTools-style :hov for the canvas.
 *
 * The inspector's "Editing at" state axis force-states the selection,
 * its subject facet and its write address: both printers compile every
 * posable pseudo-class condition, and every posable state QUALIFIER at
 * its compound, to also match a data-force-state attribute
 * (':is(:hover, [data-force-state~="hover"])'), so stamping the attribute
 * makes an element render its hover/focus/active styles without the real
 * interaction. This module is the stamping half
 * — leader-only by construction (the satellite window has no canvas; its
 * force-state intents land in the leader source, which this subscribes
 * to).
 *
 * What gets stamped: the selection's key root plus every canvas element its
 * styled selectors resolve to (computed-read's resolveTargets — the
 * donut-honoring read over the selector's structural reach, so nested
 * containers'/blocks' own pseudo styles never force along), plus, for a
 * state qualifier standing on a compound of a styled selector (bare, or
 * inside an `:is()` member of it: editing-at.js stateQualifiers finds
 * both with the compound's index), the
 * targets of the selector truncated after that compound (the grammar's
 * prefix, resolved the same way): `li:hover > ul` under a posed hover
 * stamps the `li`s, the elements whose hover reveals the `ul`s,
 * `.menu-item:is(:focus-within, :hover) > ul` stamps the `.menu-item`s
 * the same way (the matcher reads the prefix's states as holding), and
 * `&:hover > ul` stamps the owner, already the key root. Only the posed
 * state's qualifiers stamp (the attribute carries one state; a `:focus`
 * qualifier's `li` under a posed hover would light nothing of its own
 * rule and could light another's). Then the subject facet's every
 * match (the selection's part, tag tier or chain: the rows the panel
 * reads) and the write address's every match (the rule the panel writes
 * at poses where it writes, so a preview under a forced state shows on
 * every match before its first commit), each with its prefixes. A
 * generated box's selector (`&::before`, `.quote::after`) resolves to
 * its HOSTS, which is exactly the stamp a box needs: the printers put
 * the state before the pseudo
 * (`:is(:hover, [data-force-state~="hover"])::before`), so the
 * attribute is read off the host and the box has none. Mutation
 * responses swap canvas markup wholesale, which would silently drop the
 * stamp mid-edit — so while a state is forced, a per-frame loop
 * re-resolves and re-stamps (the canvas-highlight tracker posture; idle
 * cost is zero because the loop only runs while a force state is
 * active, a transient editing pose).
 */

import { prefix } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { resolveTargets } from './computed-read.js';
import { stateQualifiers } from './editing-at.js';

const ATTRIBUTE = 'data-force-state';

/**
 * The relative selectors the style map styles under a key ('.quote' from
 * 'block:{uuid}::.quote', '> d-text' from 'container:{uuid}::> d-text').
 *
 * @param {?object} styleMap
 *   The style map.
 * @param {string} key
 *   The owner key.
 *
 * @return {Set<string>}
 *   The relative selectors.
 */
const styledSelectors = (styleMap, key) => {
  const prefixLength = `${key}::`.length;
  const selectors = new Set();
  Object.keys(styleMap ?? {}).forEach((mapKey) => {
    if (mapKey.startsWith(`${key}::`)) {
      selectors.add(mapKey.slice(prefixLength));
    }
  });
  return selectors;
};

/**
 * The elements a posed state stamps for one (key, selector): the
 * selector's own targets, then the targets of its prefix at every
 * compound carrying the posed state as a qualifier.
 *
 * @param {string} key
 *   The rule's owner key.
 * @param {?string} selector
 *   The rule's relative selector, or null for the owner itself.
 * @param {string} state
 *   The posed state-axis condition name.
 * @param {Object} conditions
 *   guide.conditions.
 *
 * @return {Element[]}
 *   The elements to stamp, each once.
 */
export const stampTargets = (key, selector, state, conditions) => {
  const elements = resolveTargets(key, selector, conditions);
  stateQualifiers(selector, conditions).forEach(({ index, state: name }) => {
    if (name !== state) {
      return;
    }
    resolveTargets(key, prefix(selector, index), conditions).forEach(
      (element) => {
        if (!elements.includes(element)) {
          elements.push(element);
        }
      },
    );
  });
  return elements;
};

/**
 * Wires the force-state stamping to the leader source.
 *
 * The source owns the state (selection-scoped: any selection move clears
 * it); this owns the DOM consequence. The guide's condition table says
 * which pseudo-class qualifiers are the posed state's.
 *
 * @param {object} options
 *   The wiring.
 * @param {object} options.source
 *   The leader state source (its force state).
 * @param {object} [options.guide]
 *   The style guide payload (its condition table).
 */
export const createForceState = ({ source, guide = {} }) => {
  const conditions = guide?.conditions ?? {};
  let stamped = new Set();
  let frame = null;

  const clear = () => {
    stamped.forEach((element) => element.removeAttribute(ATTRIBUTE));
    stamped = new Set();
  };

  const stamp = () => {
    const state = source.getForceState();
    const key = source.getSelected();
    if (state === null || !key) {
      clear();
      return;
    }
    const next = new Set(resolveTargets(key, null));
    styledSelectors(source.getStyleMap(), key).forEach((selector) => {
      stampTargets(key, selector, state, conditions).forEach((element) =>
        next.add(element),
      );
    });
    [source.getTarget(), source.getAddress()].forEach((fact) => {
      if (fact) {
        stampTargets(fact.key, fact.selector, state, conditions).forEach(
          (element) => next.add(element),
        );
      }
    });
    stamped.forEach((element) => {
      if (!next.has(element)) {
        element.removeAttribute(ATTRIBUTE);
      }
    });
    next.forEach((element) => {
      if (element.getAttribute(ATTRIBUTE) !== state) {
        element.setAttribute(ATTRIBUTE, state);
      }
    });
    stamped = next;
  };

  const loop = () => {
    if (source.getForceState() === null) {
      frame = null;
      clear();
      return;
    }
    stamp();
    frame = requestAnimationFrame(loop);
  };

  source.onForceStateChange(() => {
    if (source.getForceState() === null) {
      if (frame !== null) {
        cancelAnimationFrame(frame);
        frame = null;
      }
      clear();
    } else if (frame === null) {
      frame = requestAnimationFrame(loop);
      // Stamp immediately too: the forced look must not wait a frame
      // behind the select change that asked for it.
      stamp();
    }
  });
};
