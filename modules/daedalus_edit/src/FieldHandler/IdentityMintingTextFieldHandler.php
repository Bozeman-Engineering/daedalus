<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
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
 * with s-* identity classes already in place. Vocabulary tags the format
 * allows without class are skipped here exactly as on the presave path
 * (IdentityMinter's rules); the status report names them.
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
    return new FieldHandlerResult($this->mintValue($result->getValue()), [], $result->getWarnings());
  }

  /**
   * Mints a deserialized value: a single item or an array of items.
   *
   * Mirrors the parent's validateFormats() shape handling so every item the
   * parent validates is an item this seam mints.
   */
  private function mintValue(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (isset($value['value'])) {
      return $this->mintItem($value);
    }
    foreach ($value as $delta => $item) {
      if (is_array($item) && isset($item['value'])) {
        $value[$delta] = $this->mintItem($item);
      }
    }
    return $value;
  }

  /**
   * Mints one field item's value.
   *
   * @param array $item
   *   A field item with at least 'value'.
   *
   * @return array
   *   The item with identity minted into its value.
   */
  private function mintItem(array $item): array {
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
    $item['value'] = $this->identityMinter->mint($item['value'], $format);
    return $item;
  }

}
