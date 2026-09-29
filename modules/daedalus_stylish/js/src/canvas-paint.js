import { pseudoOf, resolveTargets } from './computed-read.js';
import { paintCoverage } from './code/layers.js';

/**
 * The paint canvas: Outline Variant projected onto the page.
 *
 * ONE renderer behind the one toggled gesture: each covered canvas
 * element gets an outline box per painted layer in the shared focus
 * treatment (--daedalus-focus — focus chrome, never a per-variant hue;
 * identity duty lives with the chips and dots), and the same coverage
 * marks the covered rows in daedalus_ui's page elements panel
 * (app.tree.setRowMarks), so canvas and panel outline together and clear
 * together. Overlapping layers stack inward as concentric rings with a
 * corner tag strip naming them (the labels disambiguate — color no
 * longer does). A flash layer (a hovered wear-bar row) pulses and stays
 * canvas-only: it previews coverage without committing the toggle, and
 * only toggled layers mark the panel.
 *
 * The boxes float in #daedalus-root with pointer-events: none, exactly
 * like the highlight bands (canvas-highlight.js): chrome that can never
 * capture a click. Geometry rides daedalus_ui's shared per-frame
 * tracker; coverage is recomputed only when the paint spec or the style
 * map changes (paintCoverage over client-side map refs — nothing
 * round-trips), so the frame loop is transform updates only.
 *
 * What to paint arrives through the source's canvas-paint state
 * (setCanvasPaint), so both hosts drive one renderer: the in-page code
 * views and variant area set it directly, a satellite's intents ride the
 * BroadcastChannel and land in the leader source.
 */
export const createCanvasPaint = ({ app, source }) => {
  const root = document.getElementById('daedalus-root');
  if (!root) {
    return null;
  }
  const container = document.createElement('div');
  container.className = 'daedalus-stylish-paint';
  container.setAttribute('aria-hidden', 'true');
  container.hidden = true;
  root.appendChild(container);

  let spec = null;
  // One entry per covered canvas element: the element, its outline boxes
  // (stacking order = the spec's layer order), and the tag strip where
  // several layers overlap. Rebuilt whenever the spec or the style map
  // changes; per-frame work is measurement and transforms only.
  let entries = [];
  const tracker = app.geometry.createTracker({
    resolve: (index) => entries[Number(index)]?.element ?? null,
    // A rebuilt entry list must never reuse another entry's still-connected
    // element under a stale name.
    validate: (element, index) => entries[Number(index)]?.element === element,
  });

  // The page-elements half of the gesture: covered rows mark in the tree
  // (focus treatment there too). Guarded — an older daedalus_ui
  // without the seam just shows the canvas half.
  const markRows = (keys) => app.tree?.setRowMarks?.(keys);

  const rebuild = () => {
    container.textContent = '';
    entries = [];
    if (!spec) {
      return;
    }
    const byId = new Map(spec.layers.map((layer) => [layer.id, layer]));
    // Only TOGGLED layers mark the panel: the hover flash is a canvas
    // preview, and pulsing tree rows on every row hover would be noise.
    const toggled = new Set(
      spec.layers.filter((layer) => !layer.flash).map((layer) => layer.id),
    );
    const marks = new Set();
    // Coverage per map target, then merged per canvas ELEMENT: a collapsed
    // container carries its part class on its own root, so a base key and
    // its part key can resolve to the same node — one ring set, not two.
    const covered = new Map();
    paintCoverage(source.getStyleMap(), spec.layers).forEach((target) => {
      if (target.layers.some((id) => toggled.has(id))) {
        // A part target marks its base row: parts belong to their owner
        // in the tree's selection universe.
        marks.add(target.key);
      }
      // A generated box's coverage marks its owner's row but rings no
      // element: the ring is rect chrome and the box has no rect, only
      // its host does, and a ring on the host would name a layer that
      // covers the box, not the host.
      if (pseudoOf(target.selector) !== null) {
        return;
      }
      resolveTargets(target.key, target.selector).forEach((element) => {
        let ids = covered.get(element);
        if (!ids) {
          ids = [];
          covered.set(element, ids);
        }
        target.layers.forEach((id) => {
          if (!ids.includes(id)) {
            ids.push(id);
          }
        });
      });
    });
    markRows([...marks]);
    covered.forEach((ids, element) => {
      const boxes = ids.map((id) => {
        const layer = byId.get(id);
        const box = document.createElement('div');
        box.className = `daedalus-stylish-paint__outline${layer.flash ? ' daedalus-stylish-paint__outline--flash' : ''}`;
        box.hidden = true;
        container.appendChild(box);
        return box;
      });
      // Corner tags only where layers overlap: a lone ring's meaning is
      // in the panel toggle; stacked rings need naming in place, and the
      // labels are what tells them apart.
      let tags = null;
      if (ids.length > 1) {
        tags = document.createElement('div');
        tags.className = 'daedalus-stylish-paint__tags';
        tags.hidden = true;
        ids.forEach((id) => {
          const layer = byId.get(id);
          const tag = document.createElement('span');
          tag.className = 'daedalus-stylish-paint__tag';
          tag.textContent =
            layer.variant == null
              ? Drupal.t('This element')
              : (layer.label ?? layer.variant);
          tags.appendChild(tag);
        });
        container.appendChild(tags);
      }
      entries.push({ element, boxes, tags });
    });
    tracker.prune(entries.map((entry, i) => `p:${i}`));
  };

  // Each ring steps inward so stacked outlines stay individually visible.
  const INSET = 3;
  const frame = () => {
    for (let i = 0; i < entries.length; i++) {
      const entry = entries[i];
      const measured = tracker.measure(`p:${i}`, String(i));
      if (!measured) {
        entry.boxes.forEach((box) => {
          box.hidden = true;
        });
        if (entry.tags) {
          entry.tags.hidden = true;
        }
        continue;
      }
      const { rect } = measured;
      entry.boxes.forEach((box, j) => {
        const inset = j * INSET;
        box.hidden = false;
        box.style.transform = `translate(${rect.left + inset}px, ${rect.top + inset}px)`;
        box.style.width = `${Math.max(0, rect.width - 2 * inset)}px`;
        box.style.height = `${Math.max(0, rect.height - 2 * inset)}px`;
      });
      if (entry.tags) {
        entry.tags.hidden = false;
        entry.tags.style.transform = `translate(${rect.left}px, ${rect.top}px)`;
      }
    }
  };

  source.onCanvasPaintChange((next) => {
    spec = next;
    if (!spec) {
      tracker.stop();
      container.hidden = true;
      container.textContent = '';
      entries = [];
      markRows([]);
      return;
    }
    rebuild();
    container.hidden = false;
    tracker.start(frame);
  });

  // A settled commit replaces the mirror and may swap markup wholesale;
  // coverage itself may also have changed (an apply dressed new carriers),
  // so a held paint re-projects against the new map and fresh elements.
  source.onStyleMapChange(() => {
    if (spec) {
      rebuild();
    }
  });

  return { container };
};
