<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_tempstore\SectionStorageHelper;

/**
 * Bridges host persistence to the Layout Builder tempstore.
 *
 * The staging road of a site with layout_builder and no daedalus_tempstore.
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
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: a staged storage answers its host.
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
    protected HostResolver $hostResolver,
    protected ?SectionStorageHelper $sectionStorageHelper = NULL,
  ) {
    $this->sectionStorageManager = $sectionStorageManager;
  }

  /**
   * Loads a host from layout builder tempstore.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host of the stored entity, checked for tempstore edits.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host of the staged copy if one is found, otherwise the host
   *   handed in.
   */
  public function loadFromLayoutTempstore(HostInterface $host): HostInterface {
    // The host's own storage (an overrides storage for a content host, the
    // defaults storage for a display host), unwrapped: the tempstore keys
    // by the root storage.
    $section_storage = $host->sectionStorage();
    if ($section_storage instanceof NestedSectionStorageInterface) {
      $section_storage = $section_storage->getRootStorage();
    }
    if ($section_storage === NULL) {
      return $host;
    }

    if (!$this->layoutTempstoreRepository->has($section_storage)) {
      return $host;
    }

    return $this->hostResolver->fromSectionStorage($this->layoutTempstoreRepository->get($section_storage)) ?? $host;
  }

  /**
   * Persists a host to layout builder tempstore.
   *
   * Converts the host's entity to an overrides section storage, copies
   * default sections when needed, and stores the result in LB tempstore.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to persist.
   */
  public function persistToLayoutTempstore(HostInterface $host): void {
    $section_storage = $this->getSectionStorageForLbTempstore($host);
    if ($section_storage === NULL) {
      // Fallback: save directly if section storage cannot be obtained.
      $host->entity()->save();
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage, or NULL if unavailable.
   */
  private function getSectionStorageForLbTempstore(HostInterface $host): ?SectionStorageInterface {
    $entity = $host->entity();
    if ($this->sectionStorageHelper !== NULL) {
      return $this->sectionStorageHelper->getEditableSectionStorage($entity);
    }

    // Without daedalus_tempstore there is no defaults->overrides upgrade path.
    // NEVER stage a defaults storage for a CONTENT host: that would stage
    // the SHARED bundle display, and the host's field edits would be lost
    // on load (a defaults storage carries no entity context). Returning
    // NULL makes persistToLayoutTempstore() save the entity directly
    // instead. For a display host the defaults storage IS its own storage.
    $section_storage = $this->getSectionStorageForEntity($entity);
    if ($section_storage instanceof DefaultsSectionStorageInterface && !$entity instanceof LayoutEntityDisplayInterface) {
      return NULL;
    }

    return $section_storage;
  }

}
