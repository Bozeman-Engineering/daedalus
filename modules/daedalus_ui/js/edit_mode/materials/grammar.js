/**
 * @file
 * The value grammar's client twin: what a string in a slot IS.
 *
 * The JS mirror of the PHP ValueGrammar (daedalus_blueprint
 * src/Materials/ValueGrammar.php), the ElementResolver twin pattern: one
 * resolution semantics, two runtimes, locked together by
 * ValueGrammarTest::testClientTwinEquivalence piping the same cases
 * through tests/js/materials-cases.mjs. Change the PHP grammar and this
 * module in the same commit, or the lock fails.
 *
 * Why a client twin exists: every picker, well, code-view lint and layer
 * reader needs to know whether a value is a material reference (and
 * which), an inline media/generate value, or a literal, without a round
 * trip; today each wraps var() itself and sniffs "media:" on its own.
 * The grammar owns no vocabulary: material spellings come from the
 * roster records (createRoster in ./roster.js), so resolution is an
 * exact-match lookup per slot plus the three inline syntaxes the spine
 * defines: a literal (anything else), url('media:<uuid>') and
 * url('generate:<prompt>').
 *
 * A resolved value is {kind, id, spelling, record}:
 * - kind: one of KIND (a material kind, an inline kind, or 'unknown' for
 *   a reference-shaped value the roster does not carry: kept apart from
 *   'literal' so an unrostered reference is never let through as CSS).
 * - id: the record id for a material; a media uuid; a generate prompt;
 *   for 'unknown' the reference as written (the custom property, the
 *   class token, the attribute value, the stencil name, the animation
 *   name); null for a literal.
 * - spelling: the reference as written in its slot, canonical; a
 *   literal's is the trimmed value.
 * - record: the roster record for a material kind, else null.
 */

/**
 * The value kinds: ValueKind's cases by value.
 */
export const KIND = Object.freeze({
  TOKEN: 'token',
  SWATCH: 'swatch',
  VARIANT: 'variant',
  STENCIL: 'stencil',
  ANIMATION: 'animation',
  MEDIA: 'media',
  GENERATE: 'generate',
  LITERAL: 'literal',
  UNKNOWN: 'unknown',
});

/**
 * The slots: MaterialRecord's SLOT_* constants.
 */
export const SLOT = Object.freeze({
  STYLE: 'style',
  CLASS: 'class',
  STENCIL: 'stencil',
  ANIMATION: 'animation',
  ATTR_PREFIX: 'attr:',
});

/**
 * The slot name for an axis attribute's value on a scope
 * (MaterialRecord::attrSlot()): "attr:kind:container:width".
 *
 * Scoped, never site-wide: the same attribute value on the container kind
 * and on the block kind are two slots, so a look name may repeat across
 * scopes. The scope is the variant's own; a consumer resolves against the
 * scope the element's axis view names.
 */
export const attrSlot = (scope, attribute) =>
  `${SLOT.ATTR_PREFIX}${scope}:${attribute}`;

/**
 * [scope, attribute] of a scoped attribute slot, or null for any other
 * (the unscoped "attr:width" included; there is no site-wide slot).
 */
const attrSlotParts = (slot) => {
  if (typeof slot !== 'string' || !slot.startsWith(SLOT.ATTR_PREFIX)) {
    return null;
  }
  const rest = slot.slice(SLOT.ATTR_PREFIX.length);
  const at = rest.lastIndexOf(':');
  if (at < 0) {
    return null;
  }
  const scope = rest.slice(0, at);
  const attribute = rest.slice(at + 1);
  const [dimension = '', scopeId = ''] = scope.split(/:(.*)/s);
  if (dimension === '' || scopeId === '' || attribute === '') {
    return null;
  }
  return [scope, attribute];
};

/**
 * The attribute an axis slot names, or null for any other slot.
 */
export const slotAttribute = (slot) => attrSlotParts(slot)?.[1] ?? null;

/**
 * The scope an axis slot names, or null for any other slot.
 */
export const slotScope = (slot) => attrSlotParts(slot)?.[0] ?? null;

/**
 * Whether a slot name is one the grammar resolves.
 */
export const isSlot = (slot) =>
  slot === SLOT.STYLE ||
  slot === SLOT.CLASS ||
  slot === SLOT.STENCIL ||
  slot === SLOT.ANIMATION ||
  slotAttribute(slot) !== null;

/**
 * Whether a value kind references a rostered material.
 */
export const isMaterialKind = (kind) =>
  kind === KIND.TOKEN ||
  kind === KIND.SWATCH ||
  kind === KIND.VARIANT ||
  kind === KIND.STENCIL ||
  kind === KIND.ANIMATION;

/**
 * Whether a value kind is an inline value (spelled without a mint).
 */
export const isInlineKind = (kind) =>
  kind === KIND.MEDIA || kind === KIND.GENERATE || kind === KIND.LITERAL;

const VAR_WHOLE_RE = /^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/;
const URL_WHOLE_RE = /^url\(\s*(["']?)(.*?)\1\s*\)$/is;
const REFERENCE_RE =
  /var\(\s*(--[A-Za-z0-9_-]+)\s*|url\(\s*(["']?)(.*?)\2\s*\)/gis;
const MEDIA_PREFIX = 'media:';
const GENERATE_PREFIX = 'generate:';

const assertSlot = (slot) => {
  if (!isSlot(slot)) {
    throw new Error(
      `Unknown value slot "${slot}"; one of ${SLOT.STYLE}, ${SLOT.CLASS}, ${SLOT.STENCIL}, ${SLOT.ANIMATION} or ${SLOT.ATTR_PREFIX}{scope}:{name}.`,
    );
  }
};

const literal = (spelling) => ({
  kind: KIND.LITERAL,
  id: null,
  spelling,
  record: null,
});

const reference = (slot, spelling, name, roster) => {
  const record = roster.bySpelling(slot, spelling);
  if (record === null) {
    return { kind: KIND.UNKNOWN, id: name, spelling, record: null };
  }
  return { kind: record.kind, id: record.id, spelling, record };
};

/**
 * The canonical spelling of a media reference: url('media:<uuid>').
 */
export const spellMedia = (uuid) =>
  `url('media:${String(uuid).trim().toLowerCase()}')`;

/**
 * The canonical spelling of a generation request: url('generate:…').
 */
export const spellGenerate = (prompt) =>
  `url('generate:${String(prompt).trim()}')`;

/**
 * The inline reference a url() argument names, or null for any other.
 */
const urlReference = (argument) => {
  const trimmed = argument.trim();
  const lower = trimmed.toLowerCase();
  if (lower.startsWith(MEDIA_PREFIX)) {
    const uuid = trimmed.slice(MEDIA_PREFIX.length).trim().toLowerCase();
    return {
      kind: KIND.MEDIA,
      id: uuid,
      spelling: spellMedia(uuid),
      record: null,
    };
  }
  if (lower.startsWith(GENERATE_PREFIX)) {
    const prompt = trimmed.slice(GENERATE_PREFIX.length).trim();
    return {
      kind: KIND.GENERATE,
      id: prompt,
      spelling: spellGenerate(prompt),
      record: null,
    };
  }
  return null;
};

/**
 * Resolves a value in a slot to what it is (ValueGrammar::resolve()).
 *
 * The whole value is the reference: a style value that is exactly one
 * var() resolves to the material spelled so (or unknown); exactly one
 * url('media:…') / url('generate:…') resolves to the inline kind; any
 * other style value is a literal, even when references sit inside it
 * (references() lists those). A class token, an axis attribute value, a
 * stencil name and an animation name resolve to the variant, stencil or
 * animation spelled so, or unknown. An empty value is the empty literal
 * in every slot.
 *
 * @param {string} value
 * @param {string} slot
 * @param {object} roster
 *   A roster from createRoster().
 * @return {{kind: string, id: string|null, spelling: string, record: object|null}}
 */
export const resolveValue = (value, slot, roster) => {
  assertSlot(slot);
  const trimmed = String(value ?? '').trim();
  if (trimmed === '') {
    return literal('');
  }
  if (slot !== SLOT.STYLE) {
    return reference(slot, trimmed, trimmed, roster);
  }
  const asVar = VAR_WHOLE_RE.exec(trimmed);
  if (asVar) {
    return reference(slot, `var(${asVar[1]})`, asVar[1], roster);
  }
  const asUrl = URL_WHOLE_RE.exec(trimmed);
  if (asUrl) {
    return urlReference(asUrl[2]) ?? literal(trimmed);
  }
  return literal(trimmed);
};

/**
 * Every reference a value carries, in source order (ValueGrammar::references()).
 *
 * A style value yields each var() reference (with or without a fallback)
 * and each media or generate url() inside it; a plain url() (a file URL)
 * is no reference. A class list yields one reference per token. An
 * attribute value, a stencil name or an animation name yields its one
 * reference. Literals
 * never appear: the list is what must resolve for the value to be legal.
 *
 * @return {Array<{kind: string, id: string|null, spelling: string, record: object|null}>}
 */
export const references = (value, slot, roster) => {
  assertSlot(slot);
  const text = String(value ?? '');
  if (slot === SLOT.STYLE) {
    const found = [];
    for (const m of text.matchAll(REFERENCE_RE)) {
      if (m[1]) {
        found.push(reference(slot, `var(${m[1]})`, m[1], roster));
        continue;
      }
      const inline = urlReference(m[3] ?? '');
      if (inline) {
        found.push(inline);
      }
    }
    return found;
  }
  const tokens =
    slot === SLOT.CLASS
      ? text
          .trim()
          .split(/\s+/)
          .filter((token) => token !== '')
      : [text.trim()].filter((token) => token !== '');
  return tokens.map((token) => reference(slot, token, token, roster));
};

/**
 * A value with every reference rewritten to its canonical spelling
 * (ValueGrammar::canonical()).
 *
 * In a style value each var() head is normalized (whitespace dropped, a
 * fallback left untouched) and each media or generate url() is respelled
 * single-quoted with a lowercased uuid or a trimmed prompt; everything
 * else stays byte-identical. A class list canonicalizes to its tokens
 * joined by one space; an attribute value, a stencil name or an
 * animation name to its trimmed self. Unknown references keep their
 * spelling.
 *
 * @return {string}
 */
export const canonical = (value, slot, roster) => {
  assertSlot(slot);
  if (slot !== SLOT.STYLE) {
    return references(value, slot, roster)
      .map((ref) => ref.spelling)
      .join(' ');
  }
  return String(value ?? '').replace(
    REFERENCE_RE,
    (whole, name, quote, argument) => {
      if (name) {
        return `var(${name}`;
      }
      return urlReference(argument ?? '')?.spelling ?? whole;
    },
  );
};

/**
 * How a material is written in a slot (ValueGrammar::spell()).
 *
 * Refused loudly when the record has no spelling there: a slot a material
 * cannot occupy is a programming error, never a silent empty string.
 *
 * @param {object} record
 *   A record in the delivery shape.
 * @param {string} slot
 * @return {string}
 */
export const spell = (record, slot) => {
  assertSlot(slot);
  const spelling = record?.spellings?.[slot];
  if (typeof spelling !== 'string' || spelling === '') {
    const slots = Object.keys(record?.spellings ?? {});
    throw new Error(
      `${record?.kind} "${record?.id}" has no spelling in the ${slot} slot; it spells in: ${slots.length ? slots.join(', ') : '(no slot)'}.`,
    );
  }
  return spelling;
};
