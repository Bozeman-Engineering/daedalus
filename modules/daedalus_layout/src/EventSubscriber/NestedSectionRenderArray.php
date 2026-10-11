<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renders nested-section containers: the section render IS the component.
 *
 * A nested-section container (SectionBlock) is invisible plumbing: a nested
 * section is an ordinary section that happens to live inside a parent
 * section's component list, and its DOM must say exactly that — the layout
 * element itself, carrying the SECTION identity, with no block sandwich and
 * no block-key aliasing. This subscriber owns the whole component build for
 * containers, so it:
 * - runs BEFORE core's BlockComponentRenderArray (priority 100) and stops
 *   propagation: no '#theme' => 'block' wrapper, no block access dance, and
 *   none of the block-component decorations downstream subscribers stamp
 *   (daedalus_ui block attributes/collapse, daedalus_stylish block markers) — a
 *   container is not a block element at any surface;
 * - renders through SectionBlock::buildSection() with the EVENT's preview
 *   flag, so Layout Builder preview shape propagates into the embedded
 *   section's own component builds at every depth (plugin build() cannot
 *   see preview — this seam is why);
 * - stamps the Tier-A section attributes core's display entity stamps for
 *   root sections (LayoutBuilderEntityViewDisplay::getSectionAttributes):
 *   the section selectable key and the local delta the editing JS reads
 *   (emptiness is no LB fact: the element resolver answers it as a root
 *   form and the composed container render prints it). Live
 *   stamping answers
 *   demand against the ROOT entity — the style-map host — which under the
 *   sections-all-the-way-down model is simply the forwarded entity context
 *   at every depth. The stamped section key is load-bearing beyond
 *   selection: it is the @scope root for the section's own CSS and the
 *   donut boundary that stops ancestor section CSS at nested markup.
 */
final class NestedSectionRenderArray implements EventSubscriberInterface {

  /**
   * The render-array key marking a section build as NESTED (depth > 0).
   *
   * The depth context for layout renders: the key rides the section's
   * layout render array itself (daedalus_layout is the only party that knows
   * nesting — a section render carries no depth of its own), where both
   * consumer shapes can read it: a layout template's preprocess sees the
   * whole render array ('layout' is a render-element theme hook), and
   * daedalus_ui's composed_container element reads it at pre_render to
   * pick the depth the layout declaration resolves at (the container's
   * section/div root-form swap). Consumers read the key by its literal
   * string — depending on this class would invert the dependency
   * direction — so treat the value as a cross-module contract: never
   * rename it without updating them.
   */
  public const NESTED_BUILD_KEY = '#daedalus_layout_nested';

  /**
   * Builds the container component's render array.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $plugin = $event->getPlugin();
    if (!$plugin instanceof SectionBlock) {
      return;
    }
    $event->stopPropagation();

    $section = $plugin->getSection();
    $build = $plugin->buildSection($event->inPreview());
    // Every render through this subscriber is an embedded (nested) section;
    // root sections render through buildSections()/the canvas element and
    // never carry the key.
    $build[self::NESTED_BUILD_KEY] = TRUE;
    // Component weight orders the container among its region siblings
    // (core's block builder does the same for block components).
    $build['#weight'] = $event->getComponent()->getWeight();

    // The root entity rides the container's contexts at every depth (the
    // subject when the render names no other root); it is the
    // live-attribution demand host (the style map lives on it) for the
    // section and region keys stamped below.
    $root = $plugin->getContext(NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID);
    $entity = $root->hasContextValue() ? $root->getContextValue() : $plugin->getContext('entity')->getContextValue();
    LayoutBuilderEntityViewDisplay::getSectionAttributes(
      0,
      $build,
      NULL,
      $section,
      $entity instanceof EntityInterface ? $entity : NULL,
    );

    $cacheability = CacheableMetadata::createFromRenderArray($build);
    // Key stamping varies on the editing permission.
    $cacheability->addCacheContexts(['user.permissions']);
    $event->addCacheableDependency($cacheability);
    $event->setBuild($build);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // BEFORE core's builder (100), whose block sandwich a container must
    // never enter; daedalus_layout's root-entity registration (150) still runs
    // first and is a no-op for containers.
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = ['onBuildRender', 110];
    return $events;
  }

}
