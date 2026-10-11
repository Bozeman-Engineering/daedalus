import { foldSummary } from './code/page-model.js';
import { ELEMENT_LAYER, layerColor, layerScope } from './code/layers.js';
import { findVariant } from './variant-scope.js';
import {
  DOOR_EDIT,
  DOOR_OUTLINE,
  openDocument,
  toggleOutline,
} from './inspector-doors.js';

/**
 * @file
 * The variant row-facts provider: what the page elements panel's rows
 * say about styling, through daedalus_ui's row-facts seam
 * (app.tree.registerRowFacts; the contract is
 * daedalus_ui/js/edit_mode/components/page-elements/row-facts-registry.js).
 *
 * A row's facts are its wearing story as dots, the derivation code mode's
 * collapsed rows already read (page-model.js foldSummary): one dot per
 * variant the element wears, in the variant's identity hue (layers.js
 * layerColor, the same color the wear bar's dots, the code view's run
 * labels and the library's dots speak), titled with the variant's label,
 * and one dot in the element hue when the element carries declarations of
 * its own. Nothing says "no styles": a bare row has no facts, so the facts
 * zone is quiet exactly where there is nothing to tell. Always on (there
 * is no switch for variants); the provider re-announces when the style
 * map or the materials roster changes, since both live outside
 * daedalus_ui's store.
 *
 * A row's doorways lead from the row to where its styling comes from, in
 * the row menu ahead of the element's verbs: per worn variant, "Edit
 * {label}" (the editing session on the variant, anchored on this row's
 * element, exactly what code mode's wearing row opens) and "Outline
 * everything wearing it" (the one toggled Outline Variant gesture: the
 * canvas outline plus the matching row marks, canvas-paint.js; checked
 * while on, read from the source's paint state so the leader's menu is
 * honest while the inspector is popped out); and, for an element styled
 * on itself, "Show its own styles in code" (select it, code mode, the
 * styling place, the panel open). The gestures reach the Inspector
 * through the doors seam (inspector-doors.js) and, while the panel is
 * popped out, over the pop-out protocol to the satellite's inspector.
 *
 * The pure half (variantFacts, variantDoorways, outlinedIn) is
 * data-in/data-out for node-test backpressure; createVariantRowFacts
 * binds it to a source and the doors, and createVariantDoors builds the
 * doors over the hosts' seams.
 */

/**
 * The provider's registration id.
 */
export const VARIANTS_PROVIDER_ID = 'daedalus_stylish.variants';

/**
 * A variant's label from the roster, its record id when the roster does
 * not carry it (a stale wearing still reads as something).
 */
const labelOf = (variants, reference) =>
  findVariant(variants, reference)?.label ?? reference;

/**
 * Whether a canvas-paint spec outlines a reference (a toggled layer, not
 * the hover flash).
 *
 * @param {?{layers: Array<{variant: ?string, flash: boolean}>}} paint
 *   The source's canvas paint (getCanvasPaint).
 * @param {string} reference
 *   The variant's record id.
 *
 * @return {boolean}
 */
export const outlinedIn = (paint, reference) =>
  (paint?.layers ?? []).some(
    (layer) => layer.variant === reference && !layer.flash,
  );

/**
 * The facts for one row: a dot per worn variant, then the element's own
 * dot when it has declarations of its own.
 *
 * @param {?object} map
 *   The style map.
 * @param {string} address
 *   The row's projection-index address: an element key, or `key::selector`
 *   for a part row (a part's own map entry and its continuations).
 * @param {Array<object>} variants
 *   The variant records (the materials roster's variants).
 *
 * @return {Array<{mark: string, hue: string, title: string}>}
 */
export const variantFacts = (map, address, variants) => {
  const { count, worn } = foldSummary(map, address);
  const facts = worn.map((layer) => ({
    mark: 'dot',
    hue: layerColor(layer),
    title: Drupal.t('Wears !label', {
      '!label': labelOf(variants, layerScope(layer)),
    }),
  }));
  if (count > 0) {
    facts.push({
      mark: 'dot',
      hue: layerColor(ELEMENT_LAYER),
      title: Drupal.formatPlural(
        count,
        'Styled on itself: 1 declaration',
        'Styled on itself: @count declarations',
      ),
    });
  }
  return facts;
};

/**
 * The doorways for one row, in the menu grammar
 * (daedalus_ui/js/menu/menu-state.js).
 *
 * @param {?object} map
 *   The style map.
 * @param {string} address
 *   The row's element key (the row menu opens on a single element).
 * @param {Array<object>} variants
 *   The variant records.
 * @param {object} doors
 *   - outlined: function(reference): boolean, whether Outline Variant is
 *     on for the reference.
 *   - edit: function(reference, key), the editing session on the variant
 *     anchored on the row's element.
 *   - outline: function(reference), the Outline Variant toggle.
 *   - showCode: function(key), the element's own styles in code mode.
 *
 * @return {Array<object>}
 */
export const variantDoorways = (map, address, variants, doors) => {
  const { count, worn } = foldSummary(map, address);
  const items = [];
  worn.forEach((layer) => {
    const reference = layerScope(layer);
    const label = labelOf(variants, reference);
    items.push({
      label: Drupal.t('Edit !label', { '!label': label }),
      title: Drupal.t(
        "Edit this variant's whole document, in context of this element. Edits are pending until pushed.",
      ),
      run: () => doors.edit(reference, address),
    });
    items.push({
      // With one variant worn "it" is unambiguous; with several, each
      // item names its variant.
      label:
        worn.length === 1
          ? Drupal.t('Outline everything wearing it')
          : Drupal.t('Outline everything wearing !label', { '!label': label }),
      title: Drupal.t(
        'Outline every element wearing this variant on the canvas and in the page elements panel until toggled off.',
      ),
      checked: doors.outlined(reference),
      run: () => doors.outline(reference),
    });
  });
  if (count > 0) {
    items.push({
      label: Drupal.t('Show its own styles in code'),
      title: Drupal.t(
        "Select this element and open the styling panel in code mode, where its own declarations print as editable CSS.",
      ),
      run: () => doors.showCode(address),
    });
  }
  return items;
};

/**
 * The doors over the hosts' seams: what each doorway does when run.
 *
 * In-page, "Edit" and "Outline" go through the doors seam the mounted
 * Inspector fills (inspector-doors.js), the edit after the panel is open
 * (a session nobody can see is no doorway). Popped out, the leader's
 * inspector is unmounted, so both route the gesture to the satellite
 * over the pop-out protocol and land in its inspector. "Show its own
 * styles in code" is source and preference state alone (the selection
 * spine, the mode store, the place), which both windows already share,
 * so it needs no route: in-page it also opens the panel.
 *
 * @param {object} deps
 *   - source: the leader source (select, getPlace, setPlace).
 *   - popout: the pop-out leader (isPoppedOut, openDoor), or null when
 *     this route has no pop-out.
 *   - mode: the UI mode store (ui-mode.js uiMode).
 *   - openPanel: function(): Promise<boolean>, opens the inspector's
 *     sidebar panel and resolves whether it is open (false when the slot's
 *     current panel refused to close).
 *
 * @return {{edit: Function, outline: Function, showCode: Function}}
 */
export const createVariantDoors = ({ source, popout = null, mode, openPanel }) => ({
  edit: (reference, key) => {
    if (popout?.isPoppedOut()) {
      popout.openDoor({ door: DOOR_EDIT, reference, key });
      return;
    }
    openPanel().then((open) => {
      if (open) {
        openDocument(reference, key);
      }
    });
  },
  outline: (reference) => {
    if (popout?.isPoppedOut()) {
      popout.openDoor({ door: DOOR_OUTLINE, reference, key: null });
      return;
    }
    toggleOutline(reference);
  },
  showCode: (key) => {
    source.select(key);
    mode.set('code');
    const place = source.getPlace();
    if (place.place !== 'styles') {
      source.setPlace({ ...place, place: 'styles' });
    }
    if (!popout?.isPoppedOut()) {
      openPanel();
    }
  },
});

/**
 * The provider, bound to a source and the doors, in the row-facts seam's
 * shape.
 *
 * @param {object} deps
 *   - source: the leader source (getStyleMap, getMaterials, getCanvasPaint,
 *     onStyleMapChange, onMaterialsChange).
 *   - doors: createVariantDoors()' doors.
 *
 * @return {{id: string, facts: Function, doorways: Function, subscribe: Function}}
 */
export const createVariantRowFacts = ({ source, doors }) => {
  const variants = () => source.getMaterials().ofKind('variant');
  const outlined = (reference) =>
    outlinedIn(source.getCanvasPaint?.() ?? null, reference);
  return {
    id: VARIANTS_PROVIDER_ID,
    facts: (address) => variantFacts(source.getStyleMap(), address, variants()),
    doorways: (address) =>
      // The Outline checkmark is the provider's own read of the paint
      // state, whatever the doors carry.
      variantDoorways(source.getStyleMap(), address, variants(), {
        ...doors,
        outlined,
      }),
    // Both reads live outside daedalus_ui's store as the tree sees it:
    // the map (a mutation response, a staged preview settling) and the
    // roster (a variant renamed, minted or deleted) each change the
    // answers, so each announces.
    subscribe: (callback) => {
      source.onStyleMapChange(callback);
      source.onMaterialsChange(callback);
    },
  };
};
