import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { useMenu } from './menu.js';
import { chipFaces, chipMenuItems, systemScheme } from './chip-row-model.js';
import { bandRangeText } from './canvas-stops.js';

/**
 * The chip row: the inspector's pose, the pose group's first line.
 *
 * State · Width · Scheme · Motion, pinned under the subject line and
 * above the mode row (inspector.js; the Styled at line, styled-at.js,
 * sits beneath it in the widget modes), standard across basic,
 * advanced, and code (the Materials catalog poses nothing, so the
 * inspector renders no row there) and the satellite window, which
 * renders this same component (its source relays the writes over the
 * BroadcastChannel). Each chip mirrors its axis of the canvas state
 * vector (plus the selection-scoped force state) and opens a checkmarked
 * menu that poses that ONE axis; a lit chip contributes a part to the
 * editing compound, so the row IS the Editing-at readout. All derivation
 * lives in chip-row-model.js; this component only maps the typed
 * descriptors to translated strings and dispatches the picked actions:
 * force state through the source, vector poses as partial writes through
 * the source's canvas-vector seam, and the preview-window tail through
 * daedalus_ui's one opener (Drupal.Daedalus.openPreviewWindow; leader
 * page only — the satellite host passes previewWindow as false, so the
 * tail honestly stays away there).
 */

/**
 * A face/label descriptor as user text (chip-row-model.js's vocabulary).
 * Site vocabulary (condition labels) arrives resolved; everything fixed
 * translates here so the strings stay extractable.
 */
const descriptorText = (axis, descriptor) => {
  switch (descriptor.kind) {
    case 'axis-name':
      return axis === 'state' ? Drupal.t('State') : Drupal.t('Motion');
    case 'no-state':
      return Drupal.t('No state');
    case 'condition':
      return descriptor.label;
    case 'base':
      return Drupal.t('Base');
    case 'scheme':
      return descriptor.value === 'dark' ? Drupal.t('Dark') : Drupal.t('Light');
    case 'motion':
      return (
        {
          auto: Drupal.t('Auto'),
          full: Drupal.t('Full motion'),
          reduce: Drupal.t('Reduced'),
        }[descriptor.value] ?? descriptor.value
      );
    case 'preview-window':
      return Drupal.t('Open preview window');
    default:
      return '';
  }
};

const itemLabel = (axis, item) => {
  const text = descriptorText(axis, item.label);
  return item.label.system
    ? Drupal.t('!value (system)', { '!value': text })
    : text;
};

const hintText = (hint) => {
  if (!hint) {
    return undefined;
  }
  if (hint.kind === 'range') {
    return bandRangeText(hint);
  }
  return {
    auto: Drupal.t('edits apply everywhere'),
    light: Drupal.t('only in light'),
    dark: Drupal.t('only in dark'),
  }[hint.value];
};

const CHIP_TITLES = () => ({
  state: Drupal.t('Force an interaction state on the selected element'),
  width: Drupal.t('Pose the canvas width'),
  scheme: Drupal.t('Pose the canvas color scheme'),
  motion: Drupal.t('Pose the canvas motion preference'),
});

/**
 * @param {object} props
 * @param {object} props.source
 *   The state source (leader or satellite).
 * @param {object} props.guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?object} props.vector
 *   The canvas state vector snapshot.
 * @param {?string} props.forceState
 *   The selection-scoped force state.
 * @param {boolean} props.hasSelection
 *   Whether an element is selected (gates the state chip).
 * @param {Array<object>} props.literals
 *   The literal clauses in use on the selected element's rows
 *   (editing-at.js literalClauses): each posable one is a chip entry on
 *   its axis.
 * @param {boolean} props.previewWindow
 *   Whether a preview window is available to open (the Edit mode
 *   attached daedalusUi.responsivePreview to this page); false where the
 *   host has none (the satellite window), and the width menu drops its
 *   tail.
 */
export const ChipRow = ({
  source,
  guide,
  vector,
  forceState,
  hasSelection,
  literals = [],
  previewWindow = false,
}) => {
  const conditions = guide.conditions ?? {};
  // The resolved system scheme, kept live: the scheme chip's resting face
  // follows the OS (never "Auto"), so an OS-level flip re-renders it.
  const [scheme, setScheme] = useState(() =>
    systemScheme(window.matchMedia?.bind(window)),
  );
  useEffect(() => {
    const query = window.matchMedia?.('(prefers-color-scheme: dark)');
    if (!query?.addEventListener) {
      return undefined;
    }
    const onChange = () => setScheme(query.matches ? 'dark' : 'light');
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, []);

  // The chip dropdown (the shared primitive, anchored under the chip) and
  // which axis has it open: the chip counts as inside the menu's dismissal
  // boundary, so a click on the open chip toggles it closed instead of
  // instantly re-opening. The ref decides (synchronously: open and close
  // can land within one render), the state paints aria-expanded.
  const menu = useMenu();
  const openAxisRef = useRef(null);
  const [openAxis, setOpenAxis] = useState(null);
  const noteOpen = (axis) => {
    openAxisRef.current = axis;
    setOpenAxis(axis);
  };

  const dispatch = (action) => {
    if (action.type === 'force-state') {
      source.setForceState?.(action.state);
    } else if (action.type === 'pose') {
      source.setCanvasVector?.({ [action.axis]: action.value });
    } else if (action.type === 'preview-window' && previewWindow) {
      // daedalus_ui's one opener: the window's name, its features and the
      // pop-out route contract live there (daedalus_ui/preview_window).
      Drupal.Daedalus.openPreviewWindow();
    }
  };

  const items = (axis) =>
    chipMenuItems(axis, {
      vector,
      forceState,
      conditions,
      literals,
      scheme,
      previewWindow,
    }).map((item) =>
      item.separator
        ? item
        : {
            label: itemLabel(axis, item),
            hint: hintText(item.hint),
            checked: item.checked,
            run: () => dispatch(item.action),
          },
    );

  const titles = CHIP_TITLES();
  const chips = chipFaces(vector, forceState, conditions, {
    scheme,
    hasSelection,
    literals,
  });

  return (
    <div className="daedalus-stylish-chip-row">
      {chips.map((chip) => (
        <button
          key={chip.axis}
          type="button"
          className={[
            'daedalus-stylish-chip',
            `daedalus-stylish-chip--${chip.axis}`,
            chip.lit ? 'daedalus-stylish-chip--lit' : '',
          ]
            .filter(Boolean)
            .join(' ')}
          title={
            chip.axis === 'state' && chip.disabled
              ? Drupal.t('Select an element to force a state')
              : chip.face.kind === 'base'
                ? Drupal.t(
                    'Base styles apply at every width unless a width condition overrides them',
                  )
                : titles[chip.axis]
          }
          disabled={chip.disabled}
          aria-haspopup="menu"
          aria-expanded={openAxis === chip.axis}
          onClick={(event) => {
            if (openAxisRef.current === chip.axis) {
              menu.close();
              return;
            }
            // open() replaces any open menu (its onClose clears the axis
            // first), so the axis is recorded after the call.
            const opened = menu.open({
              anchor: event.currentTarget,
              items: items(chip.axis),
              onClose: () => noteOpen(null),
            });
            noteOpen(opened ? chip.axis : null);
          }}
        >
          {descriptorText(chip.axis, chip.face)}
          <span className="daedalus-stylish-chip__caret" aria-hidden="true">
            ▾
          </span>
        </button>
      ))}
    </div>
  );
};
