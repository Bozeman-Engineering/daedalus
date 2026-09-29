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
 * stub carries identity: a label and the form_only marker; its only
 * editing surface is the entity form, never blueprint values.
 *
 * A stub is ambiguous on its own: an empty field and a populated field the
 * blueprint cannot spell (a reference whose target the user may not view,
 * a type with no serializable representation) stub identically. The panel's
 * field-state judge (daedalus_edit field-state.js) needs the difference,
 * because an empty field earns a "Fill with sample" verb and a populated one
 * must not (a `_sample` request replaces the value). So a stub for a field
 * the user may VIEW also carries the emptiness flag (EMPTY_KEY, TRUE only
 * when the field is empty); a view-access-filtered stub carries nothing
 * beyond identity, so the flag never says anything about a value the user
 * cannot see. A stub without the flag reads as "present".
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
   * The emptiness flag: present and TRUE only on a stub for an empty field.
   */
  public const EMPTY_KEY = '_empty';

  /**
   * Builds the stub node for a field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param bool $empty
   *   TRUE when the serializer may say the field is empty (the user can view
   *   it and its item list is empty). FALSE, the default, emits no flag: a
   *   populated field the blueprint cannot spell, or a field the user cannot
   *   view, stays identity-only.
   *
   * @return array
   *   The stub value.
   */
  public static function forDefinition(FieldDefinitionInterface $definition, bool $empty = FALSE): array {
    $stub = [
      self::KEY => TRUE,
      '_label' => (string) $definition->getLabel(),
    ];
    if ($empty) {
      $stub[self::EMPTY_KEY] = TRUE;
    }
    return $stub;
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
