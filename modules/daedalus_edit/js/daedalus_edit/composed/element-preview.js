/**
 * @file
 * Morphs a composed element's DOM between two resolved trees.
 *
 * The preview half of the client resolver twin: an editing gesture changes
 * a property bag ahead of any commit, the editor resolves the OLD and NEW
 * bags through Drupal.Daedalus.Blueprint.resolveElement (the same
 * computation the server render rides), and this module reconciles the
 * on-page element from the old tree's shape to the new one. No editor
 * hand-builds markup any more: the tree is the truth, the morph follows
 * it, so the preview cannot drift from what the commit's markup swap will
 * deliver.
 *
 * Identity is tracked by MODEL KEY, not by element: the root ('') and each
 * part key map to physical elements on both sides, and an element carries
 * its unmodeled attributes with it wherever its key moves. That makes the
 * unstable-root cases fall out instead of needing bespoke fixups:
 * - Retag (the text block's tag switch): the root's key keeps the same
 *   physical children and attributes on a new element of the new tag.
 * - Nest (linking an unlinked image): the old root element IS the part the
 *   new tree nests (the hoisting rule read backwards), so it keeps its
 *   own attributes (src, alt, the part class) while the identity bag —
 *   the selection key, the component uuid, the editor attribution, the
 *   daedalus_stylish scope classes — migrates onto the new anchor root, exactly
 *   what the server prints.
 * - Un-nest (unlinking): the part element becomes the root and the bag
 *   migrates back onto it; its own attributes never left it, which is why
 *   the old root-swap's class re-seating fixups have no successor here.
 * - Part appear/disappear (the text block's anchor): the element is
 *   created from / removed with its declared model, and the content it
 *   mounts follows the mounting rule — the tree's slot claimant, or the
 *   root while the claim is gone.
 *
 * Scope: the shipped composed shapes (a root plus top-level parts). Editor
 * chrome the model does not speak for is the caller's: mountAttributes
 * declares the attributes that follow a token's mount element (the typing
 * surface markers), ownAttributes the attributes that never migrate off
 * the element carrying them (the image's server-derived source set).
 *
 * Handed to registration callbacks as `elementPreview` on the
 * DaedalusEditQueue API.
 *
 * @see ../editor-registry.js
 * @see daedalus_ui/js/edit_mode/blueprint/element-resolver.js
 */

/**
 * The class token of a part key ('.text-block-link' -> 'text-block-link').
 */
const classOf = (key) => key.replace(/^\.+/, '');

/**
 * Creates a physical element for a resolved tag, namespace-aware.
 *
 * The resolved tree speaks in tags; `svg` is the one tag in the shipped
 * grammar whose element lives outside the HTML namespace, and an
 * HTML-namespace `<svg>` built by document.createElement renders nothing.
 * Every site that builds a model element goes through here so the vector
 * root is a real SVGSVGElement wherever the morph has to mint one.
 */
const SVG_NS = 'http://www.w3.org/2000/svg';
const createElement = (tag) =>
  tag === 'svg'
    ? document.createElementNS(SVG_NS, tag)
    : document.createElement(tag);

/**
 * The physical element a tree key addresses under a root, or null.
 */
const elementForKey = (root, tree, key) => {
  if (key === '' || tree.hoisted.includes(key)) {
    return root;
  }
  return root.querySelector(key);
};

/**
 * The element a token's children mount in per the mounting rule.
 */
const mountForToken = (root, tree, token) => {
  const claimant = tree.slots[token];
  return claimant ? elementForKey(root, tree, claimant) : root;
};

/**
 * Moves the identity bag from one root element to another.
 *
 * Everything unmodeled migrates — the selection key, the component uuid,
 * the editor attribution, editing state classes — except the receiving
 * and giving elements' OWN attributes (ownAttributes) and the class
 * tokens the model assigns to the giving element (keepClasses: a part
 * class stays with its part). Classes merge instead of overwriting: the
 * old root-swap moved the whole class attribute wholesale, which is
 * exactly what stranded part classes on the wrong element.
 */
const migrateBag = (from, to, ownAttributes, keepClasses) => {
  Array.from(from.attributes).forEach(({ name, value }) => {
    if (name === 'class' || ownAttributes.includes(name)) {
      return;
    }
    to.setAttribute(name, value);
    from.removeAttribute(name);
  });
  Array.from(from.classList).forEach((token) => {
    if (keepClasses.includes(token)) {
      return;
    }
    to.classList.add(token);
    from.classList.remove(token);
  });
  if (from.getAttribute('class') === '') {
    from.removeAttribute('class');
  }
};

/**
 * Applies a resolved node's modeled attributes to its element.
 *
 * Sets the new tree's declared attributes, removes the old tree's declared
 * attributes the new tree no longer models (an unlinked href), and keeps
 * the element's class list in step with the modeled classes.
 */
const applyModeled = (
  element,
  oldAttributes,
  newAttributes,
  oldClasses,
  newClasses,
) => {
  Object.keys(oldAttributes).forEach((name) => {
    if (!(name in newAttributes)) {
      element.removeAttribute(name);
    }
  });
  Object.entries(newAttributes).forEach(([name, value]) => {
    element.setAttribute(name, value);
  });
  oldClasses.forEach((token) => {
    if (!newClasses.includes(token)) {
      element.classList.remove(token);
    }
  });
  newClasses.forEach((token) => {
    element.classList.add(token);
  });
};

/**
 * Morphs a composed element from one resolved tree to another.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited; the composed root re-resolves through it.
 * @param {Object} oldTree
 *   The resolved tree of the CURRENT property bag — the shape the DOM is
 *   in (the server rendered it, or a previous preview morphed to it).
 * @param {Object} newTree
 *   The resolved tree of the previewed property bag.
 * @param {Object} options
 *   The editor's bindings:
 *   - {string[]} ownAttributes: attribute names that never migrate off
 *     the element carrying them (the image's src/alt set).
 *   - {Object<string, string[]>} mountAttributes: per mount token, the
 *     attribute names that follow the token's mount element (the typing
 *     surface markers).
 */
export function applyResolved(
  EditableElement,
  oldTree,
  newTree,
  { ownAttributes = [], mountAttributes = {} } = {},
) {
  let root = EditableElement.getPageElement();
  if (!root || newTree.tag === null) {
    // No leaf on the page, or the previewed data resolves no declared
    // root: nothing the model can preview.
    return;
  }

  // Capture the OLD physical structure before anything moves.
  const oldParts = {};
  Object.keys(oldTree.parts).forEach((key) => {
    const element = elementForKey(root, oldTree, key);
    if (element) {
      oldParts[key] = element;
    }
  });
  const partElements = new Set(Object.values(oldParts));
  const tokens = [
    ...new Set([
      ...Object.keys(oldTree.slots),
      ...Object.keys(newTree.slots),
      ...Object.keys(mountAttributes),
    ]),
  ];
  const mounts = tokens.map((token) => {
    const from = mountForToken(root, oldTree, token) ?? root;
    return {
      token,
      from,
      // The token's content: the mount's child nodes that are not part
      // elements (at most one token mounts per element in the shipped
      // shapes).
      content: Array.from(from.childNodes).filter(
        (node) => !partElements.has(node),
      ),
      attributes: (mountAttributes[token] ?? [])
        .filter((name) => from.hasAttribute(name))
        .map((name) => ({ name, value: from.getAttribute(name) })),
    };
  });

  // The root transition. Keys continuing across the hoist boundary keep
  // their physical element (the hoisting rule read as identity).
  const nestedKey = oldTree.hoisted.find((key) => key in newTree.parts) ?? null;
  const hoistedKey =
    newTree.hoisted.find((key) => key in oldTree.parts) ?? null;
  const oldRoot = root;
  if (nestedKey !== null) {
    // Nest: the old root element continues as the part; a fresh root
    // wraps it and the identity bag migrates onto it.
    root = createElement(newTree.tag);
    oldRoot.replaceWith(root);
    root.append(oldRoot);
    migrateBag(oldRoot, root, ownAttributes, [classOf(nestedKey)]);
    oldParts[nestedKey] = oldRoot;
  } else if (hoistedKey !== null) {
    // Un-nest: the part element continues as the root; the identity bag
    // migrates back onto it and the old root wrapper dies.
    root = oldParts[hoistedKey];
    migrateBag(oldRoot, root, ownAttributes, oldTree.classes);
    oldRoot.replaceWith(root);
    delete oldParts[hoistedKey];
  } else if (root.tagName.toLowerCase() !== newTree.tag) {
    // Retag: a new element of the new tag takes over the root's
    // attributes and children wholesale (modeled leftovers are removed
    // below). The child nodes move as the same objects, which is what
    // lets a caller's caret survive the swap.
    root = createElement(newTree.tag);
    Array.from(oldRoot.attributes).forEach(({ name, value }) => {
      root.setAttribute(name, value);
    });
    root.append(...oldRoot.childNodes);
    oldRoot.replaceWith(root);
  }
  applyModeled(
    root,
    nestedKey !== null ? {} : oldTree.attributes,
    newTree.attributes,
    nestedKey !== null ? [] : oldTree.classes,
    newTree.classes,
  );

  // Parts: update the continuing, create the appearing; the disappearing
  // are removed after their content is remounted below.
  const dead = [];
  Object.entries(oldParts).forEach(([key, element]) => {
    if (key in newTree.parts) {
      const oldNode =
        key === nestedKey ? { attributes: {} } : oldTree.parts[key];
      applyModeled(
        element,
        oldNode.attributes ?? {},
        newTree.parts[key].attributes,
        [],
        [classOf(key)],
      );
    } else {
      dead.push(element);
    }
  });
  Object.entries(newTree.parts).forEach(([key, part]) => {
    if (key in oldParts || part.tag === null) {
      return;
    }
    const element = createElement(part.tag);
    applyModeled(element, {}, part.attributes, [], [classOf(key)]);
    root.append(element);
    oldParts[key] = element;
  });

  // The mounting rule: content and surface attributes follow each token
  // to its new mount.
  mounts.forEach(({ token, from, content, attributes }) => {
    const claimant = newTree.slots[token] ?? null;
    const to = claimant ? (oldParts[claimant] ?? root) : root;
    content.forEach((node) => {
      if (node.parentNode !== to) {
        to.append(node);
      }
    });
    (mountAttributes[token] ?? []).forEach((name) => {
      // Remove everywhere the marker could be stranded (the retag path
      // copied it with the rest), then restamp the recorded value on the
      // new mount.
      if (from !== to) {
        from.removeAttribute(name);
      }
      if (root !== to) {
        root.removeAttribute(name);
      }
    });
    attributes.forEach(({ name, value }) => {
      to.setAttribute(name, value);
    });
  });

  dead.forEach((element) => element.remove());
}
