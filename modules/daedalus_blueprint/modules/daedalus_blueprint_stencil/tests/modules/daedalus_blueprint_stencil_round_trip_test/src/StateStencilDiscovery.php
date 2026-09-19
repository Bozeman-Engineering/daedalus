<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil_round_trip_test;

use Drupal\Core\State\StateInterface;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;

/**
 * Serves test-provided stencil definitions alongside the real discovery.
 *
 * Round-trip tests need to feed an exporter-produced definition back through
 * the real instantiate path, but discovery reads stencil files from module
 * directories on disk, which a test cannot write into at runtime. This
 * decorator merges definitions the test placed in state over the inner
 * discovery's file-based set, so the instantiate operation consumes the
 * export exactly as it would a shipped file. Test seam only: production
 * stencils always come from files (there is deliberately no alter hook).
 */
class StateStencilDiscovery implements StencilDiscoveryInterface {

  public const STATE_KEY = 'daedalus_blueprint_stencil_round_trip_test.stencils';

  public function __construct(
    protected StencilDiscoveryInterface $inner,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getStencils(): array {
    return $this->state->get(self::STATE_KEY, []) + $this->inner->getStencils();
  }

  /**
   * {@inheritdoc}
   */
  public function getStencil(string $id): ?array {
    return $this->getStencils()[$id] ?? NULL;
  }

}
