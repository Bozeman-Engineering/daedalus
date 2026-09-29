import { parseBinding, matches } from '../edit_mode/hotkeys/binding.js';

/**
 * The pop-out's claim set: the combos the leader would act on right now.
 *
 * A pop-out senses keystrokes but never resolves them: the leader alone
 * holds the registry, the live contexts and the user's remaps. The
 * browser default (mod+d bookmarks, backspace navigates, mod+shift+c
 * opens devtools) must be swallowed synchronously at the keydown, while
 * the leader's verdict arrives asynchronously over the wire, so the
 * leader publishes the set of combos it claims (hotkeys.js claimSet(),
 * partitioned by typing state, effective binding strings with the user's
 * remaps applied) and the pop-out swallows exactly those, nothing else.
 * The set is one broadcast wide stale at worst (it changes on selection
 * or editing changes), acceptable for a keystroke.
 *
 * Pure: no DOM, no channel. The strings parse on the pop-out's own
 * platform ('mod' resolves here as it does on the leader: same machine).
 *
 * @return {{update: Function, clear: Function, claims: Function}}
 *   - update({free, typing}): replaces the set.
 *   - clear(): forgets the set (no leader: nothing is claimed).
 *   - claims(descriptor, typing): whether the leader claims this combo in
 *     this typing state, the keyboard sensor's synchronous input.
 */
export const createClaims = () => {
  let free = [];
  let typing = [];

  const parse = (combos) =>
    (combos ?? []).map(parseBinding).filter((parsed) => parsed !== null);

  return {
    update: (set) => {
      free = parse(set.free);
      typing = parse(set.typing);
    },
    clear: () => {
      free = [];
      typing = [];
    },
    claims: (descriptor, isTyping) =>
      (isTyping ? typing : free).some((parsed) => matches(parsed, descriptor)),
  };
};
