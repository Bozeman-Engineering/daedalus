<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemActivateForm;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemForm;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemListBuilder;

/**
 * The design system config entity.
 *
 * One record, one storage, one id namespace: a package ships it as
 * install config (config/install/daedalus_blueprint.design_system.ID.yml)
 * and a site builder creates one in the UI, and both are the same record.
 * Members reference it by id; the active pointer
 * (daedalus_blueprint.settings active_design_system) names one. A packaged
 * system depends on its package module, so it leaves with the module; the
 * uninstall validator refuses the uninstall while the system is active or
 * has members, and deletion refuses the active system the same way.
 */
#[ConfigEntityType(
  id: 'design_system',
  label: new TranslatableMarkup('Design system'),
  label_collection: new TranslatableMarkup('Design systems'),
  label_singular: new TranslatableMarkup('design system'),
  label_plural: new TranslatableMarkup('design systems'),
  config_prefix: 'design_system',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => DesignSystemListBuilder::class,
    'form' => [
      'default' => DesignSystemForm::class,
      'add' => DesignSystemForm::class,
      'edit' => DesignSystemForm::class,
      'delete' => EntityDeleteForm::class,
      'activate' => DesignSystemActivateForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/appearance/design-systems',
    'add-form' => '/admin/appearance/design-systems/add',
    'edit-form' => '/admin/appearance/design-systems/{design_system}',
    'delete-form' => '/admin/appearance/design-systems/{design_system}/delete',
    'activate-form' => '/admin/appearance/design-systems/{design_system}/activate',
  ],
  admin_permission: 'administer design systems',
  label_count: [
    'singular' => '@count design system',
    'plural' => '@count design systems',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'icon',
    'default_skin',
    'package',
    'enforcement',
  ],
)]
class DesignSystem extends ConfigEntityBase implements DesignSystemInterface {

  public const ENTITY_TYPE_ID = 'design_system';

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected string $label;

  /**
   * The description.
   */
  protected string $description = '';

  /**
   * The icon value, or NULL.
   */
  protected ?string $icon = NULL;

  /**
   * The default skin's machine name, or NULL.
   */
  protected ?string $default_skin = NULL;

  /**
   * The package module, or NULL.
   */
  protected ?string $package = NULL;

  /**
   * The enforcement mode of this system's law; guarded unless shipped.
   */
  protected string $enforcement = self::ENFORCEMENT_GUARDED;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnforcement(): string {
    return $this->enforcement;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): ?string {
    return $this->icon;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSkin(): ?string {
    return $this->default_skin;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackage(): ?string {
    return $this->package;
  }

  /**
   * {@inheritdoc}
   *
   * A packaged system depends on its package: the package's members
   * belong to it, so it leaves the site with the module (config with a
   * module dependency is deleted on that module's uninstall), and the
   * uninstall validator gates that departure.
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    if ($this->package !== NULL) {
      $this->addDependency('module', $this->package);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The active system is refused loudly: deleting it would leave the
   * pointer naming nothing and every member of it stranded. Activate
   * another system first.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activeId();
    foreach ($entities as $entity) {
      if ($entity->id() === $active) {
        throw new \LogicException(sprintf('The design system "%s" is active and cannot be deleted; activate another design system first.', $entity->id()));
      }
    }
  }

}
