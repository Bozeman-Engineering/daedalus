<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\daedalus_ui\Chromeless;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renders pop-out windows as pop-out documents.
 *
 * A pop-out is a window the editing page opens beside itself (the
 * responsive preview, daedalus_stylish's inspector). It should read as a
 * panel or a preview surface, not a page, so the daedalus_ui_popout display
 * variant (PopoutDocumentVariant) renders only status messages and the main
 * content: no block placement (no header, menus, or footer regions) and no
 * page title, since a pop-out renders its own heading. A route opts in with
 * the Chromeless::ROUTE_OPTION option line (see Chromeless).
 *
 * Only the variant is handled here: page_top/page_bottom chrome renders
 * regardless of the variant and is dropped for every chromeless document
 * (pop-outs and preview mode alike) in DaedalusUiHooks::preprocessHtml.
 */
final class PopoutPageVariant implements EventSubscriberInterface {

  /**
   * The pop-out display variant's plugin id.
   */
  public const VARIANT = 'daedalus_ui_popout';

  /**
   * Selects the pop-out display variant for routes declared as pop-outs.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    if ($event->getRouteMatch()->getRouteObject()?->getOption(Chromeless::ROUTE_OPTION)) {
      $event->setPluginId(self::VARIANT);
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
