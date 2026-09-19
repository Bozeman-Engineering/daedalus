<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\filter\FilterFormatInterface;

/**
 * Supersedes daedalus_blueprint's text handler to mint identity at stage time.
 *
 * Blueprint mutations (AI tools, the endpoint verbs) stage field values into
 * tempstore without an entity save, so the presave backstop never runs and
 * staged content would render identity-less until its first real save: no
 * rows in the markup view, nothing for Daedalus Stylish to target. This handler is
 * the stage-time seam: a higher-priority daedalus_blueprint.field_handler
 * registration over the same applies() as the parent, minting after the
 * parent's format validation, so whatever the parent would stage, it stages
 * with s-* identity classes already in place.
 *
 * A format that cannot carry identity (allows a vocabulary tag but strips
 * its class attribute) surfaces as a blueprint error failing the mutation:
 * the stage-path twin of the presave backstop letting IdentityMinter's
 * LogicException fly. Config errors surface; they are not worked around.
 */
final class IdentityMintingTextFieldHandler extends TextFieldHandler {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected IdentityMinter $identityMinter,
  ) {
    parent::__construct($entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $result = parent::doDeserialize($value, $definition, $options);
    if ($result->hasErrors()) {
      return $result;
    }
    $errors = [];
    $value = $this->mintValue($result->getValue(), $definition, $errors);
    return new FieldHandlerResult($value, $errors, $result->getWarnings());
  }

  /**
   * Mints a deserialized value: a single item or an array of items.
   *
   * Mirrors the parent's validateFormats() shape handling so every item the
   * parent validates is an item this seam mints.
   */
  private function mintValue(mixed $value, FieldDefinitionInterface $definition, array &$errors): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (isset($value['value'])) {
      return $this->mintItem($value, $definition, $errors);
    }
    foreach ($value as $delta => $item) {
      if (is_array($item) && isset($item['value'])) {
        $value[$delta] = $this->mintItem($item, $definition, $errors);
      }
    }
    return $value;
  }

  /**
   * Mints one field item's value.
   *
   * @param array $item
   *   A field item with at least 'value'.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition, for the error path.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] &$errors
   *   Errors collection to append to.
   *
   * @return array
   *   The item with identity minted into its value.
   */
  private function mintItem(array $item, FieldDefinitionInterface $definition, array &$errors): array {
    if (!is_string($item['value']) || $item['value'] === '' || empty($item['format'])) {
      return $item;
    }
    // The parent already validated the format (fallback for unknown ids,
    // 'use' permission). An id that still fails to load had no fallback and
    // will not render, so there is nothing sound to mint against.
    $format = $this->entityTypeManager->getStorage('filter_format')->load($item['format']);
    if (!$format instanceof FilterFormatInterface) {
      return $item;
    }
    try {
      $item['value'] = $this->identityMinter->mint($item['value'], $format);
    }
    catch (\LogicException $exception) {
      $errors[] = new BlueprintError(
        path: 'fields.' . $definition->getName(),
        code: 'IDENTITY_UNMINTABLE_FORMAT',
        message: $exception->getMessage(),
        hint: 'Allow the class attribute on every vocabulary tag the format allows (e.g. <p class>), or use a format configured for identity.',
      );
    }
    return $item;
  }

}
