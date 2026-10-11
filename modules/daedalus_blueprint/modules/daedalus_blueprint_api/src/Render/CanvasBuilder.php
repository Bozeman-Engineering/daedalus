<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The canvas-builder socket: collects and fronts tagged builders.
 *
 * Builders are asked in priority order (highest first); the first whose
 * applies() accepts the host builds. daedalus_blueprint_api's own
 * EntityViewCanvasBuilder (the lowest priority) answers for every host
 * whose entity type has a view builder, so a site without an editing UI
 * still renders content hosts.
 */
final class CanvasBuilder implements CanvasBuilderInterface {

  /**
   * The collected builders, in priority order.
   *
   * @var \Drupal\daedalus_blueprint_api\Render\CanvasBuilderInterface[]
   */
  private array $builders = [];

  /**
   * Adds a tagged builder (service collector callback).
   */
  public function addBuilder(CanvasBuilderInterface $builder): void {
    $this->builders[] = $builder;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(HostInterface $host): bool {
    return $this->builderFor($host) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \LogicException
   *   When no builder renders the host; ask applies() first at a door that
   *   takes any host.
   */
  public function build(HostInterface $host, string $view_mode): array {
    $builder = $this->builderFor($host);
    if ($builder === NULL) {
      throw new \LogicException(sprintf('No canvas builder renders %s %s.', $host->entityTypeId(), $host->id() ?? 'new'));
    }
    return $builder->build($host, $view_mode);
  }

  /**
   * The first builder that applies to a host, in priority order.
   */
  private function builderFor(HostInterface $host): ?CanvasBuilderInterface {
    foreach ($this->builders as $builder) {
      if ($builder->applies($host)) {
        return $builder;
      }
    }
    return NULL;
  }

}
