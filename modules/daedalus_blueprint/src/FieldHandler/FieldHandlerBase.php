<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Utility\HtmlEntityDecoder;

/**
 * Base class for field handlers with shared empty-guard and cardinality logic.
 *
 * Subclasses implement doSerialize() and doDeserialize() for type-specific
 * behavior. The base class handles empty field detection, cardinality wrapping,
 * and null/empty value guards.
 */
abstract class FieldHandlerBase implements FieldHandlerInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function serialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): mixed {
    if ($items->isEmpty()) {
      return NULL;
    }

    $values = $this->doSerialize($items, $definition);

    if (empty($values)) {
      return NULL;
    }

    return $this->wrapByCardinality($values, $definition);
  }

  /**
   * {@inheritdoc}
   */
  public function deserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    if ($value === NULL || $value === '' || $value === []) {
      return new FieldHandlerResult($value);
    }

    if (!empty($options['decode_html_entities'])
      && $this->decodesHtmlEntities()
      && !$this->hasTextFormat($definition)) {
      $value = HtmlEntityDecoder::decode($value);
    }

    return $this->doDeserialize($value, $definition, $options);
  }

  /**
   * Whether this handler decodes HTML entities itself.
   *
   * Handlers whose value contains OTHER entities' fields (inline entities)
   * must return FALSE and propagate $options instead: their payload mixes
   * field types, and only the per-field handler knows whether a value is
   * rich text.
   *
   * @return bool
   *   TRUE to decode at this level when the option is set.
   */
  protected function decodesHtmlEntities(): bool {
    return TRUE;
  }

  /**
   * Whether the field carries a text format (rich text).
   *
   * In rich text, entities like &amp; are legitimate HTML source, so they
   * are never decoded.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return bool
   *   TRUE for formatted text fields.
   */
  protected function hasTextFormat(FieldDefinitionInterface $definition): bool {
    $properties = $definition->getFieldStorageDefinition()->getPropertyDefinitions();
    return isset($properties['format'])
      && $properties['format']->getDataType() === 'filter_format';
  }

  /**
   * Normalizes an incoming value to the items a field will hold.
   *
   * A single-cardinality field, a scalar, or an associative array is one
   * item; a list on a multi-value field is one item per entry. Items are
   * keyed by their error path ("fields.name" for a single value,
   * "fields.name[index]" per list entry) so callers report per item without
   * rebuilding the path. Static because the structural validator normalizes
   * the same way before any handler runs.
   *
   * @param mixed $value
   *   The non-empty incoming value.
   * @param string $field_name
   *   The field machine name.
   * @param int $cardinality
   *   The field cardinality.
   *
   * @return array{0: array<string, mixed>, 1: bool}
   *   The items keyed by error path, and whether the value was a single
   *   value (so the result is one value rather than a list).
   */
  public static function normalizeItems(mixed $value, string $field_name, int $cardinality): array {
    if ($cardinality === 1 || !is_array($value) || !array_is_list($value)) {
      return [["fields.{$field_name}" => $value], TRUE];
    }

    $items = [];
    foreach ($value as $index => $item) {
      $items["fields.{$field_name}[{$index}]"] = $item;
    }

    return [$items, FALSE];
  }

  /**
   * Serializes non-empty field items into a values array.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The non-empty field item list.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array
   *   The serialized values (one entry per field item).
   */
  abstract protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array;

  /**
   * Deserializes a non-empty value.
   *
   * @param mixed $value
   *   The non-null, non-empty incoming value.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param array $options
   *   Options passed from the caller (e.g., 'is_update').
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult
   *   The result containing the processed value and any errors/warnings.
   */
  abstract protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult;

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantSettings(array $settings): array {
    $relevant = [];

    if (isset($settings['max_length'])) {
      $relevant['max_length'] = $settings['max_length'];
    }
    if (!empty($settings['allowed_values'])) {
      $relevant['allowed_values'] = $settings['allowed_values'];
    }
    if (isset($settings['min']) && $settings['min'] !== '') {
      $relevant['min'] = $settings['min'];
    }
    if (isset($settings['max']) && $settings['max'] !== '') {
      $relevant['max'] = $settings['max'];
    }

    return $relevant;
  }

  /**
   * Extracts the reference settings of a target-bearing field.
   *
   * The entity reference and inline entity handlers both report these as
   * their relevant settings and read them before resolving items: the
   * target entity type, and the allowed bundles flattened to a list (Drupal
   * stores them keyed by themselves).
   *
   * @param array $settings
   *   The full field settings from $definition->getSettings().
   *
   * @return array
   *   'target_type' and 'target_bundles' (a list), each only when set.
   */
  protected function referenceSettings(array $settings): array {
    $reference = [];

    if (isset($settings['target_type'])) {
      $reference['target_type'] = $settings['target_type'];
    }
    if (isset($settings['handler_settings']['target_bundles'])) {
      $reference['target_bundles'] = array_values($settings['handler_settings']['target_bundles']);
    }

    return $reference;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function skipInSummary(FieldDefinitionInterface $definition): bool {
    return FALSE;
  }

  /**
   * Wraps a values array according to field cardinality.
   *
   * @param array $values
   *   The serialized values array.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return mixed
   *   A single value for cardinality 1, or the full array.
   */
  protected function wrapByCardinality(array $values, FieldDefinitionInterface $definition): mixed {
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    if ($cardinality === 1) {
      return $values[0] ?? NULL;
    }

    return $values;
  }

}
