/**
 * Self-asserting tests for the doorway registry (portal-actions.js): an
 * action applies and runs on a showing, its label may be a function of the
 * showing, the applying actions bind to a door's uuid as menu items, and
 * registration is validated and reversible.
 *
 * Run with `node --test tests/js/portal-actions.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  portalActionItems,
  portalActionsFor,
  registerPortalAction,
} from '../../js/edit_mode/components/portal/portal-actions.js';
import { portalShowing } from '../../js/edit_mode/components/portal/portal-model.js';

const DOOR = '11111111-5107-4000-8000-444444444444';

/**
 * The spine's showing as the annotator delivers it on a join.
 */
const showing = (overrides = {}) => ({
  shows: 'default',
  document: { entity_type: 'blueprint', id: '1', label: 'Header' },
  blueprint: 'header',
  portal: { entity_type: 'blueprint', entity_id: '1', label: 'Header', url: '/blueprint/1' },
  claimable: true,
  ...overrides,
});

test('an action applies and runs on a showing, told the door it was made on', () => {
  const runs = [];
  const unregister = registerPortalAction({
    id: 'test.claim',
    label: 'Detach into this slot',
    applies: (fact) => fact.claimable === true,
    run: (fact, uuid) => runs.push([fact.blueprint, uuid]),
  });
  const claimable = showing();
  const applying = portalActionsFor(claimable);
  assert.equal(applying.length, 1);
  assert.equal(applying[0].id, 'test.claim');
  assert.equal(applying[0].label, 'Detach into this slot');
  applying[0].run(claimable, DOOR);
  assert.deepEqual(runs, [['header', DOOR]]);
  // The spine refused the claim (a linked placement, the frame's own canvas,
  // a door the actor may not fill): nothing applies.
  assert.deepEqual(portalActionsFor(showing({ claimable: false })), []);
  // No showing (a plugin block that is no join): nothing applies.
  assert.deepEqual(portalActionsFor(null), []);
  unregister();
  assert.deepEqual(portalActionsFor(claimable), []);
});

test('a label function names the showing', () => {
  const unregister = registerPortalAction({
    id: 'test.named',
    label: (fact) => `Claim '${fact.document.label}'`,
    icon: '<svg/>',
    applies: () => true,
    run: () => {},
  });
  const [action] = portalActionsFor(showing());
  assert.equal(action.label, "Claim 'Header'");
  assert.equal(action.icon, '<svg/>');
  unregister();
});

test('the applying actions bind to one door as menu items, in registration order', () => {
  const runs = [];
  const first = registerPortalAction({
    id: 'test.first',
    label: 'First',
    icon: '<svg id="first"/>',
    applies: () => true,
    run: (fact, uuid) => runs.push(['first', fact.shows, uuid]),
  });
  const second = registerPortalAction({
    id: 'test.second',
    label: (fact) => `Second on ${fact.document.label}`,
    applies: (fact) => fact.claimable,
    run: (fact, uuid) => runs.push(['second', fact.shows, uuid]),
  });
  const fact = showing();
  const items = portalActionItems(fact, DOOR);
  assert.deepEqual(
    items.map(({ label, icon }) => ({ label, icon })),
    [
      { label: 'First', icon: '<svg id="first"/>' },
      { label: 'Second on Header', icon: null },
    ],
  );
  items.forEach((item) => item.run());
  assert.deepEqual(runs, [['first', 'default', DOOR], ['second', 'default', DOOR]]);
  // Only the applying ones.
  assert.deepEqual(portalActionItems(showing({ claimable: false }), DOOR).map((item) => item.label), ['First']);
  assert.deepEqual(portalActionItems(null, DOOR), []);
  first();
  second();
});

test('the chip and the row hand the registry one showing: the stamps read back apply like the annotation', () => {
  const runs = [];
  const unregister = registerPortalAction({
    id: 'test.claim',
    label: 'Detach into this slot',
    applies: (fact) => fact.claimable === true,
    run: (fact, uuid) => runs.push([fact.shows, fact.blueprint, uuid]),
  });
  // The chip's showing, from a claimable door's stamps.
  const fromStamps = portalShowing({
    url: '/blueprint/1',
    label: 'Header',
    type: 'Blueprint',
    claim: { slot: 'header', blueprint: 'header' },
  });
  assert.equal(portalActionsFor(fromStamps).length, 1);
  portalActionsFor(fromStamps)[0].run(fromStamps, DOOR);
  // The row's showing, from the annotation.
  const fromAnnotation = showing();
  portalActionsFor(fromAnnotation)[0].run(fromAnnotation, DOOR);
  assert.deepEqual(runs, [['default', 'header', DOOR], ['default', 'header', DOOR]]);
  // A url-only portal (no claim stamped) applies to nothing.
  assert.deepEqual(portalActionsFor(portalShowing({ url: '/blueprint/6', label: 'Footer', type: 'Blueprint', claim: null })), []);
  unregister();
});

test('re-registering an id replaces the action in place; unregister removes only its own', () => {
  const first = registerPortalAction({ id: 'test.same', label: 'One', applies: () => true, run: () => {} });
  const second = registerPortalAction({ id: 'test.same', label: 'Two', applies: () => true, run: () => {} });
  assert.deepEqual(portalActionsFor(showing()).map((action) => action.label), ['Two']);
  // The first registration's unregister is stale: the id is the second's.
  first();
  assert.deepEqual(portalActionsFor(showing()).map((action) => action.label), ['Two']);
  second();
  assert.deepEqual(portalActionsFor(showing()), []);
});

test('registration validates the contract', () => {
  assert.throws(() => registerPortalAction(null), /string id/);
  assert.throws(() => registerPortalAction({ id: '', applies: () => true, run: () => {} }), /string id/);
  assert.throws(() => registerPortalAction({ id: 'test.bad', run: () => {} }), /applies\(showing\) and run\(showing, uuid\)/);
  assert.throws(() => registerPortalAction({ id: 'test.bad', applies: () => true }), /applies\(showing\) and run\(showing, uuid\)/);
});
