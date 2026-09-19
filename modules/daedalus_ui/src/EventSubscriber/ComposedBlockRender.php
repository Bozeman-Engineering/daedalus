<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renders opted-in blocks as their composed element tree — no sandwich.
 *
 * A Layout Builder block normally renders as a sandwich: the theme's block
 * template wraps the block plugin's content, and every editing attribute
 * (the selectable key, the component uuid, the presentation) lands on that
 * wrapper. For blocks whose content is one composed element (a text
 * block's single tag), the wrapper chain is pure noise: it blocks
 * region-level layout (align-items on the region reaches only the wrapper)
 * and gives styling nothing addressable.
 *
 * Block types opt in with `composed: true` in their
 * PROVIDER.page_builder.block_type.BUNDLE.yml declaration, which makes the
 * declaration GENERATIVE: the declared markup model IS the render (the
 * composed_block element consumes the element resolver; see
 * ComposedBlockRenderer), the block renders with no sandwich at all — the
 * composed root is the block, on the live page and in edit mode alike —
 * and the block build's stamped attributes hoist onto that root. There is
 * no template contract to verify: the renderer that receives the bag is
 * the renderer that prints it.
 *
 * A composed block gives up what only the sandwich provided: the block
 * label never renders, contextual links are not printed, and the edit-mode
 * label field wrapper has nowhere to live. Blocks that need any of those
 * should not compose.
 *
 * The swap happens at render time (#pre_render), not at the event: daedalus_layout
 * stamps its edit-chrome classes on the build after the event returns, so
 * only at render is the stamped attribute set complete.
 */
final class ComposedBlockRender implements EventSubscriberInterface, TrustedCallbackInterface {

  /**
   * Stamps only the sandwich-era consumers read; dropped on compose.
   *
   * Data-block-type and data-layout-builder-region have no runtime
   * consumers, and data-layout-builder-highlight-id feeds core's
   * layout-builder.js, which daedalus_layout never attaches.
   */
  private const DROPPED_ATTRIBUTES = [
    'data-block-type',
    'data-layout-builder-region',
    'data-layout-builder-highlight-id',
  ];

  /**
   * Classes only core's never-attached layout-builder.js/css consume.
   */
  private const DROPPED_CLASSES = [
    'js-layout-builder-block',
    'layout-builder-block',
  ];

  public function __construct(
    protected ElementModel $elementModel,
  ) {}

  /**
   * Marks opted-in block builds for the render-time swap.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $build = $event->getBuild();
    if (!isset($build['content'])) {
      return;
    }
    if (!$this->elementModel->isComposed($event->getPlugin()->getPluginId())) {
      return;
    }
    $build['#pre_render'][] = [static::class, 'preRenderComposed'];
    $event->setBuild($build);
  }

  /**
   * Replaces the block sandwich with the composed render element.
   *
   * Runs when the component build renders — after daedalus_layout has stamped its
   * post-event classes — so the hoisted bag is the complete final
   * attribute set. Unsetting #theme kills the block template (wrapper,
   * block__content, label, title_prefix) in one move; the entity view
   * builder's stub is replaced wholesale (composed bundles render no
   * fields through their display), keeping its cache metadata minus the
   * render-cache keys, which addressed a build that no longer happens.
   */
  public static function preRenderComposed(array $build): array {
    if (($build['#theme'] ?? NULL) !== 'block') {
      // Another module replaced the build shape; nothing safe to swap.
      return $build;
    }
    $entity = $build['content']['#block_content'] ?? NULL;
    if (!$entity instanceof BlockContentInterface) {
      return $build;
    }
    $attributes = $build['#attributes'] ?? [];
    foreach (self::DROPPED_ATTRIBUTES as $name) {
      unset($attributes[$name]);
    }
    if (!empty($attributes['class'])) {
      $attributes['class'] = array_values(array_diff($attributes['class'], self::DROPPED_CLASSES));
      if (!$attributes['class']) {
        unset($attributes['class']);
      }
    }
    $cache = $build['content']['#cache'] ?? [];
    unset($cache['keys'], $cache['bin']);
    unset($build['#theme'], $build['#attributes'], $build['#contextual_links']);
    $build['content'] = [
      '#type' => 'composed_block',
      '#block_content' => $entity,
      '#hoisted_attributes' => $attributes,
      '#cache' => $cache,
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderComposed'];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After LayoutBuilderBlockAttributes (-100): the bag rides the fully
    // attributed build.
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = ['onBuildRender', -200];
    return $events;
  }

}
