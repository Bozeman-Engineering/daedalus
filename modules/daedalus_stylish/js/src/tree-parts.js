import { parseKey } from './style-guide.js';
import { partsForKey, PART_SELECTOR, INSTANCE_PART } from './parts.js';
import { CONTAINER_SCOPE_LIMIT } from './printer.js';

/**
 * Daedalus Stylish's part behavior for the page-elements markup view.
 *
 * The markup view itself — the blueprint × declared-shells composition and
 * its shell rows — is daedalus_ui's (compose.js / markup-view.js):
 * anatomy is page builder vocabulary, not styling. What daedalus_stylish owns is
 * what a shell row OFFERS: a declared class is a style target, so the
 * behavior registered here (app.tree.registerPartBehavior) supplies the
 * shell-row UX:
 *
 * (Row interaction is not a behavior concern anymore: a part row's click
 * and hover commit the part as selection FACETS on the spine
 * (daedalus_ui selection slice), the row reads its active and hot
 * state back from the store, and the canvas paint adapter
 * (canvas-part-paint.js) renders the facets — so panel, canvas, and
 * inspector all follow the one shared selection.)
 *
 * The two halves are the behavior's RESOLUTION seam (view-registry.js
 * contract): resolve(domTarget) is the canvas→part lookup — closest()
 * from the pointer target against the enclosing types' declared part
 * selectors, innermost scope root first, INSTANCE parts (an
 * identity-carrying rich text element resolving to (owner key, '.s-xx')
 * under the innermost-selectable ownership rule) joining the same walk,
 * every candidate gated on the registry's injected `presented` (owner in
 * the selection universe) and `placed` (part is a row of it) predicates,
 * and selectorFor(parentKey, id, declarations) the part→scoped-CSS rule
 * builder: literally the selector the styles will use, so anything
 * painted through it is a truthful preview of what @scope will hit
 * (every match in the owner's scope root, or nothing when template and
 * YAML have drifted — a silent affordance is a diagnostic, not a bug to
 * paper over). Daedalus Stylish owns that grammar; spine consumers call the seam.
 */
export const createPartBehavior = ({ source, guide }) => {
  // ---- Part → scoped CSS: the @scope rule builder. -------------------------

  /**
   * Builds the scoped CSS rule applying `declarations` to a part.
   *
   * The seam's selectorFor: the rule addresses EVERY instance of the part
   * in its owner's scope with the same selector grammar delivered part
   * styles use, so anything painted through it is a truthful preview of
   * what @scope will hit. Null when id is not a part selector.
   *
   * @param {string} parentKey
   *   The owning element's selection key.
   * @param {string} id
   *   The part id (a '.class' selector — declared or instance).
   * @param {string} declarations
   *   CSS declarations text, nested verbatim inside the part's rule block.
   *
   * @return {?string}
   *   The @scope rule, or null when id is not a part selector.
   */
  const selectorFor = (parentKey, id, declarations = '') => {
    if (!PART_SELECTOR.test(id)) {
      return null;
    }
    // Keys carry a validated charset, but the attribute value is quoted
    // text either way — escape the two characters that could break out.
    const escapedKey = parentKey.replace(/[\\"]/g, '\\$&');
    // Container outlines get the printers' donut so the highlight previews
    // exactly what the styles will hit — never a nested container's markup.
    const limit =
      parseKey(parentKey)?.kind === 'container'
        ? ` to (${CONTAINER_SCOPE_LIMIT})`
        : '';
    // The dual selector mirrors the printers: a collapsed container wears
    // the part class ON the scope root itself, so :scope must match too.
    return `@scope ([data-selectable="${escapedKey}"])${limit} {\n  :scope${id}, ${id} {\n    ${declarations}\n  }\n}`;
  };

  // ---- Canvas → part: the resolution walk. ---------------------------------

  /**
   * Resolves the part under a canvas pointer target, innermost root first.
   *
   * The seam's resolve: pure DOM → {parentKey, id} | null, no state.
   * Walks up the pointer target's scope-root chain; at each root, a part
   * selector matches when closest() lands inside that root — the same
   * containment @scope gives the CSS, so row glow and style reach agree.
   * Within one root the DEEPEST matching part wins, not the first
   * declared: pointing at the eyebrow inside the text column must glow
   * Eyebrow, the way an element inspector picks the innermost element.
   * The closest() hits all lie on the target's ancestor chain, so
   * containment totally orders them (equal hits — one element wearing two
   * declared classes — resolve to the later declaration, which the
   * parents-before-children flatten order makes the deeper part).
   *
   * Only PRESENTED roots offer parts, and only PLACED parts are offered:
   * the caller (daedalus_ui's view registry, which owns the
   * projection) injects `presented(key)` and `placed(key, id)`. The walk
   * skips a root failing `presented` and keeps climbing: a part commits
   * its owner as the selection, so an owner outside the projection
   * universe (a media entity's inner fields absorbed by a unit reference)
   * must not resolve. Every candidate, declared part or instance carrier,
   * is dropped when `placed` denies it: placement (is this part its own
   * node?) is the MODEL's answer, the server annotation composed into the
   * projection index, and the DOM cannot give it. A top-level part whose
   * form coincides with its owner's root is hoisted onto the root as a
   * class (an unlinked image's `.image-block-image` is the root <img>
   * itself); closest() and self-inclusive contains() then match the root,
   * exactly as the style reach (`:scope.cls, .cls`) should, but the panel
   * derives no row for it and canvas and panel must offer the same parts.
   * The DOM walk stays containment-based on purpose: a strict
   * `hit !== root` check here would be a second, DOM-derived placement
   * rule that could drift from the model's.
   */
  const resolve = (target, presented = () => true, placed = () => true) => {
    if (!(target instanceof Element)) {
      return null;
    }
    // Only the canvas: panel/toolbar chrome (including the tree itself,
    // which owns its rows' hover) never resolves parts.
    if (
      !target.closest('[data-main-entity]') ||
      target.closest('[data-selection-hover-scope]')
    ) {
      return null;
    }
    let root = target.closest('[data-selectable]');
    while (root) {
      const key = root.getAttribute('data-selectable');
      const parsed = parseKey(key);
      if (parsed && !parsed.sub && presented(key)) {
        let best = null;
        // Offers one candidate; true when it became the best so far. The
        // closure runs inside this iteration only, before root advances.
        // eslint-disable-next-line no-loop-func
        const consider = (id, hit) => {
          if (
            hit &&
            root.contains(hit) &&
            placed(key, id) &&
            (!best || best.hit.contains(hit))
          ) {
            best = { parentKey: key, id, hit };
            return true;
          }
          return false;
        };
        for (const part of partsForKey(
          guide,
          key,
          source.getBlockTypes(),
          source.getSectionLayouts(),
        )) {
          consider(part.id, target.closest(part.id));
        }
        // Instance parts: the deepest identity-class carrier on the
        // target's ancestor chain that OWNS to this root (innermost
        // data-selectable ancestor, so the glow lands on exactly the row
        // the markup view derived). The identity IS the class, no tag
        // vocabulary, matching the annotation's instance rows. Carriers
        // owned by a nested selectable are that owner's business: skip
        // and keep climbing, exactly like the rows do; a carrier the
        // model does not place is passed over the same way, so the
        // deepest PLACED carrier is the one offered.
        for (
          let carrier =
            target instanceof Element ? target : target.parentElement;
          carrier && root.contains(carrier);
          carrier = carrier.parentElement
        ) {
          if (carrier.closest('[data-selectable]') !== root) {
            continue;
          }
          const identity = [...carrier.classList].find((name) =>
            INSTANCE_PART.test(`.${name}`),
          );
          if (identity && consider(`.${identity}`, carrier)) {
            break;
          }
        }
        if (best) {
          // `hit` is the canvas element the part resolved on: callers
          // arbitrating between this part and an element resolution need
          // the DOM position (deepest under the cursor wins), which the
          // owner key alone cannot order.
          return { parentKey: best.parentKey, id: best.id, hit: best.hit };
        }
      }
      root = root.parentElement?.closest('[data-selectable]') ?? null;
    }
    return null;
  };

  return {
    resolve,
    selectorFor,
  };
};
