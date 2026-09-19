/**
 * The island surface: one container, two postures, owned imperatively.
 *
 * Every island renders as static printed markup by default and exactly one
 * island, the one holding the cursor, is a live CodeMirror EditorView.
 * Mixed ownership of one container (Preact-rendered print, imperative
 * editor) is the bug farm, and a wake must happen synchronously inside the
 * triggering pointerdown (focus and caret placement cannot wait for a
 * render), so the print joins the editor OUTSIDE Preact's render loop:
 * this module owns the container's children in BOTH postures. Preact never
 * renders lines; it already did not for CodeMirror.
 *
 * What each line wears comes from the one marks model (line-marks.js) in
 * both postures: the printer here turns marks into static DOM, the live
 * view's redecorate turns the same marks into Decoration.line + widgets.
 * The winner chip and the pending mark are shared DOM builders below, so
 * the CodeMirror widgets and the printer cannot drift apart.
 *
 * Printed lines are `.daedalus-stylish-island__print > .daedalus-stylish-island__line`
 * (data-line = index) wearing the same decoration classes as the CM lines,
 * so the lost/variant/pending, winner, and pending-mark CSS applies
 * unchanged; inspector.css restates the shared metrics for both postures
 * (the parity contract daedalus_stylish owns). An empty island prints the
 * placeholder line exactly as CM's placeholder renders.
 *
 * Wake triggers wired here: pointerdown on the print (caret from
 * caretPositionFromPoint, mapped to the clicked line's offset;
 * preventDefault so the browser does not select the print). The other
 * triggers (the stitching registry's focus, autoFocus, a parked-focus
 * claim) call wake() from the island view. Park (the live view's blur,
 * except when the whole window lost focus) re-prints via onPark, which
 * hands back the print derived from the dying view so nothing the user
 * typed vanishes under them.
 */

/**
 * The completion tooltip's parent: one fixed, zero-size anchor at the
 * viewport origin per document, outside every island.
 *
 * CodeMirror puts tooltips inside .cm-editor by default and positions them
 * `fixed`, which escapes the island's overflow: hidden in Chrome. Safari
 * (and iOS) take CodeMirror's absolute-positioning fallback, and an
 * absolute tooltip inside the editor is clipped at the island's edge, so
 * the completion list shows as a sliver under the line. Parenting the
 * tooltips here works in both postures: a fixed tooltip resolves against
 * the viewport (no transformed ancestor), and an absolute one resolves
 * against this anchor, whose rect IS the viewport origin, so CodeMirror's
 * container-relative offsets land at the same coordinates. The satellite
 * has its own document and so its own anchor.
 *
 * @param {Document} doc
 *   The document the editor lives in.
 *
 * @return {HTMLElement}
 *   The anchor, created on first use.
 */
export const tooltipParent = (doc) => {
  let parent = doc.querySelector('.daedalus-stylish-code-tooltips');
  if (!parent) {
    parent = doc.createElement('div');
    parent.className = 'daedalus-stylish-code-tooltips';
    doc.body.appendChild(parent);
  }
  return parent;
};

/**
 * The winner dot: the small chrome chip on a lost line naming the layer
 * whose value beats it, in that layer's stable hue. Serves the CM widget
 * (WinnerWidget.toDOM) and the printer alike.
 *
 * @param {string} label
 *   The winning layer's label.
 * @param {string} color
 *   The winning layer's identity color.
 *
 * @return {HTMLElement}
 *   The chip.
 */
export const winnerChip = (label, color) => {
  const chip = document.createElement('span');
  chip.className = 'daedalus-stylish-island__winner daedalus-ui-chip daedalus-ui-chip--muted';
  chip.title = Drupal.t(
    'Shadowed: a higher layer sets this property. The dot names the winner.',
  );
  const dot = document.createElement('span');
  dot.className = 'daedalus-stylish-island__winner-dot daedalus-ui-dot';
  dot.style.background = color;
  chip.append(dot, document.createTextNode(label));
  return chip;
};

/**
 * The pending-row mark: the dismiss (or restore) control on a pending
 * line. Serves the CM widget (PendingMarkWidget.toDOM) and the printer
 * alike; mousedown so the gesture never moves a live view's cursor, and
 * the printer's pointerdown wake skips it (interactive chrome).
 *
 * @param {string} kind
 *   'dismiss' or 'restore'.
 * @param {string} id
 *   The pending line's id, handed to onRun.
 * @param {Function} onRun
 *   The gesture handler.
 *
 * @return {HTMLElement}
 *   The button.
 */
export const pendingMark = (kind, id, onRun) => {
  const button = document.createElement('button');
  button.type = 'button';
  button.tabIndex = -1;
  button.className = `daedalus-stylish-island__pending-mark daedalus-stylish-island__pending-mark--${kind}`;
  if (kind === 'dismiss') {
    button.title = Drupal.t(
      'Dismiss this pending change; it will not be pushed.',
    );
    button.textContent = '×';
  } else {
    button.title = Drupal.t(
      'Excluded from the commit. Click to include it again.',
    );
    button.textContent = '↩';
  }
  button.addEventListener('mousedown', (event) => {
    event.preventDefault();
    event.stopPropagation();
    onRun(id);
  });
  return button;
};

/**
 * Maps a click on the printed markup to a document offset.
 *
 * The caret from the point (caretPositionFromPoint, caretRangeFromPoint
 * fallback) resolves to the printed line's text node in the common case:
 * offset = line start + caret offset. A caret landing on line chrome (the
 * winner chip's text) clamps to the line's end; a click outside every line
 * (the print's padding) picks the nearest line by vertical position, its
 * near edge by horizontal.
 *
 * @param {HTMLElement} container
 *   The island's editor container (holding the print).
 * @param {string[]} texts
 *   The printed text lines, index-aligned with data-line.
 * @param {number} x
 *   Client X.
 * @param {number} y
 *   Client Y.
 *
 * @return {number}
 *   The document offset to wake at.
 */
const printedOffset = (container, texts, x, y) => {
  let node = null;
  let offset = 0;
  if (document.caretPositionFromPoint) {
    const caret = document.caretPositionFromPoint(x, y);
    node = caret?.offsetNode ?? null;
    offset = caret?.offset ?? 0;
  } else if (document.caretRangeFromPoint) {
    const range = document.caretRangeFromPoint(x, y);
    node = range?.startContainer ?? null;
    offset = range?.startOffset ?? 0;
  }
  const lineStart = (index) =>
    texts.slice(0, index).reduce((sum, text) => sum + text.length + 1, 0);
  const lineLength = (index) => texts[index]?.length ?? 0;
  const element =
    node === null
      ? null
      : node.nodeType === Node.TEXT_NODE
        ? node.parentElement
        : node;
  const lineElement = element?.closest?.('.daedalus-stylish-island__line') ?? null;
  if (lineElement && container.contains(lineElement)) {
    const index = Number(lineElement.dataset.line ?? 0);
    const within =
      node.nodeType === Node.TEXT_NODE && node.parentElement === lineElement
        ? Math.min(offset, lineLength(index))
        : node === lineElement && offset === 0
          ? 0
          : lineLength(index);
    return lineStart(index) + within;
  }
  for (const el of container.querySelectorAll('.daedalus-stylish-island__line')) {
    const rect = el.getBoundingClientRect();
    const index = Number(el.dataset.line ?? 0);
    if (y < rect.top) {
      return lineStart(index);
    }
    if (y <= rect.bottom) {
      return lineStart(index) + (x <= rect.left ? 0 : lineLength(index));
    }
  }
  return texts.reduce(
    (sum, text) => sum + text.length,
    Math.max(0, texts.length - 1),
  );
};

/**
 * Creates the surface owning one island's editor container.
 *
 * @param {HTMLElement} container
 *   The island's `.daedalus-stylish-island__editor` element.
 * @param {object} config
 *   - buildState(): the island's EditorState over its printed text (no
 *     selection; wake places the caret before the view exists).
 *   - buildView(state): the EditorView over that state (the island wires
 *     its extensions in the state and its decorations here).
 *   - resolveAnchor(state, placement): a stitch placement resolved to an
 *     offset against the freshly built state.
 *   - onPark(view): derives the parked print from the dying view; returns
 *     {texts, marks} (or null to re-print the last print unchanged).
 *   - onPendingRun(id): the printed pending mark's gesture handler.
 *
 * @return {object}
 *   {print(texts, marks), wake({anchor|placement}), park(), live,
 *   destroy()}.
 */
export const createIslandSurface = (
  container,
  { buildState, buildView, resolveAnchor, onPark = null, onPendingRun = null },
) => {
  let view = null;
  let texts = [];
  let marks = [];

  const renderPrint = () => {
    const print = document.createElement('div');
    print.className = 'daedalus-stylish-island__print';
    const empty = texts.length === 0 || (texts.length === 1 && texts[0] === '');
    if (empty) {
      const line = document.createElement('div');
      line.className = 'daedalus-stylish-island__line daedalus-stylish-island__line--placeholder';
      line.dataset.line = '0';
      line.textContent = Drupal.t('property: value;');
      print.append(line);
    } else {
      texts.forEach((text, index) => {
        const mark = marks[index] ?? null;
        const line = document.createElement('div');
        line.className = [
          'daedalus-stylish-island__line',
          ...(mark?.classes ?? []),
        ].join(' ');
        line.dataset.line = String(index);
        if (mark?.attributes?.style) {
          line.setAttribute('style', mark.attributes.style);
        }
        if (mark?.attributes?.title) {
          line.title = mark.attributes.title;
        }
        line.append(document.createTextNode(text));
        if (mark?.winner) {
          line.append(winnerChip(mark.winner.label, mark.winner.color));
        }
        if (mark?.pending && onPendingRun) {
          line.append(
            pendingMark(mark.pending.kind, mark.pending.id, onPendingRun),
          );
        }
        print.append(line);
      });
    }
    container.replaceChildren(print);
  };

  const surface = {
    get live() {
      return view;
    },
    print(nextTexts, nextMarks) {
      texts = nextTexts ?? [];
      marks = nextMarks ?? [];
      if (!view) {
        renderPrint();
      }
    },
    wake({ anchor = 0, placement = null } = {}) {
      if (view) {
        view.focus();
        view.dispatch({
          selection: {
            anchor: placement
              ? resolveAnchor(view.state, placement)
              : Math.min(anchor, view.state.doc.length),
          },
          scrollIntoView: true,
        });
        return view;
      }
      let state = buildState();
      const head = placement
        ? resolveAnchor(state, placement)
        : Math.min(anchor, state.doc.length);
      state = state.update({ selection: { anchor: head } }).state;
      view = buildView(state);
      container.replaceChildren(view.dom);
      view.focus();
      return view;
    },
    park() {
      if (!view) {
        return;
      }
      const parked = view;
      view = null;
      const fresh = onPark?.(parked) ?? null;
      if (fresh) {
        texts = fresh.texts ?? [];
        marks = fresh.marks ?? [];
      }
      parked.destroy();
      renderPrint();
    },
    destroy() {
      const parked = view;
      view = null;
      parked?.destroy();
      container.replaceChildren();
    },
  };

  // The pointerdown wake: caret at the click point, synchronously inside
  // the event; preventDefault so the browser never starts selecting the
  // print (it also suppresses the compatibility mousedown; focus is
  // explicit in wake). Interactive line chrome (the pending mark) keeps
  // its own gesture; secondary buttons keep the line menu's path.
  container.addEventListener('pointerdown', (event) => {
    if (view || event.button !== 0) {
      return;
    }
    if (event.target.closest?.('.daedalus-stylish-island__pending-mark')) {
      return;
    }
    event.preventDefault();
    surface.wake({
      anchor: printedOffset(container, texts, event.clientX, event.clientY),
    });
  });

  return surface;
};
