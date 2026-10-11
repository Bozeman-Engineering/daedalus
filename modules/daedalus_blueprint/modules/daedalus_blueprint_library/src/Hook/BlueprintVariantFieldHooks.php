<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint_library\BlueprintInterface;

/**
 * Provides the blueprint-ownership base fields on daedalus_stylish's variant.
 *
 * A blueprint OWNS the variants minted for it (its switchable looks): they
 * ship with it, they are offered only on its placements, and deleting the
 * blueprint orphans them. Ownership is a blueprint-domain fact, so THIS
 * module provides the fields as cross-module base fields on
 * daedalus_variant; daedalus_stylish never learns about blueprints beyond
 * reading the fields as data, and where this module is absent the fields
 * do not exist and every variant is unowned. Consumers read the fields
 * where installed: the variant payload annotates them, the picker filters
 * on placement provenance, the save-blueprint dialog offers adoption, and
 * the variants disk lane resolves its owner key against them.
 *
 * Ownership has ONE identity (the blueprint machine name, one flat
 * site-wide vocabulary across both blueprint lanes) and TWO storage lanes,
 * matching the two lanes:
 * - `blueprint` (BlueprintInterface::OWNER_FIELD), a real entity REFERENCE
 *   (not the machine-name string the provenance stamps carry) for CONTENT
 *   blueprints, so referential integrity is storage-level: the reference
 *   either resolves to the owning blueprint or reads as orphaned.
 * - `blueprint_file` (BlueprintInterface::FILE_OWNER_FIELD), the machine
 *   name of a module-shipped FILE blueprint, which has no entity to
 *   reference. Resolved against blueprint discovery; the id disappearing
 *   from discovery (module uninstalled, file removed) reads as orphaned.
 *   This is the lane that makes a cross-site package (blueprint YAML plus
 *   owned variants YAML, both code) resolvable: the variants disk lane
 *   writes it when its owner key names a file blueprint.
 * A variant sets at most one lane (the writers clear the other). Ownership
 * is identity, like machine_name and scope, so neither field is
 * revisionable.
 */
final class BlueprintVariantFieldHooks {

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'daedalus_variant') {
      return [];
    }
    $fields[BlueprintInterface::OWNER_FIELD] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner blueprint'))
      ->setDescription(new TranslatableMarkup("The content blueprint that owns this variant. Owned variants are offered only on that blueprint's placements; deleting the blueprint orphans them (offered nowhere, existing wearers keep rendering)."))
      ->setSetting('target_type', BlueprintInterface::ENTITY_TYPE_ID)
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE);
    $fields[BlueprintInterface::FILE_OWNER_FIELD] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Owner file blueprint'))
      ->setDescription(new TranslatableMarkup('The machine name of the module-shipped file blueprint that owns this variant. File blueprints have no entity to reference; the name resolves against blueprint discovery, and an undiscoverable name reads as orphaned.'))
      ->setSetting('max_length', 255)
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE);
    return $fields;
  }

}
