<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_form_only_test;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerBase;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;

/**
 * A handler with no serialized representation: form-only in the grammar.
 *
 * Claims the field named field_form_only. Its value is writable (the
 * deserializer passes it through) but never reads back, the shape of a
 * field type whose handler cannot spell it, so a value block cannot hold
 * it.
 */
class FormOnlyFieldHandler extends FieldHandlerBase {

  /**
   * The field name this handler claims.
   */
  public const FIELD_NAME = 'field_form_only';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getName() === self::FIELD_NAME;
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    return new FieldHandlerResult($value);
  }

}
