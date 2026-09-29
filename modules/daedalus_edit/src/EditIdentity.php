<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * The one minter of daedalus_edit's identity attributes.
 *
 * A rendered field item and its form widget find each other by string
 * equality on identities both sides mint from the same facts (entity type,
 * entity id, field name, delta, property). Three shapes ride the page:
 * - the field wrapper id (data-daedalus-edit-field-value-wrapper on the
 *   render, data-daedalus-edit-form-item-wrapper-id on the form):
 *   type::id::field::property;
 * - the item id (data-daedalus-edit-page-element-id on the render,
 *   data-daedalus-edit-form-item-id on the form):
 *   type::id::field::delta::property;
 * - the edit id (data-daedalus-edit-id on the render only), the 9-part
 *   descriptor the client parses into its EditableElement info:
 *   type::bundle::id::field::delta::langcode::field_type::formatter::property.
 *
 * The property segment is the field's MAIN property (mainProperty()), the
 * same rule for a base field and a configurable one: the render and the
 * form once spelled it independently (the form always said `value`, the
 * render said `value` for configurable fields by a method_exists accident
 * and the storage's main property for base fields), so a base reference
 * field's widget could never find its leaf. One minter, one rule, both
 * sides. The label pseudo-field of a block placement is not a field and
 * passes its own property (block_property) explicitly.
 */
final class EditIdentity {

  /**
   * The segment separator of every identity shape.
   */
  public const SEPARATOR = '::';

  /**
   * The property segment of a placement's label pseudo-field.
   */
  public const LABEL_PROPERTY = 'block_property';

  /**
   * The field's main property.
   *
   * The property a formatter shows and a widget edits by default.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition (a base field or a field config).
   *
   * @return string
   *   The main property name; 'value' for a field type that declares none.
   */
  public static function mainProperty(FieldDefinitionInterface $definition): string {
    return $definition->getFieldStorageDefinition()->getMainPropertyName() ?? 'value';
  }

  /**
   * The field wrapper id: type::id::field::property.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity owning the field.
   * @param string $field_name
   *   The field name (or the label pseudo-field's 'label').
   * @param string $property
   *   The property segment (mainProperty() for a field).
   */
  public static function fieldWrapperId(EntityInterface $entity, string $field_name, string $property): string {
    return implode(self::SEPARATOR, [
      $entity->getEntityTypeId(),
      daedalus_ui_entity_identifier($entity),
      $field_name,
      $property,
    ]);
  }

  /**
   * The item id: type::id::field::delta::property.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity owning the field.
   * @param string $field_name
   *   The field name (or the label pseudo-field's 'label').
   * @param int|string $delta
   *   The item delta.
   * @param string $property
   *   The property segment.
   */
  public static function itemId(EntityInterface $entity, string $field_name, int|string $delta, string $property): string {
    return implode(self::SEPARATOR, [
      $entity->getEntityTypeId(),
      daedalus_ui_entity_identifier($entity),
      $field_name,
      $delta,
      $property,
    ]);
  }

  /**
   * The edit id, the client's full element descriptor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity owning the field.
   * @param string $field_name
   *   The field name (or the label pseudo-field's 'label').
   * @param int|string $delta
   *   The item delta.
   * @param string $langcode
   *   The language the item renders in.
   * @param string $field_type
   *   The field type plugin id.
   * @param string $formatter
   *   The formatter plugin id the item renders through.
   * @param string $property
   *   The property segment.
   */
  public static function editId(EntityInterface $entity, string $field_name, int|string $delta, string $langcode, string $field_type, string $formatter, string $property): string {
    return implode(self::SEPARATOR, [
      $entity->getEntityTypeId(),
      $entity->bundle(),
      daedalus_ui_entity_identifier($entity),
      $field_name,
      $delta,
      $langcode,
      $field_type,
      $formatter,
      $property,
    ]);
  }

}
