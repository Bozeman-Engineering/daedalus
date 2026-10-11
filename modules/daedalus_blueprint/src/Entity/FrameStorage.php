<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;

/**
 * Storage for site frames: sections are config on disk, objects in memory.
 *
 * The same mapping core's LayoutBuilderEntityViewDisplayStorage does for a
 * display's sections, over the frame's own `sections` key.
 */
class FrameStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  protected function mapToStorageRecord(EntityInterface $entity) {
    $record = parent::mapToStorageRecord($entity);
    if (!empty($record['sections'])) {
      $record['sections'] = array_map(static fn (Section $section): array => $section->toArray(), $record['sections']);
    }
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  protected function mapFromStorageRecords(array $records) {
    foreach ($records as &$record) {
      if (!empty($record['sections'])) {
        $record['sections'] = array_map([Section::class, 'fromArray'], $record['sections']);
      }
    }
    return parent::mapFromStorageRecords($records);
  }

}
