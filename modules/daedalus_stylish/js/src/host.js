import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { Inspector } from './inspector.js';
import { IconButton } from './icon-button.js';
import { PanelClose } from './panel-close.js';
import { useConcealed } from './reveal.js';

const PopOutIcon = () => (
  <svg
    viewBox="0 0 256 256"
    width="16"
    height="16"
    fill="currentColor"
    aria-hidden="true"
  >
    <path d="M224,104a8,8,0,0,1-16,0V59.32l-66.33,66.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z" />
  </svg>
);

/**
 * The in-page host: the inspector, or its stand-in while popped out.
 *
 * The pop-out is host chrome, not inspector behavior — the Inspector
 * component itself is host-agnostic (it also renders in the satellite
 * window). This wrapper owns the in-page half: the header button that
 * opens the satellite, and the placeholder that keeps the panel honest
 * about where its controls went while the satellite lives. Both in-page
 * states are rail panels, so both headers end with the rail's close
 * control (Inspector's `rail` prop; the placeholder's own cluster).
 *
 * @param {object} props
 * @param {object} props.source
 *   The leader state source.
 * @param {?object} props.popout
 *   The pop-out leader, or null when the route has no channel scope (no
 *   derivable main entity) — then no pop-out control renders at all.
 * @param {object} [props.guide]
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {object} [props.variants]
 *   The variant gesture gates (drupalSettings.daedalusStylish.variants).
 * @param {object} [props.swatches]
 *   The swatch gesture gates (drupalSettings.daedalusStylish.swatches).
 * @param {object} [props.animations]
 *   The animation gesture gates (drupalSettings.daedalusStylish.animations).
 * @param {?object} [props.previewWindow]
 *   The preview-window opener {url, scope} for the chip row's width-menu
 *   tail, composed by the entry point from daedalusUi.responsivePreview.url
 *   and daedalusUi.editSession.scope.
 */
export const InspectorHost = ({
  source,
  popout,
  guide = {},
  variants = {},
  swatches = {},
  animations = {},
  previewWindow = null,
}) => {
  const [poppedOut, setPoppedOut] = useState(
    popout ? popout.isPoppedOut() : false,
  );
  // The placeholder header's action cluster conceals at clean like the
  // inspector's own.
  const placeholderActionsRef = useConcealed();

  // Subscribe, THEN re-read — see the matching comment in inspector.js.
  useEffect(() => {
    if (!popout) {
      return undefined;
    }
    const unsubscribe = popout.onChange(setPoppedOut);
    setPoppedOut(popout.isPoppedOut());
    return unsubscribe;
  }, [popout]);

  if (poppedOut) {
    return (
      <div className="daedalus-stylish-inspector daedalus-stylish-inspector--popped-out">
        <div className="daedalus-stylish-inspector__header daedalus-ui-panel__header">
          <h3 className="daedalus-stylish-inspector__title">{Drupal.t('Styles')}</h3>
          <div
            className="daedalus-stylish-inspector__actions"
            data-daedalus-ui-conceal="clean"
            ref={placeholderActionsRef}
          >
            <PanelClose />
          </div>
        </div>
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t('The style inspector is open in its own window.')}
        </div>
        <button
          type="button"
          className="daedalus-stylish-inspector__bring-back daedalus-ui-pill-button"
          onClick={() => popout.bringBack()}
        >
          {Drupal.t('Bring it back')}
        </button>
      </div>
    );
  }

  const headerActions = popout && (
    <IconButton
      label={Drupal.t('Open in a new window')}
      onClick={() => popout.open()}
    >
      <PopOutIcon />
    </IconButton>
  );

  return (
    <Inspector
      source={source}
      guide={guide}
      headerActions={headerActions}
      rail
      variants={variants}
      swatches={swatches}
      animations={animations}
      previewWindow={previewWindow}
    />
  );
};
