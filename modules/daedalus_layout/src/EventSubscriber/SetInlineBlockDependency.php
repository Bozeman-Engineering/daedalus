<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_layout\OwnedSectionsTrait;
use Drupal\layout_builder\EventSubscriber\SetInlineBlockDependency as CoreSetInlineBlockDependency;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;

/**
 * Depth-aware inline block access dependency resolution, by ownership.
 *
 * Core resolves an inline block's access dependency from its usage row (the
 * layout entity using the block) and then verifies the block revision is
 * actually referenced in that entity's sections. Under the embedded-section
 * model, usage rows always point at the ROOT host entity (see
 * \Drupal\daedalus_layout\InlineBlockEntityOperations), so the dependency
 * IS the root directly and no chain walking is needed; the one gap left is
 * the verification step, whose section walk is one level deep in core.
 * This subclass makes it depth-aware so blocks nested in embedded sections
 * inherit access from the root host instead of being denied.
 *
 * The verification reads what the host OWNS (OwnedSectionsTrait), the same
 * door the presave saves through, so the spine has one reading of "what a
 * host holds": a usage row's host is verified against its own document,
 * never against the template its render falls through to.
 *
 * @see \Drupal\layout_builder\EventSubscriber\SetInlineBlockDependency
 *
 * @internal
 *   Tagged services are internal.
 */
class SetInlineBlockDependency extends CoreSetInlineBlockDependency {

  use OwnedSectionsTrait;

  /**
   * Constructs SetInlineBlockDependency object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\layout_builder\InlineBlockUsageInterface $usage
   *   The inline block usage service.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $section_storage_manager
   *   The section storage manager.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: the ownership door.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    InlineBlockUsageInterface $usage,
    SectionStorageManagerInterface $section_storage_manager,
    protected HostResolver $hostResolver,
  ) {
    parent::__construct($entity_type_manager, $database, $usage, $section_storage_manager);
  }

  /**
   * {@inheritdoc}
   *
   * ALL sections the entity owns, root and embedded, so
   * isBlockRevisionUsedInEntity() finds inline block revisions at any depth
   * and none of the template's.
   */
  protected function getEntitySections(EntityInterface $entity) {
    return $this->ownedSections($entity);
  }

  /**
   * {@inheritdoc}
   *
   * An entity that can hold a document of its own.
   */
  protected function isLayoutCompatibleEntity(EntityInterface $entity) {
    return $this->ownsDocument($entity);
  }

}
