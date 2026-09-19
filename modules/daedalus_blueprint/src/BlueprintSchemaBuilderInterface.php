<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Builds descriptive schemas for entity type + bundle combinations.
 *
 * The schema describes what fields exist, their types, settings, and
 * metadata. Used by AI agents to learn what's possible before constructing
 * a blueprint.
 */
interface BlueprintSchemaBuilderInterface {

  /**
   * Gets the descriptive schema for an entity type and bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode for filtering available block types by
   *   layout_builder_restrictions settings. Defaults to 'full' (the view
   *   mode used by canonical entity routes). The display resolution mirrors
   *   core: tries the requested view mode first, falls back to 'default'.
   *   Empty strings are treated as 'full'.
   *
   * @return array
   *   The schema array with keys: entity_type, bundle, label, fields.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\BlueprintException
   *   If the entity type does not exist, is not fieldable, the bundle does not
   *   exist, or the current user does not have create access for the bundle.
   */
  public function getSchema(string $entity_type, string $bundle, ?string $view_mode = 'full'): array;

}
