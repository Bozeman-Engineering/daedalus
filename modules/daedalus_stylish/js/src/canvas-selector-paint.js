/**
 * The canvas selector paint: dashed boxes for the spine's hover facet and
 * a styling target under the selection, dotted boxes for a target above
 * it.
 *
 * A thin adapter over the selection spine: it subscribes to the hover
 * facet (app.onHoverSelectorChange) and the styling target
 * (app.onTargetChange) and renders each as one injected <style> tag
 * whose rule comes from the part behavior's selectorFor: the printers'
 * own @scope targets for the relative selector, so the paint is a
 * truthful preview of the selector's style reach. Every match lights
 * up, whatever the selector addresses (every instance of a declared
 * part in its owner's scope, every direct child for '> *', every text
 * block for '> pb-text', every anchor in a section for the ancestor
 * rule `container:Y::a`), exactly the elements computed-read's
 * resolveTargets answers for the same (key, selector), since both query
 * the one printed target grammar. It survives re-renders with no
 * geometry tracking, and a stale instance part (.s-xx gone after an
 * edit) simply matches nothing.
 *
 * Three treatments, three tags: hover is dashed in the muted selection
 * stroke; a target BELOW the selection (a part, a tag tier, a chain on
 * the selected element) is dashed in the full selection color (the
 * element's solid outline continues to paint from the element spine,
 * daedalus_ui outline.js, so a selected part reads as "solid element
 * box, dashed part inside"); a target ABOVE it (an ancestor rule chosen
 * from the address switcher or provenance's "Edit at") is dotted in the
 * full selection color, offset one step further out, so the blast
 * radius of the chosen rule is visible before the first write while the
 * selection's own box stays readable inside it. One target, so the two
 * slots never paint together: seating a rule above empties the dashed
 * slot, dropping it back to a facet below empties the dotted one.
 * Hovering the selector that is already the target paints nothing
 * extra: the target treatment stays, instead of a muted hover box
 * washing it out.
 *
 * Leader-only, like every canvas adapter: the satellite window has no
 * canvas, and the leader is the single spine writer/reader (satellite
 * target picks land on the spine by intent).
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

  // The hover box yields to the target box on the same address; tracked
  // here so a target change re-evaluates the standing hover.
  let hovered = null;
  const paintHover = () => {
    const target = app.Selection.getTarget();
    const targeted =
      hovered &&
      target &&
      hovered.key === target.key &&
      hovered.selector === target.selector;
    paint('hover', targeted ? null : hovered);
  };

  app.onHoverSelectorChange((hoverSelector) => {
    hovered = hoverSelector;
    paintHover();
  });
  // The one target, split by direction: on the selected element it is
  // the dashed facet box, on an ancestor the dotted rule box.
  app.onTargetChange(({ selected, target }) => {
    const above = Boolean(target) && target.key !== selected;
    paint('selected', above ? null : (target ?? null));
    paint('write', above ? target : null);
    paintHover();
  });
};
