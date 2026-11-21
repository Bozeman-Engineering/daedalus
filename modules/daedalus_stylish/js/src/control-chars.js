/**
 * The C0 control characters (U+0000 to U+001F) a CSS value may not carry.
 *
 * The value check (validate-value.js's mirror of
 * PropertyGroupBase::validateValue) rejects them by code point rather
 * than by a regex range: a control escape inside a pattern reads as a
 * typo to the linter (no-control-regex), and the comparison says the same
 * thing in the open.
 */
const CONTROL_LIMIT = 0x20;

/**
 * Whether a value carries a C0 control character outside `allowed`.
 *
 * @param {string} value
 *   The text to gate.
 * @param {string} allowed
 *   The control characters the gate lets through; '' lets none through.
 *
 * @return {boolean}
 *   True when a forbidden control character is present.
 */
export const hasControlChar = (value, allowed = '') =>
  [...value].some(
    (char) => char.codePointAt(0) < CONTROL_LIMIT && !allowed.includes(char),
  );
