import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { EditorView, keymap, placeholder, tooltips } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { history, defaultKeymap, historyKeymap } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { linter } from '@codemirror/lint';
import {
  acceptCompletion,
  autocompletion,
  completionKeymap,
} from '@codemirror/autocomplete';
import { createIslandSurface, tooltipParent } from './island-surface.js';
import { guideCompletions } from './element-view.js';
import {
  analyzeStep,
  printStep,
  settledStep,
  stepDeclaration,
} from '../animations-model.js';

/**
 * One keyframe step's island: the declarations of one (animation,
 * offset), in the code view's two postures.
 *
 * The element island (element-view.js) is bound to a style-map key: its
 * lines are the layered document of a (key, condition) pair, its settle
 * diffs against worn variants into update_style ops, its cursor stitches
 * through the page's island chain. A keyframe step is none of that: one
 * declarations map on an animation's offset, owned by the keyframes
 * SESSION (animations.js) whose Update commits every step at once. So
 * this is the thin fork the plan allowed: the same surface
 * (island-surface.js: printed by default, one live CodeMirror editor
 * while it holds the cursor, wake on pointerdown, park on blur), the
 * same language, completions (the guide's offer under the universal
 * vocabulary) and lint (the island analysis plus the keyframe-only
 * rules, animations-model.js analyzeStep), and a state model of its own:
 * per settle (a terminating semicolon, 400 ms of quiet, the cursor
 * leaving a line, blur) the buffer's valid declarations become the
 * step's map and the session hears them (onSettle), staging nothing on
 * any style map. Invalid lines hold out of the settle and flag the
 * island; the session's Update waits on a clean editor.
 *
 * The island prints its step once, on mount; from then on the buffer is
 * its own truth (a park re-prints the dying view's text verbatim, so
 * nothing typed vanishes). The session keys islands by a stable step
 * identity, so a drag along the track re-renders the header, never the
 * island.
 */
export const KeyframeIsland = ({
  guide,
  materials,
  declarations,
  autoFocus = false,
  onSettle,
}) => {
  const containerRef = useRef(null);
  const surfaceRef = useRef(null);
  const session = useRef({});
  const [hasErrors, setHasErrors] = useState(false);
  // The posture as a class (the element island's rule): the surface
  // reports each swap, the live island lifts (inspector.css `--live`).
  const [live, setLive] = useState(false);

  // CM6 lives outside preact's render loop; its closures read the latest
  // props through this ref. The completion source reads the universal
  // declaration: a step validates against every served group.
  const propsRef = useRef({});
  propsRef.current = {
    guide,
    materials,
    declaration: stepDeclaration(guide),
    onSettle,
  };

  useEffect(() => {
    if (!containerRef.current) {
      return undefined;
    }
    session.current = {
      printed: printStep(declarations),
      settled: null,
      commitTimer: null,
    };
    setHasErrors(false);

    const analyze = (text) =>
      analyzeStep(text, {
        guide: propsRef.current.guide,
        materials: propsRef.current.materials,
      });

    // The settle: the buffer's valid declarations as the step's map. Only
    // a CHANGE reaches the session (a settle on an unchanged buffer, a
    // blur after a semicolon settle, is silent), so the live preview
    // re-prints exactly once per real edit.
    const settle = (text) => {
      const { declarations: next, hasErrors: errors } = settledStep(
        analyze(text).declarations,
      );
      setHasErrors(errors);
      const signature = JSON.stringify(next);
      if (signature === session.current.settled) {
        return;
      }
      session.current.settled = signature;
      propsRef.current.onSettle?.(next, errors);
    };

    const buildState = () =>
      EditorState.create({
        doc: session.current.printed,
        extensions: [
          history(),
          css(),
          EditorView.lineWrapping,
          placeholder(Drupal.t('property: value;')),
          tooltips({
            parent: tooltipParent(containerRef.current.ownerDocument),
          }),
          autocompletion({
            override: [(context) => guideCompletions(context, propsRef)],
          }),
          linter(
            (lintView) => {
              const docLength = lintView.state.doc.length;
              return analyze(lintView.state.doc.toString()).diagnostics.map(
                (diagnostic) => ({
                  from: Math.min(diagnostic.from, docLength),
                  to: Math.min(diagnostic.to, docLength),
                  severity: diagnostic.severity,
                  message: diagnostic.message,
                }),
              );
            },
            { delay: 300 },
          ),
          keymap.of([
            { key: 'Tab', run: acceptCompletion },
            ...completionKeymap,
            ...defaultKeymap,
            ...historyKeymap,
          ]),
          EditorView.updateListener.of((update) => {
            if (update.docChanged) {
              session.current.cursorLine = update.state.doc.lineAt(
                update.state.selection.main.head,
              ).number;
              let semicolon = false;
              update.changes.iterChanges((fromA, toA, fromB, toB, inserted) => {
                if (inserted.toString().includes(';')) {
                  semicolon = true;
                }
              });
              clearTimeout(session.current.commitTimer);
              if (semicolon) {
                // The declaration just terminated: the settle boundary.
                settle(update.state.doc.toString());
              } else {
                session.current.commitTimer = setTimeout(
                  () => settle(update.view.state.doc.toString()),
                  400,
                );
              }
            } else if (update.selectionSet) {
              const head = update.state.selection.main.head;
              const headLine = update.state.doc.lineAt(head).number;
              if (session.current.cursorLine !== headLine) {
                session.current.cursorLine = headLine;
                settle(update.state.doc.toString());
              }
            }
          }),
          EditorView.domEventHandlers({
            blur: (event, target) => {
              clearTimeout(session.current.commitTimer);
              settle(target.state.doc.toString());
              // Park on blur, EXCEPT when the whole window lost focus: a
              // Cmd-Tab must not lose the cursor.
              if (document.hasFocus()) {
                surfaceRef.current?.park();
              }
            },
          }),
        ],
      });
    const buildView = (state) => {
      const built = new EditorView({ state });
      session.current.cursorLine = state.doc.lineAt(
        state.selection.main.head,
      ).number;
      return built;
    };
    const surface = createIslandSurface(containerRef.current, {
      buildState,
      buildView,
      resolveAnchor: (state) => state.doc.length,
      // The parked print is the dying view's text verbatim: what the user
      // typed (valid or not) stays on screen, and the next wake edits it.
      onPark: (view) => {
        session.current.printed = view.state.doc.toString();
        return { texts: session.current.printed.split('\n'), marks: [] };
      },
      onPosture: setLive,
    });
    surfaceRef.current = surface;
    surface.print(session.current.printed.split('\n'), []);
    if (autoFocus) {
      surface.wake({ anchor: session.current.printed.length });
    }

    return () => {
      clearTimeout(session.current.commitTimer);
      const live = surface.live;
      if (live) {
        // Torn down mid-edit (the session closed under a live editor):
        // the last buffer settles so the session heard every keystroke.
        settle(live.state.doc.toString());
      }
      surface.destroy();
      surfaceRef.current = null;
    };
  }, []);

  return (
    <div
      className={`daedalus-stylish-island daedalus-stylish-keyframes__island${live ? ' daedalus-stylish-island--live' : ''}${hasErrors ? ' daedalus-stylish-island--invalid' : ''}`}
    >
      <div className="daedalus-stylish-island__editor" ref={containerRef} />
    </div>
  );
};
