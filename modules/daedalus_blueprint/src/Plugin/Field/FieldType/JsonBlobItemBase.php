<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A field item holding one JSON document in a single big text column.
 *
 * The storage half of every "one serialized blob" field type: a lone
 * `value` string property, a big text column, emptiness as NULL or the
 * empty string, and JSON in and out with slashes and unicode unescaped.
 * Subclasses own the grammar: they name the property (valueLabel()) and
 * expose typed accessors over getJson() and setJson(), so the stored
 * shape is validated and read by the owner while the column and its
 * encoding stay one thing across modules.
 *
 * Deliberately NOT decomposed into sub-fields: nothing queries inside a
 * blob, and a second storage grammar would be a second thing to drift.
 *
 * @property string|null $value
 */
abstract class JsonBlobItemBase extends FieldItemBase {

  /**
   * The label of the single `value` property.
   */
  abstract protected static function valueLabel(): TranslatableMarkup;

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(static::valueLabel())
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'value';
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'big',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return $this->value === NULL || $this->value === '';
  }

  /**
   * Returns the decoded document, or NULL when the item is empty.
   *
   * Undecodable text also decodes to NULL, so a subclass accessor's type
   * check is the one place the stored shape is judged.
   */
  protected function getJson(): mixed {
    if ($this->isEmpty()) {
      return NULL;
    }
    return json_decode($this->value, TRUE);
  }

  /**
   * Stores the document, or empties the item for an empty one.
   *
   * An empty array or string empties the item so "no document" and "no
   * field value" are the same state.
   */
  protected function setJson(string|array $document): void {
    $this->setValue(['value' => $document === '' || $document === [] ? NULL : json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
  }

}
