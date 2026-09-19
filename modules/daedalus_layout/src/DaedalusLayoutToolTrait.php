<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Decides whether the Daedalus Layout tool applies to an entity.
 */
trait DaedalusLayoutToolTrait {

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * Checks whether the entity renders through a Layout Builder view display.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   *
   * @return bool
   *   TRUE when the rendered view display has Layout Builder enabled.
   */
  public function daedalusLayoutToolApplies(EntityInterface $entity) {

    // Layout Builder only applies to fieldable content entities. Config
    // entities (node types, views, roles, ...) can appear in a route on admin
    // pages and reach here via deriveEntityFromRoute(), but they have no view
    // displays, so probing for one is meaningless.
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }

    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    // Get the view mode used to render the entity.
    $view_mode = \Drupal::service('daedalus_ui.view_mode_tracker')->getViewMode($entity_type_id, daedalus_ui_entity_identifier($entity));
    // Ensure it exists or fallback to one that does.
    $view_mode = _daedalus_ui_get_view_mode($entity, $view_mode);
    $view_display = $this->entityDisplayRepository()->getViewDisplay($entity_type_id, $bundle, $view_mode);

    if ($view_display) {
      // Check if Layout Builder is enabled for this view display.
      return !empty($view_display->getThirdPartySetting('layout_builder', 'enabled', FALSE));
    }
    return FALSE;
  }

  /**
   * Gets the entity display repository.
   *
   * @return \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   *   The entity display repository.
   */
  protected function entityDisplayRepository(): EntityDisplayRepositoryInterface {
    return $this->entityDisplayRepository ?: \Drupal::service('entity_display.repository');
  }

}
