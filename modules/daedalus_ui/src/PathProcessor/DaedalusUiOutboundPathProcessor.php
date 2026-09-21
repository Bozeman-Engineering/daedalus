<?php

namespace Drupal\daedalus_ui\PathProcessor;

use Drupal\Core\Url;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;

/**
 * Processes outbound paths for daedalus_ui module.
 */
class DaedalusUiOutboundPathProcessor implements OutboundPathProcessorInterface {

  /**
   * The route path prefix of daedalus_ui.load_editable_page.
   *
   * This processor runs at priority 350, before path_alias and language add
   * their outbound rewrites, so the path it sees is the route's own pattern
   * with its parameters filled in.
   */
  public const LOAD_EDITABLE_PAGE_PREFIX = '/daedalus-ui/load-editable-page/';

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
    // mode-plugin-base.js adds several query parameters like
    // _wrapper_format=drupal_ajax. There are times that path gets stashed and
    // then used as a destination in a non-AJAX response. For example. A user in
    // the Live workspace viewing the canonical route of a page. If you click
    // Edit Mode it makes an AJAX call to LoadEditablePage to load the editing
    // UI. Workspaces jumps in and gives us the Workspace Switcher form. The
    // Edit Mode page request then becomes the destination after the form is
    // submitted, but there is no longer JS waiting in the browser for an ajax
    // response.
    if (!empty($options['query']['_wrapper_format']) && $options['query']['_wrapper_format'] === 'drupal_ajax' && !empty($options['query']['navigationMode'])) {
      unset($options['query']['_wrapper_format']);
    }

    // Catch anything like the workspace switcher trying to go to
    // LoadEditablePage directly. Let it go to EntityViewAlter as a normal page
    // load. The string check gates the router: this processor runs for EVERY
    // outbound URL, and matching a request here costs a full route match with
    // param conversion per generated URL. Worse, that conversion runs the
    // daedalus_tempstore entity converter, whose entity comparison evaluates
    // computed fields; a computed field that itself generates a URL (metatag's
    // [node:url]) then re-enters this processor, and the render recursed until
    // PHP ran out of memory on any edit-mode page of a site with metatag.
    if (!str_starts_with($path, self::LOAD_EDITABLE_PAGE_PREFIX)) {
      return $path;
    }
    $url = Url::fromUri("internal:$path");
    if ($url->isRouted() && $url->getRouteName() === 'daedalus_ui.load_editable_page') {
      // The router cannot be injected here: url_generator collects the
      // outbound processors and the router depends on url_generator, so a
      // constructor argument would be a circular reference.
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $route_match = \Drupal::service('router')->matchRequest(Request::create($path));
      $entity = $route_match['entity'];
      $path = $entity->toUrl()->toString();
      unset($options['query']['navigationMode']);
    }

    return $path;
  }

}
