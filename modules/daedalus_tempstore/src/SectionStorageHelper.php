<?php

namespace Drupal\daedalus_tempstore;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionListInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Resolves the section storage staged edits address, for any entity.
 *
 * The storage spine's one defaults->overrides upgrade seam. A
 * not-yet-overridden entity (its layout field is empty) resolves through
 * core's findByContext() to the shared bundle display's defaults storage.
 * Editing that from an entity page would edit the SHARED display for every
 * entity of the bundle, so this helper upgrades it to the entity's own
 * overrides storage. The editable storage is the host's own, which seeds
 * the first override from the display's template at its storage door
 * (DocumentInstantiator::seed(): fresh identity, re-keyed styles, in
 * memory); resolving the storage's identity alone (a tempstore key) seeds
 * nothing. Nothing is persisted here: the caller decides to stage or save.
 *
 * Consumers: daedalus_layout's render path (DaedalusLayoutEntityHelperTrait), the tempstore
 * write path (LayoutTempstoreStrategy), and daedalus_blueprint's Layout
 * Builder persistence (LayoutTempstoreHelper). Keep them delegating here;
 * a diverging copy of this logic makes the published UI storage and the
 * tempstore keys disagree.
 */
class SectionStorageHelper {

  public function __construct(
    protected SectionStorageManagerInterface $sectionStorageManager,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * Whether a config entity IS its own section list and stages as it.
   *
   * A Layout Builder enabled display (the template host) and a site frame
   * (the frame host) hold their document as config and stage as their own
   * section storage, so the staged layout, Save, Discard and History share
   * one key; a display without Layout Builder is plain config. The one
   * answer both tempstore strategies ask, so the layout strategy claims
   * exactly what the entity strategy declines.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE when the entity stages as its own section storage.
   */
  public static function isSectionListConfig(EntityInterface $entity): bool {
    if ($entity instanceof LayoutEntityDisplayInterface) {
      return $entity->isLayoutBuilderEnabled();
    }
    return $entity instanceof ConfigEntityInterface && $entity instanceof SectionListInterface;
  }

  /**
   * Resolves the section storage of an entity, without seeding it.
   *
   * The storage's identity (its type, its tempstore key) for any subject:
   * a display's defaults storage, a site frame's frame storage (resolved
   * by its entity context alone), an overridable entity's overrides storage
   * (upgraded from the defaults core resolves for an empty field), a
   * non-overridable bundle's defaults storage. The view mode context is the
   * resolved render display's mode (the host's one display resolution
   * rule), because overrides tempstore keys embed it and every path must
   * agree on the value.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, or an entity view display.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The storage, or NULL if the entity does not support Layout Builder.
   */
  public function resolveSectionStorage(EntityInterface $entity): ?SectionStorageInterface {
    $contexts = [];
    if ($entity instanceof LayoutEntityDisplayInterface) {
      $contexts['display'] = EntityContext::fromEntity($entity);
      $contexts['view_mode'] = new Context(new ContextDefinition('string'), $entity->getMode());
      return $this->sectionStorageManager->findByContext($contexts, new CacheableMetadata());
    }

    $contexts['entity'] = EntityContext::fromEntity($entity);
    if ($entity instanceof FieldableEntityInterface) {
      $display = EntityViewDisplay::collectRenderDisplay($entity, 'full');
      if ($display instanceof LayoutEntityDisplayInterface) {
        $contexts['display'] = EntityContext::fromEntity($display);
      }
      $contexts['view_mode'] = new Context(new ContextDefinition('string'), $display->getMode());
    }

    $section_storage = $this->sectionStorageManager->findByContext($contexts, new CacheableMetadata());

    if ($section_storage instanceof DefaultsSectionStorageInterface
      && $entity instanceof FieldableEntityInterface
      && $entity->hasField(OverridesSectionStorage::FIELD_NAME)
    ) {
      // The overrides storage wants only entity + view_mode contexts.
      unset($contexts['display']);
      return $this->sectionStorageManager->load('overrides', $contexts);
    }

    return $section_storage;
  }

  /**
   * Gets the editable section storage for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, or an entity view display (when editing the display
   *   itself, the defaults storage IS the editable storage).
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The editable section storage, or NULL if the entity does not support
   *   Layout Builder. An overridable entity answers its host's own storage,
   *   seeded from the display's template when it was empty. A defaults
   *   storage is only returned for display entities and for bundles without
   *   overrides enabled (no layout field on the entity); callers rightly
   *   reject those for entity-page editing.
   */
  public function getEditableSectionStorage(EntityInterface $entity): ?SectionStorageInterface {
    $section_storage = $this->resolveSectionStorage($entity);
    if (!$section_storage instanceof OverridesSectionStorage || !$entity instanceof FieldableEntityInterface) {
      return $section_storage;
    }
    $storage = $this->hostResolver->fromEntity($entity)->sectionStorage();
    if ($storage instanceof NestedSectionStorageInterface) {
      return $storage->getRootStorage();
    }
    return $storage;
  }

}
