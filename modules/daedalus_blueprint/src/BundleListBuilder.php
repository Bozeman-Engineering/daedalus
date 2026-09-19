<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Builds the list of AI-visible entity types and bundles.
 */
class BundleListBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the filtered bundle list visible to AI.
   *
   * Applies config-based filtering, then invokes
   * hook_daedalus_blueprint_ai_bundle_list_alter() for additional filtering.
   *
   * @return array
   *   Keyed by entity type ID. Each value contains:
   *   - label: string (entity type label)
   *   - bundles: array keyed by bundle ID with 'label' and 'description'.
   */
  public function getVisibleBundles(): array {
    $bundle_list = $this->buildBundleList();

    $config = $this->configFactory->get('daedalus_blueprint_ai.settings');
    $hidden_entity_types = $config->get('hidden_entity_types') ?? [];
    $hidden_bundles = $config->get('hidden_bundles') ?? [];

    foreach ($hidden_entity_types as $type_id) {
      unset($bundle_list[$type_id]);
    }

    foreach ($hidden_bundles as $type_id => $bundles) {
      if (!isset($bundle_list[$type_id])) {
        continue;
      }
      foreach ($bundles as $bundle_id) {
        unset($bundle_list[$type_id]['bundles'][$bundle_id]);
      }
      if (empty($bundle_list[$type_id]['bundles'])) {
        unset($bundle_list[$type_id]);
      }
    }

    $this->moduleHandler->alter('daedalus_blueprint_ai_bundle_list', $bundle_list);

    return $bundle_list;
  }

  /**
   * Whether AI visibility filtering hides an entity type (or one bundle).
   *
   * Hidden is enforcement, not just presentation: the AI tools refuse to
   * read, create, or operate on hidden types and bundles, so hiding a type
   * (daedalus_blueprint_ai.settings or
   * hook_daedalus_blueprint_ai_bundle_list_alter()) removes the AI's whole
   * surface for it: unlisted means unreachable. A type outside the
   * fieldable bundle catalog (config entity types, bundle-less types) is
   * never "hidden": the visibility vocabulary does not cover it, and the
   * tools apply their own suitability guards to it.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string|null $bundle
   *   A bundle to check within the type, or NULL for the type itself.
   *
   * @return bool
   *   TRUE when the type (or the given bundle) is catalogued but filtered
   *   out of the AI-visible list.
   */
  public function isHidden(string $entity_type, ?string $bundle = NULL): bool {
    $catalog = $this->buildBundleList();
    if (!isset($catalog[$entity_type])) {
      return FALSE;
    }
    $visible = $this->getVisibleBundles();
    if (!isset($visible[$entity_type])) {
      return TRUE;
    }
    return $bundle !== NULL
      && isset($catalog[$entity_type]['bundles'][$bundle])
      && !isset($visible[$entity_type]['bundles'][$bundle]);
  }

  /**
   * Formats the visible bundle list as markdown.
   *
   * @return string
   *   Formatted markdown string with entity types as headings and bundles
   *   as list items.
   */
  public function formatAsMarkdown(): string {
    $bundle_list = $this->getVisibleBundles();

    $lines = [];
    foreach ($bundle_list as $type_id => $type_data) {
      if (empty($type_data['bundles'])) {
        continue;
      }

      $lines[] = "## {$type_data['label']} ({$type_id})";

      foreach ($type_data['bundles'] as $bundle_id => $bundle_data) {
        $line = "- {$bundle_id}: {$bundle_data['label']}";
        if (!empty($bundle_data['description'])) {
          $line .= " — {$bundle_data['description']}";
        }
        $lines[] = $line;
      }

      $lines[] = '';
    }

    return implode("\n", $lines);
  }

  /**
   * Builds the full unfiltered bundle list.
   *
   * @return array
   *   Same structure as getVisibleBundles(), before filtering.
   */
  protected function buildBundleList(): array {
    $bundle_list = [];

    foreach ($this->entityTypeManager->getDefinitions() as $type_id => $definition) {
      if (!$definition->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }

      $bundles = $this->bundleInfo->getBundleInfo($type_id);
      if (empty($bundles)) {
        continue;
      }

      $bundle_entity_type = $definition->getBundleEntityType();
      $bundle_storage = $bundle_entity_type
        ? $this->entityTypeManager->getStorage($bundle_entity_type)
        : NULL;

      $bundle_data = [];
      foreach ($bundles as $bundle_id => $bundle_info) {
        $bundle_label = (string) ($bundle_info['label'] ?? $bundle_id);
        $description = '';

        if ($bundle_storage) {
          $bundle_entity = $bundle_storage->load($bundle_id);
          if ($bundle_entity && method_exists($bundle_entity, 'getDescription')) {
            $description = (string) $bundle_entity->getDescription();
          }
        }

        $bundle_data[$bundle_id] = [
          'label' => $bundle_label,
          'description' => $description,
        ];
      }

      $bundle_list[$type_id] = [
        'label' => (string) $definition->getLabel(),
        'bundles' => $bundle_data,
      ];
    }

    return $bundle_list;
  }

}
