<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The distro lock's gate on a skin's package layer, for its field handler.
 *
 * A skin's package layer (tokens, defaults) is what a module ships and
 * the payload sync hashes: on a consuming site the disk is its one author,
 * so a blueprint-lane write addressing it refuses while the distro lock
 * holds (DistroLock), exactly as the variant, swatch and animation
 * handlers refuse theirs. The site's own changes to a shipped skin live in
 * the site layer (site_tokens, site_defaults), written by the token
 * operations and update_default, so nothing a consuming site does is lost
 * to the refusal. The importer's own sync passes shipped_sync (disk is
 * the author the lock protects, so its writes are the exception, not a
 * bypass), and the developer gate lifts the lock: a development site
 * authoring the package edits its shipped skin's package layer here and
 * ships it back to disk.
 *
 * The tokens handler (SkinTokensFieldHandler) is the gate's one user now
 * that the defaults field has no blueprint write at all (update_default
 * is its door); the trait keeps the lock's one spelling and one code
 * (STYLE_SKIN_SHIPPED_LOCKED) for it. The using class
 * carries $importer (a service closure to SkinImporter, the living-source
 * read) and $distroLock.
 */
trait SkinPackageLockTrait {

  /**
   * The lock's refusal for a package-layer write, or NULL when it may land.
   *
   * Refuses only a skin with a LIVING disk source: its provider ships the
   * entry right now (SkinImporter::getShippedData()). A skin whose
   * provider stopped shipping it lives on as ordinary site content and
   * stays editable, the complement of a revert's missing-defaults case.
   *
   * @param array $options
   *   The deserializer's options: the entity being written and the
   *   shipped_sync flag the importer passes.
   * @param string $field_name
   *   The package-layer field the write addresses, for the error path.
   */
  protected function packageLockError(array $options, string $field_name): ?BlueprintError {
    $entity = $options['entity'] ?? NULL;
    if (!$this->distroLock->holds() || !$entity instanceof DaedalusStylishSkinInterface || $entity->isNew() || !empty($options['shipped_sync']) || ($this->importer)()->getShippedData($entity) === NULL) {
      return NULL;
    }
    return new BlueprintError("fields.$field_name", 'STYLE_SKIN_SHIPPED_LOCKED', sprintf('The "%s" skin is shipped by the "%s" module; a shipped skin\'s package layer is read-only.', $entity->getMachineName(), (string) $entity->getProvider()), ['provider' => (string) $entity->getProvider()], 'A consuming site changes a shipped skin in its site layer, never in the package\'s; the package\'s own values change in the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.');
  }

  /**
   * The guidance sentence naming the lock while it holds.
   *
   * On a development site (the gate lifted) a shipped skin's package
   * layer updates like any other, so the guidance says nothing about it.
   */
  protected function packageLockGuidance(string $tier): string {
    return $this->distroLock->holds()
      ? sprintf(' A module-shipped skin\'s %s are READ-ONLY here (the distro lock): updating them is refused; the site\'s changes to a shipped skin go in its site layer instead.', $tier)
      : '';
  }

}
