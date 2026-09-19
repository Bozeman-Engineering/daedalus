<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;

/**
 * Reads a blueprint write's design-system membership for the value seams.
 *
 * The field handlers behind variant styles, swatch values and animation
 * steps validate through StyleMapValidator with the MEMBER's universe in
 * context (see validateAt()'s design_system): the submitted sibling
 * field wins (the membership this write establishes, an explicit NULL
 * included: `design_system: ~` on disk, "global" on a create payload),
 * then the entity's stored membership covers value-only updates, exactly
 * the read VariantStylesFieldHandler makes for scope and axis.
 */
trait DesignSystemOptionTrait {

  /**
   * The membership a field write validates under, or NULL for global.
   */
  protected function submittedDesignSystem(array $options): ?string {
    $fields = $options['fields'] ?? [];
    if (is_array($fields) && array_key_exists('design_system', $fields)) {
      $submitted = $fields['design_system'];
      if (is_array($submitted) && array_key_exists('value', $submitted)) {
        $submitted = $submitted['value'];
      }
      return is_string($submitted) && $submitted !== '' ? $submitted : NULL;
    }
    $entity = $options['entity'] ?? NULL;
    return $entity instanceof DesignSystemMemberInterface ? $entity->getDesignSystem() : NULL;
  }

}
