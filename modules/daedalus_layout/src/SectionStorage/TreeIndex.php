<?php

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\daedalus_layout\TreeIndexInterface;

/**
 * Implementation of the layout tree lookup table.
 *
 * Stores all UUIDs (blocks and sections) in a single unified index.
 */
class TreeIndex implements TreeIndexInterface {

  /**
   * Unified index keyed by uuid.
   *
   * Each entry is ['path' => [...], 'type' => '...', 'metadata' => [...]].
   */
  protected array $items = [];

  /**
   * {@inheritdoc}
   */
  public function add(string $uuid, array $path, string $type, array $metadata = []): void {
    $this->items[$uuid] = [
      'path' => $path,
      'type' => $type,
      'metadata' => $metadata,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPath(string $uuid): ?array {
    return $this->items[$uuid]['path'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(string $uuid): ?string {
    return $this->items[$uuid]['type'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $uuid): bool {
    return isset($this->items[$uuid]);
  }

  /**
   * {@inheritdoc}
   */
  public function isBlock(string $uuid): bool {
    return ($this->items[$uuid]['type'] ?? NULL) === self::TYPE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function isSection(string $uuid): bool {
    return ($this->items[$uuid]['type'] ?? NULL) === self::TYPE_SECTION;
  }

  /**
   * {@inheritdoc}
   */
  public function getAll(): array {
    return $this->items;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllOfType(string $type): array {
    return array_filter($this->items, fn($item) => $item['type'] === $type);
  }

}
