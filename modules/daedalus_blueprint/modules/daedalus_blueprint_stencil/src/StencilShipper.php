<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\Dependency;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\ElementContext;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_stencil\Exception\StencilShipException;
use Drupal\file\FileInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Ships a placed element to a module as a stencil file plus its variants.
 *
 * The site-to-disk direction of the stencil lane, the one command behind
 * "this thing I built on the canvas should live in a module now": point it
 * at a placement, name a module, and it works out whether the stencil and
 * its variants exist anywhere, then writes or updates them on disk and
 * leaves the site consistent. Disk to site stays the importers' sync.
 *
 * Identity comes from the placement, never from a label: the provenance
 * stamp instantiate_stencil wrote on the placed root (a section root's
 * third-party settings, a block root's additional data) names the stencil
 * the placement came from, an explicit id overrides it, and without either
 * there is nothing to match "exists anywhere" against, so the ship
 * refuses (a label-derived id could land silently on someone else's
 * stencil).
 *
 * Existence is answered across the whole vocabulary (stencil ids are one
 * flat site-wide name space, StencilDiscovery's rule):
 * - a file in the target module: the ship updates it;
 * - a file in another module: refused, the id is that module's;
 * - a content stencil: the ship GRADUATES it, since a file and a content
 *   stencil sharing an id is a loud discovery error the site cannot rebuild
 *   through. The content stencil is deleted and its owned variants are
 *   adopted in place, never deleted and recreated: style maps may reference
 *   variants by uuid and the usage table keys on them, so the entity keeps
 *   its id and uuid and only its ownership lane flips from the content
 *   reference to the file lane (stencil_file = ID);
 * - nothing: the ship creates the file.
 *
 * The variant roster is ownership first, wearing second: every variant the
 * source stencil owns on either lane ships (worn or not, ownership is
 * curation), plus the unowned site-created variants the subtree wears,
 * which ship unowned (said out loud). Variants another module ships are
 * skipped with a warning when the target's info.yml does not list that
 * module, as are variants another stencil owns (ship that stencil).
 *
 * The stencil file is `MODULE.daedalus_blueprint.stencils.ID.yml` (the
 * discovery grammar, references stripped: their targets are site-local
 * ids that sample-populate at placement); the variants ride daedalus_stylish's
 * `MODULE.daedalus_stylish.variants.ID.yml` through its variant shipper, reached by
 * optional injection since daedalus_stylish never learns about stencils and this
 * module never requires daedalus_stylish. This module adds only the `stencil:` key.
 *
 * The icon carries over as a plan outcome. A graduating content stencil's
 * icon is a media reference (the icon value grammar's user lane); a file
 * stencil's icon is a file inside the module, so the ship COPIES the
 * media's source file (IconResolver::sourceFile, the one media-to-file
 * step) to `assets/stencils/ID.<ext>` and writes the `icon:` key naming
 * it. The media entity is left alone: it is site content that may be used
 * elsewhere. A ship with no icon source (a re-ship from the placement, the
 * file stencil being the source now) KEEPS the existing file's icon key,
 * the way it keeps the label and description; a stencil without an icon,
 * or one whose media resolves to no file (said in a warning), ships NONE.
 * The AI opt-out rides the same way: a withheld graduating stencil ships
 * `ai: false` (the key's one spelling; an offered one ships no key), and
 * a re-ship keeps the file's key.
 *
 * After a ship the site is consistent: the variants are already stamped
 * with the module as provider, the ownership lanes point at the file, the
 * content stencil is gone, and the discovery cache tag is invalidated, so
 * no sync is needed and a sync right after reports UNCHANGED.
 */
class StencilShipper {

  /**
   * The stencil file did not exist and the ship writes it.
   */
  public const CREATED = 'created';

  /**
   * The stencil file exists in the target module and differs.
   */
  public const UPDATED = 'updated';

  /**
   * The stencil file exists in the target module and already matches.
   */
  public const UNCHANGED = 'unchanged';

  /**
   * The graduating stencil's icon media is copied into the module.
   */
  public const ICON_COPIED = 'copied';

  /**
   * The existing file's icon key survives (no icon source this ship).
   */
  public const ICON_KEPT = 'kept';

  /**
   * The stencil ships without an icon (the palette renders its default).
   */
  public const ICON_NONE = 'none';

  /**
   * The module-relative directory copied icons land in.
   */
  public const ICON_DIRECTORY = 'assets/stencils';

  /**
   * The daedalus_stylish variant shipper, when daedalus_stylish is installed.
   *
   * Deliberately untyped (its class lives in the optional daedalus_stylish module,
   * and container compilation reflects every service class): the disk
   * lane's variant half. Without it the ship writes the stencil file alone.
   *
   * @var object|null
   */
  protected ?object $variantShipper;

  public function __construct(
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected StencilExporterInterface $exporter,
    protected StencilManager $manager,
    protected StencilDiscoveryInterface $discovery,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleList,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IconResolver $iconResolver,
    protected FileSystemInterface $fileSystem,
    protected ActiveDesignSystem $activeDesignSystem,
    ?object $variantShipper = NULL,
  ) {
    $this->variantShipper = $variantShipper;
  }

  /**
   * Plans a ship without touching disk or entities: the dry run's report.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The host entity carrying the placement (the map host: its
   *   daedalus_stylish_styles map holds the subtree's styles).
   * @param string $uuid
   *   The placed root's uuid: a section (top-level band or nested) or an
   *   inline-block component.
   * @param string $module
   *   The target module; must be enabled (it has a directory to ship into).
   * @param array $options
   *   Ship options:
   *   - id (string): the stencil id, overriding the provenance stamp.
   *   - label (string): the stencil label. Defaults to the graduating
   *     content stencil's label, then the existing file's, then the
   *     element's display label.
   *   - description (string): the stencil description. Defaults the same
   *     way, ending at the exporter's placeholder.
   *
   * @return \Drupal\daedalus_blueprint_stencil\ShipPlan
   *   The plan.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilShipException
   *   When the module is not enabled, the element is not a placement, no
   *   identity can be established, the id is malformed, the label or
   *   description is empty, or another module already ships the id.
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilExportException
   *   When the subtree cannot be captured at all (the exporter's refusals).
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   *   When a variant definition fails the variants disk lane's shape rule.
   */
  public function plan(FieldableEntityInterface $entity, string $uuid, string $module, array $options = []): ShipPlan {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new StencilShipException([
        new BlueprintError('module', 'STENCIL_SHIP_MODULE_UNKNOWN', sprintf('The "%s" module is not enabled, so there is no directory to ship into.', $module), [], 'Name an enabled module; the stencil file and its variants file land at its root.'),
      ]);
    }

    $context = $this->elementLocator->locate($entity, $uuid);
    if ($context === NULL || (!$context->isLbComponent() && !$context->isSection())) {
      throw new StencilShipException([
        new BlueprintError('element', 'STENCIL_SHIP_ELEMENT_NOT_FOUND', sprintf('No Layout Builder element "%s" exists on %s %s.', $uuid, $entity->getEntityTypeId(), $entity->id() ?? $entity->uuid()), [], 'Ship a placed section or inline-block component by its uuid. The command reads the SAVED page, so an unsaved canvas change is not visible to it: save the page first.'),
      ]);
    }
    $id = $this->identity($context, $options['id'] ?? NULL);

    $graduating = $this->loadContentStencil($id);
    $path = $this->filePath($module, $id);
    $existing = is_file($path) ? Yaml::decode(file_get_contents($path)) : NULL;
    $existing = is_array($existing) ? $existing : NULL;
    // Discovery is the vocabulary's truth and loud on collisions (a file and
    // a content stencil sharing the id), exactly where a ship must not
    // quietly add a second file to a broken site.
    $discovered = $this->discovery->getStencil($id);
    if ($discovered !== NULL && $discovered['source'] === StencilDiscoveryInterface::SOURCE_MODULE && $discovered['provider'] !== $module) {
      throw new StencilShipException([
        new BlueprintError('id', 'STENCIL_SHIP_SHIPPED_ELSEWHERE', sprintf('The stencil "%s" is already shipped by the "%s" module.', $id, $discovered['provider']), ['provider' => $discovered['provider']], 'Stencil ids are site-wide vocabulary: ship into the module that owns the id, or ship under another --id.'),
      ]);
    }

    $export_options = ['strip_references' => TRUE];
    $label = $options['label'] ?? ($graduating !== NULL ? (string) $graduating->label() : ($existing['label'] ?? NULL));
    if ($label !== NULL) {
      $export_options['label'] = $label;
    }
    $description = $options['description'] ?? ($graduating?->getDescription() ?? ($existing['description'] ?? NULL));
    if ($description !== NULL) {
      $export_options['description'] = $description;
    }
    $export = $this->exporter->export($entity, $uuid, $export_options);
    $definition = [
      'label' => trim((string) $export->definition['label']),
      'description' => trim((string) $export->definition['description']),
    ];
    $errors = [];
    if ($definition['label'] === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A stencil needs a label.', [], 'Name the stencil the way editors should find it in the palette.');
    }
    if ($definition['description'] === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A stencil needs a description.', [], 'Describe what the stencil is for; the palette and the AI both read it.');
    }
    if ($errors !== []) {
      throw new StencilShipException($errors);
    }
    $warnings = $export->warnings;

    // The envelope's key order: label, description, icon, ai,
    // design_system, element. The AI opt-out has one spelling, ai: false,
    // written only when withheld (discovery refuses any other value):
    // the graduating stencil's flag, else the existing file's key (kept
    // like the label and description), else offered, the default every
    // material has. The membership is always written, NULL for global (a file
    // that says nothing would take the module default at discovery, and a
    // shipped fact should not depend on where the file lands): the
    // graduating stencil's, else the existing file's (kept like the label
    // and description), else the target module's own packaged system, the
    // membership a placement with no stencil behind it would resolve to.
    [$icon, $icon_path, $icon_source] = $this->planIcon($id, $graduating, $existing, $warnings);
    if ($icon_path !== NULL) {
      $definition['icon'] = $icon_path;
    }
    $offered = $graduating !== NULL ? $graduating->isOffered() : ($existing['ai'] ?? TRUE) !== FALSE;
    if (!$offered) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $graduating !== NULL
      ? $graduating->getDesignSystem()
      : ($existing !== NULL && array_key_exists('design_system', $existing) ? $existing['design_system'] : $this->activeDesignSystem->packageOf($module));
    $definition['element'] = $export->definition['element'];

    if ($existing === NULL) {
      $stencil = self::CREATED;
    }
    else {
      // Loose on purpose: key order is not a difference in a YAML map.
      $stencil = $existing == $definition ? self::UNCHANGED : self::UPDATED;
    }

    [$variants, $outcomes, $skipped] = $this->planVariants($module, $id, $graduating, $definition['element'], $warnings);

    return new ShipPlan($module, $id, $definition, $stencil, $icon, $icon_source, $graduating, $variants, $outcomes, $skipped, $warnings);
  }

  /**
   * Ships: writes the files, adopts the variants, graduates the stencil.
   *
   * The plan is rebuilt from the live site (the report a caller confirmed
   * seconds ago is the same plan; a stale one is never executed), then
   * executed in the order that keeps every step recoverable: the icon
   * copied first (discovery validates at rebuild that the file an icon
   * key names exists, so the asset must be on disk before the yml that
   * names it), then the stencil file (the likeliest failure is a write
   * permission, and a file beside a still-existing content stencil is a
   * loud state one file removal undoes), the variants through daedalus_stylish's
   * shipper (file written, provider and source hash stamped, published),
   * the owned variants' ownership lane flipped to the file, the content
   * stencil deleted, and the discovery cache tag invalidated so the file
   * is served at once.
   *
   * @return \Drupal\daedalus_blueprint_stencil\ShipPlan
   *   The executed plan (see plan()).
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilShipException
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilExportException
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   * @throws \RuntimeException
   *   When the stencil file cannot be written.
   * @throws \Drupal\Core\File\Exception\FileException
   *   When the icon cannot be copied into the module.
   */
  public function ship(FieldableEntityInterface $entity, string $uuid, string $module, array $options = []): ShipPlan {
    $plan = $this->plan($entity, $uuid, $module, $options);

    if ($plan->iconSource !== NULL) {
      $this->copyIcon($plan);
    }
    if ($plan->stencil !== self::UNCHANGED) {
      $this->writeFile($plan);
    }

    if ($this->variantShipper !== NULL) {
      $this->variantShipper->ship($module, $plan->id, $plan->variants);
      foreach ($plan->variants as $name => $definition) {
        if (!isset($definition['stencil'])) {
          continue;
        }
        $variant = $this->loadVariant((string) $name);
        if ($variant !== NULL && $this->setFileOwner($variant, $plan->id)) {
          $variant->save();
        }
      }
    }

    // Graduation: the delete hook invalidates the discovery tag too, but a
    // create or update writes a file no hook sees, so invalidate here in
    // every case, AFTER the content stencil is gone (a rebuild in between
    // would see the collision).
    $plan->graduating?->delete();
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);

    return $plan;
  }

  /**
   * The stencil file a ship writes, relative to the Drupal root.
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function filePath(string $module, string $id): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/' . StencilDiscovery::fileName($module, $id);
  }

  /**
   * A stencil file's icon on disk, relative to the Drupal root.
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
   * The stencil id a placement ships under: stamp, override, else refusal.
   *
   * The stamp is read where instantiate_stencil wrote it: a section root's
   * third-party settings, a block root's additional data, and for a
   * container component (one identity with its embedded section) the
   * component's stamp first, then the embedded section's, the same
   * precedence the serializer and the ownership checker walk.
   */
  protected function identity(ElementContext $context, ?string $override): string {
    $stamp = NULL;
    if ($context->isSection()) {
      $section = $context->target;
      assert($section instanceof Section);
      $stamp = $this->manager->placementProvenance($section);
    }
    else {
      $component = $context->target;
      assert($component instanceof SectionComponent);
      $stamp = $this->manager->placementProvenance($component);
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      if ($stamp === NULL && $embedded !== NULL) {
        $stamp = $this->manager->placementProvenance($embedded);
      }
    }

    $id = $override !== NULL && trim($override) !== '' ? trim($override) : $stamp;
    if ($id === NULL) {
      throw new StencilShipException([
        new BlueprintError('id', 'STENCIL_SHIP_IDENTITY_MISSING', 'The element carries no stencil provenance, so there is nothing to match against the stencils that exist.', [], 'Ship a placement that was instantiated from a stencil, or pass --id to name the stencil id explicitly.'),
      ]);
    }
    if (!preg_match('/^[a-z0-9_]+$/', $id)) {
      throw new StencilShipException([
        new BlueprintError('id', 'STENCIL_SHIP_INVALID_ID', sprintf('"%s" is not a stencil id.', $id), [], 'Stencil ids are lower-case letters, digits, and underscores (the file name suffix).'),
      ]);
    }
    return $id;
  }

  /**
   * Plans the icon: copied from the graduating stencil's media, kept, or none.
   *
   * The graduating content stencil is the only icon source a ship has (a
   * placement carries no icon; the file stencil a re-ship reads from
   * already holds its key). Its media resolves to a file through
   * IconResolver::sourceFile(), the one media-to-file step, and the copy
   * takes the source file's extension (`assets/stencils/ID.<ext>`).
   *
   * @param string $id
   *   The stencil id (the copied icon's file name).
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface|null $graduating
   *   The graduating content stencil, whose icon media is the source.
   * @param array|null $existing
   *   The decoded existing stencil file, whose icon key is kept when there
   *   is no source.
   * @param string[] $warnings
   *   The plan's warnings, appended.
   *
   * @return array{0: string, 1: string|null, 2: \Drupal\file\FileInterface|null}
   *   The outcome (ICON_COPIED, ICON_KEPT, ICON_NONE), the module-relative
   *   icon path the file's icon key names (NULL for none), and the file to
   *   copy (copied only).
   */
  protected function planIcon(string $id, ?StencilInterface $graduating, ?array $existing, array &$warnings): array {
    $media = $graduating?->getIcon();
    if ($media !== NULL) {
      $file = $this->iconResolver->sourceFile($media);
      if ($file === NULL) {
        $warnings[] = sprintf('The content stencil\'s icon media "%s" (%s) references no file, so the stencil ships without an icon; add an icon key to the shipped file by hand, naming a file inside the module.', $media->label(), $media->id());
        return [self::ICON_NONE, NULL, NULL];
      }
      $extension = pathinfo((string) $file->getFileUri(), PATHINFO_EXTENSION) ?: pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION);
      return [self::ICON_COPIED, self::ICON_DIRECTORY . '/' . $id . ($extension !== '' ? '.' . $extension : ''), $file];
    }
    if (isset($existing['icon']) && is_string($existing['icon'])) {
      return [self::ICON_KEPT, $existing['icon'], NULL];
    }
    return [self::ICON_NONE, NULL, NULL];
  }

  /**
   * Plans the variants half: the roster, the definitions, the outcomes.
   *
   * @param string $module
   *   The target module.
   * @param string $id
   *   The stencil id (the per-id variants file and the `stencil:` owner).
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface|null $graduating
   *   The graduating content stencil, whose content-lane owned variants
   *   join the roster.
   * @param array $element
   *   The captured element, whose wearings name the unowned candidates.
   * @param string[] $warnings
   *   The plan's warnings, appended.
   *
   * @return array{0: array, 1: array, 2: array}
   *   The definitions to ship keyed by machine name, the variant shipper's
   *   outcomes per name, and the skipped names with their reasons.
   */
  protected function planVariants(string $module, string $id, ?StencilInterface $graduating, array $element, array &$warnings): array {
    if ($this->variantShipper === NULL || !$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      return [[], [], []];
    }

    $roster = $this->ownedVariants($id, $graduating);
    $owned = array_fill_keys(array_keys($roster), TRUE);
    $skipped = [];
    foreach ($this->exporter->referencedVariants($element) as $reference) {
      $variant = $this->loadVariant($reference);
      if ($variant === NULL) {
        $warnings[] = sprintf('The subtree wears the variant "%s", which is not a saved variant on this site; a shipped copy would place with a dangling reference.', $reference);
        continue;
      }
      $name = $variant->getMachineName();
      if (isset($roster[$name])) {
        continue;
      }
      $owner = $this->manager->variantOwnershipPayload($variant)['stencil'];
      if ($owner !== NULL || !$variant->get('stencil')->isEmpty() || (string) ($variant->get('stencil_file')->value ?? '') !== '') {
        $skipped[$name] = $owner !== NULL
          ? sprintf('owned by the "%s" stencil; ship that stencil to take it along', $owner)
          : 'owned by a stencil that no longer exists; release it to ship it';
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
        $definition['stencil'] = $id;
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
          $warnings[] = sprintf('The stencil wears the "%s" variant shipped by the "%s" module, which %s.info.yml does not list as a dependency; add it so the variant exists wherever the stencil is installed.', $name, $provider, $module);
        }
      }
      else {
        $skipped[$name] = sprintf('already shipped by another file of the "%s" module; give it its stencil: key there by hand', $module);
      }
      unset($definitions[$name], $outcomes[$name]);
    }
    foreach (array_keys($definitions) as $name) {
      if (!isset($owned[$name])) {
        $warnings[] = sprintf('The "%s" variant ships unowned: the subtree wears it, but no stencil owns it, so it stays offered on every matching element.', $name);
      }
    }
    return [$definitions, $outcomes, $skipped];
  }

  /**
   * The variants the source stencil owns, on either lane.
   *
   * The ownership question asked by id: the content lane (the graduating
   * stencil's entity reference) and the file lane (the id itself, when a
   * previous ship or a synced variants file wrote it). Not
   * StencilManager::ownedVariants(), which needs the content entity a
   * file-owned roster does not have. Worn or not: ownership is curation.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface>
   *   The daedalus_variant entities keyed by machine name, label-sorted.
   */
  protected function ownedVariants(string $id, ?StencilInterface $graduating): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $query = $storage->getQuery()->accessCheck(FALSE);
    $group = $query->orConditionGroup()->condition('stencil_file', $id);
    if ($graduating !== NULL) {
      $group->condition('stencil', $graduating->id());
    }
    $query->condition($group)->sort('label');
    $roster = [];
    foreach ($storage->loadMultiple($query->execute()) as $variant) {
      $roster[$variant->getMachineName()] = $variant;
    }
    return $roster;
  }

  /**
   * Points a variant's ownership at the file lane, clearing the content one.
   *
   * The graduation's lane flip, the stencil module's own write (daedalus_stylish's
   * importer keeps the same rule protected for its sync): one lane set at
   * a time, the other cleared.
   *
   * @return bool
   *   Whether anything changed.
   */
  protected function setFileOwner(ContentEntityInterface $variant, string $id): bool {
    $changed = FALSE;
    if (!$variant->get('stencil')->isEmpty()) {
      $variant->set('stencil', NULL);
      $changed = TRUE;
    }
    if ((string) ($variant->get('stencil_file')->value ?? '') !== $id) {
      $variant->set('stencil_file', $id);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Copies the graduating stencil's icon file into the module.
   *
   * The destination is the path the plan's icon key names; an asset a
   * previous graduation left behind is replaced (the key names one file).
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
   * Writes the stencil file.
   *
   * A header names the writer so a reader knows a later ship of the same
   * placement rewrites the file; the label, description, icon, AI opt-out
   * and design system survive such a rewrite (they default to the file's
   * own), so editing them in place is safe.
   */
  protected function writeFile(ShipPlan $plan): void {
    $path = $this->filePath($plan->module, $plan->id);
    $header = sprintf("# Machine-written by daedalus_blueprint_stencil (StencilShipper): the \"%s\"\n# stencil of the \"%s\" module. Rewritten whole by the next ship of a\n# placement carrying this id; the label, description, icon, ai and\n# design system are kept.\n", $plan->id, $plan->module);
    if (file_put_contents($path, $header . Yaml::encode($plan->definition)) === FALSE) {
      throw new \RuntimeException(sprintf('The stencil file %s could not be written.', $path));
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
   * Loads a content stencil by machine name, any publication state.
   */
  protected function loadContentStencil(string $id): ?StencilInterface {
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $id)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $stencil = $storage->load(reset($ids));
    return $stencil instanceof StencilInterface ? $stencil : NULL;
  }

  /**
   * Loads a variant by machine name, then uuid, uncached.
   *
   * The one reference vocabulary the style map speaks (VariantRepository's
   * order); uncached because the ship saves what it loads.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The daedalus_variant entity (duck-typed: daedalus_stylish is optional here), or
   *   NULL.
   */
  protected function loadVariant(string $reference): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $matches = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $variant = reset($matches) ?: NULL;
    return $variant instanceof ContentEntityInterface ? $variant : NULL;
  }

}
