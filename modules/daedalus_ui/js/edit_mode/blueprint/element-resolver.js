/**
 * @file
 * The element resolver's client twin: declaration + data -> concrete tree.
 *
 * The JS mirror of the PHP ElementResolver (src/ElementModel/
 * ElementResolver.php), the CssPrinter/printer.js twin pattern: one
 * resolution semantics, two runtimes, locked together by the round-trip
 * equivalence test (ElementResolverTest::testClientTwinEquivalence piping
 * the same cases through tests/js/resolve-cases.mjs). Change the PHP
 * resolver and this module in the same commit, or the lock fails.
 *
 * Why a client twin exists: the `_element.markup` annotation delivers what
 * an element IS rendering as for its COMMITTED data, but an editing
 * gesture changes data ahead of any commit (a tag picked, a link typed),
 * and the live preview must show the tree that data will render as before
 * the op round-trips. The editors resolve the previewed property bag
 * through this twin and morph the DOM to the answer — the same computation
 * the server render, the annotation, and the AI projection share, so the
 * preview cannot drift from what commits.
 *
 * The data facade replaces the PHP side's entity: the client has no
 * entity, it has field values (the blueprint mirror, plus the gesture's
 * previewed values). `data` is null (no entity: every data condition is
 * FALSE, data-decided values resolve null) or:
 * - fields: {NAME: string|null} — a key EXISTS iff the field is non-empty
 *   (the {field: NAME} presence fact), and its value is the string the
 *   markup prints for it (a tag field's value, a link's uri) or null when
 *   the field holds no printable string (a media reference). Callers MUST
 *   include every non-empty content field of the element, because the
 *   {empty: true} fact derives from the map: empty means no keys, the
 *   definition-based inventory's client shape.
 * - mediaKind: 'raster' | 'vector' | null — the referenced media's kind
 *   (the {media: ...} fact), derived by the caller (the annotation's img
 *   part presence, the committed tree's lane), null when the element
 *   references no resolvable media.
 * - empty: boolean — the explicit emptiness fact for elements with no
 *   fields of their own (a section is empty when it holds no components,
 *   the PHP side's emptiness input). When present it IS the {empty: true}
 *   answer; without it emptiness derives from the fields inventory.
 *
 * The resolved tree is the annotation's exact shape ({tag, attributes,
 *  classes, hoisted, parts, slots}); see the PHP class docblock for the
 * full vocabulary, including the hoisting and mounting rules. Pure module:
 * no Drupal globals, importable by node tests; the app exposes it as
 * Drupal.Daedalus.Blueprint.resolveElement.
 */

/**
 * The root nesting depth: the section renders as a page band.
 */
export const DEPTH_ROOT = 'root';

/**
 * The nested nesting depth: the section renders inside another section.
 */
export const DEPTH_NESTED = 'nested';

/**
 * The content-claim marker a field-claiming part wears in live markup.
 *
 * The PHP side's ElementResolver::CLAIMS_CONTENT_ATTRIBUTE: an anatomy
 * fact stamped at resolution — the marked element claims the block's
 * field content (`contains: field:*`) — so CSS tiers can co-target a
 * block root's inherited text properties onto the claiming interior
 * element with zero element-model knowledge at print time.
 */
export const CLAIMS_CONTENT_ATTRIBUTE = 'data-claims-content';

/**
 * Pattern parts name a class namespace, never a concrete element
 * (PageBuilderDeclarations::PART_PATTERN).
 */
const PART_PATTERN = /^\.[A-Za-z0-9_-]*\*$/;

/**
 * Loose one-condition-map equality (the PHP side's `!=` on present maps).
 */
const samePresent = (a, b) => {
  const left = a ?? null;
  const right = b ?? null;
  if (left === null || right === null) {
    return left === right;
  }
  const keys = Object.keys(left);
  return (
    keys.length === Object.keys(right).length &&
    keys.every((key) => left[key] === right[key])
  );
};

/**
 * Whether one present: condition map holds against the data facade.
 *
 * The closed vocabulary, every condition in the map required (root forms
 * may compose several as an AND; parts carry one, validated at build). No
 * data means every data condition is FALSE: nothing exists that the data
 * cannot vouch for.
 */
const conditionHolds = (present, data, depth) => {
  if (present === null || present === undefined) {
    return true;
  }
  if (
    typeof present !== 'object' ||
    Array.isArray(present) ||
    Object.keys(present).length === 0
  ) {
    return false;
  }
  return Object.entries(present).every(([condition, value]) => {
    switch (condition) {
      case 'field':
        return (
          data !== null &&
          typeof value === 'string' &&
          Object.prototype.hasOwnProperty.call(data.fields ?? {}, value)
        );

      case 'media':
        return data !== null && (data.mediaKind ?? null) === value;

      case 'depth':
        return depth === value;

      case 'empty':
        // The facade's explicit emptiness fact wins (a section's "no
        // components", the PHP side's emptiness input); otherwise the
        // fact derives from the field inventory: callers include every
        // non-empty content field, so no keys means no content at all.
        if (data === null) {
          return false;
        }
        return typeof data.empty === 'boolean'
          ? data.empty
          : Object.keys(data.fields ?? {}).length === 0;

      default:
        return false;
    }
  });
};

/**
 * Resolves a declared tag: a literal name, or the named field's value.
 */
const resolveTag = (tag, data) => {
  if (typeof tag === 'string') {
    return tag;
  }
  if (tag && typeof tag === 'object' && typeof tag.field === 'string') {
    const value = data?.fields?.[tag.field];
    return typeof value === 'string' ? value : null;
  }
  return null;
};

/**
 * Resolves a declared attributes map to literal string values.
 *
 * A {field: NAME} value whose field holds no printable string resolves to
 * no attribute at all: an empty href would be a claim the data cannot
 * back.
 */
const resolveAttributes = (attributes, data) => {
  const resolved = {};
  if (
    !attributes ||
    typeof attributes !== 'object' ||
    Array.isArray(attributes)
  ) {
    return resolved;
  }
  for (const [name, value] of Object.entries(attributes)) {
    if (typeof value === 'string') {
      resolved[name] = value;
    } else if (
      value &&
      typeof value === 'object' &&
      typeof value.field === 'string'
    ) {
      const fieldValue = data?.fields?.[value.field];
      if (typeof fieldValue === 'string') {
        resolved[name] = fieldValue;
      }
    }
  }
  return resolved;
};

/**
 * The first form whose condition holds — root or part alike; null when
 * none matches.
 */
const matchForm = (forms, data, depth) => {
  if (!Array.isArray(forms)) {
    return null;
  }
  for (const form of forms) {
    if (!form || typeof form !== 'object' || Array.isArray(form)) {
      continue;
    }
    if (!('present' in form) || conditionHolds(form.present, data, depth)) {
      return form;
    }
  }
  return null;
};

/**
 * Whether a top-level part is the element the matched root form resolved.
 *
 * Coincidence is declared, not guessed: equal present condition, equal
 * resolved tag (the PHP coincides()). For a multi-form part, `shape` is
 * the part's MATCHED form, so coincidence is decided between matched
 * forms.
 */
const coincides = (shape, form, data) => {
  if (!samePresent(shape.present, form.present)) {
    return false;
  }
  const tag = resolveTag(shape.tag ?? null, data);
  return tag !== null && tag === resolveTag(form.tag ?? null, data);
};

/**
 * Hoists a part onto the root: class worn, key addressing the root.
 */
const hoist = (key, root) => {
  root.classes.push(key.replace(/^\.+/, ''));
  root.hoisted.push(key);
};

/**
 * Merges resolved parts under existing keys kept (the PHP `+=`).
 */
const mergeParts = (parts, extra) => {
  for (const [key, part] of Object.entries(extra)) {
    if (!(key in parts)) {
      parts[key] = part;
    }
  }
  return parts;
};

/**
 * Resolves one elements: level into present part nodes.
 */
const resolveElements = (elements, data, depth, form, root, top) => {
  const parts = {};
  if (!elements || typeof elements !== 'object' || Array.isArray(elements)) {
    return parts;
  }
  for (const [rawKey, part] of Object.entries(elements)) {
    if (!part || typeof part !== 'object' || Array.isArray(part)) {
      continue;
    }
    const key = String(rawKey);
    if (PART_PATTERN.test(key)) {
      // A pattern part names a class namespace whose instances are
      // content; no concrete element resolves from it.
      continue;
    }
    if (!conditionHolds(part.present ?? null, data, depth)) {
      // A failed condition means the element does not exist: absent,
      // subtree included, never hoisted.
      continue;
    }
    // The part's shape: the part itself, or — for a multi-form part —
    // its matched form, which supplies the tag, attributes, and label.
    // Unlike root forms the list need not be exhaustive: a part none of
    // whose forms match is absent, subtree included.
    let partForm = null;
    let shape = part;
    if (part.forms != null) {
      partForm = matchForm(part.forms, data, depth);
      if (partForm === null) {
        continue;
      }
      shape = partForm;
    }
    if (top && form !== null && coincides(shape, form, data)) {
      // The matched root form resolved the part's own element as the
      // root: one element, class hoisted, key addressing the root.
      hoist(key, root);
      mergeParts(
        parts,
        resolveElements(part.elements ?? null, data, depth, null, root, false),
      );
      continue;
    }
    const attributes = resolveAttributes(shape.attributes ?? null, data);
    const contains = part.contains ?? [];
    for (const token of Array.isArray(contains) ? contains : [contains]) {
      root.slots[String(token)] = key;
      if (String(token).startsWith('field:')) {
        // The claim is an anatomy fact the markup carries (see
        // CLAIMS_CONTENT_ATTRIBUTE); stamped after the declared
        // attributes so both twins resolve the identical order.
        attributes[CLAIMS_CONTENT_ATTRIBUTE] = '';
      }
    }
    const node = {
      tag: resolveTag(shape.tag ?? null, data),
      attributes,
    };
    if (typeof partForm?.label === 'string') {
      // The matched form's own name; the key exists only when the form
      // speaks, so plain tag-parts keep their shape and consumers fall
      // back to the declaration's part-level label.
      node.label = partForm.label;
    }
    node.parts = resolveElements(
      part.elements ?? null,
      data,
      depth,
      null,
      root,
      false,
    );
    parts[key] = node;
  }
  return parts;
};

/**
 * Resolves a declaration against the data facade into a concrete tree.
 *
 * @param {Object} declaration
 *   The effective declaration, as the anatomy payload serves it (the same
 *   validated, kind-layered shape PageBuilderDeclarations serves the PHP
 *   resolver).
 * @param {?Object} data
 *   The data facade ({fields, mediaKind}, see the file docblock), or null
 *   for elements with no entity of their own.
 * @param {string} depth
 *   The nesting depth (DEPTH_ROOT / DEPTH_NESTED); only container-family
 *   declarations vary on it.
 *
 * @return {Object}
 *   The resolved tree: {tag, attributes, classes, hoisted, parts, slots}.
 */
export const resolveElement = (
  declaration,
  data = null,
  depth = DEPTH_ROOT,
) => {
  if (depth !== DEPTH_ROOT && depth !== DEPTH_NESTED) {
    throw new Error(
      `"${depth}" is not a nesting depth (expected "${DEPTH_ROOT}" or "${DEPTH_NESTED}").`,
    );
  }
  const resolved = {
    tag: null,
    attributes: {},
    classes: [],
    hoisted: [],
    parts: {},
    slots: {},
  };
  let form = null;
  if (declaration?.root?.forms != null) {
    // First-match-wins over the ordered forms; degenerate data outside
    // the declared states matches none and the root honestly resolves
    // undeclared.
    form = matchForm(declaration.root.forms, data, depth);
    if (form !== null) {
      resolved.tag = resolveTag(form.tag ?? null, data);
      resolved.attributes = resolveAttributes(form.attributes ?? null, data);
    }
  } else if (declaration && 'tag' in declaration) {
    resolved.tag = resolveTag(declaration.tag, data);
  }
  resolved.parts = resolveElements(
    declaration?.elements ?? null,
    data,
    depth,
    form,
    resolved,
    true,
  );
  return resolved;
};
