<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current daedalus_ui mode from the request.
 *
 * A deliberately lightweight service holding only the request-derived mode
 * state. It exists so that request-cheap consumers -- in particular the
 * messenger decorator, which is required by cookie authentication -- can read
 * the mode without depending on the heavy DaedalusUi service. Depending
 * on DaedalusUi from the messenger would pull the full UI (and its plugin
 * managers) onto the authentication-critical service path and create a
 * container circular reference.
 */
final class ModeState {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

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
