import { resolveTargets, flowChildRects } from './computed-read.js';
import { highlightRects } from './canvas-painters.js';

/**
 * The canvas highlight: transient tint chrome for invisible geometry.
 *
 * DevTools-style overlay boxes that tint a style target's invisible
 * geometry (padding bands, margin bands, gap strips) on the REAL canvas
 * while a property is being hovered, scrubbed, or edited. The boxes
 * float in #daedalus-root with pointer-events: none (the outline.js
 * pattern — they can never capture a click, and they are the exact
 * rects an on-canvas drag handle would need if that parked idea ever
 * ships). Never injected CSS: a padding band cannot be painted on the
 * element itself without dishonestly covering its real background.
 *
 * Geometry rides daedalus_ui's shared per-frame tracker
 * (app.geometry.createTracker): every tracked match is re-measured each
 * animation frame, and getComputedStyle on the measured element feeds
 * the painter arithmetic — the SAME read that feeds the diagram's
 * computed placeholders (computed-read.js), so the number in the panel
 * and the band on the canvas can never disagree. Scrub previews write
 * to the page's preview <style> tag per tick, so the band tracks the
 * gesture with zero extra wiring: the tint measures what preview did.
 *
 * What to show arrives through the source's canvas-highlight state
 * (setCanvasHighlight), so panel widgets in BOTH hosts drive one
 * renderer: the in-page widgets set it directly, a satellite's intents
 * ride the BroadcastChannel and land in the leader source. The
 * highlight spec is property-keyed: null, or {key, sub, properties} —
 * a style target (selectable key + optional part selector) plus WHICH
 * PROPERTIES are being edited. The painter registry
 * (canvas-painters.js) owns how each property tints; painterless
 * properties no-op silently, so consumers hand over whatever they edit
 * without checking eligibility. Multi-match part selectors tint EVERY
 * match; a missing element paints nothing (honest and self-healing —
 * matches re-resolve when markup swaps).
 */
export const createCanvasHighlight = ({ app, source }) => {
  const root = document.getElementById('daedalus-root');
  if (!root) {
    return null;
  }
  const container = document.createElement('div');
  container.className = 'daedalus-stylish-bands';
  container.setAttribute('aria-hidden', 'true');
  container.hidden = true;
  root.appendChild(container);

  let spec = null;
  // The current match elements, indexed — the tracker's cache identity is
  // the index name ('m:0'), and resolve re-queries the whole list on a
  // miss so a markup swap that replaces every match heals in one frame.
  let matches = [];
  const tracker = app.geometry.createTracker({
    resolve: (index) => {
      matches = spec ? resolveTargets(spec.key, spec.sub) : [];
      return matches[Number(index)] ?? null;
    },
    // A cached node is stale the moment it is no longer THIS index's match
    // — a changed highlight target must never reuse the old target's
    // still-connected elements.
    validate: (element, index) => matches[Number(index)] === element,
  });

  // The painted boxes, pooled: band counts change rarely (property hovers
  // come and go), so reuse beats rebuilding the DOM every frame.
  const pool = [];
  const paint = (bands) => {
    while (pool.length < bands.length) {
      const box = document.createElement('div');
      container.appendChild(box);
      pool.push(box);
    }
    bands.forEach((band, i) => {
      const box = pool[i];
      box.className = `daedalus-stylish-band daedalus-stylish-band--${band.kind}`;
      box.hidden = false;
      // Painted in document coordinates (the boxes are absolute in
      // #daedalus-root) so the bands scroll with the element they tint
      // instead of trailing by a frame.
      const { left, top } = app.geometry.documentPosition(band);
      box.style.transform = `translate(${left}px, ${top}px)`;
      box.style.width = `${band.width}px`;
      box.style.height = `${band.height}px`;
    });
    for (let i = bands.length; i < pool.length; i++) {
      pool[i].hidden = true;
    }
  };

  const frame = () => {
    const bands = [];
    // Snapshot the count: resolve() may replace `matches` mid-loop when a
    // measure misses; stale indexes then measure null and skip a frame.
    const count = matches.length;
    for (let i = 0; i < count; i++) {
      const measured = tracker.measure(`m:${i}`, String(i));
      if (!measured) {
        continue;
      }
      // childRects stays LAZY: only the gap painter walks children, so
      // padding/margin frames never pay for it.
      bands.push(
        ...highlightRects(spec.properties, {
          rect: measured.rect,
          computed: getComputedStyle(measured.element),
          childRects: () => flowChildRects(measured.element),
        }),
      );
    }
    paint(bands);
  };

  source.onCanvasHighlightChange((next) => {
    spec = next;
    if (!spec) {
      tracker.stop();
      container.hidden = true;
      pool.forEach((box) => {
        box.hidden = true;
      });
      return;
    }
    // Fresh matches for the new target; drop cached elements beyond them.
    matches = resolveTargets(spec.key, spec.sub);
    tracker.prune(matches.map((match, i) => `m:${i}`));
    container.hidden = false;
    tracker.start(frame);
  });

  // A settled commit replaces the mirror and may swap markup wholesale —
  // re-resolve so a held highlight (open editor) tracks the new nodes and
  // a changed match COUNT (a part added/removed) is caught, which element
  // staleness alone would miss.
  source.onStyleMapChange(() => {
    if (spec) {
      matches = resolveTargets(spec.key, spec.sub);
      tracker.prune(matches.map((match, i) => `m:${i}`));
    }
  });

  return { container };
};
