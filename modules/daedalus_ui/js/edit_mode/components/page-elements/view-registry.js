/**
 * The tree view seam: an alternative PROJECTION of the page-elements tree
 * renders while its Show-menu toggle is on.
 *
 * The built-in data projection (projection.js) renders the model — plus
 * the instance rows, which are content and render in every view
 * (markup-view.js withInstanceRows); the built-in markup view
 * (markup-view.js, registered at tree init) renders
 * the composed DOM sketch — anatomy is daedalus_ui vocabulary, so both
 * views live here. A contrib module can still register further views of
 * the SAME model through the same registration. The seam is structural:
 * the tree knows how to render and route rows, never what a provider's
 * extra rows mean.
 *
 * Two invariants keep a view swap display-only:
 * - The selection universe never changes with the view. The projection
 *   index (projection-index.js) composes both tiers, element keys
 *   and `key::selector` part addresses, view-independently; a view's
 *   model rows must carry the same selectable keys the built-in
 *   projection would, and its pseudo rows the same (owner, id) pairs the
 *   index addresses, so selection sync, keyboard traversal, and
 *   breadcrumbs are unaffected by which view renders.
 * - Pseudo rows enter the selection spine as the address pair. Clicking
 *   one dispatches setSelected({key: parentKey, part: id}) (the element
 *   key stays the selection identity, the row id rides beside it as
 *   selectedPart), and the row's active state is read back from the
 *   spine, never from provider state.
 *
 * View provider contract:
 * - displayToggle: {id, label} (required) — the Show-menu checkbox that
 *   turns this provider's view on (persisted in the view slice; hidden by
 *   default). Off, the tree renders the built-in projection.
 * - projectTree(root, blueprint): pure; called with the blueprint slice's
 *   root envelope and the delivered blueprint mirror. Returns the view
 *   tree's root node. Nodes are
 *   either MODEL nodes — the built-in projection's shape ({key, kind,
 *   label, presentation, children, editTarget?, formOnly?}, key null for
 *   unselectable rows, plus an optional `sublabel` rendered as muted
 *   secondary text after the label — a view's extra identity for the
 *   row, e.g. the markup class a field row's element wears) rendered as
 *   real tree rows on the selection spine —
 *   or PSEUDO nodes ({pseudo: true, id, parentKey, label, children})
 *   rendered as pseudo-selection rows: id is provider-scoped, parentKey
 *   the owning MODEL key (at any depth — a pseudo row need not sit under
 *   its owner's row). Both node sorts nest children of both sorts; every
 *   row folds.
 * - subscribe(callback): the provider announces model changes from a
 *   source outside the store (the built-in markup view needs none, its
 *   model rides the blueprint mirror); the registry fans them into tree
 *   re-renders. Returns unsubscribe (unused; providers live for the
 *   page).
 *
 * Pseudo-row CLICK, ACTIVE state, and HOVER are not provider callbacks:
 * the tree dispatches the part facets onto the selection spine itself
 * (click commits {parentKey, id} as selectedKey + selectedPart, hover
 * writes hoverPart) and reads the row's active and hot classes back from
 * it (see PartRow), so every part surface agrees through the one store.
 *
 * PART BEHAVIOR: the markup view's shell rows are daedalus_ui
 * structure, but what one OFFERS (a style vocabulary, a selector grammar)
 * is another module's meaning. A part behavior registers here (via the
 * app API: app.tree.registerPartBehavior). One behavior at a time; the
 * last registration wins. None registered = shell rows are
 * display-and-fold only (their clicks and hovers still ride the spine
 * facets; that is spine machinery, not behavior).
 *
 * The behavior is the app's part RESOLUTION seam — how
 * spine consumers (selector-events, canvas paint adapters) translate
 * between the DOM and {parentKey, id} part addresses without learning the
 * behavior module's selector grammar:
 * - resolve(domTarget): the part under a canvas DOM target, as
 *   {parentKey, id, hit} (parentKey the owning element's selection key,
 *   id the part's provider-scoped id, hit the canvas element the part
 *   matched on: callers arbitrating part-vs-element depth need the DOM
 *   position), or null — off-canvas targets, editor chrome, and part-less
 *   markup all resolve to null. Pure lookup, no state. The registry calls
 *   the registered behavior with two predicates after the target (see
 *   registerPartBehavior): `presented(key)`, only presented keys may own
 *   a resolved part, and `placed(key, id)`, only parts the projection
 *   index places as rows may resolve at all (the projection gate covers
 *   both tiers).
 * - selectorFor(parentKey, id, declarations): the complete scoped CSS
 *   rule text applying `declarations` (CSS declarations text) to every
 *   instance of the part in its owner's scope — the behavior owns the
 *   grammar (scope roots, container scope limits, instance parts), so a
 *   painted affordance is a truthful preview of the part's style reach.
 *   Null when id does not address a part.
 */

import store from '../../store/store';
import { projectionIndex, partAddress } from './projection-index';

const providers = [];
let partBehavior = null;
const listeners = new Set();
const notify = () => listeners.forEach((callback) => callback());

/**
 * Registers a tree view provider (via the app API: app.tree).
 */
export const registerTreeViewProvider = (provider) => {
  providers.push(provider);
  if (typeof provider.subscribe === 'function') {
    provider.subscribe(notify);
  }
  notify();
};

/**
 * The projection filter part resolution runs under: the part facet commits
 * its owner as the SELECTION, so the owner must be a presented key. The
 * projection is the selection universe for parts exactly as it is
 * for elements (the resolver's representedChainKeys); without the filter,
 * Cmd+click on a subtree the panel absorbs (a media entity's inner fields
 * under a unit reference) would commit a key no row can name. Inert before
 * the mirror boots, like the element filter.
 */
const presented = (key) => {
  const index = projectionIndex();
  return !index || index.has(key);
};

/**
 * The placement filter part resolution runs under: a part is offered on
 * the canvas only when the projection index carries its `key::id` row,
 * the same row the panel renders and the breadcrumb and traversal walk.
 * Placement is the model's answer (the server annotation's parts and
 * instances, composed into the index), never the DOM's: a part hoisted
 * onto its owner's root (an unlinked image's `.image-block-image` IS the
 * root <img>) has no row, so the canvas must not resolve it either, and
 * self-inclusive DOM containment cannot tell that case from a nested
 * node. A boolean gate, deliberately not placedAddress (a fallback
 * resolver for a committed pair): resolution decides whether to offer a
 * part, not where an already-committed facet seats. Inert before the
 * mirror boots, like the element filter.
 */
const placed = (key, id) => {
  const index = projectionIndex();
  return !index || index.has(partAddress(key, id));
};

/**
 * Registers the part behavior the markup view's shell rows delegate to.
 *
 * The registry owns the projection gate, both tiers of it: the behavior's
 * resolve receives the presented predicate as its second argument and the
 * placed predicate as its third, and considers only presented owners and
 * placed parts, so every consumer of getPartBehavior().resolve() stays
 * inside the selection universe without knowing either filter exists.
 */
export const registerPartBehavior = (behavior) => {
  partBehavior = {
    ...behavior,
    resolve: (target) => behavior.resolve?.(target, presented, placed) ?? null,
  };
  notify();
};

/**
 * The registered part behavior, or null (shell rows render inert).
 */
export const getPartBehavior = () => partBehavior;

/**
 * Subscribes to view changes (registration, hot/active state); returns
 * unsubscribe. The tree re-reads the active provider on each announcement.
 */
export const subscribeTreeViews = (callback) => {
  listeners.add(callback);
  return () => listeners.delete(callback);
};

/**
 * The provider whose view the tree should render, or null (the built-in
 * projection).
 *
 * One view renders at a time: the first registered provider whose
 * Show-menu toggle is checked wins. (The view slice's state key stays
 * `parts` — the historical name — so persisted toggles survive.)
 */
export const activeTreeView = () => {
  const visibility = store.getState().view.parts;
  return (
    providers.find(
      (provider) =>
        provider.displayToggle?.id &&
        visibility[provider.displayToggle.id] === true,
    ) ?? null
  );
};

/**
 * The Show-menu entries view providers contribute: {id, label} per
 * provider. The menu renders one checkbox per entry; none registered (and
 * no other menu content) means no menu at all.
 *
 * @return {Array<{id: string, label: string}>}
 */
export const viewToggles = () =>
  providers
    .filter(
      (provider) => provider.displayToggle?.id && provider.displayToggle?.label,
    )
    .map((provider) => ({
      id: provider.displayToggle.id,
      label: provider.displayToggle.label,
    }));
