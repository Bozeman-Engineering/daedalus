<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The skin list: /admin/appearance/skins.
 *
 * Shows which skin the site wears beside the library of looks it could
 * wear. The list is read-only apart from delete: a skin is authored and
 * chosen on the canvas (the Palette's skin section and its skin card),
 * never through an admin form.
 */
class DaedalusStylishSkinListBuilder extends EntityListBuilder {

  /**
   * The active-skin pointer, for the Active column.
   */
  protected SkinManager $skinManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->skinManager = $container->get('daedalus_stylish.skin_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Skin'),
      'machine_name' => $this->t('Machine name'),
      'active' => $this->t('Active'),
      'status' => $this->t('Status'),
      'provider' => $this->t('Shipped by'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof DaedalusStylishSkinInterface);
    return [
      'label' => $entity->label(),
      'machine_name' => $entity->getMachineName(),
      'active' => $this->skinManager->activeSkinName() === $entity->getMachineName() ? $this->t('Active') : '',
      'status' => $entity->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
      'provider' => $entity->getProvider() ?? '',
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t("No skins yet. A skin holds a site's design tokens as data; create one to give the page builder (and AI) reach into the site's look.");
    return $build;
  }

}
