/**
 * @file
 * The subject line's pure model: WHAT is being styled and AT which
 * address, the first line of the inspector's sentence (subject → pose →
 * mode → wears → properties).
 *
 * The line is two words. The element word names the selected element
 * the way the page tree and the canvas breadcrumb do (its minted name,
 * `_element.name` on the tree node; "Element" when the tree has no
 * node for the key); the full compound key is
 * demoted to the word's tooltip and its copy gesture, never printed. The
 * address chip names where a change lands, always rendered so the line
 * keeps one shape: its face is the facet's own words ("This element",
 * "This link", "Every link here", a chain's sentence: write-address.js
 * facetLabel), lit while a write lands on an ancestor's rule, and its
 * menu is the address set (write-address.js addressSet) with two kinds
 * of extra entry: when a facet stands, "This element" is the way back
 * to the bare element, the entry that replaced the breadcrumb's ×; and
 * on a root or part facet with a set, "+ Before" / "+ After" at the
 * end are the doorway to the facet's generated boxes (write-address.js
 * boxAddresses), the one way to seat a box since no click lands on
 * one. A doorway pick seats the box as the target and nothing more: no
 * entry exists until the first property write there.
 *
 * Pure and preact-free (tests/js/subject-model.test.mjs); the sentences
 * go through Drupal.t at call time. The component (subject-line.js)
 * maps an item's address onto the spine: a null selector on the selected
 * key reselects the bare element (the spine's "a selection write clears
 * the target"), anything else seats the styling target.
 */
import { boxWord } from './parts.js';
import { addressKey, boxAddresses, facetLabel, sameAddress } from './write-address.js';

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
 *   The ancestor address the rows write at, or null while a write lands
 *   on the own key.
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
 *   string, checked: boolean, hint: ?string, address: {key: string,
 *   selector: ?string}}>}}
 *   `face` the chip's words for the address it stands at, `id` that
 *   address's map key (the chip's data attribute), `lit` true under an
 *   ancestor address, `items` the own address first, then "This
 *   element" when a facet stands, then the ancestor addresses in the
 *   set's order (a mintable one hinted "new rule"), then the facet's
 *   box doorways "+ Before" / "+ After" when the set offers addresses
 *   at all (a root or part facet the panel can address) and the mode
 *   styles boxes.
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
  const seated =
    address === null || address === undefined
      ? null
      : (addresses.find((entry) => sameAddress(entry, address)) ?? null);
  const current = seated ?? { ...ownAddress, label: ownFace };
  const items = [
    { label: ownFace, checked: seated === null, hint: null, address: ownAddress },
    ...(ownAddress.selector === null
      ? []
      : [
          {
            label: Drupal.t('This element'),
            checked: false,
            hint: null,
            address: { key: own.key, selector: null },
          },
        ]),
    ...addresses
      .filter((entry) => !sameAddress(entry, ownAddress))
      .map((entry) => ({
        label: entry.label,
        checked: seated !== null && sameAddress(entry, seated),
        hint: entry.existing ? null : Drupal.t('new rule'),
        address: { key: entry.key, selector: entry.selector },
      })),
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
        }).map(({ key, selector, pseudo }) => ({
          label: Drupal.t('+ @box', { '@box': boxWord(pseudo) }),
          checked: false,
          hint: null,
          address: { key, selector },
        }))
      : []),
  ];
  return {
    face: current.label,
    id: addressKey(current),
    lit: seated !== null,
    items,
  };
};
