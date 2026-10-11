<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Capability;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One `style {bundle} {type} elements` permission per bundle with a style store.
 *
 * The shape of core's LayoutBuilderOverridesPermissions: the style map
 * field's existence IS a bundle's styling enablement
 * (DaedalusStylishFieldManager), so every bundle carrying the field gets
 * a permission that depends on that field config and goes with it.
 */
final class StylePermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * The per-bundle style permissions.
   *
   * @return array<string, array>
   *   Permission definitions keyed by name, as
   *   \Drupal\user\PermissionHandlerInterface::getPermissions() returns.
   */
  public function permissions(): array {
    $permissions = [];
    $map = $this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map');
    foreach ($map as $entity_type_id => $fields) {
      $bundles = $fields[DaedalusStylishFieldManager::FIELD_NAME]['bundles'] ?? [];
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle) {
        $args = [
          '%entity_type' => $entity_type->getCollectionLabel(),
          '%bundle' => $bundle_info[$bundle]['label'] ?? $bundle,
        ];
        $permissions[StylePolicy::permission($entity_type_id, $bundle)] = [
          'title' => $entity_type->hasKey('bundle')
            ? $this->t('%entity_type - %bundle: Style elements', $args)
            : $this->t('%entity_type: Style elements', $args),
          'description' => $this->t('Write styles and variants onto the elements of a page of this bundle. Structure follows the Layout Builder override permissions.'),
          'dependencies' => [
            'config' => ["field.field.$entity_type_id.$bundle." . DaedalusStylishFieldManager::FIELD_NAME],
          ],
        ];
      }
    }
    return $permissions;
  }

}
