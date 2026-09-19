/**
 * The one decoration source for island lines (pure; node-tested).
 *
 * Maps an island's lines to what each line WEARS, independent of posture:
 * the live CodeMirror view turns marks into Decoration.line + widgets, the
 * printed posture turns the same marks into static DOM. If each posture
 * derived classes itself they would drift; a line wears the same marks
 * whichever posture shows it.
 *
 * Line vocabulary (the class names are the printed/CM contract):
 * - lost: the line strikes through in place and carries the winner chip
 *   naming the layer whose value beats it (the cascade story is true on
 *   every surface, element and document islands alike).
 * - variant (element mode, tints on): a variant-supplied line washes in
 *   its layer's identity hue, hover names the supplier — identity duty,
 *   never focus chrome. Document islands print one sheet, no provenance.
 * - floor (element mode, tints on): a line the defaults floor supplies
 *   (the active skin's defaults, or the declaration's own) washes in the
 *   floor's hue (SKIN_COLOR, both tiers), hover names the tier. The same
 *   tints preference gates it: off prints every non-own line plain.
 * - pending (document islands): a staged edit wears the focus treatment
 *   plus the dismiss mark; dismissing re-prints the reverted sheet, whose
 *   lines wear no pending mark again.
 */

import {
  DECLARED_LAYER,
  ELEMENT_LAYER,
  SKIN_LAYER,
  layerColor,
  lineLayerId,
  winnerLayerId,
} from './layers.js';
import { findVariant } from '../variant-scope.js';

/**
 * The hover title naming a non-own line's supplier.
 */
const supplierTitle = (layer, line, library) => {
  if (layer === SKIN_LAYER) {
    return Drupal.t('Supplied by the active skin.');
  }
  if (layer === DECLARED_LAYER) {
    return Drupal.t("Supplied by the declaration's defaults.");
  }
  return Drupal.t('Supplied by the variant "@label".', {
    '@label': findVariant(library, line.variant)?.label ?? line.variant,
  });
};

/**
 * The winner chip's label for a lost line's winning layer.
 */
const winnerLabel = (winnerId, winner, library) => {
  if (winnerId === ELEMENT_LAYER) {
    return Drupal.t('this element');
  }
  if (winnerId === SKIN_LAYER) {
    return Drupal.t('the active skin');
  }
  if (winnerId === DECLARED_LAYER) {
    return Drupal.t("the declaration's defaults");
  }
  return findVariant(library, winner.variant)?.label ?? winner.variant;
};

/**
 * What each line wears.
 *
 * @param {Array<?object>} lines
 *   islandLines()/overlaySessionLines() line shapes ({property, value,
 *   layer, variant, lost, winner, pending?, pendingId?}). Holes
 *   (null/undefined, an unmatched, mid-edit declaration) yield null
 *   marks: an override-in-the-making prints plain.
 * @param {object} context
 *   - library: the variant library (supplier labels; falls back to the
 *     raw reference when a variant is not in it).
 *   - sheet: true for a document island (one variant sheet: pending marks
 *     apply, provenance tints do not), false for an element island.
 *   - tints: 'on'|'off' — the line-tints preference (lineTints store);
 *     off prints variant and floor lines plain, the wear bar still names
 *     the variants.
 *
 * @return {Array<?{classes: string[], attributes: ?{style: string,
 *   title: string}, winner: ?{label: string, color: string},
 *   pending: ?{kind: string, id: string}}>}
 *   One entry per input line, index-aligned; null for a hole.
 */
export const lineMarks = (
  lines,
  { library = [], sheet = false, tints = 'on' } = {},
) =>
  (Array.isArray(lines) ? lines : []).map((line) => {
    if (!line) {
      return null;
    }
    const classes = [];
    let attributes = null;
    let winner = null;
    let pending = null;
    if (line.lost) {
      classes.push('daedalus-stylish-island__line--lost');
    }
    const layer = lineLayerId(line);
    if (!sheet && layer !== ELEMENT_LAYER && tints === 'on') {
      classes.push(
        layer === SKIN_LAYER || layer === DECLARED_LAYER
          ? 'daedalus-stylish-island__line--floor'
          : 'daedalus-stylish-island__line--variant',
      );
      attributes = {
        style: `--daedalus-stylish-line-hue: ${layerColor(layer)}`,
        title: supplierTitle(layer, line, library),
      };
    }
    if (sheet && line.pending) {
      classes.push('daedalus-stylish-island__line--pending');
      pending = { kind: 'dismiss', id: line.pendingId };
    }
    if (line.lost) {
      const winnerId = winnerLayerId(line.winner);
      winner = {
        label: winnerLabel(winnerId, line.winner, library),
        color: layerColor(winnerId),
      };
    }
    return { classes, attributes, winner, pending };
  });
