<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Infers block-field non-existence from the bundle's view display.
 *
 * An inline block renders through its bundle's own view display, so a
 * display-hidden field never renders as an attributed field element: no
 * canvas element ever answers to its key. Inline blocks (KIND_BLOCK) only:
 * a ROOT entity's display-hidden field is not a no-canvas proxy (on a
 * Layout Builder display the fields render as field BLOCKS, a different
 * key family; daedalus_layout's preserved-fields feeder owns the root answer).
 */
final class DisplayHiddenFields implements ExistenceFeederInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Resolves the display the render path uses: InlineBlock renders the
   * 'full' view mode and EntityViewDisplay::collectRenderDisplay falls back
   * to the default display when 'full' is absent or disabled. No saved
   * display means core fabricates one with every field visible, so nothing
   * hides. Fields the display cannot govern (not display configurable) stay
   * rendered: the display cannot vouch for their absence.
   */
  public function nonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array {
    if ($kind !== ElementModel::KIND_BLOCK) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $storage->load("$entity_type_id.$bundle.$view_mode");
    if (!$display instanceof EntityViewDisplayInterface || !$display->status()) {
      $display = $storage->load("$entity_type_id.$bundle.default");
    }
    if (!$display instanceof EntityViewDisplayInterface) {
      return [];
    }
    $hidden = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $definition) {
      if ($definition->isDisplayConfigurable('view') && $display->getComponent($name) === NULL) {
        $hidden[] = $name;
      }
    }
    return $hidden;
  }

}
