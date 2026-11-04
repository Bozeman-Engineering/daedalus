/**
 * Self-asserting tests for the control-character gate (js/src/control-chars.js).
 *
 * The two value gates (printer.js, validate-value.js) read this helper in
 * place of a control-range regex; this pins the range and the allowance.
 */

import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { hasControlChar } from '../../js/src/control-chars.js';

test('every C0 control character is forbidden by default', () => {
  for (let code = 0; code < 0x20; code += 1) {
    assert.equal(
      hasControlChar(`1rem${String.fromCharCode(code)}`),
      true,
      `U+${code.toString(16).padStart(4, '0')} is a control character`,
    );
  }
});

test('printable text, DEL and astral characters pass', () => {
  assert.equal(hasControlChar(''), false);
  assert.equal(hasControlChar('1rem solid red'), false);
  assert.equal(hasControlChar('\x7F'), false, 'DEL is not C0');
  assert.equal(hasControlChar('"\u{1F600}"'), false, 'an astral code point');
  assert.equal(hasControlChar(' '), false, 'a C1-range space');
});

test('the allowance lets exactly the named control characters through', () => {
  assert.equal(hasControlChar('a\tb\nc\rd', '\t\n\r'), false);
  assert.equal(hasControlChar('a\tb\x0Bc', '\t\n\r'), true, 'vertical tab');
  assert.equal(hasControlChar('a\x0Cb', '\t\n\r'), true, 'form feed');
  assert.equal(hasControlChar('a\nb'), true, 'nothing allowed by default');
});
