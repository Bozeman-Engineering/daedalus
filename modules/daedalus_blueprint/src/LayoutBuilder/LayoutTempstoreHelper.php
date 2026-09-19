<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_tempstore\SectionStorageHelper;

/**
 * Bridges entity persistence to the Layout Builder tempstore.
 *
 * Holds every class-load-time dependency on layout_builder (the
 * LayoutEntityHelperTrait and the layout_builder type hints) so the rest of
 * daedalus_blueprint stays loadable without layout_builder installed.
 * DaedalusBlueprintServiceProvider registers this helper only when
 * layout_builder is present; EntityStorageHandler receives it as an optional
 * dependency and skips the Layout Builder path when it is NULL.
 */
class LayoutTempstoreHelper {

  use LayoutEntityHelperTrait;

  /**
   * Constructs a LayoutTempstoreHelper.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layoutTempstoreRepository
   *   The layout builder tempstore repository.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $sectionStorageManager
   *   The section storage manager. Sets the trait property from
   *   LayoutEntityHelperTrait.
   * @param \Drupal\daedalus_tempstore\SectionStorageHelper|null $sectionStorageHelper
   *   The storage spine's shared defaults->overrides upgrade helper, or NULL
   *   when daedalus_tempstore is not installed. The nullable type keeps this
   *   class loadable without daedalus_tempstore: NULL never triggers class
   *   loading (same pattern as EntityStorageHandler's optional
   *   daedalus_tempstore dependency).
   */
  public function __construct(
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    SectionStorageManagerInterface $sectionStorageManager,
    protected ?SectionStorageHelper $sectionStorageHelper = NULL,
  ) {
    $this->sectionStorageManager = $sectionStorageManager;
  }

  /**
   * Checks if an entity is layout builder compatible.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity is Layout Builder compatible.
   */
  public function isLayoutCompatible(FieldableEntityInterface $entity): bool {
    return $this->isLayoutCompatibleEntity($entity);
  }

  /**
   * Loads an entity from layout builder tempstore.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The database entity to check for tempstore edits.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The tempstore entity if found, otherwise the original entity.
   */
  public function loadFromLayoutTempstore(FieldableEntityInterface $entity): FieldableEntityInterface {
    $section_storage = $this->getOverridesSectionStorageForEntity($entity);
    if ($section_storage === NULL) {
      return $entity;
    }

    if (!$this->layoutTempstoreRepository->has($section_storage)) {
      return $entity;
    }

    $tempstore_storage = $this->layoutTempstoreRepository->get($section_storage);
    $tempstore_entity = $this->getEntityFromSectionStorage($tempstore_storage);
    if ($tempstore_entity instanceof FieldableEntityInterface) {
      return $tempstore_entity;
    }

    return $entity;
  }

  /**
   * Persists an entity to layout builder tempstore.
   *
   * Converts the entity to an overrides section storage, copies default
   * sections when needed, and stores the result in LB tempstore.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to persist.
   */
  public function persistToLayoutTempstore(FieldableEntityInterface $entity): void {
    $section_storage = $this->getSectionStorageForLbTempstore($entity);
    if ($section_storage === NULL) {
      // Fallback: save directly if section storage cannot be obtained.
      $entity->save();
      return;
    }
    $this->layoutTempstoreRepository->set($section_storage);
  }

  /**
   * Gets the section storage suitable for LB tempstore persistence.
   *
   * Delegates the defaults->overrides conversion for not-yet-customized
   * entities to the storage spine's one shared upgrade helper
   * (daedalus_tempstore.section_storage_helper), so this path resolves the same
   * storage and tempstore key as every other page builder path.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage, or NULL if unavailable.
   */
  private function getSectionStorageForLbTempstore(FieldableEntityInterface $entity): ?SectionStorageInterface {
    if ($this->sectionStorageHelper !== NULL) {
      return $this->sectionStorageHelper->getEditableSectionStorage($entity);
    }

    // Without daedalus_tempstore there is no defaults->overrides upgrade path.
    // NEVER stage a defaults storage: that would stage the SHARED bundle
    // display, and the entity's field edits would be lost on load (a
    // defaults storage carries no entity context). Returning NULL makes
    // persistToLayoutTempstore() save the entity directly instead.
    $section_storage = $this->getSectionStorageForEntity($entity);
    if ($section_storage instanceof DefaultsSectionStorageInterface) {
      return NULL;
    }

    return $section_storage;
  }

  /**
   * Extracts the entity from section storage context.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The entity, or NULL if not found.
   */
  private function getEntityFromSectionStorage(SectionStorageInterface $section_storage): ?FieldableEntityInterface {
    try {
      if (method_exists($section_storage, 'getContextValue')) {
        $entity = $section_storage->getContextValue('entity');
        if ($entity instanceof FieldableEntityInterface) {
          return $entity;
        }
      }
    }
    catch (\Exception $e) {
      // Context not available.
    }

    return NULL;
  }

}
