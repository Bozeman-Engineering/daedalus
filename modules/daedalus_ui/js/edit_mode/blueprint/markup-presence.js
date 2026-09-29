/**
 * The part-placement rule over ONE markup annotation, store-free.
 *
 * markup-index.js answers placement and presence for a selectable key
 * by looking the key's `_element.markup` annotation up in the mirror;
 * this module is the rule that reads the annotation itself, so the
 * store door and any consumer holding an annotation of its own ask the
 * same question with the same answer.
 */

/**
 * The instance row a selector addresses (descendants included), or null.
 */
export const findInstance = (rows, selector) => {
  for (const row of Array.isArray(rows) ? rows : []) {
    if (`.${row.class}` === selector) {
      return row;
    }
    const nested = findInstance(row.children, selector);
    if (nested) {
      return nested;
    }
  }
  return null;
};

/**
 * The resolved part node a selector addresses inside a parts tree, or
 * null. A matched key always yields an object (the resolver emits {tag,
 * attributes, parts} nodes) so callers can rely on truthiness.
 */
export const findPart = (parts, selector) => {
  for (const [key, part] of Object.entries(parts ?? {})) {
    if (key === selector) {
      return part ?? {};
    }
    const nested = findPart(part?.parts, selector);
    if (nested) {
      return nested;
    }
  }
  return null;
};

/**
 * WHERE one declared part landed in an annotation: the canonical
 * placement answer (markup-index.js partPlacement documents the four
 * values; this is its body over the annotation itself).
 *
 * @param {?object} markup
 *   An `_element.markup` annotation, or null when none is stamped.
 * @param {string} selector
 *   The declared part selector ('.text-block-link') or an instance class
 *   ('.s-ab').
 *
 * @return {'element'|'root'|false|null}
 */
export const markupPlacement = (markup, selector) => {
  if (!markup) {
    return null;
  }
  if (
    findPart(markup.parts, selector) ||
    findInstance(markup.instances, selector)
  ) {
    return 'element';
  }
  if (Array.isArray(markup.hoisted) && markup.hoisted.includes(selector)) {
    return 'root';
  }
  // An instances-only annotation (a field's markup) models no shell: it
  // can vouch for its carriers but cannot deny a part.
  return markup.parts === undefined ? null : false;
};
