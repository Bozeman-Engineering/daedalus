import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';

/**
 * The rail panel close control: the preact twin of daedalus_ui's
 * SidebarPluginBase::buildPanelClose() markup.
 *
 * Every PHP-rendered panel header ends its action cluster with this
 * control (the header grammar); the inspector renders its own header in
 * preact, so it wears the same control as JSX. Same classes (the kit's
 * icon button plus .daedalus-ui-panel__close, so panel-controls.css's two-class
 * recipe sizes it), same data-sidebar-close hook (daedalus_ui's
 * sidebar-close behavior resolves the panel it sits in and closes it
 * through SidebarManager.closeSidebar(), the one door every open/close
 * goes through), same delegated data-daedalus-ui-tooltip, and the same pack glyph
 * (daedalus_ui:minus, inlined because preact cannot call the Twig
 * icon() function). The one difference: no data-daedalus-ui-conceal of its own,
 * because daedalus_stylish's action cluster already conceals at clean as a unit
 * (the PHP grammar's other actions never conceal, so there the close
 * carries its own tier).
 *
 * Rail only: the satellite window renders outside any [data-sidebar-type],
 * where a close would silently no-op, so satellite.js never renders it.
 */
export const PanelClose = () => {
  const ref = useRef(null);
  // The sidebar-close behavior binds per element (once) at attach time,
  // and this control mounts after the page attach (and again on every
  // pop-out round trip), so attach behaviors to it here. once() scans a
  // context's descendants, hence the parent.
  useEffect(() => {
    Drupal.attachBehaviors(ref.current.parentNode);
  }, []);
  return (
    <button
      type="button"
      className="daedalus-ui-panel__close daedalus-ui-icon-button daedalus-ui-icon-button--muted"
      data-sidebar-close=""
      aria-label={Drupal.t('Close !panel', { '!panel': Drupal.t('Styles') })}
      data-daedalus-ui-tooltip={Drupal.t('Close')}
      ref={ref}
    >
      <svg
        className="daedalus-ui-panel__close-icon"
        viewBox="0 0 256 256"
        width="16"
        height="16"
        fill="currentColor"
        aria-hidden="true"
      >
        <path d="M224,128a8,8,0,0,1-8,8H40a8,8,0,0,1,0-16H216A8,8,0,0,1,224,128Z" />
      </svg>
    </button>
  );
};
