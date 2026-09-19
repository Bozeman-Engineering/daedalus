/**
 * The canvas part paint: dashed boxes for the spine's part facets.
 *
 * A thin adapter over the selection spine — it subscribes to the two part
 * facets (hoverPart via app.onHoverPartChange, selectedPart via
 * app.onPartChange) and renders each as one injected <style> tag whose
 * rule comes from the part behavior's selectorFor, so the paint is a
 * truthful preview of the part's style reach: every instance of a
 * declared part in its owner's scope lights up (semantically right — the
 * part IS all its instances), it survives re-renders with no geometry
 * tracking, and a stale instance part (.s-xx gone after an edit) simply
 * matches nothing.
 *
 * Two treatments, two tags: hover is dashed in the muted selection
 * stroke, selection dashed in the full selection color (the element's
 * solid outline continues to paint from the element spine —
 * daedalus_ui outline.js — so a selected part reads as "solid element
 * box, dashed part inside"). Hovering the part that is already selected
 * paints nothing extra: the selection treatment stays, instead of a muted
 * hover box washing it out.
 *
 * Leader-only, like every canvas adapter: the satellite window has no
 * canvas, and the leader is the single spine writer/reader.
 */
export const createCanvasPartPaint = ({ app, behavior }) => {
  const TREATMENTS = {
    hover:
      'outline: 2px dashed var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;\n' +
      '    outline-offset: 2px !important;',
    selected:
      'outline: 2px dashed var(--daedalus-selection, #0071e3) !important;\n' +
      '    outline-offset: 2px !important;',
  };

  const styles = { hover: null, selected: null };

  const paint = (slot, target) => {
    styles[slot]?.remove();
    styles[slot] = null;
    const rule = target
      ? behavior.selectorFor(target.key, target.part, TREATMENTS[slot])
      : null;
    if (rule === null) {
      return;
    }
    const tag = document.createElement('style');
    tag.setAttribute('data-daedalus-stylish-part-paint', slot);
    tag.textContent = rule;
    document.head.appendChild(tag);
    styles[slot] = tag;
  };

  // The hover box yields to the selection box on the same part; tracked
  // here so a selection change re-evaluates the standing hover.
  let hovered = null;
  const paintHover = () => {
    const selected =
      hovered &&
      hovered.key === app.Selection.getSelected() &&
      hovered.part === app.Selection.getSelectedPart();
    paint('hover', selected ? null : hovered);
  };

  app.onHoverPartChange((hoverPart) => {
    hovered = hoverPart;
    paintHover();
  });
  app.onPartChange(({ key, part }) => {
    paint('selected', part ? { key, part } : null);
    paintHover();
  });
};
