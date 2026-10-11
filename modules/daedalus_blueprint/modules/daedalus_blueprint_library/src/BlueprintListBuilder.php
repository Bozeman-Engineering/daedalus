<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * The blueprint admin listing.
 *
 * Lists CONTENT blueprints only, by construction: file-shipped blueprints
 * are read-only everywhere (no shadowing) and are not entities, so an
 * entity listing can never show them. The label links to the canonical
 * page, the blueprint's canvas: a blueprint is a page, composed where it
 * is viewed; the details form is an operation. Every row is judged by its
 * own type: the operations are the entity's access answers, per type
 * (BlueprintAccessControlHandler), and the listing itself opens to an
 * actor who may work on blueprints of any type (BlueprintTypeAccess).
 */
class BlueprintListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Machine name');
    $header['type'] = $this->t('Type');
    $header['scope'] = $this->t('Scope');
    $header['subject'] = $this->t('Subject');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof BlueprintInterface);
    $row['label'] = $entity->toLink($entity->label(), 'canonical');
    $row['machine_name'] = $entity->getMachineName();
    $type = $entity->get('type')->entity;
    $row['type'] = $type instanceof BlueprintTypeInterface ? $type->label() : $entity->getType();
    $row['scope'] = $entity->getScope();
    $row['subject'] = $entity->getSubjectType() . ':' . $entity->getSubjectBundle();
    $row['status'] = $entity->isPublished() ? $this->t('Published') : $this->t('Unpublished');
    return $row + parent::buildRow($entity);
  }

}
