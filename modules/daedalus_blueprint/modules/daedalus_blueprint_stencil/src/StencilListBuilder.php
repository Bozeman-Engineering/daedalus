<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * The stencil admin listing.
 *
 * Lists CONTENT stencils only, by construction: file-shipped stencils are
 * read-only everywhere (no shadowing) and are not entities, so an entity
 * listing can never show them. The label links to the metadata edit form —
 * a stencil has no canonical page; it is a palette entry, not content to
 * view.
 */
class StencilListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Machine name');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof StencilInterface);
    $row['label'] = $entity->toLink($entity->label(), 'edit-form');
    $row['machine_name'] = $entity->getMachineName();
    $row['status'] = $entity->isPublished() ? $this->t('Published') : $this->t('Unpublished');
    return $row + parent::buildRow($entity);
  }

}
