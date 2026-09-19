/**
 * Self-asserting tests for the Materials variants face's pure model
 * (over the store's records).
 *
 * Run directly (`node tests/js/library.test.mjs`) or via
 * PrinterEquivalenceTest inside the PHPUnit suite. Locks the filter
 * vocabularies, the row roster with its honesty flags, the lifecycle
 * card's gesture roster over the per-open management read, the ownership
 * face over the stencil records, and the this-page wearer lookup behind
 * the card's "Edit styles". Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  applyDoorway,
  cardGestures,
  libraryFilters,
  libraryRows,
  pageWearer,
  stencilLabel,
  stencilTargets,
  variantOwnership,
} from '../../js/src/library-model.js';
import { EMPTY_MATERIALS, roster, stencilRecord, variantRecord } from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The store's variant records, roster order (weight, then label).
const LIBRARY = [
  variantRecord('cta', { label: 'CTA', scope: 'layout:daedalus_stylish_container', description: 'The call to action band.' }),
  variantRecord('row', { label: 'Columns', scope: 'kind:container', group: 'container_layout', origin: 'shipped', weight: 10 }),
  variantRecord('hero_band', { label: 'Hero band', scope: 'kind:container', group: 'width', weight: 20 }),
];
// The shipped row under the developer gate: the provider stamps it
// updatable (the lock is open), its origin is still shipped.
const OPEN_ROW = variantRecord('row', { label: 'Columns', scope: 'kind:container', group: 'container_layout', origin: 'shipped', updatable: true, weight: 10 });

test('libraryFilters: distinct sorted axes and scopes; group-less reads style', () => {
  assert.deepEqual(libraryFilters(LIBRARY), {
    axes: ['container_layout', 'style', 'width'],
    scopes: ['kind:container', 'layout:daedalus_stylish_container'],
  });
  assert.deepEqual(libraryFilters([{ id: 'x', scope: 'kind:block' }]), {
    axes: ['style'],
    scopes: ['kind:block'],
  });
  assert.deepEqual(libraryFilters(null), { axes: [], scopes: [] });
});

test('libraryRows: the flagged roster in roster order, counts never a list fact', () => {
  const rows = libraryRows(LIBRARY);
  assert.deepEqual(rows.map((row) => row.reference), ['cta', 'row', 'hero_band']);
  // Shipped marks the record's origin, never a disk state and not the
  // lock: a shipped-and-updatable record (the gate) still reads shipped.
  assert.deepEqual(rows.map((row) => row.shipped), [false, true, false]);
  assert.equal(libraryRows([OPEN_ROW])[0].shipped, true);
  assert.deepEqual(rows.map((row) => row.axis), ['style', 'container_layout', 'width']);
  assert.deepEqual(rows.map((row) => row.weight), [0, 10, 20]);
  assert.deepEqual(rows.map((row) => row.description), ['The call to action band.', '', '']);
  assert.deepEqual(Object.keys(rows[0]).sort(), ['axis', 'description', 'label', 'offered', 'reference', 'scope', 'shipped', 'weight']);
});

test('libraryRows: offered is the record\'s AI opt-out; absent reads as offered, the default', () => {
  const withheld = [
    variantRecord('quiet', { label: 'Quiet', description: 'UI only.', offered: false }),
    variantRecord('loud', { label: 'Loud' }),
    { id: 'bare', label: 'Bare', scope: 'kind:block' },
  ];
  const rows = libraryRows(withheld);
  assert.deepEqual(rows.map((row) => row.offered), [false, true, true]);
  // The description stays a row fact (search still finds it); the surfaces
  // hide it while the flag is off.
  assert.deepEqual(libraryRows(withheld, { search: 'ui only' }).map((row) => row.reference), ['quiet']);
});

test('libraryRows: search matches label, id or description, case-insensitive', () => {
  assert.deepEqual(libraryRows(LIBRARY, { search: 'hero' }).map((row) => row.reference), ['hero_band']);
  assert.deepEqual(libraryRows(LIBRARY, { search: 'CTA' }).map((row) => row.reference), ['cta']);
  assert.deepEqual(libraryRows(LIBRARY, { search: 'hero_band' }).map((row) => row.reference), ['hero_band']);
  assert.deepEqual(libraryRows(LIBRARY, { search: 'action band' }).map((row) => row.reference), ['cta']);
  assert.deepEqual(libraryRows(LIBRARY, { search: '  ' }).length, 3);
  assert.deepEqual(libraryRows(LIBRARY, { search: 'nothing' }), []);
});

test('libraryRows: axis and scope filters are exact; null is all', () => {
  assert.deepEqual(libraryRows(LIBRARY, { axis: 'container_layout' }).map((row) => row.reference), ['row']);
  assert.deepEqual(libraryRows(LIBRARY, { scope: 'kind:container' }).map((row) => row.reference), ['row', 'hero_band']);
  assert.deepEqual(libraryRows(LIBRARY, { axis: 'width', scope: 'kind:container' }).map((row) => row.reference), ['hero_band']);
  assert.deepEqual(libraryRows(LIBRARY, { axis: null, scope: null }).length, 3);
});

const ALL = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };
// The per-open management read (the usage route).
const WORN = { usages: 4, shipped_state: null, provider: null };
const UNWORN = { usages: 0, shipped_state: null, provider: null };

test('cardGestures: the editable roster follows the grants', () => {
  assert.deepEqual(cardGestures(LIBRARY[0], ALL, WORN, EMPTY_MATERIALS), {
    details: true,
    revisions: true,
    editStyles: true,
    duplicate: true,
    copy: false,
    revert: false,
    release: false,
    transfer: false,
    delete: true,
  });
  // No grants: a read-only card.
  assert.deepEqual(cardGestures(LIBRARY[0], {}, WORN, EMPTY_MATERIALS), {
    details: false,
    revisions: false,
    editStyles: false,
    duplicate: false,
    copy: false,
    revert: false,
    release: false,
    transfer: false,
    delete: false,
  });
});

test('cardGestures: the distro lock splits the roster; revert waits for the management read', () => {
  // Shipped unmodified: read-only, the fork in the editing gestures'
  // place; revert only when the state is actionable.
  assert.deepEqual(cardGestures(LIBRARY[1], ALL, { usages: 31, shipped_state: 'unmodified', provider: 'daedalus_stylish' }, EMPTY_MATERIALS), {
    details: false,
    revisions: false,
    editStyles: false,
    duplicate: false,
    copy: true,
    revert: false,
    release: false,
    transfer: false,
    delete: false,
  });
  assert.equal(cardGestures(LIBRARY[1], ALL, { usages: 1, shipped_state: 'modified', provider: 'daedalus_stylish' }, EMPTY_MATERIALS).revert, true);
  assert.equal(cardGestures(LIBRARY[1], ALL, { usages: 1, shipped_state: 'defaults_changed', provider: 'daedalus_stylish' }, EMPTY_MATERIALS).revert, true);
  assert.equal(cardGestures(LIBRARY[1], { canCreate: true }, { usages: 1, shipped_state: 'modified', provider: 'daedalus_stylish' }, EMPTY_MATERIALS).revert, false);
  // The read still in flight: the lock holds, revert waits.
  assert.equal(cardGestures(LIBRARY[1], ALL, null, EMPTY_MATERIALS).copy, true);
  assert.equal(cardGestures(LIBRARY[1], ALL, null, EMPTY_MATERIALS).revert, false);
});

test('cardGestures: under the developer gate a shipped variant carries both rosters', () => {
  // Shipped AND updatable (the record's flag, the lock open): the editing
  // gestures return, copy stays (still a shipped origin), revert follows
  // the management state as before.
  assert.deepEqual(cardGestures(OPEN_ROW, ALL, { usages: 31, shipped_state: 'modified', provider: 'daedalus_stylish' }, EMPTY_MATERIALS), {
    details: true,
    revisions: true,
    editStyles: true,
    duplicate: true,
    copy: true,
    revert: true,
    release: false,
    transfer: false,
    delete: true,
  });
  assert.equal(cardGestures(OPEN_ROW, ALL, { usages: 1, shipped_state: 'unmodified', provider: 'daedalus_stylish' }, EMPTY_MATERIALS).revert, false);
  // The grants still gate: no grants, no editing gestures.
  assert.equal(cardGestures(OPEN_ROW, {}, UNWORN, EMPTY_MATERIALS).editStyles, false);
  assert.equal(cardGestures(OPEN_ROW, { canDelete: true }, UNWORN, EMPTY_MATERIALS).delete, true);
  // Ownership gestures open with the lock: an owned shipped variant releases.
  assert.equal(cardGestures({ ...OPEN_ROW, owned_by: 'call_to_action' }, OWN, WORN, MATERIALS).release, true);
});

test('cardGestures: delete carries the two permission grades', () => {
  // A worn variant needs the in-use grade (delete runs the batch).
  assert.equal(cardGestures(LIBRARY[0], { canDelete: true }, WORN, EMPTY_MATERIALS).delete, false);
  assert.equal(cardGestures(LIBRARY[0], { canDeleteInUse: true }, WORN, EMPTY_MATERIALS).delete, true);
  // An unworn one deletes on either grade.
  assert.equal(cardGestures(LIBRARY[2], { canDelete: true }, UNWORN, EMPTY_MATERIALS).delete, true);
  assert.equal(cardGestures(LIBRARY[2], { canDeleteInUse: true }, UNWORN, EMPTY_MATERIALS).delete, true);
  assert.equal(cardGestures(LIBRARY[2], {}, UNWORN, EMPTY_MATERIALS).delete, false);
});

// The stencils as the store holds them: labels for the badge, updatable =
// content stencils (transfer targets); the "save stencils" grant rides the
// variant gates as canOwn.
const MATERIALS = roster(
  stencilRecord('call_to_action', { label: 'Call to action' }),
  stencilRecord('hero', { label: 'Hero' }),
  stencilRecord('promo', { label: 'Promo band', updatable: false }),
);
const OWN = { ...ALL, canOwn: true };

const OWNED = { ...LIBRARY[0], owned_by: 'call_to_action' };
// An orphan owns by an id the roster no longer holds.
const ORPHANED = { ...LIBRARY[0], owned_by: 'deleted_stencil' };

test('stencilLabel: the record\'s label, the id when the roster lacks it', () => {
  assert.equal(stencilLabel('call_to_action', MATERIALS), 'Call to action');
  assert.equal(stencilLabel('deleted_stencil', MATERIALS), 'deleted_stencil');
});

test('variantOwnership: the badge face (owner label, orphan flag, unowned null)', () => {
  assert.equal(variantOwnership(LIBRARY[0], MATERIALS), null);
  assert.deepEqual(variantOwnership(OWNED, MATERIALS), {
    owner: 'call_to_action',
    label: 'Call to action',
    orphaned: false,
  });
  // An orphaned owner has no name at all, never a stale label.
  assert.deepEqual(variantOwnership(ORPHANED, MATERIALS), {
    owner: null,
    label: null,
    orphaned: true,
  });
  // No stencils on the roster at all: every owner is an orphan.
  assert.equal(variantOwnership(OWNED, EMPTY_MATERIALS).orphaned, true);
});

test('stencilTargets: updatable stencils minus the owner, sorted by label', () => {
  // File-shipped stencils (updatable false) never target; the owner is out.
  assert.deepEqual(stencilTargets(OWNED, MATERIALS), [
    { id: 'hero', label: 'Hero' },
  ]);
  // Unowned: every content stencil targets (adopt-later by transfer).
  assert.deepEqual(stencilTargets(LIBRARY[0], MATERIALS), [
    { id: 'call_to_action', label: 'Call to action' },
    { id: 'hero', label: 'Hero' },
  ]);
  assert.deepEqual(stencilTargets(OWNED, EMPTY_MATERIALS), []);
});

test('cardGestures: the ownership gestures ride the stencil grant', () => {
  // Owned + the grant: release and transfer (a target exists).
  assert.equal(cardGestures(OWNED, OWN, WORN, MATERIALS).release, true);
  assert.equal(cardGestures(OWNED, OWN, WORN, MATERIALS).transfer, true);
  // Orphaned: release IS the cleanup gesture; transfer to a living
  // stencil is legitimate too.
  assert.equal(cardGestures(ORPHANED, OWN, WORN, MATERIALS).release, true);
  assert.equal(cardGestures(ORPHANED, OWN, WORN, MATERIALS).transfer, true);
  // Unowned: nothing to release; transfer doubles as assign (adopt-later).
  assert.equal(cardGestures(LIBRARY[0], OWN, WORN, MATERIALS).release, false);
  assert.equal(cardGestures(LIBRARY[0], OWN, WORN, MATERIALS).transfer, true);
  // No "save stencils" grant: neither gesture, whatever the variant
  // grants say.
  assert.equal(cardGestures(OWNED, ALL, WORN, MATERIALS).release, false);
  assert.equal(cardGestures(OWNED, ALL, WORN, MATERIALS).transfer, false);
  // Shipped variants: the disk lane owns their ownership key; the routes
  // refuse, so the card never offers.
  const shippedOwned = { ...LIBRARY[1], owned_by: 'call_to_action' };
  assert.equal(cardGestures(shippedOwned, OWN, WORN, MATERIALS).release, false);
  assert.equal(cardGestures(shippedOwned, OWN, WORN, MATERIALS).transfer, false);
  // Nowhere to go: the owner is the only content stencil.
  const oneTarget = roster(stencilRecord('call_to_action', { label: 'CTA' }));
  assert.equal(cardGestures(OWNED, OWN, WORN, oneTarget).transfer, false);
  assert.equal(cardGestures(LIBRARY[0], OWN, WORN, oneTarget).transfer, true);
});

const MAP = {
  'container:root': { variants: [{ variant: 'cta', fragment: '' }] },
  'container:root::.inner': { default: { gap: '1rem' } },
  'block:child': { variants: [{ variant: 'hero_band', fragment: 'block(text,1)' }] },
  'block:other': { default: { color: 'red' } },
};

test('pageWearer: the selection wins when it wears the variant', () => {
  assert.deepEqual(pageWearer(MAP, LIBRARY[0], 'container:root'), {
    key: 'container:root',
    reference: 'cta',
    fragment: '',
  });
  // A non-wearing selection falls through to the map walk.
  assert.deepEqual(pageWearer(MAP, LIBRARY[0], 'block:other'), {
    key: 'container:root',
    reference: 'cta',
    fragment: '',
  });
});

test('pageWearer: a fragment wearing answers when nothing wears the root', () => {
  assert.deepEqual(pageWearer(MAP, LIBRARY[2], null), {
    key: 'block:child',
    reference: 'hero_band',
    fragment: 'block(text,1)',
  });
  // A reference is the record id: a retired uuid spelling never wears.
  const uuidMap = { 'block:x': { variants: [{ variant: '33333333-1111-2222-3333-444444444444', fragment: '' }] } };
  assert.equal(pageWearer(uuidMap, LIBRARY[2], null), null);
});

test('pageWearer: a root wearing beats a fragment wearing', () => {
  const map = {
    'block:frag': { variants: [{ variant: 'cta', fragment: 'block(text,1)' }] },
    'container:root': { variants: [{ variant: 'cta', fragment: '' }] },
  };
  assert.equal(pageWearer(map, LIBRARY[0], null).key, 'container:root');
});

test('applyDoorway: no selection shuts the doorway first', () => {
  assert.equal(applyDoorway(LIBRARY[0], MAP, null, LIBRARY, null, MATERIALS), 'no-selection');
  assert.equal(applyDoorway(LIBRARY[0], MAP, '', LIBRARY, null, MATERIALS), 'no-selection');
});

test('applyDoorway: a wearing selection reads worn, fragments included', () => {
  // container:root wears cta.
  assert.equal(applyDoorway(LIBRARY[0], MAP, 'container:root', LIBRARY, null, MATERIALS), 'worn');
  // block:child wears hero_band as a fragment — still worn (the wearing
  // row is the gesture surface then).
  assert.equal(applyDoorway(LIBRARY[2], MAP, 'block:child', LIBRARY, null, MATERIALS), 'worn');
});

test('applyDoorway: offer membership answers fit', () => {
  // A non-wearing selection with the variant on offer: ready.
  assert.equal(applyDoorway(LIBRARY[1], MAP, 'container:root', [LIBRARY[1]], null, MATERIALS), 'ready');
  // Off the offer (scope gates, or an unstylable selection's empty
  // offer): unfit, never a dead ready button.
  assert.equal(applyDoorway(LIBRARY[1], MAP, 'container:root', [LIBRARY[0]], null, MATERIALS), 'unfit');
  assert.equal(applyDoorway(LIBRARY[1], MAP, 'container:root', [], null, MATERIALS), 'unfit');
  assert.equal(applyDoorway(LIBRARY[1], MAP, 'container:root', null, null, MATERIALS), 'unfit');
});

test('applyDoorway: ownership names its own unfit reasons', () => {
  const owned = { ...LIBRARY[1], owned_by: 'call_to_action' };
  const orphaned = { ...LIBRARY[1], owned_by: 'deleted_stencil' };
  // Off the offer outside the owner's placements: the ownership story.
  assert.equal(applyDoorway(owned, MAP, 'container:root', [], null, MATERIALS), 'unfit-owned');
  assert.equal(applyDoorway(owned, MAP, 'container:root', [], 'hero', MATERIALS), 'unfit-owned');
  // INSIDE the owner's placements the refusal is scope's, not ownership's.
  assert.equal(applyDoorway(owned, MAP, 'container:root', [], 'call_to_action', MATERIALS), 'unfit');
  // Orphaned: offers nowhere, whatever the provenance.
  assert.equal(applyDoorway(orphaned, MAP, 'container:root', [], 'call_to_action', MATERIALS), 'unfit-orphaned');
  // On the offer, ownership already passed: plain ready.
  assert.equal(applyDoorway(owned, MAP, 'container:root', [owned], 'call_to_action', MATERIALS), 'ready');
  // Unowned stays the vague-but-true plain unfit.
  assert.equal(applyDoorway(LIBRARY[1], MAP, 'container:root', [], 'call_to_action', MATERIALS), 'unfit');
});

test('pageWearer: no wearer on this page is null; part keys never match', () => {
  assert.equal(pageWearer(MAP, LIBRARY[1], 'container:root'), null);
  assert.equal(pageWearer({}, LIBRARY[0], null), null);
  assert.equal(pageWearer(MAP, null, 'container:root'), null);
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
