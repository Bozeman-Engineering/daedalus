/**
 * Self-asserting tests for the portal chip's pure model
 * (portal-model.js): which element is the portal for a pointer target
 * (the nearest stamped placement root; the chip's own chrome keeps the
 * portal it belongs to), what the chip reads off it (the stamped URL and
 * label), and whether a double-click navigates (only when the selection
 * resolver yielded nothing for the target, so the Selector's drill keeps
 * precedence).
 *
 * Run with `node --test tests/js/portal-model.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  PORTAL_URL_ATTRIBUTE,
  PORTAL_LABEL_ATTRIBUTE,
  PORTAL_SELECTOR,
  portalForTarget,
  portalIdentity,
  portalNavigation,
} from '../../js/edit_mode/components/portal/portal-model.js';

/**
 * A stub element: attributes, a parent, and the two DOM lookups the model
 * uses (closest by the portal selector only; contains by ancestry).
 */
const stubElement = (attributes = {}, parent = null) => {
  const element = {
    attributes,
    parent,
    getAttribute: (name) => attributes[name] ?? null,
    closest: (selector) => {
      assert.equal(selector, PORTAL_SELECTOR);
      let node = element;
      while (node) {
        if (node.attributes[PORTAL_URL_ATTRIBUTE] !== undefined) {
          return node;
        }
        node = node.parent;
      }
      return null;
    },
    contains: (other) => {
      let node = other;
      while (node) {
        if (node === element) {
          return true;
        }
        node = node.parent;
      }
      return false;
    },
  };
  return element;
};

const footer = stubElement({
  [PORTAL_URL_ATTRIBUTE]: '/block-content/6/full',
  [PORTAL_LABEL_ATTRIBUTE]: 'Footer',
});
const insideFooter = stubElement({ 'data-selectable': 'container:abc' }, footer);
const leaf = stubElement({}, insideFooter);
const body = stubElement({});
const canvasElement = stubElement({ 'data-selectable': 'block:xyz' }, body);

test('the portal is the nearest stamped placement root above the target', () => {
  assert.equal(portalForTarget(leaf), footer);
  assert.equal(portalForTarget(insideFooter), footer);
  assert.equal(portalForTarget(footer), footer);
});

test('a target outside every portal resolves to nothing', () => {
  assert.equal(portalForTarget(canvasElement), null);
  assert.equal(portalForTarget(body), null);
});

test('a non-element target (text node, window, nothing) resolves to nothing', () => {
  assert.equal(portalForTarget(null), null);
  assert.equal(portalForTarget(undefined), null);
  assert.equal(portalForTarget({}), null);
});

test('pointing at the chip keeps the portal it belongs to', () => {
  // The chip is painted outside the portal; the pointer crossing to its
  // Edit link must not lose the portal on the way.
  const chrome = stubElement({});
  const link = stubElement({}, chrome);
  assert.equal(portalForTarget(link, { chrome, current: footer }), footer);
  // Without a shown portal the chip has nothing to keep.
  assert.equal(portalForTarget(link, { chrome, current: null }), null);
  // Chrome only matters for targets inside it.
  assert.equal(portalForTarget(canvasElement, { chrome, current: footer }), null);
});

test('the identity is the stamped URL and label', () => {
  assert.deepEqual(portalIdentity(footer), { url: '/block-content/6/full', label: 'Footer' });
});

test('a missing label reads as empty; a missing URL is no portal at all', () => {
  const unlabeled = stubElement({ [PORTAL_URL_ATTRIBUTE]: '/block-content/1/full' });
  assert.deepEqual(portalIdentity(unlabeled), { url: '/block-content/1/full', label: '' });
  assert.equal(portalIdentity(stubElement({ [PORTAL_LABEL_ATTRIBUTE]: 'Header' })), null);
  assert.equal(portalIdentity(stubElement({ [PORTAL_URL_ATTRIBUTE]: '' })), null);
  assert.equal(portalIdentity(null), null);
});

test('a double-click in a portal navigates when nothing selectable resolved', () => {
  assert.equal(portalNavigation({ portal: footer, resolvedKey: null }), '/block-content/6/full');
  assert.equal(portalNavigation({ portal: footer }), '/block-content/6/full');
});

test('the drill gesture keeps precedence: a resolved selectable never navigates', () => {
  assert.equal(portalNavigation({ portal: footer, resolvedKey: 'block:xyz' }), null);
});

test('a double-click outside every portal is not ours', () => {
  assert.equal(portalNavigation({ portal: null, resolvedKey: null }), null);
  assert.equal(portalNavigation({}), null);
});
