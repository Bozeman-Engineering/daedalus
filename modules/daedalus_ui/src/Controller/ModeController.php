<?php

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\Form\BundleEditFormAlter;

/**
 * Enables and disables a mode on an entity bundle.
 */
class ModeController extends ControllerBase {

  /**
   * Enables a mode on a bundle and redirects back to the bundle form.
   */
  public function enable(string $plugin_id, string $entity_type_id, string $entity_bundle_id) {
    $bundled_entity = $this->entityTypeManager()->getDefinition($entity_type_id);
    $bundle_entity_type = $bundled_entity->getBundleEntityType();
    if ($bundle_entity_type) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $bundle_entity */
      $bundle_entity = $this->entityTypeManager()->getStorage($bundle_entity_type)->load($entity_bundle_id);
      $statuses = $bundle_entity->getThirdPartySetting('daedalus_ui', "status", []);
      $statuses[$plugin_id] = TRUE;
      $bundle_entity->setThirdPartySetting('daedalus_ui', 'status', $statuses);
      daedalus_ui_save_outside_workspace($bundle_entity);
    }
    $response = new AjaxResponse();
    $build = [
      'operations' => [
        '#type' => 'operations',
        '#links' => BundleEditFormAlter::getOperations($plugin_id, $entity_type_id, $entity_bundle_id),
        // Allow links to use modals.
        '#attached' => [
          'library' => ['core/drupal.dialog.ajax'],
        ],
      ],
    ];
    $response->addCommand(new ReplaceCommand('[data-drupal-selector=edit-modes-edit] .dropbutton-wrapper', $build));
    return $response;
  }

  /**
   * Disables a mode on a bundle and redirects back to the bundle form.
   */
  public function disable(string $plugin_id, string $entity_type_id, string $entity_bundle_id) {
    $bundled_entity = $this->entityTypeManager()->getDefinition($entity_type_id);
    $bundle_entity_type = $bundled_entity->getBundleEntityType();
    if ($bundle_entity_type) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $bundle_entity */
      $bundle_entity = $this->entityTypeManager()->getStorage($bundle_entity_type)->load($entity_bundle_id);
      $statuses = $bundle_entity->getThirdPartySetting('daedalus_ui', "status", []);
      unset($statuses[$plugin_id]);
      $bundle_entity->setThirdPartySetting('daedalus_ui', 'status', $statuses ?? []);
      daedalus_ui_save_outside_workspace($bundle_entity);

    }
    $response = new AjaxResponse();
    $build = [
      'operations' => [
        '#type' => 'operations',
        '#links' => BundleEditFormAlter::getOperations($plugin_id, $entity_type_id, $entity_bundle_id),
        // Allow links to use modals.
        '#attached' => [
          'library' => ['core/drupal.dialog.ajax'],
        ],
      ],
    ];
    $response->addCommand(new ReplaceCommand('[data-drupal-selector=edit-modes-edit] .dropbutton-wrapper', $build));
    return $response;
  }

}
