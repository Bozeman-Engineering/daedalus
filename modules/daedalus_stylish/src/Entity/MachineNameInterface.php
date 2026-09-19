<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A daedalus_stylish content entity referenced by a stable machine name.
 *
 * MachineNameTrait's contract: the machine name is the identity YAML
 * files, config pointers, style maps and CSS values reference (content ids
 * are environment-local, uuids opaque to authors), auto-generated from the
 * label when omitted and unique per entity type. Each material interface
 * documents its own derivation rule (what the name spells in delivered
 * CSS); this is the one type the name-keyed spines (the value-usage tables
 * and their delete batches) read it through.
 */
interface MachineNameInterface extends ContentEntityInterface {

  /**
   * Returns the stable reference name.
   */
  public function getMachineName(): string;

}
