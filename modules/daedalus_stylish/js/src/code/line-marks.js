/**
 * The one decoration source for island lines (pure; node-tested).
 *
 * Maps an island's lines to what each line WEARS, independent of posture:
 * the live CodeMirror view turns marks into Decoration.line + widgets, the
 * printed posture turns the same marks into static DOM. If each posture
 * derived classes itself they would drift; a line wears the same marks
 * whichever posture shows it.
 *
 * Provenance is the primary reading of an element island, as a lens: the
 * lines already print in cascade order (the floor, the worn layers, the
 * own layer), so the marks name what that order is without reorganizing
 * a line. Every line carries its layer's hue (`--daedalus-stylish-line-hue`,
 * the rail's color); the first line of each layer RUN (consecutive lines
 * of one layer) carries the run label, the layer's word once above its
 * lines, which is also the handle the run menu opens on (line-menu.js
 * runMenuItems: the layer's doorways); a line the element did not author
 * is delivered, italic and muted, so the editable layer is the loudest
 * thing on the panel. Holes (an unmatched, mid-edit declaration) are
 * transparent to a run: the run continues past them.
 *
 * Line vocabulary (the class names are the printed/CM contract):
 * - lost: the line strikes through in place and carries the winner chip
 *   naming the layer whose value beats it (the cascade story is true on
 *   every surface, element and document islands alike). A rule island's
 *   line the selected element beats wears the same strike and chip
 *   through `beaten` (element-model.js ruleIslandLines: the beating
 *   layer's winner, display only, so the rule's own diff stays blind).
 * - delivered (element islands): a line a layer beneath the own layer
 *   supplies (a worn variant's, the defaults floor's at one declaring
 *   scope, an ancestor rule's own or worn line), hover naming the
 *   supplier. Document islands print one sheet, no provenance.
 * - pending (document islands): a staged edit wears the focus treatment
 *   plus the dismiss mark; dismissing re-prints the reverted sheet, whose
 *   lines wear no pending mark again.
 */

import {
  ELEMENT_LAYER,
  TIER_DECLARED,
  TIER_SKIN,
  ancestorRule,
  ancestorVariant,
  layerColor,
  layerScope,
  layerTier,
  lineLayerId,
  winnerLayerId,
} from './layers.js';
import { findVariant } from '../variant-scope.js';
import { declarationLabel } from '../provenance-model.js';

/**
 * A variant's label for the titles and chips: the library's, or the raw
 * reference when the library lacks it.
 */
const variantLabel = (library, reference) =>
  findVariant(library, reference)?.label ?? reference;

/**
 * A floor scope in the run label's words: the declaring scope as the
 * provenance sentences spell it ("text block type", "container kind"),
 * the skin's base:{tag} block as its tag, an unknown scope as itself.
 */
const scopeWords = (scope) => {
  if (typeof scope === 'string' && scope.startsWith('base:')) {
    return Drupal.t('@tag elements', { '@tag': scope.slice('base:'.length) });
  }
  return declarationLabel(scope) ?? scope;
};

/**
 * The hover title naming a delivered line's supplier: a floor line names
 * its tier and the declaring scope its layer id carries (the skin's
 * `kind:block` block, its `base:p` block, the declaration's
 * `block_type:text` defaults), an ancestor rule's own line the rule and
 * its owner, a worn ancestor line its variant and the rule position the
 * value arrives through (a line carries its layer id, whose tail is the
 * position; the carrier that wears the variant is the layer's, read by
 * the provenance row), a variant line its variant.
 */
const supplierTitle = (layer, line, library) => {
  const tier = layerTier(layer);
  if (tier === TIER_SKIN) {
    return Drupal.t('Supplied by the active skin (@scope).', {
      '@scope': layerScope(layer),
    });
  }
  if (tier === TIER_DECLARED) {
    return Drupal.t("Supplied by the declaration's defaults (@scope).", {
      '@scope': layerScope(layer),
    });
  }
  const rule = ancestorRule(layer);
  if (rule) {
    const worn = ancestorVariant(layer);
    return worn === null
      ? Drupal.t('Inherited from the rule "@selector" on @owner.', {
          '@selector': rule.selector,
          '@owner': rule.key,
        })
      : Drupal.t(
          'Supplied by the variant "@label" through the rule "@selector" on @owner.',
          {
            '@label': variantLabel(library, worn),
            '@selector': rule.selector,
            '@owner': rule.key,
          },
        );
  }
  return Drupal.t('Supplied by the variant "@label".', {
    '@label': variantLabel(library, line.variant),
  });
};

/**
 * The run label's words: the layer named once above its lines.
 *
 * The own layer is "this element" (or "this rule" on a rule island,
 * whose own layer is the rule's entry); a floor layer its tier and
 * declaring scope ("skin, text block type"; "declared, container
 * kind"); any other layer the variant its line carries. The ancestor
 * tier has no word of its own: it never prints on an island
 * (element-model.js ownIslandLines drops it, every rule reaching the
 * element printing as a rule island whose own run reads "this rule"),
 * so a worn ancestor line's run would read its variant's label and an
 * own ancestor line's run nothing.
 */
const runLabel = (layer, line, { library, own }) => {
  if (layer === ELEMENT_LAYER) {
    return own === 'rule' ? Drupal.t('this rule') : Drupal.t('this element');
  }
  const tier = layerTier(layer);
  if (tier === TIER_SKIN) {
    return Drupal.t('skin, @scope', { '@scope': scopeWords(layerScope(layer)) });
  }
  if (tier === TIER_DECLARED) {
    return Drupal.t('declared, @scope', {
      '@scope': scopeWords(layerScope(layer)),
    });
  }
  return variantLabel(library, line.variant);
};

/**
 * The winner chip's label for a lost line's winning layer: the tier for
 * a floor winner (the chip is short; the winning line's own hover title
 * names the scope), the rule for an ancestor rule's own winner, the
 * variant's label for a variant winner, an ancestor's worn one included.
 */
const winnerLabel = (winnerId, winner, library) => {
  if (winnerId === ELEMENT_LAYER) {
    return Drupal.t('this element');
  }
  const tier = layerTier(winnerId);
  if (tier === TIER_SKIN) {
    return Drupal.t('the active skin');
  }
  if (tier === TIER_DECLARED) {
    return Drupal.t("the declaration's defaults");
  }
  const rule = ancestorRule(winnerId);
  if (rule) {
    const worn = ancestorVariant(winnerId);
    return worn === null
      ? Drupal.t('an ancestor\'s "@selector" rule', {
          '@selector': rule.selector,
        })
      : Drupal.t('"@label" through an ancestor\'s rule', {
          '@label': variantLabel(library, worn),
        });
  }
  return variantLabel(library, winner.variant);
};

/**
 * What each line wears.
 *
 * @param {Array<?object>} lines
 *   stackLines()/overlaySessionLines() line shapes ({property, value,
 *   layer, variant, lost, winner, beaten?, pending?, pendingId?}). Holes
 *   (null/undefined, an unmatched, mid-edit declaration) yield null
 *   marks: an override-in-the-making prints plain.
 * @param {object} context
 *   - library: the variant library (supplier labels; falls back to the
 *     raw reference when a variant is not in it).
 *   - sheet: true for a document island (one variant sheet: pending marks
 *     apply, provenance does not), false for an element island.
 *   - own: 'element' or 'rule', the own layer's word (a rule island's
 *     own layer is the rule's entry).
 *
 * @return {Array<?{classes: string[], attributes: ?{style: string,
 *   title?: string}, winner: ?{label: string, color: string},
 *   pending: ?{kind: string, id: string}, run: ?{label: string,
 *   layer: string, color: string, first: true}}>}
 *   One entry per input line, index-aligned; null for a hole.
 */
export const lineMarks = (
  lines,
  { library = [], sheet = false, own = 'element' } = {},
) => {
  let previousLayer = null;
  return (Array.isArray(lines) ? lines : []).map((line) => {
    if (!line) {
      return null;
    }
    const classes = [];
    let attributes = null;
    let winner = null;
    let pending = null;
    let run = null;
    // The strike: lost inside the island's own cascade, or beaten on the
    // selected element by a layer above a rule island's document.
    const beatenBy = line.lost ? line.winner : (line.beaten ?? null);
    if (beatenBy) {
      classes.push('daedalus-stylish-island__line--lost');
    }
    const layer = lineLayerId(line);
    if (!sheet) {
      const color = layerColor(layer);
      attributes = { style: `--daedalus-stylish-line-hue: ${color}` };
      if (layer !== ELEMENT_LAYER) {
        classes.push('daedalus-stylish-island__line--delivered');
        attributes.title = supplierTitle(layer, line, library);
      }
      if (layer !== previousLayer) {
        run = {
          label: runLabel(layer, line, { library, own }),
          layer,
          color,
          first: true,
        };
      }
      previousLayer = layer;
    }
    if (sheet && line.pending) {
      classes.push('daedalus-stylish-island__line--pending');
      pending = { kind: 'dismiss', id: line.pendingId };
    }
    if (beatenBy) {
      const winnerId = winnerLayerId(beatenBy);
      winner = {
        label: winnerLabel(winnerId, beatenBy, library),
        color: layerColor(winnerId),
      };
    }
    return { classes, attributes, winner, pending, run };
  });
};
