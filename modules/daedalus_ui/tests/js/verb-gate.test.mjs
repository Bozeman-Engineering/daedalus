/**
 * Self-asserting tests for the verb registry's pure applies gate
 * (js/edit_mode/verbs/verb-gate.js): the semantic kinds, the
 * selection/edit scope partition, the capability gate (the verb's
 * declared capability asked of the key or of its projection parent
 * through the one client gate), then the verb's own applies()
 * refinement, in that order; and the registration contract
 * (declaredCapability): a verb without a valid capability is refused,
 * naming the five.
 *
 * Run with `node --test tests/js/verb-gate.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { verbApplies, declaredCapability, verbCapability, verbSubject } from '../../js/edit_mode/verbs/verb-gate.js';

const verb = (overrides = {}) => ({
  id: 'test.verb',
  kinds: ['block', 'container'],
  applies: null,
  editScoped: false,
  capability: 'arrange',
  of: 'element',
  ...overrides,
});
const KEY = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const PARENT = 'container:cccccccc-1111-2222-3333-444444444444';
// An open gate: every capability allowed on every key.
const open = () => true;

test('a verb applies within its kinds and nowhere else', () => {
  assert.equal(verbApplies(verb(), KEY, { kind: 'block', capable: open }), true);
  assert.equal(verbApplies(verb(), KEY, { kind: 'field', capable: open }), false);
  assert.equal(verbApplies(verb(), null, { kind: 'block', capable: open }), false);
  assert.equal(verbApplies(verb(), KEY, { kind: null, capable: open }), false);
});

test('the scope partition: a selection verb steps aside while the key is being edited, an edit-scoped verb only applies then', () => {
  assert.equal(verbApplies(verb(), KEY, { kind: 'block', editingKey: KEY, capable: open }), false);
  assert.equal(verbApplies(verb(), KEY, { kind: 'block', editingKey: 'block:other', capable: open }), true);
  const scoped = verb({ editScoped: true });
  assert.equal(verbApplies(scoped, KEY, { kind: 'block', editingKey: KEY, capable: open }), true);
  assert.equal(verbApplies(scoped, KEY, { kind: 'block', editingKey: null, capable: open }), false);
});

test('the capability gate asks the verb\'s capability of the key, and refuses what the gate refuses', () => {
  const asked = [];
  const capable = (key, capability) => {
    asked.push([key, capability]);
    return capability !== 'arrange';
  };
  assert.equal(verbApplies(verb({ capability: 'arrange' }), KEY, { kind: 'block', capable }), false);
  assert.equal(verbApplies(verb({ capability: 'configure' }), KEY, { kind: 'block', capable }), true);
  assert.deepEqual(asked, [[KEY, 'arrange'], [KEY, 'configure']]);
  // The default gate refuses everything: a registry that never wired one
  // offers no verb.
  assert.equal(verbApplies(verb(), KEY, { kind: 'block' }), false);
});

test('a per-kind capability map asks the kind\'s capability', () => {
  const rename = verb({ kinds: ['container', 'entity'], capability: { container: 'configure', entity: 'content' } });
  const asked = [];
  const capable = (key, capability) => {
    asked.push(capability);
    return true;
  };
  assert.equal(verbApplies(rename, PARENT, { kind: 'container', capable }), true);
  assert.equal(verbApplies(rename, 'entity:root', { kind: 'entity', capable }), true);
  assert.deepEqual(asked, ['configure', 'content']);
  assert.equal(verbCapability(rename, 'entity'), 'content');
  assert.equal(verbCapability(verb(), 'block'), 'arrange');
});

test('a verb asked of the parent asks the projection parent, and never applies without one', () => {
  const insert = verb({ capability: 'insert', of: 'parent' });
  const asked = [];
  const capable = (key, capability) => {
    asked.push([key, capability]);
    return true;
  };
  assert.equal(verbApplies(insert, KEY, { kind: 'block', capable, parent: PARENT }), true);
  assert.deepEqual(asked, [[PARENT, 'insert']]);
  assert.equal(verbApplies(insert, KEY, { kind: 'block', capable, parent: null }), false, 'the root has no parent to insert into');
  const mapped = verb({ capability: 'insert', of: { block: 'parent', container: 'element' } });
  assert.equal(verbSubject(mapped, 'block'), 'parent');
  assert.equal(verbSubject(mapped, 'container'), 'element');
  assert.equal(verbSubject(insert, 'block'), 'parent');
  assert.equal(verbSubject(verb(), 'block'), 'element');
});

test('a function-valued subject answers per key: the parent of a block, the block itself where it is a door', () => {
  const DOOR = 'block:dddddddd-1111-2222-3333-444444444444';
  const insert = verb({
    capability: 'insert',
    of: (key, kind) => (kind === 'block' && key !== DOOR ? 'parent' : 'element'),
  });
  const asked = [];
  const capable = (key, capability) => {
    asked.push([key, capability]);
    return true;
  };
  assert.equal(verbApplies(insert, KEY, { kind: 'block', capable, parent: PARENT }), true);
  assert.equal(verbApplies(insert, DOOR, { kind: 'block', capable, parent: PARENT }), true);
  assert.equal(verbApplies(insert, PARENT, { kind: 'container', capable, parent: null }), true);
  assert.deepEqual(asked, [[PARENT, 'insert'], [DOOR, 'insert'], [PARENT, 'insert']]);
  assert.equal(verbSubject(insert, 'block', KEY), 'parent');
  assert.equal(verbSubject(insert, 'block', DOOR), 'element');
  assert.deepEqual(declaredCapability({ id: 'x', kinds: ['block'], capability: 'insert', of: insert.of }), { capability: 'insert', of: insert.of });
});

test('the capability gate runs before the verb\'s own refinement, which still has the last word', () => {
  let asked = 0;
  const refined = verb({
    applies: () => {
      asked += 1;
      return false;
    },
  });
  assert.equal(verbApplies(refined, KEY, { kind: 'block', capable: () => false }), false);
  assert.equal(asked, 0, 'a refused key never reaches the refinement');
  assert.equal(verbApplies(refined, KEY, { kind: 'block', capable: open }), false);
  assert.equal(asked, 1);
  const accepting = verb({ applies: (key) => key === KEY });
  assert.equal(verbApplies(accepting, KEY, { kind: 'block', capable: open }), true);
  assert.equal(verbApplies(accepting, 'block:other', { kind: 'block', capable: open }), false);
});

test('registration refuses a verb without a capability, naming the five', () => {
  const spec = (overrides = {}) => ({ id: 'my_module.thing', kinds: ['block', 'container'], ...overrides });
  assert.throws(() => declaredCapability(spec()), /content, configure, style, arrange, insert/);
  assert.throws(() => declaredCapability(spec({ capability: 'move' })), /my_module\.thing must declare the capability/);
  assert.throws(() => declaredCapability(spec({ capability: { block: 'arrange' } })), /covering block, container/, 'a map must cover every kind');
  assert.throws(() => declaredCapability(spec({ capability: 'arrange', of: 'sibling' })), /invalid subject/);
  assert.throws(() => declaredCapability(spec({ capability: 'arrange', of: { block: 'parent' } })), /invalid subject/);
  assert.deepEqual(declaredCapability(spec({ capability: 'arrange' })), { capability: 'arrange', of: 'element' });
  assert.deepEqual(
    declaredCapability(spec({ capability: { block: 'arrange', container: 'configure' }, of: { block: 'parent', container: 'element' } })),
    { capability: { block: 'arrange', container: 'configure' }, of: { block: 'parent', container: 'element' } },
  );
});
