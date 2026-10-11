import { h } from 'preact';
import { useMemo } from 'preact/hooks';
import { writeClipboardText } from './clipboard.js';
import { failureText } from './gesture-transport.js';
import { useMenu } from './menu.js';
import { notify } from './notify.js';
import { previewPair } from './preview-pair.js';
import { createMatcher } from './selector-matcher.js';
import { elementIcon, elementWord, subjectChip } from './subject-model.js';

/**
 * The subject line: the selection pill, "at", the address chip.
 *
 * The first line of the inspector's sentence, universal: every mode of
 * the styling place and the Materials place mount it for a selection
 * (the model, subject-model.js, says what the words are). The pill is
 * the selected element in the outline row grammar's selected-row dress
 * (daedalus_ui panel-controls.css: the wash, the kind icon as a mask,
 * the name in the selection color), so it reads as "the selected
 * element" to anyone who has used the page elements panel; it is a
 * readout, not a control, and nothing happens on a click (a click on a
 * selected row never copies). The muted "at" turns the chip into the
 * clause it is: where a change lands. The address chip is the
 * write-address switcher (chip selectors `__address`, `__address-label`
 * and the `data-daedalus-stylish-address` attribute are what the FJS
 * scenarios read): its menu seats an entry as the spine's WRITE ADDRESS
 * (source.address, so the satellite and the canvas see the same pick;
 * the subject facet stays, the rows stay its), drops the address for
 * the own entry (address(null)), reselects the bare element for the
 * "This element" way back (a selection write clears facet and address,
 * the spine's invariant), and seats a box doorway as the subject facet
 * (source.target: a box is a facet of its host, and no click can seat
 * one). The model says which (subject-model.js, `seat`). While an entry
 * is the menu's active item (under the pointer or the keyboard focus,
 * the primitive's one fact) the canvas previews it: the entry's
 * `preview` pair (the model again) drives the spine's hover-selector
 * facet through the one preview road (preview-pair.js, shared with the
 * provenance row menu's "Edit at"), so canvas-selector-paint.js paints
 * the rule's reach dashed before any pick and the pop-out gets the same
 * through its hover-selector intent; the primitive un-previews before
 * it runs the pick, so the dashed preview hands straight over to the
 * seated address's dotted paint. The menu always opens,
 * even with one address: after the address items and a separator,
 * "Copy key" puts the full compound key the panel targets on the
 * clipboard through the clipboard door (clipboard.js) and the messenger
 * says "Copied key" (the key is never printed on the line). The item is
 * never withheld: on a plain-http page, where the browser has no
 * clipboard API, the door rejects and the messenger says the door's
 * sentence instead, a toast rather than a greyed item, because the menu
 * is the only road to the key and nothing on the line can wear the
 * reason before the click.
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
  const icon = elementIcon(matcher, selected);
  const copyKey = () => {
    // The door never throws: on a plain-http page it rejects with the
    // editor's sentence, so the item stays on the menu and the messenger
    // says why, never the console.
    writeClipboardText(() => Promise.resolve(targetKey))
      .then(() => notify('status', Drupal.t('Copied key')))
      .catch((error) => {
        notify(
          'error',
          failureText(error) || Drupal.t('The key could not be copied.'),
        );
      });
  };
  const seat = (item) => {
    switch (item.seat) {
      case 'element':
        source.select(selected);
        break;
      case 'own':
        source.address(null);
        break;
      case 'box':
        source.target(selected, item.address.selector);
        break;
      default:
        source.address(item.address.key, item.address.selector);
        break;
    }
  };
  return (
    <div className="daedalus-stylish-inspector__subject">
      <span className="daedalus-stylish-inspector__subject-word daedalus-ui-outline__row daedalus-ui-outline__row--selected">
        {icon && (
          <span
            className="daedalus-ui-outline__icon"
            aria-hidden="true"
            style={{ '--daedalus-outline-icon': `url("${icon}")` }}
          ></span>
        )}
        <span className="daedalus-ui-outline__name">
          {elementWord(matcher, selected)}
        </span>
      </span>
      <span className="daedalus-stylish-inspector__subject-at">
        {Drupal.t('at')}
      </span>
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
            items: [
              ...chip.items.map((item) => ({
                label: item.label,
                checked: item.checked,
                ...(item.hint === null ? {} : { hint: item.hint }),
                ...(item.preview === null
                  ? {}
                  : {
                      preview: (active) =>
                        previewPair(source, item.preview, active),
                    }),
                run: () => seat(item),
              })),
              { separator: true },
              { label: Drupal.t('Copy key'), run: copyKey },
            ],
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
    </div>
  );
};
