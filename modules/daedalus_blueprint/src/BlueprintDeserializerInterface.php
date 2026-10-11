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
   *   optionally _root_entity, langcode, layout, styles.
   * @param array $options
   *   Options controlling deserialization behavior:
   *   - validate_only (bool): Judge the whole blueprint (a create's layout
   *     expanded on the unsaved host included) without answering an
   *     entity; nothing persists. Default FALSE.
   *   - actor (AccountInterface): the account the write is judged for;
   *     the current user by default.
   *   - system (bool): TRUE skips the capability gate, as a system batch
   *     does.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result containing the unsaved entity on success, or errors on
   *   failure. The caller is responsible for saving the entity.
   */
  public function deserialize(array $data, array $options = []): BlueprintResult;

}
