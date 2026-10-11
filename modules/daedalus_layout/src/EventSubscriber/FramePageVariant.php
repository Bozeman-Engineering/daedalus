<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_layout\Plugin\DisplayVariant\DaedalusPageVariant;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Selects the site frame's page display variant for a theme that has one.
 *
 * The frame replaces the block module's page variant and nothing else: a
 * route the block module would have decorated with block layout is
 * rendered as its theme's frame instead when a daedalus_frame config
 * entity exists for the active theme. A theme without a frame (the admin
 * theme, which ships block config) keeps the block module's variant, and
 * a route another subscriber already took over (daedalus_ui's pop-outs
 * and bare documents, which are chromeless on purpose) keeps its variant.
 * Administration routes are never framed (Canvas's rule, the Drupal CMS
 * precedent: the admin theme stays on block layout), so the subscriber
 * runs after the block module's (priority -100) and returns early for
 * them; so is the one canvas route where the frame itself is the page's
 * content, the frame's own canvas (FrameCanvas): framed, it would render
 * twice. A blueprint's canonical route, its canvas (BlueprintCanvasMode),
 * IS framed, like every other front-end route: the route output carries
 * the blueprint as the route's host, and the frame door whose default is
 * that blueprint hosts the output (InnerRender::routeSlot()) while the
 * main slot prints the stand-in. The chrome's canvas thereby renders in
 * situ, in the door that shows the blueprint by reference on every page
 * of the theme, with the frame around it as the context it is edited for,
 * and the blueprint shows once (the door hosts the canvas instead of
 * showing the default beside it). A blueprint no door defaults to renders
 * framed in main, as a page does.
 *
 * The route's entity, when the route has one, is handed to the variant as
 * the `entity` context through the event (core's channel for a
 * context-aware variant), resolved by daedalus_ui's one route derivation
 * so the frame answers the same route grammar as every other consumer;
 * on a blueprint's canvas it is the blueprint entity, the route host's
 * own. The entity object itself travels, never an id: a revision route or
 * a preview shows an entity a reload would not find.
 */
final class FramePageVariant implements EventSubscriberInterface {

  /**
   * The block module's page variant: the one the frame replaces.
   */
  public const BLOCK_VARIANT = 'block_page';

  public function __construct(
    protected ThemeManagerInterface $themeManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AdminContext $adminContext,
    protected DaedalusUi $daedalusUi,
  ) {}

  /**
   * Selects the frame variant for a framed theme on a front-end route.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    if ($event->getPluginId() !== self::BLOCK_VARIANT) {
      return;
    }
    $route_match = $event->getRouteMatch();
    if ($this->adminContext->isAdminRoute($route_match->getRouteObject())) {
      return;
    }
    $host = $this->daedalusUi->deriveHostFromRoute($route_match);
    if ($host instanceof FrameHost) {
      // The frame canvas (the frame's own route): the frame is the page's
      // content there, rendered by the canvas element in the editing
      // posture, never its frame too.
      return;
    }
    $theme = $this->themeManager->getActiveTheme()->getName();
    $storage = $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID);
    // The choice varies by theme and follows the frames that exist.
    $event->addCacheContexts(['theme'])
      ->addCacheTags($this->entityTypeManager->getDefinition(FrameInterface::ENTITY_TYPE_ID)->getListCacheTags());
    $frame = $storage->load($theme);
    if (!$frame instanceof FrameInterface) {
      return;
    }
    $event->setPluginId(DaedalusPageVariant::ID)
      ->setPluginConfiguration([DaedalusPageVariant::FRAME_KEY => $theme])
      ->addCacheableDependency($frame);
    $subject = $host?->fields();
    if ($subject !== NULL) {
      $event->setContexts(['entity' => EntityContext::fromEntity($subject)] + $event->getContexts());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RenderEvents::SELECT_PAGE_DISPLAY_VARIANT => ['onSelectPageDisplayVariant', -100],
    ];
  }

}
