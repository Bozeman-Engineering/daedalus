import { rowMenu } from '../../provenance-model.js';
import { useMenu } from '../../menu.js';
import { previewPair } from '../../preview-pair.js';
import { lawDocument, skinDocument } from '../../code/layers.js';

/**
 * The one dispatch of a row's provenance doorways, and the menu spec
 * over it.
 *
 * Every property row (the advanced row, the choice row, the two color
 * wells, the stroke row's three parts, the padding row and the box
 * model's side editors) opens the same right-click menu
 * (provenance-model.js rowMenu: the origin in words, its doorways, the
 * "Also set at" group, the token items), and every doorway means the
 * same thing wherever it opens: `drive` poses the canvas, `document`
 * opens the supplying variant's session at the wearer the doorway
 * names (the carrier of a variant an ancestor's rule wears, else the
 * host's wear key), `ancestor` seats the ancestor's rule as the styling
 * target on the source (the carrier for a worn value: the selection
 * stays put) and, while it is the menu's active item, previews the
 * rule's reach on the canvas (preview-pair.js, the hover-selector facet
 * the address chip's entries drive the same way), `unset` is the row's
 * own unset gesture, `skin` opens the
 * editing session on the skin's document at the supplying scope (the
 * same openDocument, handed the skin document's reference, code/layers.js
 * skinDocument, at the delivering condition and the host's own anchor),
 * `declared` opens the Materials place's Law tab at the supplying
 * declaration with the property's rows revealed (the same openDocument,
 * handed a law document reference, code/layers.js lawDocument: the
 * Inspector routes it to the tab, never a session),
 * `winner` is the session's jump to the layer that
 * beats the document, `element` drops the write address on the source
 * (address(null): the subject stays, and the element's own value, above
 * the address's editable layer, is edited on the element again). The
 * rows differ only in what
 * their unset removes and in which token items their field offers, so
 * those are the inputs; the dispatch itself lives here once, and a row
 * with no source (a satellite-safe row handed none) simply has inert
 * ancestor and element doorways.
 *
 * @param {object} input
 * @param {?object} input.origin
 *   choice-row-model.js originFor() / provenance-model.js originOf(),
 *   or null for a row with no origin story (the padding row's shorthand
 *   field: four sides can have four origins).
 * @param {Array<object>|Function} [input.tokens]
 *   provenance-model.js tokenItems(), or a thunk answering them: the
 *   items are built on open, never per render.
 * @param {string|Function} [input.face]
 *   The shown display value (or a thunk), the header for a row with no
 *   origin; a row with one always heads its menu with the origin's
 *   sentence.
 * @param {?object} [input.source]
 *   The state source whose `address(key, selector)` seats the ancestor
 *   doorway's rule as the write address and whose `address(null)` drops
 *   it for the element doorway; its `hoverSelector` carries the
 *   ancestor doorway's preview.
 * @param {?Function} [input.onOpenDocument]
 *   (reference, condition, anchor): the document doorway and the skin
 *   doorway both open through it.
 * @param {?Function} [input.onPickCondition]
 *   (condition): the drive doorway and the "Also set at" entries.
 * @param {?Function} [input.onUnset]
 *   The row's unset gesture; a row that refuses it (withheld, nothing
 *   own at the pose) decides inside.
 * @param {?Function} [input.onWinner]
 *   (winner): the session's winner doorway.
 *
 * @return {?{header: ?string, items: Array<object>}}
 *   menu.js useMenu().open() spec parts, or null when there is nothing
 *   to show (the native menu rides through).
 */
export const rowMenuSpec = ({
  origin = null,
  tokens = [],
  face = '',
  source = null,
  onOpenDocument = null,
  onPickCondition = null,
  onUnset = null,
  onWinner = null,
}) => {
  const onDoorway = (doorway) => {
    switch (doorway.id) {
      case 'drive':
        onPickCondition?.(doorway.condition);
        break;
      case 'document':
        onOpenDocument?.(doorway.reference, doorway.condition, doorway.anchor);
        break;
      case 'ancestor':
        source?.address?.(doorway.key, doorway.selector);
        break;
      case 'element':
        source?.address?.(null);
        break;
      case 'unset':
        onUnset?.();
        break;
      case 'skin':
        onOpenDocument?.(skinDocument(doorway.scope), doorway.condition, null);
        break;
      case 'declared':
        onOpenDocument?.(lawDocument(doorway.scope, doorway.property), null, null);
        break;
      case 'winner':
        onWinner?.(doorway.winner);
        break;
      default:
        break;
    }
  };
  return rowMenu({
    origin,
    tokens: typeof tokens === 'function' ? tokens() : tokens,
    face: typeof face === 'function' ? face() : face,
    onDoorway,
    onPreview: (pair, active) => previewPair(source, pair, active),
    onCondition: onPickCondition,
  });
};

/**
 * The row's right-click handler over rowMenuSpec through the shared
 * menu primitive (menu.js useMenu): opens the menu at the pointer and
 * consumes the event (no native menu, no enclosing row's menu: a stroke
 * part's menu inside the stroke row, a side editor inside the diagram);
 * with nothing to show the event rides through untouched.
 *
 * @param {object} input
 *   rowMenuSpec's input.
 *
 * @return {Function}
 *   (event) => void, for the row's `onContextMenu`.
 */
export const useRowMenu = (input) => {
  const menu = useMenu();
  return (event) => {
    const spec = rowMenuSpec(input);
    if (spec && menu.open({ x: event.clientX, y: event.clientY, ...spec })) {
      event.preventDefault();
      event.stopPropagation();
    }
  };
};
