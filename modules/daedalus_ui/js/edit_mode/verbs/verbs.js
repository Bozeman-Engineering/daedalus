import store from '../store/store';
import { kindOf } from '../selection/selectable-key';
import {
  register as registerHotkey,
  unregister as unregisterHotkey,
} from '../hotkeys/hotkeys';

/**
 * The verb registry — what can be done to the current selection.
 *
 * Object-first editing: the user selects a noun, then verbs offer
 * themselves for it. A verb is registered once, keyed by the semantic
 * selection kinds it applies to (entity | field | section | region |
 * block); the floating verb menu and the verb hotkeys are two faces of
 * this one registry. This is the extension point tools and contrib use
 * instead of owning their own canvas chrome: daedalus_layout registers Duplicate /
 * Trash / Configure here, daedalus_edit's Edit verb and the undo verbs follow.
 *
 * Verb hotkeys register into the hotkey dispatcher under the 'selection'
 * context, so they only fire while something is selected and are
 * remappable in the settings tray like any other binding. A verb whose
 * gesture happens mid-edit (typingHotkeys) also registers the 'typing'
 * context, so its keys keep firing while keystrokes land in an open
 * editor.
 *
 * The registry is also where the selection/edit scope partition lives:
 * a verb is either selection-scoped (the default — it acts on the
 * selected element and applies only while NO live edit lands on the
 * key) or edit-scoped (editScoped — it acts on the key's OPEN edit and
 * applies only while the store's editingKey IS the key). One gate here
 * means every face of the registry (chip face, context menu, tree,
 * hotkeys) flips between the two verb sets together as edits open and
 * close; no face re-derives the partition.
 */

/** @type {Map<string, object>} */
const verbs = new Map();

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener());

/**
 * Whether a verb applies to one selectable key: kind, edit scope, then
 * the verb's own applies() refinement. The edit-scope gate reads the
 * store's reactive editingKey (the engine's editChanged report is its
 * only writer): a selection verb applies only while no live edit lands
 * on the key, an edit-scoped verb only while the live edit IS the key.
 */
const appliesTo = (verb, key) => {
  if (!key || !verb.kinds.includes(kindOf(key))) {
    return false;
  }
  const { editingKey } = store.getState().selection;
  if (verb.editScoped ? editingKey !== key : editingKey === key) {
    return false;
  }
  return !verb.applies || verb.applies(key);
};

/**
 * Runs a verb against a multi-select set: runMany when the verb provides
 * it (one atomic batch — one mutation, one history entry), otherwise once
 * per member in selection order.
 */
const runOnKeys = (verb, keys) => {
  if (verb.runMany) {
    verb.runMany([...keys]);
  } else {
    keys.forEach((key) => verb.run(key));
  }
};

/**
 * Runs a verb on a selection set by size: one member runs the plain verb,
 * a real set runs the multi path (runOnKeys, runMany's atomic batch when
 * the verb has one). The ONE verbs-to-run wire every face (menu, context
 * menu, canvas chrome) uses; nobody re-derives the size branch.
 */
const runVerbOn = (verb, keys) => {
  if (keys.length > 1) {
    runOnKeys(verb, keys);
  } else {
    verb.run(keys[0]);
  }
};

/**
 * Runs a verb against the committed selection, for hotkey dispatch.
 * Returns false (do not handle) when the verb does not apply to it.
 * With a multi-select set, only multi-capable verbs that apply to
 * EVERY member run, and they run against the whole set.
 */
const runOnSelection = (verb) => {
  const { selectedKey, selectedKeys } = store.getState().selection;
  if (selectedKeys.length > 1) {
    if (!verb.multi || !selectedKeys.every((key) => appliesTo(verb, key))) {
      return false;
    }
    runOnKeys(verb, selectedKeys);
    return undefined;
  }
  if (!appliesTo(verb, selectedKey)) {
    return false;
  }
  verb.run(selectedKey);
  return undefined;
};

/**
 * Registers a verb.
 *
 * @param {object} spec
 *   - id: unique id, e.g. 'daedalus_layout.trash'. Also the hotkey binding id.
 *   - label: menu-button label (and hotkey settings-tray label).
 *   - menuLabel: optional (key) => string refinement of the label for a
 *     SINGLE selection: a verb naming its target ("Update stencil 'X'").
 *     The static label stays the verb's identity everywhere else (hotkey
 *     tray, menu ordering, multi-select sets).
 *   - icon: optional inline SVG markup, rendered beside the label in the
 *     menu button. Use fill="currentColor" so it follows the button's
 *     text color (hover inverts it).
 *   - kinds: semantic selection kinds the verb applies to.
 *   - applies: optional (key) => bool refinement within those kinds.
 *   - run: function(key) — perform the verb on a selectable key.
 *   - multi: optional bool; the verb applies to a multi-select SET
 *     when it applies to every member. Default false: with a set selected,
 *     single-only verbs leave the menu and their hotkeys don't fire.
 *   - runMany: optional function(keys) — perform the verb on the whole set
 *     as ONE atomic action (one mutation batch, one history entry). Multi
 *     verbs without it fall back to run() per member.
 *   - editScoped: optional bool. The verb acts on the key's OPEN edit
 *     (a staged-preview control like tag/link/format/media) instead of
 *     the selected element: it applies only while the store's
 *     editingKey IS the key. Default false — a selection verb, which
 *     applies only while NO live edit lands on the key. The registry
 *     gates both scopes (appliesTo), so every face flips between the
 *     two sets together as edits open and close.
 *   - hotkeys: optional binding string(s); registered in the 'selection'
 *     context. The first is the verb's primary binding; extras register
 *     as '{id}.N' so aliases like delete/backspace can coexist.
 *   - typingHotkeys: optional bool — the verb's hotkeys register in the
 *     'typing' context too (as ['selection', 'typing']), so they keep
 *     firing while keystrokes land in an open editor. For verbs whose
 *     gesture happens mid-edit (Cmd+K on an open text edit); the
 *     dispatch still checks applies, so a stray typing surface with no
 *     live edit on the key never fires an edit-scoped verb.
 *   - weight: menu order, lighter first (default 0).
 *   - tab: optional bool. The verb opts onto the selection chip's FACE
 *     (the icon row on the primary's chip, selection-frame.js);
 *     unflagged verbs live behind the chip's chevron in the context
 *     menu, and nothing appears in both. Default false.
 *   - widget: optional function(key, container, context) — a
 *     custom-rendered menu entry (a stepper, a popover control, a small
 *     control cluster) instead of a button. Called with the
 *     single-selection key, an empty container element to populate, and
 *     a context object carrying the frame's density facts ({labels}:
 *     whether entries render text labels at the current chrome density),
 *     so a widget's face follows the same density ladder as the plain
 *     verb buttons; a returned function runs when the entry unmounts,
 *     the selection changes, or the density flips. Widget verbs order by
 *     weight like buttons and never join multi-select sets. run is
 *     optional alongside widget, and hotkeys only register when run
 *     exists.
 */
const register = (spec) => {
  if (
    !spec.id ||
    !spec.label ||
    !Array.isArray(spec.kinds) ||
    (!spec.run && !spec.widget)
  ) {
    throw new Error(`Invalid verb: ${JSON.stringify(spec)}`);
  }
  const verb = {
    weight: 0,
    icon: null,
    applies: null,
    hotkeys: [],
    multi: false,
    runMany: null,
    menuLabel: null,
    widget: null,
    run: null,
    tab: false,
    editScoped: false,
    typingHotkeys: false,
    ...spec,
  };
  verb.hotkeys = Array.isArray(verb.hotkeys) ? verb.hotkeys : [verb.hotkeys];
  verbs.set(verb.id, verb);

  // A hotkey dispatches to run; a widget-only verb has nothing to bind.
  verb.hotkeys = verb.run ? verb.hotkeys : [];
  verb.hotkeys.forEach((key, index) => {
    registerHotkey({
      id: index === 0 ? verb.id : `${verb.id}.${index + 1}`,
      key,
      when: verb.typingHotkeys ? ['selection', 'typing'] : 'selection',
      label: verb.label,
      section: Drupal.t('Actions'),
      remappable: true,
      action: () => runOnSelection(verb),
    });
  });
  notify();
};

const unregister = (id) => {
  const verb = verbs.get(id);
  if (!verb) {
    return;
  }
  verb.hotkeys.forEach((key, index) => {
    unregisterHotkey(index === 0 ? verb.id : `${verb.id}.${index + 1}`);
  });
  verbs.delete(id);
  notify();
};

/**
 * The verbs applicable to a selectable key, menu-ordered.
 *
 * @param {?string} key
 *   The selectable key.
 *
 * @return {object[]}
 */
const verbsFor = (key) =>
  Array.from(verbs.values())
    .filter((verb) => appliesTo(verb, key))
    .sort((a, b) => a.weight - b.weight || a.label.localeCompare(b.label));

/**
 * The verbs applicable to a multi-select set, menu-ordered: for a set of
 * one, the single-selection verbs; for a real set, only multi-capable
 * verbs that apply to EVERY member.
 *
 * @param {string[]} keys
 *   The selection set's selectable keys.
 *
 * @return {object[]}
 */
const verbsForSet = (keys) => {
  if (!keys || keys.length === 0) {
    return [];
  }
  if (keys.length === 1) {
    return verbsFor(keys[0]);
  }
  return Array.from(verbs.values())
    .filter((verb) => verb.multi && keys.every((key) => appliesTo(verb, key)))
    .sort((a, b) => a.weight - b.weight || a.label.localeCompare(b.label));
};

/**
 * Subscribes to registry changes (for the menu's re-render).
 *
 * @return {Function}
 *   Unsubscribe.
 */
const onChange = (listener) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/**
 * Boots the registry facade. Called once by the app entry, after hotkeys.
 */
export const initVerbs = () => {
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.Verbs = {
    register,
    unregister,
    verbsFor,
    verbsForSet,
    onChange,
  };
};

export {
  register,
  unregister,
  verbsFor,
  verbsForSet,
  runOnKeys,
  runVerbOn,
  onChange,
};
