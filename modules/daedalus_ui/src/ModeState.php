<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current daedalus_ui mode from the request, and queues the next.
 *
 * A deliberately lightweight service holding only the request-derived mode
 * state. It exists so that request-cheap consumers -- in particular the
 * messenger decorator, which is required by cookie authentication -- can read
 * the mode without depending on the heavy DaedalusUi service. Depending
 * on DaedalusUi from the messenger would pull the full UI (and its plugin
 * managers) onto the authentication-critical service path and create a
 * container circular reference.
 *
 * The same lightness makes it the one door for WRITING the mode from the
 * server. The navigationMode cookie is scoped to the edited page's path
 * (one page's mode never bleeds onto another), so a server-side door that
 * sends the user to a page in a mode cannot set the cookie for the page
 * it is on: it queues the cookie here (queueModeCookie) and the response
 * subscriber emits it (takeQueuedModeCookie, read by
 * InitialMode::setResponseCookies), where the browser stores it for the
 * path and the next navigation there carries it. A created page's
 * redirect, the Add blueprint form's landing and a canvas route's first
 * visit (enterModeOnFirstVisit) all queue through here; nothing else
 * touches the session key.
 */
final class ModeState {

  /**
   * The session key a queued mode cookie waits under until the response.
   */
  private const QUEUED_COOKIE = 'daedalus_ui_set_mode_cookie';

  /**
   * The session key prefix marking a canvas path whose first visit is spent.
   */
  private const ENTERED = 'daedalus_ui_entered_mode:';

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Queues the mode cookie for a path, to ride this request's response.
   *
   * One queued cookie per response: a later queue replaces an earlier one.
   *
   * @param string $path
   *   The page path the cookie is scoped to (the page's URL as the browser
   *   requests it: Url::toString()).
   * @param string $mode
   *   The mode plugin id, 'edit' unless a bundle's initial mode says
   *   otherwise.
   */
  public function queueModeCookie(string $path, string $mode = 'edit'): void {
    $this->requestStack->getCurrentRequest()->getSession()->set(self::QUEUED_COOKIE, [
      'path' => $path,
      'mode' => $mode,
    ]);
  }

  /**
   * Takes the queued mode cookie, if one waits, leaving the queue empty.
   *
   * @return \Symfony\Component\HttpFoundation\Cookie|null
   *   The path-scoped navigationMode cookie to set on the response, or NULL
   *   when nothing was queued.
   */
  public function takeQueuedModeCookie(): ?Cookie {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $params = $session->get(self::QUEUED_COOKIE);
    if (!$params) {
      return NULL;
    }
    $session->remove(self::QUEUED_COOKIE);
    return new Cookie('navigationMode', $params['mode'], 0, $params['path'], NULL, FALSE, FALSE);
  }

  /**
   * Enters a mode on a canvas route's first visit in this session.
   *
   * For a route that is itself the editing surface (a blueprint's
   * canonical page, the frame canvas): the first visit lands
   * in the mode, and only the first, so the user can still leave it
   * afterward. Two halves: mutating the request cookie bag makes THIS
   * response render the mode's chrome (page_top builds after the request
   * listeners and the controller run), and the queued path-scoped
   * Set-Cookie arms the client boot gate and every subsequent request. The
   * request bag mutation alone never reaches the browser.
   *
   * @param string $path
   *   The canvas path the cookie is scoped to.
   * @param string $mode
   *   The mode plugin id.
   */
  public function enterModeOnFirstVisit(string $path, string $mode = 'edit'): void {
    $request = $this->requestStack->getCurrentRequest();
    $session = $request->getSession();
    $flag = self::ENTERED . $path;
    if ($session->get($flag, FALSE)) {
      return;
    }
    $request->cookies->set('navigationMode', $mode);
    $this->queueModeCookie($path, $mode);
    $session->set($flag, TRUE);
  }

  /**
   * Get mode.
   *
   * Many UI state items are stored in JS's Local and Session storage. Edit mode
   * is stored as a cookie so that the server can conditionally render the page
   * elements with attributes used for the editing UI. Rendering this server
   * side prevents a flashing that would occur if we waited till the JS was
   * loaded to enable the editing UI.
   *
   * @param string $cookie_name
   *   The name of the cookie.
   *
   * @return string
   *   Whether edit mode is enabled (Whether the toolbar is open or closed).
   */
  public function getMode(string $cookie_name = 'navigationMode'): string {
    // Query parameters are for js requests like /daedalus-blueprint/node/16
    // (the navigationMode cookie is path-scoped to the edited page, so
    // endpoint requests carry the mode as a query param instead).
    return $this->requestStack->getCurrentRequest()->query->get($cookie_name) ??
    // Cookies are for pages like /node/10.
    $this->requestStack->getCurrentRequest()->cookies->get($cookie_name) ??
      'none';
  }

}
