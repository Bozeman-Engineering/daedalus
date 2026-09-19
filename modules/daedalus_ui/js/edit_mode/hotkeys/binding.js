/**
 * Binding strings and keyboard-event matching.
 *
 * A binding string is lowercase, '+'-separated, modifiers first, key last:
 * 'v', 'mod+d', 'shift+escape', 'alt'. 'mod' is the platform primary
 * modifier (meta on macOS, ctrl elsewhere). The key may itself be a bare
 * modifier name ('alt') — that is how hold-style bindings express "the
 * modifier IS the key", which the old flat map could not.
 *
 * Matching is strict: the event's modifier set must equal the binding's.
 * 'v' does not fire on Cmd+V, and 'mod+d' does not fire on Cmd+Shift+D.
 */

const MODIFIERS = ['meta', 'ctrl', 'alt', 'shift'];

const IS_MAC =
  typeof navigator !== 'undefined' && /mac/i.test(navigator.platform);

/**
 * The modifier the "mod" token stands for on this platform.
 */
const MOD_KEY = IS_MAC ? 'meta' : 'ctrl';

/**
 * Maps KeyboardEvent.key modifier names to binding-string tokens.
 */
const EVENT_KEY_MODIFIERS = {
  meta: 'meta',
  control: 'ctrl',
  alt: 'alt',
  shift: 'shift',
  // Some platforms report the meta key as 'OS'.
  os: 'meta',
};

/**
 * Parses a binding string into { key, modifiers } or null when unbound.
 *
 * 'mod' resolves to the platform modifier at parse time, so a stored
 * 'mod+d' matches meta+d on a Mac and ctrl+d elsewhere.
 */
export const parseBinding = (str) => {
  if (!str) {
    return null;
  }
  const tokens = str.toLowerCase().split('+').filter(Boolean);
  if (!tokens.length) {
    return null;
  }
  const key = tokens.pop();
  const modifiers = { meta: false, ctrl: false, alt: false, shift: false };
  for (const token of tokens) {
    const name = token === 'mod' ? MOD_KEY : token;
    if (!MODIFIERS.includes(name)) {
      return null;
    }
    modifiers[name] = true;
  }
  return { key, modifiers, raw: str };
};

/**
 * Derives a lowercase key name from KeyboardEvent.code for letters and
 * digits ('KeyA' → 'a', 'Digit1' → '1'), or null for anything else.
 */
const keyFromCode = (code) => {
  if (/^Key[A-Z]$/.test(code)) {
    return code.slice(3).toLowerCase();
  }
  if (/^Digit[0-9]$/.test(code)) {
    return code.slice(5);
  }
  return null;
};

/**
 * Normalizes a KeyboardEvent to { key, modifiers } for matching.
 *
 * macOS composes Alt combos into special characters (Alt+A → 'å'), so when
 * Alt is held and the produced character is not what the physical key says,
 * the key falls back to the layout-independent event.code. Everything else
 * matches on event.key — the character the user's layout actually produced,
 * which is what non-qwerty users expect their remaps to follow.
 */
export const normalizeEvent = (event) => {
  let key = event.key.toLowerCase();
  if (key === ' ') {
    // The space bar's event.key is a literal space character, which a
    // '+'-separated binding string cannot express — bindings say 'space'.
    key = 'space';
  }
  if (key in EVENT_KEY_MODIFIERS) {
    key = EVENT_KEY_MODIFIERS[key];
  } else if (event.altKey) {
    key = keyFromCode(event.code) ?? key;
  }
  return {
    key,
    modifiers: {
      meta: event.metaKey,
      ctrl: event.ctrlKey,
      alt: event.altKey,
      shift: event.shiftKey,
    },
  };
};

/**
 * Whether a normalized event matches a parsed binding.
 *
 * When the binding's key is itself a modifier ('alt'), that modifier is
 * necessarily active on its own keydown, so it is treated as implied
 * rather than required to be declared.
 */
export const matches = (parsed, desc) => {
  if (!parsed || parsed.key !== desc.key) {
    return false;
  }
  return MODIFIERS.every((name) => {
    const expected = parsed.modifiers[name] || parsed.key === name;
    return desc.modifiers[name] === expected;
  });
};

/**
 * How many modifiers a binding requires — ties between same-context
 * bindings go to the more specific one.
 */
export const specificity = (parsed) =>
  MODIFIERS.reduce(
    (count, name) => count + (parsed.modifiers[name] ? 1 : 0),
    0,
  );

/**
 * Whether the release of `key` ends a hold on `parsed` — the hold's own
 * key or any of its modifiers going up releases it.
 */
export const releasedBy = (parsed, key) =>
  parsed.key === key || parsed.modifiers[key] === true;

/**
 * Builds a binding string from a capture keydown event, for the settings
 * tray's click-to-rebind. Returns null for a bare-modifier keydown (the
 * caller decides whether to commit it on keyup as a hold-style binding).
 * The platform primary modifier is stored as 'mod' so the setting is
 * portable across the user's machines.
 */
export const eventToBindingString = (event) => {
  const desc = normalizeEvent(event);
  if (desc.key in EVENT_KEY_MODIFIERS || MODIFIERS.includes(desc.key)) {
    return null;
  }
  const primary = MOD_KEY;
  const tokens = [];
  for (const name of MODIFIERS) {
    if (desc.modifiers[name]) {
      tokens.push(name === primary ? 'mod' : name);
    }
  }
  tokens.push(desc.key);
  return tokens.join('+');
};
