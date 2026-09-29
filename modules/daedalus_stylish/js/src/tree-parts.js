import { parseKey } from './style-guide.js';
import { BOX_LABELS, partsForKey, selectorLabel, INSTANCE_PART } from './parts.js';
import { scopeTargets, donutLimit } from './printer.js';
import { pseudoOf, resolveTargets } from './computed-read.js';
import { buildStack, deliveredEntry } from './style-stack.js';
import { axisVocabulary } from './variant-scope.js';
import {
  ANCHOR,
  PSEUDOS,
  SUFFIX,
  isCanonicalSelector,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * Whether a delivered entry (deliveredEntry: condition → property →
 * winning value) materializes a generated box under any condition: the
 * box exists iff the cascade sets `content` to something other than
 * `none` there (the GeneratedContent group's two spellings, `""` and
 * `none`; a box born at the op spine carries `""` at default, an author
 * may switch it off with `none` and on again under a state).
 */
const deliversBox = (entry) =>
  Object.values(entry).some(
    (values) =>
      typeof values.content === 'string' &&
      values.content.trim().toLowerCase() !== 'none',
  );

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
 * and hover commit the part's selector as the styling TARGET and the
 * hover facet on the spine (daedalus_ui selection slice), the row reads
 * its active and hot state back from the store, and the canvas paint
 * adapter (canvas-selector-paint.js) renders both — so panel, canvas,
 * and inspector all follow the one shared selection.)
 *
 * The third half is the LABEL seam: label(key, selector) words a
 * relative selector under an owner the way the inspector's own chip does
 * (parts.js selectorLabel, "Every link", "Raster"), so daedalus_ui's
 * breadcrumb can name a target crumb in this module's vocabulary
 * without learning the grammar.
 *
 * The two other halves are the behavior's RESOLUTION seam (view-registry.js
 * contract): resolve(domTarget) is the canvas→part lookup — closest()
 * from the pointer target against the enclosing types' declared part
 * selectors, innermost scope root first, INSTANCE parts (an
 * identity-carrying rich text element resolving to (owner key, '.s-xx')
 * under the innermost-selectable ownership rule) joining the same walk,
 * every candidate gated on the registry's injected `presented` (owner in
 * the selection universe) and `placed` (part is a row of it) predicates,
 * and selectorFor(parentKey, selector, declarations) the
 * selector→scoped-CSS rule builder: literally the targets the styles will
 * use (the printers' scopeTargets and donutLimit for any relative
 * selector, a part's '.cls', the children '> *', a type '> pb-text'),
 * so anything painted through it is a truthful preview of what @scope
 * will hit (every match in the owner's scope root, or nothing when
 * template and YAML have drifted — a silent affordance is a diagnostic,
 * not a bug to paper over). Daedalus Stylish owns that grammar; spine
 * consumers call the seam.
 *
 * A generated box (`&::before`, `.quote::after`) is one more address of
 * the grammar with no node behind it: selectorFor prints it (the paint
 * outlines the generated box itself, the browser's own geometry for
 * it), matches(parentKey, selector) answers its HOSTS plus the pseudo
 * name (computed-read's law: the box rides getComputedStyle's pseudo
 * argument, and rect-measuring consumers withhold), no pointer resolves
 * one (a click lands on the host; seating a box is a doorway, like a
 * rule), and generated(key) lists the owner's own boxes the style stack
 * materializes, the rows the page-elements tree renders under the
 * element since anatomy cannot know them (existence is a cascade fact:
 * the box exists iff something delivers `content` there).
 */
export const createPartBehavior = ({ source, guide }) => {
  // ---- Selector → scoped CSS: the @scope rule builder. ---------------------

  /**
   * Builds the scoped CSS rule applying `declarations` to a selector's
   * matches under an owner.
   *
   * The seam's selectorFor: the rule addresses EVERY match of the
   * relative selector in its owner's scope with the printers' own targets
   * (scopeTargets: the hoisted dual for a part, `:where(:scope) > […]`
   * for the children and types) and the printers' donut for a
   * container-owned part-first selector (donutLimit), so anything painted
   * through it is a truthful preview of what the delivered @scope rule
   * hits: the CSS twin of computed-read's resolveTargets, which queries
   * the same targets. Null when the selector is outside the grammar.
   *
   * @param {string} parentKey
   *   The owning element's selection key.
   * @param {string} selector
   *   A canonical relative selector ('.quote', '.s-ab', '> *',
   *   '> pb-text', 'p').
   * @param {string} declarations
   *   CSS declarations text, nested verbatim inside the rule block.
   *
   * @return {?string}
   *   The @scope rule, or null when the selector is outside the grammar.
   */
  const selectorFor = (parentKey, selector, declarations = '') => {
    if (!isCanonicalSelector(selector)) {
      return null;
    }
    // Keys carry a validated charset, but the attribute value is quoted
    // text either way — escape the two characters that could break out.
    const escapedKey = parentKey.replace(/[\\"]/g, '\\$&');
    // Container outlines get the printers' donut so the highlight previews
    // exactly what the styles will hit — never a nested container's markup
    // for a part-first selector; the children, tags and types reach
    // their whole subtree, as delivered.
    const donut =
      parseKey(parentKey)?.kind === 'container' ? donutLimit(selector) : null;
    const limit = donut === null ? '' : ` to (${donut})`;
    return `@scope ([data-selectable="${escapedKey}"])${limit} {\n  ${scopeTargets(selector).join(', ')} {\n    ${declarations}\n  }\n}`;
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
      if (parsed && !parsed.selector && presented(key)) {
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

  /**
   * The words for a relative selector under an owner, the inspector
   * chip's own wording (parts.js selectorLabel): the label seam
   * daedalus_ui's target crumb reads.
   *
   * @param {string} key
   *   The owning element's selection key.
   * @param {string} selector
   *   A canonical relative selector.
   *
   * @return {string}
   */
  const label = (key, selector) =>
    selectorLabel(
      guide,
      key,
      selector,
      source.getBlockTypes(),
      source.getSectionLayouts(),
      source.getMarkup?.(key),
    );

  /**
   * The live canvas elements a selector hits under its owner, and the
   * generated box it addresses on them.
   *
   * The seam's matches, the DOM twin of selectorFor: computed-read's
   * resolveTargets (the same host targets and donut the printers use),
   * so a consumer measuring the matches before it prints through
   * selectorFor measures exactly what the rule will reach. For a box
   * the elements are its HOSTS (the owner's root for `&::before`, every
   * `.quote` for `.quote::after`) and `pseudo` names the box; the
   * box's computed style is getComputedStyle(host, '::before'), and it
   * has no rect, so a consumer that measures geometry withholds for a
   * non-null pseudo instead of measuring the host as the box.
   *
   * @param {string} parentKey
   *   The owning element's selection key.
   * @param {string} selector
   *   A canonical relative selector.
   *
   * @return {{elements: Element[], pseudo: ?string}}
   *   Every match in document order (the root first when it matches),
   *   empty when the owner is off the canvas or the selector outside
   *   the grammar; `pseudo` 'before' / 'after' for a box, else null.
   */
  const matches = (parentKey, selector) => ({
    elements: resolveTargets(parentKey, selector),
    pseudo: pseudoOf(selector),
  });

  /**
   * The owner's own generated boxes the style stack materializes.
   *
   * Existence is a cascade fact, never an anatomy fact: `&::before`
   * exists on an element iff something delivers `content` at it under
   * some condition, the element's own entry (born with `content: ""` at
   * the op spine), a worn look's box fragment, a skin default, or an
   * ancestor rule ending in the same box that reaches the element
   * (`> pb-text::before` on its section), so the answer is the box's
   * full stack (style-stack.js buildStack at `key::&::before`, floor
   * and ancestor tier included) read for a delivered `content` other
   * than `none`. The page-elements tree renders one row per box under
   * the element, id the box's selector so the row seats it as the
   * styling target exactly as a part row seats its part. Pure over the
   * source's current map, materials and tree; nothing is cached.
   *
   * @param {string} key
   *   The owning element's selection key (a root key: a key already
   *   carrying a selector owns no boxes of its own, since a
   *   pseudo-element is terminal).
   *
   * @return {Array<{id: string, pseudo: string, label: string}>}
   *   The delivered boxes in grammar order (before, after): `id` the
   *   selector ('&::before'), `pseudo` its name, `label` the row's
   *   words. [] for a key that owns no delivered box, or no element.
   */
  const generated = (key) => {
    const parsed = parseKey(key);
    if (!parsed || parsed.selector) {
      return [];
    }
    const blockTypes = source.getBlockTypes();
    const sectionLayouts = source.getSectionLayouts();
    const map = source.getStyleMap();
    const variants = source.getMaterials().ofKind('variant');
    const axes = axisVocabulary(guide, key, blockTypes, sectionLayouts, source.getLayout());
    const tree = source.getTree();
    return PSEUDOS.filter((pseudo) =>
      deliversBox(
        deliveredEntry(
          buildStack({
            guide,
            map,
            key: `${key}${SUFFIX}${ANCHOR}${SUFFIX}${pseudo}`,
            variants,
            axes,
            blockTypes,
            sectionLayouts,
            tree,
          }),
        ),
      ),
    ).map((pseudo) => ({
      id: `${ANCHOR}${SUFFIX}${pseudo}`,
      pseudo,
      label: BOX_LABELS[pseudo](),
    }));
  };

  return {
    resolve,
    selectorFor,
    matches,
    generated,
    label,
  };
};
