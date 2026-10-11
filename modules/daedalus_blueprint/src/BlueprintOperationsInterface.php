<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Targeted CRUD operations for AI-driven entity editing.
 *
 * Provides direct entity manipulation without full serialize/deserialize
 * round-trips. Read methods delegate to BlueprintSerializer. Write methods
 * manipulate entities directly using the FieldHandler infrastructure.
 *
 * Each write method returns a BlueprintResult with the unsaved entity — the
 * caller decides whether to save or write to tempstore.
 */
interface BlueprintOperationsInterface {

  /**
   * Creates an entity from a full blueprint.
   *
   * Delegates to BlueprintDeserializer. This is the one operation where the
   * AI provides the complete document.
   *
   * @param array $blueprint
   *   The full blueprint data array.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the unsaved entity on success.
   */
  public function createEntity(array $blueprint, array $options = []): BlueprintResult;

  /**
   * Returns the full serialized blueprint for an entity.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The full blueprint array.
   */
  public function getBlueprint(HostInterface $host, ?string $langcode = NULL): array;

  /**
   * Returns the structural summary of an entity.
   *
   * Shows the full tree hierarchy at all depths without field values.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to summarize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The summary blueprint array.
   */
  public function getBlueprintSummary(HostInterface $host, ?string $langcode = NULL): array;

  /**
   * Returns a single element's data by UUID.
   *
   * Immediate fields are fully populated. Nested content appears as
   * summaries. Walks LB sections, paragraph fields, and nested structures
   * at arbitrary depth to find the target.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host containing the element.
   * @param string $uuid
   *   The UUID of the element to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array|null
   *   The element array, or NULL if the UUID was not found.
   */
  public function getElement(HostInterface $host, string $uuid, ?string $langcode = NULL): ?array;

  /**
   * Adds a new element at the specified location.
   *
   * The location speaks the ONE placement grammar, or the paragraph
   * family's own field addressing:
   * - Anchor-relative (the placement grammar): anchor (a UUID) + placement
   *   ('before'|'after'|'inside'). Before/after place beside the anchor in
   *   the flow it lives in — a sibling element, a nested section (an
   *   ordinary weighted container in its parent region's mixed flow), or a
   *   paragraph; a block beside a ROOT section is refused (the root list
   *   holds only sections — wrap via add_section, then place inside).
   *   Inside anchors a section (optional region, defaults to the layout's
   *   default region), a paragraph, or the root entity (both require field
   *   naming the reference field to append into).
   * - Paragraph field addressing: field + position (+ optional parent_uuid)
   *   names a reference field slot directly (a separate family with no
   *   anchor equivalent yet).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to add an element to.
   * @param array $location
   *   The location specification.
   * @param array $data
   *   The element data: block_type + block_fields for LB inline blocks,
   *   plugin_id for LB plugin blocks (no fields — they render existing
   *   content), type + fields for paragraphs. LB adds accept an optional
   *   populate: 'sample' to fill unprovided fields with sample content
   *   (the palette-placement flow; sampling only, the element-created
   *   event fires for every LB add and carries the batch's dressed
   *   posture), and an optional additional map: opaque per-placement
   *   metadata stored on the SectionComponent (keyed by owning module),
   *   never rendered or serialized to clients; the blueprint provenance
   *   stamp rides here.
   *   Blocks hold no nested layouts: sections nest inside sections via
   *   addSection (placement 'inside') or section nodes in layout shapes.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function addElement(HostInterface $host, array $location, array $data, array $options = []): BlueprintResult;

  /**
   * Adds a new Layout Builder section, born with its real layout.
   *
   * Without an explicit layout the section mints as a container: the
   * default-layout ladder prefers a container layout from the allowed set
   * (falling back to the configured daedalus_layout default_section, then the first
   * allowed layout). An explicit layout names a genuinely named-slot layout
   * (or pins layout settings) at birth; there is no layout-change
   * operation, a section IS its layout for life.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to add a section to.
   * @param array $location
   *   Anchor-relative placement: anchor (a UUID) + placement, the ONE
   *   placement grammar, page or nested. Any sibling element in a flow
   *   (section or block, any depth) anchors 'before'/'after' — in a region
   *   flow the new section arrives as a container beside the
   *   anchor; a section anchors 'inside' (nests the new section into it;
   *   multi-region anchors may name a region with the optional region
   *   key); the root entity anchors 'inside' (appends a top-level
   *   section).
   * @param string|null $uuid
   *   A client-provided daedalus_layout section UUID to honor, or NULL.
   * @param string|null $layout
   *   An explicit layout plugin ID the section is born with, or NULL for
   *   the container/default ladder.
   * @param array $layout_settings
   *   Layout plugin settings patched over the explicit layout's defaults
   *   (the OpaqueDataMerger PATCH contract: an explicit empty value clears
   *   the key). Only valid together with an explicit layout.
   * @param string|null $blueprint
   *   The source blueprint ID when this section is a blueprint placement's
   *   root, or NULL. Provenance is a birth fact: the ID is written into the
   *   section's third-party settings (LayoutSerializer's
   *   BLUEPRINT_PROVENANCE_KEY) at creation, exactly as uuid carries
   *   identity, so trailing style operations already see the stamp.
   *   place_blueprint passes it on the root op it expands; pass it
   *   directly only when hand-placing a blueprint root.
   * @param string|null $slot
   *   The slot id this section fills, or NULL for an ordinary section. A
   *   slot section is the host's own top-level section filling the slot
   *   of that id the document around it placed (a locked bundle's
   *   template, the site frame): a birth fact written into the section's
   *   third-party settings (LayoutSerializer::SLOT_PROVIDER), surfaced as
   *   the node's `slot` key. The id is a machine name (INVALID_SLOT
   *   otherwise), the section lands in the root flow only
   *   (BLUEPRINT_SLOT_NESTED on any other anchor) and the id appears at
   *   most once in the host's own document (BLUEPRINT_SLOT_DUPLICATE).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function addSection(HostInterface $host, array $location, ?string $uuid = NULL, ?string $layout = NULL, array $layout_settings = [], ?string $blueprint = NULL, ?string $slot = NULL): BlueprintResult;

  /**
   * Removes an element by UUID.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to remove an element from.
   * @param string $uuid
   *   The UUID of the element to remove.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function removeElement(HostInterface $host, string $uuid): BlueprintResult;

  /**
   * Duplicates an element, inserted after the original.
   *
   * The clone is a deep copy with fresh identity throughout: a new UUID, a
   * duplicated block_content entity for inline blocks, and, for sections,
   * a recursively cloned subtree whose elements' and nested sections' UUIDs
   * are regenerated, so every selectable identity on the page stays unique.
   * Sections duplicate uniformly at every depth (a top-level clone becomes
   * a new layout row, a nested clone a sibling container).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host containing the element.
   * @param string $uuid
   *   The UUID of the element to duplicate.
   * @param string|null $new_uuid
   *   Optional client-provided UUID for the clone (optimistic-UI support).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success; the clone's UUID is
   *   reported as an added target.
   */
  public function duplicateElement(HostInterface $host, string $uuid, ?string $new_uuid = NULL): BlueprintResult;

  /**
   * Moves a layout element (block or section) to an anchor-relative spot.
   *
   * Blocks and sections accept the same placement grammar: before/after
   * any sibling in the anchor's flow (any depth, block or section anchors
   * alike) or inside a section (with an optional region). The root entity
   * anchors 'inside' for a section (appends it to the end of the top-level
   * flow, whatever its depth before, the location addSection() appends to)
   * and nothing else: before/after on the root are refused (the page has no
   * outside), and a block anchored on the root is refused with the wrap in
   * the hint. The one flow capability rule applies: the root section list
   * holds only sections. Section moves are pure value moves; depth
   * crossings (top-level row to nested container and back) need no
   * conversion and keep identity. Paragraphs are not movable here;
   * reorder_field covers ordering within a field.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host containing the element.
   * @param string $uuid
   *   The UUID of the element to move.
   * @param array $location
   *   The destination: anchor (required), placement (before|after|inside),
   *   and optionally region for inside placement.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function moveElement(HostInterface $host, string $uuid, array $location): BlueprintResult;

  /**
   * Updates properties on an entity or one of its elements.
   *
   * Accepts a flat property bag. The method sorts properties to the correct
   * storage location: entity fields, managed configuration keys, or element
   * label. Unknown properties produce structural errors.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to update.
   * @param string $uuid
   *   The UUID of the element to update. For root entity fields, pass the
   *   entity's own UUID.
   * @param array $properties
   *   Flat map of property names to values.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function updateElement(HostInterface $host, string $uuid, array $properties, array $options = []): BlueprintResult;

  /**
   * Updates top-level entity fields only.
   *
   * Rejects layout_section and entity_reference_revisions fields.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to update.
   * @param array $fields
   *   Map of field name to value.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function updateFields(HostInterface $host, array $fields, array $options = []): BlueprintResult;

  /**
   * Reorders items in an entity_reference_revisions field.
   *
   * Accepts UUIDs in the desired order. Optionally scoped to a specific
   * parent paragraph via parent_uuid for nested fields.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to reorder.
   * @param array $structure
   *   The reorder specification with field name and UUID order.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  public function reorderField(HostInterface $host, array $structure): BlueprintResult;

  /**
   * Applies multiple operations atomically to a single entity.
   *
   * Operations are applied sequentially and EVERY operation is evaluated,
   * even after a failure: on any error the whole batch rolls back, but the
   * remaining operations keep executing on the doomed entity purely for
   * diagnostics, so one round reports every failing operation (each error
   * tagged with its operation index) instead of drip-feeding one failure
   * per round. Errors from an operation that references identity a failed
   * operation was to mint are suppressed — they are shadows of the earlier
   * failure, not real findings — and structural errors take priority over
   * semantic ones in the returned result.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to modify.
   * @param array $operations
   *   Array of operation definitions, each with an 'op' key.
   * @param array $options
   *   Request-scoped options forwarded to field handlers AND registered
   *   operation handlers (e.g. decode_html_entities for the AI plain-text
   *   entity-escape defense, or any module-defined key) — one lane, so an
   *   option means the same thing on every write path. The batch's own
   *   key is `dressed` (bool, default FALSE): TRUE declares that the
   *   batch's elements arrive with their styles (a blueprint expansion, a
   *   document dialect), so the lifecycle events it dispatches answer
   *   dressed() and birth subscribers stamp no defaults under it. An
   *   enclosing dressed batch dresses its nested batches; the option
   *   cannot undress a nested batch of a dressed one. `admitted` (bool,
   *   default FALSE): TRUE declares that the batch copies a document the
   *   display already holds (a page seeded from its template), so the
   *   placement restrictions, which gate what an author may pick, are not
   *   asked; nested batches inherit it. `system` (bool, default FALSE):
   *   TRUE declares that the batch acts for the site, not for an actor
   *   (the seed of a page from its template, an importer), so the
   *   capability gate is not asked; nested batches inherit it. `actor`
   *   (AccountInterface, default the current user): the account every
   *   operation is judged for at the capability gate; nested batches
   *   inherit the outermost batch's. `judge` (bool,
   *   default FALSE): TRUE evaluates the batch in full and lands
   *   nothing: every op runs on the entity so later ops are judged
   *   against the world the earlier ones made, the result reports what
   *   would have landed (success, or every failing op's errors), and the
   *   entity is restored either way with no alias persisted. The posture
   *   is the judged batch's own; nested batches its ops run land on the
   *   doomed entity as usual. `langcode` (string, default NULL): the
   *   translation the batch mutates, resolved as the read side resolves
   *   it (the translation must exist); NULL mutates the entity as handed
   *   in, whatever translation that object is. The result carries the
   *   entity object the caller passed, its named translation modified.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   Success with the fully modified entity, or failure with errors.
   */
  public function batchOperations(HostInterface $host, array $operations, array $options = []): BlueprintResult;

}
