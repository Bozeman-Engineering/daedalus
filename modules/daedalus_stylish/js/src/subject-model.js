/**
 * @file
 * The subject line's pure model: WHAT is being styled and AT which
 * address, the first line of the inspector's sentence (subject → pose →
 * mode → wears → properties).
 *
 * The line is a clause: the selection pill, "at", the address chip. The
 * pill names the selected element the way the page tree and the canvas
 * chips do, in the outline row grammar's selected-row dress: the kind
 * icon (`_element.icon` on the tree node, a URL; none when the node
 * carries none) and the minted name (`_element.name`; "Element" when the
 * tree has no node for the key). The full compound key is never
 * printed: it rides the chip's menu as "Copy key". The address chip
 * names where a change lands, always rendered so the line keeps one
 * shape: its face is the facet's own words ("This element", "This
 * link", "Every link here", a chain's sentence: write-address.js
 * facetLabel), lit while a write lands at an address other than the
 * subject's own key, and its menu is the address set (write-address.js
 * addressSet) with two kinds of extra entry: when a facet stands, "This
 * element" is the way back to the bare element, the entry that replaced
 * the breadcrumb's ×; and on a root or part facet with a set, "+
 * Before" / "+ After" at the end are the doorway to the facet's
 * generated boxes (write-address.js boxAddresses), the one way to seat
 * a box since no click lands on one. A doorway pick seats the box as
 * the subject facet and nothing more: no entry exists until the first
 * property write there. An address the set does not carry (the spine
 * admits any ancestor; a programmatic seat can name one the switcher
 * never offered) still faces the chip, lit, and lists checked with a
 * hint saying so: nothing drops it, the rows are withheld by reach.
 *
 * Pure and preact-free (tests/js/subject-model.test.mjs); the sentences
 * go through Drupal.t at call time. Each item says how it seats
 * (`seat`), and the component (subject-line.js) maps that onto the
 * spine: 'element' reselects the bare element (the spine's "a selection
 * write clears the facet and the address"), 'own' drops the write
 * address (`address(null)`, the subject's own key again), 'address'
 * seats the write address, 'box' seats the box as the subject facet;
 * it appends the "Copy key" item after the address items itself. Each
 * item also says what it PREVIEWS (`preview`): the pair the canvas
 * paints while the item is the menu's active one, before any pick, so
 * the reach of "Every list item in Card" is visible under the pointer
 * (or the keyboard focus) and not only after the click. It is the
 * item's own address whenever that address names a selector (the own
 * facet, an ancestor rule, a tag tier, a box) and null for the bare
 * element ("This element" either way: the element's solid outline
 * already stands, there is no selector to paint). The component drives
 * the spine's hover-selector facet with it, the same facet code mode's
 * row headers and the page tree's part rows write, so the canvas's
 * hover slot (canvas-selector-paint.js) paints it dashed and yields
 * when the pair is already the facet or the address.
 */
import { boxWord } from './parts.js';
import {
  addressKey,
  addressLabel,
  boxAddresses,
  facetLabel,
  ownerName,
  sameAddress,
} from './write-address.js';

/**
 * The element word: the name the tree carries for the key (the
 * annotator's minted `_element.name`: the custom label, the content
 * excerpt or the type word, one vocabulary with the page tree, the
 * canvas chips and the AI), else "Element" for a key the tree has no
 * node for.
 *
 * @param {?object} matcher
 *   A matcher over the tree (selector-matcher.js createMatcher), or null.
 * @param {string} key
 *   The selected element's selectable key.
 *
 * @return {string}
 */
export const elementWord = (matcher, key) =>
  matcher?.facts?.(key)?.name ?? Drupal.t('Element');

/**
 * The element's face: the glyph the tree carries for the key (the
 * annotator's minted `_element.icon`, a URL, the same spine as the
 * word: the matcher's facts), else null when the tree has no node for
 * the key, the node carries no icon, or there is no tree at all. The
 * pill omits its icon zone on null, as the page tree does.
 *
 * @param {?object} matcher
 *   A matcher over the tree (selector-matcher.js createMatcher), or null.
 * @param {string} key
 *   The selected element's selectable key.
 *
 * @return {?string}
 */
export const elementIcon = (matcher, key) =>
  matcher?.facts?.(key)?.icon ?? null;

/**
 * The address chip: its face, its map key, whether it is lit, and its
 * menu items in switcher order.
 *
 * @param {object} args
 * @param {object} args.guide
 *   The style guide payload.
 * @param {?object} args.matcher
 *   A matcher over the tree, or null.
 * @param {{key: string, selector: ?string}} args.own
 *   The selected facet's own address (the selected key, its selector or
 *   null for the root).
 * @param {Array<{key: string, selector: ?string, label: string,
 *   existing: boolean}>} args.addresses
 *   The facet's address set (addressSet), [] when the panel offers none
 *   (no tree, a chain facet, a mode or posture that writes nowhere else).
 * @param {?{key: string, selector: string}} args.address
 *   The write address the rows write at (the spine's, when it is not
 *   the subject's own key), or null while a write lands on the own key.
 * @param {object} [args.blockTypes]
 * @param {object} [args.sectionLayouts]
 * @param {?object} [args.markup]
 *   The selected key's `_element.markup` annotation, or null.
 * @param {boolean} [args.boxes]
 *   Whether the panel's mode can style a generated box (advanced and
 *   code); false withholds the box doorways (basic mode mounts no rows
 *   at a box, so a seat there would land on an empty panel).
 *
 * @return {{face: string, id: string, lit: boolean, items: Array<{label:
 *   string, checked: boolean, hint: ?string, seat: string, address:
 *   {key: string, selector: ?string}, preview: ?{key: string, selector:
 *   string}}>}}
 *   `face` the chip's words for the address it stands at, `id` that
 *   address's map key (the chip's data attribute), `lit` true under a
 *   write address, `items` the own address first (`seat` 'own'), then
 *   "This element" when a facet stands ('element'), then the addresses
 *   in the set's order ('address'; a mintable one hinted "new rule"),
 *   then a standing address the set does not carry, checked and hinted
 *   as such, then the facet's box doorways "+ Before" / "+ After"
 *   ('box') when the set offers addresses at all (a root or part facet
 *   the panel can address) and the mode styles boxes. Every item's
 *   `preview` is its address when that carries a selector, else null.
 */
export const subjectChip = ({
  guide,
  matcher,
  own,
  addresses,
  address,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
  boxes = true,
}) => {
  const ownAddress = { key: own.key, selector: own.selector ?? null };
  const ownEntry = addresses.find((entry) => sameAddress(entry, ownAddress));
  const ownFace =
    ownEntry === undefined
      ? facetLabel(guide, matcher, ownAddress, blockTypes, sectionLayouts, markup)
      : ownEntry.label;
  const standing =
    address === null || address === undefined || sameAddress(address, ownAddress)
      ? null
      : address;
  const member =
    standing === null
      ? null
      : (addresses.find((entry) => sameAddress(entry, standing)) ?? null);
  // A standing address the set does not carry words itself the way the
  // set would have: its facet face on the selected key, the rule's
  // sentence in its owner on an ancestor.
  const stranger =
    standing === null || member !== null
      ? null
      : {
          ...standing,
          label:
            standing.key === ownAddress.key
              ? facetLabel(guide, matcher, standing, blockTypes, sectionLayouts, markup)
              : addressLabel(
                  guide,
                  standing,
                  ownerName(matcher, standing.key),
                  blockTypes,
                  sectionLayouts,
                ),
        };
  const seated = member ?? stranger;
  const current = seated ?? { ...ownAddress, label: ownFace };
  // What the canvas paints while the item is active: the address itself
  // when it names a selector, nothing for the bare element.
  const previewOf = (pair) => (pair.selector === null ? null : { ...pair });
  const item = (fields) => ({ ...fields, preview: previewOf(fields.address) });
  const items = [
    item({
      label: ownFace,
      checked: seated === null,
      hint: null,
      seat: 'own',
      address: ownAddress,
    }),
    ...(ownAddress.selector === null
      ? []
      : [
          item({
            label: Drupal.t('This element'),
            checked: false,
            hint: null,
            seat: 'element',
            address: { key: own.key, selector: null },
          }),
        ]),
    ...addresses
      .filter((entry) => !sameAddress(entry, ownAddress))
      .map((entry) =>
        item({
          label: entry.label,
          checked: seated !== null && sameAddress(entry, seated),
          hint: entry.existing ? null : Drupal.t('new rule'),
          seat: 'address',
          address: { key: entry.key, selector: entry.selector },
        }),
      ),
    ...(stranger === null
      ? []
      : [
          item({
            label: stranger.label,
            checked: true,
            hint: Drupal.t('not an address of this element'),
            seat: 'address',
            address: { key: stranger.key, selector: stranger.selector },
          }),
        ]),
    // The doorway to the facet's generated boxes, where the set is
    // offered at all (it always carries the own address then) and the
    // mode has rows for a box (basic mode has none: its rows are the
    // declared controls, and no box declares any).
    ...(addresses.length && boxes
      ? boxAddresses({
          guide,
          key: ownAddress.key,
          selector: ownAddress.selector,
          blockTypes,
          sectionLayouts,
        }).map(({ key, selector, pseudo }) =>
          item({
            label: Drupal.t('+ !box', { '!box': boxWord(pseudo) }),
            checked: false,
            hint: null,
            seat: 'box',
            address: { key, selector },
          }),
        )
      : []),
  ];
  return {
    face: current.label,
    id: addressKey(current),
    lit: seated !== null,
    items,
  };
};
