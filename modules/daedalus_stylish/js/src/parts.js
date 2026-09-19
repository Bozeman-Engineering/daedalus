import {
  parseKey,
  declarationForKey,
  bundleForKey,
  flattenElements,
  findPatternElement,
  findInstanceDeclaration,
  instanceTags,
} from './style-guide.js';

/**
 * The type-YAML → instance join for presentation parts.
 *
 * A part is a sub-element the style guide declares for an element's TYPE
 * (elements: '.testimonial__quote' in the kind, block-type, or layout
 * YAML); an instance's parts are just its type's declared elements keyed
 * under the instance's selectable key. This module is the pure half — the
 * join the inspector's drill target and the tree's part rows both resolve
 * through. Instance facts (which minted classes exist, what carries them)
 * come from the blueprint mirror's `_element.markup` annotation, handed in
 * by the caller, this module reads no DOM and no store.
 */

/**
 * The one legal part selector shape: a single CSS class.
 *
 * Locked to match the sub-element key rule ('::.foo'): the selector
 * prints into CSS output and queries the live DOM, so its charset is an
 * injection surface. A declared element with any other selector shape is
 * unreachable as a style-map key anyway — filtered here so no consumer
 * renders a row it could never style.
 */
export const PART_SELECTOR = /^\.[A-Za-z0-9_-]+$/;

/**
 * The instance-part namespace: minted rich-text identity classes.
 *
 * The client twin of this module's own server constant
 * (StyleMapValidator::INSTANCE_PART, the CssPrinter/printer.js twin
 * pattern), a part selector's leading dot over the minted class shape.
 * Instance parts are never DECLARED concretely; they resolve through the
 * guide's site instance namespaces (guide.instances, the spine's
 * `instances:` lane) and exist only in content, so their labels come from
 * the `_element.markup.instances` annotation on the blueprint mirror
 * (stamped server-side from the declared tag vocabulary, there is no
 * client tag-vocabulary copy) and their styles never travel in variants
 * (captureSources excludes them; the server rejects them in variant
 * styles).
 */
export const INSTANCE_PART = /^\.s-[a-z0-9]+$/;

/**
 * Finds an instance row (or nested carrier) in a markup annotation.
 *
 * @param {?object} markup
 *   A key's `_element.markup` annotation (or null).
 * @param {string} sub
 *   The instance-part selector ('.s-a3').
 *
 * @return {?{class: string, tag: string, label: string}}
 */
export const findInstancePart = (markup, sub) => {
  const search = (rows) => {
    for (const row of Array.isArray(rows) ? rows : []) {
      if (`.${row.class}` === sub) {
        return row;
      }
      const nested = search(row.children);
      if (nested) {
        return nested;
      }
    }
    return null;
  };
  return search(markup?.instances);
};

/**
 * The declared parts for an element key.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?string} key
 *   A selectable key. Sub-element keys and unparseable keys have no parts.
 * @param {object} blockTypes
 *   Component uuid → inline block type (source.getBlockTypes()).
 * @param {object} sectionLayouts
 *   Section uuid → layout plugin id (source.getSectionLayouts()).
 *
 * @return {Array<{id: string, label: string}>}
 *   Parts in flattened tree order (depth-first, parents before children,
 *   per flattenElements()); id is the class selector ('.quote'), label the
 *   YAML's human label (falls back to the selector).
 */
export const partsForKey = (
  guide,
  key,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const parsed = parseKey(key);
  if (!parsed || parsed.sub) {
    return [];
  }
  const bundle = bundleForKey(parsed, blockTypes, sectionLayouts);
  const elements = flattenElements(declarationForKey(guide, parsed, bundle));
  return Object.entries(elements)
    .filter(([selector]) => PART_SELECTOR.test(selector))
    .map(([selector, declaration]) => ({
      id: selector,
      label: declaration?.label ?? selector,
    }));
};

/**
 * The human label for one part.
 *
 * Declared parts label as authored. An INSTANCE part (a minted identity
 * class, never declared concretely) labels from the key's markup
 * annotation ("Paragraph", "Heading 2", stamped server-side from the
 * declared tag vocabulary, the same name its markup-view row wears),
 * falling back to the covering declaration's own label — an owner pattern
 * part's, else the site instance namespace's — where the annotation is
 * out of reach (the satellite window, a key the model does not speak
 * for). A TAG-TIER sub (a dotless tag name, '{key}::p') labels from the
 * spine's declared tag vocabulary (guide.instances tags — the same map
 * the minter and the annotator read). The selector itself is the last
 * resort, unchanged.
 *
 * @param {?object} markup
 *   The key's `_element.markup` annotation (source.getMarkup(key)), or
 *   null where no annotation is reachable.
 */
export const partLabel = (
  guide,
  key,
  sub,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
) => {
  const declared = partsForKey(guide, key, blockTypes, sectionLayouts).find(
    (part) => part.id === sub,
  )?.label;
  if (declared !== undefined) {
    return declared;
  }
  if (typeof sub === 'string' && INSTANCE_PART.test(sub)) {
    const instance = findInstancePart(markup, sub);
    if (instance?.label) {
      return instance.label;
    }
    const parsed = parseKey(key);
    if (parsed && !parsed.sub) {
      const declaration = declarationForKey(
        guide,
        parsed,
        bundleForKey(parsed, blockTypes, sectionLayouts),
      );
      const covering =
        findPatternElement(declaration, sub) ??
        findInstanceDeclaration(guide?.instances, sub);
      if (covering?.label) {
        return covering.label;
      }
    }
  }
  if (typeof sub === 'string' && /^[a-z][a-z0-9-]*$/.test(sub)) {
    const label = instanceTags(guide?.instances)[sub];
    if (label !== undefined) {
      return label;
    }
  }
  return sub;
};
