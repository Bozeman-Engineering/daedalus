/**
 * Self-asserting tests for the skin card's pure model
 * (skin-card-model.js): the read gate, the worn skin, the member roster
 * with each skin's standing, the copy's source roster grouped by design
 * system, the gesture roster over the read and the administer gate,
 * what a restore restores, and the copy's label.
 *
 * Run directly (`node tests/js/skin-card-model.test.mjs`) or with the
 * rest of the suite (`node --test tests/js/*.test.mjs`). Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import {
  copyLabel,
  copySources,
  restoreScope,
  skinCardGestures,
  skinCardReadable,
  skinCardRows,
  wornSkin,
} from '../../js/src/skin-card-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// A member entry as the skins route lists it.
const member = (machine_name, label, extra = {}) => ({
  machine_name,
  label,
  published: true,
  provider: null,
  site_layer: false,
  locked: false,
  refusal: null,
  ...extra,
});

// A source entry as the skins route lists every skin on the site.
const source = (machine_name, label, design_system, extra = {}) => ({
  machine_name,
  label,
  design_system,
  design_system_label:
    design_system === null ? null : (extra.design_system_label ?? `${label} system`),
  provider: null,
  ...extra,
});

// Every skin on the site by label: aurora's four members, a global skin
// (carbon), and two other systems' shipped skins.
const SOURCES = [
  source('aurora', 'Aurora', 'aurora', { provider: 'aurora_pkg', design_system_label: 'Aurora' }),
  source('bare', 'Bare', 'aurora', { design_system_label: 'Aurora' }),
  source('carbon', 'Carbon', null, { provider: 'daedalus_stylish_carbon_skin' }),
  source('dusk', 'Dusk', 'aurora', { design_system_label: 'Aurora' }),
  source('night', 'Night', 'aurora', { design_system_label: 'Aurora' }),
  source('olivero', 'Olivero', 'olivero', { provider: 'daedalus_stylish_olivero_skin', design_system_label: 'Olivero' }),
  source('starter', 'Starter', 'starter', { provider: 'daedalus_stylish_starter', design_system_label: 'Starter' }),
];

// The aurora system on a consuming site: the package's skin worn (with a
// site layer over it), a site-made copy, an unpublished member, and a
// member the contract outgrew.
const READ = {
  sources: SOURCES,
  design_system: 'aurora',
  worn: 'aurora',
  choice: null,
  default: 'aurora',
  skins: [
    member('aurora', 'Aurora', { provider: 'aurora_pkg', site_layer: true, locked: true }),
    member('bare', 'Bare', {
      refusal: {
        path: 'id',
        code: 'STYLE_SKIN_NONCONFORMING',
        message: 'lacks',
        hint: '',
        context: { lacks: { space: ['gutter'] } },
      },
    }),
    member('dusk', 'Dusk'),
    member('night', 'Night', {
      published: false,
      refusal: { path: 'id', code: 'STYLE_SKIN_UNPUBLISHED', message: 'unpublished', hint: '' },
    }),
  ],
};
// No active design system: nothing worn, no members, and still every
// skin on the site as a source (the read serves them in both branches).
const NONE = {
  design_system: null,
  worn: null,
  choice: null,
  default: null,
  skins: [],
  sources: SOURCES,
};
// A site's own unit just created at /admin/appearance/design-systems and
// activated: no package, no default, no members; the first-skin door.
const FRESH = {
  design_system: 'mine',
  worn: null,
  choice: null,
  default: null,
  skins: [],
  sources: SOURCES.filter((entry) => entry.design_system !== 'aurora'),
};
const ADMIN = { canEditDefaults: true, canAdminister: true };
const EDITOR = { canEditDefaults: true, canAdminister: false };

test('skinCardReadable: any grade that touches the skin reads the card', () => {
  assert.equal(skinCardReadable({}, {}), false);
  assert.equal(skinCardReadable(undefined, undefined), false);
  assert.equal(skinCardReadable({ canCreate: true }, {}), true);
  assert.equal(skinCardReadable({ canUpdate: true }, {}), true);
  assert.equal(skinCardReadable({ canDelete: true }, {}), true);
  assert.equal(skinCardReadable({ canDeleteInUse: true }, {}), true);
  assert.equal(skinCardReadable({}, { canEditDefaults: true }), true);
  assert.equal(skinCardReadable({}, { canAdminister: true }), true);
});

test('wornSkin: the worn member entry, null when nothing is worn or it is not there', () => {
  assert.equal(wornSkin(READ).machine_name, 'aurora');
  assert.equal(wornSkin(NONE), null);
  assert.equal(wornSkin(null), null);
  // A default_skin naming a skin the skins lane has not landed yet.
  assert.equal(wornSkin({ ...READ, worn: 'ghost' }), null);
});

test('skinCardRows: every member in read order with its standing', () => {
  assert.deepEqual(skinCardRows(READ), [
    {
      reference: 'aurora',
      label: 'Aurora',
      worn: true,
      isDefault: true,
      shipped: true,
      provider: 'aurora_pkg',
      published: true,
      choosable: false,
      reason: null,
      lacks: null,
    },
    {
      reference: 'bare',
      label: 'Bare',
      worn: false,
      isDefault: false,
      shipped: false,
      provider: null,
      published: true,
      choosable: false,
      reason: 'nonconforming',
      lacks: { space: ['gutter'] },
    },
    {
      reference: 'dusk',
      label: 'Dusk',
      worn: false,
      isDefault: false,
      shipped: false,
      provider: null,
      published: true,
      choosable: true,
      reason: null,
      lacks: null,
    },
    {
      reference: 'night',
      label: 'Night',
      worn: false,
      isDefault: false,
      shipped: false,
      provider: null,
      published: false,
      choosable: false,
      reason: 'unpublished',
      lacks: null,
    },
  ]);
  assert.deepEqual(skinCardRows(NONE), []);
  assert.deepEqual(skinCardRows(null), []);
  // A refusal code the model does not know still reads as refused.
  const [row] = skinCardRows({ ...NONE, skins: [member('x', 'X', { refusal: { code: 'STYLE_NEW' } })] });
  assert.equal(row.reason, 'refused');
  assert.equal(row.choosable, false);
  // A blank label falls back to the machine name.
  assert.equal(skinCardRows({ ...NONE, skins: [member('raw', '')] })[0].label, 'raw');
});

test('copySources: every skin on the site grouped by system, the worn skin first', () => {
  // The worn skin's group (aurora) leads with the worn skin at its head;
  // the other groups follow in read order, the global skins as their own
  // group with no system.
  assert.deepEqual(copySources(READ), [
    {
      system: 'aurora',
      label: 'Aurora',
      skins: [
        { reference: 'aurora', label: 'Aurora', provider: 'aurora_pkg', worn: true },
        { reference: 'bare', label: 'Bare', provider: null, worn: false },
        { reference: 'dusk', label: 'Dusk', provider: null, worn: false },
        { reference: 'night', label: 'Night', provider: null, worn: false },
      ],
    },
    {
      system: null,
      label: null,
      skins: [
        { reference: 'carbon', label: 'Carbon', provider: 'daedalus_stylish_carbon_skin', worn: false },
      ],
    },
    {
      system: 'olivero',
      label: 'Olivero',
      skins: [
        { reference: 'olivero', label: 'Olivero', provider: 'daedalus_stylish_olivero_skin', worn: false },
      ],
    },
    {
      system: 'starter',
      label: 'Starter',
      skins: [
        { reference: 'starter', label: 'Starter', provider: 'daedalus_stylish_starter', worn: false },
      ],
    },
  ]);
  // A worn skin that is not first by label is hoisted to the head of its
  // group, the rest of the group keeping read order.
  const dusk = copySources({ ...READ, worn: 'dusk' });
  assert.deepEqual(
    dusk[0].skins.map((skin) => skin.reference),
    ['dusk', 'aurora', 'bare', 'night'],
  );
  assert.equal(dusk[0].skins[0].worn, true);
  assert.equal(dusk[0].skins[1].worn, false);
  // A global skin worn (through default_skin, unjudged) hoists the global
  // group first; the other groups keep read order.
  const carbon = copySources({ ...READ, worn: 'carbon' });
  assert.deepEqual(
    carbon.map((group) => group.system),
    [null, 'aurora', 'olivero', 'starter'],
  );
  assert.equal(carbon[0].skins[0].worn, true);
  // Nothing worn: read order, nothing flagged.
  const fresh = copySources(FRESH);
  assert.deepEqual(
    fresh.map((group) => group.system),
    [null, 'olivero', 'starter'],
  );
  assert.equal(fresh.flatMap((group) => group.skins).some((skin) => skin.worn), false);
  assert.deepEqual(copySources(NONE).map((group) => group.system), ['aurora', null, 'olivero', 'starter']);
  // No read, or a read with no skins on the site: no sources.
  assert.deepEqual(copySources(null), []);
  assert.deepEqual(copySources({ ...FRESH, sources: [] }), []);
  assert.deepEqual(copySources({ ...FRESH, sources: undefined }), []);
  // A blank label falls back to the machine name; a unit that has not
  // landed reads as its id.
  const [raw] = copySources({
    ...FRESH,
    sources: [source('raw', '', 'ghost', { design_system_label: 'ghost' })],
  });
  assert.equal(raw.label, 'ghost');
  assert.equal(raw.skins[0].label, 'raw');
});

test('skinCardGestures: the administer grade over the read', () => {
  assert.deepEqual(skinCardGestures(READ, ADMIN), {
    choose: true,
    copy: true,
    revisions: true,
    revert: true,
  });
  // Without the administer grade the card reads and offers nothing.
  assert.deepEqual(skinCardGestures(READ, EDITOR), {
    choose: false,
    copy: false,
    revisions: false,
    revert: false,
  });
  assert.deepEqual(skinCardGestures(READ, {}), {
    choose: false,
    copy: false,
    revisions: false,
    revert: false,
  });
  // No active design system: nothing to restore or revert, nothing to
  // choose, and no copy either, since a copy lands in the active system
  // (the sources are served, but there is nowhere to land).
  assert.deepEqual(skinCardGestures(NONE, ADMIN), {
    choose: false,
    copy: false,
    revisions: false,
    revert: false,
  });
  assert.deepEqual(skinCardGestures(null, ADMIN), {
    choose: false,
    copy: false,
    revisions: false,
    revert: false,
  });
  // A site's own unit with no skin yet: copy is the way in (an active
  // system and a source to copy), nothing else applies.
  assert.deepEqual(skinCardGestures(FRESH, ADMIN), {
    choose: false,
    copy: true,
    revisions: false,
    revert: false,
  });
  assert.equal(skinCardGestures(FRESH, EDITOR).copy, false);
  // An active system on a site with no skin at all: nothing to copy.
  assert.equal(skinCardGestures({ ...FRESH, sources: [] }, ADMIN).copy, false);
  // Only the worn skin and refused members: no choose.
  const alone = { ...READ, skins: READ.skins.filter((skin) => skin.machine_name !== 'dusk') };
  assert.equal(skinCardGestures(alone, ADMIN).choose, false);
  // A worn skin with no site layer has nothing to revert; the site's own
  // skin (no provider) has no package to reveal even with a site layer.
  const clean = { ...READ, skins: [member('aurora', 'Aurora', { provider: 'aurora_pkg', locked: true })] };
  assert.equal(skinCardGestures(clean, ADMIN).revert, false);
  const own = { ...READ, worn: 'dusk', skins: [member('dusk', 'Dusk', { site_layer: true })] };
  assert.equal(skinCardGestures(own, ADMIN).revert, false);
  assert.equal(skinCardGestures(own, ADMIN).copy, true);
  assert.equal(skinCardGestures(own, ADMIN).revisions, true);
});

test('restoreScope: the site layer while the package layer is the disk\'s', () => {
  assert.equal(restoreScope(true), 'site');
  assert.equal(restoreScope(false), 'whole');
});

test('copyLabel: the server\'s derived default for the picked source as the draft', () => {
  assert.equal(copyLabel({ label: 'Aurora' }), 'Aurora (copy)');
  // The picked source is a copySources() entry: the worn skin first, or
  // any other skin on the site.
  assert.equal(copyLabel(copySources(READ)[0].skins[0]), 'Aurora (copy)');
  assert.equal(copyLabel(copySources(FRESH)[2].skins[0]), 'Starter (copy)');
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
