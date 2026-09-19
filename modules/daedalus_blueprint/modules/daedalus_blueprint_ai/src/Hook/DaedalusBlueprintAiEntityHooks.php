<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Entity-related hooks for the Daedalus Blueprint AI module.
 */
final class DaedalusBlueprintAiEntityHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * Marks ai_log as workspace-ignored so logging does not break in
   * non-default workspaces.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['ai_log'])) {
      $entity_types['ai_log']->setHandlerClass('workspace', IgnoredWorkspaceHandler::class);
    }
  }

}
