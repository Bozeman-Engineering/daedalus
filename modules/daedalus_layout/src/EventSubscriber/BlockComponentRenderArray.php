<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\RootEntityRegistry;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;

/**
 * Block component render array.
 */
class BlockComponentRenderArray implements EventSubscriberInterface {

  public function __construct(
    protected RootEntityRegistry $rootEntityRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = [
      // BEFORE core's subscriber (priority 100): BlockContentViewBuilder
      // builds eagerly, so the nested render happens inside core's
      // $block->build() call — the root must be registered by then.
      ['registerRootEntity', 150],
    ];
    return $events;
  }

  /**
   * Records the root entity an inline block's content renders under.
   *
   * Registered by UUID before the component builds, so when the eager
   * nested render reaches getContextsForEntity() the root is resolvable
   * (see RootEntityRegistry). The root comes from the host render's
   * contexts: the propagated root when the host is itself nested,
   * otherwise the host entity.
   *
   * EVERY inline block registers: live-attribution demand (daedalus_stylish's
   * provider) resolves the style-map host for any inline block's content;
   * a plain text block's field/label keys are unanswerable without its
   * root.
   *
   * @param \Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent $event
   *   The section component render event.
   */
  public function registerRootEntity(SectionComponentBuildRenderArrayEvent $event): void {
    $block = $event->getPlugin();
    if (!$block instanceof BlockPluginInterface) {
      return;
    }
    $block_content = NestedAwareSectionStorage::extractBlockContent($block);
    if ($block_content === NULL) {
      return;
    }
    $contexts = $event->getContexts();
    foreach ([NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID, 'layout_builder.entity', 'entity'] as $id) {
      if (isset($contexts[$id]) && $contexts[$id]->hasContextValue()) {
        $root = $contexts[$id]->getContextValue();
        if ($root instanceof EntityInterface) {
          $this->rootEntityRegistry->setRoot($block_content->uuid(), $root);
          return;
        }
      }
    }
  }

}
