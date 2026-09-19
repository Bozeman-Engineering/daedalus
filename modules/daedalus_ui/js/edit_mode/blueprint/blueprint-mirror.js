import store from '../store/store';
import { blueprintBooted } from '../store/slices/blueprint-slice';
import { createEnvelopeSync } from '../utilities/settings-envelope';

/**
 * Boots the blueprint mirror from drupalSettings.
 *
 * The envelope is a JSON STRING, not an object, synced by string equality
 * (settings-envelope.js explains why). Sync is idempotent and re-checked
 * on every behavior attach, never init-only: the settings can land after
 * the bundle initialized (a cold non-edit load entering Edit Mode via
 * AJAX) or be refreshed by a LoadEditablePage re-render's settings merge.
 *
 * The delivered blueprint carries the spine's lean node tree as `_tree`
 * (kind-discriminated container/region/block nodes, styles on the node) —
 * the shape the projection, the markup index, and componentForUuid read;
 * the classic layout_builder__layout shape rides beside it for the
 * consumers not yet ported.
 */

const syncFromSettings = createEnvelopeSync(
  () => drupalSettings.daedalusUi?.blueprint,
  (envelope) => store.dispatch(blueprintBooted(envelope)),
);

export const initBlueprintMirror = () => {
  syncFromSettings();
  Drupal.behaviors.NpBlueprintMirror = {
    attach: () => syncFromSettings(),
  };
};
