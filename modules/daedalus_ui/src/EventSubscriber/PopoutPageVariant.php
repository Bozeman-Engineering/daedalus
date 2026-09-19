<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Renders pop-out windows without block chrome.
 *
 * A pop-out is a window the editing page opens beside itself (the
 * responsive preview, daedalus_stylish's inspector). It should read as a panel or a
 * preview surface, not a page, so the simple_page variant skips block
 * placement entirely (no header, menus, or footer regions). A route opts in
 * with one option line:
 *
 * @code
 * options:
 *   _daedalus_ui.popout: true
 * @endcode
 *
 * Only block chrome is handled here; page_top chrome renders regardless of
 * the variant and is a route's own concern (see daedalus_stylish's PopoutHooks).
 */
final class PopoutPageVariant implements EventSubscriberInterface {

  /**
   * The route option a pop-out route declares.
   */
  public const ROUTE_OPTION = '_daedalus_ui.popout';

  /**
   * Selects the simple_page variant for routes declared as pop-outs.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    if ($event->getRouteMatch()->getRouteObject()?->getOption(self::ROUTE_OPTION)) {
      $event->setPluginId('simple_page');
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
