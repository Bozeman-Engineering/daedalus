<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\Result;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResultBase;

/**
 * Result of a blueprint operation on a config entity.
 *
 * Narrows getEntity() to ConfigEntityInterface; everything else is the
 * shared result machinery.
 */
class ConfigBlueprintResult extends BlueprintResultBase {

  /**
   * Returns the config entity if the operation succeeded.
   *
   * Covariant override: narrows EntityInterface to ConfigEntityInterface.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The config entity, or NULL if the operation failed or was validate-only.
   */
  public function getEntity(): ?ConfigEntityInterface {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function entitySummary(EntityInterface $entity): array {
    $summary = ['entity_type' => $entity->getEntityTypeId()];
    if ($entity->id()) {
      $summary['entity_id'] = $entity->id();
    }
    if ($entity->label()) {
      $summary['label'] = $entity->label();
    }
    return $summary;
  }

}
