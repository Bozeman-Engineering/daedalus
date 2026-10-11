<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;

/**
 * The sections a layout store OWNS, read through its host.
 *
 * The inline block machinery (the presave that saves a store's inline
 * blocks, the access verifier that checks a usage row against its host)
 * asks one question of an entity: which sections does this entity hold as
 * its own document? That is the host's ownership door,
 * HostInterface::sectionStorage() over the host's OWN storage: a content
 * host's override field (empty when the page follows its template), a
 * display host's defaults, a blueprint host's own field. A plain host (a
 * fieldable entity whose bundle carries no override field) owns no
 * document, and an entity nothing hosts owns none either.
 *
 * It is NOT core's render rule, LayoutEntityHelperTrait::
 * getSectionStorageForEntity(): findByContext() answers "which storage
 * renders this entity", and for a page whose own document is empty that is
 * the DISPLAY's defaults storage, the template. Core got away with the
 * conflation because a template's inline blocks were saved entities and
 * saving one again is a no-op; a template's value blocks hydrate on any
 * save, so a presave walking the template would mint them as the page's
 * block_content and record usage rows at the page.
 *
 * The host is a fresh object per call (HostResolver::fromEntity() does not
 * memoize) with its seed declined: these readers read the store as it
 * STANDS, and a seed run here would be persisted by the save in progress
 * (the presave) or counted as the page's own authorship (the verifier),
 * the posture HostInterface::declineSeed() names for them.
 *
 * Requires a `$hostResolver` property holding
 * \Drupal\daedalus_blueprint\Host\HostResolver.
 */
trait OwnedSectionsTrait {

  /**
   * The entity's host, reading the store as it stands.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host with its seed declined, or NULL when nothing hosts the
   *   entity.
   */
  protected function ownedHost(EntityInterface $entity): ?HostInterface {
    if (!$this->hostResolver->hosts($entity)) {
      return NULL;
    }
    $host = $this->hostResolver->fromEntity($entity);
    $host->declineSeed();
    return $host;
  }

  /**
   * The entity's own section storage, nested-aware.
   *
   * The host's sectionStorage() already wraps when daedalus_layout is
   * installed (it is, here) and wrap() passes a wrapped storage through;
   * the wrap is stated so the depth-aware list is this reading's own
   * promise, not the host's.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface|null
   *   The wrapped storage, or NULL when the entity owns no document.
   */
  protected function ownedStorage(EntityInterface $entity): ?NestedSectionStorageInterface {
    $storage = $this->ownedHost($entity)?->sectionStorage();
    return $storage === NULL ? NULL : NestedAwareSectionStorage::wrap($storage);
  }

  /**
   * The sections the entity owns, at every depth.
   *
   * Core's blank marker (one layout_builder_blank section) is a section
   * here, as the storage holds it: it carries no inline block, so these
   * readers see the same nothing either way.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The own sections, root and embedded; empty for a store with no
   *   document of its own or an empty one.
   */
  protected function ownedSections(EntityInterface $entity): array {
    return $this->ownedStorage($entity)?->getAllSections() ?? [];
  }

  /**
   * Whether the entity can hold a document of its own.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE for a host with a document door (a content host whose bundle
   *   carries the override field, a display host, a blueprint host); FALSE
   *   for a plain host or an entity nothing hosts.
   */
  protected function ownsDocument(EntityInterface $entity): bool {
    return $this->ownedHost($entity)?->hasDocument() ?? FALSE;
  }

}
