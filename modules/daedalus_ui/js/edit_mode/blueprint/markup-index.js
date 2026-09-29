import store from '../store/store';
import { findInstance, findPart, markupPlacement } from './markup-presence';

/**
 * THE markup index over the blueprint mirror: selectable key → the
 * `_element.markup` annotation, memoized on the mirror reference (the
 * mirror is replaced wholesale by every mutation response, so reference
 * equality is change detection).
 *
 * The annotation is the canonical instance model, the element resolver's
 * concrete tree for each owning node's data ({tag, attributes, classes,
 * hoisted, parts, slots}, plus the rich-text facts `instances` for minted
 * carriers and `tags` for the distinct tag names the prose contains),
 * stamped server-side on both delivery seams (boot payload, mutation
 * response). Every client question about what an element IS rendering as
 * reads THIS index, part presence, drag's container drop surface,
 * daedalus_stylish's layout-context facts, and the canvas DOM is a projection to
 * verify against, never a source. Consumers get the same answer the
 * server render printed, by construction.
 *
 * Key forms served (the selectable-key grammar):
 * - entity:{root uuid}, the root node's markup.
 * - field:{root uuid}:{name}, a root field's markup (rich-text facts
 *   only, instances and tags: fields have no resolver tree of their own;
 *   the field-shell sketch stays declaration territory).
 * - container:{uuid}, a section node's markup (resolved at its real
 *   nesting depth).
 * - block:{uuid}, a component's markup (absent when the component's
 *   entity did not resolve server-side, or for plugin components: the
 *   model only claims what the data can back).
 * - block:{uuid}:{name}, an inline block field's markup (rich-text facts
 *   only, open renders; a composed block's ride its own markup).
 */

let indexSource = null;
let index = null;

/**
 * Indexes one owning node's annotation under its key family.
 */
const indexNode = (element, baseKey) => {
  if (element?.markup) {
    index.set(baseKey, element.markup);
  }
  for (const [name, facts] of Object.entries(element?.fields ?? {})) {
    if (facts?.markup) {
      index.set(`${baseKey}:${name}`, facts.markup);
    }
  }
};

/**
 * Walks the lean node tree, containers at every depth.
 *
 * The tree is kind-discriminated with ONE child axis: containers carry
 * their annotation under the `container:` key family and recurse; region
 * nodes are transparent grouping (not addressable, never annotated);
 * everything else is a block node.
 */
const indexTree = (nodes) => {
  for (const node of Array.isArray(nodes) ? nodes : []) {
    if (!node || typeof node !== 'object') {
      continue;
    }
    if (node.kind === 'container') {
      if (node.uuid && node._element?.markup) {
        index.set(`container:${node.uuid}`, node._element.markup);
      }
      indexTree(node.children);
    } else if (node.kind === 'region') {
      indexTree(node.children);
    } else if (node.uuid) {
      indexNode(node._element, `block:${node.uuid}`);
    }
  }
};

/**
 * The markup annotation for a selectable key, or null.
 *
 * Null means the model does not speak for the key (no annotation stamped:
 * plugin components, unresolved entities, undelivered mirrors), the
 * honest "unknown", which presence consumers read as "degrade to the
 * declared sketch", never as absence.
 *
 * @param {?string} key
 *   A selectable key ('block:{uuid}', 'container:{uuid}',
 *   'field:{uuid}:{name}', …).
 *
 * @return {?object}
 *   The `_element.markup` annotation.
 */
export const markupForKey = (key) => {
  const state = store.getState().blueprint;
  if (typeof key !== 'string' || !state.blueprint || !state.root) {
    return null;
  }
  if (indexSource !== state.blueprint) {
    indexSource = state.blueprint;
    index = new Map();
    if (state.root.uuid) {
      indexNode(state.blueprint._element, `entity:${state.root.uuid}`);
      for (const [name, facts] of Object.entries(
        state.blueprint._element?.fields ?? {},
      )) {
        if (facts?.markup) {
          index.set(`field:${state.root.uuid}:${name}`, facts.markup);
        }
      }
    }
    indexTree(state.blueprint._tree);
  }
  return index.get(key) ?? null;
};

/**
 * WHERE one declared part landed for a key's element: the canonical
 * placement answer, read from the markup annotation. The model decided
 * the parts at serialize time from entity data, so the answer is the
 * same one the server render printed. Four-valued:
 * - 'element', the part resolved as its own element: a node in the
 *   resolved parts tree, or a minted instance carrier.
 * - 'root', the part coincided with the root form and hoisted onto it
 *   (the unlinked image block's img IS the block root): present, but not
 *   a distinct DOM node of its own.
 * - false, the key's node carries a resolver tree and the part is not in
 *   it: the model positively says absent.
 * - null, the model does not speak (no annotation, or an instances-only
 *   field annotation with no matching carrier): consumers degrade to the
 *   declared sketch, never to an emptier one.
 * The rule itself lives in markup-presence.js (markupPlacement), so a
 * consumer holding the annotation already (the delivered tree in any
 * window) reads the same answer without the store.
 *
 * Surfaces that PROJECT THE PAGE (the markup view's rows) gate on
 * placement-as-own-element; surfaces that STYLE or complete against the
 * part gate on partPresence below, where a hoisted part still counts.
 *
 * @param {?string} key
 *   The owning model key.
 * @param {string} selector
 *   The declared part selector ('.text-block-link') or an instance class
 *   ('.s-ab').
 *
 * @return {'element'|'root'|false|null}
 */
export const partPlacement = (key, selector) =>
  markupPlacement(markupForKey(key), selector);

/**
 * Whether one declared part selector is present on a key's element, the
 * element itself included: a thin projection over partPlacement in which
 * a hoisted part IS present (its class is in the DOM either way, and the
 * `:scope.cls` dual selector targets the root when it is). The presence
 * question for surfaces that style or complete against the part. Three-
 * valued like the placement it derives from: true placed (own element or
 * root), false the model positively says absent, null the model does not
 * speak.
 *
 * @param {?string} key
 *   The owning model key.
 * @param {string} selector
 *   The declared part selector ('.text-block-link') or an instance class
 *   ('.s-ab').
 *
 * @return {?boolean}
 */
export const partPresence = (key, selector) => {
  const placement = partPlacement(key, selector);
  return placement === null ? null : placement !== false;
};

/**
 * The resolved tag of a key's element, or null when the model does not
 * speak. This is the tag the server render printed for the root of the
 * key's markup tree; declared tags ride the anatomy payload and are the
 * degrade path, not this accessor's business.
 *
 * @param {?string} key
 *   The owning model key.
 *
 * @return {?string}
 */
export const rootTag = (key) => markupForKey(key)?.tag ?? null;

/**
 * Whether a container's flow resolved empty, or null when the model does
 * not speak for the key.
 *
 * The resolver's one {empty: true} answer, read the way it reaches every
 * surface: as the matched empty form's declared drop-affordance
 * attribute (`data-container-empty`) on the resolved root — the same
 * fact the composed render printed, so consumers (the caret, the drop
 * grammar) read what the page shows instead of re-deriving emptiness
 * from canvas markup. A container whose annotation carries no marker
 * resolved under a populated form: a positive "not empty".
 *
 * @param {?string} key
 *   A container selectable key ('container:{uuid}').
 *
 * @return {?boolean}
 */
export const containerEmpty = (key) => {
  const markup = markupForKey(key);
  return markup ? 'data-container-empty' in (markup.attributes ?? {}) : null;
};

/**
 * The resolved tag one declared part rendered as, or null when the model
 * does not speak or positively says absent. An 'element' placement
 * answers with the part's own tag (resolved parts tree or minted
 * instance carrier); a 'root' placement answers with the root's tag,
 * because the part IS the root there.
 *
 * @param {?string} key
 *   The owning model key.
 * @param {string} selector
 *   The declared part selector ('.text-block-link') or an instance class
 *   ('.s-ab').
 *
 * @return {?string}
 */
export const partTag = (key, selector) => {
  const markup = markupForKey(key);
  if (!markup) {
    return null;
  }
  const part = findPart(markup.parts, selector);
  if (part) {
    return part.tag ?? null;
  }
  const instance = findInstance(markup.instances, selector);
  if (instance) {
    return instance.tag ?? null;
  }
  return partPlacement(key, selector) === 'root' ? (markup.tag ?? null) : null;
};

/**
 * The matched-form label one declared part resolved under, or null.
 *
 * A multi-form part's resolved node carries the matched form's own name
 * ('Raster' for the image part's img form, 'Vector' for its svg form).
 * The resolver stamps it only when the form declares one, so a plain
 * tag-part or a nameless form answers null and consumers fall back to
 * the declaration's part-level label. Instance carriers answer with the
 * annotation's stamped label the same way. A 'root'-placed part answers
 * null: the hoist keeps no form facts, and the owning row already has
 * its own label, so the degrade path (the declared part label) is the
 * honest answer.
 *
 * @param {?string} key
 *   The owning model key.
 * @param {string} selector
 *   The declared part selector ('.image-block-image') or an instance
 *   class ('.s-ab').
 *
 * @return {?string}
 */
export const partLabel = (key, selector) => {
  const markup = markupForKey(key);
  if (!markup) {
    return null;
  }
  const part = findPart(markup.parts, selector);
  if (part) {
    return part.label ?? null;
  }
  return findInstance(markup.instances, selector)?.label ?? null;
};
