<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Opens a core Layout Builder route only for a storage no host owns.
 *
 * The `_daedalus_layout_hostless_storage` requirement, set by
 * LayoutBuilderRouteSubscriber on core's write routes: a storage whose
 * host Daedalus resolves is written through the op spine alone, so the
 * route is forbidden for it; a storage with no host (core's navigation
 * layout) keeps core's path, judged by core's own `_layout_builder_access`
 * beside this one.
 */
final class HostlessStorageAccessCheck implements AccessInterface {

  public function __construct(
    private readonly HostResolver $hostResolver,
  ) {}

  /**
   * Checks that the route's storage has no host.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The route's section storage (nested-scoped or not).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Forbidden for a hosted storage, allowed otherwise.
   */
  public function access(SectionStorageInterface $section_storage): AccessResultInterface {
    $host = $this->hostResolver->fromSectionStorage($section_storage);
    $result = $host === NULL
      ? AccessResult::allowed()
      : AccessResult::forbidden(sprintf('The %s %s is hosted by Daedalus: its document is written through the blueprint operations, never through core\'s Layout Builder routes.', $host->entityTypeId(), $host->id()));
    if ($section_storage instanceof CacheableDependencyInterface) {
      $result->addCacheableDependency($section_storage);
    }
    else {
      $result->setCacheMaxAge(0);
    }
    return $result;
  }

}
