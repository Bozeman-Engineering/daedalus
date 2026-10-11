<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_blueprint\Entity\DesignSystem;

/**
 * The site's one activation: which design system is in force.
 *
 * Reads and writes daedalus_blueprint.settings active_design_system, the
 * one pointer every consumer of the unit derives from (the roster's
 * partition, the skin a package's members validate against, the law
 * stratum daedalus_ui layers). activate() is the one WRITER on the
 * op and admin lanes (the drush command and the confirm form are
 * adapters over it); ActiveDesignSystemSubscriber reacts to the config
 * save itself so drush config-set and config sync switch the site the
 * same way.
 *
 * Two readings of "package" live here on purpose. The LANDED packages
 * (packages(), packageOf()) are the design_system entities on the site
 * that name a module: an act ON a system (activate(), the uninstall
 * validator's protection) resolves against them, because there is no
 * entity to act on before it lands. The SHIPPED packages
 * (shippedPackages()) are read off disk: every installed module's
 * config/install design_system file whose package names that module. A
 * module that ships a system is a package from the moment it is
 * installed, before its config lands (a recipe installs modules first
 * and imports their config after), so every reader of a fact that ships
 * as install config resolves against the UNION of both: the membership
 * a file-shipped member carries (shippedMembership()), and the law
 * stratum that keeps a dormant package out of the site's law (the
 * declarations daedalus_ui layers).
 */
class ActiveDesignSystem {

  public const SERVICE_ID = 'daedalus_blueprint.active_design_system';
  public const CONFIG_NAME = 'daedalus_blueprint.settings';
  public const KEY = 'active_design_system';

  /**
   * Memoized shippedPackages(): id => module, or NULL before the first read.
   *
   * Per request: the set changes with the module list, and a module list
   * change rebuilds the container (and so this service); the one other
   * writer of a shipped unit inside a request, the package ship, calls
   * forgetShippedPackages() after writing the file.
   */
  protected ?array $shippedPackages = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The active design system's id, or NULL when none is active.
   */
  public function activeId(): ?string {
    $id = $this->configFactory->get(self::CONFIG_NAME)->get(self::KEY);
    return is_string($id) && $id !== '' ? $id : NULL;
  }

  /**
   * The active design system, or NULL when none is active.
   *
   * A pointer naming a system that no longer exists answers NULL too: the
   * pointer is config and the entity is config, and config sync can land
   * them in either order; the roster then keeps global materials only.
   */
  public function load(): ?DesignSystemInterface {
    $id = $this->activeId();
    return $id === NULL ? NULL : $this->storage()->load($id);
  }

  /**
   * Activates a design system by id.
   *
   * @throws \Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException
   *   When no design system has that id; the message names the known ids.
   */
  public function activate(string $id): void {
    if ($this->storage()->load($id) === NULL) {
      throw new UnknownDesignSystemException($id, $this->knownIds());
    }
    $this->configFactory->getEditable(self::CONFIG_NAME)->set(self::KEY, $id)->save();
  }

  /**
   * The membership a file-shipped member of a module carries.
   *
   * The ONE disk-lane rule, shared by every lane that reads a member off
   * a module's files (daedalus_stylish's four importers, blueprint discovery, skill
   * discovery): the definition's own `design_system` key wins; `~` (NULL)
   * opts out, a global member offered under every system; an ABSENT key
   * is the module default, the system the module packages
   * (packagedSystemOf()), or global when it packages none.
   *
   * Both branches resolve against the systems that are landed OR shipped
   * (nameableIds()), never the landed set alone: a member is read off
   * disk in the same window its system's install config is still on
   * disk (a recipe installs the module first and imports its config
   * after), and the two branches must agree in that window, or one
   * package's members would read as two memberships in one request. A
   * key naming a system nothing landed and nothing ships refuses, so a
   * package cannot silently ship members of a system it forgot to ship.
   *
   * @param string $module
   *   The shipping module.
   * @param array $definition
   *   The definition as authored (the key is read, never written).
   *
   * @return string|null
   *   The design system id, or NULL for global.
   *
   * @throws \Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException
   *   When the key names a design system that is neither landed nor
   *   shipped by an installed module; its known ids are nameableIds().
   * @throws \InvalidArgumentException
   *   When the key is neither NULL nor a non-empty string (the lanes check
   *   the shape in their own grammar first; this is the backstop).
   * @throws \LogicException
   *   When the module packages two systems (packageOf()).
   */
  public function shippedMembership(string $module, array $definition): ?string {
    if (!array_key_exists('design_system', $definition)) {
      return $this->packagedSystemOf($module);
    }
    $design_system = $definition['design_system'];
    if ($design_system === NULL) {
      return NULL;
    }
    if (!is_string($design_system) || trim($design_system) === '') {
      throw new \InvalidArgumentException('design_system is a design system id, or ~ (null) for a global member.');
    }
    $nameable = $this->nameableIds();
    if (!in_array($design_system, $nameable, TRUE)) {
      throw new UnknownDesignSystemException($design_system, $nameable);
    }
    return $design_system;
  }

  /**
   * The cache tags under which the set of design systems stays the same.
   *
   * A discovery that derives memberships through shippedMembership()
   * caches its parsed set; these tags refresh it when a system lands or
   * leaves. A shipped system's landing no longer changes an answer (the
   * rule reads landed-or-shipped), but a file may name a SITE-BUILT
   * system, one with no shipped file, and that system's deletion must
   * turn the cached membership into a refusal on the next read.
   *
   * @return string[]
   *   The design_system entity type's list cache tags.
   */
  public function packagesCacheTags(): array {
    return $this->entityTypeManager->getDefinition(DesignSystem::ENTITY_TYPE_ID)->getListCacheTags();
  }

  /**
   * The ids of every design system on the site, in id order.
   *
   * The landed set: what activate() lists when it refuses an id, since a
   * system that has not landed cannot be activated.
   *
   * @return string[]
   *   The ids.
   */
  public function knownIds(): array {
    $ids = array_keys($this->storage()->loadMultiple());
    sort($ids);
    return $ids;
  }

  /**
   * The ids a file-shipped member may name, in id order.
   *
   * The union of the landed set (knownIds()) and the shipped set
   * (shippedPackages()): what shippedMembership() resolves an explicit
   * key against, and lists when it refuses one.
   *
   * @return string[]
   *   The ids, each once.
   */
  public function nameableIds(): array {
    $ids = array_unique(array_merge($this->knownIds(), array_keys($this->shippedPackages())));
    sort($ids);
    return array_values($ids);
  }

  /**
   * The id of the design system a module packages, or NULL.
   *
   * A file-shipped member of the module belongs to this system unless it
   * opts out; a module that packages no system ships global members.
   *
   * @throws \LogicException
   *   When two design systems name the module as their package: a
   *   module packages at most one system, or its shipped members could
   *   not say which they belong to.
   */
  public function packageOf(string $module): ?string {
    $ids = array_keys(array_filter($this->packages(), static fn (string $package): bool => $package === $module));
    if (count($ids) > 1) {
      throw new \LogicException(sprintf('Module "%s" is the package of %d design systems (%s); a module packages at most one.', $module, count($ids), implode(', ', $ids)));
    }
    return $ids[0] ?? NULL;
  }

  /**
   * The design system a module packages, landed or still on disk, or NULL.
   *
   * Reads packageOf() with the shipped set (shippedPackages()) behind it:
   * the answer to "is this installed module a design-system package, and
   * of which system" in the window between a recipe installing the module
   * and importing its system's config, when the entity has not landed yet.
   * The module default shippedMembership() gives an absent key, and what
   * a lane that must NOT act on a package's members before its system is
   * active (daedalus_stylish's importers defer them) asks before reading a file.
   *
   * @throws \LogicException
   *   When two landed design systems name the module as their package
   *   (packageOf()).
   */
  public function packagedSystemOf(string $module): ?string {
    $landed = $this->packageOf($module);
    if ($landed !== NULL) {
      return $landed;
    }
    $shipped = array_search($module, $this->shippedPackages(), TRUE);
    return $shipped === FALSE ? NULL : $shipped;
  }

  /**
   * The packaged design systems: id => package module.
   *
   * @return array<string, string>
   *   Systems that name a package, in id order; UI-created systems (no
   *   package) are absent.
   */
  public function packages(): array {
    $packages = [];
    foreach ($this->storage()->loadMultiple() as $id => $system) {
      assert($system instanceof DesignSystemInterface);
      if ($system->getPackage() !== NULL) {
        $packages[$id] = $system->getPackage();
      }
    }
    ksort($packages);
    return $packages;
  }

  /**
   * The design systems installed modules ship on disk: id => package module.
   *
   * Every installed module's
   * config/install/daedalus_blueprint.design_system.*.yml whose `package`
   * names that module, whether or not the entity has landed yet. A file
   * naming another module as its package is not that module's system (a
   * carrier of someone else's config is no package) and is left out. The
   * landed set (packages()) is the truth for an act on the entity; this
   * is the truth for "which installed modules are design-system
   * packages", which a recipe install needs answered BEFORE the config
   * import that lands the entity, and the half of the membership rule
   * (shippedMembership()) that answers in that window. Memoized per
   * request; a malformed file fails loudly here rather than landing a
   * broken entity later.
   *
   * @return array<string, string>
   *   Shipped systems that name their own module as package, in id order.
   */
  public function shippedPackages(): array {
    if ($this->shippedPackages !== NULL) {
      return $this->shippedPackages;
    }
    $shipped = [];
    $pattern = 'config/install/' . $this->entityTypeManager->getDefinition(DesignSystem::ENTITY_TYPE_ID)->getConfigPrefix() . '.*.yml';
    foreach ($this->moduleHandler->getModuleDirectories() as $module => $directory) {
      foreach (glob($directory . '/' . $pattern) ?: [] as $file) {
        $definition = Yaml::decode((string) file_get_contents($file));
        if (!is_array($definition) || !isset($definition['id']) || !is_string($definition['id'])) {
          throw new \UnexpectedValueException(sprintf('%s does not declare a design system id.', $file));
        }
        if (($definition['package'] ?? NULL) === $module) {
          $shipped[$definition['id']] = $module;
        }
      }
    }
    ksort($shipped);
    return $this->shippedPackages = $shipped;
  }

  /**
   * Drops the shipped-packages memo: the next read scans disk again.
   *
   * For the package ship (DesignSystemShip), which writes a unit's
   * install config file mid-request; nothing else changes the set
   * without a container rebuild.
   */
  public function forgetShippedPackages(): void {
    $this->shippedPackages = NULL;
  }

  /**
   * The design system storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID);
  }

}
