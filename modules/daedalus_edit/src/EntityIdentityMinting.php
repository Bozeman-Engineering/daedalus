<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\filter\FilterFormatInterface;

/**
 * Mints identity classes across an entity's formatted text fields.
 *
 * The entity-level walker behind eager identity's universal write paths: the
 * hook_entity_presave() backstop runs it on every save (forms, REST,
 * migrations, blueprint persists), and IdentityBackfill runs it over
 * existing content. The blueprint stage-time seam
 * (\Drupal\daedalus_edit\FieldHandler\IdentityMintingTextFieldHandler) mints the
 * same values per field at deserialize time, so mutations that stage into
 * tempstore without a save carry ids too.
 *
 * Only the 'value' property is minted. Summaries (text_with_summary) render
 * in teasers outside the canvas identity story: no row, no Daedalus Stylish target,
 * so ids there would be noise in teaser markup with no consumer.
 */
final class EntityIdentityMinting {

  public function __construct(
    private readonly IdentityMinter $identityMinter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Mints missing identity classes on every formatted text value.
   *
   * Walks every translation's fields that carry a text format and runs the
   * minter over each item value in place.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to mint, mutated in place.
   *
   * @return bool
   *   TRUE when any field value changed. A format that allows a vocabulary
   *   tag without class leaves that tag's elements unminted (IdentityMinter's
   *   rules); the save proceeds and the status report names the format.
   */
  public function mintEntity(FieldableEntityInterface $entity): bool {
    $changed = FALSE;
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      foreach ($translation->getFieldDefinitions() as $field_name => $definition) {
        if (!$this->carriesFormat($definition)) {
          continue;
        }
        foreach ($translation->get($field_name) as $item) {
          $value = $item->value;
          if (!is_string($value) || $value === '') {
            continue;
          }
          $format = $this->loadFormat($item->format);
          if ($format === NULL) {
            // No format (or a dangling id with no filter_format entity)
            // renders through the filter system's fallback machinery; its
            // restrictions are unknowable here, so there is nothing sound to
            // mint against. The format system owns surfacing that state.
            continue;
          }
          $minted = $this->identityMinter->mint($value, $format);
          if ($minted !== $value) {
            $item->value = $minted;
            $changed = TRUE;
          }
        }
      }
    }
    return $changed;
  }

  /**
   * Whether the field stores a text format alongside its value.
   *
   * The same predicate daedalus_blueprint's FieldHandlerBase::hasTextFormat()
   * uses (protected there, so restated): covers text, text_long,
   * text_with_summary and any custom type exposing a filter_format property.
   */
  private function carriesFormat(FieldDefinitionInterface $definition): bool {
    $properties = $definition->getFieldStorageDefinition()->getPropertyDefinitions();
    return isset($properties['format'])
      && $properties['format']->getDataType() === 'filter_format';
  }

  /**
   * Loads a filter format by id, NULL for empty or dangling ids.
   */
  private function loadFormat(?string $format_id): ?FilterFormatInterface {
    if ($format_id === NULL || $format_id === '') {
      return NULL;
    }
    $format = $this->entityTypeManager->getStorage('filter_format')->load($format_id);
    return $format instanceof FilterFormatInterface ? $format : NULL;
  }

}
