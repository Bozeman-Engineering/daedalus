<?php

namespace Drupal\daedalus_layout_transform_test;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_layout\ComponentTransform\HostTransformInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Renames legacy hosts to modern ones.
 *
 * A minimal, idempotent host transform: it dirties the host exactly once
 * and reports FALSE ever after, which is what the spine's save-only-dirty
 * behavior and the presave net's reentrancy lean on. A content host is
 * legacy when it is labeled 'Legacy host' (renamed to 'Modern host'); a
 * display host when it carries the test module's `legacy` third-party
 * setting (unset).
 */
class LegacyNameHostTransform implements HostTransformInterface {

  /**
   * The third-party provider a legacy display is marked under.
   */
  public const PROVIDER = 'daedalus_layout_transform_test';

  /**
   * {@inheritdoc}
   */
  public function transformHost(HostInterface $host): bool {
    $entity = $host->entity();
    if ($entity instanceof ContentEntityInterface) {
      if ($entity->label() !== 'Legacy host') {
        return FALSE;
      }
      TransformLog::$calls[] = 'host:' . $entity->label();
      $entity->set('name', 'Modern host');
      return TRUE;
    }
    if ($entity instanceof LayoutEntityDisplayInterface && $entity->getThirdPartySetting(self::PROVIDER, 'legacy')) {
      TransformLog::$calls[] = 'host:' . $host->id();
      $entity->unsetThirdPartySetting(self::PROVIDER, 'legacy');
      return TRUE;
    }
    return FALSE;
  }

}
