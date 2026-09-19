<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\EventSubscriber\SetInlineBlockDependency as CoreSetInlineBlockDependency;

/**
 * Depth-aware inline block access dependency resolution.
 *
 * Core resolves an inline block's access dependency from its usage row (the
 * layout entity using the block) and then verifies the block revision is
 * actually referenced in that entity's sections. Under the embedded-section
 * model, usage rows always point at the ROOT host entity (see
 * \Drupal\daedalus_layout\InlineBlockEntityOperations), so the dependency IS the
 * root directly and no chain walking is needed; the one gap left is the
 * verification step, whose section walk is one level deep in core. This
 * subclass makes it depth-aware so blocks nested in embedded sections
 * inherit access from the root host instead of being denied.
 *
 * @see \Drupal\layout_builder\EventSubscriber\SetInlineBlockDependency
 *
 * @internal
 *   Tagged services are internal.
 */
class SetInlineBlockDependency extends CoreSetInlineBlockDependency {

  /**
   * Overrides LayoutEntityHelperTrait::getEntitySections().
   *
   * Returns ALL sections, root and embedded, so
   * isBlockRevisionUsedInEntity() finds inline block revisions at any depth.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The layout builder enabled entity.
   *
   * @return \Drupal\layout_builder\Section[]
   *   All sections within this entity, at every depth.
   */
  protected function getEntitySections(EntityInterface $entity) {
    $section_storage = $this->getSectionStorageForEntity($entity);
    if (!$section_storage) {
      return [];
    }
    return NestedAwareSectionStorage::wrap($section_storage)->getAllSections();
  }

}
