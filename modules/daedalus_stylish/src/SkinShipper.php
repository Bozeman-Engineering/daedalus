<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;

/**
 * Ships site skins to disk: the skin kind of the shipper base.
 *
 * SkinImporter brings MODULE.daedalus_stylish.skins*.yml INTO a site (sync); this
 * service takes a site's skins OUT to a module (ship), writing
 * MODULE.daedalus_stylish.skins.FILE_ID.yml and adopting each skin in place with the
 * written {tokens, defaults} payload as its source hash. The lane's
 * mechanics (the whole-file rewrite, the shipped-elsewhere refusal, the
 * workspace escape, the adoption) live in ShippedDefinitionsShipper; this
 * class supplies the skin facts: definition() in SkinImporter's grammar,
 * the keyed {tokens, defaults} map as the hashed slice (exactly what
 * SkinImporter::syncDefinition() stamps, so a sync right after a ship
 * reads UNCHANGED), DaedalusStylishSkin's hash, and ShippedSkinException.
 *
 * Skins carry no distro lock (a site-edited shipped skin is KEPT by sync),
 * so the ship lane is the only way a site's edit of a shipped skin reaches
 * disk; the activation (which skin a site wears) is never touched.
 */
class SkinShipper extends ShippedDefinitionsShipper {

  /**
   * Builds a skin's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, design_system (always: an omitted key would mean the target
   *   module's own system on the next sync, and the skin's membership is a
   *   fact to carry, ~ for global), tokens and defaults (always, even when
   *   empty: the two tiers are what a skins file ships, and an empty map
   *   hashes exactly as an omitted tier does).
   */
  public function definition(DaedalusStylishSkinInterface $skin): array {
    return [
      'label' => (string) $skin->label(),
      'design_system' => $skin->getDesignSystem(),
      'tokens' => $skin->getTokens(),
      'defaults' => $skin->getDefaults(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_skin';
  }

  /**
   * {@inheritdoc}
   *
   * The keyed {tokens, defaults} map: what SkinImporter stamps on sync
   * (DaedalusStylishSkin::shippedData()), a missing tier reading as empty.
   */
  protected function shippedData(array $definition): array {
    return [
      'tokens' => $definition['tokens'] ?? [],
      'defaults' => $definition['defaults'] ?? [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishSkin::hashData($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSkinException {
    return new ShippedSkinException($module, $name, $errors);
  }

}
