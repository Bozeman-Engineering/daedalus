import { useEffect, useRef } from 'preact/hooks';
import { highlightClaimsFor } from '../../highlight-claims.js';

/**
 * Splits a bound style key into the canvas-highlight target:
 * `${key}::${sub}` for a part binding, a plain selectable key
 * otherwise.
 *
 * @param {string} modelKey
 *
 * @return {{key: string, sub: ?string}}
 */
export const styleTarget = (modelKey) => {
  const pos = modelKey.indexOf('::');
  return pos === -1
    ? { key: modelKey, sub: null }
    : { key: modelKey.slice(0, pos), sub: modelKey.slice(pos + 2) };
};

/**
 * The generic row-level canvas-highlight wiring.
 *
 * One hook makes any panel row a canvas-highlight consumer: spread the
 * returned handlers on the row's container(s) and the properties the
 * row edits tint their invisible geometry on the canvas while the row
 * is touched. Hovering the row highlights; focus anywhere inside (an
 * open editor field, keyboard stepping) HOLDS the highlight after the
 * pointer leaves — the box-model diagram's precedence at row
 * granularity. A scrub holds through its pointer capture: captured
 * pointers never fire the row's pointerleave, so the hover claim
 * survives until release. Claims ride the per-source claim stack
 * (highlight-claims.js), so rows, diagrams, and future consumers
 * arbitrate instead of clobbering each other's held highlights.
 *
 * Rows never check paint eligibility: painterless properties claim
 * like any other and the painter registry silently paints nothing for
 * them (the property-keyed spine's contract).
 *
 * @param {?object} source
 *   The state source (null/undefined degrades to inert handlers, so
 *   rows render fine without a host).
 * @param {?string} key
 *   The selectable key of the styled target.
 * @param {?string} sub
 *   The part selector under the key, or null for the whole element.
 * @param {string[]} properties
 *   The properties this row edits.
 *
 * @return {object}
 *   Handler props to spread on the row's container element(s).
 */
export const useRowHighlight = (source, key, sub, properties) => {
  const ownerRef = useRef(null);
  if (ownerRef.current === null) {
    ownerRef.current = {};
  }
  const stateRef = useRef({ hovered: false, focused: false });
  const claims = source?.setCanvasHighlight ? highlightClaimsFor(source) : null;
  const claimsRef = useRef(null);
  claimsRef.current = claims;

  // Unmount releases whatever this row holds — a highlight must never
  // outlive its row.
  useEffect(() => () => claimsRef.current?.release(ownerRef.current), []);

  if (!claims || !key) {
    return {};
  }

  const update = () => {
    const { hovered, focused } = stateRef.current;
    if (hovered || focused) {
      claims.claim(ownerRef.current, { key, sub, properties });
    } else {
      claims.release(ownerRef.current);
    }
  };

  return {
    onPointerEnter: () => {
      stateRef.current.hovered = true;
      update();
    },
    onPointerLeave: () => {
      stateRef.current.hovered = false;
      update();
    },
    onFocusIn: () => {
      stateRef.current.focused = true;
      update();
    },
    onFocusOut: (event) => {
      // Focus moving WITHIN the container keeps the hold (field →
      // unit select); only focus leaving it releases.
      if (event.currentTarget.contains(event.relatedTarget)) {
        return;
      }
      stateRef.current.focused = false;
      update();
    },
  };
};
