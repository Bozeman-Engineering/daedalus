/**
 * The motion-triggers runtime: the Animation Triggers draft where the
 * browser lacks it.
 *
 * `timeline-trigger` / `animation-trigger` decide WHEN a worn animation
 * plays (the shipped entrances trigger when the element scrolls into
 * view). Browsers with the draft honor the printed properties natively and
 * this module stands down. Elsewhere the printer's `@supports not
 * (animation-trigger: none)` hold keeps every triggered element's
 * animation paused until it carries the stamp attribute, and this module
 * is what stamps it: it reads the effective pair off computed style
 * through the marker custom properties the printer prints beside each
 * property (in whatever block the property lands, so the cascade resolves
 * an element override over a variant default like the real property),
 * binds the two when they name one trigger, watches the element with an
 * IntersectionObserver shaped from the activation range, and on
 * activation stamps and runs the enter action, on deactivation runs the
 * exit action (lifting the stamp again for `reset`, which re-arms the
 * hold).
 *
 * A plain ES module outside the webpack bundles: the live site carries no
 * inspector code, and this file must stay a few hundred lines that die by
 * deletion the day the draft is Baseline. It therefore cannot import
 * printer.js (whose import graph reaches the inspector) and spells the two
 * marker names and the stamp attribute itself; they must stay literally
 * equal to printer.js's TRIGGER_MARKERS / TRIGGERED_ATTRIBUTE (and
 * CssPrinter's). Discovery is computed-style driven (two reads per
 * element) on DOMContentLoaded and on every added subtree: edit-mode
 * mutation responses swap an entity's markup, so an edited element is a
 * fresh, unstamped node that re-arms naturally.
 *
 * Runtime API: `Drupal.Daedalus.Stylish.motionTriggers.replay(el)` re-arms one
 * element (the inspector's Replay affordance): the stamp lifts, the
 * animations rewind, and the element is observed afresh, so it plays
 * again the moment its range point is met (at once when already in view).
 * Natively there is no hold to lift and no observer; replay rewinds and
 * plays the animations now.
 */
import {
  actionFor,
  bindingFor,
  observerOptions,
} from './src/motion-triggers-model.js';

/**
 * printer.js TRIGGER_MARKERS / TRIGGERED_ATTRIBUTE mirrors (CssPrinter
 * authoritative). Keep byte-identical.
 */
const TIMELINE_MARKER = '--daedalus-stylish-timeline-trigger';
const ANIMATION_MARKER = '--daedalus-stylish-animation-trigger';
const TRIGGERED_ATTRIBUTE = 'data-daedalus-stylish-triggered';

const NATIVE = CSS.supports('animation-trigger', 'none');

// Element -> {observer, actions, timelineValue, animationValue}.
const bindings = new WeakMap();

const readMarkers = (el) => {
  const style = getComputedStyle(el);
  return [
    style.getPropertyValue(TIMELINE_MARKER).trim(),
    style.getPropertyValue(ANIMATION_MARKER).trim(),
  ];
};

const run = (el, kind) => {
  const step = actionFor(kind);
  if (!step) {
    return;
  }
  for (const animation of el.getAnimations()) {
    step(animation);
  }
};

const unbind = (el) => {
  const binding = bindings.get(el);
  if (binding) {
    binding.observer.disconnect();
    bindings.delete(el);
  }
};

const observe = (el, binding) => {
  const options = observerOptions(
    binding.timeline.range,
    el.offsetHeight,
    window.innerHeight,
  );
  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      const stamped = el.hasAttribute(TRIGGERED_ATTRIBUTE);
      if (entry.isIntersecting) {
        if (stamped) {
          continue;
        }
        // The stamp lifts the CSS hold before the API step: a CSS-paused
        // animation ignores play() from script.
        el.setAttribute(TRIGGERED_ATTRIBUTE, '');
        run(el, binding.actions.enter);
        if (binding.actions.enter === 'play-once') {
          observer.disconnect();
        }
      } else if (stamped && binding.actions.exit !== 'none') {
        run(el, binding.actions.exit);
        if (binding.actions.exit === 'reset') {
          el.removeAttribute(TRIGGERED_ATTRIBUTE);
        }
      }
    }
  }, options);
  observer.observe(el);
  bindings.set(el, { ...binding, observer });
};

/**
 * Binds one element from its effective markers; rebinds when the pair
 * changed since the last scan, leaves a bound element alone otherwise.
 */
const bind = (el) => {
  const [timelineValue, animationValue] = readMarkers(el);
  const current = bindings.get(el);
  if (
    current &&
    current.timelineValue === timelineValue &&
    current.animationValue === animationValue
  ) {
    return;
  }
  unbind(el);
  const binding = bindingFor(timelineValue, animationValue);
  if (!binding) {
    return;
  }
  observe(el, { ...binding, timelineValue, animationValue });
};

const scan = (root) => {
  if (root.nodeType !== Node.ELEMENT_NODE) {
    return;
  }
  bind(root);
  for (const el of root.querySelectorAll('*')) {
    bind(el);
  }
};

/**
 * Re-arms one element: lifts the stamp (the hold pauses again), rewinds
 * every animation, and observes afresh, so the entrance plays again when
 * its range point is met, at once when the element is already in view.
 * Natively, where nothing is held or observed, rewinds and plays now.
 */
const replay = (el) => {
  if (NATIVE) {
    for (const animation of el.getAnimations()) {
      animation.currentTime = 0;
      animation.playbackRate = 1;
      animation.play();
    }
    return;
  }
  el.removeAttribute(TRIGGERED_ATTRIBUTE);
  run(el, 'reset');
  unbind(el);
  bind(el);
};

((Drupal.Daedalus.Stylish ??= {}).motionTriggers ??= {}).replay = replay;

if (!NATIVE) {
  // Custom properties inherit; a registered non-inheriting marker reads
  // empty on every element that does not declare it itself, so a
  // triggered element's descendants never bind through inheritance.
  for (const name of [TIMELINE_MARKER, ANIMATION_MARKER]) {
    CSS.registerProperty({ name, syntax: '*', inherits: false });
  }
  const start = () => {
    scan(document.body);
    new MutationObserver((records) => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          scan(node);
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}
