/**
 * @file
 * The canvas state vector shell: stage width, emulation, handles, ruler.
 *
 * The vector (width / scheme / motion) is headless spine state — the posing
 * UI lives in the daedalus_stylish inspector's chip row, which drives this store
 * through the CanvasVector facade. The stage (#daedalus-ui-stage, a
 * query container) narrows to the width, re-evaluating container-breakpoint
 * conditions live; scheme and motion emulate by media-condition rewriting
 * (emulate-media.js).
 *
 * The vector is addressable state, not buried UI state:
 * - Drupal.Daedalus.CanvasVector = {get, set, subscribe, bands}
 *   (the inspector's chip row and the AI's render tool use it). Facade
 *   snapshots carry `measured` — the full (unposed) stage width from real
 *   layout — beside the axes, so consumers can derive the effective canvas
 *   width as width ?? measured. Measured is not an axis: nothing poses it,
 *   set() ignores it, it only reports what the canvas is.
 * - ?canvasWidth=…&canvasScheme=…&canvasMotion=… pose the page at boot.
 * - The html element mirrors it as data-canvas-* for CSS and tests.
 *
 * Modules contribute ruler bands via (window.DaedalusUiCanvasStops
 * ||= []).push({min?, max?, label, name}) — inclusive px edges, missing
 * edges unbounded; no load-order coupling either way. The ruler renders
 * each band as a labeled span with ticks on the band edges, and lights
 * the band the EFFECTIVE width (posed ?? measured) sits in. It shows
 * only while the canvas is constrained in any way (a width posed at
 * all, or the unposed stage squeezed below base's floor by rails or a
 * narrow window) and rests when the stage sits at its natural full
 * width in the bandless base. While a handle drag is live, a center
 * readout of the actual canvas width in px rides the ruler.
 */
import {
  AXES,
  createVector,
  parseBootState,
  MIN_WIDTH,
} from './vector-state.js';
import { applyToSheets } from './emulate-media.js';
import {
  normalizeBands,
  bandEdgeTicks,
  bandFor,
  constrained,
} from './ruler-bands.js';

(function (Drupal, once) {
  Drupal.behaviors.daedalusUiCanvasVector = {
    attach: () => {
      // Keyed on the root element: the shell has no DOM of its own (the
      // pose UI lives in the daedalus_stylish inspector), so it initializes once
      // per page the moment the library first attaches — full page load
      // and AJAX-delivered edit chrome alike.
      once('DaedalusUiCanvasVector', document.documentElement).forEach(
        () => {
          const stage = document.getElementById('daedalus-ui-stage');
          const html = document.documentElement;
          const vector = createVector(parseBootState(window.location.search));

          // ------------------------------------------------------------------
          // Width bands: drained from the global queue, live thereafter.
          // ------------------------------------------------------------------
          let bands = [];
          const acceptBands = (entries) => {
            bands = normalizeBands([...bands, ...entries]);
          };
          window.DaedalusUiCanvasStops ||= [];
          const queue = window.DaedalusUiCanvasStops;
          acceptBands(queue.splice(0));
          queue.push = (...entries) => {
            acceptBands(entries);
            buildRuler();
            return queue.length;
          };

          // ------------------------------------------------------------------
          // Scheme/motion emulation over the CSSOM.
          // ------------------------------------------------------------------
          const originals = new WeakMap();
          let emulationScheduled = false;
          const applyEmulation = () => {
            emulationScheduled = false;
            const { scheme, motion } = vector.get();
            applyToSheets(document.styleSheets, { scheme, motion }, originals);
          };
          const scheduleEmulation = () => {
            if (!emulationScheduled) {
              emulationScheduled = true;
              requestAnimationFrame(applyEmulation);
            }
          };
          // While an axis is forced, fresh styles (AJAX swaps, re-printed
          // inline style tags) need rewriting too. The filter keeps the
          // observer cheap on the busy edit-mode DOM.
          const touchesStyles = (mutation) => {
            if (
              mutation.target.nodeName === 'STYLE' ||
              mutation.target.parentElement?.nodeName === 'STYLE'
            ) {
              return true;
            }
            const nodes = [...mutation.addedNodes, ...mutation.removedNodes];
            return nodes.some(
              (node) => node.nodeName === 'STYLE' || node.nodeName === 'LINK',
            );
          };
          new MutationObserver((mutations) => {
            const { scheme, motion } = vector.get();
            if (
              (scheme !== 'auto' || motion !== 'auto') &&
              mutations.some(touchesStyles)
            ) {
              scheduleEmulation();
            }
          }).observe(html, {
            childList: true,
            subtree: true,
            characterData: true,
          });

          // ------------------------------------------------------------------
          // Drag handles and the breakpoint ruler (body-level fixed chrome).
          // ------------------------------------------------------------------
          const makeHandle = (side) => {
            const handle = document.createElement('div');
            handle.className = `canvas-vector-handle canvas-vector-handle--${side}`;
            handle.setAttribute('data-canvas-chrome', '');
            // Hidden from minimal density up (reveal.css), except while a
            // width is posed (data-canvas-width override in canvas-vector.css).
            handle.setAttribute('data-daedalus-ui-conceal', 'minimal');
            handle.title = Drupal.t('Drag to resize the canvas');
            document.body.append(handle);
            // JS-created after behaviors ran, so register with the reveal
            // engine explicitly; the tier comes from data-daedalus-ui-conceal.
            Drupal.Daedalus.reveal.register(handle, { near: 80 });
            return handle;
          };
          const handles = stage
            ? [makeHandle('left'), makeHandle('right')]
            : [];

          const ruler = document.createElement('div');
          ruler.id = 'daedalus-ui-canvas-ruler';
          ruler.setAttribute('data-canvas-chrome', '');
          document.body.append(ruler);

          // The scrub readout: the actual canvas width in px, centered on
          // the stage's center line. Its text tracks every width change,
          // but CSS keeps it invisible outside a live handle drag (plus a
          // short linger after release): the inspector chip's face
          // deliberately names the write target (the band), so a permanent
          // px echo has no home; this one exists only while the number is
          // the thing being scrubbed.
          const readout = document.createElement('span');
          readout.className = 'canvas-vector-ruler__readout';

          /**
           * Geometry captured once at the grab, constant for the whole drag.
           *
           * The canvas is centered, so a symmetric resize never moves the
           * stage's center line and never changes the full (unposed) width:
           * both are measured ONCE on pointerdown and every per-move
           * computation is pure arithmetic from them. Zero layout reads on
           * the drag's hot path — the only forced relayout left is the
           * container-query re-evaluation the width write exists to cause.
           */
          let dragGeometry = null;

          /**
           * The stage's center line: handles and ruler marks position from it.
           */
          const stageCenter = () => {
            if (dragGeometry) {
              return dragGeometry.center;
            }
            if (!stage) {
              return window.innerWidth / 2;
            }
            const rect = stage.getBoundingClientRect();
            return rect.left + rect.width / 2;
          };

          const positionHandles = () => {
            if (!stage) {
              return;
            }
            // Centered on the canvas edges so they never crowd the sidebars.
            if (dragGeometry) {
              // Arithmetic from the drag-constant center; no layout read.
              const width = vector.get().width ?? dragGeometry.fullWidth;
              handles[0].style.left = `${dragGeometry.center - width / 2 - 6}px`;
              handles[1].style.left = `${dragGeometry.center + width / 2 - 6}px`;
              return;
            }
            const rect = stage.getBoundingClientRect();
            handles[0].style.left = `${rect.left - 6}px`;
            handles[1].style.left = `${rect.right - 6}px`;
          };

          // The ruler's DOM is built once per band-set change; everything a
          // width change touches afterwards is an is-active toggle on the
          // existing spans, and everything a geometry change touches is a
          // style left/width write. Rebuilding the whole DOM per drag move
          // would be pure waste. The canvas is centered, so every band
          // renders as a symmetric pair of half-spans (a floorless band's
          // halves meet at the center line) and every edge tick as a
          // symmetric pair of marks; labels ride the right half only.
          let rulerBands = [];
          let rulerTicks = [];

          const buildRuler = () => {
            ruler.replaceChildren();
            rulerBands = [];
            rulerTicks = [];
            bands.forEach((band) => {
              ['left', 'right'].forEach((side) => {
                const span = document.createElement('span');
                span.className = 'canvas-vector-ruler__band';
                if (side === 'right') {
                  span.textContent = band.label;
                }
                ruler.append(span);
                rulerBands.push({ el: span, band, side });
              });
            });
            bandEdgeTicks(bands).forEach((px) => {
              ['left', 'right'].forEach((side) => {
                const tick = document.createElement('span');
                tick.className = 'canvas-vector-ruler__mark';
                if (side === 'right') {
                  tick.textContent = String(px);
                }
                ruler.append(tick);
                rulerTicks.push({ el: tick, px, side });
              });
            });
            // The readout survives band-set rebuilds: re-adopt it last so it
            // paints over the spans it labels.
            ruler.append(readout);
            layoutRuler();
            updateRuler();
          };

          const layoutRuler = () => {
            const center = stageCenter();
            rulerBands.forEach(({ el, band, side }) => {
              // Width space maps to half-width offsets from the center line.
              const inner = (band.min ?? 0) / 2;
              const outer = band.max === undefined ? null : band.max / 2;
              if (side === 'right') {
                el.style.left = `${center + inner}px`;
                el.style.width = outer === null ? '' : `${outer - inner}px`;
                el.style.right = outer === null ? '0' : '';
              } else {
                el.style.left = outer === null ? '0' : `${center - outer}px`;
                el.style.width =
                  outer === null
                    ? `${Math.max(center - inner, 0)}px`
                    : `${outer - inner}px`;
              }
            });
            rulerTicks.forEach(({ el, px, side }) => {
              el.style.left = `${side === 'left' ? center - px / 2 : center + px / 2}px`;
            });
            readout.style.left = `${center}px`;
          };

          /**
           * The ruler's one width-driven refresher. Lights the band the
           * effective width (posed ?? measured) sits in. Above every band,
           * or with nothing measured yet, nothing lights: that space is
           * deliberately bandless, the contributor's unconditioned base.
           * Shows the ruler only while the canvas is constrained
           * (ruler-bands.js constrained(): any pose, or the unposed stage
           * measured inside a band); while a handle drag is live the
           * canvas-vector-resizing mark holds the display up in CSS
           * regardless, so a scrub crossing the full-width release edge
           * cannot thrash it. The readout text tracks the effective width;
           * its visibility is the drag mark's alone.
           */
          const updateRuler = () => {
            const posed = vector.get().width;
            const active = bandFor(bands, posed ?? measured);
            rulerBands.forEach(({ el, band }) => {
              el.classList.toggle('is-active', band === active);
            });
            ruler.classList.toggle(
              'is-constrained',
              constrained(bands, posed, measured),
            );
            const effective = posed ?? measured;
            readout.textContent = effective === null ? '' : `${effective}px`;
          };

          const reposition = () => {
            positionHandles();
            layoutRuler();
            publishMeasured();
          };

          /**
           * The width the stage fills when nothing is posed. NOT the
           * viewport: open rails displace the canvas, so a drag to the stage
           * edge lands short of window.innerWidth and would never release
           * the pose.
           */
          const stageWidth = () =>
            dragGeometry?.fullWidth ??
            stage?.parentElement?.clientWidth ??
            window.innerWidth;

          // ------------------------------------------------------------------
          // Measured width: the full (unposed) stage width, published beside
          // the vector on facade snapshots. Not an axis — nothing poses it;
          // it reports what the canvas IS, so consumers (the daedalus_stylish
          // inspector's write-target derivation) read the effective canvas
          // width as width ?? measured: a posed stage renders the pose, an
          // unposed stage fills exactly this measurement. Updates only from
          // real-layout safe points (boot, the ResizeObserver, drag end via
          // reposition()); the drag hot path stays arithmetic and the
          // measurement stays valid through it — a pose narrows the stage,
          // never its parent.
          // ------------------------------------------------------------------
          let measured = null;
          const facadeListeners = new Set();
          const facadeSnapshot = () => ({ ...vector.get(), measured });
          const notifyFacade = (snapshot, changed) => {
            facadeListeners.forEach((listener) => listener(snapshot, changed));
          };
          const publishMeasured = () => {
            const width = Math.round(stageWidth());
            if (Number.isFinite(width) && width > 0 && width !== measured) {
              measured = width;
              updateRuler();
              notifyFacade(facadeSnapshot(), ['measured']);
            }
          };
          vector.subscribe((state, changed) => {
            notifyFacade({ ...state, measured }, changed);
          });

          // Symmetric resize (the canvas is centered): width is twice the
          // pointer's distance from the center line; past the stage edge
          // returns to full width.
          handles.forEach((handle) => {
            handle.addEventListener('pointerdown', (event) => {
              // Measure before begin() so no move can race the capture, but
              // install only on acceptance: a refused gesture (or a second
              // pointer landing during a live drag) must not clobber the
              // live drag's constants.
              const geometry = {
                center: stageCenter(),
                fullWidth: stageWidth(),
              };
              const begun = Drupal.Daedalus.drag.begin(handle, event, {
                marks: [[html, 'canvas-vector-resizing']],
                onMove: (moveEvent) => {
                  const width = Math.round(
                    Math.abs(moveEvent.clientX - stageCenter()) * 2,
                  );
                  if (width >= stageWidth() - 8) {
                    vector.set({ width: null });
                  } else if (width >= MIN_WIDTH) {
                    vector.set({ width });
                  }
                },
                onEnd: () => {
                  // True up from real layout once: the drag positioned
                  // everything arithmetically and skipped the observer.
                  dragGeometry = null;
                  reposition();
                },
              });
              if (begun) {
                dragGeometry = geometry;
              }
            });
          });

          // ------------------------------------------------------------------
          // Application: state -> page. Work is gated per changed axis: a
          // width-only change (every drag move) must never pay for the
          // scheme/motion machinery — applyEmulation() walks every rule of
          // every stylesheet, and width can never affect a media condition.
          // ------------------------------------------------------------------
          const apply = (state, changed = AXES) => {
            if (changed.includes('width')) {
              // Width: narrowing the query container re-evaluates every
              // container-breakpoint condition (the feature's one real cost).
              if (stage) {
                stage.style.width =
                  state.width === null ? '' : `${state.width}px`;
              }
              if (state.width === null) {
                html.removeAttribute('data-canvas-width');
              } else {
                html.setAttribute('data-canvas-width', String(state.width));
              }
              updateRuler();
              // Handle geometry: during a drag, arithmetic from the cached
              // constants, synchronously so the handles paint with the frame
              // that moved them. Outside a drag the ResizeObserver answers
              // the stage's actual resize from real layout.
              if (dragGeometry) {
                positionHandles();
              }
            }

            if (changed.includes('scheme')) {
              // Scheme: color-scheme also flips the UA's own rendering (form
              // controls, scrollbars, light-dark()).
              if (stage) {
                stage.style.colorScheme =
                  state.scheme === 'auto' ? '' : state.scheme;
              }
              html.setAttribute('data-canvas-scheme', state.scheme);
            }

            if (changed.includes('motion')) {
              html.setAttribute('data-canvas-motion', state.motion);
            }

            if (changed.includes('scheme') || changed.includes('motion')) {
              applyEmulation();
            }
          };

          vector.subscribe(apply);
          buildRuler();
          apply(vector.get());

          window.addEventListener('resize', reposition);
          if (stage) {
            // The observer is the ONE repositioner for real geometry changes
            // (rails opening, window resizes, programmatic width sets
            // resolving through layout). While a handle drag is live it
            // stays out: apply() already positioned arithmetically, and the
            // observer echoing every width write would redo the work with
            // forced reads (the old double-reposition).
            const observer = new ResizeObserver(() => {
              if (!dragGeometry) {
                reposition();
              }
            });
            observer.observe(stage);
            observer.observe(stage.parentElement);
          }

          // Leaving edit mode returns the canvas to reality: the chrome that
          // could undo a pose leaves with the mode, so the pose must not
          // outlive it.
          new MutationObserver(() => {
            if (html.getAttribute('data-daedalus-ui-mode') !== 'edit') {
              vector.set({ width: null, scheme: 'auto', motion: 'auto' });
            }
          }).observe(html, {
            attributes: true,
            attributeFilter: ['data-daedalus-ui-mode'],
          });

          // ------------------------------------------------------------------
          // The addressable surface. Snapshots (get() and every subscriber
          // call) carry `measured` beside the axes; a measured change
          // notifies with changed = ['measured'], so axis-gated consumers
          // ignore it for free.
          // ------------------------------------------------------------------
          publishMeasured();
          Drupal.Daedalus.CanvasVector = {
            get: facadeSnapshot,
            set: vector.set,
            subscribe: (listener) => {
              facadeListeners.add(listener);
              return () => facadeListeners.delete(listener);
            },
            bands: () => bands.map((band) => ({ ...band })),
          };
        },
      );
    },
  };
})(Drupal, once);
