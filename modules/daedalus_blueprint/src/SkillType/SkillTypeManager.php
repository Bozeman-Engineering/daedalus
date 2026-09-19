<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\SkillType;

/**
 * Collects skill type plugins and resolves by type ID.
 */
class SkillTypeManager {

  /**
   * Registered skill type plugins keyed by type ID.
   *
   * @var \Drupal\daedalus_blueprint\SkillType\SkillTypeInterface[]
   */
  protected array $types = [];

  /**
   * Adds a skill type plugin.
   *
   * @param \Drupal\daedalus_blueprint\SkillType\SkillTypeInterface $type
   *   The skill type plugin.
   * @param string $type_id
   *   The type ID.
   */
  public function addType(SkillTypeInterface $type, string $type_id): void {
    $this->types[$type_id] = $type;
  }

  /**
   * Gets a skill type plugin by ID.
   *
   * @param string $id
   *   The type ID.
   *
   * @return \Drupal\daedalus_blueprint\SkillType\SkillTypeInterface|null
   *   The plugin, or NULL if not registered.
   */
  public function getType(string $id): ?SkillTypeInterface {
    return $this->types[$id] ?? NULL;
  }

}
