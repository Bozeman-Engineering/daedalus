/**
 * Instance rows: annotation-derived rows for minted rich-text elements.
 *
 * Declared anatomy sketches STRUCTURE, the wrapper chain a type's markup
 * model declares, known without looking at the page. Identity-carrying
 * rich text is the opposite: the semantic elements the editor produces
 * (paragraphs, headings, block quotes) exist only in the CONTENT, each
 * wearing a short identity class minted into the stored field HTML
 * server-side. Those stored values are entity data, so the instance model
 * covers them like every other markup fact: the annotator enumerates each
 * owner's carriers into `_element.markup.instances` ({class, tag, label,
 * text?, children}, labels stamped translated, no client tag-vocabulary
 * copy), and this module projects them into the tree's row seam — for
 * BOTH projections. Instance rows are content, not markup (a freshly
 * placed empty block would have none), so each seam row they become is
 * stamped `source: 'instance'` (markup-view.js toInstanceRow), and
 * reach (reach.js depicted) draws and reaches an instance source in
 * every view: the default model projection appends them too
 * (withInstanceRows), tag-less, and the tree's draw filter keeps them
 * whatever the Show menu says. Only shell rows, generated-box rows and
 * tag facts — developer-declared anatomy and the cascade — carry the
 * markup view's source and ride its toggle.
 *
 * A carrier's row reads like an element's: an icon (what it is, from the
 * tag it rendered as: row-glyphs.js), then a name (what it says: its
 * content excerpt, the first-line/80-char rule every content label in the
 * tree follows). Its vocabulary word ("Paragraph", "List item", stamped by
 * the annotator) is its `elementType`, the row's tooltip, and names the
 * row outright where the excerpt would only repeat its rows: a list's
 * text is its items' text, which the item rows already say, so a list
 * reads as its word; an empty carrier reads as its word too.
 *
 * Ownership mirrors the DOM by construction: a composed block's carriers
 * ride the block's own markup (its render is one element), an open
 * render's carriers ride the owning field's annotation, the same node
 * whose selectable element contains them on the canvas. Liveness rides
 * the mirror: every mutation response re-annotates, and the tree renders
 * from the mirror, so committed edits re-row with no DOM observation.
 * Mid-gesture content (typing before blur commits) is ahead of the model
 * until its op round-trips, the annotation is canonical, the canvas is a
 * projection, and the verification observer (markup-verify.js) logs the
 * drift instead of re-deriving rows from it.
 */

import { markupForKey } from '../../blueprint/markup-index';
import { textExcerpt } from './compose';
import { tagGlyph } from './row-glyphs';

/**
 * The carriers whose text is only their rows' text: a list wrapper reads
 * as its word, never as the run-on of every item.
 */
const WRAPPER_TAGS = new Set(['ul', 'ol', 'dl']);

/**
 * One annotation instance as a markup-view row.
 */
const toRow = (instance) => {
  const word = instance.label ?? instance.tag ?? instance.class;
  const excerpt = WRAPPER_TAGS.has(instance.tag)
    ? null
    : textExcerpt(instance.text ?? '');
  const row = {
    selector: `.${instance.class}`,
    label: excerpt ?? word,
    elementType: word,
    icon: tagGlyph(instance.tag ?? null),
    children: (Array.isArray(instance.children) ? instance.children : []).map(
      toRow,
    ),
  };
  if (instance.tag) {
    // The carrier's tag fact: the annotation resolved it server-side, so
    // no client accessor is asked (unlike shell rows).
    row.tag = instance.tag;
  }
  return row;
};

/**
 * The instance rows a model key owns, from its markup annotation.
 *
 * The markup view's instance source (toViewTree's third argument): called
 * per keyed model row at projection time. A key the model does not speak
 * for (or with no carriers) owns nothing.
 *
 * @param {?string} key
 *   The selectable key.
 *
 * @return {Array<{selector: string, label: string, elementType: string,
 *   icon: string, tag?: string, children: Array}>}
 */
export const instanceRows = (key) => {
  const instances = markupForKey(key)?.instances;
  return Array.isArray(instances) ? instances.map(toRow) : [];
};
