import { h, render } from 'preact';
import { InspectorHost } from './host.js';
import { createLeaderSource } from './sources/leader-source.js';
import { createPopoutLeader } from './broadcast/popout-leader.js';
import { createPartBehavior } from './tree-parts.js';
import { createCanvasSelectorPaint } from './canvas-selector-paint.js';
import { createCanvasHighlight } from './canvas-highlight.js';
import { createCanvasPaint } from './canvas-paint.js';
import { createForceState } from './force-state.js';
import { uiMode } from './ui-mode.js';
import { stopsFromConditions } from './canvas-stops.js';
import { openPicker as openVariantPicker } from './apply-variant-hotkey.js';
import { createVariantDoors, createVariantRowFacts } from './row-facts.js';
import { registerCollapseHotkeys } from '../../../daedalus_ui/js/outline/collapse-hotkeys.js';
import { OUTLINE_ID, readOutline, applyOutline } from './code/collapse-seam.js';
import { copyStyles, pasteStyles } from './copy-styles.js';
// Registers the media library selection AJAX command handler (the client
// half of DaedalusStylishMediaLibraryOpener); pickers import openMediaLibrary.
import './media-library.js';

/**
 * The in-page entry point: mounts the inspector into its sidebar panel.
 *
 * Registration rides the DaedalusUiQueue seam — the only load-time act
 * is pushing a callback, so this bundle never races the app bundle. The
 * panel markup renders server-side (InspectorSidebar) and persists across
 * mode and tool switches, so one mount per page lifetime suffices. The
 * behavior covers panels that arrive later via AJAX replacement; the
 * immediate attach() covers the normal case where the app boots after the
 * initial behavior round has already passed.
 */
(window.DaedalusUiQueue ||= []).push((app) => {
  // The guide payload feeds the leader source too (not just the panel): the
  // live-preview printer dispatches on its condition wrapper table.
  const guide = drupalSettings.daedalusStylish?.guide ?? {};

  // The canvas ruler's width bands are daedalus_stylish vocabulary (the
  // container-breakpoint conditions and their thresholds); register them on
  // daedalus_ui's canvas state vector through the plain global queue —
  // no load-order coupling in either direction. The payload is the band
  // shape itself ({min?, max?, label, name}); the unconditioned base band
  // above every ceiling has no condition, so it deliberately isn't here
  // and the ruler leaves that space bandless.
  (window.DaedalusUiCanvasStops ||= []).push(
    ...stopsFromConditions(guide.conditions),
  );
  // The media uuid -> URL table for preview-side token resolution, seeded
  // from the host's style map server-side (InspectorSidebar).
  const source = createLeaderSource(
    app,
    guide,
    drupalSettings.daedalusStylish?.mediaUrls ?? {},
  );

  // No pop-out URL (no derivable main entity on this route) means no
  // pop-out: the panel renders in-page only. The channel scope is the edit
  // session's identity, daedalus_ui's one delivery (editSession.scope);
  // the URL already forwards it as ?scope per the pop-out route contract.
  const popoutSettings = drupalSettings.daedalusStylish?.popout;
  const popout = popoutSettings
    ? createPopoutLeader({
        scope: drupalSettings.daedalusUi.editSession.scope,
        url: popoutSettings.url,
        source,
      })
    : null;

  // The satellite and the in-page panel are alternatives, not layers:
  // while a satellite window lives, the sidebar panel closes; when the
  // satellite goes (bye), the panel it displaced comes back. Keyed to
  // satellite PRESENCE rather than the pop-out click so a satellite
  // adopted after a leader refresh closes the panel too, and a blocked
  // window.open never strands a closed panel. The reopen is symmetric:
  // only a panel this wiring closed (never one the user closed
  // themselves) and only while Edit Mode is still active — a satellite
  // outliving the mode session must not drag the inspector open on exit.
  if (popout) {
    let displacedPanel = false;
    popout.onChange((poppedOut) => {
      const sidebars = Drupal.Daedalus.SidebarManager;
      if (poppedOut) {
        const open = sidebars.getOpenPanel('right', 'top');
        if (open?.id === 'daedalus_stylish_inspector') {
          displacedPanel = true;
          sidebars.closeSidebar('daedalus_stylish_inspector');
        }
      } else if (displacedPanel) {
        displacedPanel = false;
        if (Drupal.Daedalus.getCookieValue('navigationMode') === 'edit') {
          sidebars.openSidebar('daedalus_stylish_inspector');
        }
      }
    });
  }

  // The markup view in the page-elements tree is daedalus_ui's own;
  // daedalus_stylish registers the part BEHAVIOR its shell rows delegate to: the
  // "style" badge and the part resolution seam (part clicks, hover, and
  // active state live on the selection spine itself). The canvas
  // selector paint rides the same behavior: a spine subscriber rendering
  // the hover facet and the subject facet as dashed @scope boxes and the
  // write address's matches (the spine's address, the switcher's pick)
  // as dotted ones through selectorFor, so the chosen rule's reach shows
  // before its first write. Guarded: an older daedalus_ui without the
  // behavior seam just renders inert shell rows, and one without the
  // hover facet paints no selector boxes.
  if (app.tree?.registerPartBehavior) {
    const partBehavior = createPartBehavior({ source, guide });
    app.tree.registerPartBehavior(partBehavior);
    if (app.onHoverSelectorChange && app.onTargetChange) {
      createCanvasSelectorPaint({ app, behavior: partBehavior });
    }
  }

  // The inspector's sidebar panel: whether it is showing, and opening
  // it (the sidebar manager's open, the same path as the panel's toggle)
  // for the gestures that land something in it. The inspector stays
  // mounted while its panel is closed, so a gesture can run with the
  // panel closed and nobody would see it land; these open it first. A
  // same-slot panel refusing to close (an invalid daedalus_edit form)
  // has already told the user, so the open resolves false and the
  // gesture stays undone; anything else is a real failure.
  const PANEL_ID = 'daedalus_stylish_inspector';
  const isPanelOpen = () => {
    const panel = document.getElementById(PANEL_ID);
    return Boolean(panel) && !panel.classList.contains('daedalus-ui-hidden');
  };
  const openPanel = () => {
    if (isPanelOpen()) {
      return Promise.resolve(true);
    }
    if (!document.getElementById(PANEL_ID)) {
      return Promise.resolve(false);
    }
    return Drupal.Daedalus.SidebarManager.openSidebar(PANEL_ID).then(
      () => true,
      (error) => {
        if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
          throw error;
        }
        return false;
      },
    );
  };

  // The variant row facts: what a page elements row says about styling
  // (a dot per worn variant in its hue, one in the element hue when the
  // element is styled on itself) and the doorways from the row to where
  // that styling lives ("Edit {variant}", "Outline everything wearing
  // it", "Show its own styles in code"), through daedalus_ui's row-facts
  // seam. Always on while registered: variants have no switch. The
  // doors reach the mounted inspector through its doors seam
  // (inspector-doors.js) and, popped out, the satellite's inspector over
  // stylish's protocol (row-facts.js createVariantDoors). Guarded: an
  // older daedalus_ui without the seam shows rows without facts.
  if (app.tree?.registerRowFacts) {
    app.tree.registerRowFacts(
      createVariantRowFacts({
        source,
        doors: createVariantDoors({ source, popout, mode: uiMode, openPanel }),
      }),
    );
  }

  // The canvas highlight: transient tint chrome for invisible-geometry
  // properties (padding/margin bands, gap strips), rendered from the
  // source's property-keyed canvas-highlight state on daedalus_ui's
  // shared per-frame geometry tracker. Guarded — an older daedalus_ui
  // without the geometry seam just shows no bands.
  if (app.geometry?.createTracker) {
    createCanvasHighlight({ app, source });
    // The paint canvas: Outline Variant projected onto the page — outline
    // chrome per covered element in the shared focus treatment, plus the
    // matching row marks in the page elements panel (app.tree.setRowMarks
    // — one gesture, both halves), driven from the source's canvas-paint
    // state over client-side map refs (canvas-paint.js). Same geometry
    // seam, same guard.
    createCanvasPaint({ app, source });
  }

  // The selection-scoped force state: the inspector's "Editing
  // at" state axis stamps data-force-state on the selected element's
  // canvas markup, which the printers' pseudo-condition compile matches —
  // DevTools :hov without the real interaction. Leader-only, like the
  // highlight: satellite force-state intents land in the source, and the
  // stamping subscribes there.
  createForceState({ source });

  // The UI mode drives daedalus_ui's Show-menu markup toggle:
  // advanced and code flip the markup view on, basic flips it off (basic
  // is the only mode over the simple page-elements tree). Mirrored on
  // CHANGE only (never on load), so within a mode the Show menu stays
  // user-controllable. The subscription also hears satellite-window
  // switches (the storage event relays them into this window's store).
  uiMode.subscribe((mode) =>
    app.tree.setViewVisibility('markup', mode !== 'basic'),
  );

  // The apply-variant hotkey: shift+v with a selection opens the variant
  // picker for it, through the opener seam the mounted wear bar fills
  // (apply-variant-hotkey.js). Registered in daedalus_ui's registry
  // like every other Edit Mode key, so the hotkey tray lists it under
  // Styling and the user can remap it; the selection context keeps it
  // out of typing (inputs, contenteditable, CKEditor) and off an empty
  // canvas. The inspector stays mounted while its panel is closed, so a
  // closed panel is opened first (the sidebar manager's open, the same
  // path as the panel's toggle) and the picker follows once it is
  // visible: the press is handled either way, so it never propagates.
  // With the panel open, the action returns false when no wear bar is
  // mounted or it declines (no offer, an apply in flight), so the press
  // propagates. Registered here only because the leader alone resolves
  // keys: a shift+v pressed in the satellite window reaches this action
  // through daedalus_ui's pop-out keyboard wire, and the picker opens
  // where the panel is. While the panel is popped out the sidebar stays
  // closed (the satellite displaced it) and the open is routed to the
  // satellite over stylish's own protocol (open-variant-picker); that
  // wire is asynchronous, so the routed press counts as handled and
  // the satellite's own wear bar decides the rest in its window.
  Drupal.Daedalus.Hotkeys.register({
    id: 'daedalus_stylish.apply_variant',
    key: 'shift+v',
    when: 'selection',
    label: Drupal.t('Apply variant'),
    section: Drupal.t('Styling'),
    remappable: true,
    action: () => {
      if (popout?.isPoppedOut()) {
        popout.openVariantPicker();
        return true;
      }
      if (isPanelOpen()) {
        return openVariantPicker();
      }
      openPanel().then((open) => open && openVariantPicker());
      return true;
    },
  });

  // Code mode's collapse-all and expand-all: daedalus_ui's collapse
  // registrar (the page elements tree's, under this outline's own id),
  // listed in the tray's Panels section and unbound by default (the
  // tree holds 3 and 4, both outlines can be open at once and both
  // bindings live in the global context; the user binds this pair in
  // the tray). Registered at leader boot like the apply-variant hotkey
  // and for the same reason: the leader alone resolves keys, and its
  // own page view is unmounted exactly while the panel is popped out.
  // The actions read and land the posture through the seam the mounted
  // page view fills (code/collapse-seam.js); popped out, the GESTURE is
  // routed to the satellite over stylish's protocol (collapse-outline)
  // and lands on the satellite's own posture, since a collapse is a
  // reading posture of one window. The routed press counts as handled
  // like any bound key; with no view mounted the model returns the same
  // posture and the landing is a no-op.
  registerCollapseHotkeys({
    id: OUTLINE_ID,
    label: Drupal.t('Code mode'),
    read: readOutline,
    apply: (next, gesture) => {
      if (popout?.isPoppedOut()) {
        popout.collapseOutline(gesture);
      } else {
        applyOutline(next);
      }
    },
  });

  // The styles clipboard: mod+alt+c copies the styling target's own
  // document (its entries and worn variants, copy-styles.js), mod+alt+v
  // lands it at the target under the selection as one batch, one undo.
  // The bindings the design tools use for copy/paste properties, leaving
  // mod+c / mod+v to the browser and to element copy. Registered on the
  // leader for the same reason as apply_variant: keys resolve here, a
  // press in the satellite is forwarded, and the slot lives in this
  // window; the toasts land on this page's messenger. No panel needs
  // opening: the gesture reads the map and writes the op queue, so it
  // works with the inspector closed.
  Drupal.Daedalus.Hotkeys.register({
    id: 'daedalus_stylish.copy_styles',
    key: 'mod+alt+c',
    when: 'selection',
    label: Drupal.t('Copy styles'),
    section: Drupal.t('Styling'),
    remappable: true,
    action: () => copyStyles(source),
  });
  Drupal.Daedalus.Hotkeys.register({
    id: 'daedalus_stylish.paste_styles',
    key: 'mod+alt+v',
    when: 'selection',
    label: Drupal.t('Paste styles'),
    section: Drupal.t('Styling'),
    remappable: true,
    action: () => pasteStyles(source, guide),
  });

  Drupal.behaviors.daedalusStylishInspector = {
    attach: () => {
      const mount = document.getElementById('daedalus-stylish-inspector-root');
      if (!mount || mount.dataset.daedalusStylishMounted) {
        return;
      }
      mount.dataset.daedalusStylishMounted = 'true';
      render(
        <InspectorHost
          source={source}
          popout={popout}
          guide={guide}
          variants={drupalSettings.daedalusStylish?.variants ?? {}}
          swatches={drupalSettings.daedalusStylish?.swatches ?? {}}
          tokens={drupalSettings.daedalusStylish?.tokens ?? {}}
          animations={drupalSettings.daedalusStylish?.animations ?? {}}
          fonts={drupalSettings.daedalusStylish?.fonts ?? {}}
          skin={drupalSettings.daedalusStylish?.skin ?? {}}
          previewWindow={
            // The opener's {url, scope}, composed from the two settings
            // the Edit mode attaches together (the preview route's URL and
            // the session identity); the satellite host composes none.
            drupalSettings.daedalusUi?.responsivePreview
              ? {
                  url: drupalSettings.daedalusUi.responsivePreview.url,
                  scope: drupalSettings.daedalusUi.editSession.scope,
                }
              : null
          }
        />,
        mount,
      );
    },
  };
  Drupal.behaviors.daedalusStylishInspector.attach();
});
