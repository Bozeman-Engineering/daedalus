<?php

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\layout_builder\OverridesSectionStorageInterface;

/**
 * Nested-aware wrapper for overrides section storages.
 *
 * This subclass implements OverridesSectionStorageInterface so that
 * instanceof checks (e.g. in layout_builder_restrictions) pass through
 * correctly when the root storage is an overrides storage.
 */
class NestedAwareOverridesSectionStorage extends NestedAwareSectionStorage implements OverridesSectionStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function getDefaultSectionStorage() {
    return $this->rootStorage->getDefaultSectionStorage();
  }

  /**
   * {@inheritdoc}
   */
  public function isOverridden() {
    return $this->rootStorage->isOverridden();
  }

  /**
   * {@inheritdoc}
   */
  public function isSupported(string $entity_type_id, string $bundle, string $view_mode): bool {
    return $this->rootStorage->isSupported($entity_type_id, $bundle, $view_mode);
  }

}
