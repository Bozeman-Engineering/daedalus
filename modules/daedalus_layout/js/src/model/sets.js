/**
 * @file
 * The sets: the Add panel as one list of folding sets.
 *
 * Favorites first, then each group that holds anything, in the
 * vocabulary's declaration order, then All, which holds everything once,
 * nested by kind: Basics (the Container and the block types),
 * Blueprints, then core's block categories in label order. A face shows
 * in every set it belongs to. There is no Favorites and All switch and
 * there are no chips: a set is a heading that folds, with its count.
 *
 * A set is `{id, label, count, items, children?, description?}`:
 * - `id` is the set's key and its row in the fold outline (outline.js):
 *   FAVORITES_ID, `group:{id}`, ALL_ID, and `kind:{kind}` for All's
 *   children.
 * - `items` are the set's faces in label order (order.js), the Container
 *   leading Favorites. All's own `items` are every face once; its rows
 *   are drawn through its children, so `items` there is the count's
 *   source and the keyboard's list, not a second rendering.
 * - `count` is what the heading shows: the number of faces in the set.
 *
 * Favorites is the one set drawn when empty (it carries the panel's
 * sentence about starring); a group with nothing in it on this canvas
 * is not drawn, and neither is a kind under All with nothing in it, so a
 * heading never reads "0". A face's group id the site does not declare
 * files it nowhere: the sets are built over the vocabulary, never over
 * the ids faces carry (a shipped blueprint from a package whose groups
 * this site lacks keeps its ids and shows under All alone).
 *
 * The fixed headings' words (Favorites, All, Basics, Blueprints) are the
 * component's to translate: it passes them in as `words`, so the model
 * never reads Drupal.t. A group's label and a category's are already the
 * site's words.
 *
 * Pure: no DOM, no Preact.
 */

import { CONTAINER_ID, KIND_BASIC, KIND_BLUEPRINT, isFavorite } from './faces.js';
import { byLabel, compareLabels } from './order.js';

/**
 * The Favorites set's id.
 */
export const FAVORITES_ID = 'favorites';

/**
 * The All set's id.
 */
export const ALL_ID = 'all';

/**
 * The words of the fixed headings, in English; the component translates.
 */
export const DEFAULT_WORDS = Object.freeze({
  favorites: 'Favorites',
  all: 'All',
  basics: 'Basics',
  blueprints: 'Blueprints',
});

/**
 * A group's set id.
 */
export const groupSetId = (groupId) => `group:${groupId}`;

/**
 * A kind's set id under All.
 */
export const kindSetId = (kind) => `kind:${kind}`;

/**
 * The kind a face lives under in All: its set id and its label.
 *
 * This is what a search names beside each hit ("where it lives").
 *
 * @param {object} face
 *   A face.
 * @param {object} words
 *   The fixed headings' words.
 *
 * @return {{id: string, label: string}}
 */
export const kindOf = (face, words = DEFAULT_WORDS) => {
  if (face.kind === KIND_BASIC) {
    return { id: kindSetId(KIND_BASIC), label: words.basics };
  }
  if (face.kind === KIND_BLUEPRINT) {
    return { id: kindSetId(KIND_BLUEPRINT), label: words.blueprints };
  }
  return { id: kindSetId(face.kind), label: face.kind };
};

const set = (id, label, items, extra = {}) => ({
  id,
  label,
  count: items.length,
  items,
  ...extra,
});

/**
 * All's children: Basics, Blueprints, then the categories by label.
 */
const kindsOf = (faces, words) => {
  const buckets = new Map();
  faces.forEach((face) => {
    const kind = kindOf(face, words);
    if (!buckets.has(kind.id)) {
      buckets.set(kind.id, { ...kind, items: [] });
    }
    buckets.get(kind.id).items.push(face);
  });
  const fixed = [kindSetId(KIND_BASIC), kindSetId(KIND_BLUEPRINT)];
  const categories = [...buckets.values()]
    .filter((kind) => !fixed.includes(kind.id))
    .sort((a, b) => compareLabels(a.label, b.label));
  return [...fixed.map((id) => buckets.get(id)).filter(Boolean), ...categories]
    .map((kind) => set(kind.id, kind.label, byLabel(kind.items)));
};

/**
 * The sets, in the order the panel draws them.
 *
 * @param {Array<object>} faces
 *   Every face (faces.js faceList).
 * @param {Array<{id: string, label: string, description?: string}>} groups
 *   The site's vocabulary in declaration order (AddPanelAnswer's
 *   `groups`).
 * @param {?object} marks
 *   The editor's marks.
 * @param {object} words
 *   The fixed headings' words.
 *
 * @return {Array<object>}
 */
export const buildSets = (faces, groups, marks, words = DEFAULT_WORDS) => {
  const favorites = set(
    FAVORITES_ID,
    words.favorites,
    byLabel(faces.filter((face) => isFavorite(face, marks)), CONTAINER_ID),
  );
  const groupSets = (groups ?? [])
    .map((group) =>
      set(
        groupSetId(group.id),
        String(group.label ?? group.id),
        byLabel(faces.filter((face) => face.groups.includes(String(group.id)))),
        { description: String(group.description ?? '') },
      ),
    )
    .filter((group) => group.count > 0);
  const all = set(ALL_ID, words.all, byLabel(faces), {
    children: kindsOf(faces, words),
  });
  return [favorites, ...groupSets, all];
};
