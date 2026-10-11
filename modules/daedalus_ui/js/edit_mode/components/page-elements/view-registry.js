/**
 * The tree view seam: an alternative PROJECTION of the page-elements tree
 * renders while its Show-menu toggle is on.
 *
 * The built-in data projection (projection.js) renders the model — plus
 * the instance rows, which are content and depicted in every view
 * (markup-view.js withInstanceRows); the built-in markup view
 * (markup-view.js, registered at tree init) renders
 * the composed DOM sketch — anatomy is daedalus_ui vocabulary, so both
 * views live here. A contrib module can still register further views of
 * the SAME model through the same registration. The seam is structural:
 * the tree knows how to render and route rows, never what a provider's
 * extra rows mean.
 *
 * Two concepts keep the views and the spine honest with each other:
 * - EXISTENCE is view-independent. The projection index
 *   (projection-index.js) composes both tiers, element keys and
 *   `key::selector` part addresses, whatever the Show menu says; a view's
 *   model rows must carry the same selectable keys the built-in
 *   projection would, and its pseudo rows the same (owner, id) pairs the
 *   index addresses, so a seat, a label and an ancestry mean the same
 *   thing in every view.
 * - REACH is the view's. What the user may currently land on from an
 *   entry road (the canvas pointer's depth stop, the keyboard's next
 *   row, the breadcrumb's crumbs, a lifted Explode plane) is exactly the
 *   rows the panel depicts: every index entry records the `source` its
 *   row was composed with ('element', 'instance', or a view id), and
 *   reach.js answers `depicted(source, view visibility)` over the index
 *   (element and instance rows always; a view's rows while its toggle is
 *   on). Flipping a view therefore changes what is REACHABLE, never what
 *   EXISTS: a programmatic seat of an unreachable address (the facade's
 *   target(), a styling module's switcher, code mode, the AI) still
 *   stands, riding its element with a target crumb.
 * - Pseudo rows enter the selection spine as the address pair. Clicking
 *   one selects parentKey and targets the row id under it
 *   (commit-address.js: the element key stays the selection identity,
 *   the row id, the part's selector, rides beside it as the styling
 *   target), and the row's active state is read back from the spine,
 *   never from provider state.
 *
 * View provider contract:
 * - displayToggle: {id, label} (required) — the Show-menu checkbox that
 *   turns this provider's view on (persisted in the view slice; hidden by
 *   default). Off, the tree renders the built-in projection.
 * - projectTree(root, blueprint): pure; called with the blueprint slice's
 *   root envelope and the delivered blueprint mirror. Returns the view
 *   tree's root node. Nodes are
 *   either MODEL nodes — the built-in projection's shape ({key, kind,
 *   label, elementType?, icon?, presentation, children, editTarget?,
 *   formOnly?}, key null for unselectable rows; the row draws `icon`
 *   (a URL) in its icon zone, `label` as its name and an optional `tag`
 *   (the element tag the row rendered as) as a fact at its end) rendered
 *   as real tree rows on the selection spine —
 *   or PSEUDO nodes ({pseudo: true, id, parentKey, label, source,
 *   children, elementType?, icon?, tag?}) rendered as pseudo-selection
 *   rows with the same zones: id is provider-scoped,
 *   parentKey the owning MODEL key (at any depth — a pseudo row need not
 *   sit under its owner's row), source the row's origin for reach
 *   ('instance' for content, otherwise the provider's own displayToggle
 *   id, so the row is reachable exactly while the view is on). Both node
 *   sorts nest children of both sorts; every row folds.
 * - subscribe(callback): the provider announces model changes from a
 *   source outside the store (the built-in markup view needs none, its
 *   model rides the blueprint mirror); the registry fans them into tree
 *   re-renders. Returns unsubscribe (unused; providers live for the
 *   page).
 *
 * Pseudo-row CLICK, ACTIVE state, and HOVER are not provider callbacks:
 * the tree writes the selection spine itself (click selects parentKey
 * and targets {parentKey, id}, hover writes hoverSelector) and reads the
 * row's active and hot classes back from it (see PartRow), so every part
 * surface agrees through the one store.
 *
 * PART BEHAVIOR: the markup view's shell rows are daedalus_ui
 * structure, but what one OFFERS (a style vocabulary, a selector grammar)
 * is another module's meaning. A part behavior registers here (via the
 * app API: app.tree.registerPartBehavior). One behavior at a time; the
 * last registration wins. None registered = shell rows are
 * display-and-collapse only (their clicks and hovers still ride the spine's
 * target and hover facet; that is spine machinery, not behavior).
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
 *   a resolved part, and `reachable(key, id)`, only parts whose row the
 *   panel currently depicts may resolve at all (reach.js: the index has
 *   the `key::id` row AND its source is depicted under the view
 *   visibility). The behavior never learns the gate: it names its third
 *   predicate whatever it likes and asks it per candidate.
 * - selectorFor(parentKey, selector, declarations): the complete scoped
 *   CSS rule text applying `declarations` (CSS declarations text) to
 *   every match of a relative selector in its owner's scope (a part's
 *   '.cls', the children '> *', a type '> pb-text') — the behavior owns
 *   the grammar (scope roots, container scope limits, the printed
 *   targets), so a painted affordance is a truthful preview of the
 *   selector's style reach. Null when the selector is outside the grammar.
 * - matches(parentKey, selector): {elements, pseudo}, the live canvas
 *   elements the same selector hits under its owner, the DOM twin of
 *   selectorFor (the printed rule and this list must agree, since a
 *   consumer reads what the rule paints: daedalus_explode measures each
 *   match's computed position before it prints a label anchor through
 *   selectorFor). Every match, document order; empty when the owner is
 *   off the canvas or the selector outside the grammar. A selector
 *   addressing a GENERATED BOX (the grammar's terminal `::before` /
 *   `::after`) has no node to match: `elements` are then the box's
 *   hosts and `pseudo` names the box ('before' / 'after'; null for an
 *   element selector), so a consumer reads the box's computed style as
 *   getComputedStyle(host, '::before') and one that measures geometry
 *   withholds (a box has no rect; the host's is not its). Optional; a
 *   behavior without it offers selector reach as CSS only.
 * - generated(key): the generated boxes the behavior's cascade
 *   materializes on an element, [{id, pseudo, label}] in grammar order
 *   (id the box's relative selector, '&::before'; label its words,
 *   "Before" / "After"). Existence is the behavior's cascade fact, never
 *   anatomy's (a box exists iff something delivers content at it), so
 *   the tree renders these as pseudo rows under the element's row, id as
 *   the row id, exactly as it renders the anatomy's part rows: clicking
 *   one seats {parentKey: key, id} as the styling target, and the
 *   projection index places the row at `key::&::before`. Read through
 *   generated-rows.js, which memoizes the answer on the mirror, the
 *   materials and the behavior (the tree and the index ask for every
 *   keyed row). Optional; a behavior without it lists no boxes.
 * - label(key, selector): the words for a relative selector under an
 *   owner ("Every link", "Raster"), the behavior module's own vocabulary
 *   (daedalus_stylish's selectorLabel), read by the breadcrumb's target
 *   crumb (label-for-key.js targetLabel); optional, the raw selector
 *   stands in when absent.
 *
 * SHOW-MENU ITEMS: the panel's Show kebab is the tree's one menu, and its
 * list is the view toggles above. A module whose entry belongs in that
 * menu without being a view of the tree (daedalus_explode's Explode, a
 * canvas projection toggled from the Page elements panel) registers an
 * item here (via the app API: app.tree.registerShowMenuItem) rather than
 * reaching into the kebab. The menu renders the items after the toggles,
 * behind a separator (show-menu-entries.js composes the list).
 *
 * Show-menu item contract:
 * - id: string (required); the registration key. Re-registering an id
 *   replaces the earlier item in place.
 * - label: string (required); the item's words.
 * - icon: optional inline SVG markup, the menu grammar's icon.
 * - checked(): boolean; read when the menu opens, never stored here. The
 *   owner answers from wherever its state lives (a panel's DOM open
 *   state), so the menu shows the truth without a second record.
 * - run(): what activating the item does (the menu closes first, as any
 *   activation does).
 */

import store from '../../store/store';
import { projectionIndex, selectorAddress } from './projection-index';
import { reachPredicate } from './reach';
import { withShowMenuItem } from './show-menu-entries';

const providers = [];
let partBehavior = null;
let menuItems = [];
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
 * The projection filter part resolution runs under: a part target commits
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
 * The reach gate part resolution runs under: a part is offered on the
 * canvas only when the panel currently depicts its row, which is
 * reach.js's answer over the projection index (the `key::id` row exists
 * AND its source is depicted under the view visibility: an instance
 * carrier in every view, a declared shell part while the Markup view is
 * on). Existence is the model's answer (the server annotation's parts and
 * instances, composed into the index), never the DOM's: a part hoisted
 * onto its owner's root (an unlinked image's `.image-block-image` IS the
 * root <img>) has no row, so the canvas must not resolve it either, and
 * self-inclusive DOM containment cannot tell that case from a nested
 * node. Reach is the view's: with the Markup view off the shell row is
 * not drawn, so Cmd-click and the picker stop at the element, exactly
 * where the keyboard and the breadcrumb stop. A boolean gate,
 * deliberately not seatAddress (a fallback resolver for a committed
 * pair): resolution decides whether to OFFER a part, not where an
 * already-committed facet seats; a programmatic seat of an unreachable
 * part still stands. One predicate per resolution, closed over the
 * index and visibility at the gesture, like the walks. Before the mirror
 * boots nothing exists, so no part is offered (the element filter is
 * inert then, but no canvas gesture precedes the synchronous boot).
 */
const reachableRow = () => {
  const reach = reachPredicate();
  return (key, id) => reach(selectorAddress(key, id));
};

/**
 * Registers the part behavior the markup view's shell rows delegate to.
 *
 * The registry owns the gate, both tiers of it: the behavior's resolve
 * receives the presented predicate as its second argument and the reach
 * predicate as its third, and considers only presented owners and
 * reachable parts, so every consumer of getPartBehavior().resolve() stays
 * inside what the panel depicts without knowing either filter exists.
 */
export const registerPartBehavior = (behavior) => {
  partBehavior = {
    ...behavior,
    resolve: (target) =>
      behavior.resolve?.(target, presented, reachableRow()) ?? null,
  };
  notify();
};

/**
 * The registered part behavior, or null (shell rows render inert).
 */
export const getPartBehavior = () => partBehavior;

/**
 * Registers a Show-menu item (via the app API: app.tree.registerShowMenuItem;
 * the contract is in the file docblock). Keyed by id: a second
 * registration under the same id replaces the first. Announces to the
 * same listeners a provider registration does, so the kebab re-reads.
 *
 * A malformed item throws: the menu primitive would refuse it at open
 * time anyway, and a registration is the place to say so.
 */
export const registerShowMenuItem = (item) => {
  if (!item || typeof item.id !== 'string' || item.id === '') {
    throw new Error('A Show-menu item requires a string id.');
  }
  if (typeof item.label !== 'string' || item.label === '') {
    throw new Error(`Show-menu item "${item.id}" requires a label.`);
  }
  if (typeof item.checked !== 'function' || typeof item.run !== 'function') {
    throw new Error(
      `Show-menu item "${item.id}" requires checked() and run() functions.`,
    );
  }
  menuItems = withShowMenuItem(menuItems, item);
  notify();
};

/**
 * The registered Show-menu items, registration order (a replacement keeps
 * its predecessor's place). The kebab composes them behind the view
 * toggles (show-menu-entries.js); none registered and no toggles means no
 * menu at all.
 *
 * @return {Array<{id: string, label: string, icon?: string,
 *   checked: function(): boolean, run: function(): void}>}
 */
export const showMenuItems = () => menuItems;

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
 * Show-menu toggle is checked (the view slice's `views` map) wins.
 */
export const activeTreeView = () => {
  const visibility = store.getState().view.views;
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
 * no registered Show-menu items) means no menu at all.
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
