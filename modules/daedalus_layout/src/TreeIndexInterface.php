<?php

namespace Drupal\daedalus_layout;

/**
 * Interface for the layout tree lookup table.
 *
 * The TreeIndex provides O(1) lookups of blocks and sections by UUID across
 * the entire layout tree, including nested sections at any depth.
 *
 * All UUIDs (block and section) are stored in a single unified index. The type
 * can be determined from the path structure:
 * - Odd path length (1, 3, 5...) = section
 * - Even path length (2, 4, 6...) = block
 *
 * The type is also stored explicitly in metadata for debugging convenience.
 */
interface TreeIndexInterface {

  /**
   * Type constant for blocks (section components).
   */
  const TYPE_BLOCK = 'block';

  /**
   * Type constant for sections.
   */
  const TYPE_SECTION = 'section';

  /**
   * Adds an item to the index.
   *
   * @param string $uuid
   *   The UUID of the block or section.
   * @param array $path
   *   The path to the item. For sections: [delta] or [delta, uuid, delta, ...].
   *   For blocks: [delta, uuid] or [delta, uuid, delta, uuid, ...].
   * @param string $type
   *   The type: 'block' or 'section'. Use the TYPE_* constants.
   * @param array $metadata
   *   Optional additional metadata (region, weight, plugin_id for blocks;
   *   layout_id for sections).
   */
  public function add(string $uuid, array $path, string $type, array $metadata = []): void;

  /**
   * Gets the path to an item by UUID.
   *
   * @param string $uuid
   *   The UUID of the block or section.
   *
   * @return array|null
   *   The path array, or NULL if not found.
   */
  public function getPath(string $uuid): ?array;

  /**
   * Gets the type of an item by UUID.
   *
   * @param string $uuid
   *   The UUID to look up.
   *
   * @return string|null
   *   The type ('block' or 'section'), or NULL if not found.
   */
  public function getType(string $uuid): ?string;

  /**
   * Checks if an item exists in the index.
   *
   * @param string $uuid
   *   The UUID to check.
   *
   * @return bool
   *   TRUE if the item exists in the index.
   */
  public function has(string $uuid): bool;

  /**
   * Checks if a UUID is a block (section component).
   *
   * @param string $uuid
   *   The UUID to check.
   *
   * @return bool
   *   TRUE if the UUID exists and is a block.
   */
  public function isBlock(string $uuid): bool;

  /**
   * Checks if a UUID is a section.
   *
   * @param string $uuid
   *   The UUID to check.
   *
   * @return bool
   *   TRUE if the UUID exists and is a section.
   */
  public function isSection(string $uuid): bool;

  /**
   * Gets all indexed items.
   *
   * @return array
   *   Array of uuid => ['path' => [...], 'type' => '...', 'metadata' => [...]].
   */
  public function getAll(): array;

  /**
   * Gets all items of a specific type.
   *
   * @param string $type
   *   The type to filter by ('block' or 'section').
   *
   * @return array
   *   Array of uuid => ['path' => [...], 'type' => '...', 'metadata' => [...]].
   */
  public function getAllOfType(string $type): array;

}
