<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The design systems admin listing: which is active, and activate another.
 */
class DesignSystemListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get(ActiveDesignSystem::SERVICE_ID),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Design system');
    $header['id'] = $this->t('Machine name');
    $header['default_skin'] = $this->t('Default skin');
    $header['package'] = $this->t('Package');
    $header['enforcement'] = $this->t('Enforcement');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof DesignSystemInterface);
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['default_skin'] = $entity->getDefaultSkin() ?? $this->t('None');
    $row['package'] = $entity->getPackage() ?? $this->t('Site-built');
    $row['enforcement'] = ucfirst($entity->getEnforcement());
    $row['status'] = $entity->id() === $this->activeDesignSystem->activeId() ? $this->t('Active') : '';
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * An inactive system offers Activate; the active one offers no Delete
   * (the entity refuses it anyway: activate another first).
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->id() === $this->activeDesignSystem->activeId()) {
      unset($operations['delete']);
      return $operations;
    }
    if ($entity->access('update') && $entity->hasLinkTemplate('activate-form')) {
      $operations['activate'] = [
        'title' => $this->t('Activate'),
        'weight' => -10,
        'url' => $this->ensureDestination($entity->toUrl('activate-form')),
      ];
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No design systems yet. A module ships one as install config, or add one here.');
    return $build;
  }

}
