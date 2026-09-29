<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * A field value the caller already extracted and validated in-process.
 *
 * The blueprint grammar carries field values as JSON-shaped data that a
 * field handler deserializes and that the processable filter admits. Some
 * values never take that road: a Drupal widget's submitted input is turned
 * into a field item list by the entity form itself (widget extraction and
 * validation, site form alters, every widget that exists or will exist),
 * and the resulting item list is the value, already spelled the way the
 * field stores it. An ExtractedValue is how such a caller hands that item
 * list to the spine: `update_element` with `properties: {field: ExtractedValue}`.
 *
 * On the write path (BlueprintOperations::validateAndApplyFields()) an
 * ExtractedValue is applied directly: no handler deserialize, and no
 * processable filter, because the field a form edits (a path alias, the
 * status, the author, a widget-only field) need not be one the blueprint
 * reads or the AI writes. What stays is everything that judges whether the
 * write may land: the field must exist on the entity, the caller must have
 * edit access to it, and entity constraints run on the applied value.
 *
 * Server-only by construction: it wraps a live item list, never crosses the
 * wire, and is never minted from request data. The item list is read
 * through getValue(), so the target entity receives a plain copy and the
 * source list (the form-built entity's) is left untouched.
 */
final class ExtractedValue {

  public function __construct(
    private readonly FieldItemListInterface $items,
  ) {}

  /**
   * The extracted item list, as the caller built it.
   */
  public function getItems(): FieldItemListInterface {
    return $this->items;
  }

  /**
   * The item list's values, the shape FieldableEntityInterface::set() takes.
   *
   * @return array
   *   One value array per item, in delta order.
   */
  public function getValue(): array {
    return $this->items->getValue();
  }

}
