import { projectionIndex } from '../components/page-elements/projection-index';
import { capableIn, selectableIn, verdictIn } from './capable-model';

export {
  CAPABILITIES,
  isCapability,
  capableIn,
  capableInTree,
  selectableIn,
  verdictIn,
  verdictInTree,
} from './capable-model';

/**
 * THE client capability gate: whether the published verdict on a key
 * allows a capability, against the live projection index.
 *
 * The app-side read of the spine's verdict (the model and its words are
 * capable-model.js). Every gesture asks here: the verb registry's applies
 * gate, the drag controller (arrange of a moved element, insert of a
 * destination), the tree's rename, paste styles, the file-drop faces, the
 * Add panel's click-to-place, the stylish inspector. Exposed to modules as
 * `Drupal.Daedalus.Selection.capable`.
 *
 * @param {?string} key
 *   The selectable key or part address.
 * @param {string} capability
 *   One of CAPABILITIES: content, configure, style, arrange, insert.
 *
 * @return {boolean}
 */
export const capable = (key, capability) =>
  capableIn(projectionIndex(), key, capability);

/**
 * Whether a key is SELECTABLE: its published verdict allows anything at
 * all (capable-model.js selectableIn), against the live projection
 * index. The one predicate every road to a selection asks: the pointer
 * chain (resolver.js representedChainKeys), the editing gate
 * (keyForEditableItem), reach, commitAddress and the store's selection
 * gate, so neither the facade, the tree, a verb nor a styling source can
 * select an element the actor can do nothing to. Exposed to modules as
 * `Drupal.Daedalus.Selection.selectable`.
 *
 * @param {?string} key
 *   The selectable key or part address.
 *
 * @return {boolean}
 */
export const selectable = (key) => selectableIn(projectionIndex(), key);

/**
 * The published verdict on a key, or null when none is placed for it.
 *
 * @param {?string} key
 *   The selectable key or part address.
 *
 * @return {?Object<string, boolean>}
 */
export const verdictFor = (key) => verdictIn(projectionIndex(), key);
