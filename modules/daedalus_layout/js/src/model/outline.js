/**
 * @file
 * The fold outline: the sets in the shape daedalus_ui's collapse model
 * takes.
 *
 * The panel folds with the one posture rule every outline the UI folds
 * adapts to (`daedalus_ui/js/outline/collapse.js`: `explicit ??
 * default`, over an outline of `{id, foldable, folded, children}`). This
 * file projects the sets (sets.js) into that outline per render; the
 * component holds the explicit postures (`folds`) as its state, seeded
 * from the fold store (fold-store.js) and written back through it.
 *
 * The defaults are the decision's: Favorites open, every other set
 * folded, All's kinds included. Every heading is foldable, so the shared
 * expand-all and collapse-all pair reaches all of them.
 *
 * Pure: no DOM, no Preact.
 */

import { FAVORITES_ID } from './sets.js';

/**
 * The outline of the sets.
 *
 * @param {Array<object>} sets
 *   The sets (sets.js buildSets).
 *
 * @return {Array<{id: string, foldable: boolean, folded: boolean, children: Array}>}
 */
export const outlineOf = (sets) =>
  (sets ?? []).map((set) => ({
    id: set.id,
    foldable: true,
    folded: set.id !== FAVORITES_ID,
    children: (set.children ?? []).map((kind) => ({
      id: kind.id,
      foldable: true,
      folded: true,
      children: [],
    })),
  }));
