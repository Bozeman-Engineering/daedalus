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
 * EditableUiBuilder keeps only preserved fields beside the layout), so a
 * non-preserved root field has no row of its own in the page-elements
 * tree: this feeder answers the tree's default rows, at bundle and view
 * mode granularity, which is the right grain for that question.
 *
 * It is NOT an editability verdict. The same field can still reach the
 * canvas through a Layout Builder FIELD BLOCK, and that render carries the
 * field's own key inside the placement (block:{uuid} wrapping
 * field:{root}:{field_name}, attributed exactly like a preserved field's,
 * the editable leaf inside). A per-bundle answer cannot know which layouts
 * placed one, and it does not need to: the placement presents MERGED into
 * the field it renders (ElementModel::fieldBlockPresentation, stamped on
 * the placement and delivered as its `_element`), so the panel folds the
 * placement row and the field row as one and the canvas resolver admits
 * the leaf through the placement, and the resolver's own gate admits any
 * rendered leaf whose key names a known field of a known subject on the
 * mirror. Rendered evidence beats display inference; this feeder is the
 * inference. Root entities only: inline-block fields render through their
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
