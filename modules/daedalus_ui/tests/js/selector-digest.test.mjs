/**
 * The selector digester's client twin (selector-digest.js) over the
 * shared battery (tests/js/selector-digest-cases.mjs, the record the PHP
 * side reads too): the nesting sugar, the peel by name, the lists, the
 * at-rule preludes, every refusal with its code, and the reach. PHP is
 * authoritative (SelectorDigestTest).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  BAD_SELECTOR,
  OWNER,
  SELECTOR_LIST,
  UNKNOWN_WRAPPER,
  digest,
  reach,
} from '../../js/edit_mode/materials/selector-digest.js';
import { DIGESTS, REACHES, TABLES } from './selector-digest-cases.mjs';

test('the client twin digests every battery case as recorded', () => {
  assert.ok(DIGESTS.length > 0, 'the battery carries digest cases');
  DIGESTS.forEach(({ name, text, table, answer }) => {
    assert.deepEqual(digest(text, TABLES[table ?? 'default']), answer, name);
  });
});

test('the client twin reaches every battery case as recorded', () => {
  assert.ok(REACHES.length > 0, 'the battery carries reach cases');
  REACHES.forEach(({ name, selector, table, answer }) => {
    assert.equal(reach(selector, TABLES[table ?? 'default']), answer, name);
  });
});

test('the refusal codes are the dialect\'s three, and every refusal carries one', () => {
  const codes = new Set(DIGESTS.filter(({ answer }) => answer.refusal).map(({ answer }) => answer.refusal.code));
  assert.deepEqual([...codes].sort(), [BAD_SELECTOR, SELECTOR_LIST, UNKNOWN_WRAPPER].sort());
  DIGESTS.forEach(({ name, answer }) => {
    if (answer.refusal) {
      assert.equal(answer.selector, null, name);
      assert.equal(answer.condition, null, name);
      assert.equal(answer.folded, false, name);
      assert.ok(answer.refusal.message.length && answer.refusal.hint.length, name);
    }
  });
});

test('a fold is reported only when a list was folded into :is()', () => {
  DIGESTS.forEach(({ name, answer }) => {
    if (answer.folded) {
      assert.ok(answer.selector.includes(':is('), name);
    }
  });
  assert.ok(DIGESTS.some(({ answer }) => answer.folded), 'the battery carries folds');
  assert.ok(DIGESTS.some(({ answer }) => !answer.folded && answer.selector?.includes(':is(')), 'the battery carries a typed :is()');
});

test('the owner itself is the empty selector, and a non-string digests as nothing typed', () => {
  assert.equal(OWNER, '');
  assert.deepEqual(digest(null, TABLES.default), { selector: '', condition: null, refusal: null, folded: false });
  assert.deepEqual(digest('a:hover'), { selector: 'a', condition: ':hover', refusal: null, folded: false }, 'with no roster a posable state peels as a literal clause');
  assert.deepEqual(digest('a:visited'), { selector: 'a:visited', condition: null, refusal: null, folded: false }, 'a state no stamp stands in for stays a qualifier');
  assert.equal(reach(null), null);
});
