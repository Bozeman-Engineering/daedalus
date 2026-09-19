<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * The one placement-restriction answer for blocks and layouts.
 *
 * Every surface that asks "may this block/layout go here?" — blueprint ops,
 * blueprint validation, the AI schema, and (through the same core mechanism)
 * the daedalus_layout palette and layout chooser — projects the answer produced by
 * core's filtered plugin definitions for the 'layout_builder' consumer.
 * Restriction providers (layout_builder_restrictions and anything else
 * implementing hook_plugin_filter_TYPE__layout_builder_alter) plug into that
 * mechanism, so enforcement is generic over providers rather than coupled to
 * one module's config shape.
 *
 * Root display governs all depths: the section storage handed in resolves
 * its restrictions from the ROOT display (nested-aware storages delegate
 * getDefaultSectionStorage() scope-blind), and per-region providers derive
 * the governing layout from the scoped storage's getSection(delta), which
 * for a nested section is the nested section itself.
 *
 * Block answers always carry list => 'inline_blocks' (so core keeps the
 * inline_block derivatives in the definition set) plus browse => TRUE (the
 * layout_builder_browser convention layout_builder_restrictions gates its
 * block filtering on when a list is requested), making one call authoritative
 * for inline AND plugin blocks.
 */
interface PlacementRestrictionsInterface {

  /**
   * Block definitions placeable at a destination.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The section storage whose display governs the answer, or NULL when no
   *   storage is resolvable (only provider-independent filtering applies).
   * @param \Drupal\layout_builder\Section|null $section
   *   The destination section, for per-region providers. NULL for a
   *   region-agnostic listing (palette/schema shape): per-region providers
   *   then apply at op time instead.
   * @param string|null $region
   *   The destination region within the section, or NULL.
   *
   * @return array
   *   The filtered block plugin definitions, keyed by plugin ID.
   */
  public function allowedBlockDefinitions(?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): array;

  /**
   * Whether one block plugin may be placed at a destination.
   *
   * @param string $plugin_id
   *   The block plugin ID (inline_block:* or any other block plugin).
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The section storage whose display governs the answer.
   * @param \Drupal\layout_builder\Section|null $section
   *   The destination section, or NULL for a display-level answer.
   * @param string|null $region
   *   The destination region, or NULL.
   *
   * @return bool
   *   TRUE when the block survives the filtered-definitions answer.
   */
  public function blockAllowed(string $plugin_id, ?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): bool;

  /**
   * Whether a block plugin definition exists at all.
   *
   * Lets consumers distinguish "no such plugin" (INVALID) from "exists but
   * not allowed here" (RESTRICTED).
   *
   * @param string $plugin_id
   *   The block plugin ID.
   *
   * @return bool
   *   TRUE when the plugin is defined.
   */
  public function blockExists(string $plugin_id): bool;

  /**
   * Inline block bundles placeable at a destination.
   *
   * The inline projection of allowedBlockDefinitions(): bundle b is allowed
   * iff inline_block:b survives the filter.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The section storage whose display governs the answer.
   * @param \Drupal\layout_builder\Section|null $section
   *   The destination section, or NULL for a display-level answer.
   * @param string|null $region
   *   The destination region, or NULL.
   *
   * @return string[]
   *   Allowed block_content bundle machine names, sorted.
   */
  public function allowedInlineBlockBundles(?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): array;

  /**
   * Layout definitions allowed for sections under a storage's display.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The section storage whose display governs the answer, or NULL.
   *
   * @return \Drupal\Core\Layout\LayoutDefinition[]
   *   The filtered layout definitions keyed by layout ID; empty when layout
   *   discovery is unavailable.
   */
  public function allowedLayoutDefinitions(?SectionStorageInterface $storage): array;

  /**
   * Whether a layout may be used for sections under a storage's display.
   *
   * Sections at every depth share the ROOT display's answer.
   *
   * @param string $layout_id
   *   The layout plugin ID.
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The section storage whose display governs the answer, or NULL.
   *
   * @return bool
   *   TRUE when allowed (or when layout discovery is unavailable — nothing
   *   to enforce against).
   */
  public function layoutAllowed(string $layout_id, ?SectionStorageInterface $storage): bool;

  /**
   * Resolves the defaults section storage for display coordinates.
   *
   * The storage handed to the answer methods on paths that have no live
   * entity storage (whole-layout validation, schema building). Display
   * resolution mirrors core's collectRenderDisplays(): the requested view
   * mode first, falling back to 'default'.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode being edited.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The defaults storage, or NULL when layout_builder is unavailable or no
   *   display exists.
   */
  public function defaultsStorage(string $entity_type, string $bundle, string $view_mode = 'full'): ?SectionStorageInterface;

}
