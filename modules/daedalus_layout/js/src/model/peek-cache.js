/**
 * @file
 * The peek's cache: each row's answer remembered for the page load.
 *
 * A peek is a dry run of a row's drop on the staged working copy,
 * answered by the peek door as a bare document (../peek.js). The row's
 * drop batch differs on every call (a fresh uuid for the placed element),
 * so the answer cannot be keyed on the request; it is keyed on the row's
 * stable identity, its face id (a block plugin id or `blueprint:<id>`),
 * the same face in Favorites and All composing the same drop. Holding
 * the document under that key, a second rest on a row already peeked
 * shows it at once and asks the server nothing.
 *
 * What makes a held answer stale is the caller's to decide, and the
 * caller tells this model: `clear()` when everything may have moved
 * (this session's staged copy changed: a mutation, undo, redo or history
 * jump), `drop(predicate)` when a family of rows may have (every
 * `blueprint:*` face on a materials delivery, since a record does not
 * say what changed inside it). The model never watches anything and
 * never expires an entry on its own; what it holds, it holds until told.
 *
 * Nothing survives a page load: the panel builds one of these when it
 * mounts and it lives as long as the panel's markup does. Refusals are
 * not held (the caller stores a document, never an error) and whether a
 * hit is shown is still the dwell's call (../model/dwell.js): a sweep
 * must not flash cached frames.
 *
 * Pure: no DOM, no Preact, no Drupal.
 */

/**
 * Builds a peek cache.
 *
 * @return {{get: Function, put: Function, drop: Function, clear: Function, size: Function}}
 *   `get(faceId)` answers the held document or undefined; `put(faceId,
 *   document)` holds a document for a face, replacing one already held;
 *   `drop(predicate)` sheds every face `predicate(faceId)` answers true
 *   for and answers how many went; `clear()` empties; `size()` counts
 *   the faces held.
 */
export const createPeekCache = () => {
  const documents = new Map();

  const drop = (predicate) => {
    let dropped = 0;
    [...documents.keys()]
      .filter((faceId) => predicate(faceId))
      .forEach((faceId) => {
        documents.delete(faceId);
        dropped += 1;
      });
    return dropped;
  };

  return {
    get: (faceId) => documents.get(faceId),
    put: (faceId, document) => {
      documents.set(faceId, document);
    },
    drop,
    clear: () => {
      documents.clear();
    },
    size: () => documents.size,
  };
};
