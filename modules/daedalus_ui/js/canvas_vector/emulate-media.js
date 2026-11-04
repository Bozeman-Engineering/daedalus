/**
 * @file
 * Scheme/motion emulation by media-condition rewriting.
 *
 * Color scheme and reduced motion are genuinely global facts that compile
 * to @media, so no amount of canvas resizing re-evaluates them. Real
 * emulation lives at the browser level (DevTools); the closest a page can
 * get is rewriting its own stylesheets' media conditions, which this
 * module does over the CSSOM: every (prefers-color-scheme: …) /
 * (prefers-reduced-motion: …) TERM in a media condition is substituted
 * with an always-true or always-false term per the forced value, leaving
 * the rest of the condition (width terms, `not`, `and`) intact. That
 * covers the theme's own dark/motion styles as well as daedalus_stylish's printed
 * conditions — the whole page flips, not just the styled bits.
 *
 * Honest limits: JS matchMedia() consumers are not fooled, and
 * cross-origin stylesheets whose rules the CSSOM refuses to expose are
 * skipped. The popout preview window remains the true-viewport surface.
 */

/**
 * A term that matches every rendering.
 */
const TRUE_TERM = '(min-width: 0px)';

/**
 * A term that matches no real rendering.
 */
const FALSE_TERM = '(max-width: 0px)';

const SCHEME_TERM = /\(\s*prefers-color-scheme\s*:\s*(dark|light)\s*\)/gi;
const MOTION_TERM =
  /\(\s*prefers-reduced-motion\s*:\s*(reduce|no-preference)\s*\)/gi;

/**
 * Rewrites one media condition text for a forced scheme/motion state.
 *
 * @param {string} original
 *   The authored condition text.
 * @param {object} state
 *   The vector state; 'auto' leaves an axis authored.
 * @param {string} state.scheme
 *   The forced color scheme ('dark', 'light') or 'auto'.
 * @param {string} state.motion
 *   The forced motion preference ('reduce', 'no-preference') or 'auto'.
 *
 * @return {string}
 *   The rewritten condition text (identical when both axes are 'auto' or
 *   no term matches).
 */
export const rewriteMediaText = (
  original,
  { scheme = 'auto', motion = 'auto' } = {},
) => {
  let text = original;
  if (scheme !== 'auto') {
    text = text.replace(SCHEME_TERM, (_, value) =>
      value.toLowerCase() === scheme ? TRUE_TERM : FALSE_TERM,
    );
  }
  if (motion !== 'auto') {
    const forced = motion === 'reduce' ? 'reduce' : 'no-preference';
    text = text.replace(MOTION_TERM, (_, value) =>
      value.toLowerCase() === forced ? TRUE_TERM : FALSE_TERM,
    );
  }
  return text;
};

/**
 * True when a condition text carries a term either axis could force.
 *
 * @param {string} text
 *   The media condition text.
 *
 * @return {boolean}
 *   Whether a scheme or motion term is present.
 */
export const hasEmulatableTerm = (text) => {
  SCHEME_TERM.lastIndex = 0;
  MOTION_TERM.lastIndex = 0;
  return SCHEME_TERM.test(text) || MOTION_TERM.test(text);
};

/**
 * Applies the emulation state to a list of stylesheets.
 *
 * Walks every conditional group rule; rules whose media condition carries
 * an emulatable term get their ORIGINAL condition (remembered per rule in
 * the caller's WeakMap, so repeated applications never compound) rewritten
 * for the state — or restored verbatim when the state is fully 'auto'.
 *
 * @param {Iterable} sheets
 *   The stylesheets (document.styleSheets or stubs).
 * @param {Object} state
 *   The vector state ({scheme, motion}).
 * @param {WeakMap} originals
 *   rule -> authored condition text, owned by the caller so it survives
 *   across applications.
 */
export const applyToSheets = (sheets, state, originals) => {
  for (const sheet of sheets) {
    let rules;
    try {
      rules = sheet.cssRules;
    } catch {
      // Cross-origin stylesheet: unreadable, unemulatable.
      continue;
    }
    if (rules) {
      applyToRules(rules, state, originals);
    }
  }
};

/**
 * Recursively applies the state to a CSSRuleList.
 *
 * @param {CSSRuleList} rules
 *   The rules to rewrite, nested rule lists included.
 * @param {object} state
 *   The vector state, as for rewriteCondition.
 * @param {Map<CSSRule, string>} originals
 *   The authored condition text per rewritten rule, filled on first visit
 *   so a later state rewrites from the original, never from a rewrite.
 */
const applyToRules = (rules, state, originals) => {
  for (const rule of rules) {
    if (rule.media && typeof rule.media.mediaText === 'string') {
      let original = originals.get(rule);
      if (original === undefined) {
        original = rule.media.mediaText;
        if (hasEmulatableTerm(original)) {
          originals.set(rule, original);
        } else {
          original = null;
          originals.set(rule, null);
        }
      }
      if (original !== null) {
        const rewritten = rewriteMediaText(original, state);
        if (rule.media.mediaText !== rewritten) {
          rule.media.mediaText = rewritten;
        }
      }
    }
    // Conditional group rules (@media, @supports, @container) nest.
    if (rule.cssRules && rule.cssRules.length) {
      applyToRules(rule.cssRules, state, originals);
    }
  }
};
