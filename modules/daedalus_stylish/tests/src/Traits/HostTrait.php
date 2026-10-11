<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Traits;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Resolves an entity's host the way the spine's doors do.
 *
 * Every public spine method takes the host; a test that holds an entity
 * resolves it here.
 */
trait HostTrait {

  /**
   * The host of an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   Its host.
   */
  protected function host(FieldableEntityInterface $entity): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
  }

}
