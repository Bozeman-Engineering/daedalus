<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\filter\FilterFormatInterface;

/**
 * Mints identity classes across an entity's formatted text fields.
 *
 * The one walker behind eager identity's write paths: the
 * hook_entity_presave() backstop runs mintEntity() on every save (forms,
 * REST, migrations, blueprint persists), IdentityBackfill runs it over
 * existing content, the placement-time seam (ElementCreatedMintIdentity)
 * runs it over a freshly placed block, and the stage-time seam
 * (\Drupal\daedalus_edit\EventSubscriber\FieldAppliedMintIdentity) runs
 * mintItems() over each field the blueprint spine applies, so mutations
 * that stage into tempstore without a save carry ids too.
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
      foreach (array_keys($translation->getFieldDefinitions()) as $field_name) {
        if ($this->mintItems($translation->get($field_name))) {
          $changed = TRUE;
        }
      }
    }
    return $changed;
  }

  /**
   * Mints missing identity classes on one field's items in place.
   *
   * A field that carries no text format is left alone, so callers hand over
   * any field and let the format predicate decide.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field's live item list, mutated in place.
   *
   * @return bool
   *   TRUE when any item value changed; the same skip rule as mintEntity()
   *   for tags a format allows without class.
   */
  public function mintItems(FieldItemListInterface $items): bool {
    if (!$this->carriesFormat($items->getFieldDefinition())) {
      return FALSE;
    }
    $changed = FALSE;
    foreach ($items as $item) {
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
