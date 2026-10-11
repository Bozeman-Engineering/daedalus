<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Dependency;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\DesignSystem\PackageShipperInterface;
use Drupal\daedalus_blueprint\DesignSystem\ShipReport;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_library\Exception\BlueprintShipException;
use Drupal\file\FileInterface;

/**
 * Ships a saved blueprint to a module as a blueprint file plus its variants.
 *
 * The site-to-disk direction of the blueprint lane, the one command behind
 * "this blueprint I composed on its canvas should live in a module now":
 * name the blueprint and a module, and the ship writes the file and the
 * variants file from the blueprint's own document and leaves the site
 * consistent. Disk to site stays the importers' sync.
 *
 * The blueprint is its own source. A saved blueprint is a host like a
 * page, so the shipped definition is the host's built tree
 * (NodeTreeBuilder::build()) with identity and provenance dropped and the
 * references stripped (reference targets are site-local ids that
 * sample-populate at placement), plus the node-less style keys of its
 * style map (the document's statements about the page root, re-addressed
 * to the bare root key: a file has no host). The face (label, description,
 * icon, the AI opt-out, the design system, scope and subject) is the
 * entity's; --label and --description override the two words an author
 * may want to polish on the way out.
 *
 * Every ship GRADUATES the blueprint: a file and a content blueprint
 * sharing an id is a loud discovery error the site cannot rebuild through
 * (Blueprint::preSave() refuses the collision from the content side, so a
 * content blueprint never has a file under its id anywhere), so once the
 * file is written the entity is deleted and its owned variants are
 * adopted in place, never deleted and recreated: style maps may reference
 * variants by uuid and the usage table keys on them, so each entity keeps
 * its id and uuid and only its ownership lane flips from the content
 * reference (`blueprint`) to the file lane (`blueprint_file` = ID).
 *
 * The variant roster is ownership first, wearing second: every variant the
 * blueprint owns on either lane ships (worn or not, ownership is
 * curation), plus the unowned site-created variants the document wears,
 * which ship unowned (said out loud). Variants another module ships are
 * skipped with a warning when the target's info.yml does not list that
 * module, as are variants another blueprint owns (ship that blueprint).
 *
 * The blueprint file is `MODULE.daedalus_blueprint.blueprints.ID.yml` (the
 * discovery envelope, BlueprintDiscovery::DEFINITION_KEYS); the variants
 * ride daedalus_stylish's `MODULE.daedalus_stylish.variants.ID.yml` through
 * its variant shipper, reached by optional injection since daedalus_stylish
 * never learns about blueprints and this module never requires
 * daedalus_stylish. This module adds only the `blueprint:` owner key.
 *
 * The icon carries over as a plan outcome. A blueprint's icon is a media
 * reference (the icon value grammar's user lane); a file blueprint's icon
 * is a file inside the module, so the ship COPIES the media's source file
 * (IconResolver::sourceFile, the one media-to-file step) to
 * `assets/blueprints/ID.<ext>` and writes the `icon:` key naming it. The
 * media entity is left alone: it is site content that may be used
 * elsewhere. A blueprint without an icon, or one whose media resolves to
 * no file (said in a warning), ships NONE. The AI opt-out rides the same
 * way: a withheld blueprint ships `ai: false` (the key's one spelling; an
 * offered one ships no key), and so do the groups (written only when the
 * blueprint is filed under any) and the site favorite (`favorite: true`
 * only for a favorite). The membership is always written, NULL for
 * global (a file that says nothing would take the module default at
 * discovery, and a shipped fact should not depend on where the file
 * lands).
 *
 * After a ship the site is consistent: discovery serves the file with the
 * module as provider, the ownership lanes point at the file, the content
 * blueprint is gone, every placement keeps its provenance stamp (the id
 * did not change), and a variants sync right after reports UNCHANGED. A
 * shipped file is read-only on the site; to revise it, edit the file by
 * hand or save a new blueprint and ship it.
 *
 * The same ship is the blueprint lane of the package ship
 * (PackageShipperInterface, behind `daedalus-blueprint:design-system:ship`):
 * every CONTENT blueprint that is a member of the shipped design system
 * graduates into the module, with its owned variants.
 */
class BlueprintShipper implements PackageShipperInterface {

  /**
   * The report kind of a blueprint line.
   */
  public const KIND = 'blueprint';

  /**
   * The report kind of an icon line.
   */
  public const KIND_ICON = 'icon';

  /**
   * A blueprint id: the file name suffix's grammar.
   */
  protected const ID = '/^[a-z0-9_]+$/';

  /**
   * The blueprint file's outcome: a ship always creates it.
   *
   * A content blueprint never shares its id with a file (the entity's
   * presave and discovery both refuse the collision), so the file a ship
   * writes never exists yet; one on disk anyway is refused before anything
   * is written.
   */
  public const CREATED = 'created';

  /**
   * The blueprint's icon media is copied into the module.
   */
  public const ICON_COPIED = 'copied';

  /**
   * The blueprint ships without an icon (the palette renders its default).
   */
  public const ICON_NONE = 'none';

  /**
   * The module-relative directory copied icons land in.
   */
  public const ICON_DIRECTORY = 'assets/blueprints';

  /**
   * The owner key this module adds to a shipped variant definition.
   */
  public const VARIANT_OWNER_KEY = 'blueprint';

  /**
   * Field types pruned from block_fields when a tree ships to disk.
   *
   * Reference targets are site-local IDs that cannot ship in a file, and
   * an absent field sample-populates at placement. Content blueprints
   * saved on a site keep their references; only the ship lane strips.
   */
  protected const REFERENCE_FIELD_TYPES = [
    'entity_reference',
    'entity_reference_revisions',
    'image',
    'file',
  ];

  /**
   * The daedalus_stylish variant shipper, when daedalus_stylish is installed.
   *
   * Deliberately untyped (its class lives in the optional daedalus_stylish
   * module, and container compilation reflects every service class): the
   * disk lane's variant half. Without it the ship writes the blueprint
   * file alone.
   *
   * @var object|null
   */
  protected ?object $variantShipper;

  public function __construct(
    protected NodeTreeBuilder $nodeTreeBuilder,
    protected HostResolver $hostResolver,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected BlueprintManager $manager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleList,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IconResolver $iconResolver,
    protected FileSystemInterface $fileSystem,
    ?object $variantShipper = NULL,
  ) {
    $this->variantShipper = $variantShipper;
  }

  /**
   * Plans a ship without touching disk or entities: the dry run's report.
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The content blueprint, as saved (a freshly loaded entity's host reads
   *   the saved document, never an editor's staged canvas).
   * @param string $module
   *   The target module; must be enabled (it has a directory to ship into).
   * @param array $options
   *   Ship options:
   *   - label (string): the shipped label, overriding the blueprint's.
   *   - description (string): the shipped description, overriding the
   *     blueprint's.
   *
   * @return \Drupal\daedalus_blueprint_library\ShipPlan
   *   The plan.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintShipException
   *   When the module is not enabled, the machine name is not a blueprint
   *   id, the module already holds a file under the id, the document is
   *   empty, or the label or description is empty.
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   *   When a variant definition fails the variants disk lane's shape rule.
   */
  public function plan(BlueprintInterface $blueprint, string $module, array $options = []): ShipPlan {
    $this->checkModule($module);
    $id = $this->checkId($blueprint->getMachineName());
    if (is_file($this->filePath($module, $id))) {
      throw new BlueprintShipException([
        new BlueprintError('id', 'BLUEPRINT_SHIP_FILE_EXISTS', sprintf('The "%s" module already holds a file for the blueprint "%s".', $module, $id), ['file' => BlueprintDiscovery::fileName($module, $id)], 'Blueprint ids are site-wide vocabulary and a file is read-only on the site: edit that file by hand, or rename the blueprint and ship it under its new id.'),
      ]);
    }

    $host = $this->hostResolver->fromEntity($blueprint);
    $tree = $this->nodeTreeBuilder->build($host);
    if ($tree === []) {
      throw new BlueprintShipException([
        new BlueprintError('layout', 'BLUEPRINT_SHIP_EMPTY', sprintf('The "%s" blueprint has an empty document; there is nothing to ship.', $id), [], 'Compose the blueprint on its canvas first: a blueprint file holds at least one root container.'),
      ]);
    }
    $layout = $this->stripReferences(BlueprintLayout::withoutProvenance(BlueprintLayout::withoutUuids($tree)));
    $styles = $host->hasStyleStore() ? $this->fileStyles($blueprint, NodeTreeBuilder::nodelessStyles($tree, $host->styleMap())) : [];

    $definition = [
      'label' => trim((string) ($options['label'] ?? $blueprint->label())),
      'description' => trim((string) ($options['description'] ?? $blueprint->getDescription())),
    ];
    $errors = [];
    if ($definition['label'] === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A blueprint needs a label.', [], 'Name the blueprint the way editors should find it in the palette.');
    }
    if ($definition['description'] === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A blueprint needs a description.', [], 'Describe what the blueprint is for; the palette and the AI both read it.');
    }
    if ($errors !== []) {
      throw new BlueprintShipException($errors);
    }

    // The envelope in discovery's key order: label, description, icon, ai,
    // design_system, groups, favorite, type, scope, subject, layout,
    // styles. The optional keys have one spelling each: an icon key only
    // for a copied icon, ai: false only when withheld, groups only when
    // the blueprint is filed under any, favorite: true only for a site
    // favorite, type only for a type other than the default (section),
    // styles only when the document says something about its root; the
    // membership is always written, NULL for global.
    $warnings = [];
    [$icon, $icon_path, $icon_source] = $this->planIcon($id, $blueprint, $warnings);
    if ($icon_path !== NULL) {
      $definition['icon'] = $icon_path;
    }
    if (!$blueprint->isOffered()) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $blueprint->getDesignSystem();
    if (($groups = $blueprint->getGroups()) !== []) {
      $definition['groups'] = $groups;
    }
    if ($blueprint->isFavorite()) {
      $definition['favorite'] = TRUE;
    }
    if ($blueprint->getType() !== BlueprintInterface::TYPE_SECTION) {
      $definition['type'] = $blueprint->getType();
    }
    $definition['scope'] = $blueprint->getScope();
    $definition['subject'] = $blueprint->getSubjectType() . ':' . $blueprint->getSubjectBundle();
    $definition['layout'] = $layout;
    if ($styles !== []) {
      $definition['styles'] = $styles;
    }

    [$variants, $outcomes, $skipped] = $this->planVariants($module, $id, $blueprint, $layout, $warnings);

    return new ShipPlan($module, $id, $definition, $icon, $icon_source, $blueprint, $variants, $outcomes, $skipped, $warnings);
  }

  /**
   * Ships: writes the files, graduates the blueprint, adopts the variants.
   *
   * The plan is rebuilt from the live site (the report a caller confirmed
   * seconds ago is the same plan; a stale one is never executed), then
   * executed; see execute() for the order and why.
   *
   * @return \Drupal\daedalus_blueprint_library\ShipPlan
   *   The executed plan (see plan()).
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintShipException
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   * @throws \RuntimeException
   *   When the blueprint file cannot be written.
   * @throws \Drupal\Core\File\Exception\FileException
   *   When the icon cannot be copied into the module.
   */
  public function ship(BlueprintInterface $blueprint, string $module, array $options = []): ShipPlan {
    return $this->execute($this->plan($blueprint, $module, $options));
  }

  /**
   * {@inheritdoc}
   */
  public function packageDependencies(): array {
    return ['daedalus:daedalus_blueprint_library'];
  }

  /**
   * {@inheritdoc}
   */
  public function planPackage(DesignSystemInterface $system, string $module, ShipReport $report): void {
    $this->runPackage($system, $module, $report, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function shipPackage(DesignSystemInterface $system, string $module, ShipReport $report): void {
    $this->runPackage($system, $module, $report, TRUE);
  }

  /**
   * The blueprint file's content as the ship writes it: the header, the YAML.
   *
   * The dry run prints exactly this, so what an author reads on the
   * terminal is byte for byte what the real run writes. The header names
   * the writer and says how the file is revised (by hand, or by a new
   * blueprint shipped under a new id), since the blueprint that produced
   * it no longer exists on the site.
   */
  public function fileContents(ShipPlan $plan): string {
    $header = sprintf("# Machine-written by daedalus_blueprint_library (BlueprintShipper): the \"%s\"\n# blueprint of the \"%s\" module, shipped from the content blueprint of the\n# same id, which graduated (deleted on the site) as this file was written.\n# A shipped blueprint is read-only on a site: edit this file by hand, or save\n# a new blueprint and ship it under its own id.\n", $plan->id, $plan->module);
    return $header . Yaml::encode($plan->definition);
  }

  /**
   * The blueprint file a ship writes, relative to the Drupal root.
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function filePath(string $module, string $id): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/' . BlueprintDiscovery::fileName($module, $id);
  }

  /**
   * A blueprint file's icon on disk, relative to the Drupal root.
   *
   * @param string $module
   *   The shipping module.
   * @param string $icon
   *   The file's icon key: a path relative to the module (the file grammar
   *   discovery enforces).
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function iconFilePath(string $module, string $icon): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/' . $icon;
  }

  /**
   * Graduates every content blueprint of the system into the module.
   *
   * One plan per member blueprint in machine-name order, published or not
   * (a file serves as soon as it exists; the package is the developer's
   * whole system). A refusal the lane can name (the shipper's own, or the
   * variants disk lane's) fails that blueprint's line and the next
   * blueprint still ships.
   */
  protected function runPackage(DesignSystemInterface $system, string $module, ShipReport $report, bool $write): void {
    foreach ($this->memberBlueprints($system->id()) as $blueprint) {
      $id = $blueprint->getMachineName();
      try {
        $plan = $write ? $this->ship($blueprint, $module) : $this->plan($blueprint, $module);
      }
      catch (MaterialGestureException $e) {
        // Meaningful recovery: the blueprint is refused whole and reported
        // (a malformed id, an empty document, a variant the lane refuses);
        // the system's other blueprints still ship.
        $report->fail(self::KIND, $id, $e->getErrors());
        continue;
      }
      $this->record($report, $plan);
    }
  }

  /**
   * Records a plan on the package report, in the ship command's lines.
   *
   * The blueprint file's outcome, the icon's when there is one, each
   * variant's outcome against the blueprint's variants file, then the
   * skipped variants, the graduation and every warning as notes.
   */
  protected function record(ShipReport $report, ShipPlan $plan): void {
    $report->record(self::KIND, $plan->id, self::CREATED, BlueprintDiscovery::fileName($plan->module, $plan->id));
    if ($plan->icon !== self::ICON_NONE) {
      $report->record(self::KIND_ICON, $plan->id, $plan->icon, (string) $plan->definition['icon']);
    }
    $variants_file = sprintf('%s.daedalus_stylish.variants.%s.yml', $plan->module, $plan->id);
    foreach ($plan->variantOutcomes as $name => $outcome) {
      $report->record('variant', (string) $name, $outcome, $variants_file);
    }
    foreach ($plan->skipped as $name => $reason) {
      $report->note(sprintf('%s: variant %s skipped: %s', $plan->module, $name, $reason));
    }
    $report->note(sprintf('%s: blueprint %s graduates: the content blueprint "%s" (%s) is deleted after the ship, its owned variants adopted in place', $plan->module, $plan->id, $plan->blueprint->label(), $plan->blueprint->id()));
    foreach ($plan->warnings as $warning) {
      $report->note(sprintf('Warning: %s', $warning));
    }
  }

  /**
   * The content blueprints that are members of a design system.
   *
   * @return \Drupal\daedalus_blueprint_library\BlueprintInterface[]
   *   In machine-name order, published or not.
   */
  protected function memberBlueprints(string $design_system): array {
    $storage = $this->entityTypeManager->getStorage(BlueprintInterface::ENTITY_TYPE_ID);
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('design_system', $design_system)
      ->sort('machine_name')
      ->execute();
    $blueprints = [];
    foreach ($storage->loadMultiple($ids) as $blueprint) {
      assert($blueprint instanceof BlueprintInterface);
      $blueprints[] = $blueprint;
    }
    return $blueprints;
  }

  /**
   * Executes a plan: writes the files, graduates the blueprint, adopts the variants.
   *
   * The order keeps every step recoverable and the site rebuildable at
   * every point: the icon copied first (discovery validates at rebuild
   * that the file an icon key names exists, so the asset must be on disk
   * before the yml that names it); then the blueprint file (the likeliest
   * failure is a write permission, so it fails before anything on the site
   * moved; a file beside a still-existing content blueprint is a loud
   * state one file removal undoes); then the content blueprint deleted,
   * BEFORE any variant is saved: a variant save compiles the materials
   * roster, which reads discovery, and a rebuild between the file write
   * and the delete would see the file and the entity collide. From here
   * discovery serves the file. Then the variants through daedalus_stylish's
   * shipper (file written, provider and source hash stamped, published)
   * and the owned variants' ownership lane flipped to the file (between
   * the delete and the flip an owned variant's content reference dangles,
   * which reads as unowned for the moment), and the discovery cache tag
   * invalidated for the written file.
   */
  protected function execute(ShipPlan $plan): ShipPlan {
    if ($plan->iconSource !== NULL) {
      $this->copyIcon($plan);
    }
    $this->writeFile($plan);
    $plan->blueprint->delete();

    if ($this->variantShipper !== NULL) {
      $this->variantShipper->ship($plan->module, $plan->id, $plan->variants);
      foreach ($plan->variants as $name => $definition) {
        if (!isset($definition[self::VARIANT_OWNER_KEY])) {
          continue;
        }
        $variant = $this->loadVariant((string) $name);
        if ($variant !== NULL && $this->setFileOwner($variant, $plan->id)) {
          $variant->save();
        }
      }
    }

    Cache::invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    return $plan;
  }

  /**
   * Refuses a module that is not enabled: there is no directory to ship into.
   */
  protected function checkModule(string $module): void {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new BlueprintShipException([
        new BlueprintError('module', 'BLUEPRINT_SHIP_MODULE_UNKNOWN', sprintf('The "%s" module is not enabled, so there is no directory to ship into.', $module), [], 'Name an enabled module; the blueprint file and its variants file land at its root.'),
      ]);
    }
  }

  /**
   * Refuses an id that is not a blueprint id (the file name suffix's grammar).
   */
  protected function checkId(string $id): string {
    if (!preg_match(self::ID, $id)) {
      throw new BlueprintShipException([
        new BlueprintError('id', 'BLUEPRINT_SHIP_INVALID_ID', sprintf('"%s" is not a blueprint id.', $id), [], 'Blueprint ids are lower-case letters, digits, and underscores (the file name suffix).'),
      ]);
    }
    return $id;
  }

  /**
   * The node-less styles as a file spells them: the root key bare.
   *
   * A saved blueprint's node-less keys address its own root
   * (`entity:<uuid>` and its rule forms, the capture's re-addressing of
   * the source page's root); a file has no host, so its root key is bare
   * (`entity:`), the spelling discovery's envelope reads, and a placement
   * re-addresses it to the destination the way a capture did.
   */
  protected function fileStyles(BlueprintInterface $blueprint, array $styles): array {
    $map = [];
    foreach ($styles as $key => $entry) {
      $map[str_replace($blueprint->uuid(), '', (string) $key)] = $entry;
    }
    return $map;
  }

  /**
   * Plans the icon: copied from the blueprint's media, or none.
   *
   * The blueprint's media resolves to a file through
   * IconResolver::sourceFile(), the one media-to-file step, and the copy
   * takes the source file's extension (`assets/blueprints/ID.<ext>`).
   *
   * @param string $id
   *   The blueprint id (the copied icon's file name).
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The blueprint, whose icon media is the source.
   * @param string[] $warnings
   *   The plan's warnings, appended.
   *
   * @return array{0: string, 1: string|null, 2: \Drupal\file\FileInterface|null}
   *   The outcome (ICON_COPIED, ICON_NONE), the module-relative icon path
   *   the file's icon key names (NULL for none), and the file to copy.
   */
  protected function planIcon(string $id, BlueprintInterface $blueprint, array &$warnings): array {
    $media = $blueprint->getIcon();
    if ($media === NULL) {
      return [self::ICON_NONE, NULL, NULL];
    }
    $file = $this->iconResolver->sourceFile($media);
    if ($file === NULL) {
      $warnings[] = sprintf('The blueprint\'s icon media "%s" (%s) references no file, so the blueprint ships without an icon; add an icon key to the shipped file by hand, naming a file inside the module.', $media->label(), $media->id());
      return [self::ICON_NONE, NULL, NULL];
    }
    $extension = pathinfo((string) $file->getFileUri(), PATHINFO_EXTENSION) ?: pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION);
    return [self::ICON_COPIED, self::ICON_DIRECTORY . '/' . $id . ($extension !== '' ? '.' . $extension : ''), $file];
  }

  /**
   * Plans the variants half: the roster, the definitions, the outcomes.
   *
   * @param string $module
   *   The target module.
   * @param string $id
   *   The blueprint id (the per-id variants file and the owner key).
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The blueprint, whose owned variants are the roster.
   * @param array $layout
   *   The shipped layout, whose wearings name the unowned candidates.
   * @param string[] $warnings
   *   The plan's warnings, appended.
   *
   * @return array{0: array, 1: array, 2: array}
   *   The definitions to ship keyed by machine name, the variant shipper's
   *   outcomes per name, and the skipped names with their reasons.
   */
  protected function planVariants(string $module, string $id, BlueprintInterface $blueprint, array $layout, array &$warnings): array {
    if ($this->variantShipper === NULL || !$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      return [[], [], []];
    }

    $roster = $this->ownedVariants($id, $blueprint);
    $owned = array_fill_keys(array_keys($roster), TRUE);
    $skipped = [];
    foreach (BlueprintLayout::referencedVariants($layout) as $reference) {
      $variant = $this->loadVariant($reference);
      if ($variant === NULL) {
        $warnings[] = sprintf('The blueprint wears the variant "%s", which is not a saved variant on this site; a shipped copy would place with a dangling reference.', $reference);
        continue;
      }
      $name = $variant->getMachineName();
      if (isset($roster[$name])) {
        continue;
      }
      $owner = $this->manager->variantOwnershipPayload($variant)['blueprint'];
      if ($owner !== NULL || !$variant->get(BlueprintInterface::OWNER_FIELD)->isEmpty() || (string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '') !== '') {
        $skipped[$name] = $owner !== NULL
          ? sprintf('owned by the "%s" blueprint; ship that blueprint to take it along', $owner)
          : 'owned by a blueprint that no longer exists; release it to ship it';
        continue;
      }
      $roster[$name] = $variant;
    }

    $definitions = [];
    foreach ($roster as $name => $variant) {
      $definition = $this->variantShipper->definition($variant);
      if (isset($owned[$name])) {
        $styles = $definition['styles'];
        unset($definition['styles']);
        $definition[self::VARIANT_OWNER_KEY] = $id;
        $definition['styles'] = $styles;
      }
      $definitions[$name] = $definition;
    }

    $outcomes = $this->variantShipper->plan($module, $id, $definitions);
    $dependencies = $this->dependencies($module);
    foreach ($outcomes as $name => $outcome) {
      if ($outcome !== $this->variantShipper::SHIPPED_ELSEWHERE) {
        continue;
      }
      $provider = $roster[$name]->getProvider();
      if ($provider !== NULL && $provider !== $module) {
        $skipped[$name] = sprintf('shipped by the "%s" module', $provider);
        if (!in_array($provider, $dependencies, TRUE)) {
          $warnings[] = sprintf('The blueprint wears the "%s" variant shipped by the "%s" module, which %s.info.yml does not list as a dependency; add it so the variant exists wherever the blueprint is installed.', $name, $provider, $module);
        }
      }
      else {
        $skipped[$name] = sprintf('already shipped by another file of the "%s" module; give it its %s: key there by hand', $module, self::VARIANT_OWNER_KEY);
      }
      unset($definitions[$name], $outcomes[$name]);
    }
    foreach (array_keys($definitions) as $name) {
      if (!isset($owned[$name])) {
        $warnings[] = sprintf('The "%s" variant ships unowned: the blueprint wears it, but no blueprint owns it, so it stays offered on every matching element.', $name);
      }
    }
    return [$definitions, $outcomes, $skipped];
  }

  /**
   * The variants the blueprint owns, on either lane.
   *
   * The ownership question asked by id: the content lane (the entity
   * reference) and the file lane (the id itself, when a synced variants
   * file wrote it ahead of the entity). Worn or not: ownership is
   * curation.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface>
   *   The daedalus_variant entities keyed by machine name, label-sorted.
   */
  protected function ownedVariants(string $id, BlueprintInterface $blueprint): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()
      ->condition(BlueprintInterface::FILE_OWNER_FIELD, $id)
      ->condition(BlueprintInterface::OWNER_FIELD, $blueprint->id()));
    $query->sort('label');
    $roster = [];
    foreach ($storage->loadMultiple($query->execute()) as $variant) {
      $roster[$variant->getMachineName()] = $variant;
    }
    return $roster;
  }

  /**
   * Points a variant's ownership at the file lane, clearing the content one.
   *
   * The graduation's lane flip, this module's own write (daedalus_stylish's
   * importer keeps the same rule protected for its sync): one lane set at
   * a time, the other cleared.
   *
   * @return bool
   *   Whether anything changed.
   */
  protected function setFileOwner(ContentEntityInterface $variant, string $id): bool {
    $changed = FALSE;
    if (!$variant->get(BlueprintInterface::OWNER_FIELD)->isEmpty()) {
      $variant->set(BlueprintInterface::OWNER_FIELD, NULL);
      $changed = TRUE;
    }
    if ((string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '') !== $id) {
      $variant->set(BlueprintInterface::FILE_OWNER_FIELD, $id);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Copies the blueprint's icon file into the module.
   *
   * The destination is the path the plan's icon key names; an asset an
   * earlier ship left behind is replaced (the key names one file).
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   When the directory cannot be prepared or the copy fails.
   */
  protected function copyIcon(ShipPlan $plan): void {
    assert($plan->iconSource instanceof FileInterface && isset($plan->definition['icon']));
    $destination = $this->iconFilePath($plan->module, $plan->definition['icon']);
    $directory = dirname($destination);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new FileException(sprintf('The icon directory %s could not be created.', $directory));
    }
    $this->fileSystem->copy((string) $plan->iconSource->getFileUri(), $destination, FileExists::Replace);
  }

  /**
   * Writes the blueprint file (see fileContents()).
   */
  protected function writeFile(ShipPlan $plan): void {
    $path = $this->filePath($plan->module, $plan->id);
    if (file_put_contents($path, $this->fileContents($plan)) === FALSE) {
      throw new \RuntimeException(sprintf('The blueprint file %s could not be written.', $path));
    }
  }

  /**
   * The module names a module's info.yml lists as dependencies.
   *
   * @return string[]
   *   Bare module names (the project prefix and version constraints
   *   dropped).
   */
  protected function dependencies(string $module): array {
    $info = $this->moduleList->getExtensionInfo($module);
    return array_map(
      static fn (string $dependency): string => Dependency::createFromString($dependency)->getName(),
      $info['dependencies'] ?? [],
    );
  }

  /**
   * Loads a variant by machine name, then uuid, uncached.
   *
   * The one reference vocabulary the style map speaks (VariantRepository's
   * order); uncached because the ship saves what it loads.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The daedalus_variant entity (duck-typed: daedalus_stylish is optional
   *   here), or NULL.
   */
  protected function loadVariant(string $reference): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $matches = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $variant = reset($matches) ?: NULL;
    return $variant instanceof ContentEntityInterface ? $variant : NULL;
  }

  /**
   * Prunes the reference fields from a tree, recursively.
   *
   * The disk-shipping strategy: every inline block's reference-typed
   * fields (read off the block type's field definitions) leave its
   * block_fields, a bag left empty losing the key.
   *
   * @param array $nodes
   *   Lean nodes.
   *
   * @return array
   *   The same nodes without reference fields.
   */
  protected function stripReferences(array $nodes): array {
    return array_map(function ($node): mixed {
      if (!is_array($node)) {
        return $node;
      }
      if (($node['kind'] ?? NULL) === 'block' && isset($node['block_fields']) && is_array($node['block_fields']) && is_string($node['block_type'] ?? NULL)) {
        $definitions = $this->entityFieldManager->getFieldDefinitions('block_content', $node['block_type']);
        foreach (array_keys($node['block_fields']) as $field_name) {
          if (isset($definitions[$field_name]) && in_array($definitions[$field_name]->getType(), self::REFERENCE_FIELD_TYPES, TRUE)) {
            unset($node['block_fields'][$field_name]);
          }
        }
        if ($node['block_fields'] === []) {
          unset($node['block_fields']);
        }
      }
      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = $this->stripReferences($node['children']);
      }
      return $node;
    }, array_values($nodes));
  }

}
