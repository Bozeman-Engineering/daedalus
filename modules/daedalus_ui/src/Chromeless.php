<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * The chromeless-document predicate: pop-out routes and preview mode.
 *
 * Two kinds of document render without page_top/page_bottom chrome (core
 * navigation's rail and top bar, the toolbar, daedalus_ui's mode bars,
 * sidebars and fabs, and everything those attach):
 *
 * - A pop-out: a window the editing page opens beside itself (the
 *   responsive preview, daedalus_stylish's inspector satellite). A route
 *   opts in with one option line, and that option is the whole contract;
 *   no pop-out carries its own chrome story:
 *   @code
 *   options:
 *     _daedalus_ui.popout: true
 *   @endcode
 *   The contract has one input: a pop-out is opened with
 *   ?scope=<edit session scope> (the editing page's
 *   daedalusUi.editSession.scope), which the pop-out document receives as
 *   drupalSettings.daedalusUi.popout.scope (DaedalusUiHooks::pageAttachments)
 *   and pairs every wire of the session on; no pop-out script reads the
 *   URL itself. The option also gives the document the app's keyboard: a
 *   popped-out panel is the same app in another window, so every Edit
 *   Mode shortcut pressed there is sensed by the pop-out (the
 *   daedalus_ui/popout library's keyboard half) and resolved and acted on
 *   by the editing page, never by the pop-out.
 * - Preview mode (?navigationMode=preview): the staged page as a visitor
 *   sees it. It IS a site page (theme header and all), just with the editor
 *   gone, so it drops the page_top/page_bottom chrome only; a pop-out also
 *   takes the pop-out display variant daedalus_ui_popout (selected by
 *   PopoutPageVariant: messages and main content, no block placement, no
 *   page title) and the pop-out document chrome (the daedalus-ui-popout
 *   html class and the daedalus_ui/popout stylesheet, which neutralizes
 *   the site theme around the mounted tool).
 *
 * The drop itself lives in DaedalusUiHooks::preprocessHtml, the one seam
 * that runs after every page_top contributor whatever order their hooks
 * ran in: core hands page_top and page_bottom to the html template as
 * unrendered arrays, so nulling them there keeps both markup and
 * attachments out of the document. Emptying page_top from a page_top hook
 * "after everyone" is not a stable seam (several modules claim Order::Last).
 * The editor's own removal in preview mode is the Edit mode's veto
 * (ShouldNotEditMode), so no mode-bound bar, sidebar, bundle or setting
 * is built for it in the first place.
 */
final class Chromeless {

  /**
   * The route option a pop-out route declares.
   */
  public const ROUTE_OPTION = '_daedalus_ui.popout';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ModeState $modeState,
  ) {}

  /**
   * Is the current route a pop-out?
   */
  public function isPopout(): bool {
    return (bool) $this->routeMatch->getRouteObject()?->getOption(self::ROUTE_OPTION);
  }

  /**
   * Does the current document render without page chrome?
   */
  public function applies(): bool {
    return $this->isPopout() || $this->modeState->getMode() === 'preview';
  }

}
