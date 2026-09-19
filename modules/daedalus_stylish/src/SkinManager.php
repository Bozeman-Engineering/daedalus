<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;

/**
 * The active skin: which skin the site wears.
 *
 * Derived, never stored: the site activates a DESIGN SYSTEM
 * (daedalus_blueprint.settings active_design_system, through
 * ActiveDesignSystem), and the active skin is that system's default_skin.
 * The ONE reader of that derivation, so no consumer (delivery, validation,
 * admin UI, AI surface) asks the design system itself. The skin is named
 * by machine name, not id: skins ship as YAML and sync across
 * environments, and machine names are the stable identity content ids
 * cannot provide.
 *
 * The activation is config while skins are content, a deliberate split
 * (decided): EDITS to the active skin are revisioned and workspace-staged
 * via the entity, while SWITCHING design systems is a live admin action,
 * like changing the default theme. Staged switching is deferred and needs
 * no re-architecture: it would wrap the activation, not replace it.
 */
class SkinManager {

  public function __construct(
    protected ActiveDesignSystem $activeDesignSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The active skin's machine name, or NULL when no skin is active.
   *
   * The active design system's default_skin; NULL with no active system
   * or a system that carries no skin (stencils and doctrine only).
   */
  public function activeSkinName(): ?string {
    $name = $this->activeDesignSystem->load()?->getDefaultSkin();
    return is_string($name) && $name !== '' ? $name : NULL;
  }

  /**
   * Loads the active skin's live revision.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface|null
   *   The active skin, or NULL when no skin is active or the active
   *   system's default_skin names a skin that does not exist (a recipe's
   *   activation can land before the skins lane syncs; a deleted skin
   *   leaves its system pointing at nothing); delivery treats both as
   *   "no skin", never an error.
   */
  public function loadActiveSkin(): ?DaedalusStylishSkinInterface {
    $name = $this->activeSkinName();
    return $name === NULL ? NULL : $this->loadByMachineName($name);
  }

  /**
   * Loads a skin by its stable machine name.
   */
  public function loadByMachineName(string $machine_name): ?DaedalusStylishSkinInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $machine_name)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $skin = $storage->load(reset($ids));
    return $skin instanceof DaedalusStylishSkinInterface ? $skin : NULL;
  }

  /**
   * The scopes the active skin carries site defaults for.
   *
   * The skin's half of the stamping demand (DefaultsStylesheet::scopes()
   * unions it with the declared tier's scopes; the stamping seams read
   * that union, never this alone). Reads resolve through
   * loadActiveSkin(), so inside a workspace the staged revision answers,
   * and the stamped markup always matches the CSS the page previews. An
   * unpublished active skin defaults nothing, matching the compiled
   * stylesheet's gate on the skin's tiers.
   *
   * @return string[]
   *   The defaults' scope strings in stored order, [] without an active
   *   published skin or defaults.
   */
  public function activeDefaultScopes(): array {
    $skin = $this->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    return array_map('strval', array_keys($skin->getDefaults()));
  }

}
