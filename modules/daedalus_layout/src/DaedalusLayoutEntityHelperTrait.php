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
   * entity yields its OWN overrides storage, seeded from the display's
   * template with fresh identity at the host's storage door, never the
   * shared defaults storage; the view mode context is the host's resolved
   * display mode, so the storage published to the UI and the tempstore
   * keys agree.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage if found otherwise NULL.
   */
  protected function getSectionStorageForEntity(EntityInterface $entity) {
    return \Drupal::service('daedalus_tempstore.section_storage_helper')
      ->getEditableSectionStorage($entity);
  }

}
