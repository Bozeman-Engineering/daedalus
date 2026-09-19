<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Resolves a Drupal route path to the entity that page is about.
 *
 * The path rides in the client's DeepChat request ("the page the user is
 * on"). Resolution delegates to daedalus_ui' derivation spine
 * (deriveEntityFromRoute), so chat context answers the same route grammar as
 * every other page builder consumer: canonical entity pages, the standalone
 * block_content editing surface (/block-content/{id}), and any future editing
 * route, instead of a bespoke entity.*.canonical regex that goes stale as
 * routes are added.
 */
class RouteEntityResolver {

  /**
   * Per-request cache of resolved entities keyed by path.
   *
   * @var array<string, \Drupal\Core\Entity\FieldableEntityInterface|null>
   */
  protected array $cache = [];

  public function __construct(
    protected PathValidatorInterface $pathValidator,
    protected BlueprintAccessChecker $accessChecker,
    protected RequestMatcherInterface $router,
    protected DaedalusUi $daedalusUi,
  ) {}

  /**
   * Resolves a path to the entity its page is about.
   *
   * @param string $path
   *   A Drupal internal path (e.g., "/node/5" or "/block-content/31").
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The entity, or NULL if the path does not resolve to one.
   */
  public function resolve(string $path): ?FieldableEntityInterface {
    if (array_key_exists($path, $this->cache)) {
      return $this->cache[$path];
    }

    $this->cache[$path] = $this->doResolve($path);
    return $this->cache[$path];
  }

  /**
   * Performs the actual path-to-entity resolution.
   */
  protected function doResolve(string $path): ?FieldableEntityInterface {
    // The access-aware validation gate: a path the current user cannot route
    // to (or that routes nowhere) never resolves.
    $url = $this->pathValidator->getUrlIfValid($path);
    if (!$url || !$url->isRouted()) {
      return NULL;
    }

    // Re-match without access checks purely to UPCAST the route parameters
    // (the access gate already ran above): Url carries only raw parameters,
    // and the derivation spine reads upcast entities off a route match.
    $request = Request::create($path);
    $request->attributes->add($this->router->matchRequest($request));
    $entity = $this->daedalusUi->deriveEntityFromRoute(RouteMatch::createFromRequest($request));

    // The path is client-supplied (it rides in the DeepChat request), so route
    // access is not a proxy for view access here. Only surface the entity as
    // prompt context if the current user may actually view it.
    if ($entity instanceof FieldableEntityInterface
        && $this->accessChecker->entityAccess($entity, 'view')) {
      return $entity;
    }

    return NULL;
  }

}
