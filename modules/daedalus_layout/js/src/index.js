import { h, render } from 'preact';
import { AddPanel } from './components/add-panel.js';
import { createInsertSession } from './insert-session.js';
import { installBlueprintGestures } from './blueprint-gestures.js';
import { MOUNT_ID } from './panel-dom.js';

/**
 * @file
 * The Add panel bundle's entry: mounts the panel into its sidebar mount.
 *
 * The panel is a bundled Preact surface: one renderer over two feeds
 * (the server's faces and the materials store's blueprints), drawn as
 * folding sets with counts, search, a star and a menu. Its pure model
 * lives in ./model/ and is everything the node tier sees; the component
 * is ./components/add-panel.js; the insert session (the drag sources,
 * the Insert verb, click-to-place, the empty canvas) is
 * ./insert-session.js; a blueprint row's gestures are
 * ./blueprint-gestures.js; the peek's request is ./peek.js and its
 * frame ./components/peek-frame.js; the DOM contract the component and
 * the session meet at is ./panel-dom.js.
 *
 * Registration rides the DaedalusUiQueue seam, as daedalus_stylish's and
 * daedalus_explode's bundles do: the only load-time act is pushing a
 * callback, so this bundle never races the app bundle. The panel markup
 * (the header and the mount) renders server-side (PlaceBlock) and
 * persists across mode and tool switches, so one mount per page lifetime
 * suffices. The behavior covers a panel re-delivered later (an editable-
 * page re-render replaces the markup); the immediate attach() covers the
 * normal case where the app boots after the initial behavior round has
 * already passed.
 */
(window.DaedalusUiQueue ||= []).push((app) => {
  installBlueprintGestures();
  const session = createInsertSession(app);

  Drupal.behaviors.daedalusLayoutAddPanel = {
    attach: () => {
      const mount = document.getElementById(MOUNT_ID);
      if (!mount || mount.dataset.daedalusLayoutAddPanelMounted) {
        return;
      }
      mount.dataset.daedalusLayoutAddPanelMounted = 'true';
      render(
        <AddPanel
          app={app}
          session={session}
          settings={drupalSettings.daedalusLayout?.addPanel ?? {}}
        />,
        mount,
      );
    },
  };
  Drupal.behaviors.daedalusLayoutAddPanel.attach();
});
