import { createGeometryTracker } from '../utilities/geometry-tracker';
import { elementForKey } from './resolver';

/**
 * The selectable-key flavor of the geometry tracker.
 *
 * Tracks canvas elements by their selectable key: resolution is the
 * selection universe's own lookup (elementForKey), and a cached node is
 * stale the moment it stops carrying the key it was resolved for, which is
 * how a markup swap that reuses DOM nodes gets caught before isConnected
 * would notice. The outline tracks its boxes through this; exported to
 * contrib via the registration app (app.geometry.createSelectableTracker)
 * for chrome that follows the selection, e.g. daedalus_stylish's spacing bands.
 *
 * @return {ReturnType<typeof createGeometryTracker>}
 */
export const createSelectableTracker = () =>
  createGeometryTracker({
    resolve: elementForKey,
    validate: (element, key) => element.getAttribute('data-selectable') === key,
  });
