<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\ElementModel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\ElementModel\ExistenceFeederInterface;

/**
 * Feeds root-field existence from a display's preserved fields.
 *
 * On an LB-enabled display, daedalus_layout's LayoutBuilderEntityViewDisplay
 * removes every display-configurable field from the entity render except
 * the display's daedalus_layout preserved_fields (edit mode agrees:
 * EditableUiBuilder keeps only preserved fields beside the layout). A
 * non-preserved root field can still reach the canvas through a Layout
 * Builder FIELD BLOCK, but that renders as a block-keyed element
 * (block:{uuid}), never as field:{root}:{field_name}, so nothing ever
 * answers to the root field's own key and reporting it nonexistent is
 * safe. Root entities only: inline-block fields render through their
 * bundle's own display (daedalus_ui's display-hidden feeder), and
 * non-configurable fields stay rendered on the live page, so the display
 * cannot vouch for their absence.
 */
final class PreservedFieldsRootExistence implements ExistenceFeederInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function nonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array {
    if ($kind !== ElementModel::KIND_ENTITY) {
      return [];
    }
    // Resolve the display the render path uses: the requested view mode
    // when it is saved and enabled, otherwise the default display
    // (EntityViewDisplay::collectRenderDisplay's fallback).
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $storage->load("$entity_type_id.$bundle.$view_mode");
    if (!$display instanceof EntityViewDisplayInterface || !$display->status()) {
      $display = $storage->load("$entity_type_id.$bundle.default");
    }
    if (!$display instanceof EntityViewDisplayInterface || !$display->getThirdPartySetting('layout_builder', 'enabled', FALSE)) {
      return [];
    }
    $preserved = $display->getThirdPartySetting('daedalus_layout', 'preserved_fields', []);
    $names = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $definition) {
      if ($definition->isDisplayConfigurable('view') && !in_array($name, $preserved, TRUE)) {
        $names[] = $name;
      }
    }
    return $names;
  }

}
