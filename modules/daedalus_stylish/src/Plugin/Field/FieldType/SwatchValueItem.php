<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Plugin\Field\FieldType\JsonBlobItemBase;

/**
 * A swatch's CSS value: a string or a condition-keyed map, as one JSON doc.
 *
 * Shares StyleMapItem's storage (JsonBlobItemBase) but is deliberately its
 * own type: the grammar is a token VALUE, not a map of keys — a plain CSS
 * string ("#1a1a2e"), or a condition-keyed map with a required 'default'
 * entry ({"default": "...", "dark": "..."}) on the width/scheme/motion
 * axes (StyleMapValidator::TOKEN_CONDITION_AXES), validated through
 * StyleMapValidator::validateSwatchValue(). A distinct type id lets the
 * daedalus_blueprint field handler target swatch values precisely when that
 * lane lands.
 */
#[FieldType(
  id: 'daedalus_swatch_value',
  label: new TranslatableMarkup('Swatch value'),
  description: new TranslatableMarkup('The CSS value a swatch names: a string or a condition-keyed map.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SwatchValueItem extends JsonBlobItemBase {

  /**
   * {@inheritdoc}
   */
  protected static function valueLabel(): TranslatableMarkup {
    return new TranslatableMarkup('Swatch value JSON');
  }

  /**
   * Returns the decoded swatch value.
   *
   * @return string|array
   *   The value as authored: a plain CSS string, or a condition-keyed map.
   *   The empty string when the item holds no value.
   */
  public function getSwatchValue(): string|array {
    $value = $this->getJson();
    return is_string($value) || is_array($value) ? $value : '';
  }

  /**
   * Sets the swatch value.
   *
   * An empty value empties the item so "no value" and "no field value" are
   * the same state.
   */
  public function setSwatchValue(string|array $value): void {
    $this->setJson($value);
  }

}
