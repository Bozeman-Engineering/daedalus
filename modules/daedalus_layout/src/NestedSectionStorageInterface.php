<?php

namespace Drupal\daedalus_layout;

use Drupal\layout_builder\Section;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\SectionListInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Interface for nested-aware section storage.
 *
 * This interface extends SectionStorageInterface and provides O(1) access to
 * blocks and sections anywhere in the layout tree. Nesting is sections all
 * the way down: a nested section is an ordinary SectionComponent (the
 * daedalus_layout_section container) whose configuration embeds ONE
 * Section::toArray() value, recursively. There is no entity boundary inside
 * the tree.
 *
 * Key design principles:
 * - getSections()/getSection() operate at ROOT (for core compatibility)
 *   unless scoped via forSection().
 * - Component uuid == embedded section uuid (one identity): every surface
 *   addresses a nested section by its SECTION uuid, and the wrapper
 *   component is findable by the same uuid.
 * - Use getSectionFor()/getSectionsFor() to access any depth.
 * - bubbleChangesToRoot() flushes embedded sections deepest-first,
 *   unconditionally; the tempstore seam calls it on every set().
 * - Unified TreeIndex: all UUIDs stored in a single map.
 */
interface NestedSectionStorageInterface extends SectionStorageInterface {

  /**
   * The context id the root Layout Builder entity is always available under.
   *
   * Under the embedded-section model the entity flowing down the tree IS the
   * root entity at every depth, and the container plugin forwards it under
   * this id as well as core's layout_builder.entity/entity ids. The id is
   * retained so context mappings stored against it (and legacy mappings
   * healed to it) keep resolving in root renders, nested renders, and form
   * previews alike.
   */
  public const ROOT_ENTITY_CONTEXT_ID = 'daedalus_layout.root_entity';

  /**
   * Gets a component by UUID from anywhere in the tree.
   *
   * This is the primary method for accessing blocks. It uses the TreeIndex
   * for O(1) lookup regardless of nesting depth. Nested-section container
   * components are NOT returned here (their uuid resolves as a section);
   * use getContainerComponent() for the wrapper.
   *
   * @param string $uuid
   *   The block component UUID.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The component, or NULL if not found.
   */
  public function getComponent(string $uuid): ?SectionComponent;

  /**
   * Gets the container component wrapping a nested section.
   *
   * One identity: the container component's uuid IS the embedded section's
   * uuid, so remove-section == remove-component is a single lookup. Root
   * sections have no container and return NULL.
   *
   * @param string $section_uuid
   *   The nested section's UUID.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The container component, or NULL for root sections and unknown uuids.
   */
  public function getContainerComponent(string $section_uuid): ?SectionComponent;

  /**
   * Gets a section by UUID from anywhere in the tree.
   *
   * Sections have UUIDs added by daedalus_layout via third-party settings.
   *
   * @param string $uuid
   *   The section UUID.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The section, or NULL if not found.
   */
  public function getSectionByUuid(string $uuid): ?Section;

  /**
   * Gets the Section containing or identified by a UUID.
   *
   * For block UUIDs: Returns the section that CONTAINS the block.
   * For section UUIDs: Returns that section itself.
   *
   * Use this when you need to add/remove components from a section.
   *
   * @param string $uuid
   *   The UUID of a block or section.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The section, or NULL if not found.
   */
  public function getSectionFor(string $uuid): ?Section;

  /**
   * Gets the section list CONTAINING a section.
   *
   * For a root section this is the root storage; for a nested section it is
   * the identity-stable EmbeddedSectionList of its container (the section
   * itself is delta 0). Blocks contain no sections under the
   * embedded-section model, so block UUIDs return NULL.
   *
   * @param string $uuid
   *   The UUID of a section.
   *
   * @return \Drupal\layout_builder\SectionListInterface|null
   *   The section list, or NULL if not found (or a block UUID).
   */
  public function getSectionsFor(string $uuid): ?SectionListInterface;

  /**
   * Gets the tree index (lookup table).
   *
   * The index maps all UUIDs (block and section) to their paths, enabling
   * O(1) lookups. Use TreeIndexInterface::isBlock() or isSection() to
   * determine the type of an item. Building the index also stamps missing
   * section uuids and repairs duplicates (deterministically, in lockstep
   * with the container component uuid for nested sections).
   *
   * @return \Drupal\daedalus_layout\TreeIndexInterface
   *   The tree index.
   */
  public function getTreeIndex(): TreeIndexInterface;

  /**
   * Gets the path to any item by UUID.
   *
   * Works for both block and section UUIDs. Paths alternate
   * [delta, containerUuid, delta, ...]; block paths additionally end with
   * the block uuid. A nested section's local delta is always 0 (a container
   * embeds exactly one section).
   *
   * @param string $uuid
   *   The UUID of a block or section.
   *
   * @return array|null
   *   The path array, or NULL if not found.
   */
  public function getPath(string $uuid): ?array;

  /**
   * Gets the scoping coordinates for a UUID (block or section).
   *
   * Parses a TreeIndex path into the section scope and local delta that
   * make the item addressable as (scoped storage, delta) by core-shaped
   * forms. For a nested block the scope is its parent section's uuid; for a
   * nested section the scope is its OWN uuid (the scoped list is the
   * embedded list containing it at delta 0).
   *
   * @param string $uuid
   *   The UUID of a block or section.
   *
   * @return array|null
   *   An associative array with keys:
   *   - 'sectionUuid': The section uuid to scope the storage to.
   *   - 'localDelta': The section delta within that scope (always 0 for
   *     nested scopes).
   *   Returns NULL if the item is at root level or not found.
   */
  public function getNestedContext(string $uuid): ?array;

  /**
   * Returns a view of this storage scoped to a section.
   *
   * When scoped, getSections()/getSection()/count() and mutation methods
   * operate on the section list containing the given section instead of
   * root. This enables core/contrib forms using delta-based access to work
   * transparently at any depth: scope to a nested section and it is
   * getSection(0).
   *
   * A scope lookup that no longer resolves THROWS; there is no root
   * fallback, because silently falling back to the root list would
   * redirect edits to the wrong layout.
   *
   * @param string|null $section_uuid
   *   The section UUID to scope to, or NULL for root.
   *
   * @return static
   *   A new instance scoped to the specified section. If already scoped
   *   to the same UUID, returns $this.
   */
  public function forSection(?string $section_uuid): static;

  /**
   * Gets the current section scope.
   *
   * @return string|null
   *   The section UUID this storage is scoped to, or NULL if at root.
   */
  public function getSectionScope(): ?string;

  /**
   * Gets the root (unwrapped) section storage.
   *
   * Use this when you need direct access to the underlying storage,
   * for example when passing to core Layout Builder code.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The root section storage.
   */
  public function getRootStorage(): SectionStorageInterface;

  /**
   * Flushes embedded-section mutations down to the root storage.
   *
   * Every hydrated EmbeddedSectionList writes its memoized Section back into
   * its container component's configuration via toArray(), deepest-first so
   * child writes land in parent section objects before the parents
   * themselves serialize. The flush is UNCONDITIONAL: core forms mutate
   * hydrated Sections in place with no signal, so there is no dirty flag to
   * consult; writing back what was read is idempotent.
   *
   * The tempstore seam (LayoutTempstoreStrategy::set()) calls this on every
   * write; call it explicitly only before bypassing that seam (e.g. a
   * direct entity field write + save).
   */
  public function bubbleChangesToRoot(): void;

  /**
   * Invalidates the internal caches, forcing rebuild on next access.
   *
   * Call this if you've made structural changes to the layout (adding/
   * removing/moving sections or blocks) that would affect the TreeIndex.
   * Hydrated embedded sections survive invalidation: the next index build
   * re-binds them in place, so unflushed mutations are never lost.
   */
  public function invalidateCache(): void;

  /**
   * Extracts the block_content entity from a block plugin.
   *
   * Generic InlineBlock helper (media blocks, usage tracking, form alters);
   * not a traversal primitive. Creates a NEW deserialized entity each call.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $plugin
   *   The block plugin.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The block_content entity, or NULL if not applicable.
   */
  public function getBlockContentFromPlugin(BlockPluginInterface $plugin): ?BlockContentInterface;

  /**
   * Gets all sections including nested ones.
   *
   * Returns all sections across all nesting levels in the layout tree.
   * Uses TreeIndex internally for efficient traversal.
   *
   * @return \Drupal\layout_builder\Section[]
   *   Array of all sections in the layout tree.
   */
  public function getAllSections(): array;

  /**
   * Checks whether the storage has unsaved changes that need persisting.
   *
   * Set when an index build stamped or repaired section identity; the admin
   * element re-saves tempstore so generated uuids are stable across
   * requests.
   *
   * @return bool
   *   TRUE if the storage has been modified since the last save.
   */
  public function isDirty(): bool;

  /**
   * Marks the storage as having unsaved changes.
   */
  public function markDirty(): void;

}
