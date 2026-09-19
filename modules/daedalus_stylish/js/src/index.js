import { h, render } from 'preact';
import { InspectorHost } from './host.js';
import { createLeaderSource } from './sources/leader-source.js';
import { createPopoutLeader } from './broadcast/popout-leader.js';
import { createPartBehavior } from './tree-parts.js';
import { createCanvasPartPaint } from './canvas-part-paint.js';
import { createCanvasHighlight } from './canvas-highlight.js';
import { createCanvasPaint } from './canvas-paint.js';
import { createForceState } from './force-state.js';
import { uiMode } from './ui-mode.js';
import { stopsFromConditions } from './canvas-stops.js';
import { openPicker as openVariantPicker } from './apply-variant-hotkey.js';
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

  // No channel scope (no derivable main entity on this route) means no
  // pop-out: the panel renders in-page only.
  const popoutSettings = drupalSettings.daedalusStylish?.popout;
  const popout = popoutSettings
    ? createPopoutLeader({ ...popoutSettings, source })
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
  // active state live on the selection spine itself). The canvas part
  // paint rides the same behavior: a spine subscriber rendering the
  // hover/selection part facets as dashed @scope boxes through
  // selectorFor. Guarded: an older daedalus_ui without the behavior
  // seam just renders inert shell rows, and one without the hover facet
  // paints no part boxes.
  if (app.tree?.registerPartBehavior) {
    const partBehavior = createPartBehavior({ source, guide });
    app.tree.registerPartBehavior(partBehavior);
    if (app.onHoverPartChange && app.onPartChange) {
      createCanvasPartPaint({ app, behavior: partBehavior });
    }
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
  // Guarded: an older daedalus_ui without the seam just keeps manual
  // toggling.
  if (app.tree?.setPartVisibility) {
    uiMode.subscribe((mode) =>
      app.tree.setPartVisibility('markup', mode !== 'basic'),
    );
  }

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
  // propagates. Leader only: the satellite window has no dispatcher and
  // keeps the add row as its one apply gesture.
  Drupal.Daedalus.Hotkeys.register({
    id: 'daedalus_stylish.apply_variant',
    key: 'shift+v',
    when: 'selection',
    label: Drupal.t('Apply variant'),
    section: Drupal.t('Styling'),
    remappable: true,
    action: () => {
      const panel = document.getElementById('daedalus_stylish_inspector');
      if (!panel?.classList.contains('daedalus-ui-hidden')) {
        return openVariantPicker();
      }
      Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector')
        .then(() => openVariantPicker())
        .catch((error) => {
          // A same-slot panel refusing to close (an invalid daedalus_edit form)
          // has already told the user; anything else is a real failure.
          if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
            throw error;
          }
        });
      return true;
    },
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
          animations={drupalSettings.daedalusStylish?.animations ?? {}}
          previewWindow={
            drupalSettings.daedalusUi?.responsivePreview ?? null
          }
        />,
        mount,
      );
    },
  };
  Drupal.behaviors.daedalusStylishInspector.attach();
});
