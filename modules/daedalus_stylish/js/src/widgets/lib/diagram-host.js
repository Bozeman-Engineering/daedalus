import { useEffect, useRef, useState } from 'preact/hooks';
import { highlightClaimsFor } from '../../highlight-claims.js';
import { styleTarget } from './use-highlight.js';

/**
 * The host seams a box-model ring needs from the row that opens it,
 * satellite-safe through the source: the canvas probe behind the
 * cells' computed placeholders and the centre readout, and the canvas
 * highlight the ring's touched sides drive.
 *
 * The probe reads at render time, but the render a settled commit
 * triggers can land BEFORE the preview tag releases and the canvas
 * markup finishes swapping — the read would freeze the outgoing state
 * into the diagram's placeholders. One deferred re-render per
 * authoritative change re-reads once the canvas is quiet. Off canvas
 * (the satellite, a target with no canvas element) the probe is null
 * and the cells show '–'.
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
 *   The widget model (its key names the bound target).
 * @param {boolean} options.open
 *   Whether the ring is mounted; nothing is read while it is closed.
 * @param {string[]} options.longhands
 *   The computed properties the probe reads.
 * @param {string} options.identity
 *   The authoritative state's identity (the layers the ring reads,
 *   stringified); a change schedules the deferred re-read.
 *
 * @return {{probe: ?{values: Object<string, string>, content: {width:
 *   number, height: number}}, center: ?string, highlight: Function}}
 *   highlight(properties | null) claims or releases the ring's slot.
 */
export const useDiagramHost = ({
  source,
  model,
  open,
  longhands,
  identity,
}) => {
  const [, setProbeTick] = useState(0);
  useEffect(() => {
    if (!open) {
      return undefined;
    }
    const timer = setTimeout(() => setProbeTick((tick) => tick + 1), 200);
    return () => clearTimeout(timer);
  }, [open, identity]);

  // The bound target for canvas chrome: model.key is `${key}::${sub}`
  // for a part binding, a plain selectable key for a root one.
  const { key, sub } = styleTarget(model.key);
  const claims = source?.setCanvasHighlight ? highlightClaimsFor(source) : null;
  const ownerRef = useRef(null);
  if (ownerRef.current === null) {
    ownerRef.current = {};
  }

  const probe = open
    ? (source?.readCanvas?.(key, sub, longhands) ?? null)
    : null;
  const center = probe
    ? `${Math.round(probe.content.width)} × ${Math.round(probe.content.height)}`
    : null;
  const highlight = (properties) => {
    if (!claims) {
      return;
    }
    if (properties) {
      claims.claim(ownerRef.current, { key, sub, properties });
    } else {
      claims.release(ownerRef.current);
    }
  };
  return { probe, center, highlight };
};
