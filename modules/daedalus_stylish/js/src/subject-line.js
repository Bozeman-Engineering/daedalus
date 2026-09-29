import { h } from 'preact';
import { useMemo, useState } from 'preact/hooks';
import { useMenu } from './menu.js';
import { createMatcher } from './selector-matcher.js';
import { elementWord, subjectChip } from './subject-model.js';

/**
 * The subject line: the element word and the address chip.
 *
 * The first line of the inspector's sentence, universal: every mode of
 * the styling place and the Materials place mount it for a selection
 * (the model, subject-model.js, says what the two words are). The
 * element word is a copy affordance: its tooltip carries the full
 * compound key the panel targets, a click puts that key on the
 * clipboard, and a drag-selection click is not a copy, the user is
 * taking a substring. The address chip is the write-address switcher
 * (chip selectors `__address`, `__address-label` and the
 * `data-daedalus-stylish-address` attribute are what the FJS scenarios
 * read): its menu seats an entry as the styling target (source.target,
 * the spine's one target state, so the satellite and the canvas see the
 * same pick) or reselects the bare element for the own root entry and
 * the "This element" way back (a selection write clears the target, the
 * spine's invariant). With one entry only (the root of an element the
 * tree cannot place, or a mode that offers no other address) the chip is
 * a still readout with no menu.
 */
export const SubjectLine = ({
  guide,
  source,
  selected,
  targetKey,
  tree,
  own,
  addresses,
  address,
  blockTypes,
  sectionLayouts,
  markup,
  boxes = true,
}) => {
  const menu = useMenu();
  const [copied, setCopied] = useState(false);
  const matcher = useMemo(
    () => (Array.isArray(tree) ? createMatcher(tree) : null),
    [tree],
  );
  const chip = subjectChip({
    guide,
    matcher,
    own,
    addresses,
    address,
    blockTypes,
    sectionLayouts,
    markup,
    boxes,
  });
  const copyKey = () => {
    if (window.getSelection?.().toString()) {
      return;
    }
    navigator.clipboard.writeText(targetKey).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1200);
    });
  };
  const seat = (entry) => {
    if (entry.selector === null && entry.key === selected) {
      source.select(selected);
    } else {
      source.target(entry.key, entry.selector);
    }
  };
  return (
    <div className="daedalus-stylish-inspector__subject">
      <button
        type="button"
        className={`daedalus-stylish-inspector__subject-word${copied ? ' daedalus-stylish-inspector__subject-word--copied' : ''}`}
        title={
          copied
            ? Drupal.t('Copied')
            : `${targetKey}\n${Drupal.t('Click to copy')}`
        }
        onClick={copyKey}
      >
        {elementWord(matcher, selected)}
      </button>
      {chip.items.length > 1 ? (
        <button
          type="button"
          className={`daedalus-stylish-inspector__address daedalus-stylish-chip${chip.lit ? ' daedalus-stylish-chip--lit' : ''}`}
          title={Drupal.t(
            'Where a change lands: this element alone, or a rule on one of its ancestors that reaches it',
          )}
          aria-haspopup="menu"
          data-daedalus-stylish-address={chip.id}
          onClick={(event) => {
            menu.open({
              anchor: event.currentTarget,
              header: Drupal.t('Style this at'),
              items: chip.items.map((item) => ({
                label: item.label,
                checked: item.checked,
                ...(item.hint === null ? {} : { hint: item.hint }),
                run: () => seat(item.address),
              })),
            });
          }}
        >
          <span className="daedalus-stylish-inspector__address-label">
            {chip.face}
          </span>
          <span className="daedalus-stylish-chip__caret" aria-hidden="true">
            ▾
          </span>
        </button>
      ) : (
        <span
          className="daedalus-stylish-inspector__address daedalus-stylish-inspector__address--still daedalus-stylish-chip"
          title={Drupal.t('Where a change lands')}
          data-daedalus-stylish-address={chip.id}
        >
          <span className="daedalus-stylish-inspector__address-label">
            {chip.face}
          </span>
        </span>
      )}
    </div>
  );
};
