/**
 * The row-facts seam: what other modules TELL about rows that already
 * exist, drawn in the outline row grammar's facts zone and offered at the
 * head of the row menu.
 *
 * A view (view-registry.js) changes which rows the tree draws and so what
 * reach offers; a fact changes neither. It is a word about a row the
 * projection already lists (daedalus_stylish: a dot per variant the
 * element wears and one when it is styled on itself; a doorway to where a
 * style comes from). So the seam is deliberately not a view and has no
 * toggle: a provider is on while it is registered, and the tree re-reads
 * every provider on every render, so facts follow the model with no
 * bookkeeping of their own.
 *
 * Provider contract (via the app API: app.tree.registerRowFacts):
 * - id: string (required); the registration key. Registering an id a
 *   second time replaces the earlier provider in place (it keeps its
 *   position in the order), so a module may re-register on its own
 *   re-boot without stacking.
 * - facts(address): pure; the facts for one row, as
 *   [{mark: 'dot' | 'text', hue?, text?, title}], in the order the
 *   provider wants them drawn. `address` is the row's projection-index
 *   address (projection-index.js): an element key for a model row, the
 *   `key::selector` pair for a part row. A 'dot' is a round swatch in
 *   `hue` (a CSS color); a 'text' is its `text` in the grammar's caption
 *   voice (`hue` optional, its color). `title` is the fact's words, the
 *   swatch's tooltip. Empty (or absent) for a row the provider has
 *   nothing to say about. Facts draw in the facts zone AHEAD of the
 *   Markup view's tag, in registration order across providers. Optional.
 * - doorways(address): pure; the row menu items this provider offers for
 *   the row, in the menu grammar (js/menu/menu-state.js: {label, icon?,
 *   title?, hint?, run}), listed AHEAD of the verbs behind one separator
 *   (withDoorways). A doorway leads from a row to where something about
 *   it lives ("Edit {variant}"); it is never a verb on the element (those
 *   are the verb registry's, verb-menu-items.js) and never changes the
 *   selection set the menu was opened on. Optional.
 * - subscribe(callback): optional; the provider announces that its
 *   answers changed from a source outside the store (a style map it
 *   reads). The registry fans the announcement into tree re-renders
 *   (subscribeRowFacts). A provider whose facts ride the store (the
 *   mirror, the materials) needs none: the tree already re-renders on
 *   those.
 *
 * A provider's answers are read per render and per menu open, never
 * stored here. A failing provider is contained (console.error, no facts
 * from it this read), as a failing view provider is in the tree: one
 * broken module's fact must not take the panel's rows with it.
 *
 * No Drupal globals: the tree composes through it and tests drive it bare.
 */

/**
 * The registered providers by id, in registration order (a Map keeps an
 * existing key's position on set, which is the re-registration rule).
 */
const providers = new Map();
const listeners = new Set();
const notify = () => listeners.forEach((callback) => callback());

/**
 * Registers a row-facts provider (via the app API:
 * app.tree.registerRowFacts; the contract is in the file docblock).
 *
 * A malformed provider throws: the tree would read it on its next render
 * anyway, and the registration is the place to say so.
 *
 * @param {object} provider
 *   {id, facts?, doorways?, subscribe?}.
 *
 * @return {function(): void}
 *   Unregisters this provider (a no-op once another registration under
 *   the same id has replaced it).
 */
export const registerRowFacts = (provider) => {
  if (!provider || typeof provider.id !== 'string' || provider.id === '') {
    throw new Error('A row-facts provider requires a string id.');
  }
  for (const entry of ['facts', 'doorways', 'subscribe']) {
    if (provider[entry] !== undefined && typeof provider[entry] !== 'function') {
      throw new Error(
        `Row-facts provider "${provider.id}" names ${entry} but it is not a function.`,
      );
    }
  }
  providers.set(provider.id, provider);
  if (provider.subscribe) {
    provider.subscribe(notify);
  }
  notify();
  return () => {
    if (providers.get(provider.id) === provider) {
      providers.delete(provider.id);
      notify();
    }
  };
};

/**
 * Reads one entry of every registered provider for an address, in
 * registration order, concatenated. A provider without the entry
 * contributes nothing; one whose entry throws is contained.
 */
const readAll = (entry, address) => {
  const answers = [];
  providers.forEach((provider) => {
    if (typeof provider[entry] !== 'function') {
      return;
    }
    try {
      answers.push(...(provider[entry](address) ?? []));
    } catch (error) {
      console.error(
        `Row-facts provider "${provider.id}" failed answering ${entry}:`,
        error,
      );
    }
  });
  return answers;
};

/**
 * The facts for a row, every provider's in registration order.
 *
 * @param {?string} address
 *   The row's projection-index address; null (a keyless row) asks nobody.
 *
 * @return {Array<{mark: string, hue?: string, text?: string, title: string}>}
 */
export const rowFacts = (address) => (address ? readAll('facts', address) : []);

/**
 * The doorways for a row, every provider's in registration order, in the
 * menu grammar.
 *
 * @param {?string} address
 *   The row's projection-index address; null asks nobody.
 *
 * @return {Array<object>}
 */
export const rowDoorways = (address) =>
  address ? readAll('doorways', address) : [];

/**
 * Composes a row menu's item list: the doorways lead, the verbs follow,
 * one separator between them when both sides have items. Either side
 * empty means no separator: the menu primitive would trim a leading or
 * trailing one anyway, but the list this returns is the list the menu
 * gets, so the trim is not relied on.
 *
 * @param {Array<object>} doorways
 *   The row's doorways (rowDoorways).
 * @param {Array<object>} verbs
 *   The selection set's verb items (verb-menu-items.js verbMenuItems).
 *
 * @return {Array<object>}
 *   Items in the menu primitive's grammar.
 */
export const withDoorways = (doorways, verbs) => {
  if (doorways.length === 0 || verbs.length === 0) {
    return [...doorways, ...verbs];
  }
  return [...doorways, { separator: true }, ...verbs];
};

/**
 * Subscribes to the seam's announcements (a registration, an
 * unregistration, a provider's own change); returns unsubscribe. The
 * tree re-renders its rows on each.
 */
export const subscribeRowFacts = (callback) => {
  listeners.add(callback);
  return () => listeners.delete(callback);
};
