/**
 * @file
 * The chrome reveal engine (Drupal.Daedalus.reveal).
 *
 * Chrome density is a three-tier ladder on the html element
 * (data-daedalus-ui-chrome-density: full | minimal | clean). Secondary chrome
 * declares the tier where it starts hiding with data-daedalus-ui-conceal="minimal"
 * (hidden at minimal and clean) or data-daedalus-ui-conceal="clean" (hidden only at
 * clean), and hides until the pointer approaches it. The normal opt-in is
 * declarative, no JS at all:
 * @code
 *   <button data-daedalus-ui-conceal="clean" data-daedalus-ui-reveal-near="120">
 * @endcode
 * A behavior registers every [data-daedalus-ui-conceal] element automatically, and
 * css/reveal.css owns the hiding. JS-created chrome can register
 * explicitly instead:
 * @code
 *   Drupal.Daedalus.reveal.register(handle, { near: 80 });
 *   Drupal.Daedalus.reveal.unregister(handle);
 * @endcode
 *
 * One rAF-throttled pointermove loop measures the distance from the pointer
 * to each registered element's rect and toggles data-daedalus-ui-revealed on
 * elements concealed at the current tier; CSS does the showing. The engine
 * is inert while density is full (or the attribute is absent, which means
 * the same): the pointermove handler bails before any measuring, and
 * leaving a concealing density strips every data-daedalus-ui-revealed so no stale
 * reveal state lingers.
 *
 * Concealment is opacity plus delayed visibility (reveal.css), never
 * display: none: the engine needs a measurable rect to compute approach
 * distance (a collapsed rect is treated as unapproachable; visibility:
 * hidden preserves the rect), and concealed chrome keeps its space so
 * nothing reflows when it reveals. The visibility flip exists so hidden
 * layers stop compositing — an opacity-0 fixed layer can leave stale
 * first-commit paint on load.
 */
((Drupal, once) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  const REVEALED = 'data-daedalus-ui-revealed';
  const DENSITY = 'data-daedalus-ui-chrome-density';
  const CONCEAL = 'data-daedalus-ui-conceal';
  const NEAR = 'data-daedalus-ui-reveal-near';
  const DEFAULT_NEAR = 80;

  /**
   * The density ladder. Higher hides more; unknown or absent reads as full.
   */
  const TIERS = { full: 0, minimal: 1, clean: 2 };

  /**
   * Registered elements mapped to their options ({near, tier}).
   */
  const registry = new Map();

  /**
   * The last known pointer position in viewport coordinates, null until the
   * pointer has moved and again after it leaves the document.
   */
  let pointer = null;

  /**
   * The pending requestAnimationFrame id, 0 when none is scheduled.
   */
  let frame = 0;

  /**
   * The current density level on the ladder.
   */
  const level = () =>
    TIERS[document.documentElement.getAttribute(DENSITY)] ?? 0;

  /**
   * Whether the engine measures at all: any concealing tier is active.
   */
  const active = () => level() > 0;

  /**
   * Distance from a point to a rect: 0 inside, straight-line to the nearest
   * edge or corner outside.
   */
  const distanceTo = (rect, x, y) => {
    const dx = Math.max(rect.left - x, 0, x - rect.right);
    const dy = Math.max(rect.top - y, 0, y - rect.bottom);
    return Math.hypot(dx, dy);
  };

  const clearAll = () => {
    registry.forEach((options, el) => el.removeAttribute(REVEALED));
  };

  /**
   * The single measuring pass, run at most once per frame.
   */
  const evaluate = () => {
    frame = 0;
    if (!pointer || !active()) {
      return;
    }
    const current = level();
    registry.forEach((options, el) => {
      if (!el.isConnected) {
        return;
      }
      // Visible at this tier anyway (e.g. clean-tier chrome while density is
      // minimal): keep it free of stale reveal state and skip the measuring.
      if (options.tier > current) {
        el.removeAttribute(REVEALED);
        return;
      }
      const rect = el.getBoundingClientRect();
      // A collapsed rect means the element is unmeasurable (display: none
      // somewhere up the tree); it cannot be approached.
      const within =
        (rect.width > 0 || rect.height > 0) &&
        distanceTo(rect, pointer.x, pointer.y) <= options.near;
      el.toggleAttribute(REVEALED, within);
    });
  };

  const schedule = () => {
    if (!frame) {
      frame = requestAnimationFrame(evaluate);
    }
  };

  // The one pointermove listener. The position is recorded even while inert
  // so switching to a concealing density can evaluate immediately instead of
  // waiting for the next move.
  window.addEventListener(
    'pointermove',
    (event) => {
      pointer = { x: event.clientX, y: event.clientY };
      if (active()) {
        schedule();
      }
    },
    { passive: true },
  );

  // Rects move under a stationary pointer when anything scrolls; capture
  // catches nested scrollers, which don't bubble.
  window.addEventListener(
    'scroll',
    () => {
      if (active()) {
        schedule();
      }
    },
    { passive: true, capture: true },
  );

  // The pointer leaving the document means nothing is being approached.
  document.documentElement.addEventListener('pointerleave', () => {
    pointer = null;
    clearAll();
  });

  // Density changes live (the settings select mirrors the saved value onto
  // html): entering a concealing tier re-evaluates against the last known
  // position (which also strips reveals from elements the new tier no
  // longer conceals), leaving them strips all reveal state.
  new MutationObserver(() => {
    if (active()) {
      schedule();
    } else {
      clearAll();
    }
  }).observe(document.documentElement, {
    attributes: true,
    attributeFilter: [DENSITY],
  });

  Drupal.Daedalus.reveal = {
    /**
     * Registers an element for proximity reveal.
     *
     * @param {Element} el
     *   The chrome element to reveal on approach.
     * @param {object} options
     *   Options object.
     * @param {number} options.near
     *   The reveal radius in px: the element gains data-daedalus-ui-revealed while
     *   the pointer is within this distance of its rect.
     * @param {string} [options.at]
     *   The tier the element hides from: 'minimal' or 'clean'. Defaults to
     *   the element's data-daedalus-ui-conceal value, then 'clean'.
     */
    register(el, { near, at }) {
      if (!(near > 0)) {
        throw new TypeError(
          'Drupal.Daedalus.reveal.register() requires a positive `near` radius.',
        );
      }
      const tier = TIERS[at ?? el.getAttribute(CONCEAL)] ?? TIERS.clean;
      registry.set(el, { near, tier });
      if (active()) {
        schedule();
      }
    },

    /**
     * Unregisters an element and strips its reveal state.
     *
     * @param {Element} el
     *   The element to remove from the registry.
     */
    unregister(el) {
      el.removeAttribute(REVEALED);
      registry.delete(el);
    },
  };

  /**
   * Auto-registers declaratively concealed chrome.
   *
   * Any module marks chrome with data-daedalus-ui-conceal="minimal|clean" (usually
   * server-side in its render array, so SSR hides it with no flash) and
   * optionally data-daedalus-ui-reveal-near for a custom radius; no per-module JS.
   */
  Drupal.behaviors.daedalusUiConceal = {
    attach(context) {
      once('daedalus-ui-conceal', `[${CONCEAL}]`, context).forEach((el) => {
        const near = Number(el.getAttribute(NEAR)) || DEFAULT_NEAR;
        Drupal.Daedalus.reveal.register(el, { near });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload' && context.querySelectorAll) {
        once.remove('daedalus-ui-conceal', `[${CONCEAL}]`, context).forEach((el) => {
          Drupal.Daedalus.reveal.unregister(el);
        });
      }
    },
  };
})(Drupal, once);
