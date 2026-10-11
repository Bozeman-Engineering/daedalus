<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Infers field non-existence from what a view display can carry.
 *
 * Two truths, both the display's:
 *
 * A field with no view display options renders through no display, at
 * either kind: core's display carries only the fields that declare them
 * (EntityDisplayBase::fieldHasDisplayOptions()), so a base field without
 * them is record metadata (a saved blueprint's scope, block_content's
 * reusable) and no canvas element ever answers to its key. A configurable
 * field always declares them (hidden by default), so this speaks for base
 * fields only. The root's label key is excepted: its one canvas render may
 * live OUTSIDE the entity render as the page title, which the display
 * cannot speak for, and an inferred non-existence would veto the root
 * row's edit target (ElementModel::rootPresentation()).
 *
 * An inline block renders through its bundle's own view display, so a
 * display-hidden field never renders as an attributed field element.
 * Inline blocks (KIND_BLOCK) only: a ROOT entity's display-hidden field is
 * not a no-canvas proxy (on a Layout Builder display the fields render as
 * field BLOCKS, a different key family; daedalus_layout's Layout Builder
 * root-existence feeder owns the root answer).
 */
final class DisplayHiddenFields implements ExistenceFeederInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * The hidden-on-display half resolves the display the render path uses:
   * InlineBlock renders the 'full' view mode and
   * EntityViewDisplay::collectRenderDisplay falls back to the default
   * display when 'full' is absent or disabled. No saved display means core
   * fabricates one with every field visible, so nothing hides. A field that
   * declares view display options but is not display configurable stays
   * rendered: the display cannot vouch for its absence.
   */
  public function nonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $label_field = $kind === ElementModel::KIND_ENTITY
      ? $this->entityTypeManager->getDefinition($entity_type_id)->getKey('label')
      : NULL;
    $names = [];
    foreach ($definitions as $name => $definition) {
      if (!$definition->getDisplayOptions('view') && $name !== $label_field) {
        $names[] = $name;
      }
    }
    if ($kind !== ElementModel::KIND_BLOCK) {
      return $names;
    }
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $storage->load("$entity_type_id.$bundle.$view_mode");
    if (!$display instanceof EntityViewDisplayInterface || !$display->status()) {
      $display = $storage->load("$entity_type_id.$bundle.default");
    }
    if (!$display instanceof EntityViewDisplayInterface) {
      return $names;
    }
    foreach ($definitions as $name => $definition) {
      if ($definition->isDisplayConfigurable('view') && $display->getComponent($name) === NULL) {
        $names[] = $name;
      }
    }
    return $names;
  }

}
