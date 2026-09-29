import { h } from 'preact';
import {
  CHILD,
  NEXT,
  SUBSEQUENT,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { COMBINATOR, WRAPPER, preludeParts } from './prelude.js';

/**
 * A chrome row's code face: its nested-rule prelude in one `<code>`,
 * the `>`, `+` and `~` combinators rendered as chips.
 *
 * A row (a condition, a part, the children, a type, a tag, their chains
 * and relations, an ancestor rule) is chrome, never buffer text, so its
 * header names the block twice: the human label (the condition's word,
 * selectorLabel()'s "Children" or "Text blocks", the address words of a
 * rule) and the prelude a designer would write for it (prelude.js:
 * `&:hover`, `> pb-text:has(a)`, `@container (max-width: 640px) &`).
 * The code face keeps that spelling as its text (a copy, a test, an
 * assertive eye all read the prelude back) while each symbol combinator
 * wears a chip that says what the symbol means, the one piece of the
 * grammar that is not self-evident to a reader: `>` "Direct children
 * only", `+` "Right after the previous", `~` "After a previous".
 * Descendant combinators are the spaces between compounds and wear
 * nothing; a compound's qualifiers (`:has(a)`, `:not(pb-image)`), the
 * anchor and the states print as code text; an at-rule wrapper prints
 * in its own span so the visual system can set it apart. Shared by the
 * element rows and the variant document's rows.
 *
 * @param {object} props
 *   - selector: the canonical relative selector, or null for the element.
 *   - condition: the row's condition ('default' when omitted).
 *   - guide: the style guide payload (the condition wrappers).
 *   - className: the `<code>` element's class.
 */
export const Prelude = ({ selector, condition = 'default', guide = null, className }) => {
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
