<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the stencil-ownership base field on daedalus_stylish's variant entity.
 *
 * A stencil OWNS the variants minted for it (its switchable looks): they
 * ship with it, they are offered only on its placements, and deleting the
 * stencil orphans them. Ownership is a stencil-domain fact, so THIS module
 * provides the field as a cross-module base field on daedalus_variant —
 * daedalus_stylish core never learns about stencils; where this module is absent
 * the field simply does not exist and every variant is unowned. Consumers
 * read the field where installed: the variant payload annotates it, the
 * picker filters on placement provenance, the save-stencil dialog offers
 * adoption, and the variants disk lane resolves its `stencil:` key
 * against it.
 *
 * Ownership has ONE identity (the stencil machine name, one flat site-wide
 * vocabulary across both stencil sources) and TWO storage lanes, matching
 * the two sources:
 * - `stencil`, a real entity REFERENCE (not the machine-name string the
 *   provenance stamps carry) for CONTENT stencils, so referential integrity
 *   is storage-level: the reference either resolves to the owning stencil
 *   or reads as orphaned.
 * - `stencil_file`, the machine name of a module-shipped FILE stencil,
 *   which has no entity to reference. Resolved against stencil discovery;
 *   the id disappearing from discovery (module uninstalled, file removed)
 *   reads as orphaned. This is the lane that makes a cross-site package
 *   (stencil YAML plus owned variants YAML, both code) resolvable: the
 *   variants disk lane writes it when its `stencil:` key names a file
 *   stencil.
 * A variant sets at most one lane (the writers clear the other). Ownership
 * is identity, like machine_name and scope, so neither field is
 * revisionable.
 */
final class StencilVariantFieldHooks {

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'daedalus_variant') {
      return [];
    }
    $fields['stencil'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner stencil'))
      ->setDescription(new TranslatableMarkup("The content stencil that owns this variant. Owned variants are offered only on that stencil's placements; deleting the stencil orphans them (offered nowhere, existing wearers keep rendering)."))
      ->setSetting('target_type', 'stencil')
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE);
    $fields['stencil_file'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Owner file stencil'))
      ->setDescription(new TranslatableMarkup('The machine name of the module-shipped file stencil that owns this variant. File stencils have no entity to reference; the name resolves against stencil discovery, and an undiscoverable name reads as orphaned.'))
      ->setSetting('max_length', 255)
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE);
    return $fields;
  }

}
