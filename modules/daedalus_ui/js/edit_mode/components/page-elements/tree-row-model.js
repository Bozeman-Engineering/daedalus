import { selectableIn } from '../../selection/capable-model.js';

/**
 * The tree row's posture toward the actor, pure.
 *
 * A row is INERT when it names nothing selectable: a keyless row (a
 * uuid-less section, a region) as before, and now an element the actor
 * can do nothing to (selection/capable-model.js selectableIn: a
 * template's elements on a page that renders but does not own them, a
 * frozen translation's structure). An inert row is still drawn, in the
 * muted treatment, and still expands (the page elements panel shows
 * everything as it normally does, so the editor can see what the page
 * is made of), but it offers no gesture: no hover, no click, no context
 * menu, no drag source, no rename, no Space activation. Its selectable
 * descendants (a field block presenting one of the page's own fields
 * inside a template container) keep theirs.
 *
 * @param {?Map<string, {can?: ?object}>} index
 *   The projection index, or null before the mirror boots.
 * @param {?string} key
 *   The row's key, or null for a keyless row.
 *
 * @return {boolean}
 */
export const rowInert = (index, key) => !key || !selectableIn(index, key);
