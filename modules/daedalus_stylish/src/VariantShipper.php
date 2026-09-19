<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\ShippedVariantException;

/**
 * Ships site variants to disk: the variant kind of the shipper base.
 *
 * VariantImporter brings MODULE.daedalus_stylish.variants*.yml INTO a site (sync);
 * this service takes a site's variants OUT to a module (ship), writing
 * MODULE.daedalus_stylish.variants.FILE_ID.yml and adopting each variant in place
 * with the written styles as its source hash. The lane's mechanics (the
 * whole-file rewrite, the shipped-elsewhere refusal, the workspace escape,
 * the adoption) live in ShippedDefinitionsShipper; this class supplies the
 * variant facts: definition() in VariantImporter's grammar, the styles as
 * the hashed slice, DaedalusStylishVariant's hash, and ShippedVariantException.
 *
 * Callers add what this module cannot know: the `stencil:` key belongs to
 * whoever owns stencils (daedalus_blueprint_stencil), not here.
 */
class VariantShipper extends ShippedDefinitionsShipper {

  /**
   * Builds a variant's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, scope, axis (only when not the built-in style axis, which an
   *   omitted key means), description and weight (only when set), ai: false
   *   (only when withheld from the AI),
   *   design_system (always: an omitted key would mean the target
   *   module's own system on the next sync, and the variant's membership
   *   is a fact to carry, ~ for global), then styles. Callers append the
   *   keys this module cannot know (stencil:).
   */
  public function definition(DaedalusStylishVariantInterface $variant): array {
    $definition = [
      'label' => (string) $variant->label(),
      'scope' => $variant->getScope(),
    ];
    if ($variant->getAxis() !== StyleGuide::STYLE_AXIS) {
      $definition['axis'] = $variant->getAxis();
    }
    if ($variant->getDescription() !== '') {
      $definition['description'] = $variant->getDescription();
    }
    if ($variant->getWeight() !== 0) {
      $definition['weight'] = $variant->getWeight();
    }
    // The AI opt-out's one spelling: offered is the default, so the key
    // is written only to withhold (the importer refuses any other value).
    if (!$variant->isOffered()) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $variant->getDesignSystem();
    $definition['styles'] = $variant->getStyles();
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_variant';
  }

  /**
   * {@inheritdoc}
   *
   * The styles alone: what VariantImporter stamps on sync.
   */
  protected function shippedData(array $definition): array {
    return $definition['styles'];
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishVariant::hashStyles($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedVariantException {
    return new ShippedVariantException($module, $name, $errors);
  }

}
