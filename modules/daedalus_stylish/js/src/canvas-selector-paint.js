/**
 * The canvas selector paint: dashed boxes for the spine's hover facet and
 * the subject facet under the selection, dotted boxes for the write
 * address.
 *
 * A thin adapter over the selection spine: it subscribes to the hover
 * facet (app.onHoverSelectorChange) and the styling facts
 * (app.onTargetChange, which carries the subject facet and the write
 * address together) and renders each as one injected <style> tag whose
 * rule comes from the part behavior's selectorFor: the printers' own
 * @scope targets for the relative selector, so the paint is a truthful
 * preview of the selector's style reach. Every match lights up,
 * whatever the selector addresses (every instance of a declared part in
 * its owner's scope, every direct child for '> *', every text block for
 * '> pb-text', every anchor in a section for the ancestor rule
 * `container:Y::a`), exactly the elements computed-read's
 * resolveTargets answers for the same (key, selector), since both query
 * the one printed target grammar. It survives re-renders with no
 * geometry tracking, and a stale instance part (.s-xx gone after an
 * edit) simply matches nothing.
 *
 * Three treatments, three tags: hover is dashed in the muted selection
 * stroke; the SUBJECT facet (a part, a tag tier, a chain on the
 * selected element: the spine's `target`) is dashed in the full
 * selection color (the element's solid outline continues to paint from
 * the element spine, daedalus_ui outline.js, so a selected part reads
 * as "solid element box, dashed part inside"); the WRITE ADDRESS (a
 * rule chosen from the address switcher or provenance's "Edit at": the
 * spine's `address`) is dotted in the full selection color, offset one
 * step further out, so the blast radius of the chosen rule is visible
 * before the first write while the selection's own box stays readable
 * inside it. Two facts, two slots: a facet and an address paint
 * together (the selected list item dashed inside every list item the
 * section rule reaches, dotted), and each slot empties when its fact
 * drops. Hovering the selector that is already the facet or the address
 * paints nothing extra: the standing treatment stays, instead of a
 * muted hover box washing it out.
 *
 * Leader-only, like every canvas adapter: the satellite window has no
 * canvas, and the leader is the single spine writer/reader (satellite
 * facet and address picks land on the spine by intent).
 */
export const createCanvasSelectorPaint = ({ app, behavior }) => {
  const TREATMENTS = {
    hover:
      'outline: 2px dashed var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;\n' +
      '    outline-offset: 2px !important;',
    selected:
      'outline: 2px dashed var(--daedalus-selection, #0071e3) !important;\n' +
      '    outline-offset: 2px !important;',
    write:
      'outline: 2px dotted var(--daedalus-selection, #0071e3) !important;\n' +
      '    outline-offset: 5px !important;',
  };

  const styles = { hover: null, selected: null, write: null };

  const paint = (slot, target) => {
    styles[slot]?.remove();
    styles[slot] = null;
    const rule = target
      ? behavior.selectorFor(target.key, target.selector, TREATMENTS[slot])
      : null;
    if (rule === null) {
      return;
    }
    const tag = document.createElement('style');
    tag.setAttribute('data-daedalus-stylish-selector-paint', slot);
    tag.textContent = rule;
    document.head.appendChild(tag);
    styles[slot] = tag;
  };

  const samePair = (a, b) =>
    Boolean(a) &&
    Boolean(b) &&
    a.key === b.key &&
    a.selector === b.selector;
  const sameFact = (a, b) => (a === null && b === null) || samePair(a, b);

  // The hover box yields to the facet or address box on the same
  // address; tracked here so a facet or address change re-evaluates the
  // standing hover.
  let hovered = null;
  const paintHover = () => {
    const painted =
      samePair(hovered, app.Selection.getTarget()) ||
      samePair(hovered, app.Selection.getAddress());
    paint('hover', painted ? null : hovered);
  };

  app.onHoverSelectorChange((hoverSelector) => {
    hovered = hoverSelector;
    paintHover();
  });
  // The two facts, one slot each: the subject facet dashed, the write
  // address dotted. The subscription fires when either moves (or the
  // selection does), so each slot repaints only when ITS fact changed:
  // an address pick leaves the facet's tag in place.
  const standing = { selected: null, write: null };
  app.onTargetChange(({ target, address }) => {
    const facts = { selected: target ?? null, write: address ?? null };
    Object.keys(facts).forEach((slot) => {
      if (!sameFact(facts[slot], standing[slot])) {
        standing[slot] = facts[slot];
        paint(slot, facts[slot]);
      }
    });
    paintHover();
  });
};
