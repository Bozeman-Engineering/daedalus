<?php

namespace Drupal\daedalus_layout_transform_test;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_layout\ComponentTransform\HostTransformInterface;

/**
 * Renames hosts labeled 'Legacy host' to 'Modern host'.
 *
 * A minimal, idempotent host transform: it dirties the host exactly once
 * and reports FALSE ever after, which is what the spine's save-only-dirty
 * behavior and the presave net's reentrancy lean on.
 */
class LegacyNameHostTransform implements HostTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function transformHost(ContentEntityInterface $host): bool {
    if ($host->label() !== 'Legacy host') {
      return FALSE;
    }
    TransformLog::$calls[] = 'host:' . $host->label();
    $host->set('name', 'Modern host');
    return TRUE;
  }

}
