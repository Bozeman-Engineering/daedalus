import { useEffect, useRef, useState } from 'preact/hooks';
import { highlightClaimsFor } from '../../highlight-claims.js';
import { styleTarget } from './use-highlight.js';

/**
 * The identity of what a per-property reader sees: each distinct stack
 * among a model's `stacks` (several properties share one box's stack;
 * a binding over two boxes carries two), by key, with its layers'
 * ids and styles. Stringified by the callers that re-read or reset on
 * an authoritative change (the diagram host's deferred probe, the
 * box-model diagram's draft), since the stacks are rebuilt every render
 * and their identity is what their layers say.
 *
 * @param {Object<string, object>} stacks
 *   A control model's `stacks` (property → stack).
 *
 * @return {Array<[string, Array<[string, object]>]>}
 */
export const stacksIdentity = (stacks) => {
  const seen = new Map();
  Object.values(stacks ?? {}).forEach((stack) => {
    if (stack && !seen.has(stack.key)) {
      seen.set(
        stack.key,
        stack.layers.map((layer) => [layer.id, layer.styles]),
      );
    }
  });
  return [...seen.entries()];
};

/**
 * The host seams a box-model ring needs from the row that opens it,
 * satellite-safe through the source: the canvas probe behind the
 * cells' computed placeholders and the centre readout, and the canvas
 * highlight the ring's touched sides drive.
 *
 * The two seams address two different boxes on purpose. The probe reads
 * the binding's HOME box (`model.home.key`, the selected element's own
 * key): a rule has no geometry, so the computed placeholders and the
 * content readout can only come from the element on the canvas,
 * whatever address its writes go to. The highlight claims at the
 * family's WRITE key (`model.keys[property]`, the row's write property:
 * the shorthand, or the first granted side under a subset grant), the
 * address every row consumer claims at (choice rows, the advanced rows,
 * `styleTarget(model.keys[property])`), so under a rule target the
 * ring tints every match of the rule exactly as the advanced Padding
 * row does, and on the element itself the two keys coincide.
 *
 * The probe reads at render time, but the render a settled commit
 * triggers can land BEFORE the preview tag releases and the canvas
 * markup finishes swapping — the read would freeze the outgoing state
 * into the diagram's placeholders. One deferred re-render per
 * authoritative change (the model's stacks, stacksIdentity, at the
 * editing compound) re-reads once the canvas is quiet. Off canvas (the
 * satellite, a target with no canvas element) the probe is null and the
 * cells show '–'.
 *
 * The highlight rides the per-source claim stack (highlight-claims.js)
 * so a neighboring row's hover-and-leave (the gap row's strips) falls
 * back to the ring's held sides instead of clearing them; the ring owns
 * ONE claim slot, re-claimed in place as its touched sides change and
 * released on null (the diagram's onHighlight(null) on unmount).
 *
 * @param {object} options
 * @param {?object} options.source
 *   The state source (readCanvas, setCanvasHighlight).
 * @param {object} options.model
 *   The widget model (its home box is the probe target, its keys the
 *   highlight's address, its stacks the authoritative state).
 * @param {boolean} options.open
 *   Whether the ring is mounted; nothing is read while it is closed.
 * @param {string} options.property
 *   The family's write property, an owned name of the model: the
 *   highlight claims at `model.keys[property]`.
 * @param {string[]} options.longhands
 *   The computed properties the probe reads.
 *
 * @return {{probe: ?{values: Object<string, string>, bases: {rootPx:
 *   ?number, fontPx: ?number}, content: ?{width: number, height:
 *   number}}, center: ?string, highlight: Function}}
 *   highlight(properties | null) claims or releases the ring's slot.
 *   `center` is null without a probe and for a generated box (the
 *   probe's content size is null there: a box has no rect to measure,
 *   though its computed values still read through its host). `bases`
 *   are the canvas's measured conversion bases (readCanvas), what the
 *   ring's px placeholders convert into the gesture's unit through.
 */
export const useDiagramHost = ({ source, model, open, property, longhands }) => {
  const identity = JSON.stringify([
    model.condition,
    stacksIdentity(model.stacks),
  ]);
  const [, setProbeTick] = useState(0);
  useEffect(() => {
    if (!open) {
      return undefined;
    }
    const timer = setTimeout(() => setProbeTick((tick) => tick + 1), 200);
    return () => clearTimeout(timer);
  }, [open, identity]);

  // Both targets split as `${key}::${selector}` for a part or a rule, a
  // plain selectable key for the root: the home box for the probe, the
  // family's write key for the highlight.
  const home = styleTarget(model.home.key);
  const write = styleTarget(model.keys[property]);
  const claims = source?.setCanvasHighlight ? highlightClaimsFor(source) : null;
  const ownerRef = useRef(null);
  if (ownerRef.current === null) {
    ownerRef.current = {};
  }

  const probe = open
    ? (source?.readCanvas?.(home.key, home.selector, longhands) ?? null)
    : null;
  const center = probe?.content
    ? `${Math.round(probe.content.width)} × ${Math.round(probe.content.height)}`
    : null;
  const highlight = (properties) => {
    if (!claims) {
      return;
    }
    if (properties) {
      claims.claim(ownerRef.current, {
        key: write.key,
        selector: write.selector,
        properties,
      });
    } else {
      claims.release(ownerRef.current);
    }
  };
  return { probe, center, highlight };
};
