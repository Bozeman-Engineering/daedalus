<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\CommitRefusedException;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The top bar's Save: an adapter over the storage handler's commit door.
 *
 * Staging never saves; this route is the one commit of an edited host.
 * The host the route names (a content entity, or a Layout Builder display
 * whose template is staged) is loaded as its working copy (the staged
 * copy while a stage exists, the stored row otherwise) and handed to
 * EntityStorageHandler::commit(), which applies the host's persistence
 * policy, saves the working copy as it is, clears the stage and fires the
 * persisted hook (daedalus_edit's implementer invalidates the render tag).
 * Nothing here judges or copies fields: the spine judged every write on the
 * way in, so what the stage holds is what the database gets, computed and
 * key fields included (the URL alias, the published flag, the owner).
 *
 * The route takes a POST carrying the session token and answers JSON, read
 * by the top bar's script (daedalus_ui's save-commit.js). A commit answers
 * 200 `{destination}`: where the browser goes next. A commit the door
 * refuses (CommitRefusedException: the page was saved elsewhere, the whole
 * entity does not validate, storage failed) answers `{reason, messages}`
 * with a 409, 422 or 500: nothing was saved, the stage is as it was, and
 * the editor stays in Edit Mode reading the messages.
 *
 * The destination and the cookie are two facts, not one. The destination is
 * the page the client named (`destination`): the edited page itself, or the
 * page a portal's leave came from (Drupal.Daedalus.Portal.leave puts it on
 * the canvas URL, and the top bar passes it on), so saving a header or a
 * footer returns the editor to the page they were on. The client's text
 * only selects a route; the answer is a URL generated here (destination()),
 * and a destination that names no route answers the saved host's own page. The
 * `navigationMode=none` cookie that drops Edit Mode is scoped to the SAVED
 * host's editing path (ModeState: the cookie is scoped to the edited page's
 * path), never to the destination: the saved entity is what left Edit Mode;
 * the page the editor returns to keeps whatever mode it was in. A refusal
 * sets no cookie.
 */
class Tempstore extends ControllerBase {

  /**
   * Constructs a Tempstore controller.
   *
   * @param \Drupal\daedalus_blueprint\EntityStorageHandler $storageHandler
   *   The storage handler: the working copy's loader and its commit door.
   * @param \Drupal\daedalus_ui\DaedalusUi $daedalusUi
   *   The editable UI: the one reader of a host's editing path.
   */
  public function __construct(
    protected EntityStorageHandler $storageHandler,
    protected DaedalusUi $daedalusUi,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_ui.ui'),
    );
  }

  /**
   * The status a refusal answers with, by its reason.
   */
  private const REFUSAL_STATUS = [
    CommitRefusedException::MOVED => 409,
    CommitRefusedException::INVALID => 422,
    CommitRefusedException::STORAGE => 500,
  ];

  /**
   * Commits the route's host and answers where to go, or why it did not.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The host's entity type id.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `{destination}` with the cookie that takes the saved host out of Edit
   *   Mode, or `{reason, messages}` when the commit door refused.
   */
  public function save(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $host = $this->storageHandler->load($entity_type, $entity->id());
    if ($host === NULL) {
      throw new NotFoundHttpException(sprintf('No %s %s to save.', $entity_type, $entity->id()));
    }
    try {
      $this->storageHandler->commit($host);
    }
    catch (CommitRefusedException $refusal) {
      return new JsonResponse(
        ['reason' => $refusal->reason(), 'messages' => $refusal->messages()],
        self::REFUSAL_STATUS[$refusal->reason()],
      );
    }
    $saved = $host->entity();

    $response = new JsonResponse(['destination' => $this->destination($request, $saved)->toString()]);
    // The saved host leaves Edit Mode; the cookie is scoped to ITS editing
    // path (a node's canonical URL under its fresh alias, a block_content's
    // standalone canvas), whatever page the browser goes to next.
    $edited_path = $this->daedalusUi->getEditingUrl($saved)->toString();
    $response->headers->setCookie((new Cookie('navigationMode', 'none', 0, $edited_path, NULL, FALSE, FALSE)));
    return $response;
  }

  /**
   * Where the browser goes after the commit: always a URL built here.
   *
   * The client names a page (`destination`): the edited page's own path, or
   * the page a portal's leave came from. That text only ever selects a
   * route; the answer is generated from the route it resolves to, and no
   * character of the request reaches it unparsed. The destination is read
   * beneath the base path (the client sends a browser pathname, which
   * carries the base path when the site is served under a sub-path) and must
   * be a local path: one leading slash, no backslash, no control character.
   *
   * - It resolves to the saved host's own canonical route: the URL is
   *   generated from the saved entity, because saving a changed title or
   *   alias can change it.
   * - It resolves to any other route (the standalone block_content editing
   *   surface, a template's manage-layout route, the page a header or a
   *   footer was opened from): that route's URL, its query included.
   * - Anything else (no destination, a path that is not local, a stale
   *   alias that no longer resolves): the saved host's canonical page, or
   *   its editing surface when it has no canonical page (a display).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Entity\EntityInterface $saved
   *   The saved host entity.
   *
   * @return \Drupal\Core\Url
   *   A routed URL.
   */
  private function destination(Request $request, EntityInterface $saved): Url {
    $has_canonical = $saved->hasLinkTemplate('canonical');
    $routed = $this->routedDestination($request);
    if ($routed === NULL) {
      return $has_canonical ? $saved->toUrl() : $this->daedalusUi->getEditingUrl($saved);
    }
    $type = $saved->getEntityTypeId();
    if ($has_canonical
      && $routed->getRouteName() === "entity.$type.canonical"
      && (string) ($routed->getRouteParameters()[$type] ?? '') === (string) $saved->id()) {
      return $saved->toUrl();
    }
    return $routed;
  }

  /**
   * The route the request's destination names, or NULL when it names none.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Core\Url|null
   *   The routed URL of a local path that resolves, NULL otherwise.
   */
  private function routedDestination(Request $request): ?Url {
    $path = (string) $request->query->get('destination', '');
    $base_path = $request->getBasePath();
    if ($base_path !== '' && str_starts_with($path, $base_path . '/')) {
      $path = substr($path, strlen($base_path));
    }
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
      return NULL;
    }
    try {
      $url = Url::fromUri('internal:' . $path);
    }
    catch (\InvalidArgumentException) {
      // The path does not parse as a URI, so it names no route.
      return NULL;
    }
    return $url->isRouted() ? $url : NULL;
  }

}
