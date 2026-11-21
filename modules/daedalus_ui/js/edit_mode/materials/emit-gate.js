/**
 * The emit gate's client twin: the one judgment of text bound for a style
 * element (Drupal\daedalus_blueprint\Materials\EmitGate, which owns the
 * rationale). PHP is authoritative; the shared battery
 * (daedalus_ui/tests/js/emit-gate-cases.mjs) locks the two, so change
 * both in the same commit.
 *
 * Admitted text carries no `;`, `{`, `}`, `<` or `>` (a selector keeps
 * `>`) and no control character but tab, line feed and carriage return;
 * closes every string on its own line and every `(` and `[` by its own
 * partner; carries no backslash and no comment outside a string; and
 * calls `url()` only as the media token or as the resolved file the
 * media resolver writes (a double-quoted path on this site), and no
 * other function that fetches what its argument names.
 */

const FORBIDDEN = ';{}<>';
const FORBIDDEN_IN_SELECTOR = ';{}<';
// The control characters admitted text may carry: tab, line feed,
// carriage return (the gate's \x09, \x0A and \x0D gaps).
const WHITESPACE = '\t\n\r';
const CONTROL_LIMIT = 0x20;

// A media token, and a resolved file URL (a path on this site), standing
// at lastIndex. Mirror EmitGate::MEDIA_TOKEN_AT and RESOLVED_URL_AT, byte
// for byte.
const MEDIA_TOKEN_AT =
  /url\([ \t\n\r]*(["']?)media:[0-9A-Za-z-]+\1[ \t\n\r]*\)/iy;
const RESOLVED_URL_AT = /url\("\/(?![/\\])[^"'()\\ \t\n\r]*"\)/iy;

// The functions, beside url(), that fetch what their argument names.
const LOADERS = ['image-set', '-webkit-image-set', 'image', 'src'];

const IDENT_CHAR = /[A-Za-z0-9_-]/;

/**
 * Whether text carries a character the gate forbids.
 *
 * @param {string} text
 *   The text.
 * @param {string} forbidden
 *   The forbidden punctuation.
 *
 * @return {boolean}
 *   True when it does.
 */
const hasForbidden = (text, forbidden) =>
  Array.prototype.some.call(
    text,
    (char) =>
      forbidden.includes(char) ||
      (char.charCodeAt(0) < CONTROL_LIMIT && !WHITESPACE.includes(char)),
  );

/**
 * The offset just past the string opening at an offset, or null when it
 * does not close: at the end of the text or at a line break (an escaped
 * one continues the string, as in CSS).
 *
 * @param {string} text
 *   The text.
 * @param {number} at
 *   The opening quote's offset.
 *
 * @return {?number}
 *   The offset past the closing quote.
 */
const stringEnd = (text, at) => {
  const quote = text[at];
  for (at++; at < text.length; at++) {
    const char = text[at];
    if (char === '\\') {
      at++;
    } else if (char === '\n' || char === '\r') {
      return null;
    } else if (char === quote) {
      return at + 1;
    }
  }
  return null;
};

/**
 * The length of the admitted url() call standing at an offset, or 0.
 *
 * @param {string} text
 *   The text.
 * @param {number} start
 *   The offset of the call's name.
 *
 * @return {number}
 *   The call's length; 0 when it is neither admitted spelling.
 */
const urlLength = (text, start) => {
  for (const pattern of [MEDIA_TOKEN_AT, RESOLVED_URL_AT]) {
    pattern.lastIndex = start;
    const match = pattern.exec(text);
    if (match !== null) {
      return match[0].length;
    }
  }
  return 0;
};

/**
 * Whether the text's strings, brackets and calls all close as they open
 * (EmitGate::balanced mirror).
 *
 * @param {string} text
 *   The text.
 *
 * @return {boolean}
 *   True when they do.
 */
const balanced = (text) => {
  const open = [];
  let at = 0;
  while (at < text.length) {
    const char = text[at];
    if (char === '"' || char === "'") {
      at = stringEnd(text, at);
      if (at === null) {
        return false;
      }
      continue;
    }
    if (char === '\\' || (char === '/' && text[at + 1] === '*')) {
      return false;
    }
    if (char === '(') {
      let start = at;
      while (start > 0 && IDENT_CHAR.test(text[start - 1])) {
        start--;
      }
      const name = text.slice(start, at).toLowerCase();
      if (name === 'url') {
        const length = urlLength(text, start);
        if (length === 0) {
          return false;
        }
        at = start + length;
        continue;
      }
      if (LOADERS.includes(name)) {
        return false;
      }
      open.push(')');
    } else if (char === '[') {
      open.push(']');
    } else if ((char === ')' || char === ']') && open.pop() !== char) {
      return false;
    }
    at++;
  }
  return open.length === 0;
};

/**
 * Whether text may print as a value, a condition or a name.
 *
 * @param {string} text
 *   The text.
 *
 * @return {boolean}
 *   True when the gate admits it.
 */
export const admits = (text) =>
  !hasForbidden(text, FORBIDDEN) && balanced(text);

/**
 * Whether text may print as a selector.
 *
 * @param {string} text
 *   The text.
 *
 * @return {boolean}
 *   True when the gate admits it.
 */
export const admitsSelector = (text) =>
  !hasForbidden(text, FORBIDDEN_IN_SELECTOR) && balanced(text);
