<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\SkillType;

/**
 * Default skill type that returns static instructions as-is.
 */
class DefaultSkillType implements SkillTypeInterface {

  /**
   * {@inheritdoc}
   */
  public function compose(array $skill_definition, array $parameters): string {
    return $skill_definition['instructions'] ?? '';
  }

}
