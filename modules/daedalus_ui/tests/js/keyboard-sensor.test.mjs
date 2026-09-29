/**
 * @file
 * The keyboard sensor (hotkeys/keyboard-sensor.js): the DOM adapter every
 * document of the app runs, turning key events into strokes (pure data:
 * descriptor, typing, phase, consumed, control), asking `claims`
 * synchronously, and swallowing the browser default only when the app
 * handled the key. Locked here: the typing target, the focused-control
 * rule (a bare Space or Enter on a button, link, select or summary is the
 * control's and never reaches the app), swallow-follows-verdict, the
 * phases, the blur release, the sensed facts, and stopping.
 *
 * The DOM is a stub: a document and window recording their listeners, and
 * elements that answer closest() from the selector tokens they are made
 * of.
 *
 * Run: node --test modules/daedalus_ui/tests/js/keyboard-sensor.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

const { createKeyboardSensor, isControlTarget } = await import(
  '../../js/edit_mode/hotkeys/keyboard-sensor.js'
);
const { isTypingTarget } = await import('../../js/edit_mode/hotkeys/typing.js');

/**
 * A stub element made of the selector tokens it (or an ancestor) matches:
 * el('button'), el('button', '[role="tree"]'), el('input').
 */
const el = (...kinds) => ({
  tagName: kinds[0]?.toUpperCase() ?? 'DIV',
  isContentEditable: kinds.includes('[contenteditable="true"]'),
  closest(selector) {
    const wanted = selector.split(',').map((token) => token.trim());
    return wanted.some((token) => kinds.includes(token)) ? this : null;
  },
});

/** A stub EventTarget recording listeners, firing them by type. */
const target = () => {
  const listeners = new Map();
  return {
    addEventListener(type, listener) {
      listeners.set(type, [...(listeners.get(type) ?? []), listener]);
    },
    removeEventListener(type, listener) {
      listeners.set(
        type,
        (listeners.get(type) ?? []).filter((l) => l !== listener),
      );
    },
    fire(type, event = {}) {
      (listeners.get(type) ?? []).forEach((listener) => listener(event));
      return event;
    },
    count(type) {
      return (listeners.get(type) ?? []).length;
    },
  };
};

const keyEvent = (key, { target: t = el('div'), consumed = false, ...mods } = {}) => {
  const event = {
    key,
    code: '',
    metaKey: false,
    ctrlKey: false,
    altKey: false,
    shiftKey: false,
    ...mods,
    target: t,
    defaultPrevented: consumed,
    prevented: false,
    stopped: false,
    preventDefault() {
      this.prevented = true;
      this.defaultPrevented = true;
    },
    stopPropagation() {
      this.stopped = true;
    },
  };
  return event;
};

/**
 * A sensor over stub DOM with recording inputs. `verdict` is what onKey
 * returns; `claimed` what claims answers.
 */
const sensor = ({ verdict = undefined, claimed = true } = {}) => {
  const doc = target();
  const win = target();
  const strokes = [];
  const asked = [];
  const stop = createKeyboardSensor({
    document: doc,
    window: win,
    claims: (desc, typing) => {
      asked.push({ desc, typing });
      return claimed;
    },
    isTyping: isTypingTarget,
    onKey: (stroke) => {
      strokes.push(stroke);
      return verdict;
    },
  });
  return { doc, win, strokes, asked, stop };
};

test('a keydown becomes a down stroke with its descriptor and is swallowed when handled', () => {
  const { doc, strokes, asked } = sensor();
  const event = doc.fire('keydown', keyEvent('z', { metaKey: true }));
  assert.equal(strokes.length, 1);
  assert.deepEqual(strokes[0], {
    descriptor: {
      key: 'z',
      modifiers: { meta: true, ctrl: false, alt: false, shift: false },
    },
    typing: false,
    phase: 'down',
    consumed: false,
    control: false,
  });
  assert.deepEqual(asked, [{ desc: strokes[0].descriptor, typing: false }]);
  assert.equal(event.prevented, true);
  assert.equal(event.stopped, true);
});

test('the typing fact is the target half: an input, a contenteditable, deep-chat', () => {
  const { doc, strokes, asked } = sensor();
  doc.fire('keydown', keyEvent('z', { metaKey: true, target: el('input') }));
  doc.fire('keydown', keyEvent('z', { metaKey: true, target: el('[contenteditable="true"]') }));
  doc.fire('keydown', keyEvent('z', { metaKey: true, target: el('deep-chat') }));
  doc.fire('keydown', keyEvent('z', { metaKey: true, target: el('div') }));
  assert.deepEqual(strokes.map((s) => s.typing), [true, true, true, false]);
  assert.deepEqual(asked.map((a) => a.typing), [true, true, true, false]);
});

test('a synthetic press on document itself is neither typing nor a control', () => {
  const { doc, strokes } = sensor();
  const documentTarget = { nodeType: 9 };
  doc.fire('keydown', keyEvent('v', { target: documentTarget }));
  assert.equal(strokes.length, 1);
  assert.equal(strokes[0].typing, false);
  assert.equal(strokes[0].control, false);
  assert.equal(isTypingTarget(documentTarget), false);
  assert.equal(isTypingTarget(null), false);
});

test('the focused-control rule: a bare Space or Enter on a control is the control\'s', () => {
  const { doc, strokes, asked } = sensor();
  for (const kind of ['button', 'a[href]', 'select', 'summary']) {
    const space = doc.fire('keydown', keyEvent(' ', { target: el(kind) }));
    const enter = doc.fire('keydown', keyEvent('Enter', { target: el(kind) }));
    assert.equal(space.prevented, false, `${kind} keeps Space`);
    assert.equal(enter.prevented, false, `${kind} keeps Enter`);
  }
  // A control inside the tree keeps its activation too (a disclosure
  // button's Space toggles its row, it never lifts).
  doc.fire('keydown', keyEvent(' ', { target: el('button', '[role="tree"]') }));
  assert.equal(strokes.length, 0);
  assert.equal(asked.length, 0);
});

test('a modified Space or Enter on a control, or a bare one off a control, reaches the app', () => {
  const { doc, strokes } = sensor();
  doc.fire('keydown', keyEvent('Enter', { metaKey: true, target: el('button') }));
  doc.fire('keydown', keyEvent(' ', { shiftKey: true, target: el('a[href]') }));
  // The add panel's items are div[role=button]: not a native control.
  doc.fire('keydown', keyEvent('Enter', { target: el('div') }));
  doc.fire('keydown', keyEvent(' ', { target: el('div') }));
  assert.deepEqual(
    strokes.map((s) => [s.descriptor.key, s.descriptor.modifiers.meta || s.descriptor.modifiers.shift]),
    [['enter', true], ['space', true], ['enter', false], ['space', false]],
  );
});

test('the swallow follows the verdict: a declined key propagates', () => {
  const declined = sensor({ verdict: false });
  const event = declined.doc.fire('keydown', keyEvent('v'));
  assert.equal(declined.strokes.length, 1);
  assert.equal(event.prevented, false);
  assert.equal(event.stopped, false);

  const handled = sensor({ verdict: true });
  const handledEvent = handled.doc.fire('keydown', keyEvent('v'));
  assert.equal(handledEvent.prevented, true);
  assert.equal(handledEvent.stopped, true);
});

test('an unclaimed combo is neither forwarded nor swallowed', () => {
  const { doc, strokes, asked } = sensor({ claimed: false });
  const event = doc.fire('keydown', keyEvent('v'));
  assert.equal(asked.length, 1);
  assert.equal(strokes.length, 0);
  assert.equal(event.prevented, false);
});

test('a keyup is an up stroke: forwarded without asking claims, never swallowed', () => {
  const { doc, strokes, asked } = sensor({ verdict: true });
  const event = doc.fire('keyup', keyEvent('Alt', { altKey: true }));
  assert.equal(strokes.length, 1);
  assert.equal(strokes[0].phase, 'up');
  assert.equal(strokes[0].descriptor.key, 'alt');
  assert.equal(asked.length, 0);
  assert.equal(event.prevented, false);
});

test('a window blur is a blur stroke with no descriptor', () => {
  const { win, strokes } = sensor();
  win.fire('blur');
  assert.deepEqual(strokes, [
    { descriptor: null, typing: false, phase: 'blur', consumed: false, control: false },
  ]);
});

test('the consumed fact is the sensed defaultPrevented, and the stroke still forwards', () => {
  const { doc, strokes } = sensor();
  doc.fire('keydown', keyEvent('Tab', { consumed: true, target: el('input') }));
  doc.fire('keydown', keyEvent('Tab', { target: el('input') }));
  assert.deepEqual(strokes.map((s) => s.consumed), [true, false]);
});

test('the control fact: a native control outside a tree widget', () => {
  const { doc, strokes } = sensor();
  doc.fire('keydown', keyEvent('ArrowDown', { target: el('button') }));
  doc.fire('keydown', keyEvent('ArrowDown', { target: el('a[href]') }));
  doc.fire('keydown', keyEvent('ArrowDown', { target: el('select') }));
  doc.fire('keydown', keyEvent('ArrowDown', { target: el('button', '[role="tree"]') }));
  doc.fire('keydown', keyEvent('ArrowDown', { target: el('div') }));
  assert.deepEqual(strokes.map((s) => s.control), [true, true, true, false, false]);
  assert.equal(isControlTarget(el('summary')), true);
  assert.equal(isControlTarget({ nodeType: 9 }), false);
  assert.equal(isControlTarget(null), false);
});

test('stopping the sensor unbinds all three listeners', () => {
  const { doc, win, strokes, stop } = sensor();
  assert.equal(doc.count('keydown'), 1);
  assert.equal(doc.count('keyup'), 1);
  assert.equal(win.count('blur'), 1);
  stop();
  assert.equal(doc.count('keydown'), 0);
  assert.equal(doc.count('keyup'), 0);
  assert.equal(win.count('blur'), 0);
  doc.fire('keydown', keyEvent('v'));
  assert.equal(strokes.length, 0);
});
