<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

/**
 * A lane that ships a design system's members of its kinds into a module.
 *
 * The package ship (DesignSystemShip, behind
 * `drush daedalus-blueprint:design-system:ship`) writes the unit and then
 * runs every service tagged `daedalus_blueprint.package_shipper` in
 * priority order, highest first, each over the SAME module and report:
 * daedalus_blueprint_library's lane graduates the system's content
 * blueprints (priority 10, so their owned variants land in the blueprints'
 * own variants files before the materials lane reads them), and
 * daedalus_stylish's lane rebuilds the module's per-id files and adopts
 * the system's unshipped fonts, animations, swatches, skins and variants
 * (priority 0). A lane records its outcomes on the report and never
 * throws for a refusal it can name: a refused entry is a FAILED line and
 * the run goes on.
 *
 * The plan is the dry run: the same outcomes from the lane's plans, with
 * no file written and no entity touched.
 */
interface PackageShipperInterface {

  /**
   * The service tag a package shipper carries.
   */
  public const TAG = 'daedalus_blueprint.package_shipper';

  /**
   * The modules a package this lane writes into depends on.
   *
   * Listed in the info file of a module the package ship creates, in
   * info.yml's `project:module` spelling, so the package installs its
   * readers wherever it lands.
   *
   * @return string[]
   *   The dependencies.
   */
  public function packageDependencies(): array;

  /**
   * Plans the lane's part of a package ship: outcomes recorded, nothing written.
   *
   * @param \Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface $system
   *   The design system shipped.
   * @param string $module
   *   The enabled module shipped into (the system's package).
   * @param \Drupal\daedalus_blueprint\DesignSystem\ShipReport $report
   *   The run's report, this lane's outcomes recorded on it.
   */
  public function planPackage(DesignSystemInterface $system, string $module, ShipReport $report): void;

  /**
   * Ships the lane's part of a package ship.
   *
   * @param \Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface $system
   *   The design system shipped.
   * @param string $module
   *   The enabled module shipped into (the system's package).
   * @param \Drupal\daedalus_blueprint\DesignSystem\ShipReport $report
   *   The run's report, this lane's outcomes recorded on it.
   */
  public function shipPackage(DesignSystemInterface $system, string $module, ShipReport $report): void;

}
