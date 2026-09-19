<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Provides entity_reference_revisions helpers for kernel tests.
 *
 * Allows tests that need ERR to enable it on demand rather than requiring
 * it in the base $modules array, so non-ERR tests can run independently.
 */
trait EntityReferenceRevisionsTrait {

  /**
   * Enables the entity_reference_revisions module.
   *
   * Call this at the start of any test method or setUp() that needs ERR.
   * Skips the test if ERR is not available in the codebase.
   */
  protected function enableEntityReferenceRevisions(): void {
    /** @var \Drupal\Core\Extension\ModuleExtensionList $extension_list */
    $extension_list = \Drupal::service('extension.list.module');
    if (!$extension_list->exists('entity_reference_revisions')) {
      $this->markTestSkipped('entity_reference_revisions module is not available.');
    }
    $this->enableModules(['entity_reference_revisions']);
  }

  /**
   * Creates an entity_reference_revisions field on an entity type.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $host_entity_type
   *   The host entity type (e.g., 'entity_test').
   * @param string $host_bundle
   *   The host bundle.
   * @param string $target_type
   *   The target entity type (e.g., 'entity_test_rev').
   * @param array $target_bundles
   *   Allowed target bundles. Empty for all bundles.
   * @param int $cardinality
   *   Field cardinality (-1 for unlimited).
   */
  protected function createEntityReferenceRevisionsField(
    string $field_name,
    string $host_entity_type,
    string $host_bundle,
    string $target_type,
    array $target_bundles = [],
    int $cardinality = -1,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $host_entity_type,
      'type' => 'entity_reference_revisions',
      'cardinality' => $cardinality,
      'settings' => [
        'target_type' => $target_type,
      ],
    ])->save();

    $handler_settings = [];
    if (!empty($target_bundles)) {
      $handler_settings['target_bundles'] = array_combine($target_bundles, $target_bundles);
    }

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $host_entity_type,
      'bundle' => $host_bundle,
      'label' => ucwords(str_replace('_', ' ', str_replace('field_', '', $field_name))),
      'settings' => [
        'handler' => 'default:' . $target_type,
        'handler_settings' => $handler_settings,
      ],
    ])->save();
  }

}
