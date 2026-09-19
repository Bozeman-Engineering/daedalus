<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Represents a resolved page element with identity, data, and context.
 *
 * Returned by BlueprintElementResolver::resolve(). Contains everything a
 * consumer needs to describe an element to an AI: what it is, where it sits,
 * its current values, and any guidance/defaults from context YAML.
 */
class ResolvedElement {

  /**
   * Constructs a ResolvedElement.
   *
   * @param string $elementType
   *   The element type: 'block', 'paragraph', 'field', 'section', 'region'.
   * @param string $typeId
   *   The type identifier: block_type, paragraph bundle, field_type,
   *   layout_id, or layout_id for regions.
   * @param string $label
   *   Human-readable name.
   * @param string $entityType
   *   The root entity type ID.
   * @param string|int $entityId
   *   The root entity ID.
   * @param string|null $uuid
   *   UUID for blocks, paragraphs, and sections.
   * @param string|null $fieldName
   *   Field name for field elements.
   * @param string|null $regionName
   *   Region name for region elements.
   * @param string|null $parentUuid
   *   Parent element UUID when nested.
   * @param string|null $parentLabel
   *   Parent element label when nested.
   * @param string|null $parentTypeId
   *   Parent element type ID when nested.
   * @param string|null $guidance
   *   Merged guidance string (YAML override wins over handler guidance).
   * @param array|null $defaults
   *   Default values from context YAML.
   * @param mixed $data
   *   Current values. Shape varies by elementType and field cardinality.
   */
  public function __construct(
    public readonly string $elementType,
    public readonly string $typeId,
    public readonly string $label,
    public readonly string $entityType,
    public readonly string|int $entityId,
    public readonly ?string $uuid = NULL,
    public readonly ?string $fieldName = NULL,
    public readonly ?string $regionName = NULL,
    public readonly ?string $parentUuid = NULL,
    public readonly ?string $parentLabel = NULL,
    public readonly ?string $parentTypeId = NULL,
    public readonly ?string $guidance = NULL,
    public readonly ?array $defaults = NULL,
    public readonly mixed $data = NULL,
  ) {}

  /**
   * Converts to an array representation.
   *
   * @return array
   *   The element as an associative array with NULL values omitted.
   */
  public function toArray(): array {
    $result = [
      'element_type' => $this->elementType,
      'type_id' => $this->typeId,
      'label' => $this->label,
      'entity_type' => $this->entityType,
      'entity_id' => $this->entityId,
    ];

    if ($this->uuid !== NULL) {
      $result['uuid'] = $this->uuid;
    }
    if ($this->fieldName !== NULL) {
      $result['field_name'] = $this->fieldName;
    }
    if ($this->regionName !== NULL) {
      $result['region_name'] = $this->regionName;
    }
    if ($this->parentUuid !== NULL) {
      $result['parent_uuid'] = $this->parentUuid;
      $result['parent_label'] = $this->parentLabel;
      $result['parent_type_id'] = $this->parentTypeId;
    }
    if ($this->guidance !== NULL) {
      $result['guidance'] = $this->guidance;
    }
    if ($this->defaults !== NULL) {
      $result['defaults'] = $this->defaults;
    }
    if ($this->data !== NULL) {
      $result['data'] = $this->data;
    }

    return $result;
  }

}
