<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Exception\ShippedSwatchException;

/**
 * Ships site swatches to disk: the swatch kind of the shipper base.
 *
 * SwatchImporter brings MODULE.daedalus_stylish.swatches*.yml INTO a site
 * (sync); this service takes a site's swatches OUT to a module (ship),
 * writing MODULE.daedalus_stylish.swatches.FILE_ID.yml and adopting each
 * swatch in place with the written {kind, value} payload as its source
 * hash. The lane's
 * mechanics (the whole-file rewrite, the shipped-elsewhere refusal, the
 * workspace escape, the adoption) live in ShippedDefinitionsShipper; this
 * class supplies the swatch facts: definition() in SwatchImporter's
 * grammar, the keyed {kind, value} map as the hashed slice (exactly what
 * SwatchImporter::syncDefinition() stamps, so a sync right after a ship
 * reads UNCHANGED), DaedalusStylishSwatch's hash, and ShippedSwatchException.
 *
 * The distro lock guards a shipped swatch's value on a consuming site
 * (SwatchManager::checkShippedLock()); the package author works with the
 * lock lifted (the developer gate), edits the swatch in place, and this
 * lane carries the edit back to disk.
 */
class SwatchShipper extends ShippedDefinitionsShipper {

  /**
   * Builds a swatch's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, kind, description (only when set: the importer refuses an
   *   empty one), ai: false (only when withheld from the AI),
   *   design_system (always: an omitted key would mean the target
   *   module's own system on the next sync, and the swatch's membership
   *   is a fact to carry, ~ for global), then value (a CSS string or the
   *   condition-keyed map, as stored).
   */
  public function definition(DaedalusStylishSwatchInterface $swatch): array {
    $definition = [
      'label' => (string) $swatch->label(),
      'kind' => $swatch->getKind(),
    ];
    if ($swatch->getDescription() !== '') {
      $definition['description'] = $swatch->getDescription();
    }
    // The AI opt-out's one spelling: offered is the default, so the key
    // is written only to withhold (the importer refuses any other value).
    if (!$swatch->isOffered()) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $swatch->getDesignSystem();
    $definition['value'] = $swatch->getSwatchValue();
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_swatch';
  }

  /**
   * {@inheritdoc}
   *
   * The keyed {kind, value} map: what SwatchImporter stamps on sync
   * (DaedalusStylishSwatch::shippedData()). The module and the name are
   * unused: a swatch's whole payload is in its file entry.
   */
  protected function shippedData(array $definition, string $module, string $name): array {
    return [
      'kind' => $definition['kind'],
      'value' => $definition['value'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishSwatch::hashData($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSwatchException {
    return new ShippedSwatchException($module, $name, $errors);
  }

}
