<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Controller;

use Drupal\Component\Utility\UrlHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * Applies a JSON body's ajax_page_state to the request.
 *
 * The attachments processor diffs attached libraries against the page state
 * the client sends, mirroring what drupal.ajax POSTs as form data.
 * drupalSettings.ajaxPageState carries `libraries` compressed; core's
 * AjaxPageState middleware only decompresses form/query parameters, so a
 * JSON body must decompress here or the diff matches nothing and every
 * response re-ships all core scripts — re-executing drupal.js, which resets
 * window.Drupal and destroys the client app mid-session.
 */
trait AjaxPageStateRequestTrait {

  /**
   * Moves a decoded body's ajax_page_state onto the request attributes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param array $body
   *   The decoded JSON request body.
   */
  protected function applyAjaxPageState(Request $request, array $body): void {
    if (is_array($body['ajax_page_state'] ?? NULL)) {
      $ajax_page_state = $body['ajax_page_state'];
      if (is_string($ajax_page_state['libraries'] ?? NULL)) {
        $ajax_page_state['libraries'] = UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']);
      }
      $request->attributes->set('ajax_page_state', $ajax_page_state);
    }
  }

}
