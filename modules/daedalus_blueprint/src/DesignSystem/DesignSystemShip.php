<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;

/**
 * Ships a design system to its package module: one gesture, every lane.
 *
 * The site-to-disk direction of a whole design system, behind
 * `drush daedalus-blueprint:design-system:ship SYSTEM [MODULE]`. The
 * module is the system's package (the unit's `package`), or the one
 * named when the system has none yet; a module that does not exist is
 * created (an info file listing every lane's readers as dependencies)
 * and installed, so the package is enabled before anything ships into
 * it. Then the unit lands as install config
 * (config/install/daedalus_blueprint.design_system.ID.yml, `package` set,
 * the same record the site holds minus its uuid), and every service
 * tagged `daedalus_blueprint.package_shipper` runs over the module in
 * priority order, highest first (PackageShipperInterface names them and
 * why that order), each recording on the one ShipReport. After a ship
 * the module holds the whole system as the site had it, and a sync of
 * the module reports UNCHANGED.
 *
 * The plan is the dry run: the unit's outcome and every lane's plan on
 * the same report, nothing written. A module that is not enabled stops
 * a plan at its own line, since no lane can plan into a directory that
 * is not there yet.
 *
 * Creating the module installs it, which rebuilds the container; this
 * instance, its module handler and its shippers then predate the
 * module, so the run continues on the fresh instance read off the
 * kernel's live container (the same hand-over core's ModuleInstaller
 * makes).
 */
final class DesignSystemShip {

  public const SERVICE_ID = 'daedalus_blueprint.design_system_ship';

  /**
   * Where a created module lands, relative to the Drupal root.
   */
  public const DEFAULT_DIRECTORY = 'modules/custom';

  /**
   * The report kind of the package module's own line.
   */
  public const KIND_MODULE = 'module';

  /**
   * The report kind of the unit's line.
   */
  public const KIND_UNIT = 'design_system';

  /**
   * The module or unit file did not exist and the ship writes it.
   */
  public const CREATED = 'created';

  /**
   * The unit file exists and differs from the site's record.
   */
  public const UPDATED = 'updated';

  /**
   * The module existed on disk, disabled, and the ship installed it.
   */
  public const INSTALLED = 'installed';

  /**
   * A module name: the machine-name grammar an info file's key must meet.
   */
  protected const MODULE_NAME = '/^[a-z][a-z0-9_]*$/';

  /**
   * The lanes with their priorities, sorted highest first.
   *
   * @var array<int, array{priority: int, shipper: \Drupal\daedalus_blueprint\DesignSystem\PackageShipperInterface}>
   */
  protected array $shippers = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleList,
    protected ModuleInstallerInterface $moduleInstaller,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DrupalKernelInterface $kernel,
    protected string $root,
  ) {}

  /**
   * Collects a tagged package shipper (service_collector).
   */
  public function addShipper(PackageShipperInterface $shipper, int $priority = 0): void {
    $this->shippers[] = ['priority' => $priority, 'shipper' => $shipper];
    usort($this->shippers, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
  }

  /**
   * The lanes in the order they run, highest priority first.
   *
   * @return \Drupal\daedalus_blueprint\DesignSystem\PackageShipperInterface[]
   *   The shippers.
   */
  public function shippers(): array {
    return array_column($this->shippers, 'shipper');
  }

  /**
   * Plans a ship without touching disk or entities: the dry run's report.
   *
   * @param string $system_id
   *   The design system id.
   * @param string|null $module
   *   The module to ship into; NULL for the system's package.
   *
   * @throws \Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException
   *   When no design system has that id.
   * @throws \InvalidArgumentException
   *   When no module can be resolved (resolveModule()).
   */
  public function plan(string $system_id, ?string $module = NULL): ShipReport {
    return $this->run($system_id, $module, NULL, FALSE);
  }

  /**
   * Ships: creates and installs the module if needed, writes the unit, runs the lanes.
   *
   * @param string $system_id
   *   The design system id.
   * @param string|null $module
   *   The module to ship into; NULL for the system's package.
   * @param string|null $directory
   *   Where a created module lands, relative to the Drupal root; NULL for
   *   DEFAULT_DIRECTORY.
   *
   * @throws \Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException
   *   When no design system has that id.
   * @throws \InvalidArgumentException
   *   When no module can be resolved (resolveModule()).
   * @throws \RuntimeException
   *   When a file or directory cannot be written.
   */
  public function ship(string $system_id, ?string $module = NULL, ?string $directory = NULL): ShipReport {
    return $this->run($system_id, $module, $directory, TRUE);
  }

  /**
   * The module a system ships into, resolved and checked.
   *
   * The system's package when it has one (a system ships into its
   * package, never beside it); else the named module, which no other
   * system may package (a module packages at most one system,
   * ActiveDesignSystem::packageOf()'s rule).
   *
   * @throws \InvalidArgumentException
   *   When the system has no package and no module was named, the named
   *   module is not the system's package, another system packages the
   *   named module, or the name is not a module name.
   */
  public function resolveModule(DesignSystemInterface $system, ?string $module): string {
    $package = $system->getPackage();
    if ($module === NULL) {
      if ($package === NULL) {
        throw new \InvalidArgumentException(sprintf('The design system "%s" has no package; name the module to ship it into (it is created when it does not exist).', $system->id()));
      }
      return $package;
    }
    if ($package !== NULL && $package !== $module) {
      throw new \InvalidArgumentException(sprintf('The design system "%s" is packaged by the "%s" module; a design system ships into its package.', $system->id(), $package));
    }
    if (!preg_match(self::MODULE_NAME, $module)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a module name (lower-case letters, digits and underscores, starting with a letter).', $module));
    }
    $other = $this->activeDesignSystem->packageOf($module);
    if ($other !== NULL && $other !== $system->id()) {
      throw new \InvalidArgumentException(sprintf('The "%s" module already packages the design system "%s"; a module packages at most one.', $module, $other));
    }
    return $module;
  }

  /**
   * The unit's install config file, relative to the Drupal root.
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function unitPath(string $module, DesignSystemInterface $system): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/config/install/' . $system->getConfigDependencyName() . '.yml';
  }

  /**
   * Runs the ship; see plan() and ship().
   */
  protected function run(string $system_id, ?string $module, ?string $directory, bool $write): ShipReport {
    $system = $this->load($system_id);
    $module = $this->resolveModule($system, $module);
    $report = new ShipReport($module);
    if ($this->moduleHandler->moduleExists($module)) {
      return $this->finish($system_id, $module, $write, $report);
    }
    $exists = $this->moduleList->exists($module);
    $outcome = $exists ? self::INSTALLED : self::CREATED;
    $report->record(self::KIND_MODULE, $module, $outcome, "$module.info.yml");
    if (!$write) {
      $report->note(sprintf('%s: the module is not enabled, so the dry run stops here; the ship %s it and the package lands.', $module, $exists ? 'installs' : 'creates and installs'));
      return $report;
    }
    if (!$exists) {
      $path = $this->createModule($system, $module, $directory ?? self::DEFAULT_DIRECTORY);
      $report->note(sprintf('%s: created at %s.', $module, $path));
    }
    $this->moduleInstaller->install([$module]);
    $fresh = $this->kernel->getContainer()->get(self::SERVICE_ID);
    if (!$fresh instanceof self) {
      throw new \LogicException(sprintf('The rebuilt container serves no %s at %s.', self::class, self::SERVICE_ID));
    }
    return $fresh->finish($system_id, $module, $write, $report);
  }

  /**
   * Writes the unit and runs the lanes, on an instance that knows the module.
   */
  protected function finish(string $system_id, string $module, bool $write, ShipReport $report): ShipReport {
    $system = $this->load($system_id);
    $this->shipUnit($system, $module, $write, $report);
    foreach ($this->shippers() as $shipper) {
      $write ? $shipper->shipPackage($system, $module, $report) : $shipper->planPackage($system, $module, $report);
    }
    return $report;
  }

  /**
   * Lands the unit: `package` on the record, the record as install config.
   *
   * The file is the site's record minus its uuid (install config carries
   * none; the importing site mints its own) and minus `_core`, in the
   * config schema's key order, the module dependency `package` implies
   * included. Compared loosely with the file on disk: key order is not
   * a difference in a YAML map.
   */
  protected function shipUnit(DesignSystemInterface $system, string $module, bool $write, ShipReport $report): void {
    if ($system->getPackage() !== $module) {
      $system->set('package', $module);
      if ($write) {
        $system->save();
      }
    }
    $definition = $system->calculateDependencies()->toArray();
    unset($definition['uuid'], $definition['_core']);
    $path = $this->root . '/' . $this->unitPath($module, $system);
    $existing = is_file($path) ? Yaml::decode(file_get_contents($path)) : NULL;
    if ($existing === NULL) {
      $outcome = self::CREATED;
    }
    else {
      $outcome = $existing == $definition ? ShipReport::UNCHANGED : self::UPDATED;
    }
    if ($write && $outcome !== ShipReport::UNCHANGED) {
      $directory = dirname($path);
      if (!is_dir($directory) && !mkdir($directory, 0777, TRUE) && !is_dir($directory)) {
        throw new \RuntimeException(sprintf('The install config directory %s could not be created.', $directory));
      }
      if (file_put_contents($path, Yaml::encode($definition)) === FALSE) {
        throw new \RuntimeException(sprintf('The design system file %s could not be written.', $path));
      }
      $this->activeDesignSystem->forgetShippedPackages();
    }
    $report->record(self::KIND_UNIT, $system->id(), $outcome, basename($path));
  }

  /**
   * Creates the module on disk: its directory and info file.
   *
   * The info file names the system (its label and a description saying
   * what the package is) and depends on daedalus_blueprint and on every
   * lane's readers, so the package installs what reads its files
   * wherever it lands. Core's extension scan is forgotten and the
   * module list reset so the installer finds the new directory in this
   * same process.
   *
   * @return string
   *   The module's directory, relative to the Drupal root.
   *
   * @throws \RuntimeException
   *   When the directory or the info file cannot be written.
   */
  protected function createModule(DesignSystemInterface $system, string $module, string $directory): string {
    $relative = trim($directory, '/') . '/' . $module;
    $path = $this->root . '/' . $relative;
    if (!is_dir($path) && !mkdir($path, 0777, TRUE) && !is_dir($path)) {
      throw new \RuntimeException(sprintf('The module directory %s could not be created.', $path));
    }
    $dependencies = ['daedalus:daedalus_blueprint'];
    foreach ($this->shippers() as $shipper) {
      $dependencies = array_merge($dependencies, $shipper->packageDependencies());
    }
    $info = [
      'name' => (string) $system->label(),
      'type' => 'module',
      'description' => sprintf('The %s design system package: its unit, materials and blueprints, written by drush daedalus-blueprint:design-system:ship.', $system->label()),
      'package' => 'Design systems',
      'core_version_requirement' => '^11',
      'dependencies' => array_values(array_unique($dependencies)),
    ];
    if (file_put_contents("$path/$module.info.yml", Yaml::encode($info)) === FALSE) {
      throw new \RuntimeException(sprintf('The info file %s/%s.info.yml could not be written.', $path, $module));
    }
    ExtensionScanReset::forget();
    $this->moduleList->reset();
    return $relative;
  }

  /**
   * Loads a design system by id, loudly.
   *
   * @throws \Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException
   *   When no design system has that id; the message names the known ids.
   */
  protected function load(string $system_id): DesignSystemInterface {
    $system = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($system_id);
    if (!$system instanceof DesignSystemInterface) {
      throw new UnknownDesignSystemException($system_id, $this->activeDesignSystem->knownIds());
    }
    return $system;
  }

}
