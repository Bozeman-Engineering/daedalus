<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Resolves context YAML guidance and defaults for entity type + bundle combos.
 *
 * Extracts the context resolution pipeline (universal YAML, scoped YAML,
 * hook alter) from BlueprintSchemaBuilder so it can be shared by both the
 * schema system and the element resolution system.
 */
interface BlueprintContextResolverInterface {

  /**
   * Resolves the full context array for an entity type + bundle.
   *
   * Merges universal YAML -> scoped YAML -> hook alter.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return array
   *   The context array with optional keys: guidance, defaults, field_types,
   *   block_types, layouts.
   */
  public function getContext(string $entity_type, string $bundle): array;

  /**
   * Gets entity-level guidance.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return string|null
   *   The guidance string, or NULL if none.
   */
  public function getEntityGuidance(string $entity_type, string $bundle): ?string;

  /**
   * Gets block type context (guidance + defaults).
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $block_type
   *   The block content type machine name.
   *
   * @return array
   *   Array with optional 'guidance' and 'defaults' keys. Empty if no context.
   */
  public function getBlockTypeContext(string $entity_type, string $bundle, string $block_type): array;

  /**
   * Gets layout context (guidance + defaults).
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $layout_id
   *   The layout plugin ID.
   *
   * @return array
   *   Array with optional 'guidance' and 'defaults' keys. Empty if no context.
   */
  public function getLayoutContext(string $entity_type, string $bundle, string $layout_id): array;

  /**
   * Gets field type guidance from context YAML.
   *
   * This returns the YAML override only, not handler-provided guidance.
   * The consumer is responsible for merging with handler guidance.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $field_type
   *   The field type plugin ID.
   *
   * @return string|null
   *   The guidance string, or NULL if none.
   */
  public function getFieldTypeGuidance(string $entity_type, string $bundle, string $field_type): ?string;

}
