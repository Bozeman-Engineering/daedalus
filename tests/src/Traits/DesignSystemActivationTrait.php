<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;

/**
 * Activates a skin, or sets the law's enforcement, the way a site does.
 *
 * Both are facts of the active design system: the active skin is derived
 * (the system's default_skin) and the enforcement mode is the system's
 * property (enforce()), so a test that wants either activates a system
 * carrying it. A test with no system of its own gets one created in code
 * (named after the skin, or `site` for a bare mode carrier; no package),
 * which is what a site builder creating "the Aurora system" in the UI
 * produces; the skin itself stays global unless the test made it a
 * member.
 */
trait DesignSystemActivationTrait {

  /**
   * Activates the design system whose default skin is the given skin.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface|string $skin
   *   The skin, or its machine name (it need not exist yet: a recipe's
   *   activation lands before the skins lane syncs).
   * @param string|null $design_system
   *   The system to carry it, created when missing; NULL names it after
   *   the skin.
   *
   * @return string
   *   The activated design system's id.
   */
  protected function activateSkin(DaedalusStylishSkinInterface|string $skin, ?string $design_system = NULL): string {
    $name = $skin instanceof DaedalusStylishSkinInterface ? $skin->getMachineName() : $skin;
    $id = $design_system ?? $name;
    $storage = \Drupal::entityTypeManager()->getStorage(DesignSystem::ENTITY_TYPE_ID);
    $system = $storage->load($id) ?? $storage->create(['id' => $id, 'label' => $id]);
    $system->set('default_skin', $name)->save();
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate($id);
    return $id;
  }

  /**
   * Deactivates the design system: no system, no skin.
   */
  protected function deactivateDesignSystem(): void {
    \Drupal::configFactory()->getEditable(ActiveDesignSystem::CONFIG_NAME)->set(ActiveDesignSystem::KEY, NULL)->save();
  }

  /**
   * Puts the site's law at an enforcement mode the way a site does.
   *
   * Enforcement is the active design system's property
   * (DesignSystemInterface::getEnforcement(); StyleGuide::enforcement()
   * reads it there and answers guarded with no system active), so the
   * mode is written on the active system. A test with no system yet gets
   * a bare site-built one (id `site`: no skin, no package, so no law
   * layers and no members sync) activated first, which is what a site
   * builder creating a system in the UI and picking a mode produces.
   * The mode is the system's, not the site's: a test that activates
   * ANOTHER system afterwards reads that system's mode, so call this
   * after the activation it means to govern (or ship the mode in the
   * fixture's install config, as the aurora package does).
   *
   * @param string $mode
   *   One of the DesignSystemInterface::ENFORCEMENT_* modes.
   *
   * @return string
   *   The design system id the mode was written on.
   */
  protected function enforce(string $mode): string {
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    $system = $active->load();
    if ($system === NULL) {
      $storage = \Drupal::entityTypeManager()->getStorage(DesignSystem::ENTITY_TYPE_ID);
      $system = $storage->load('site') ?? $storage->create(['id' => 'site', 'label' => 'Site']);
      $system->save();
      $active->activate('site');
    }
    $system->set('enforcement', $mode)->save();
    return $system->id();
  }

}
