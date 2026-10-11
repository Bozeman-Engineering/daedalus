/**
 * Self-asserting tests for the drop's composer (js/src/model/drop.js):
 * the peek's batch for a row is the batch a click-to-place of that row at
 * the root's end sends, for each of the three row kinds; a block at the
 * root rides a new section; the anchor's location grammar.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const {
  anchorLocation,
  blueprintOperations,
  composeDrop,
  peekRequest,
  resolveTarget,
  rootEndTarget,
} = await import('../../js/src/model/drop.js');

const ROOT = 'root-uuid';

/**
 * The standard builders' shapes over a counting uuid generator, so two
 * compositions from fresh deps mint the same uuids.
 */
const deps = () => {
  let n = 0;
  const generateUuid = () => `uuid-${(n += 1)}`;
  const builders = {
    newBlock: (target, data) => {
      const operation = {
        op: 'add_element',
        uuid: generateUuid(),
        populate: 'sample',
        location: target.location,
        plugin_id: data.pluginId,
      };
      if (!target.newSection) {
        return [operation];
      }
      const section = generateUuid();
      return [
        { op: 'add_section', uuid: section, location: target.location },
        { ...operation, location: { anchor: section, placement: 'inside' } },
      ];
    },
    newSection: (target) => [
      { op: 'add_section', uuid: generateUuid(), location: target.location },
    ],
  };
  return { builders, generateUuid };
};

const isRootFlowLocation = (location) =>
  location.anchor === ROOT && location.placement === 'inside';

const sources = {
  block: { source: 'new_block', data: { pluginId: 'field_block:node:page:title' }, placingSection: false },
  container: { source: 'new_section', data: {}, placingSection: true },
  blueprint: { source: 'new_blueprint_section', data: { blueprintId: 'card' }, placingSection: true },
  linked: { source: 'new_blueprint_block', data: { blueprintId: 'card', linked: true }, placingSection: false },
};

/**
 * The peek's request for a row: the drop at the end of the page.
 */
const peekOf = (source) =>
  peekRequest(deps(), source, resolveTarget(isRootFlowLocation, source, rootEndTarget(ROOT)));

/**
 * The click-to-place's drop for a row at the root anchor (the empty
 * canvas's anchor): the location the anchor resolves to, then the same
 * gates' target, then the same composer.
 */
const clickOf = (source) => {
  const location = anchorLocation(`entity:${ROOT}`, source.placingSection, () => null);
  return composeDrop(deps(), source, resolveTarget(isRootFlowLocation, source, { location }));
};

test('the peek\'s batch for a row equals the batch a click-to-place at the root\'s end sends', () => {
  Object.entries(sources).forEach(([kind, source]) => {
    const peek = peekOf(source);
    const click = clickOf(source);
    assert.deepEqual(peek.operations, click.operations, kind);
    assert.equal(peek.show, click.placedKey, kind);
  });
});

test('a block at the end of the page rides a new section, and the peek shows the block', () => {
  const peek = peekOf(sources.block);
  assert.equal(peek.operations.length, 2);
  assert.equal(peek.operations[0].op, 'add_section');
  assert.deepEqual(peek.operations[0].location, { anchor: ROOT, placement: 'inside' });
  assert.equal(peek.operations[1].op, 'add_element');
  assert.equal(peek.operations[1].plugin_id, 'field_block:node:page:title');
  assert.deepEqual(peek.operations[1].location, { anchor: peek.operations[0].uuid, placement: 'inside' });
  assert.equal(peek.show, `block:${peek.operations[1].uuid}`);
});

test('a blueprint at the end of the page is one place_blueprint naming its root, and the Container one add_section', () => {
  const blueprint = peekOf(sources.blueprint);
  assert.deepEqual(blueprint.operations, [
    { op: 'place_blueprint', blueprint: 'card', uuid: 'uuid-1', location: { anchor: ROOT, placement: 'inside' } },
  ]);
  assert.equal(blueprint.show, 'container:uuid-1');
  const container = peekOf(sources.container);
  assert.deepEqual(container.operations, [
    { op: 'add_section', uuid: 'uuid-1', location: { anchor: ROOT, placement: 'inside' } },
  ]);
  assert.equal(container.show, 'container:uuid-1');
});

test('a nested target composes without a new section, and blueprintOperations spells the op', () => {
  const target = resolveTarget(isRootFlowLocation, sources.block, { location: { anchor: 'c', placement: 'inside' } });
  assert.equal(target.newSection, undefined);
  const drop = composeDrop(deps(), sources.block, target);
  assert.equal(drop.operations.length, 1);
  assert.equal(drop.placedKey, 'block:uuid-1');
  let n = 0;
  assert.deepEqual(
    blueprintOperations(() => `u${(n += 1)}`, { location: { anchor: 'c', placement: 'after' } }, { blueprintId: 'band' }),
    [{ op: 'place_blueprint', blueprint: 'band', uuid: 'u1', location: { anchor: 'c', placement: 'after' } }],
  );
});

test('a linked blueprint is one place_blueprint in linked mode naming its block, riding a new section at the root', () => {
  // At the end of the page: a block drop, so a section is minted first
  // and the linked node lands inside it; the peek shows the node.
  const peek = peekOf(sources.linked);
  assert.deepEqual(peek.operations, [
    { op: 'add_section', uuid: 'uuid-1', location: { anchor: ROOT, placement: 'inside' } },
    { op: 'place_blueprint', blueprint: 'card', mode: 'linked', uuid: 'uuid-2', location: { anchor: 'uuid-1', placement: 'inside' } },
  ]);
  assert.equal(peek.show, 'block:uuid-2');
  // Inside a container: the one op, where a block lands.
  const target = resolveTarget(isRootFlowLocation, sources.linked, { location: { anchor: 'c', placement: 'inside' } });
  assert.equal(target.newSection, undefined);
  const drop = composeDrop(deps(), sources.linked, target);
  assert.deepEqual(drop.operations, [
    { op: 'place_blueprint', blueprint: 'card', mode: 'linked', uuid: 'uuid-1', location: { anchor: 'c', placement: 'inside' } },
  ]);
  assert.equal(drop.placedKey, 'block:uuid-1');
  // A detached drop spells no mode: the server's default.
  assert.equal('mode' in composeDrop(deps(), sources.blueprint, { location: { anchor: 'c', placement: 'after' } }).operations[0], false);
});

test('the anchor\'s location grammar', () => {
  const enclosing = (key) => (key === 'block:b' ? 'section-of-b' : null);
  // The root takes blocks and sections alike inside itself.
  assert.deepEqual(anchorLocation('entity:r', false, enclosing), { anchor: 'r', placement: 'inside' });
  assert.deepEqual(anchorLocation('entity:r', true, enclosing), { anchor: 'r', placement: 'inside' });
  assert.deepEqual(rootEndTarget('r'), { location: { anchor: 'r', placement: 'inside' } });
  // Blocks: after a block, inside a container.
  assert.deepEqual(anchorLocation('block:b', false, enclosing), { anchor: 'b', placement: 'after' });
  assert.deepEqual(anchorLocation('container:c', false, enclosing), { anchor: 'c', placement: 'inside' });
  // Sections: after a container, after the container a block sits in.
  assert.deepEqual(anchorLocation('container:c', true, enclosing), { anchor: 'c', placement: 'after' });
  assert.deepEqual(anchorLocation('block:b', true, enclosing), { anchor: 'section-of-b', placement: 'after' });
  // A slot door: blocks and sections alike land inside the door (the
  // server minting the page's slot section), never beside it.
  const door = (key) => key === 'block:d';
  assert.deepEqual(anchorLocation('block:d', false, enclosing, door), { anchor: 'd', placement: 'inside' });
  assert.deepEqual(anchorLocation('block:d', true, enclosing, door), { anchor: 'd', placement: 'inside' });
  assert.deepEqual(anchorLocation('block:b', false, enclosing, door), { anchor: 'b', placement: 'after' }, 'an ordinary block is unchanged by the predicate');
  // Unresolvable anchors.
  assert.equal(anchorLocation('block:gone', true, enclosing), null);
  assert.equal(anchorLocation('field:f', false, enclosing), null);
  assert.equal(anchorLocation('field:f', true, enclosing), null);
});
