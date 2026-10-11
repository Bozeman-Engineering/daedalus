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
 * class supplies the skin facts: definition() in SkinImporter's grammar
 * (tokens as the stored records, {value, description, offered}: a
 * machine-written file never spells the bare shorthand, so what the site
 * holds is what disk holds), the keyed {tokens, defaults} map as the
 * hashed slice (exactly what SkinImporter::syncDefinition() stamps, so a
 * sync right after a ship reads UNCHANGED), DaedalusStylishSkin's hash,
 * and ShippedSkinException.
 *
 * Only the package layer ships. The distro lock guards it on a consuming
 * site (SkinPackageLockTrait), so what such a site changes lives in the
 * skin's site layer (site_tokens, site_defaults), which is the site's own
 * and never reaches disk. The package author works with the lock lifted
 * (the developer gate): the package layer is edited in place, reads as
 * modified against the disk, and this lane carries it back; sync then
 * reads the shipped file as UNCHANGED. The activation (which skin a site
 * wears) is never touched.
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
      'tokens' => $skin->packageTokens(),
      'defaults' => $skin->packageDefaults(),
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
  protected function shippedData(array $definition, string $module, string $name): array {
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
