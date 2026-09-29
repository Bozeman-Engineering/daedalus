import { parse as parseCss } from 'css-tree';
import { WRAPPER, preludeParts } from './prelude.js';
import { canonicalSelector } from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * The paste door's pure half: text with structure, read through the spine.
 *
 * Structure is chrome and text is a door, never the store. An island is a
 * flat declaration list, so a nested block inside it (a `&:hover { }`
 * rule, an `@container` wrapper, a whole `#id { }` block from the AI's
 * answer, a `.quote { }` part) is never island text: it is handed whole
 * to the server's parse door (`POST …/parse-css {key, css}`, the one
 * parser the AI's style op uses, CssFragmentDoor), which answers the flat
 * (key, condition) entries the text means. The client then emits those
 * entries as its own batch (the element document: one update_style per
 * entry plus apply_variant for rule wear, one history entry) or stages
 * them (the variant document), and the chunk leaves the buffer. Nothing
 * here touches the DOM or the wire: the Island does the sending, this
 * module decides what is structure, what to send under which key, and
 * what the answer becomes.
 *
 * Why csstree plus a brace scan: in the declarationList context csstree
 * reads `&:hover { }` as a Rule and `@wrapper { }` as an Atrule, but a
 * block whose prelude is not nesting sugar (`#id { }`, `.quote { }`,
 * `> pb-text { }`, `p { }`) becomes one Raw node that swallows every
 * declaration after it. The scan splits such a Raw at its first balanced
 * block when the prelude is a selector the grammar knows (a `#id` root or
 * a canonical relative selector), and the tail is read again, so the
 * declarations beside a pasted block stay declarations.
 */

/**
 * The id prelude a copied block carries (`#<uuid>`, states or a chain
 * after it); the door re-roots it to the key it was pasted into.
 */
const ID_PRELUDE = /^#[^\s{:>.+~]+/;

/**
 * The offset of the `}` closing the block opened at `open`, or -1 when
 * the text ends first. Strings and comments are not modeled: the door's
 * parser blanks comments itself, and a brace inside a string value is
 * not a shape a pasted style block carries.
 */
const closingBrace = (text, open) => {
  let depth = 0;
  for (let i = open; i < text.length; i += 1) {
    if (text[i] === '{') {
      depth += 1;
    } else if (text[i] === '}') {
      depth -= 1;
      if (depth === 0) {
        return i;
      }
    }
  }
  return -1;
};

/**
 * Whether a prelude names a block the door can read: a copied `#id`
 * root, nesting sugar (`&…`, a leading `:`), or a relative selector in
 * the grammar's canonical reach (`> pb-text`, `.quote`, `p`).
 */
const blockPrelude = (prelude) => {
  const text = prelude.trim();
  if (text === '' || text.includes(';')) {
    return false;
  }
  if (ID_PRELUDE.test(text) || text.startsWith('&') || text.startsWith(':') || text.startsWith('@')) {
    return true;
  }
  return canonicalSelector(text) !== null;
};

/**
 * One chunk from a csstree node, or null when the block is not closed
 * (mid-typing: the brace is still to come, nothing goes to the door yet).
 */
const nodeChunk = (text, node, kind) => {
  const from = node.loc.start.offset;
  const to = node.loc.end.offset;
  const slice = text.slice(from, to);
  if (!slice.trimEnd().endsWith('}')) {
    return null;
  }
  const brace = slice.indexOf('{');
  const prelude = brace === -1 ? '' : slice.slice(0, brace).trim();
  return { from, to, text: slice, kind, prelude, root: ID_PRELUDE.test(prelude) };
};

/**
 * Splits a Raw node's slice at its first readable block: the chunk and
 * the offset the tail resumes at, or null when the slice holds none.
 */
const rawChunk = (text, from, to) => {
  const slice = text.slice(from, to);
  const brace = slice.indexOf('{');
  if (brace === -1) {
    return null;
  }
  // The prelude runs back to the previous terminator: a Raw may start
  // with a declaration that lost its semicolon before the block.
  const before = slice.slice(0, brace);
  const start = before.lastIndexOf(';') + 1;
  const prelude = before.slice(start);
  if (!blockPrelude(prelude)) {
    return null;
  }
  const close = closingBrace(slice, brace);
  if (close === -1) {
    return null;
  }
  const leading = prelude.length - prelude.trimStart().length;
  const chunkFrom = from + start + leading;
  const chunkTo = from + close + 1;
  return {
    from: chunkFrom,
    to: chunkTo,
    text: text.slice(chunkFrom, chunkTo),
    kind: 'block',
    prelude: prelude.trim(),
    root: ID_PRELUDE.test(prelude.trim()),
  };
};

/**
 * The structural chunks in an island's text: every closed block the door
 * can read, in source order.
 *
 * @param {string} text
 *   The island's buffer text (or a clipboard's text).
 *
 * @return {Array<{from: number, to: number, text: string, kind: string,
 *   prelude: string, root: boolean}>}
 *   Each chunk's source range and text; `kind` is 'rule' (csstree Rule),
 *   'atrule' (csstree Atrule) or 'block' (a Raw split at a selector
 *   block); `root` marks a copied `#id { }` block, which the element
 *   document sends to the element's own key.
 */
export const structuralChunks = (text) => chunksFrom(text, 0);

/**
 * structuralChunks over a slice of the buffer starting at `base`.
 */
const chunksFrom = (text, base) => {
  let ast;
  try {
    ast = parseCss(text, { context: 'declarationList', positions: true });
  } catch {
    return [];
  }
  const chunks = [];
  ast.children.toArray().forEach((node) => {
    if (node.type === 'Rule' || node.type === 'Atrule') {
      const chunk = nodeChunk(text, node, node.type === 'Rule' ? 'rule' : 'atrule');
      if (chunk) {
        chunks.push({ ...chunk, from: chunk.from + base, to: chunk.to + base });
      }
    } else if (node.type === 'Raw') {
      const chunk = rawChunk(text, node.loc.start.offset, node.loc.end.offset);
      if (chunk) {
        chunks.push({ ...chunk, from: chunk.from + base, to: chunk.to + base });
        // The tail after the block is read again from a fresh parse: the
        // Raw swallowed it, and it may hold declarations or more blocks.
        const tail = text.slice(chunk.to, node.loc.end.offset);
        chunksFrom(tail, base + chunk.to).forEach((later) => chunks.push(later));
      }
    }
  });
  return chunks;
};

/**
 * The text with its chunks blanked to spaces, newlines kept.
 *
 * Same length as the original, so every offset a parse of the blanked
 * text reports is an offset in the original: the island lint reads the
 * declarations around a pending chunk as declarations.
 */
export const blankChunks = (text, chunks) => {
  let blanked = text;
  chunks.forEach(({ from, to }) => {
    const gap = blanked.slice(from, to).replace(/[^\n]/g, ' ');
    blanked = `${blanked.slice(0, from)}${gap}${blanked.slice(to)}`;
  });
  return blanked;
};

/**
 * Wraps a text under an island's condition as CSS nesting, on one line
 * each side so the door's line numbers stay the text's: at-rule wrappers
 * outermost in compound order, then `&` with the states glued, exactly
 * how the parser flattens nesting sugar (a wrapper's declarations read
 * at the wrapper's condition; `&:hover { }` adds the state).
 *
 * @param {string} text
 *   The fragment.
 * @param {string} condition
 *   The island's condition ('default' returns the text as is).
 * @param {?object} guide
 *   The style guide payload (conditions: name => {wrapper}).
 *
 * @return {string}
 */
export const conditionWrap = (text, condition, guide) => {
  if (!condition || condition === 'default') {
    return text;
  }
  const parts = preludeParts(null, condition, guide);
  const wrappers = parts.filter((part) => part.kind === WRAPPER).map((part) => part.text);
  const anchor = parts
    .filter((part) => part.kind !== WRAPPER)
    .map((part) => part.text)
    .join('')
    .trim();
  const open = wrappers.map((wrapper) => `${wrapper} { `).join('');
  const close = ' }'.repeat(wrappers.length);
  if (anchor === '' || anchor === '&') {
    return `${open}${text}${close}`;
  }
  return `${open}${anchor} { ${text} }${close}`;
};

/**
 * What to send the door for a text pasted into (or settled inside) an
 * island: the key and the css.
 *
 * A copied `#id { }` block carries its own conditions and rules, so the
 * element document sends it to the ELEMENT's key unwrapped (the door
 * re-roots the id to that key; under a rule key `#id` would continue the
 * rule by descent instead). Everything else is the island's own body:
 * it goes to the island's key, wrapped in the island's condition so bare
 * declarations land where they were pasted. A document island always
 * sends to its own anchoring key (its slot is the document's address;
 * the returned keys map back onto slots through documentSlot).
 *
 * @param {string} text
 *   The text to send.
 * @param {Array<object>} chunks
 *   structuralChunks(text).
 * @param {object} island
 *   {mapKey, rootKey, condition, guide, document}: the island's full key,
 *   its element's key, its condition, the guide payload and whether it
 *   is a variant document island.
 *
 * @return {{key: string, css: string}}
 */
export const doorRequest = (text, chunks, { mapKey, rootKey, condition, guide, document = false }) => {
  if (!document && chunks.some((chunk) => chunk.root)) {
    return { key: rootKey, css: text };
  }
  return { key: mapKey, css: conditionWrap(text, condition, guide) };
};

/**
 * The door's answer as one batch: an update_style op per entry (the
 * condition omitted at 'default', the wire's default) and an
 * apply_variant op per wear, plus the declaration count for the label.
 *
 * Additive by construction: an entry sets what it names and unsets
 * nothing (a pasted block never says "remove"; clearing is the row's ×).
 *
 * @param {Array<{key: string, condition: string, properties: object}>} entries
 *   The door's entries.
 * @param {Array<{key: string, variant: string}>} wear
 *   The door's wear tuples.
 *
 * @return {{operations: Array<object>, count: number}}
 */
export const entriesToOps = (entries = [], wear = []) => {
  const operations = [];
  let count = 0;
  entries.forEach(({ key, condition, properties }) => {
    const names = Object.keys(properties ?? {});
    if (!names.length) {
      return;
    }
    count += names.length;
    operations.push({
      op: 'update_style',
      key,
      ...(condition && condition !== 'default' ? { condition } : {}),
      properties: { ...properties },
    });
  });
  wear.forEach(({ key, variant }) => {
    operations.push({ op: 'apply_variant', key, variant });
  });
  return { operations, count };
};

/**
 * The document slot a returned entry stages at, for a document island
 * anchored at `mapKey` whose slot is `slot`: the island's own slot for
 * its own key, the slot continued by the entry's tail for a key the
 * paste nested under it (`::> pb-text` under an element key, ` > a`
 * under a rule key), null for a key outside the island (a stranger the
 * door rooted elsewhere; the caller refuses it).
 *
 * @param {string} entryKey
 *   The door entry's key.
 * @param {string} mapKey
 *   The island's anchoring key on the page.
 * @param {string} slot
 *   The island's document slot ('' the root, 'block(text,1)::.quote' a
 *   part under a path).
 *
 * @return {?string}
 */
export const documentSlot = (entryKey, mapKey, slot) => {
  if (entryKey === mapKey) {
    return slot;
  }
  if (!entryKey.startsWith(mapKey)) {
    return null;
  }
  const tail = entryKey.slice(mapKey.length);
  if (!tail.startsWith('::') && !tail.startsWith(' ')) {
    return null;
  }
  return `${slot}${tail}`;
};

/**
 * Maps the door's correctives onto buffer lines.
 *
 * The door's `context.line` is 1-based in the sent text. The sent text is
 * one or more segments of the buffer (the settle sends every chunk,
 * joined by newlines; a paste sends the clipboard whole, inserted at the
 * cursor when errors stand), each starting at a known buffer line, so a
 * line of the sent text falls in exactly one segment. A line the answer
 * does not carry, or one past the sent text, lands on the first segment's
 * first line (the parser's granularity for a declaration-level
 * corrective is the rule's opening brace, which is the wrapper's line).
 *
 * @param {Array<{message: string, hint?: string, context?: {line?: number}}>} errors
 *   The door's errors (BlueprintError::toArray()).
 * @param {Array<{startLine: number, lines: number}>} segments
 *   The sent text's segments: each one's first buffer line (1-based) and
 *   line count, in sent order.
 *
 * @return {Array<{line: number, message: string}>}
 *   Buffer line (1-based) and the message with its hint.
 */
export const errorLines = (errors, segments) => {
  const first = segments[0]?.startLine ?? 1;
  return errors.map((error) => {
    const message = [error.message, error.hint].filter(Boolean).join(' ');
    const line = Number(error.context?.line);
    if (!Number.isInteger(line) || line < 1) {
      return { line: first, message };
    }
    let offset = line - 1;
    for (let i = 0; i < segments.length; i += 1) {
      const segment = segments[i];
      if (offset < segment.lines) {
        return { line: segment.startLine + offset, message };
      }
      offset -= segment.lines;
    }
    return { line: first, message };
  });
};

/**
 * The line count of a text (a text without newlines is one line).
 */
export const lineCount = (text) => text.split('\n').length;
