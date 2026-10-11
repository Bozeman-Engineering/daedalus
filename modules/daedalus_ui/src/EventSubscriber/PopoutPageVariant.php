<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\daedalus_ui\Chromeless;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Selects the display variant of a pop-out and of a bare site document.
 *
 * A pop-out is a window the editing page opens beside itself (the
 * responsive preview, daedalus_stylish's inspector). It should read as a
 * panel or a preview surface, not a page, so the daedalus_ui_popout display
 * variant (PopoutDocumentVariant) renders only status messages and the main
 * content: no block placement (no header, menus, or footer regions) and no
 * page title, since a pop-out renders its own heading. A route opts in with
 * the Chromeless::ROUTE_OPTION option line (see Chromeless).
 *
 * A bare site document (Chromeless::BARE_ROUTE_OPTION) is one piece of
 * site content rendered alone in the skin: it takes core's own simple_page
 * variant, which is "messages and the main content, no block placement",
 * so none of the theme's regions render around the content, and the
 * route's title (when it declares one) stays core's page title.
 *
 * Only the variant is handled here: page_top/page_bottom chrome renders
 * regardless of the variant and is dropped for every chromeless document
 * (pop-outs, bare documents and preview mode alike) in
 * DaedalusUiHooks::preprocessHtml.
 */
final class PopoutPageVariant implements EventSubscriberInterface {

  /**
   * The pop-out display variant's plugin id.
   */
  public const VARIANT = 'daedalus_ui_popout';

  /**
   * The bare site document's display variant: core's simple page.
   */
  public const BARE_VARIANT = 'simple_page';

  /**
   * Selects the variant for routes declared as pop-outs or bare documents.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $route = $event->getRouteMatch()->getRouteObject();
    if ($route?->getOption(Chromeless::ROUTE_OPTION)) {
      $event->setPluginId(self::VARIANT);
    }
    elseif ($route?->getOption(Chromeless::BARE_ROUTE_OPTION)) {
      $event->setPluginId(self::BARE_VARIANT);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RenderEvents::SELECT_PAGE_DISPLAY_VARIANT => 'onSelectPageDisplayVariant',
    ];
  }

}
