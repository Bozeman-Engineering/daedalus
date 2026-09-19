<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Entity;

use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Label-derived machine name generation for daedalus_stylish content entities.
 *
 * The stable-reference pattern variants established and skins share: the
 * machine name is the identity YAML files, config pointers, and style maps
 * reference (content ids are environment-local, uuids opaque to authors),
 * auto-generated from the label when omitted and unique per entity type.
 */
trait MachineNameTrait {

  /**
   * Derives a unique machine name from the label.
   *
   * @param string $fallback
   *   The base used when the label transliterates to nothing.
   */
  protected function generateMachineName(string $fallback): string {
    $base = \Drupal::transliteration()->transliterate((string) $this->label(), 'en');
    $base = trim(preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($base)) ?? '', '_');
    if ($base === '') {
      $base = $fallback;
    }
    $storage = $this->entityTypeManager()->getStorage($this->getEntityTypeId());
    $name = $base;
    $suffix = 2;
    while ($this->machineNameExists($storage, $name)) {
      $name = $base . '_' . $suffix++;
    }
    return $name;
  }

  /**
   * Checks whether another entity of this type already uses a machine name.
   */
  protected function machineNameExists(EntityStorageInterface $storage, string $name): bool {
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $name);
    if (!$this->isNew()) {
      $query->condition('id', $this->id(), '<>');
    }
    return (bool) $query->range(0, 1)->execute();
  }

}
