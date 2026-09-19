<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Utility;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Identity-only stub nodes for fields the blueprint carries no value for.
 *
 * Structure completeness: every processable field definition gets a node in
 * FULL serialization so structural consumers (the page-elements panel, the
 * presentation policy) cover the same set the canvas can select — a field
 * can be selectable/editable on the page while absent from the blueprint
 * (empty value, view access filtered, no serializable representation). The
 * stub carries identity only: a label and the form_only marker; its only
 * editing surface is the entity form (form-swap), never blueprint values.
 *
 * Summary and AI transport omit stubs — the LLM never sees fields it cannot
 * write — and every write path treats a stub value as "leave this field
 * alone" (PATCH omit semantics), so a full blueprint echoed back in a
 * read-modify-write can never smuggle a stub into stored field values.
 */
final class FormOnlyStub {

  /**
   * The marker key identifying a stub value.
   */
  public const KEY = '_form_only';

  /**
   * Builds the stub node for a field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array
   *   The identity-only stub value.
   */
  public static function forDefinition(FieldDefinitionInterface $definition): array {
    return [
      self::KEY => TRUE,
      '_label' => (string) $definition->getLabel(),
    ];
  }

  /**
   * Whether a serialized field value is a stub node.
   *
   * @param mixed $value
   *   The field value from a blueprint.
   *
   * @return bool
   *   TRUE for a stub.
   */
  public static function isStub(mixed $value): bool {
    return is_array($value) && !empty($value[self::KEY]);
  }

  /**
   * Recursively removes stub nodes from a blueprint array.
   *
   * Used by AI transport before handing a FULL blueprint to the model.
   *
   * @param array $data
   *   The blueprint data.
   *
   * @return array
   *   The data without stub entries.
   */
  public static function strip(array $data): array {
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        if (self::isStub($value)) {
          unset($data[$key]);
          continue;
        }
        $data[$key] = self::strip($value);
      }
    }
    return $data;
  }

}
