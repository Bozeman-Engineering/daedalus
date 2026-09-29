/**
 * The host: the wiring from the projection index through the plane model
 * and the stylesheet printer to the one injected <style>.
 *
 * Runs while the Explode panel is open. On start it reads the index
 * (app.tree.index() and app.tree.root(), null before the mirror boots,
 * so they are read at every print, never once), reach (app.tree
 * .reachable: the planes are what the Page elements panel depicts, so a
 * shell part lifts only while the Markup view is on), the settings store
 * and the selection, runs the pure model (plane-model.js), prints the
 * sheet (stylesheet.js) into `<style id="daedalus-explode">` in <head>,
 * and re-prints on every blueprint, selection, hover, view-visibility
 * and settings change. Prints coalesce to one per animation frame: hover
 * fires at pointer speed, and a selection and a hover often change in
 * the same tick.
 *
 * The canvas-view attribute (canvas-view.js) is written by the sidebar
 * plugin before start() and cleared by this host after the return
 * transition: every hide rule under the attribute is display-based, so
 * dropping it before the planes have settled flat would paint the
 * selection frame and its siblings against still-projected rects. stop()
 * therefore re-prints the sheet flat (the stage at rest, every plane
 * unlifted) with the attribute still on, waits for the stage's transform
 * transition to end (or the duration the sheet reads,
 * --daedalus-explode-duration, as a fallback when nothing transitions),
 * then removes the style and leaves the view. A start() during that
 * return cancels it and resumes in place.
 *
 * The label chip anchors on `position: relative`, which the printer adds
 * only for the planes this host reports static (anchored). The read is
 * made once per element (a WeakMap): the printed sheet itself makes an
 * anchored element read `relative`, so a later read of the same element
 * would drop the anchor and the next would restore it. A new element
 * under an address the sheet already anchors (a mutation swapped its
 * markup) keeps the anchor rather than trusting a read the sheet has
 * tainted. Only lifted, labeled planes are read: an anchor makes the
 * element the containing block of its absolutely positioned descendants,
 * a cost worth paying for a chip and for nothing else.
 *
 * Part planes are addressed by one printed rule reaching every match, so
 * `anchored` is keyed by the plane address and a part address anchors
 * every match at once: the read covers the matches the behavior's
 * `matches(owner, selector)` lists (the DOM twin of its selectorFor), and
 * the address anchors when there is at least one match and every match
 * reads static. One positioned match among static ones withholds the
 * anchor from all of them (their chips then sit on the nearest positioned
 * ancestor, usually the anchored owner) rather than turning an absolute
 * or sticky match relative, which would move it.
 *
 * The chip's wording: a block plane speaks its type (app.Blueprint
 * .typeForKey, the one type resolver, which lives on the Blueprint facade
 * and not on Selection: "Text", "Image"), since the plane already shows what the
 * block says and its index label reads the content, the spine's own rule
 * for canvas chips; every other plane keeps the index label (a named
 * section reads its name, a field its field label, a part its
 * matched-form label). Part planes print through the registered part
 * behavior's selectorFor, read from app.tree.partBehavior() at every
 * print (the behavior registers from its own queue callback, which may
 * run after this module's); without one they print nothing, the
 * printer's honest answer for no behavior.
 */
import { planes as planesFor } from './plane-model.js';
import { print } from './stylesheet.js';
import * as canvasView from './canvas-view.js';
import { log } from './log.js';

export const STYLE_ID = 'daedalus-explode';

/**
 * The stage at rest: what stop() prints so the return animates.
 */
const FLAT = Object.freeze({ tilt: 0, turn: 0, scale: 1, gap: 0 });

const DEFAULT_DURATION_MS = 600;

/**
 * The stage element the sheet transforms.
 */
const stageElement = () => document.getElementById('daedalus-ui-stage');

/**
 * The transition duration the sheet reads, in milliseconds.
 *
 * @param {?Element} stage
 *   The stage, whose computed --daedalus-explode-duration (a theme may
 *   set it) is the one the transition uses.
 *
 * @return {number}
 */
const durationMs = (stage) => {
  const raw = stage
    ? getComputedStyle(stage).getPropertyValue('--daedalus-explode-duration').trim()
    : '';
  const match = /^(\d*\.?\d+)(ms|s)$/.exec(raw);
  if (!match) {
    return DEFAULT_DURATION_MS;
  }
  return match[2] === 's' ? Number(match[1]) * 1000 : Number(match[1]);
};

/**
 * Resolves once the stage's transform transition has ended, or after the
 * duration when nothing transitions (a stage already at rest fires no
 * event). The returned cancel drops both and settles the promise at
 * once; the caller tells a cancelled return from a finished one by its
 * own bookkeeping.
 *
 * @return {{done: Promise<void>, cancel: Function}}
 */
const afterReturn = () => {
  const stage = stageElement();
  let settle = null;
  const done = new Promise((resolve) => {
    let timer = null;
    let onEnd = null;
    settle = () => {
      clearTimeout(timer);
      if (onEnd) {
        stage.removeEventListener('transitionend', onEnd);
      }
      resolve();
    };
    if (stage) {
      onEnd = (event) => {
        if (event.target === stage && event.propertyName === 'transform') {
          settle();
        }
      };
      stage.addEventListener('transitionend', onEnd);
    }
    timer = setTimeout(settle, durationMs(stage) + 50);
  });
  return { done, cancel: settle };
};

/**
 * Builds the host over the app API and the settings store.
 *
 * @param {object} options
 * @param {object} options.app
 *   The app API handed to the registration callback.
 * @param {{get: Function, subscribe: Function}} options.settings
 *   The settings store (settings.js).
 *
 * @return {{start: Function, stop: Function, isRunning: Function}}
 *   start() enters (idempotent; resumes a pending return); stop()
 *   returns a promise that resolves once the style is gone and the view
 *   is left; isRunning() reports whether a sheet is printed.
 */
export const createHost = ({ app, settings }) => {
  let style = null;
  let unsubscribes = [];
  let frame = null;
  let pendingReturn = null;
  // Element → whether its computed position was static when first read.
  const measured = new WeakMap();
  // The plane addresses the printed sheet anchors right now.
  let anchoredKeys = new Set();

  /**
   * The registered part behavior at this moment, or null.
   */
  const partBehavior = () => app.tree.partBehavior?.() ?? null;

  /**
   * The elements a plane addresses: the one element under an element
   * key, every match of a part's selector under its owner (none without
   * a behavior that lists matches). The model lists no plane for a
   * generated box (plane-model.js), so the behavior's answer is read as
   * the contract states it: a hit naming a pseudo matches the HOSTS of
   * a box, which has no geometry of its own and anchors nothing here.
   */
  const elementsOf = (plane, behavior) => {
    if (plane.tier === 'element') {
      const element = app.Selection.getElement(plane.address);
      return element ? [element] : [];
    }
    const hit = behavior?.matches?.(plane.owner, plane.selector) ?? null;
    return hit === null || hit.pseudo !== null ? [] : hit.elements;
  };

  /**
   * Whether an element counts as static for the anchor: its first read,
   * or the anchor it already wears when the sheet has tainted the read.
   */
  const isStatic = (element, address) => {
    if (!measured.has(element)) {
      measured.set(
        element,
        anchoredKeys.has(address) ||
          getComputedStyle(element).position === 'static',
      );
    }
    return measured.get(element);
  };

  const paint = () => ({
    selected: app.Selection.getSelected(),
    set: app.Selection.getSelectedSet(),
    hovered: app.Selection.getHovered(),
  });

  const worded = (plane) =>
    plane.tier === 'element' && plane.kind === 'block'
      ? { ...plane, label: app.Blueprint.typeForKey(plane.address) }
      : plane;

  const model = (view) =>
    planesFor(
      app.tree.index(),
      app.tree.root(),
      app.tree.reachable,
      view,
      app.Selection.getSelectedSet(),
    ).map(worded);

  const anchorsFor = (planes, behavior) => {
    const anchors = new Set();
    for (const plane of planes) {
      if (!plane.lifted || !plane.labeled) {
        continue;
      }
      const elements = elementsOf(plane, behavior);
      if (
        elements.length > 0 &&
        elements.every((element) => isStatic(element, plane.address))
      ) {
        anchors.add(plane.address);
      }
    }
    anchoredKeys = anchors;
    return anchors;
  };

  const render = () => {
    frame = null;
    const view = settings.get();
    const planes = model(view);
    const behavior = partBehavior();
    style.textContent = print(planes, view, paint(), {
      selectorFor: behavior?.selectorFor ?? null,
      anchored: anchorsFor(planes, behavior),
    });
    log('print', `Printed ${planes.length} planes`, view);
  };

  const schedule = () => {
    if (frame === null) {
      frame = requestAnimationFrame(render);
    }
  };

  const cancelFrame = () => {
    if (frame !== null) {
      cancelAnimationFrame(frame);
      frame = null;
    }
  };

  const subscribe = () => {
    unsubscribes = [
      app.onBlueprintChange(schedule),
      app.onSelectionChange(schedule),
      app.onHoverChange(schedule),
      app.tree.onViewVisibilityChange(schedule),
      settings.subscribe(schedule),
    ];
  };

  const unsubscribe = () => {
    unsubscribes.forEach((off) => off());
    unsubscribes = [];
  };

  const start = () => {
    if (pendingReturn) {
      pendingReturn.cancel();
      pendingReturn = null;
      log('start', 'Return cancelled, resuming');
    } else if (style) {
      return;
    } else {
      style = document.getElementById(STYLE_ID);
      if (!style) {
        style = document.createElement('style');
        style.id = STYLE_ID;
        document.head.appendChild(style);
      }
    }
    subscribe();
    render();
    log('start', 'Host started');
  };

  const stop = () => {
    if (!style) {
      // Never started (a close sweep over a panel that was not open): the
      // view has nothing to animate back, so the attribute drops now.
      canvasView.leave();
      return Promise.resolve();
    }
    if (pendingReturn) {
      return pendingReturn.done;
    }
    unsubscribe();
    cancelFrame();
    const flat = model(settings.get()).map((plane) => ({
      ...plane,
      lifted: false,
      lift: 0,
      labeled: false,
    }));
    anchoredKeys = new Set();
    // Part planes settle through the same behavior they lifted through;
    // dropping them from the flat sheet would snap them down unanimated.
    style.textContent = print(flat, FLAT, {}, {
      selectorFor: partBehavior()?.selectorFor ?? null,
    });
    log('stop', 'Returning flat');
    const returning = afterReturn();
    pendingReturn = returning;
    return returning.done.then(() => {
      if (pendingReturn !== returning) {
        // A start() cancelled this return; the sheet stays.
        return;
      }
      pendingReturn = null;
      style.remove();
      style = null;
      canvasView.leave();
      log('stop', 'Host stopped, canvas view cleared');
    });
  };

  return {
    start,
    stop,
    isRunning: () => style !== null && pendingReturn === null,
  };
};
