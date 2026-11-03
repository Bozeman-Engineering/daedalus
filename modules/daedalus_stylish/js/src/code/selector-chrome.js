import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { EditorView, keymap, tooltips } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { autocompletion } from '@codemirror/autocomplete';
import {
  CHILD,
  NEXT,
  SUBSEQUENT,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { isDoubleClick } from '../../../../daedalus_ui/js/edit_mode/selection/double-click.js';
import { COMBINATOR, WRAPPER, preludeParts } from './prelude.js';
import { POSITION, PSEUDO, QUALIFIER, STATE, selectorGestureOptions } from './element-model.js';
import { tooltipParent } from './island-surface.js';
import { boxWord } from '../parts.js';
import { notify } from '../notify.js';
import {
  CANCEL,
  COMMIT,
  NOOP,
  REFUSED,
  openSelectorEdit,
  selectorEditOutcome,
  selectorEditPreview,
  typedSelectorEdit,
} from './selector-edit.js';

/**
 * The words of the `:` lane's position pseudo-classes and open
 * qualifiers, in the reader's terms.
 */
const positionWord = (name) => {
  const words = {
    'first-child': Drupal.t('The first among its siblings'),
    'last-child': Drupal.t('The last among its siblings'),
    'only-child': Drupal.t('An only child'),
    empty: Drupal.t('Holding nothing'),
    'first-of-type': Drupal.t('The first of its kind among its siblings'),
    'last-of-type': Drupal.t('The last of its kind among its siblings'),
    'only-of-type': Drupal.t('The only one of its kind among its siblings'),
    'nth-child': Drupal.t('Every nth among its siblings (2n+1, odd, 3)'),
    'nth-last-child': Drupal.t('Every nth from the end'),
    'nth-of-type': Drupal.t('Every nth of its kind'),
    'nth-last-of-type': Drupal.t('Every nth of its kind from the end'),
    not: Drupal.t('All but what the parens name'),
    has: Drupal.t('Those holding what the parens name'),
    is: Drupal.t('Any of what the parens name'),
  };
  return words[name] ?? name;
};

/**
 * The sentence a selector completion's detail reads: what the rule
 * addresses, in the reader's words. One sentence for both surfaces the
 * selector completion opens on: an island's line and the prelude editor.
 */
export const selectorDetail = (option) => {
  const lower = (label) =>
    typeof label === 'string' && label !== ''
      ? label.charAt(0).toLowerCase() + label.slice(1)
      : label;
  switch (option.kind) {
    case UNIVERSAL:
      return Drupal.t('Children');
    case TYPE:
      return option.name === TYPE_CONTAINER || option.name === TYPE_BLOCK
        ? Drupal.t('Every !label here', { '!label': lower(option.label ?? option.name) })
        : Drupal.t('Every !label block here', {
            '!label': lower(option.label ?? option.name),
          });
    case TAG:
      return Drupal.t('Every !label here', { '!label': lower(option.label ?? option.name) });
    case PSEUDO:
      return boxWord(option.name);
    case STATE:
      return Drupal.t('While !label', { '!label': lower(option.label ?? option.name) });
    case POSITION:
    case QUALIFIER:
      return positionWord(option.name);
    default:
      return option.label ?? option.name;
  }
};

/**
 * The completion list one selector offer (element-model.js
 * selectorGestureOptions) renders as: an item per option in CodeMirror's
 * shape, the detail sentence beside it, the undeclared class the offer
 * would create last and marked. The caller spells the label (an island
 * nests it under its rule, the editor prints the selector whole) and
 * says what picking does (an island mints the row, the editor rewrites
 * its text); an open qualifier option (`:not(`, `:has(`, `:nth-child(`:
 * `insert` in place of `selector`) inserts its text for the designer to
 * finish, through the caller's `insert`, which defaults to `apply`.
 *
 * @param {{options: Array<object>, create: ?string}} offer
 * @param {{label: (selector: string) => string,
 *   apply: (selector: string) => Function,
 *   insert?: (text: string) => Function}} spelling
 */
export const selectorCompletionItems = (offer, { label, apply, insert = apply }) => {
  const typeOf = (kind) =>
    kind === UNIVERSAL || kind === PSEUDO || kind === STATE || kind === POSITION || kind === QUALIFIER
      ? 'keyword'
      : kind === TYPE
        ? 'type'
        : 'class';
  const items = offer.options.map((option) =>
    option.insert !== undefined
      ? {
          label: label(option.insert),
          type: typeOf(option.kind),
          detail: selectorDetail(option),
          apply: insert(option.insert),
        }
      : {
          label: label(option.selector),
          type: typeOf(option.kind),
          detail: selectorDetail(option),
          apply: apply(option.selector),
        },
  );
  if (offer.create) {
    items.push({
      label: label(offer.create),
      type: 'class',
      detail: Drupal.t('Create this part'),
      boost: -1,
      apply: apply(offer.create),
    });
  }
  return items;
};

/**
 * How long a selector offer stays valid as typing goes on: the stem is
 * the typed text up to the token under the cursor, and the popup holds
 * while only that token grows; a new compound (a space, a combinator, a
 * dot, a colon opening the `:` lane) asks the source again. A `:` lane
 * token (`:ho`) holds while its name grows and lapses at a paren.
 *
 * @param {string} typed
 *   The text from the offer's anchor to the cursor.
 *
 * @return {(text: string) => boolean}
 *   CodeMirror's `validFor` predicate.
 */
export const selectorOfferValidity = (typed) => {
  const token = /(?::[A-Za-z-]*|[A-Za-z0-9_.*-]*)$/.exec(typed)?.[0] ?? '';
  const stem = typed.slice(0, typed.length - token.length);
  return (text) =>
    text.startsWith(stem) && /^(?::[A-Za-z-]*|[A-Za-z0-9_.*-]*)$/.test(text.slice(stem.length));
};

/**
 * A selector row header's click: the first click of a pair seats the
 * row as it always has (the write address, the subject facet), the
 * click that closes the pair opens the editor. The one definition of a
 * double-click is Edit Mode's (daedalus_ui selection/double-click.js
 * isDoubleClick: the even OS click count on `click`, never `dblclick`),
 * the convention the page elements panel renames by.
 *
 * @param {{detail?: number}} event
 *   The click event.
 * @param {{seat: Function, edit: Function}} gestures
 *   What a single click does and what a double-click does.
 */
export const selectorHeaderClick = (event, { seat, edit }) => {
  if (isDoubleClick(event)) {
    edit();
    return;
  }
  seat();
};

/**
 * The editor's completion source: the one selector completion
 * (element-model.js selectorGestureOptions) over the row's gesture
 * context, the text before the cursor read as the selector being typed
 * (nothing may follow the cursor, as on an island's line). Picking an
 * option rewrites the editor's text to the option's canonical selector
 * and leaves the cursor at its end, so a chain goes on compound by
 * compound; committing stays the designer's Enter or `{`.
 */
const editorCompletions = (context, gesture) => {
  if (!gesture) {
    return null;
  }
  const text = context.state.doc.toString();
  if (text.slice(context.pos).trim() !== '') {
    return null;
  }
  const typed = text.slice(0, context.pos);
  const from = typed.length - typed.trimStart().length;
  if (typed.trim() === '') {
    return null;
  }
  const offer = selectorGestureOptions(typed, gesture);
  if (!offer) {
    return null;
  }
  const pick = (selector) => (view) =>
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: selector },
      selection: { anchor: selector.length },
    });
  // Picking rewrites the text to the option's spelling, a state's
  // included (`a:hover`): the digester judges at the commit.
  const options = selectorCompletionItems(offer, { label: (selector) => selector, apply: pick });
  if (!options.length) {
    return null;
  }
  return { from, options, validFor: selectorOfferValidity(typed.trimStart()) };
};

/**
 * The prelude's edit mode: a one-line CodeMirror editor seeded with the
 * row's selector, driven by selector-edit.js over the digester. Enter
 * or a typed `{` settles (COMMIT hands `onMove(from, to)` to the host,
 * NOOP closes, REFUSED names the digester's reason through the host's
 * failure path and keeps the editor open), Escape or blur cancels
 * (`onClose`), and every keystroke hands the typed text's structural
 * reach, or null, to `onPreview` so the canvas paints it before anything
 * commits; unmounting clears the preview. The editor hosts the same
 * selector completion the islands' lines open, over the row's gesture
 * context (`completions`, the selector gesture's context; null hosts no
 * completion), whose condition table is the digester's roster. A
 * newline is never accepted: the selector is one line.
 *
 * @param {object} props
 *   - selector: the row's canonical selector.
 *   - className: the prelude's class, worn with its `--editing` state.
 *   - completions: the selector gesture context, or null.
 *   - conditions: the published condition table (guide.conditions), the
 *     digester's roster for the peel; the gesture context's when omitted.
 *   - onPreview: (canonical | null) => void, per keystroke.
 *   - onMove: (from, to) => void, on a commit.
 *   - onClose: () => void, on a cancel or a no-op.
 */
export const SelectorEditor = ({
  selector,
  className,
  completions = null,
  conditions = null,
  onPreview,
  onMove,
  onClose,
}) => {
  const hostRef = useRef(null);
  const propsRef = useRef(null);
  propsRef.current = { completions, conditions, onPreview, onMove, onClose };
  useEffect(() => {
    let edit = openSelectorEdit(selector);
    let settled = false;
    const options = () => ({
      conditions: propsRef.current.conditions ?? propsRef.current.completions?.conditions ?? {},
    });
    const dispatch = (outcome) => {
      if (settled) {
        return true;
      }
      if (outcome === null) {
        return false;
      }
      const current = propsRef.current;
      switch (outcome.kind) {
        case COMMIT:
          settled = true;
          current.onMove(outcome.from, outcome.to);
          return true;
        case NOOP:
        case CANCEL:
          settled = true;
          current.onClose();
          return true;
        case REFUSED:
          notify(
            'error',
            Drupal.t('!reason Fix it, or press Escape to keep "!selector".', {
              '!reason': outcome.reason,
              '!selector': outcome.from,
            }),
          );
          return true;
        default:
          return false;
      }
    };
    const gesture = (name) => () => dispatch(selectorEditOutcome(edit, name, options()));
    const view = new EditorView({
      parent: hostRef.current,
      state: EditorState.create({
        doc: selector,
        selection: { anchor: selector.length },
        extensions: [
          EditorState.transactionFilter.of((transaction) =>
            transaction.newDoc.lines > 1 ? [] : transaction,
          ),
          EditorView.lineWrapping,
          tooltips({ parent: tooltipParent(hostRef.current.ownerDocument) }),
          autocompletion({
            override: [(context) => editorCompletions(context, propsRef.current.completions)],
          }),
          EditorView.inputHandler.of((editorView, from, to, inserted) =>
            inserted === '{' ? gesture('{')() : false,
          ),
          keymap.of([
            { key: 'Enter', run: gesture('Enter') },
            { key: 'Escape', run: gesture('Escape') },
          ]),
          EditorView.updateListener.of((update) => {
            if (update.docChanged) {
              edit = typedSelectorEdit(edit, update.state.doc.toString());
              propsRef.current.onPreview?.(selectorEditPreview(edit, options()));
            }
          }),
          EditorView.domEventHandlers({
            blur: () => {
              gesture('blur')();
            },
          }),
        ],
      }),
    });
    view.focus();
    return () => {
      settled = true;
      view.destroy();
      propsRef.current.onPreview?.(null);
    };
  }, []);
  return <span className={`${className} ${className}--editing`} ref={hostRef} />;
};

/**
 * A chrome row's code face: its nested-rule prelude in one `<code>`,
 * the `>`, `+` and `~` combinators rendered as chips; or, while the row
 * is being edited, the selector editor in its place.
 *
 * A row (a condition, a part, the children, a type, a tag, their chains
 * and relations, an ancestor rule) is chrome, never buffer text, so its
 * header names the block twice: the human label (the condition's word,
 * selectorLabel()'s "Children" or "Text blocks", the address words of a
 * rule) and the prelude a designer would write for it (prelude.js:
 * `&:hover`, `> d-text:has(a)`, `@container (max-width: 640px) &`). The
 * code face keeps that spelling as its text (a copy, a test, an
 * assertive eye all read the prelude back) while each symbol combinator
 * wears a chip that says what the symbol means, the one piece of the
 * grammar that is not self-evident to a reader: `>` "Direct children
 * only", `+` "Right after the previous", `~` "After a previous".
 * Descendant combinators are the spaces between compounds and wear
 * nothing; a compound's qualifiers (`:has(a)`, `:not(d-image)`), the
 * anchor and the states print as code text; an at-rule wrapper prints
 * in its own span so the visual system can set it apart. Shared by the
 * element rows and the variant document's rows.
 *
 * The edit mode is the host's to open (a double-click on the row
 * header, selectorHeaderClick): with `edit` given, the face is the
 * SelectorEditor seeded with the selector, and the host closes it by
 * passing null again once the editor reports (onMove, onClose).
 *
 * @param {object} props
 *   - selector: the canonical relative selector, or null for the element.
 *   - condition: the row's condition ('default' when omitted).
 *   - guide: the style guide payload (the condition wrappers).
 *   - className: the `<code>` element's class.
 *   - edit: null for the code face, else the editor's props past the
 *     selector and class ({completions, onPreview, onMove, onClose}).
 */
export const Prelude = ({ selector, condition = 'default', guide = null, className, edit = null }) => {
  if (edit !== null) {
    return <SelectorEditor selector={selector} className={className} {...edit} />;
  }
  const titles = {
    [CHILD]: Drupal.t('Direct children only'),
    [NEXT]: Drupal.t('Right after the previous'),
    [SUBSEQUENT]: Drupal.t('After a previous'),
  };
  const pieces = preludeParts(selector, condition, guide).map((part, index) => {
    if (part.kind === COMBINATOR) {
      return (
        <span
          key={`combinator-${index}`}
          className="daedalus-stylish-islands__combinator"
          title={titles[part.combinator]}
        >
          {part.text}
        </span>
      );
    }
    if (part.kind === WRAPPER) {
      return (
        <span key={`wrapper-${index}`} className="daedalus-stylish-islands__wrapper">
          {part.text}
        </span>
      );
    }
    return part.text;
  });
  return <code className={className}>{pieces}</code>;
};
