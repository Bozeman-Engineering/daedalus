<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Deserializes blueprint JSON into Drupal entities.
 *
 * Performs phased validation (structural then semantic) and returns a result
 * containing the hydrated entity or collected errors.
 */
interface BlueprintDeserializerInterface {

  /**
   * Deserializes a blueprint array into an entity.
   *
   * @param array $data
   *   The blueprint data with keys: entity_type, bundle, fields, and
   *   optionally _root_entity, langcode, layout_builder__layout.
   * @param array $options
   *   Options controlling deserialization behavior:
   *   - validate_only (bool): Run structural validation only, do not hydrate
   *     the entity. Default FALSE.
   *   - view_mode (string): The view mode for layout_builder_restrictions
   *     enforcement. Defaults to 'full'.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result containing the unsaved entity on success, or errors on
   *   failure. The caller is responsible for saving the entity.
   */
  public function deserialize(array $data, array $options = []): BlueprintResult;

}
