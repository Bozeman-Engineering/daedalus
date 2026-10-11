/**
 * @file
 * The drop's batch: one composer for every door a row places through.
 *
 * A row's drop is the batch the standard builders compose for its source
 * at a target (a new block, a new section, a blueprint placement). The
 * pointer drag composes it in the drag controller from the gap the caret
 * resolved; click-to-place composes it here from the pending anchor; and
 * the peek composes it here for the end of the page, so "what a drop
 * here would give" is literally the drop's batch, dry run, and no second
 * statement of a placement exists on either side. The peek's request is
 * that batch plus the key of the element it places (`show`), which the
 * batch names client-side (the grammar's optimistic seam).
 *
 * Pure: no DOM, no Preact, no Drupal. The builders, the uuid generator
 * and the depth read are injected so the node tier drives them by hand;
 * the insert session hands in the app's.
 */

/**
 * A blueprint drop routes through the place_blueprint operation (the
 * stamping spine, daedalus_blueprint_library) instead of add_section:
 * the server instantiates the blueprint's tree through the same batch
 * grammar a panel drop uses, so restrictions, sample population and
 * styles all apply server-side. The client-minted uuid names the ROOT
 * element. A detached blueprint is a section drop (its roots are
 * containers): the location IS add_section's one placement grammar, so
 * the same blueprint lands as a top-level band or nested into any region
 * with no client-side wrapping; which gaps are OFFERED is the source's
 * acceptsTarget (a band, top level only). A LINKED placement
 * (`data.linked`) is the same op with `mode: 'linked'`: one block node
 * showing the saved blueprint by reference, so it is a block drop (the
 * uuid names that block) and lands where a block lands.
 *
 * @param {Function} generateUuid
 *   Mints a uuid.
 * @param {object} target
 *   The resolved drop target ({location}).
 * @param {object} data
 *   The drag data ({blueprintId, linked?}).
 *
 * @return {object[]}
 *   The batch.
 */
export const blueprintOperations = (generateUuid, target, data) => [
  {
    op: 'place_blueprint',
    blueprint: data.blueprintId,
    ...(data.linked ? { mode: 'linked' } : {}),
    uuid: generateUuid(),
    location: target.location,
  },
];

/**
 * The server location a row click means, from the pending anchor.
 *
 * Blocks place after a block anchor and inside a container anchor.
 * Sections place after a container anchor (a sibling at that anchor's
 * depth) and after the containing container for block anchors (resolved
 * by the caller, at any nesting depth). The root entity anchor (the empty
 * canvas, the end of the page) takes blocks and sections alike inside
 * itself; a block there rides a new section, which resolveTarget adds. A
 * slot door (a block key the caller names as one: a slot block of the
 * template around the page, whose insert verdict is the page's own)
 * takes blocks and sections alike inside itself too; the server lands
 * them in the page's slot section, minted when the slot is empty.
 *
 * @param {string} key
 *   The anchor's selectable key (kind block, container or entity).
 * @param {boolean} placingSection
 *   Whether the row is a section drop (the Container or a blueprint).
 * @param {Function} enclosingContainerOf
 *   Answers the uuid of the container a block key sits in, or null.
 * @param {Function} slotDoor
 *   Answers whether a block key is a slot door (false by default).
 *
 * @return {?object}
 *   A placement-grammar location, or null when the anchor cannot resolve
 *   one (e.g. its element left the page).
 */
export const anchorLocation = (
  key,
  placingSection,
  enclosingContainerOf,
  slotDoor = () => false,
) => {
  const [kind, uuid] = key.split(':');
  if (kind === 'entity' || (kind === 'block' && slotDoor(key))) {
    return { anchor: uuid, placement: 'inside' };
  }
  if (!placingSection) {
    if (kind === 'block') {
      return { anchor: uuid, placement: 'after' };
    }
    if (kind === 'container') {
      return { anchor: uuid, placement: 'inside' };
    }
    return null;
  }
  if (kind === 'container') {
    return { anchor: uuid, placement: 'after' };
  }
  if (kind === 'block') {
    const section = enclosingContainerOf(key);
    return section ? { anchor: section, placement: 'after' } : null;
  }
  return null;
};

/**
 * The target at the end of the page: inside the root entity, which
 * appends. The same target the root anchor resolves to.
 *
 * @param {string} rootUuid
 *   The root entity's uuid.
 *
 * @return {object}
 *   A target ({location}).
 */
export const rootEndTarget = (rootUuid) => ({
  location: anchorLocation(`entity:${rootUuid}`, false, () => null),
});

/**
 * Resolves a target for a source: a block aimed at the root flow lands in
 * a new section there (the root flow holds only sections), the
 * [add_section, place-inside] batch the builder composes.
 *
 * @param {Function} isRootFlowLocation
 *   The one depth read for a location.
 * @param {{placingSection: boolean}} source
 *   The row's source (panel-dom.js rowSource).
 * @param {object} target
 *   A target ({location}).
 *
 * @return {object}
 *   The target, with newSection where the source asks for one.
 */
export const resolveTarget = (isRootFlowLocation, source, target) =>
  !source.placingSection && isRootFlowLocation(target.location)
    ? { ...target, newSection: true }
    : target;

/**
 * Composes a row's drop at a resolved target.
 *
 * @param {{builders: object, generateUuid: Function}} deps
 *   The standard builders (newBlock, newSection) and the uuid generator.
 * @param {{source: string, data: object, placingSection: boolean}} source
 *   The row's source.
 * @param {object} target
 *   The resolved target (resolveTarget).
 *
 * @return {{operations: object[], placedKey: string}}
 *   The batch, which ends with the op carrying the placed element's uuid,
 *   and the placed element's selectable key.
 */
export const composeDrop = (deps, source, target) => {
  const { data, placingSection } = source;
  let operations;
  if (data.blueprintId) {
    // A blueprint, detached (a section) or linked (a block): one op. A
    // linked node aimed at the root flow rides a new section there, as
    // any block does (the root flow holds only sections).
    if (target.newSection) {
      const [section] = deps.builders.newSection(target);
      operations = [
        section,
        ...blueprintOperations(
          deps.generateUuid,
          { location: { anchor: section.uuid, placement: 'inside' } },
          data,
        ),
      ];
    } else {
      operations = blueprintOperations(deps.generateUuid, target, data);
    }
  } else if (!placingSection) {
    operations = deps.builders.newBlock(target, data);
  } else {
    operations = deps.builders.newSection(target);
  }
  const placedUuid = operations[operations.length - 1].uuid;
  return {
    operations,
    placedKey: `${placingSection ? 'container' : 'block'}:${placedUuid}`,
  };
};

/**
 * The peek's request for a row at a target: the drop's own batch and the
 * key of what it places, which the peek door shows.
 *
 * @param deps
 * @param source
 * @param target
 * @return {{operations: object[], show: string}}
 */
export const peekRequest = (deps, source, target) => {
  const { operations, placedKey } = composeDrop(deps, source, target);
  return { operations, show: placedKey };
};
