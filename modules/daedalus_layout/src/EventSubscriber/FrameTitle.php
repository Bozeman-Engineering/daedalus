<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives a title block placed in the site frame the route's title.
 *
 * Core's contract for the block that shows a page's title
 * (TitleBlockPluginInterface::setTitle()) is honoured by the page display
 * variant that renders the block: the block module's variant sets it on
 * each placed block entity's plugin before viewing it. Inside a Layout
 * Builder document the plugin instance that builds is created by the
 * component build event, so this is the seam: when the render carries the
 * route's output (the frame render's RouteOutput context) and the
 * component is a title block, the title is set before any builder runs.
 * Outside a frame render (a title block placed in a template or a page,
 * where core's Layout Builder filter never offers it) nothing happens.
 */
final class FrameTitle implements EventSubscriberInterface {

  /**
   * Sets the route's title on a title block of the frame.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $plugin = $event->getPlugin();
    if (!$plugin instanceof TitleBlockPluginInterface) {
      return;
    }
    $context = $event->getContexts()[RouteOutput::CONTEXT_ID] ?? NULL;
    if ($context === NULL || !$context->hasContextValue()) {
      return;
    }
    $output = $context->getContextValue();
    if ($output instanceof RouteOutput) {
      $plugin->setTitle($output->title);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before every builder: daedalus_layout's root registration (150), the
    // container render (110), core's block builder (100).
    return [
      LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY => ['onBuildRender', 200],
    ];
  }

}
