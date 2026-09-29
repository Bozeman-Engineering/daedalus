import { matches, specificity } from './binding';

/**
 * Resolution: bindings x live contexts -> the winning binding. Pure, so
 * the leader's dispatcher and the claim set it publishes to pop-outs
 * derive from one loop; the store reads live in the dispatcher's
 * `isActive` callback, never here.
 *
 * Contexts, highest first:
 * - typing: the user is entering text. Primarily a guard: while typing,
 *   only bindings whose contexts include 'typing' fire, and a typing
 *   binding never fires otherwise (the open edit's grammar keys declare
 *   it alongside 'editing').
 * - lift: an element is keyboard-lifted through the insertion caret.
 *   While lifted, the caret-walking keys outrank every selection grammar:
 *   a lift is a committed micro-gesture, like a drag.
 * - editing: an edit is open on the selection (the store's editingKey).
 * - selection: something is selected on the canvas (verb hotkeys, Enter).
 * - global: anywhere in Edit Mode (the traversal, Escape's ladder).
 */
export const CONTEXT_PRIORITY = {
  typing: 50,
  lift: 45,
  editing: 40,
  selection: 30,
  global: 10,
};

/**
 * The binding's contexts that are live for this keystroke. While typing,
 * only the 'typing' context applies, and vice versa.
 *
 * @param {object} binding
 * @param {boolean} typing
 * @param {Function} isActive
 *   (context, binding) => boolean for every context but 'typing'.
 *
 * @return {string[]}
 */
const liveContexts = (binding, typing, isActive) =>
  binding.when.filter((context) =>
    context === 'typing' ? typing : !typing && isActive(context, binding),
  );

/**
 * The binding a keystroke resolves to, or null.
 *
 * A multi-context binding fires at the highest priority among its live
 * contexts. Between candidates the higher priority wins, then the more
 * specific combo, and on a full tie (same context, same specificity) the
 * LATER registration: a binding registered dynamically when a session
 * arms (the palette's insert keys) must beat a boot-time static binding
 * on the same key; dynamic registration is the coexistence mechanism,
 * and registration order is the only signal of intent.
 *
 * @param {Iterable<object>} bindings
 *   Registered bindings in registration order, each with `parsed`, `when`.
 * @param {{key: string, modifiers: object}} desc
 *   The keystroke's descriptor (binding.js normalizeEvent).
 * @param {boolean} typing
 *   The keystroke's typing fact.
 * @param {Function} isActive
 *   (context, binding) => boolean for every context but 'typing'.
 *
 * @return {object|null}
 */
export const resolve = (bindings, desc, typing, isActive) => {
  let best = null;
  let bestPriority = 0;
  for (const binding of bindings) {
    if (!matches(binding.parsed, desc)) {
      continue;
    }
    const active = liveContexts(binding, typing, isActive);
    if (!active.length) {
      continue;
    }
    const priority = Math.max(
      ...active.map((context) => CONTEXT_PRIORITY[context]),
    );
    if (
      best === null ||
      priority > bestPriority ||
      (priority === bestPriority &&
        specificity(binding.parsed) >= specificity(best.parsed))
    ) {
      best = binding;
      bestPriority = priority;
    }
  }
  return best;
};

/**
 * The combos the app claims right now, partitioned by typing state: the
 * binding strings (remaps applied) of every bound binding with a live
 * context. A pop-out, whose keystrokes the leader resolves asynchronously,
 * swallows a browser default only for a combo in this set.
 *
 * @param {Iterable<object>} bindings
 * @param {Function} isActive
 *   (context, binding) => boolean for every context but 'typing'.
 *
 * @return {{free: string[], typing: string[]}}
 *   `free`: claimed while not typing; `typing`: claimed while typing.
 */
export const claimSet = (bindings, isActive) => {
  const free = new Set();
  const typing = new Set();
  for (const binding of bindings) {
    if (!binding.parsed) {
      continue;
    }
    if (binding.when.includes('typing')) {
      typing.add(binding.key);
    }
    if (liveContexts(binding, false, isActive).length) {
      free.add(binding.key);
    }
  }
  return { free: Array.from(free), typing: Array.from(typing) };
};
