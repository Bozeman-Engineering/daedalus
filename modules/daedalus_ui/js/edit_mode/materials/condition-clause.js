/**
 * @file
 * The condition clause grammar: client twin of daedalus_blueprint's ConditionClause (PHP
 * authoritative, locked by ConditionClauseTest's shared battery
 * daedalus_ui/tests/js/condition-clause-cases.mjs).
 *
 * A condition key is a COMPOUND of clauses, one per axis, joined by `+`.
 * A clause is a REFERENCE (a published condition's name: `hover`,
 * `narrow`, `dark`), a LITERAL the browser knows (`@media (hover: hover)`,
 * `@container (min-width: 500px)`, `@supports (display: grid)`,
 * `:checked`), or an UNKNOWN word (`bogus`, `default`, `variants`); the
 * grammar never invents or drops anything, and validity is the
 * validator's verdict. The compound splits at its top-level `+` only (a
 * literal carries spaces, colons and parentheses). A literal's axis is
 * its feature: the width features are `width`, a feature a published
 * wrapper also tests shares that condition's axis, any other feature is
 * its own axis by name, `@supports` is `supports`, a pseudo-class `state`;
 * a reference's axis is its plugin's. A literal spelling a published
 * wrapper IS that reference.
 *
 * The structured twins the canvas poses are DERIVED from a clause's CSS,
 * references and literals alike: `band` {min?, max?} the inclusive px
 * edges of a `@container` width query (a `@media` width query is the
 * viewport's, which the canvas cannot pose, so none; a non-px edge none),
 * `preference` {feature, value} the user preference a single-feature
 * `@media` `prefers-*` query tests. Nothing in the client declares them
 * and nothing reads the wrapper text but this module.
 *
 * Canonical order: published axes by first appearance in the table, then
 * literal axes the table does not know alphabetically, then unknown words
 * in spelled order; ties keep spelled order.
 *
 * A clause record is {kind, text, form, axis, css, band, preference}; the
 * server class owns the rationale.
 */

/** The delimiter joining a compound's clauses. */
export const DELIMITER = '+';

/** Clause kinds. */
export const REFERENCE = 'reference';
export const LITERAL = 'literal';
export const UNKNOWN = 'unknown';

/** Clause forms: how the clause's CSS wraps a rule. */
export const PSEUDO = 'pseudo';
export const MEDIA = 'media';
export const CONTAINER = 'container';
export const SUPPORTS = 'supports';

/** The axes the grammar names itself. */
export const WIDTH_AXIS = 'width';
export const STATE_AXIS = 'state';
export const SUPPORTS_AXIS = 'supports';

/**
 * The pseudo-class literals no stamp stands in for (the browser's history
 * and the URL's fragment are not element states a stamp can pretend);
 * the server constant owns the rationale.
 */
export const UNPOSABLE_STATES = ['visited', 'target'];

const WIDTH_FEATURES = ['width', 'min-width', 'max-width'];
const PREFERENCE_PREFIX = 'prefers-';
const QUERY_KEYWORDS = ['and', 'or', 'not', 'only', 'all', 'screen', 'print', 'speech'];

const isRow = (definition) =>
  definition !== null && typeof definition === 'object' && !Array.isArray(definition);

/**
 * Splits a compound into its clause texts, at the top-level `+` only.
 *
 * The one compound split (ConditionTypePluginManager::compoundParts()):
 * a single clause is the compound of one, an empty compound one empty
 * clause.
 *
 * @param {string} compound
 *
 * @return {string[]}
 *   The clause texts, trimmed, in spelled order.
 */
export const compoundParts = (compound) => {
  const parts = [];
  let current = '';
  let depth = 0;
  for (const char of String(compound)) {
    if (char === '(') {
      depth += 1;
    } else if (char === ')' && depth > 0) {
      depth -= 1;
    }
    if (char === DELIMITER && depth === 0) {
      parts.push(current.trim());
      current = '';
      continue;
    }
    current += char;
  }
  parts.push(current.trim());
  return parts;
};

/**
 * Splits a query body into words and parenthesized groups; null when the
 * parentheses do not balance.
 */
const tokens = (body) => {
  const found = [];
  let current = '';
  let depth = 0;
  for (const char of body) {
    if (depth === 0 && /\s/.test(char)) {
      if (current !== '') {
        found.push(current);
        current = '';
      }
      continue;
    }
    if (char === '(') {
      if (depth === 0 && current !== '') {
        found.push(current);
        current = '';
      }
      depth += 1;
    } else if (char === ')') {
      if (depth === 0) {
        return null;
      }
      depth -= 1;
      current += char;
      if (depth === 0) {
        found.push(current);
        current = '';
      }
      continue;
    }
    current += char;
  }
  if (depth !== 0) {
    return null;
  }
  if (current !== '') {
    found.push(current);
  }
  return found;
};

/**
 * Reads a wrapper's CSS into {form, text, features}, or null for text of
 * no known form (the PHP read()'s twin).
 */
const read = (input) => {
  const css = String(input).trim();
  const pseudo = css.match(/^:([a-z][a-z0-9-]*)$/i);
  if (pseudo) {
    return { form: PSEUDO, text: `:${pseudo[1].toLowerCase()}`, features: [] };
  }
  const atRule = css.match(/^@(media|container|supports)(?:\s+([\s\S]*))?$/i);
  if (!atRule) {
    return null;
  }
  const form = atRule[1].toLowerCase();
  const body = (atRule[2] ?? '').trim();
  if (body === '') {
    return null;
  }
  if (form === SUPPORTS) {
    const collapsed = body
      .replace(/\s+/g, ' ')
      .replace(/\(\s+/g, '(')
      .replace(/\s+\)/g, ')');
    return { form: SUPPORTS, text: `@supports ${collapsed}`, features: [] };
  }
  const parts = tokens(body);
  if (parts === null) {
    return null;
  }
  const features = [];
  const printed = [];
  for (const token of parts) {
    if (token[0] === '(') {
      const feature = token.match(/^\(\s*([a-z][a-z0-9-]*)\s*(?::\s*([^()]*?)\s*)?\)$/i);
      if (!feature) {
        return null;
      }
      const name = feature[1].toLowerCase();
      const value =
        feature[2] !== undefined && feature[2] !== ''
          ? feature[2].replace(/\s+/g, ' ').toLowerCase()
          : null;
      features.push([name, value]);
      printed.push(value === null ? `(${name})` : `(${name}: ${value})`);
    } else {
      const lower = token.toLowerCase();
      printed.push(QUERY_KEYWORDS.includes(lower) ? lower : token);
    }
  }
  if (!features.length) {
    return null;
  }
  return { form, text: `@${form} ${printed.join(' ')}`, features };
};

/**
 * How a wrapper wraps a rule, by its prefix: PSEUDO, MEDIA, CONTAINER,
 * SUPPORTS, or null for text of no known form.
 */
export const clauseForm = (input) => {
  const css = String(input).trim();
  const atRule = css.match(/^@(media|container|supports)\b/i);
  if (atRule) {
    return atRule[1].toLowerCase();
  }
  if (css.startsWith(':') && !css.startsWith('::')) {
    return PSEUDO;
  }
  return null;
};

/**
 * The width band a wrapper's CSS poses: {min?, max?} in inclusive px for
 * a `@container` width query, else null.
 */
export const clauseBand = (css) => {
  const reading = read(css);
  if (reading === null || reading.form !== CONTAINER) {
    return null;
  }
  let min = null;
  let max = null;
  for (const [name, value] of reading.features) {
    const px = value === null ? null : value.match(/^(\d+)px$/);
    if (!WIDTH_FEATURES.includes(name) || !px) {
      return null;
    }
    const edge = Number(px[1]);
    if (name !== 'max-width') {
      min = edge;
    }
    if (name !== 'min-width') {
      max = edge;
    }
  }
  const band = {};
  if (min !== null) {
    band.min = min;
  }
  if (max !== null) {
    band.max = max;
  }
  return Object.keys(band).length ? band : null;
};

/**
 * The user preference a wrapper's CSS tests: {feature, value} for a
 * single-feature `@media` `prefers-*` query with a value, else null.
 */
export const clausePreference = (css) => {
  const reading = read(css);
  if (reading === null || reading.form !== MEDIA || reading.features.length !== 1) {
    return null;
  }
  const [name, value] = reading.features[0];
  if (value === null || !name.startsWith(PREFERENCE_PREFIX)) {
    return null;
  }
  return { feature: name, value };
};

/**
 * The feature tests a wrapper's CSS makes, as [name, value] pairs in
 * spelled order (ConditionClause::features()'s twin): empty for a
 * pseudo-class, a `@supports` test or text of no known form.
 */
export const clauseFeatures = (css) => read(css)?.features ?? [];

/**
 * Whether the canvas can pose a clause (ConditionClause::posable()'s
 * twin): an at-rule clause when it derives a band or a preference, a
 * published pseudo-class always, a pseudo-class literal unless it names
 * a state no stamp stands in for; never an unknown word.
 *
 * @param {{kind: string, form: ?string, text: string, band: ?Object,
 *   preference: ?Object}} clause
 *   A clause record (parseClause).
 *
 * @return {boolean}
 */
export const clausePosable = (clause) => {
  if (clause.kind === UNKNOWN) {
    return false;
  }
  if (clause.form === PSEUDO) {
    return clause.kind === REFERENCE || !UNPOSABLE_STATES.includes(clause.text.slice(1));
  }
  return clause.band !== null || clause.preference !== null;
};

/**
 * The word the force-state stamp carries for a pseudo-class clause
 * (ConditionClause::stamp()'s twin): a reference's published name, a
 * literal's bare pseudo-class; null for any other form.
 *
 * @param {{kind: string, form: ?string, text: string}} clause
 *   A clause record (parseClause).
 *
 * @return {?string}
 */
export const clauseStamp = (clause) => {
  if (clause.form !== PSEUDO || clause.kind === UNKNOWN) {
    return null;
  }
  return clause.kind === REFERENCE ? clause.text : clause.text.slice(1);
};

const record = (kind, text, form, axis, css) => ({
  kind,
  text,
  form,
  axis,
  css,
  band: css === null ? null : clauseBand(css),
  preference: css === null ? null : clausePreference(css),
});

const reference = (name, conditions) => {
  const definition = conditions[name];
  const wrapper = typeof definition.wrapper === 'string' ? definition.wrapper : null;
  const axis =
    typeof definition.axis === 'string' && definition.axis !== '' ? definition.axis : null;
  return record(REFERENCE, name, wrapper === null ? null : clauseForm(wrapper), axis, wrapper);
};

const publishedRows = (conditions) =>
  Object.entries(conditions ?? {}).filter(
    ([, definition]) => isRow(definition) && typeof definition.wrapper === 'string',
  );

const featureAxis = (feature, conditions) => {
  if (WIDTH_FEATURES.includes(feature)) {
    return WIDTH_AXIS;
  }
  for (const [, definition] of publishedRows(conditions)) {
    if (typeof definition.axis !== 'string' || definition.axis === '') {
      continue;
    }
    const published = read(definition.wrapper);
    if (published !== null && published.features.some(([name]) => name === feature)) {
      return definition.axis;
    }
  }
  return feature;
};

const literalAxis = (reading, conditions) => {
  if (reading.form === PSEUDO) {
    return STATE_AXIS;
  }
  if (reading.form === SUPPORTS) {
    return SUPPORTS_AXIS;
  }
  const axes = new Set(reading.features.map(([name]) => featureAxis(name, conditions)));
  return axes.size === 1 ? [...axes][0] : null;
};

/**
 * Reads one clause.
 *
 * @param {string} input
 *   The clause text.
 * @param {Object} conditions
 *   The published condition table: name -> {wrapper, axis, ...}
 *   (guide.conditions; a non-object entry such as the payload's `default`
 *   string is no condition).
 *
 * @return {{kind: string, text: string, form: ?string, axis: ?string,
 *   css: ?string, band: ?Object, preference: ?Object}}
 *   The clause record.
 */
export const parseClause = (input, conditions) => {
  const text = String(input ?? '').trim();
  const table = conditions ?? {};
  if (Object.prototype.hasOwnProperty.call(table, text) && isRow(table[text])) {
    return reference(text, table);
  }
  const reading = read(text);
  if (reading === null) {
    return record(UNKNOWN, text, null, null, null);
  }
  for (const [name, definition] of publishedRows(table)) {
    const published = read(definition.wrapper);
    if (published !== null && published.text === reading.text) {
      return reference(name, table);
    }
  }
  const axis = literalAxis(reading, table);
  if (axis === null) {
    return record(UNKNOWN, text, null, null, null);
  }
  return record(LITERAL, reading.text, reading.form, axis, reading.text);
};

/**
 * Parses a compound into its clause records, in spelled order.
 */
export const parseCompound = (compound, conditions) =>
  compoundParts(compound).map((text) => parseClause(text, conditions));

/**
 * The published axes in first-appearance order over the table: the
 * canonical compound order's spine (ConditionTypePluginManager::
 * conditionAxes()'s twin).
 *
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 *
 * @return {string[]}
 *   The axis names, each once.
 */
export const conditionAxisOrder = (conditions) => {
  const axes = [];
  for (const definition of Object.values(conditions ?? {})) {
    if (!isRow(definition)) {
      continue;
    }
    const { axis } = definition;
    if (typeof axis === 'string' && axis !== '' && !axes.includes(axis)) {
      axes.push(axis);
    }
  }
  return axes;
};

const rank = (clause, order) => {
  if (clause.axis === null) {
    return [2, 0];
  }
  if (order.has(clause.axis)) {
    return [0, order.get(clause.axis)];
  }
  return [1, clause.axis];
};

const compareRanks = (a, b) => {
  if (a[0] !== b[0]) {
    return a[0] - b[0];
  }
  if (a[0] === 0) {
    return a[1] - b[1];
  }
  if (a[0] === 1) {
    return a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0;
  }
  return 0;
};

/**
 * The canonical spelling of a compound: clauses in canonical order, each
 * as its clause text. Idempotent; never invents or drops a clause.
 */
export const canonicalCompound = (compound, conditions) => {
  const order = new Map(conditionAxisOrder(conditions).map((axis, index) => [axis, index]));
  return parseCompound(compound, conditions)
    .map((clause, index) => ({ rank: rank(clause, order), index, text: clause.text }))
    .sort((a, b) => compareRanks(a.rank, b.rank) || a.index - b.index)
    .map(({ text }) => text)
    .join(DELIMITER);
};
