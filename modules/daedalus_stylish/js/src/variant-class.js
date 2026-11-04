/**
 * @file
 * The class a worn variant delivers on its element: the client twin of
 * VariantStylesheet::variantClass() / fragmentClass() (authoritative).
 *
 * An element entry's variant references are stamped on the element as
 * classes (daedalus_stylish.module, one per reference), derived from the
 * stored reference VERBATIM: the '' (root) fragment is the plain variant
 * class, a distributed fragment appends a deterministic encoding of its
 * slot key ('/' becomes '--', '(' and ',' become '-', ')' drops), and a
 * pair that cannot be a class name stamps nothing. The matcher reads a
 * node's worn classes through this so a class qualifier naming one
 * (`> d-text.daedalus-stylish-variant--featured`, the decision
 * "targeting by worn variant spelled by the delivered class") is a tree
 * fact it can judge; SelectorMatcherTest's battery locks the spelling
 * through the facts both twins answer.
 */

export const VARIANT_CLASS_PREFIX = 'daedalus-stylish-variant--';

const CLASS_NAME = /^[A-Za-z0-9_-]+$/;

/**
 * The class stamped on an element referencing a variant, or null when
 * the reference cannot be a class name.
 *
 * @param {string} reference
 *   The variant's machine name, as the entry stores it.
 */
export const variantClass = (reference) =>
  typeof reference === 'string' && CLASS_NAME.test(reference)
    ? `${VARIANT_CLASS_PREFIX}${reference}`
    : null;

/**
 * The class stamped for one {variant, fragment} reference, or null when
 * the pair cannot be a class name.
 *
 * @param {string} reference
 *   The variant's machine name.
 * @param {string} fragment
 *   The distributed fragment's slot key, '' for the root.
 */
export const fragmentClass = (reference, fragment) => {
  const base = variantClass(reference);
  if (base === null || fragment === '') {
    return base;
  }
  const suffix = String(fragment)
    .replace(/\//g, '--')
    .replace(/[(,]/g, '-')
    .replace(/\)/g, '');
  return CLASS_NAME.test(suffix) ? `${base}--${suffix}` : null;
};

/**
 * Whether a class name is in the variant-class namespace: a class the
 * tree can judge by wear, as opposed to a markup class it cannot see.
 *
 * @param {string} name
 *   The class name.
 */
export const isVariantClass = (name) =>
  typeof name === 'string' && name.startsWith(VARIANT_CLASS_PREFIX);
