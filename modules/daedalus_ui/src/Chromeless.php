<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * The chromeless-document predicate: pop-out routes, bare routes, preview.
 *
 * Three kinds of document render without page_top/page_bottom chrome (core
 * navigation's rail and top bar, the toolbar, daedalus_ui's mode bars,
 * sidebars and fabs, and everything those attach). Each keeps something
 * different of the site page, and the three are the whole vocabulary: a
 * route that wants a chromeless document declares one of the two options
 * below or is reached in preview mode, never a chrome story of its own.
 *
 * - A pop-out: a window the editing page opens beside itself (the
 *   responsive preview, daedalus_stylish's inspector satellite). A route
 *   opts in with one option line, and that option is the whole contract:
 *   @code
 *   options:
 *     _daedalus_ui.popout: true
 *   @endcode
 *   A pop-out is a TOOL WINDOW, not a site page: it takes the pop-out
 *   display variant daedalus_ui_popout (selected by PopoutPageVariant:
 *   messages and main content, no block placement, no page title) and
 *   the pop-out document chrome (the daedalus-ui-popout html class and the
 *   daedalus_ui/popout stylesheet, which neutralizes the site theme around
 *   the mounted tool), and the skin does not reach it (daedalus_stylish's
 *   reach gate reads isPopout()). The contract has one input: a pop-out is
 *   opened with ?scope=<edit session scope> (the editing page's
 *   daedalusUi.editSession.scope), which the pop-out document receives as
 *   drupalSettings.daedalusUi.popout.scope (DaedalusUiHooks::pageAttachments)
 *   and pairs every wire of the session on; no pop-out script reads the
 *   URL itself. The option also gives the document the app's keyboard: a
 *   popped-out panel is the same app in another window, so every Edit
 *   Mode shortcut pressed there is sensed by the pop-out (the
 *   daedalus_ui/popout library's keyboard half) and resolved and acted on
 *   by the editing page, never by the pop-out.
 * - A bare site document: a piece of site content rendered alone, with
 *   the site's skin and nothing of the site's page around it (the peek
 *   door of daedalus_blueprint_api, which answers one element of a dry
 *   run in a frame beside the Add panel). A route opts in with:
 *   @code
 *   options:
 *     _daedalus_ui.bare: true
 *   @endcode
 *   A bare document drops the page chrome like the other two, takes
 *   core's simple_page display variant (main content and messages, no
 *   block placement, so none of the theme's regions; PopoutPageVariant
 *   selects it), KEEPS the skin (it is a site page in the skin's eyes:
 *   the reach gate asks isPopout() alone), wears no pop-out class,
 *   stylesheet or keyboard, carries no session identity, and never builds
 *   Edit Mode (ShouldNotEditMode vetoes the mode on it, whatever mode the
 *   request names). It is the document for one element rendered alone:
 *   the host's own style tag applies inside it and the skin's token scope
 *   wraps it, which is what a fragment mounted in the editing document
 *   cannot have.
 * - Preview mode (?navigationMode=preview): the staged page as a visitor
 *   sees it. It IS a site page (theme header and all), just with the editor
 *   gone, so it drops the page_top/page_bottom chrome only and keeps the
 *   theme's block regions, the skin and its native keys.
 *
 * The drop itself lives in DaedalusUiHooks::preprocessHtml, the one seam
 * that runs after every page_top contributor whatever order their hooks
 * ran in: core hands page_top and page_bottom to the html template as
 * unrendered arrays, so nulling them there keeps both markup and
 * attachments out of the document. Emptying page_top from a page_top hook
 * "after everyone" is not a stable seam (several modules claim Order::Last).
 * The editor's own removal in preview mode and on a bare route is the
 * Edit mode's veto (ShouldNotEditMode), so no mode-bound bar, sidebar,
 * bundle or setting is built for either in the first place.
 */
final class Chromeless {

  /**
   * The route option a pop-out route declares.
   */
  public const ROUTE_OPTION = '_daedalus_ui.popout';

  /**
   * The route option a bare site document's route declares.
   */
  public const BARE_ROUTE_OPTION = '_daedalus_ui.bare';

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
   * Is the current route a bare site document?
   */
  public function isBare(): bool {
    return (bool) $this->routeMatch->getRouteObject()?->getOption(self::BARE_ROUTE_OPTION);
  }

  /**
   * Does the current document render without page chrome?
   */
  public function applies(): bool {
    return $this->isPopout() || $this->isBare() || $this->modeState->getMode() === 'preview';
  }

}
