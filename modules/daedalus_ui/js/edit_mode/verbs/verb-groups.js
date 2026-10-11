/**
 * Verb groups: the registry's pure fold of a menu-ordered verb list into
 * the entries a face draws. No DOM, no store, no Drupal globals, so the
 * rule is node-testable on its own (tests/js/verb-groups.test.mjs).
 *
 * A verb's `group` spec key (verbs.js register()) names the control it
 * shares with the other verbs carrying the same id: one control where a
 * face can draw one, plain members everywhere else. This fold is the one
 * reading of that key. A face that draws a group as a single control (the
 * selection chip's split button) maps its verbs through groupVerbs() and
 * draws each entry; a face that lists verbs flat (every menu, the
 * hotkeys) never calls it and reads the members in weight order, which is
 * the same order the fold preserves inside a group.
 */

/**
 * Folds a menu-ordered verb list into face entries.
 *
 * Each entry is either a plain `{verb}` or a group
 * `{group, primary, rest}`. The input's order is the registry's menu
 * order (weight, then label), so the first verb met of a group is its
 * lightest member: the group sits where that member sits and its
 * members keep the order they arrived in, the primary first, the rest
 * after it. A group with one applicable member is a plain `{verb}`
 * entry, not a one-member control: an unsaved container's chip shows the
 * lone "Save as blueprint…" button exactly as a verb with no group
 * would, which falls out of the fold instead of a special case at the
 * face.
 *
 * @param {object[]} verbs
 *   Verbs in menu order; a verb's `group` is a string or null.
 *
 * @return {Array<{verb: object}|{group: string, primary: object, rest: object[]}>}
 *   The entries in face order.
 */
export const groupVerbs = (verbs) => {
  const entries = [];
  const members = new Map();
  verbs.forEach((verb) => {
    const group = verb.group ?? null;
    if (group === null) {
      entries.push({ verb });
      return;
    }
    if (!members.has(group)) {
      members.set(group, []);
      entries.push({ group });
    }
    members.get(group).push(verb);
  });
  return entries.map((entry) => {
    if (!entry.group) {
      return entry;
    }
    const [primary, ...rest] = members.get(entry.group);
    return rest.length === 0
      ? { verb: primary }
      : { group: entry.group, primary, rest };
  });
};
