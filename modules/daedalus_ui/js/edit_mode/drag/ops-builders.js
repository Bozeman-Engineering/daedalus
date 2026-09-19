import { generateUuid } from '../utilities/uuid';

/**
 * Standard operation builders for caret drops.
 *
 * A drag source's operations() maps a resolved drop target to a blueprint
 * operation batch. These builders cover the standard placement kinds so
 * every source (the palette, the move gesture, media file-drags, tree-row
 * drops) emits the same grammar without re-deriving it; custom sources can
 * still supply their own operations().
 *
 * Client-generated UUIDs ride every create so the optimistic layer (and
 * tests) can address the new element before the server confirms — the
 * batch grammar honors them.
 *
 * A block drop in a ROOT-flow gap resolves with newSection (that flow
 * holds only sections): the location addresses the SECTION gap, so the
 * drop becomes an atomic [add_section, place-inside-it] batch, "place
 * block in a new section" expressed in the one grammar. Gaps in nested
 * flows are plain placements; a container's region holds blocks and
 * containers alike.
 */

/**
 * Wraps a block operation in a new section when the target asks for one.
 */
const inNewSection = (target, blockOperation) => {
  const sectionUuid = generateUuid();
  return [
    { op: 'add_section', uuid: sectionUuid, location: target.location },
    {
      ...blockOperation,
      location: { anchor: sectionUuid, placement: 'inside' },
    },
  ];
};

/**
 * Places a new block.
 *
 * @param {Object} target
 *   The resolved drop target.
 * @param {Object} data
 *   The drag data: {pluginId} for a plugin block, {blockType} for an
 *   inline block. A pluginId of the form inline_block:{type} is normalized
 *   to its block type — the grammar rejects that plugin-id spelling.
 *   Optional {properties} rides field values onto the new inline block
 *   (e.g. a media drop's reference); sample population skips provided
 *   fields, so both compose.
 *
 * @return {Object[]}
 */
export const newBlock = (target, data) => {
  const operation = {
    op: 'add_element',
    uuid: generateUuid(),
    // Palette drops arrive with no field values; sample population gives
    // the new block visible, editable content (parity with the old
    // dropzone placement path).
    populate: 'sample',
    location: target.location,
  };
  const inlineType = data.pluginId?.startsWith('inline_block:')
    ? data.pluginId.slice('inline_block:'.length)
    : data.blockType;
  if (inlineType) {
    operation.block_type = inlineType;
  } else {
    operation.plugin_id = data.pluginId;
  }
  if (data.properties) {
    operation.properties = data.properties;
  }
  return target.newSection ? inNewSection(target, operation) : [operation];
};

/**
 * Moves an existing block placement.
 *
 * @param {Object} data
 *   The drag data: {uuid} of the moved component.
 */
export const moveBlock = (target, data) => {
  const operation = {
    op: 'move_element',
    element: data.uuid,
    location: target.location,
  };
  return target.newSection ? inNewSection(target, operation) : [operation];
};

/**
 * Adds a new (default-layout) section.
 */
export const newSection = (target) => [
  { op: 'add_section', uuid: generateUuid(), location: target.location },
];

/**
 * Moves an existing section.
 *
 * @param {Object} data
 *   The drag data: {uuid} of the moved section.
 */
export const moveSection = (target, data) => [
  { op: 'move_element', element: data.uuid, location: target.location },
];
