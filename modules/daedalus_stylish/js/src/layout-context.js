/**
 * The canvas-side selection-facts read: what the selected element IS and
 * what surrounds it.
 *
 * The one sanctioned "read contextually" primitive behind the adaptive
 * widgets (text_alignment's cross axis, the dynamic gates). It answers two
 * questions from live computed style and DOM structure:
 * - self: what kind of box is selected (display and flex-direction,
 *   whether it contains other selectable elements, how many child boxes it
 *   lays out, whether it carries any real text, and, joined in by the
 *   caller from the blueprint mirror's `_element.markup` annotation, the
 *   model's answer rather than a DOM scrape, which of its declared parts
 *   are present);
 * - parent: how the DOM parent lays out its children (display,
 *   flex-direction, align-items) plus the nearest selectable ancestor key.
 * It never feeds a write path; controls write only to the selected
 * element's own key. Facts only ever shape what a widget OFFERS.
 *
 * Leader-only by construction: computed style needs the canvas document,
 * which the satellite window does not have. The leader source exposes this
 * as getLayoutContext() and the popout leader broadcasts the result with
 * the rest of the session state, so both hosts read the same context (see
 * the satellite source's cache).
 *
 * The computed-style parent is the IMMEDIATE parentElement (the only box
 * whose flex/grid affects this child), while parent.key is the nearest
 * SELECTABLE ancestor: the element the attribution copy can honestly name
 * and a future click-through could select. When wrapper markup sits
 * between the two, the layout truth still comes from the real parent box.
 */

/**
 * Editor chrome that lives INSIDE canvas elements and must not count as
 * content: contextual-links wrappers (core), daedalus_edit validation
 * messages, daedalus_ui drag placeholders, the layout-builder content
 * preview placeholder label, and CKEditor 5 in-content UI. Text or boxes
 * under these are the editor talking, not the page — hasText and
 * childCount skip them so gates reflect what a visitor would see.
 */
export const CHROME_SELECTOR = [
  '[data-contextual-id]',
  '.daedalus-edit-validation-message',
  '.daedalus-ui-drop-placeholder',
  '.layout-builder-block__content-preview-placeholder-label',
  '.ck-reset_all',
  '.ck-widget__type-around',
  '.ck-fake-selection-container',
].join(', ');

/**
 * Elements that generate no box and render no text, whatever they hold:
 * the inline stylesheet daedalus_stylish prints inside the entity root
 * (<style data-daedalus-stylish-entity>), scripts, templates and link tags. A grid
 * or flex gap ignores them, so a counter that saw them would open the gap
 * gate on a one-band page (the style tag beside the one section) and a
 * text check would read a stylesheet's rules as page copy. hasText and
 * childCount skip them beside the chrome above.
 */
export const NON_BOX_SELECTOR = ['style', 'script', 'template', 'link'].join(', ');

/**
 * Whether a child element is neither editor chrome nor a box-less
 * element: the one test both facts apply per element child.
 *
 * @param {Element} child
 *
 * @returns {boolean}
 */
const isPageBox = (child) => !child.matches(CHROME_SELECTOR) && !child.matches(NON_BOX_SELECTOR);

/**
 * Whether a node's subtree carries any real (non-whitespace) text.
 *
 * The cheap DOM check behind the hasText fact: recursion over childNodes,
 * short-circuiting on the first hit, skipping editor-chrome subtrees
 * (CHROME_SELECTOR) and box-less elements (NON_BOX_SELECTOR). Numeric
 * nodeType literals (3 = text, 1 = element) keep this runnable under
 * plain node tests with fake nodes.
 *
 * @param {Node} node
 *
 * @return {boolean}
 */
export const hasTextNode = (node) => {
  for (const child of node.childNodes) {
    if (child.nodeType === 3 && /\S/.test(child.data)) {
      return true;
    }
    if (child.nodeType === 1 && isPageBox(child) && hasTextNode(child)) {
      return true;
    }
  }
  return false;
};

/**
 * The number of child BOXES the element lays out: direct element children
 * minus editor chrome and box-less elements. This is the count the gap
 * gate cares about — a gap on a one-child container is a no-op a lay
 * user cannot see.
 *
 * @param {Element} element
 *
 * @return {number}
 */
export const childCount = (element) => {
  let count = 0;
  for (const child of element.children) {
    if (isPageBox(child)) {
      count += 1;
    }
  }
  return count;
};

/**
 * The selected element's own facts.
 *
 * isContainer is structural, not display-based: does this element contain
 * other SELECTABLE elements (a section holds regions, a region holds
 * blocks, a block holds fields)? A text field full of paragraphs is not a
 * container in this sense — its markup is content, not page structure —
 * while a one-block region still is. Display-based questions ("is this
 * already flex/grid?") read self.display instead.
 *
 * parts is the per-declared-selector presence map the caller resolved
 * from the markup annotation (leader-source getLayoutContext); {} when
 * the type declares none or the model does not speak. It rides the facts
 * object so every consumer (both hosts, via the broadcast) reads one
 * shape.
 *
 * partChildCount is the per-declared-part twin of childCount: for each
 * declared selector the caller names, the number of child boxes the
 * part's FIRST canvas match under the selection lays out (the same
 * first-match rule the placeholder read uses), read from the DOM the way
 * childCount is. It is what an element-bound control's gate reads (the
 * page's Gap control bound to the layout root: a gap between one band is
 * a no-op), and it counts the part's OWN children, never a flow
 * container resolved through it (a one-band layout root would otherwise
 * be climbed through to the entity root). A selector with no match is
 * omitted, which a gate reads as unknown and degrades to offering;
 * whether the part EXISTS is the presence map's question, the model's
 * answer, so the two facts stay separately sourced. Sibling map rather
 * than a richer per-part object so the presence map's consumers (the
 * offer rule, the code view's part gestures) keep reading booleans.
 *
 * @param {Element} element
 * @param {CSSStyleDeclaration} computed
 * @param {Object<string, boolean>} parts
 *   Declared part selector → annotation presence.
 * @param {string[]} selectors
 *   The declared part selectors to count under (single class selectors,
 *   the PART_SELECTOR shape; the caller filters).
 *
 * @return {{display: string, flexDirection: string, isContainer: boolean,
 *   childCount: number, hasText: boolean,
 *   parts: Object<string, boolean>,
 *   partChildCount: Object<string, number>}}
 */
export const selfFacts = (element, computed, parts = {}, selectors = []) => {
  const partChildCount = {};
  for (const selector of selectors) {
    const match = element.querySelector(selector);
    if (match) {
      partChildCount[selector] = childCount(match);
    }
  }
  return {
    display: computed.display,
    flexDirection: computed.flexDirection,
    isContainer: element.querySelector('[data-selectable]') !== null,
    childCount: childCount(element),
    hasText: hasTextNode(element),
    parts,
    partChildCount,
  };
};

/**
 * The immediate parent box's facts: the computed layout that places the
 * selection, plus the nearest selectable ancestor key for attribution
 * (closest() finds the parent itself first; with wrapper markup between
 * them the layout truth is still the immediate box's and the name the
 * ancestor's).
 *
 * @param {Element} parent
 * @param {CSSStyleDeclaration} computed
 *
 * @return {{display: string, flexDirection: string, alignItems: string,
 *   key: ?string}}
 */
export const parentFacts = (parent, computed) => ({
  display: computed.display,
  flexDirection: computed.flexDirection,
  alignItems: computed.alignItems,
  key:
    parent.closest('[data-selectable]')?.getAttribute('data-selectable') ??
    null,
});

/**
 * Reads the selection facts for a selectable key from the live canvas.
 *
 * @param {?string} key
 *   The selected element's selectable key.
 * @param {Object<string, boolean>} parts
 *   The key's per-declared-part presence facts, resolved by the caller
 *   from the markup annotation, this module reads computed style and DOM
 *   structure, it does not know the guide or the mirror.
 * @param {string[]} selectors
 *   The key's declared part selectors (partsForKey ids), counted under
 *   the selection for partChildCount; every declared part, not only the
 *   ones the model speaks for, since the count is a DOM fact.
 *
 * @return {?{self: {display: string, flexDirection: string,
 *   isContainer: boolean, childCount: number, hasText: boolean,
 *   parts: Object<string, boolean>, partChildCount: Object<string, number>},
 *   parent: ?{display: string, flexDirection: string, alignItems: string,
 *   key: ?string}}}
 *   The selection facts, or null when the key has no canvas element
 *   (context unknown; widgets degrade rather than guess). parent is null
 *   for a parentless element (the document root); consumers of parent
 *   facts degrade the same way they do for a null context.
 */
export const readLayoutContext = (key, parts = {}, selectors = []) => {
  if (typeof key !== 'string' || key === '') {
    return null;
  }
  // Keys carry a validated charset, but the attribute value is quoted text
  // either way: escape the two characters that could break out (the
  // tree-parts.js precedent).
  const escaped = key.replace(/[\\"]/g, '\\$&');
  const element = document.querySelector(`[data-selectable="${escaped}"]`);
  if (!element) {
    return null;
  }
  const parent = element.parentElement;
  return {
    self: selfFacts(element, window.getComputedStyle(element), parts, selectors),
    parent: parent
      ? parentFacts(parent, window.getComputedStyle(parent))
      : null,
  };
};
