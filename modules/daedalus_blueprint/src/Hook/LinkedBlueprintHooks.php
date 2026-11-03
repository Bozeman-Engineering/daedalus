<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Hook;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Host\BlueprintHost;

/**
 * Keeps the linked placement derivatives in step with the blueprints.
 *
 * A linked placement block derives per block-scope blueprint entity,
 * published or not (LinkedBlueprintDeriver; publication is the fit
 * gate's and the primitive's question), and block plugin definitions are
 * cached without entity dependencies, so a blueprint's birth, a label
 * change and its deletion must drop them for the schema and the op door
 * to know the right set. The entity type is the library module's, known
 * here by its id; on a site without it these hooks never fire for it.
 */
final class LinkedBlueprintHooks {

  public function __construct(protected BlockManagerInterface $blockManager) {}

  /**
   * Rebuilds the block definitions after a blueprint entity is written.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function blueprintChanged(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === BlueprintHost::ENTITY_TYPE_ID) {
      $this->blockManager->clearCachedDefinitions();
    }
  }

}
