<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\Access;

use Drupal\Core\Entity\EntityAccessCheck;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Symfony\Component\Routing\Route;

/**
 * Judges a route's `_entity_access` on the stored entity.
 *
 * Decorates core's entity access check. The entity param converter may
 * answer a route parameter with the entity's unsaved copy; access to the
 * route is a question for the entity as it is saved, so the check is asked
 * of the stored entity the copy stands for. A parameter the converter did
 * not swap is core's to judge, unchanged.
 */
final class StoredEntityAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EntityAccessCheck $inner,
    private readonly EntityConverter $entityConverter,
  ) {}

  /**
   * Checks access to the entity operation on the given route.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account asking.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   *
   * @see \Drupal\Core\Entity\EntityAccessCheck::access()
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    [$slug, $operation] = explode('.', $route->getRequirement('_entity_access'));
    $entity = $route_match->getParameters()->get($slug);
    $stored = $entity instanceof EntityInterface ? $this->entityConverter->storedOf($entity) : NULL;
    if ($stored === NULL) {
      return $this->inner->access($route, $route_match, $account);
    }
    return $stored->access($operation, $account, TRUE);
  }

}
