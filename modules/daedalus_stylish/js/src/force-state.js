/**
 * @file
 * Selection-scoped force state: DevTools-style :hov for the canvas.
 *
 * The inspector's "Editing at" state axis force-states the SELECTED element:
 * both printers compile every pseudo-class condition to also match a
 * data-force-state attribute (':is(:hover, [data-force-state~="hover"])'),
 * so stamping the attribute makes the element render its hover/focus/active
 * styles without the real interaction. This module is the stamping half —
 * leader-only by construction (the satellite window has no canvas; its
 * force-state intents land in the leader source, which this subscribes to).
 *
 * What gets stamped: the selection's key root plus every canvas element its
 * styled part selectors resolve to (computed-read's resolveTargets — the
 * donut-honoring read, so nested containers'/blocks' own pseudo styles
 * never force along), plus the drilled part's elements so a preview under
 * a forced state shows before its first commit. Mutation responses swap
 * canvas markup wholesale, which would silently drop the stamp mid-edit —
 * so while a state is forced, a per-frame loop re-resolves and re-stamps
 * (the canvas-highlight tracker posture; idle cost is zero because the loop
 * only runs while a force state is active, a transient editing pose).
 */

import { resolveTargets } from './computed-read.js';

const ATTRIBUTE = 'data-force-state';

/**
 * The part selectors the style map styles under a key ('.quote' from
 * 'block:{uuid}::.quote').
 */
const styledSubs = (styleMap, key) => {
  const prefix = `${key}::`;
  const subs = new Set();
  Object.keys(styleMap ?? {}).forEach((mapKey) => {
    if (mapKey.startsWith(prefix)) {
      subs.add(mapKey.slice(prefix.length));
    }
  });
  return subs;
};

/**
 * Wires the force-state stamping to the leader source.
 *
 * The source owns the state (selection-scoped: any selection move clears
 * it); this owns the DOM consequence.
 */
export const createForceState = ({ source }) => {
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
    const subs = styledSubs(source.getStyleMap(), key);
    const drilled = source.getSelectedPart();
    if (drilled) {
      subs.add(drilled);
    }
    const next = new Set(resolveTargets(key, null));
    subs.forEach((sub) => {
      resolveTargets(key, sub).forEach((element) => next.add(element));
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
