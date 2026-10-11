/**
 * Self-asserting tests for the portal chip's pure model
 * (portal-model.js): which element is the portal for a pointer target
 * (the nearest stamped placement root; the chip's own chrome keeps the
 * portal it belongs to), what the chip reads off it (the stamped URL and
 * label), and whether a double-click navigates (when the selection
 * resolver yielded nothing for the target, or a key whose element lies
 * ABOVE the portal; a key resolved inside the portal's subtree keeps the
 * Selector's drill), which portal the hover shows (the same rule: a slot
 * door's fill is the page's own, so a key resolved inside the door sets it
 * aside), the chip's label (the name, then the stamped kind word
 * lowercased: "Header · blueprint"), and the URL a leave navigates to (the
 * editing URL carrying the page it left from as `destination`).
 *
 * Run with `node --test tests/js/portal-model.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  PORTAL_URL_ATTRIBUTE,
  PORTAL_LABEL_ATTRIBUTE,
  PORTAL_TYPE_ATTRIBUTE,
  PORTAL_SLOT_ATTRIBUTE,
  PORTAL_CLAIM_ATTRIBUTE,
  PORTAL_SELECTOR,
  portalForTarget,
  portalIdentity,
  portalChipLabel,
  portalNavigation,
  portalShowing,
  portalUnder,
  portalLeaveUrl,
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
        if (
          node.attributes[PORTAL_URL_ATTRIBUTE] !== undefined ||
          node.attributes[PORTAL_CLAIM_ATTRIBUTE] !== undefined
        ) {
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
  [PORTAL_URL_ATTRIBUTE]: '/blueprint/6',
  [PORTAL_LABEL_ATTRIBUTE]: 'Footer',
  [PORTAL_TYPE_ATTRIBUTE]: 'Blueprint',
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

test('the identity is the stamped URL, label and kind', () => {
  assert.deepEqual(portalIdentity(footer), { url: '/blueprint/6', label: 'Footer', type: 'Blueprint', claim: null });
});

test('the chip label is the name, then the kind word lowercased', () => {
  assert.equal(portalChipLabel(portalIdentity(footer)), 'Footer · blueprint');
  const frameNode = stubElement({
    [PORTAL_URL_ATTRIBUTE]: '/site-frame/stark',
    [PORTAL_LABEL_ATTRIBUTE]: 'Stark',
    [PORTAL_TYPE_ATTRIBUTE]: 'Site frame',
  });
  assert.equal(portalChipLabel(portalIdentity(frameNode)), 'Stark · site frame');
  // No kind stamped (a claim-only door): the name alone.
  assert.equal(portalChipLabel({ label: 'Header', type: null }), 'Header');
});

test('a missing label reads as empty; neither a URL nor a claim is no portal at all', () => {
  const unlabeled = stubElement({ [PORTAL_URL_ATTRIBUTE]: '/blueprint/1' });
  assert.deepEqual(portalIdentity(unlabeled), { url: '/blueprint/1', label: '', type: null, claim: null });
  assert.equal(portalIdentity(stubElement({ [PORTAL_LABEL_ATTRIBUTE]: 'Header' })), null);
  assert.equal(portalIdentity(stubElement({ [PORTAL_URL_ATTRIBUTE]: '' })), null);
  assert.equal(portalIdentity(null), null);
});

// A slot door showing its default, claimable by this page: the slot it
// declares and the default it shows, with or without an editing URL.
const claimableHeader = stubElement({
  [PORTAL_URL_ATTRIBUTE]: '/blueprint/1',
  [PORTAL_LABEL_ATTRIBUTE]: 'Header',
  [PORTAL_TYPE_ATTRIBUTE]: 'Blueprint',
  [PORTAL_SLOT_ATTRIBUTE]: 'header',
  [PORTAL_CLAIM_ATTRIBUTE]: 'header',
});
const claimOnly = stubElement({
  [PORTAL_LABEL_ATTRIBUTE]: 'Header',
  [PORTAL_SLOT_ATTRIBUTE]: 'header',
  [PORTAL_CLAIM_ATTRIBUTE]: 'header',
});

test('a claimable slot door carries its claim beside the URL', () => {
  assert.deepEqual(portalIdentity(claimableHeader), {
    url: '/blueprint/1',
    label: 'Header',
    type: 'Blueprint',
    claim: { slot: 'header', blueprint: 'header' },
  });
});

test('a claimable door without an editing URL is still a portal, with no destination', () => {
  assert.deepEqual(portalIdentity(claimOnly), {
    url: null,
    label: 'Header',
    type: null,
    claim: { slot: 'header', blueprint: 'header' },
  });
  assert.equal(portalChipLabel(portalIdentity(claimOnly)), 'Header', 'The name alone: nothing stamped a kind.');
  assert.equal(portalForTarget(stubElement({}, claimOnly)), claimOnly, 'The selector finds it.');
  assert.equal(portalNavigation({ portal: claimOnly, resolvedKey: null }), null, 'Nothing to leave for.');
});

test('a claim stamp without the slot id is no claim', () => {
  const half = stubElement({ [PORTAL_URL_ATTRIBUTE]: '/blueprint/1', [PORTAL_CLAIM_ATTRIBUTE]: 'header' });
  assert.equal(portalIdentity(half).claim, null);
});

// The showing the chip hands the doorway registry: the stamps read back
// into the spine's shape, so the chip and the tree's shown-document row
// call one applies(showing) and one run(showing, uuid).
test('a claimable door reads back as a default shown, claimable, with its portal', () => {
  assert.deepEqual(portalShowing(portalIdentity(claimableHeader)), {
    shows: 'default',
    document: { label: 'Header' },
    blueprint: 'header',
    portal: { label: 'Header', kind: 'Blueprint', url: '/blueprint/1' },
    claimable: true,
  });
});

test('a claim-only door is claimable with no portal', () => {
  assert.deepEqual(portalShowing(portalIdentity(claimOnly)), {
    shows: 'default',
    document: { label: 'Header' },
    blueprint: 'header',
    portal: null,
    claimable: true,
  });
});

test('a url-only portal is not claimable and the stamps do not say what it shows', () => {
  // A linked footer the actor may open, or the frame's own chrome block:
  // the stamps carry the portal and nothing else, so `shows` stays null
  // rather than guessing from the kind word.
  assert.deepEqual(portalShowing(portalIdentity(footer)), {
    shows: null,
    document: { label: 'Footer' },
    blueprint: null,
    portal: { label: 'Footer', kind: 'Blueprint', url: '/blueprint/6' },
    claimable: false,
  });
  assert.deepEqual(portalShowing(portalIdentity(stubElement({ [PORTAL_URL_ATTRIBUTE]: '/blueprint/1' }))).portal, {
    label: '',
    kind: null,
    url: '/blueprint/1',
  });
  assert.equal(portalShowing(null), null);
  assert.equal(portalShowing(portalIdentity(null)), null);
});

test('a double-click in a portal navigates when nothing selectable resolved', () => {
  assert.equal(portalNavigation({ portal: footer, resolvedKey: null }), '/blueprint/6');
  assert.equal(portalNavigation({ portal: footer }), '/blueprint/6');
});

test('a resolved selectable passed without its element keeps the drill', () => {
  assert.equal(portalNavigation({ portal: footer, resolvedKey: 'block:xyz' }), null);
});

// A portal placed inside the page's own markup (a foreign document held
// by a block of the page): the pointer's closest presented ancestor
// resolves as that block, above the portal, while the foreign markup
// inside the portal has no projection row.
const nestedBlock = stubElement({ 'data-selectable': 'block:holder' }, body);
const nestedField = stubElement({ 'data-selectable': 'field:root:field_holder' }, nestedBlock);
const nestedPortal = stubElement(
  {
    [PORTAL_URL_ATTRIBUTE]: '/blueprint/7',
    [PORTAL_LABEL_ATTRIBUTE]: 'Nested',
  },
  nestedField,
);
const nestedInner = stubElement({ 'data-selectable': 'container:inner' }, nestedPortal);
const nestedLeaf = stubElement({}, nestedInner);

test('the portal inside presented markup: a key resolved on an ancestor of the portal still navigates', () => {
  assert.equal(portalForTarget(nestedLeaf), nestedPortal);
  // The page's block holding the placement (the merged placement row).
  assert.equal(
    portalNavigation({ portal: nestedPortal, resolvedKey: 'block:holder', resolvedElement: nestedBlock }),
    '/blueprint/7',
  );
  // A presented element between them, had it a row of its own.
  assert.equal(
    portalNavigation({ portal: nestedPortal, resolvedKey: 'field:root:field_holder', resolvedElement: nestedField }),
    '/blueprint/7',
  );
});

test('a key resolved inside the portal keeps the drill', () => {
  assert.equal(
    portalNavigation({ portal: nestedPortal, resolvedKey: 'container:inner', resolvedElement: nestedInner }),
    null,
  );
  assert.equal(
    portalNavigation({ portal: nestedPortal, resolvedKey: 'container:inner', resolvedElement: nestedLeaf }),
    null,
  );
});

test('a key resolved on the portal element itself is the page\'s: the gesture leaves', () => {
  assert.equal(
    portalNavigation({ portal: nestedPortal, resolvedKey: 'block:portal', resolvedElement: nestedPortal }),
    '/blueprint/7',
  );
});

test('a double-click outside every portal is not ours', () => {
  assert.equal(portalNavigation({ portal: null, resolvedKey: null }), null);
  assert.equal(portalNavigation({}), null);
});

// The leave URL: the editing URL carrying where it left from, so Save and
// Discard on the portal's root find the way back.
test('the leave URL carries the page it left from as the destination', () => {
  assert.equal(
    portalLeaveUrl('/blueprint/7', '/node/3'),
    '/blueprint/7?destination=%2Fnode%2F3',
  );
  assert.equal(
    portalLeaveUrl('/blueprint/6', '/about-us'),
    '/blueprint/6?destination=%2Fabout-us',
  );
});

test('a url already carrying a query appends the destination with an ampersand', () => {
  assert.equal(
    portalLeaveUrl('/blueprint/7?view_mode=full', '/node/3'),
    '/blueprint/7?view_mode=full&destination=%2Fnode%2F3',
  );
});

test('the destination is one percent-encoded value, a query of its own included', () => {
  assert.equal(
    portalLeaveUrl('/blueprint/7', '/node/3?page=2&x=y'),
    '/blueprint/7?destination=%2Fnode%2F3%3Fpage%3D2%26x%3Dy',
  );
});

test('no page to return to leaves the url unchanged', () => {
  assert.equal(portalLeaveUrl('/blueprint/7', ''), '/blueprint/7');
  assert.equal(portalLeaveUrl('/blueprint/7', null), '/blueprint/7');
  assert.equal(portalLeaveUrl('/blueprint/7', undefined), '/blueprint/7');
});

// A slot door: the template's (or the frame's) slot block is a portal
// whose subtree holds the page's OWN fill, every element of it a
// selectable of this page.
const door = stubElement(
  {
    [PORTAL_URL_ATTRIBUTE]: '/layout/template',
    [PORTAL_LABEL_ATTRIBUTE]: 'Template',
    [PORTAL_TYPE_ATTRIBUTE]: 'Template',
    'data-selectable': 'block:door',
  },
  body,
);
const fill = stubElement({ 'data-selectable': 'container:fill' }, door);
const fillLeaf = stubElement({}, fill);

test('the hover sets a portal aside while the pointer is over the page\'s own fill inside it', () => {
  assert.equal(portalForTarget(fillLeaf), door, 'the door is the nearest stamped root');
  assert.equal(portalUnder({ portal: door, resolvedKey: 'container:fill', resolvedElement: fill }), null);
  assert.equal(portalUnder({ portal: door, resolvedKey: 'block:door', resolvedElement: door }), door, 'the door itself keeps the portal');
  assert.equal(portalUnder({ portal: door, resolvedKey: null }), door, 'nothing selectable: the door');
  assert.equal(portalUnder({ portal: door, resolvedKey: 'block:door' }), door, 'a key with no element errs toward the door');
  assert.equal(portalUnder({ portal: null, resolvedKey: null }), null);
  // The double-click reads the same rule.
  assert.equal(portalNavigation({ portal: door, resolvedKey: 'container:fill', resolvedElement: fill }), null);
  assert.equal(portalNavigation({ portal: door, resolvedKey: 'block:door', resolvedElement: door }), '/layout/template');
});
