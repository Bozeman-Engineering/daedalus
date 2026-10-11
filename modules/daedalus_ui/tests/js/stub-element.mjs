/**
 * A stub element for the drag layer's node specs: a selectable key, a
 * rect, generic attributes, and just enough DOM surface for gaps.js and
 * tree-gaps.js (getAttribute, matches, closest, parentElement,
 * querySelector(All) over descendants, getBoundingClientRect).
 *
 * Shared by gaps.test.mjs and tree-gaps.test.mjs; a spec installs it as
 * globalThis.Element before importing the module under test (the
 * resolvers guard on `instanceof Element`).
 */
export class El {
  constructor(selectable, rect) {
    this.selectable = selectable;
    this.rect = rect;
    this.children = [];
    this.parentElement = null;
    this.mainEntity = false;
    // Generic attributes (data-tree-key, aria-level and friends).
    this.attrs = {};
  }

  add(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    if (name === 'data-selectable') {
      return this.selectable ?? null;
    }
    return this.attrs[name] ?? null;
  }

  hasAttribute(name) {
    if (name === 'data-selectable') {
      return this.selectable != null;
    }
    if (name === 'data-main-entity') {
      return this.mainEntity;
    }
    return name in this.attrs;
  }

  get childElementCount() {
    return this.children.length;
  }

  /**
   * Attribute selectors only, compound and comma-listed: [name],
   * [name="v"], [name^="v"], [name~="v"] (the shapes the resolvers
   * query). A descendant prefix such as `.daedalus-ui-tree ` is ignored.
   */
  matches(selector) {
    return selector.split(',').some((single) => {
      const clauses = [...single.trim().matchAll(/\[([a-z-]+)(?:([\^~]?)="([^"]*)")?\]/g)];
      if (clauses.length === 0) {
        return false;
      }
      return clauses.every(([, name, operator, value]) => {
        if (value === undefined) {
          return this.hasAttribute(name);
        }
        const actual = this.getAttribute(name);
        if (actual == null) {
          return false;
        }
        if (operator === '^') {
          return actual.startsWith(value);
        }
        if (operator === '~') {
          return actual.split(' ').includes(value);
        }
        return actual === value;
      });
    });
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }

  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]);
  }

  querySelectorAll(selector) {
    return this.descendants().filter((node) => node.matches(selector));
  }

  querySelector(selector) {
    return this.descendants().find((node) => node.matches(selector)) ?? null;
  }

  getBoundingClientRect() {
    const { left, top, width, height } = this.rect;
    return { left, top, width, height, right: left + width, bottom: top + height };
  }

  get isConnected() {
    return true;
  }
}
