<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityInterface;

/**
 * Resolves the editable section storage for an entity.
 */
trait DaedalusLayoutEntityHelperTrait {

  /**
   * Gets the editable section storage for an entity.
   *
   * Thin adapter over the storage spine's one defaults->overrides upgrade
   * helper (daedalus_tempstore.section_storage_helper): a not-yet-overridden
   * entity yields its OWN overrides storage seeded from the bundle display,
   * never the shared defaults storage, and the view mode context is the
   * resolved display mode (e.g. 'full' falls back to 'default'), so the
   * storage published to the UI and the tempstore keys agree.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The requested view mode.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage if found otherwise NULL.
   */
  protected function getSectionStorageForEntity(EntityInterface $entity, $view_mode = 'full') {
    return \Drupal::service('daedalus_tempstore.section_storage_helper')
      ->getEditableSectionStorage($entity, $view_mode);
  }

}
