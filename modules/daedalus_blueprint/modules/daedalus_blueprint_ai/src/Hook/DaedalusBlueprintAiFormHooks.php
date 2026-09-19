<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Form hooks for the Daedalus Blueprint AI module.
 */
final class DaedalusBlueprintAiFormHooks {

  /**
   * Implements hook_form_ai_api_explorer_form_alter().
   *
   * Marks the AI API Explorer form as workspace-safe so that WSE does not
   * bypass the active workspace during form submission. Without this, AI
   * tools that require a workspace context would be incorrectly blocked.
   */
  #[Hook('form_ai_api_explorer_form_alter')]
  public function formAiApiExplorerFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_state->set('workspace_safe', TRUE);
  }

}
