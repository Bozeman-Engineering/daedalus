/**
 * Keyboard stitching for the islands code view (pure; the views wire it).
 *
 * Islands are separate CodeMirror instances by construction, so the
 * keyboard has to be stitched back into one continuous surface: arrows
 * traverse island boundaries as if the buffers were adjacent, and Tab
 * walks the DevTools Styles-pane chain — value, next declaration, a new
 * declaration at the island's end, then the next element's first island.
 * Chrome rows (headers, chips, ×, placeholders) are never part of the
 * chain: they carry tabindex="-1" in the views, and this module only ever
 * targets registered islands.
 *
 * Two halves live here, both DOM-light so node tests cover them:
 * - The tab-stop model over one island's flat declaration text
 *   (tabStops / nextTabTarget / previousTabTarget).
 * - The registry stitching islands together (createStitching): islands
 *   register their container element and a focus handler; neighbor lookup
 *   sorts by document position, so the chain follows render order — in
 *   the page tree that IS pageNodes' layout order, with each element's
 *   islands (default, conditions, parts) preceding its children's.
 *   The registry also carries the focus-restore memory: an island that
 *   unmounts focused parks its cursor here, and the remount claims it.
 */

/**
 * Node.DOCUMENT_POSITION_FOLLOWING, without depending on a DOM global.
 */
const DOCUMENT_POSITION_FOLLOWING = 4;

/**
 * The Tab stops of one island's text, in document order.
 *
 * Per non-blank line: a `property` stop at the first non-space character,
 * and — when the line holds a ':' — a `value` stop just past the colon
 * and its following spaces. Blank lines contribute nothing; the trailing
 * blank line is the "new declaration" slot, which is a Tab TARGET but not
 * a stop (nextTabTarget owns that step).
 *
 * @param {string} text
 *   The island's document text.
 *
 * @return {Array<{pos: number, type: string}>}
 *   Absolute positions, ascending.
 */
export const tabStops = (text) => {
  const stops = [];
  let from = 0;
  for (const line of String(text ?? '').split('\n')) {
    if (line.trim() !== '') {
      const indent = line.length - line.trimStart().length;
      stops.push({ pos: from + indent, type: 'property' });
      const colon = line.indexOf(':');
      if (colon !== -1) {
        let value = colon + 1;
        while (value < line.length && line[value] === ' ') {
          value += 1;
        }
        stops.push({ pos: from + value, type: 'value' });
      }
    }
    from += line.length + 1;
  }
  return stops;
};

/**
 * Where Tab goes from a position: the DevTools chain, one island's slice.
 *
 * The next stop after the cursor when one exists (property -> value ->
 * next declaration's property...); past the last stop, the new-declaration
 * offer — a move onto the trailing blank line when the buffer already ends
 * with one, otherwise `new-declaration` (the view appends the line); and
 * from the blank line itself, `exit` — the chain leaves for the next
 * island. An empty island is its own new-declaration offer, so Tab exits
 * immediately.
 *
 * @param {string} text
 *   The island's document text.
 * @param {number} pos
 *   The cursor head.
 *
 * @return {{type: string, pos?: number}}
 *   {type: 'stop', pos}, {type: 'new-declaration', pos} (append '\n' at
 *   pos, cursor after it), or {type: 'exit'}.
 */
export const nextTabTarget = (text, pos) => {
  const doc = String(text ?? '');
  const next = tabStops(doc).find((stop) => stop.pos > pos);
  if (next) {
    return { type: 'stop', pos: next.pos };
  }
  const lastLineFrom = doc.lastIndexOf('\n') + 1;
  if (doc.slice(lastLineFrom).trim() === '') {
    return pos >= lastLineFrom
      ? { type: 'exit' }
      : { type: 'stop', pos: lastLineFrom };
  }
  return { type: 'new-declaration', pos: doc.length };
};

/**
 * Where Shift-Tab goes from a position: the chain, reversed.
 *
 * The last stop before the cursor, or `exit` — the chain leaves for the
 * previous island's last stop.
 *
 * @return {{type: string, pos?: number}}
 */
export const previousTabTarget = (text, pos) => {
  const stops = tabStops(String(text ?? ''));
  for (let index = stops.length - 1; index >= 0; index -= 1) {
    if (stops[index].pos < pos) {
      return { type: 'stop', pos: stops[index].pos };
    }
  }
  return { type: 'exit' };
};

/**
 * The island registry: one per code-view root, shared by all its islands.
 *
 * register() ties an island id to its container element and a focus
 * handler; focusNeighbor() finds the next/previous CONNECTED island in
 * document order and hands it the cursor with a placement the island
 * resolves against its own buffer (edges, goal column, first/last tab
 * stop). rememberFocus()/claimFocus() are the re-render seam: an island
 * torn down while focused parks {id, head} here, and the island that
 * mounts under the same id claims it — chrome and unrelated islands never
 * inherit a stale cursor because the claim is id-exact.
 *
 * @return {object}
 *   The registry.
 */
export const createStitching = () => {
  const entries = new Map();
  let pendingFocus = null;

  const ordered = () =>
    [...entries.values()]
      .filter((entry) => entry.element && entry.element.isConnected)
      .sort((a, b) =>
        // compareDocumentPosition() answers with a bitmask.
        // eslint-disable-next-line no-bitwise
        a.element.compareDocumentPosition(b.element) &
        DOCUMENT_POSITION_FOLLOWING
          ? -1
          : 1,
      );

  return {
    register(id, { element, focus }) {
      const entry = { id, element, focus };
      entries.set(id, entry);
      return () => {
        if (entries.get(id) === entry) {
          entries.delete(id);
        }
      };
    },
    order() {
      return ordered().map((entry) => entry.id);
    },
    focusNeighbor(id, direction, placement = {}) {
      const list = ordered();
      const index = list.findIndex((entry) => entry.id === id);
      const target = index === -1 ? null : list[index + direction];
      if (!target) {
        return false;
      }
      target.focus(placement);
      return true;
    },
    rememberFocus(id, head) {
      pendingFocus = { id, head };
    },
    claimFocus(id) {
      if (pendingFocus && pendingFocus.id === id) {
        const { head } = pendingFocus;
        pendingFocus = null;
        return { head };
      }
      return null;
    },
  };
};
