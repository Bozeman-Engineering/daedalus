<?php

namespace Drupal\daedalus_tempstore;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Resolves the EDITABLE section storage for an entity.
 *
 * The storage spine's one defaults->overrides upgrade seam. A
 * not-yet-overridden entity (its layout field is empty) resolves through
 * core's findByContext() to the shared bundle display's defaults storage.
 * Editing that from an entity page would edit the SHARED display for every
 * entity of the bundle, so this helper upgrades it to the entity's own
 * overrides storage, seeded with the defaults' sections. Nothing is
 * persisted here: the caller decides to stage or save.
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
  ) {}

  /**
   * Gets the editable section storage for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, or an entity view display (when editing the display
   *   itself, the defaults storage IS the editable storage).
   * @param string $view_mode
   *   The requested view mode. The actually used display mode is resolved
   *   from it (e.g. 'full' falls back to 'default' when no full display
   *   exists) and used for the storage contexts, because overrides
   *   tempstore keys embed the view mode: every path must agree on the
   *   resolved value or the UI and the tempstore talk about different
   *   drafts.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The editable section storage, or NULL if the entity does not support
   *   Layout Builder. A defaults storage is only returned for display
   *   entities and for bundles without overrides enabled (no layout field
   *   on the entity); callers rightly reject those for entity-page editing.
   */
  public function getEditableSectionStorage(EntityInterface $entity, string $view_mode = 'full'): ?SectionStorageInterface {
    $contexts = [];
    if ($entity instanceof LayoutEntityDisplayInterface) {
      $contexts['display'] = EntityContext::fromEntity($entity);
      $contexts['view_mode'] = new Context(new ContextDefinition('string'), $entity->getMode());
      return $this->sectionStorageManager->findByContext($contexts, new CacheableMetadata());
    }

    $contexts['entity'] = EntityContext::fromEntity($entity);
    if ($entity instanceof FieldableEntityInterface) {
      $display = EntityViewDisplay::collectRenderDisplay($entity, $view_mode);
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
      $overrides = $this->sectionStorageManager->load('overrides', $contexts);
      if ($overrides === NULL) {
        return NULL;
      }
      if (empty($overrides->getSections())) {
        foreach ($section_storage->getSections() as $section) {
          $overrides->appendSection($section);
        }
      }
      return $overrides;
    }

    return $section_storage;
  }

}
