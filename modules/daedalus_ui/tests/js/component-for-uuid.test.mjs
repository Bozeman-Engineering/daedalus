/**
 * Self-asserting tests for the mirror's uuid lookup (componentForUuid).
 *
 * The lookup walks the delivered blueprint's lean node tree (`layout`):
 * kind-discriminated container/region/block nodes with ONE child axis at
 * every depth. Any node may match by uuid (a container's uuid is the
 * element's uuid at every surface — both halves of the selectable
 * vocabulary resolve here), and region nodes are transparent grouping the
 * walk descends through without ever returning.
 *
 * Run with `node --test tests/js/component-for-uuid.test.mjs` from the
 * module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { componentForUuid } from '../../js/edit_mode/blueprint/component-for-uuid.js';

const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const NESTED = 'bbbbbbbb-1111-2222-3333-444444444444';
const BLOCK = 'cccccccc-1111-2222-3333-444444444444';
const REGION_BLOCK = 'dddddddd-1111-2222-3333-444444444444';

const blueprint = {
  bundle: 'page',
  fields: {},
  layout: [
    {
      kind: 'container',
      uuid: SECTION,
      layout_id: 'daedalus_stylish_container',
      children: [
        {
          kind: 'container',
          uuid: NESTED,
          layout_id: 'daedalus_stylish_container',
          label: 'Inner',
          children: [
            { kind: 'block', uuid: BLOCK, block_type: 'text', block_fields: {} },
          ],
        },
      ],
    },
    {
      kind: 'container',
      layout_id: 'layout_twocol',
      children: [
        { kind: 'region', region: 'first', children: [] },
        {
          kind: 'region',
          region: 'second',
          children: [
            { kind: 'block', uuid: REGION_BLOCK, plugin_id: 'system_branding_block' },
          ],
        },
      ],
    },
  ],
};

test('a block resolves at any container depth', () => {
  const node = componentForUuid(blueprint, BLOCK);
  assert.equal(node.kind, 'block');
  assert.equal(node.block_type, 'text');
});

test('a container resolves by its own uuid — one identity at every surface', () => {
  const node = componentForUuid(blueprint, NESTED);
  assert.equal(node.kind, 'container');
  assert.equal(node.label, 'Inner');
});

test('region nodes are walked through, never returned', () => {
  const node = componentForUuid(blueprint, REGION_BLOCK);
  assert.equal(node.kind, 'block');
  assert.equal(node.plugin_id, 'system_branding_block');
});

test('an unknown uuid, an empty tree, and a missing blueprint answer null', () => {
  assert.equal(componentForUuid(blueprint, '00000000-0000-0000-0000-000000000000'), null);
  assert.equal(componentForUuid({ layout: [] }, BLOCK), null);
  assert.equal(componentForUuid(null, BLOCK), null);
});
