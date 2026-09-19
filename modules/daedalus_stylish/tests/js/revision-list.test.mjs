/**
 * Self-asserting tests for the shared revision list, the one history face
 * the variant, swatch and animation cards wear.
 *
 * Run directly (`node tests/js/revision-list.test.mjs`) or via JsSuitesTest
 * in the PHP tier. Renders the component's vnode tree (the JSX compiled by
 * jsx-loader.mjs) and locks the markup the inspector CSS and the cards rely
 * on: the empty-history rule, the current row's mark, the restore chip with
 * its one title string, and the date face. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { registerJsx } from './jsx-loader.mjs';

registerJsx();
const { RevisionList, revisionDate } = await import('../../js/src/revision-list.js');

const tests = [];
const test = (name, run) => tests.push([name, run]);

globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
};

/**
 * Renders a vnode tree to a plain HTML string: function components are
 * called, host elements print their class and the attributes a test
 * reads, handlers are dropped.
 */
const html = (node) => {
  if (node == null || typeof node === 'boolean') {
    return '';
  }
  if (Array.isArray(node)) {
    return node.map(html).join('');
  }
  if (typeof node !== 'object') {
    return String(node);
  }
  const { type, props } = node;
  if (typeof type === 'function') {
    return html(type(props));
  }
  const { children, className, ...rest } = props;
  const attributes = Object.entries(rest)
    .filter(([, value]) => value != null && value !== false && typeof value !== 'function')
    .map(([name, value]) => (value === true ? ` ${name}` : ` ${name}="${value}"`));
  return `<${type}${className != null ? ` class="${className}"` : ''}${attributes.join('')}>${html(children)}</${type}>`;
};

/**
 * Every host vnode in the tree the predicate accepts, document order.
 */
const find = (node, predicate, found = []) => {
  if (node == null || typeof node !== 'object') {
    return found;
  }
  if (Array.isArray(node)) {
    node.forEach((child) => find(child, predicate, found));
    return found;
  }
  if (typeof node.type === 'function') {
    return find(node.type(node.props), predicate, found);
  }
  if (predicate(node)) {
    found.push(node);
  }
  return find(node.props.children, predicate, found);
};

const TITLE = 'Restore this revision as a new one on top, never a rollback, so a restore is itself one restore away from undo.';

const HISTORY = [
  { revision_id: 12, created: 1_700_000_000, user: 'lily', current: true },
  { revision_id: 7, created: 1_690_000_000, user: 'tim', current: false },
  { revision_id: 3, created: 1_680_000_000, user: null, current: false },
];

test('revisionDate: the record\'s unix seconds in the viewer\'s locale', () => {
  assert.equal(revisionDate(0), new Date(0).toLocaleString());
  assert.equal(revisionDate(1_700_000_000), new Date(1_700_000_000_000).toLocaleString());
});

test('a history of one is no history: null and a single revision render nothing', () => {
  assert.equal(RevisionList({ revisions: null, onRestore: () => {} }), null);
  assert.equal(RevisionList({ revisions: [], onRestore: () => {} }), null);
  assert.equal(RevisionList({ revisions: [HISTORY[0]], onRestore: () => {} }), null);
});

test('one row per revision: the current one marked, every other one a restore chip', () => {
  assert.equal(html(RevisionList({ revisions: HISTORY, onRestore: () => {} })), [
    '<div class="daedalus-stylish-library-card__revisions">',
    '<div class="daedalus-stylish-library-card__revision daedalus-stylish-library-card__revision--current">',
    `<span class="daedalus-stylish-library-card__revision-date">${revisionDate(1_700_000_000)} · lily</span>`,
    '<span class="daedalus-stylish-library-card__revision-current">current</span>',
    '</div>',
    '<div class="daedalus-stylish-library-card__revision">',
    `<span class="daedalus-stylish-library-card__revision-date">${revisionDate(1_690_000_000)} · tim</span>`,
    `<button class="daedalus-ui-chip" type="button" title="${TITLE}">restore</button>`,
    '</div>',
    '<div class="daedalus-stylish-library-card__revision">',
    `<span class="daedalus-stylish-library-card__revision-date">${revisionDate(1_680_000_000)}</span>`,
    `<button class="daedalus-ui-chip" type="button" title="${TITLE}">restore</button>`,
    '</div>',
    '</div>',
  ].join(''));
});

test('the restore chip carries the one title string, no em dash', () => {
  const chips = find(RevisionList({ revisions: HISTORY, onRestore: () => {} }), (node) => node.type === 'button');
  assert.equal(chips.length, 2);
  chips.forEach((chip) => assert.equal(chip.props.title, TITLE));
  assert.equal(TITLE.includes('—'), false);
});

test('busy disables the chips; idle leaves them live', () => {
  const idle = find(RevisionList({ revisions: HISTORY, onRestore: () => {} }), (node) => node.type === 'button');
  assert.deepEqual(idle.map((chip) => chip.props.disabled), [false, false]);
  const busy = find(RevisionList({ revisions: HISTORY, busy: true, onRestore: () => {} }), (node) => node.type === 'button');
  assert.deepEqual(busy.map((chip) => chip.props.disabled), [true, true]);
});

test('a chip hands its row\'s record to onRestore', () => {
  const restored = [];
  const chips = find(RevisionList({ revisions: HISTORY, onRestore: (revision) => restored.push(revision) }), (node) => node.type === 'button');
  chips[1].props.onClick();
  assert.deepEqual(restored, [HISTORY[2]]);
  chips[0].props.onClick();
  assert.deepEqual(restored, [HISTORY[2], HISTORY[1]]);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} failure(s)`);
  process.exit(1);
}
console.log(`${tests.length} tests passed`);
