<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Declares the form endpoint kind on Layout Builder inline blocks.
 *
 * The form path has two endpoint kinds: the entity form (the default,
 * MultipleEntityFormController) and the Layout Builder block form
 * (BlockFormController), which edits a placement's block_content through
 * UpdateBlockFormForEditTool. Which kind a leaf fetches is the
 * placement's fact, so the placement declares it in its attribution:
 * data-daedalus-edit-form-endpoint="block-form" rides the block wrapper (for
 * composed blocks the hoisted-attribute bag carries it onto the composed
 * root), and entity-form.js resolves a leaf's endpoint from its closest
 * declaring ancestor, defaulting to the entity form.
 *
 * Only inline blocks declare the block-form kind: the block form's edit
 * target is the placement's block_content
 * (NestedSectionStorage::getBlockContentFromPlugin), which only inline
 * block plugins carry. A field block's leaves belong to the host entity
 * and ride the entity form default, as does everything rendered outside
 * Layout Builder.
 *
 * Registered by DaedalusEditServiceProvider only when daedalus_layout is installed,
 * alongside the endpoint's route (DaedalusLayoutRouteSubscriber).
 */
final class BlockFormEndpoint implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * On section component build render array.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $build = $event->getBuild();
    $build['#cache']['contexts'][] = 'user.permissions';
    // Components that rendered empty are a cache-metadata-only stub:
    // stamping an attribute would make Element::isEmpty() report the stub
    // as non-empty (see LayoutBuilderBlockAttributes for the fallout).
    if (Element::isEmpty($build['content'] ?? [])) {
      $event->setBuild($build);
      return;
    }
    if ($this->currentUser->hasPermission('access inline editing')
      && str_starts_with($event->getPlugin()->getPluginId(), 'inline_block:')) {
      $build['#attributes']['data-daedalus-edit-form-endpoint'] = 'block-form';
    }
    $event->setBuild($build);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After daedalus_ui's LayoutBuilderBlockAttributes (-100), with the
    // other attribution stampers; ComposedBlockRender's render-time swap
    // (-200) hoists the final attribute set either way.
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = ['onBuildRender', -150];
    return $events;
  }

}
