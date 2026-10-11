import { h } from 'preact';
import { useEffect, useId, useRef, useState } from 'preact/hooks';
import { layerColor, variantLayerId } from './code/layers.js';
import { listboxTarget } from './palette-picker-model.js';
import { axisLabel } from './variant-scope.js';
import { pickerGroups, pickerHome, pickerIds } from './variant-picker-model.js';

/**
 * The variant picker: the one apply gesture over the offer.
 *
 * A popover the wear bar opens from its add row ("Apply variant",
 * unfiltered) or from a worn row's swap trigger (preset to that row's
 * axis): an autofocused search field over a grouped listbox of the
 * applicable, fit-ranked variants (pickerGroups, the pure half: an
 * owning blueprint's curated looks head their axis under the blueprint's
 * name, the generic offer under the axis's). Type to filter across
 * label, axis label, owner label and description; ArrowUp/Down walk
 * the options across group boundaries (listboxTarget, the palette
 * grid's map); Enter picks the focused option; Escape closes (the
 * popover hook's, focus back on the trigger). A picked option reaches
 * the consumer through `onPick(id)`; the consumer owns the close and the
 * focus return before it acts (the PaletteGrid order), so this component
 * never touches the popover state it renders inside.
 *
 * The combobox idiom rather than a roving-tabindex button pile: DOM focus
 * stays in the search field the whole time (typing and walking are one
 * posture), and the focused option is the field's aria-activedescendant.
 * The listbox owns every key it answers (preventDefault +
 * stopPropagation), so the sidebar never scrolls and the edit-mode
 * hotkey dispatcher never sees the press. A preset axis renders as a
 * chip in the search field; clearing it (its button, or Backspace on an
 * empty field) widens the offer to every axis.
 *
 * @param {object} props
 * @param {Array} props.ranked
 *   The fit-ranked applicable roster (rankVariants' shape).
 * @param {string[]} props.vocabulary
 *   The scope's axis vocabulary.
 * @param {Array<{variant: string, fragment: string}>} props.refs
 *   The element's worn references.
 * @param {?object} props.guide
 *   The style guide (axis labels).
 * @param {object} props.materials
 *   The materials roster (the owner headings' blueprint labels).
 * @param {?string} [props.axis]
 *   The axis to open filtered to (the swap), or null.
 * @param {Function} props.onPick
 *   Called with the picked variant id.
 */
export const VariantPicker = ({
  ranked,
  vocabulary,
  refs,
  guide,
  materials,
  axis: presetAxis = null,
  onPick,
}) => {
  const [search, setSearch] = useState('');
  const [axis, setAxis] = useState(presetAxis);
  const [activeId, setActiveId] = useState(null);
  const input = useRef(null);
  const options = useRef(new Map());
  const baseId = useId();
  const listboxId = `${baseId}-listbox`;
  const optionDomId = (id) => `${baseId}-option-${id}`;

  const groups = pickerGroups({
    ranked,
    vocabulary,
    refs,
    guide,
    materials,
    search,
    axis,
  });
  const ids = pickerIds(groups);
  // A swap opens on the axis's worn variant (the native select's posture
  // of landing on the current value); a search lands on the best match
  // (pickerHome: the typed name over a description that says the word).
  const wornHome =
    axis !== null
      ? (groups.flatMap((group) => group.options).find((option) => option.worn)
          ?.id ?? null)
      : null;
  const roving =
    activeId !== null && ids.includes(activeId)
      ? activeId
      : pickerHome(groups, search, wornHome);
  // Headers are noise when there is nothing to tell apart: an offer that
  // makes one group (one axis, or a swap's preset axis with no owner
  // split). Counted before the search narrows it, so headers never blink
  // while typing; an owner heading beside the axis's own is worth telling
  // apart even under the chip.
  const flat =
    pickerGroups({ ranked, vocabulary, refs, guide, materials, axis }).length <=
    1;
  const axisChip = axis !== null ? axisLabel(guide, axis) : null;

  useEffect(() => {
    input.current?.focus();
  }, []);

  useEffect(() => {
    if (roving !== null) {
      options.current.get(roving)?.scrollIntoView({ block: 'nearest' });
    }
  }, [roving]);

  const widen = () => {
    setAxis(null);
    setActiveId(null);
    input.current?.focus();
  };

  const onKeyDown = (event) => {
    if (event.key === 'Enter') {
      if (roving === null) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      onPick(roving);
      return;
    }
    if (event.key === 'Backspace' && search === '' && axis !== null) {
      event.preventDefault();
      widen();
      return;
    }
    const target = listboxTarget(ids, roving, event.key);
    if (target === null) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    setActiveId(target);
  };

  const hintText = (hint) =>
    Drupal.t('fits !resolved of !total slots', {
      '!resolved': hint.resolved,
      '!total': hint.total,
    });

  return (
    <div className="daedalus-stylish-variant-picker">
      <div className="daedalus-stylish-variant-picker__search">
        {axisChip !== null && (
          <span className="daedalus-stylish-variant-picker__chip">
            <span>{axisChip}</span>
            <button
              type="button"
              className="daedalus-stylish-variant-picker__chip-clear"
              aria-label={Drupal.t('Show every axis')}
              title={Drupal.t('Show every axis')}
              onClick={widen}
            >
              ×
            </button>
          </span>
        )}
        <input
          ref={input}
          type="text"
          className="daedalus-stylish-variant-picker__input daedalus-ui-input"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded="true"
          aria-controls={listboxId}
          aria-activedescendant={
            roving !== null ? optionDomId(roving) : undefined
          }
          aria-label={Drupal.t('Search variants')}
          placeholder={Drupal.t('Search variants')}
          autoComplete="off"
          spellCheck={false}
          value={search}
          onInput={(event) => {
            setSearch(event.target.value);
            setActiveId(null);
          }}
          onKeyDown={onKeyDown}
        />
      </div>
      <div
        id={listboxId}
        className="daedalus-stylish-variant-picker__list"
        role="listbox"
        aria-label={Drupal.t('Variants')}
      >
        {groups.map((group) => (
          <div
            key={group.key}
            className="daedalus-stylish-variant-picker__group"
            role={flat ? undefined : 'group'}
            aria-label={flat ? undefined : group.label}
          >
            {!flat && (
              <div
                className="daedalus-stylish-variant-picker__group-label"
                aria-hidden="true"
              >
                {group.label}
              </div>
            )}
            {group.options.map((option) => {
              const active = option.id === roving;
              return (
                <div
                  key={option.id}
                  id={optionDomId(option.id)}
                  ref={(element) => {
                    if (element) {
                      options.current.set(option.id, element);
                    } else {
                      options.current.delete(option.id);
                    }
                  }}
                  role="option"
                  aria-selected={active}
                  className={`daedalus-stylish-variant-picker__option${active ? ' daedalus-stylish-variant-picker__option--active' : ''}${option.worn ? ' daedalus-stylish-variant-picker__option--worn' : ''}`}
                  onMouseMove={() => {
                    if (!active) {
                      setActiveId(option.id);
                    }
                  }}
                  onClick={() => onPick(option.id)}
                >
                  <span
                    className="daedalus-stylish-variant-picker__dot daedalus-ui-dot"
                    style={{
                      background: layerColor(variantLayerId(option.id)),
                    }}
                  />
                  <span className="daedalus-stylish-variant-picker__label">
                    {option.label}
                  </span>
                  {option.hint !== null && (
                    <span className="daedalus-stylish-variant-picker__hint">
                      {hintText(option.hint)}
                    </span>
                  )}
                  {option.worn && (
                    <span
                      className="daedalus-stylish-variant-picker__worn"
                      title={Drupal.t('Worn')}
                    >
                      ✓
                    </span>
                  )}
                </div>
              );
            })}
          </div>
        ))}
        {ids.length === 0 && (
          <div className="daedalus-stylish-variant-picker__empty" role="status">
            {search !== ''
              ? Drupal.t('No variants match')
              : Drupal.t('Nothing to apply here')}
          </div>
        )}
      </div>
    </div>
  );
};
