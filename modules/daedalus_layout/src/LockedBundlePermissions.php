<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core's per-bundle override permissions, continued for locked bundles.
 *
 * Core (LayoutBuilderOverridesPermissions) defines `configure all
 * {bundle} {type} layout overrides` and `configure editable {bundle}
 * {type} layout overrides` for a bundle with an OVERRIDABLE display only,
 * and the spine's role policy (daedalus_blueprint's RolePolicy) knows
 * those two names and `configure any layout` as the roads to a page's
 * own structure. A locked bundle (Layout Builder enabled, overrides off)
 * still gives its pages a document of their own: the override field
 * daedalus_layout adds to every enabled bundle, holding the slot sections
 * the template's slot blocks are filled with. An editor filling a slot
 * needs the same road, so the same two names are defined here for every
 * bundle whose enabled displays are all locked (never beside core's: a
 * bundle with one overridable display is core's). One vocabulary for
 * both states, so a role carrying the bundle's permission keeps it, and
 * keeps meaning "this role edits the page's own document", when the site
 * builder locks or unlocks the bundle: the lock is a display toggle, not
 * a permission migration.
 */
final class LockedBundlePermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * The override permissions of every bundle whose enabled displays are locked.
   *
   * @return array<string, array>
   *   Permission definitions keyed by name, as
   *   \Drupal\user\PermissionHandlerInterface::getPermissions() returns.
   */
  public function permissions(): array {
    /** @var \Drupal\layout_builder\Entity\LayoutEntityDisplayInterface[] $displays */
    $displays = $this->entityTypeManager->getStorage('entity_view_display')->loadByProperties(['third_party_settings.layout_builder.enabled' => TRUE]);
    $locked = [];
    $overridable = [];
    foreach ($displays as $display) {
      if (!$display instanceof LayoutEntityDisplayInterface) {
        continue;
      }
      $key = $display->getTargetEntityTypeId() . ':' . $display->getTargetBundle();
      if ($display->isOverridable()) {
        $overridable[$key] = TRUE;
        continue;
      }
      $locked[$key][] = $display;
    }

    $permissions = [];
    foreach (array_diff_key($locked, $overridable) as $displays_of_bundle) {
      $display = reset($displays_of_bundle);
      $entity_type_id = $display->getTargetEntityTypeId();
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $bundle = $display->getTargetBundle();
      $args = [
        '%entity_type' => $entity_type->getCollectionLabel(),
        '@entity_type_singular' => $entity_type->getSingularLabel(),
        '@entity_type_plural' => $entity_type->getPluralLabel(),
        '%bundle' => $this->bundleInfo->getBundleInfo($entity_type_id)[$bundle]['label'] ?? $bundle,
      ];
      // The permissions exist on behalf of the locked displays: they go
      // with them, as core's go with the overridable display.
      $dependencies = ['config' => []];
      foreach ($displays_of_bundle as $locked_display) {
        $dependencies['config'][] = $locked_display->getConfigDependencyName();
      }
      $bundled = $entity_type->hasKey('bundle');
      $description = $this->t('The template is locked; a page of this type owns only the slots the template places. The same permission core defines for an overridable display, so the role keeps it when the template is unlocked.');
      $permissions["configure all $bundle $entity_type_id layout overrides"] = [
        'title' => $bundled
          ? $this->t("%entity_type - %bundle: Fill the template's slots on any @entity_type_singular", $args)
          : $this->t("%entity_type: Fill the template's slots on any @entity_type_singular", $args),
        'description' => $description,
        'warning' => $this->t('Warning: Allows filling the slots even if the user cannot edit the @entity_type_singular itself.', $args),
        'dependencies' => $dependencies,
      ];
      $permissions["configure editable $bundle $entity_type_id layout overrides"] = [
        'title' => $bundled
          ? $this->t("%entity_type - %bundle: Fill the template's slots on @entity_type_plural that the user can edit", $args)
          : $this->t("%entity_type: Fill the template's slots on @entity_type_plural that the user can edit", $args),
        'description' => $description,
        'dependencies' => $dependencies,
      ];
    }
    return $permissions;
  }

}
