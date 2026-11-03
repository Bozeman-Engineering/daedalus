/**
 * The shared condition clause battery: one record both twins must answer.
 *
 * Each split case is a compound `text` and the clause texts both sides
 * split it into; each clause case a clause `text`, the `table` it is read
 * against (TABLES; `default` when omitted) and the one record both sides
 * must answer ({kind, text, form, axis, css, band, preference},
 * ConditionClause::clause() and parseClause()'s return); each canonical
 * case a compound, its table and the canonical spelling; each axes case a
 * table and its axis order. The node suite daedalus_ui/tests/js/condition-clause.test.mjs
 * runs the client twin over every case; ConditionClauseTest (the PHP unit
 * test) runs this file to read the cases (stdout: {tables, splits,
 * clauses, canonicals, axes}) and runs the PHP class over every one. Both
 * compare to the same recorded answer, so the two can never drift and a
 * case neither side agrees with fails on both. Importing this file reads
 * nothing: the print runs only as a script.
 */
import { fileURLToPath } from 'node:url';

const STATES = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  'focus-within': { label: 'Focus within', wrapper: ':focus-within', axis: 'state' },
  'focus-visible': { label: 'Focus visible', wrapper: ':focus-visible', axis: 'state' },
  active: { label: 'Active', wrapper: ':active', axis: 'state' },
};
const WRAPPERS = {
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
  medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width' },
  dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion' },
};

/**
 * The tables the cases read against: the shipped vocabulary; a site
 * vocabulary of viewport queries (a @media width band the canvas cannot
 * pose, a light scheme spelled with a media type, a named query
 * container, a pointer condition on an axis of its own); an empty one;
 * and the client payload's shape, whose `default` row is a string.
 */
export const TABLES = {
  default: { conditions: { ...STATES, ...WRAPPERS } },
  viewport: {
    conditions: {
      hover: STATES.hover,
      mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
      page: { label: 'Page', wrapper: '@container page (max-width: 720px)', axis: 'width' },
      light: { label: 'Light mode', wrapper: '@media screen and (prefers-color-scheme: light)', axis: 'scheme' },
      pointer: { label: 'Pointer', wrapper: '@media (hover: hover)', axis: 'pointer' },
    },
  },
  none: { conditions: {} },
  payload: { conditions: { default: 'always applies', ...STATES, ...WRAPPERS } },
};

const clause = (kind, text, form, axis, css, band = null, preference = null) => ({ kind, text, form, axis, css, band, preference });
const reference = (text, form, axis, css, band = null, preference = null) => clause('reference', text, form, axis, css, band, preference);
const literal = (text, form, axis, band = null, preference = null) => clause('literal', text, form, axis, text, band, preference);
const unknown = (text) => clause('unknown', text, null, null, null);

const HOVER = reference('hover', 'pseudo', 'state', ':hover');
const NARROW = reference('narrow', 'container', 'width', '@container (max-width: 767px)', { max: 767 });
const MEDIUM = reference('medium', 'container', 'width', '@container (min-width: 768px) and (max-width: 1023px)', { min: 768, max: 1023 });
const DARK = reference('dark', 'media', 'scheme', '@media (prefers-color-scheme: dark)', null, { feature: 'prefers-color-scheme', value: 'dark' });

export const SPLITS = [
  { name: 'a single clause is the compound of one', text: 'hover', answer: ['hover'] },
  { name: 'references split at +', text: 'narrow+dark', answer: ['narrow', 'dark'] },
  { name: 'clauses are trimmed', text: ' narrow + dark ', answer: ['narrow', 'dark'] },
  { name: 'a + inside parentheses belongs to its literal', text: '@supports (selector(a + b))+hover', answer: ['@supports (selector(a + b))', 'hover'] },
  { name: 'two literals', text: '@container (min-width: 500px)+@media (hover: hover)', answer: ['@container (min-width: 500px)', '@media (hover: hover)'] },
  { name: 'an empty compound is one empty clause', text: '', answer: [''] },
  { name: 'an empty clause is kept, never dropped', text: 'a++b', answer: ['a', '', 'b'] },
];

export const CLAUSES = [
  // References: the published definition's wrapper and axis, the twins
  // derived from the wrapper.
  { name: 'a state reference', text: 'hover', answer: HOVER },
  { name: 'a width reference derives its band', text: 'narrow', answer: NARROW },
  { name: 'a two-edged width reference', text: 'medium', answer: MEDIUM },
  { name: 'a scheme reference derives its preference', text: 'dark', answer: DARK },
  { name: 'a motion reference', text: 'reduced-motion', answer: reference('reduced-motion', 'media', 'motion', '@media (prefers-reduced-motion: reduce)', null, { feature: 'prefers-reduced-motion', value: 'reduce' }) },
  // A literal spelling a published wrapper is that reference.
  { name: 'a published container wrapper is its name', text: '@container (max-width: 767px)', answer: NARROW },
  { name: 'case and spacing fold', text: '@CONTAINER  ( max-width:767px )', answer: NARROW },
  { name: 'the colon spacing folds', text: '@media (prefers-color-scheme:dark)', answer: DARK },
  { name: 'a two-feature wrapper folds', text: '@container (min-width: 768px) and (max-width: 1023px)', answer: MEDIUM },
  { name: 'feature order is kept, so a reordered wrapper is a literal', text: '@container (max-width: 1023px) and (min-width: 768px)', answer: literal('@container (max-width: 1023px) and (min-width: 768px)', 'container', 'width', { min: 768, max: 1023 }) },
  { name: 'a published pseudo-class is its name', text: ':hover', answer: HOVER },
  { name: 'a pseudo-class folds case', text: ':HOVER', answer: HOVER },
  // Literals: media features.
  { name: 'a media feature is its own axis', text: '@media (hover: hover)', answer: literal('@media (hover: hover)', 'media', 'hover') },
  { name: 'a media feature folds case and spacing', text: '@media (HOVER:hover)', answer: literal('@media (hover: hover)', 'media', 'hover') },
  { name: 'a preference literal derives its preference', text: '@media (prefers-contrast: more)', answer: literal('@media (prefers-contrast: more)', 'media', 'prefers-contrast', null, { feature: 'prefers-contrast', value: 'more' }) },
  { name: 'a feature a published wrapper tests shares its axis', text: '@media (prefers-color-scheme: light)', answer: literal('@media (prefers-color-scheme: light)', 'media', 'scheme', null, { feature: 'prefers-color-scheme', value: 'light' }) },
  { name: 'the motion axis the same way', text: '@media (prefers-reduced-motion: no-preference)', answer: literal('@media (prefers-reduced-motion: no-preference)', 'media', 'motion', null, { feature: 'prefers-reduced-motion', value: 'no-preference' }) },
  { name: 'a media width is the width axis with no band (the viewport\'s)', text: '@media (max-width: 600px)', answer: literal('@media (max-width: 600px)', 'media', 'width') },
  { name: 'a two-edged media width', text: '@media (min-width: 900px) and (max-width: 1200px)', answer: literal('@media (min-width: 900px) and (max-width: 1200px)', 'media', 'width') },
  { name: 'a media type is kept, keywords lowercased', text: '@media SCREEN AND (prefers-color-scheme: light)', answer: literal('@media screen and (prefers-color-scheme: light)', 'media', 'scheme', null, { feature: 'prefers-color-scheme', value: 'light' }) },
  { name: 'a boolean feature', text: '@media (color)', answer: literal('@media (color)', 'media', 'color') },
  // Literals: container features.
  { name: 'a container floor derives a floor-only band', text: '@container (min-width: 500px)', answer: literal('@container (min-width: 500px)', 'container', 'width', { min: 500 }) },
  { name: 'an exact width is both edges', text: '@container (width: 500px)', answer: literal('@container (width: 500px)', 'container', 'width', { min: 500, max: 500 }) },
  { name: 'a two-edged container band', text: '@container (min-width: 500px) and (max-width: 900px)', answer: literal('@container (min-width: 500px) and (max-width: 900px)', 'container', 'width', { min: 500, max: 900 }) },
  { name: 'a named container keeps its name', text: '@container page (max-width: 720px)', answer: literal('@container page (max-width: 720px)', 'container', 'width', { max: 720 }) },
  { name: 'an identifier keeps its case', text: '@container Page (max-width: 720px)', answer: literal('@container Page (max-width: 720px)', 'container', 'width', { max: 720 }) },
  { name: 'a non-px edge derives no band', text: '@container (max-width: 40em)', answer: literal('@container (max-width: 40em)', 'container', 'width') },
  // Literals: supports and pseudo-classes.
  { name: 'a supports test', text: '@supports (display: grid)', answer: literal('@supports (display: grid)', 'supports', 'supports') },
  { name: 'a supports test collapses whitespace', text: '@supports   ( display: grid )', answer: literal('@supports (display: grid)', 'supports', 'supports') },
  { name: 'an unpublished pseudo-class is a state literal', text: ':checked', answer: literal(':checked', 'pseudo', 'state') },
  { name: 'a pseudo-class literal folds case', text: ':Checked', answer: literal(':checked', 'pseudo', 'state') },
  // Unknown words: kept verbatim, judged by the validator.
  { name: 'an unpublished word', text: 'bogus', answer: unknown('bogus') },
  { name: 'default is no condition', text: 'default', answer: unknown('default') },
  { name: 'the empty clause', text: '', answer: unknown('') },
  { name: 'a literal spanning two axes is two clauses, not one', text: '@media (hover: hover) and (pointer: fine)', answer: unknown('@media (hover: hover) and (pointer: fine)') },
  { name: 'a container mixing width and another feature', text: '@container (min-width: 500px) and (hover: hover)', answer: unknown('@container (min-width: 500px) and (hover: hover)') },
  { name: 'a media type alone tests nothing', text: '@media screen', answer: unknown('@media screen') },
  { name: 'unbalanced parentheses', text: '@media (min-width: 900px', answer: unknown('@media (min-width: 900px') },
  { name: 'a pseudo-element is no condition', text: '::before', answer: unknown('::before') },
  { name: 'a functional pseudo-class is selector grammar', text: ':nth-child(2)', answer: unknown(':nth-child(2)') },
  { name: 'a prefixless feature query names no at-rule', text: '(min-width: 900px)', answer: unknown('(min-width: 900px)') },
  // Other tables.
  { name: 'with no table a preference is its feature\'s axis', text: '@media (prefers-color-scheme: dark)', table: 'none', answer: literal('@media (prefers-color-scheme: dark)', 'media', 'prefers-color-scheme', null, { feature: 'prefers-color-scheme', value: 'dark' }) },
  { name: 'a published media feature wrapper is its name', text: '@media (hover: hover)', table: 'viewport', answer: reference('pointer', 'media', 'pointer', '@media (hover: hover)') },
  { name: 'another value of that feature shares its axis', text: '@media (hover: none)', table: 'viewport', answer: literal('@media (hover: none)', 'media', 'pointer') },
  { name: 'a @media width reference derives no band', text: 'mobile', table: 'viewport', answer: reference('mobile', 'media', 'width', '@media (max-width: 767px)') },
  { name: 'a reference spelled with a media type derives its preference', text: 'light', table: 'viewport', answer: reference('light', 'media', 'scheme', '@media screen and (prefers-color-scheme: light)', null, { feature: 'prefers-color-scheme', value: 'light' }) },
  { name: 'a named-container reference derives its band', text: 'page', table: 'viewport', answer: reference('page', 'container', 'width', '@container page (max-width: 720px)', { max: 720 }) },
  { name: 'the payload\'s default string is no condition', text: 'default', table: 'payload', answer: unknown('default') },
  { name: 'the payload\'s rows are references', text: 'hover', table: 'payload', answer: HOVER },
];

export const CANONICALS = [
  { name: 'references sort into axis order', text: 'dark+narrow+hover', answer: 'hover+narrow+dark' },
  { name: 'canonical is idempotent', text: 'hover+narrow+dark', answer: 'hover+narrow+dark' },
  { name: 'two references', text: 'reduced-motion+narrow', answer: 'narrow+reduced-motion' },
  { name: 'a single reference', text: 'hover', answer: 'hover' },
  { name: 'default passes through', text: 'default', answer: 'default' },
  { name: 'variants passes through', text: 'variants', answer: 'variants' },
  { name: 'unknown words sort last in spelled order', text: 'bogus+dark+worse', answer: 'dark+bogus+worse' },
  { name: 'a literal on an unpublished axis sorts after the published axes', text: '@media (hover: hover)+hover', answer: 'hover+@media (hover: hover)' },
  { name: 'unpublished axes sort alphabetically', text: '@supports (display: grid)+@media (hover: hover)+narrow', answer: 'narrow+@media (hover: hover)+@supports (display: grid)' },
  { name: 'a literal on a published axis takes that axis\'s place', text: '@media (prefers-color-scheme: light)+hover', answer: 'hover+@media (prefers-color-scheme: light)' },
  { name: 'a container literal is the width axis', text: 'dark+@container (min-width: 500px)', answer: '@container (min-width: 500px)+dark' },
  { name: 'a literal spelling a wrapper canonicalizes to its name', text: '@CONTAINER ( max-width:767px )+hover', answer: 'hover+narrow' },
  { name: 'a pseudo-class spelling a state canonicalizes to its name', text: ':hover+narrow', answer: 'hover+narrow' },
  { name: 'a single literal normalizes', text: '@media (HOVER: Hover)', answer: '@media (hover: hover)' },
  { name: 'two clauses on one axis keep spelled order (the validator refuses them)', text: 'medium+narrow', answer: 'medium+narrow' },
  { name: 'the empty compound', text: '', answer: '' },
  { name: 'a pseudo-class literal is the state axis', text: '@media (hover: hover)+:checked', answer: ':checked+@media (hover: hover)' },
  { name: 'an unknown word sorts after a literal', text: 'bogus+@supports (display: grid)', answer: '@supports (display: grid)+bogus' },
  { name: 'with no table every name is unknown', text: 'dark+narrow', table: 'none', answer: 'dark+narrow' },
  { name: 'with no table literal axes sort alphabetically', text: '@container (min-width: 500px)+@media (prefers-color-scheme: dark)', table: 'none', answer: '@media (prefers-color-scheme: dark)+@container (min-width: 500px)' },
  { name: 'the payload\'s string row places nothing', text: 'dark+hover', table: 'payload', answer: 'hover+dark' },
];

export const AXES = [
  { name: 'the shipped table', table: 'default', answer: ['state', 'width', 'scheme', 'motion'] },
  { name: 'a site table in its own order', table: 'viewport', answer: ['state', 'width', 'scheme', 'pointer'] },
  { name: 'no table', table: 'none', answer: [] },
  { name: 'a string row is skipped', table: 'payload', answer: ['state', 'width', 'scheme', 'motion'] },
];

/**
 * Pose cases: a clause text, its table and the pose-ability verdict
 * both sides must answer ({posable, stamp, features}: whether the canvas
 * can pose the clause, the word the force-state stamp carries for a
 * pseudo-class clause, and the feature tests the clause's CSS makes).
 */
const pose = (posable, stamp = null, features = []) => ({ posable, stamp, features });

export const POSES = [
  { name: 'a published state is posed by its name', text: 'hover', answer: pose(true, 'hover') },
  { name: 'a pseudo-class spelling a published state stamps the name', text: ':hover', answer: pose(true, 'hover') },
  { name: 'a width reference is posed by the ruler', text: 'narrow', answer: pose(true, null, [['max-width', '767px']]) },
  { name: 'a scheme reference is posed by the emulation vector', text: 'dark', answer: pose(true, null, [['prefers-color-scheme', 'dark']]) },
  { name: 'a container literal with a band is posed', text: '@container (min-width: 500px)', answer: pose(true, null, [['min-width', '500px']]) },
  { name: 'a container width in em has no band and is not posed', text: '@container (max-width: 40em)', answer: pose(false, null, [['max-width', '40em']]) },
  { name: 'a viewport width is not posed', text: '@media (max-width: 600px)', answer: pose(false, null, [['max-width', '600px']]) },
  { name: 'a preference literal is posed', text: '@media (prefers-contrast: more)', answer: pose(true, null, [['prefers-contrast', 'more']]) },
  { name: 'a pointer literal is not posed', text: '@media (hover: hover)', answer: pose(false, null, [['hover', 'hover']]) },
  { name: 'a boolean feature test has no value', text: '@media (color)', answer: pose(false, null, [['color', null]]) },
  { name: 'a supports test is never posed and tests no feature', text: '@supports (display: grid)', answer: pose(false) },
  { name: 'a pseudo-class literal is stamped by its bare name', text: ':checked', answer: pose(true, 'checked') },
  { name: 'visited is a state no stamp stands in for', text: ':visited', answer: pose(false, 'visited') },
  { name: 'target the same', text: ':target', answer: pose(false, 'target') },
  { name: 'an unknown word is not posed', text: 'bogus', answer: pose(false) },
  { name: 'a prefixless query is unknown', text: '(min-width: 900px)', answer: pose(false) },
  { name: 'a published pointer wrapper is posed by nothing', text: 'pointer', table: 'viewport', answer: pose(false, null, [['hover', 'hover']]) },
];

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.stdout.write(JSON.stringify({ tables: TABLES, splits: SPLITS, clauses: CLAUSES, canonicals: CANONICALS, axes: AXES, poses: POSES }));
}
