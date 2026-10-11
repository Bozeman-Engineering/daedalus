<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedVariantException;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Imports and syncs module-shipped style variants: the disk lane.
 *
 * A module ships default variants in a MODULE.daedalus_stylish.variants.yml at its
 * root (next to its style guide YAML), keyed by machine name:
 * @code
 * card_left:
 *   label: 'Card left'
 *   scope: 'block_type:teaser'
 *   axis: 'style'          # optional; omitted means the built-in style axis
 *   description: 'A left-aligned card treatment for calls to action.'
 *   design_system: ~       # optional; omitted means the module's own system
 *   styles:
 *     '':
 *       default:
 *         display: 'grid'
 *     '::.teaser-text':
 *       default:
 *         align-self: 'center'
 * @endcode
 *
 * The file is a thin adapter over the blueprint spine: every definition is
 * expressed as an entity blueprint and rides daedalus_blueprint's deserializer,
 * so disk-shipped variants are validated by exactly the machinery AI-created
 * and UI-saved variants go through (the validator's address door,
 * StyleMapValidator::validateAt(), via the variant styles field
 * handler). What the spine deliberately does NOT own is the save:
 * daedalus_blueprint never saves implicitly ("the caller decides"), and this
 * importer is the caller. It saves directly, live and published, the only
 * correct semantics for shipped defaults. It never saves through
 * EntityStorageHandler::persist(), which asserts an active workspace and
 * would stage shipped defaults invisibly (unpublished workspace revisions
 * are skipped by default-variant application, so blocks would place
 * unstyled).
 *
 * Sync semantics per shipped entry ("one hash, two comparisons", see
 * DaedalusStylishVariantInterface):
 * - No variant with the machine name exists: import it.
 * - Disk styles hash equals the stored source hash: nothing shipped changed;
 *   leave the variant alone (user modifications included).
 * - Disk changed and the variant is unmodified: auto-sync the new defaults
 *   as a NEW revision (an ordinary revisioned save, reversible like any
 *   other variant edit).
 * - Disk changed but the user modified the variant: keep the user's styles
 *   and do not touch the stored hash, so the UI can keep detecting
 *   "defaults changed" and offer revert (the revert button IS the ask).
 * - Machine name collides with a variant this module does not ship: loud
 *   failure; machine names are the site-wide reference vocabulary.
 *
 * Activation runs through ShippedSync: every cache rebuild (`drush cr`),
 * module install, and `drush daedalus_stylish:sync [MODULE]` sync the variants after
 * the animations, swatches, and skins they may reference.
 */
class VariantImporter extends ShippedDefinitionsImporter {

  /**
   * The keys a shipped variant definition must carry.
   *
   * The FILE grammar (which keys exist, their PHP types) is the importer's;
   * the values (scope vocabulary, style keys, properties, constraints, the
   * axis vocabulary) are validated by the spine and the entity.
   */
  protected const REQUIRED_KEYS = ['label', 'scope', 'styles'];

  /**
   * The optional definition keys.
   *
   * Axis: the variant axis within the scope. Omitted definitions land
   * on the built-in "style" axis (DaedalusStylishVariant::preSave()).
   *
   * Blueprint: the owning blueprint's machine name (blueprint-owned
   * variants: a shipped blueprint's switchable looks ship beside it and
   * are offered only on its placements). Requires daedalus_blueprint_library
   * (the module providing the ownership fields) and resolves against the
   * site's whole blueprint vocabulary: content blueprints through the
   * entity reference lane, file-shipped blueprints through the
   * machine-name lane (see syncOwner()). A dangling reference is a loud
   * developer error, never silently dropped ownership. An absent key on
   * an update leaves stored ownership alone, exactly like axis.
   *
   * Description: when to reach for this look, the payload the style
   * materials roster serves to the AI (the schema's materials.variants). Syncs
   * like ownership, independently of the styles outcome: a description
   * added to the file later must land even when the styles never changed
   * (see syncDescription()). An absent key leaves the stored description
   * alone.
   *
   * Weight: the variant's sort weight within its family (rosters order
   * weight-then-label, see DaedalusStylishVariantInterface::getWeight()). Syncs
   * exactly like description, independently of the styles hash (see
   * syncWeight()): a weight-only disk change must reach sites without a
   * styles change. An absent key leaves the stored weight alone.
   *
   * Design system: the variant's membership (see the base class): an
   * authored id, ~ for global, absent for the module's own shipped
   * system. Syncs like description and weight, independently of the
   * styles hash, and unlike them an absent key is NOT "leave it alone"
   * but the module default, so a module gaining a design system moves
   * its shipped variants into it on the next sync.
   *
   * AI opt-out: `ai: false` keeps the variant out of the AI's roster (the
   * brief and the schema; it still applies wherever its scope fits). The
   * only spelling: offered is the default every material has, so a
   * design-system file never ships the key. Syncs like description,
   * independently of the styles hash, and like design_system an absent
   * key is the default, not "leave it alone" (see the base class's
   * syncOffered()).
   */
  protected const OPTIONAL_KEYS = ['axis', self::OWNER_KEY, 'description', 'weight', 'ai', 'design_system'];

  /**
   * The owner key: the owning blueprint's machine name.
   *
   * daedalus_blueprint_library's shipper writes it beside the blueprint
   * file; this module reads it onto the ownership fields that module
   * provides.
   */
  public const OWNER_KEY = 'blueprint';

  /**
   * The blueprint discovery service, when daedalus_blueprint_library exists.
   *
   * Deliberately untyped (its interface lives in an optional module): the
   * FILE half of the blueprint vocabulary. Content blueprints resolve
   * through entity queries; file-shipped blueprints only exist here.
   *
   * @var object|null
   */
  protected ?object $blueprintDiscovery;

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    BlueprintDeserializerInterface $deserializer,
    AdminAccountSwitcher $accountSwitcher,
    TimeInterface $time,
    ActiveDesignSystem $activeDesignSystem,
    ?WorkspaceManagerInterface $workspaceManager = NULL,
    ?object $blueprintDiscovery = NULL,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $deserializer, $accountSwitcher, $time, $activeDesignSystem, $workspaceManager);
    $this->blueprintDiscovery = $blueprintDiscovery;
  }

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'variant';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.variants.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedVariantException {
    return new ShippedVariantException($module, $name, $errors);
  }

  /**
   * Reads a shipped variant's current disk styles (the revert source).
   *
   * The read behind the shipped-state UI: the state comparisons and the
   * revert gesture both need "what does the provider ship RIGHT NOW", not
   * what was shipped at the last sync (the stored hash covers that).
   *
   * @return array|null
   *   The styles exactly as authored on disk, or NULL when the variant has
   *   no disk source or its provider no longer ships an entry under its
   *   machine name (sync never deletes, so such variants live on as ordinary
   *   site content: no shipped state, no revert).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   *   When the shipped entry is malformed; a broken shipped file is a
   *   developer error, never skippable.
   */
  public function getShippedStyles(DaedalusStylishVariantInterface $variant): ?array {
    $provider = $variant->getProvider();
    if ($provider === NULL) {
      return NULL;
    }
    $name = $variant->getMachineName();
    $definitions = $this->getShippedDefinitions($provider);
    if (!array_key_exists($name, $definitions)) {
      return NULL;
    }
    $this->checkDefinitionShape($provider, $name, $definitions[$name]);
    return $definitions[$name]['styles'];
  }

  /**
   * Syncs one shipped definition; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    $styles = $definition['styles'];

    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $variant = reset($existing) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);

    if ($variant !== NULL && $variant->getProvider() !== $module) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'SHIPPED_VARIANT_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $variant->getProvider() === NULL ? 'a variant created on this site' : sprintf('a variant shipped by the "%s" module', $variant->getProvider())), [], 'Machine names are the site-wide variant vocabulary; rename the shipped variant.'),
      ]);
    }

    // The optional axis key rides along only when authored: an absent key
    // on an update leaves the stored axis alone, exactly like any other
    // field a blueprint update does not mention.
    $axis = array_key_exists('axis', $definition) ? ['axis' => $definition['axis']] : [];
    // Membership always rides the payload: the styles field handler
    // validates in the member's universe, and disk is its author.
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($variant === NULL) {
      $this->checkSpellingCollision($module, $name, $definition);
      $variant = $this->deserialize($module, $name, [
        'entity_type' => 'daedalus_variant',
        'bundle' => 'daedalus_variant',
        'fields' => [
          'label' => $definition['label'],
          'machine_name' => $name,
          'scope' => $definition['scope'],
          'design_system' => $design_system,
          'styles' => $styles,
        ] + $axis,
      ]);
      $this->syncOwner($module, $name, $variant, $definition);
      $this->syncDescription($variant, $definition);
      $this->syncWeight($variant, $definition);
      $this->syncOffered($variant, $definition);
      $variant->setShippedSource($module, $styles);
      $variant->setPublished();
      $variant->save();
      return self::CREATED;
    }

    // Ownership, the description, the weight, and the membership sync
    // independently of the styles outcome: the source hash covers styles
    // only, and all four are part of the variant's shipped face (a
    // blueprint:, description:, weight:, or design_system: key added later
    // must land even when the styles never changed). The UNCHANGED / KEPT
    // vocabulary keeps describing the STYLES sync.
    if (!$variant->hasNewDefaults($styles)) {
      $changed = $this->syncOwner($module, $name, $variant, $definition);
      $changed = $this->syncDescription($variant, $definition) || $changed;
      $changed = $this->syncWeight($variant, $definition) || $changed;
      $changed = $this->syncOffered($variant, $definition) || $changed;
      if ($this->syncMembership($variant, $design_system) || $changed) {
        $variant->save();
      }
      return self::UNCHANGED;
    }
    if ($variant->isModified()) {
      $changed = $this->syncOwner($module, $name, $variant, $definition);
      $changed = $this->syncDescription($variant, $definition) || $changed;
      $changed = $this->syncWeight($variant, $definition) || $changed;
      $changed = $this->syncOffered($variant, $definition) || $changed;
      if ($this->syncMembership($variant, $design_system) || $changed) {
        $variant->save();
      }
      return self::KEPT;
    }

    // Unmodified variant, new defaults on disk: auto-sync as a new revision.
    // The update rides the deserializer too, the same spine validation as
    // the create, with the existing entity addressed by machine name.
    $variant = $this->deserialize($module, $name, [
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      '_root_entity' => ['field' => 'machine_name', 'value' => $name],
      'fields' => [
        'label' => $definition['label'],
        'scope' => $definition['scope'],
        'design_system' => $design_system,
        'styles' => $styles,
      ] + $axis,
    ]);
    $this->syncOwner($module, $name, $variant, $definition);
    $this->syncDescription($variant, $definition);
    $this->syncWeight($variant, $definition);
    $this->syncOffered($variant, $definition);
    $variant->setNewRevision(TRUE);
    $variant->setRevisionUserId($uid);
    $variant->setRevisionCreationTime($this->time->getRequestTime());
    $variant->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $variant->setShippedSource($module, $styles);
    $variant->setPublished();
    $variant->save();
    return self::UPDATED;
  }

  /**
   * Refuses a new definition whose spelling a published variant already owns.
   *
   * A declared axis on a tagged scope spells its variants as an attribute
   * value (DaedalusStylishMaterialsProvider::attrValue(): the machine name
   * minus the scope prefix, else the label's slug), and the materials
   * roster refuses two records spelling the same value in one slot by
   * throwing from its constructor. A variant save compiles the roster, so
   * landing such a definition would roll the save back and propagate a
   * storage fault out of the whole sync: the cache rebuild, the update
   * run. The collision is this definition's failure, not the run's: it
   * lands on the report naming the variant that owns the spelling, and
   * the rest of the file syncs. The case that meets it in practice is a
   * shipped machine-name rename (row -> columns, both labeled "Columns"):
   * the first rebuild after the code lands refuses the new name, and the
   * rename's post-update retires the old entity and syncs again.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   */
  protected function checkSpellingCollision(string $module, string $name, array $definition): void {
    $axis = $definition['axis'] ?? StyleGuide::STYLE_AXIS;
    $scope = Address::fromString($definition['scope']);
    if ($axis === StyleGuide::STYLE_AXIS || !DaedalusStylishMaterialsProvider::hasTag($scope)) {
      return;
    }
    $spelling = DaedalusStylishMaterialsProvider::attrValue($scope, $name, (string) $definition['label']);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('scope', $definition['scope'])
      ->condition('axis', $axis)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $other) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $other */
      if (DaedalusStylishMaterialsProvider::attrValue($scope, $other->getMachineName(), (string) $other->label()) !== $spelling) {
        continue;
      }
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'SHIPPED_VARIANT_SPELLING_CONFLICT', sprintf('"%s" would spell %s="%s" on %s, the spelling the published "%s" variant already owns.', $name, DaedalusStylishMaterialsProvider::axisAttr($scope, $axis), $spelling, $definition['scope'], $other->getMachineName()), ['owner' => $other->getMachineName()], 'Two variants cannot share a spelling on one axis; retire or relabel the other variant and sync again (a shipped machine-name rename does this in its post-update).'),
      ]);
    }
  }

  /**
   * Syncs a definition's blueprint: ownership onto the variant, loudly.
   *
   * Resolution runs against the site's WHOLE blueprint vocabulary, one
   * flat machine-name space across both sources (BlueprintDiscovery's
   * rule):
   * - A CONTENT blueprint owns through the `blueprint` entity reference
   *   field daedalus_blueprint_library provides on daedalus_variant
   *   (referential integrity at storage level).
   * - A FILE-shipped blueprint has no entity to reference, so it owns
   *   through the sibling `blueprint_file` machine-name field, resolved
   *   against blueprint discovery. This is what makes a cross-site package
   *   (blueprint file plus owned variants file) work: both ship as code,
   *   and the variant's owner is the file blueprint's id.
   * Setting one lane clears the other; both empty means unowned.
   *
   * Both failure modes are developer errors: shipping ownership without the
   * module that defines it, and naming a blueprint the site knows nothing
   * about in either source. The caller decides when the assignment saves.
   *
   * @return bool
   *   Whether the variant's owner changed.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   */
  protected function syncOwner(string $module, string $name, DaedalusStylishVariantInterface $variant, array $definition): bool {
    if (!array_key_exists(self::OWNER_KEY, $definition)) {
      return FALSE;
    }
    if (!$variant->hasField('blueprint')) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'SHIPPED_VARIANT_OWNER_UNAVAILABLE', sprintf('The "%s" entry declares a blueprint owner, but blueprint ownership requires the daedalus_blueprint_library module.', $name), [], 'Enable daedalus_blueprint_library, or drop the blueprint key.'),
      ]);
    }
    $owner = $definition[self::OWNER_KEY];
    $ids = $this->entityTypeManager->getStorage('blueprint')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $owner)
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      return $this->setOwnerLanes($variant, (string) reset($ids), NULL);
    }
    // No content blueprint: the FILE lane. Discovery serves published
    // content blueprints too, but those would have matched the query
    // above, so a hit here is a module-shipped file blueprint (guarded
    // anyway: 'module' is BlueprintDiscoveryInterface::SOURCE_MODULE,
    // mirrored as a literal because the interface lives in the optional
    // module).
    $discovered = $this->blueprintDiscovery?->getBlueprint($owner);
    if (is_array($discovered) && ($discovered['source'] ?? NULL) === 'module') {
      return $this->setOwnerLanes($variant, NULL, $owner);
    }
    throw new ShippedVariantException($module, $name, [
      new BlueprintError($name, 'SHIPPED_VARIANT_OWNER_MISSING', sprintf('The "%s" entry names the blueprint "%s", which is neither a content blueprint nor a module-shipped file blueprint on this site.', $name, $owner), [], 'Owned variants resolve their owner across the whole blueprint vocabulary; save (or ship) the blueprint first, or drop the blueprint key.'),
    ]);
  }

  /**
   * Syncs a definition's description onto the variant.
   *
   * Ownership's sibling: part of the shipped face, synced whenever the key
   * is authored and the stored text differs: disk is the author of a
   * shipped variant's teaching text, exactly as it is of its ownership. An
   * absent key leaves the stored description alone, so site-authored
   * descriptions on shipped variants survive as long as the file stays
   * silent. The caller decides when the assignment saves.
   *
   * @return bool
   *   Whether the description changed.
   */
  protected function syncDescription(DaedalusStylishVariantInterface $variant, array $definition): bool {
    if (!array_key_exists('description', $definition) || $variant->getDescription() === $definition['description']) {
      return FALSE;
    }
    $variant->setDescription($definition['description']);
    return TRUE;
  }

  /**
   * Syncs a definition's weight onto the variant.
   *
   * The description's pattern: part of the shipped face, synced whenever
   * the key is authored and the stored value differs, independently of the
   * styles hash (a reordered family must reach sites without a styles
   * change). An absent key leaves the stored weight alone, so
   * site-authored weights on shipped variants survive as long as the file
   * stays silent. The caller decides when the assignment saves.
   *
   * @return bool
   *   Whether the weight changed.
   */
  protected function syncWeight(DaedalusStylishVariantInterface $variant, array $definition): bool {
    if (!array_key_exists('weight', $definition) || $variant->getWeight() === $definition['weight']) {
      return FALSE;
    }
    $variant->setWeight($definition['weight']);
    return TRUE;
  }

  /**
   * Writes the two ownership lanes; setting one clears the other.
   *
   * @return bool
   *   Whether anything changed.
   */
  protected function setOwnerLanes(DaedalusStylishVariantInterface $variant, ?string $target_id, ?string $file_id): bool {
    $changed = FALSE;
    if ((string) ($variant->get('blueprint')->target_id ?? '') !== (string) ($target_id ?? '')) {
      $variant->set('blueprint', $target_id);
      $changed = TRUE;
    }
    if ((string) ($variant->get('blueprint_file')->value ?? '') !== (string) ($file_id ?? '')) {
      $variant->set('blueprint_file', $file_id);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Deserializes a shipped definition through the blueprint spine, loudly.
   */
  protected function deserialize(string $module, string $name, array $blueprint): DaedalusStylishVariantInterface {
    // shipped_sync: disk is the one author the distro lock protects, so the
    // importer's own writes pass the styles field handler's read-only gate
    // (VariantStylesFieldHandler); every other blueprint write refuses.
    // A shipped sync acts for the site (files on disk are the author), so
    // the capability gate is not asked: the system posture.
    $result = $this->deserializer->deserialize($blueprint, ['shipped_sync' => TRUE, 'system' => TRUE]);
    if (!$result->isSuccess()) {
      throw new ShippedVariantException($module, $name, $result->getErrors());
    }
    $variant = $result->getEntity();
    assert($variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * Enforces the variants-file grammar: the keys and their PHP types.
   *
   * Public because the grammar has one owner: the ship lane (VariantShipper,
   * through the shipper base) checks every definition here before writing
   * it, so disk never holds what this importer would refuse to read back.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedVariantException
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry is not a variant definition.', $name), [], 'Each entry maps a machine name to label, scope, and styles keys, plus an optional axis.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS)) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped variant definition consists of label, scope, and styles, plus an optional axis.'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped variant definition consists of label, scope, and styles, plus an optional axis.'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '' || !is_string($definition['scope']) || !is_array($definition['styles'])) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label and scope are strings; styles is a map of element-relative keys ("" for the root, "::" plus a relative selector for what is inside it).'),
      ]);
    }
    if (array_key_exists('axis', $definition) && (!is_string($definition['axis']) || trim($definition['axis']) === '')) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'axis, when given, is an axis machine name string; omit it for the built-in "style" axis.'),
      ]);
    }
    if (array_key_exists(self::OWNER_KEY, $definition) && (!is_string($definition[self::OWNER_KEY]) || trim($definition[self::OWNER_KEY]) === '')) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'blueprint, when given, is the owning blueprint\'s machine name (a content blueprint or a module-shipped file blueprint); omit it for an unowned variant.'),
      ]);
    }
    if (array_key_exists('description', $definition) && (!is_string($definition['description']) || trim($definition['description']) === '')) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'description, when given, is a non-empty string saying when to reach for this look (the AI reads it in the style guide\'s variants roster); omit it rather than shipping an empty one.'),
      ]);
    }
    if (array_key_exists('weight', $definition) && !is_int($definition['weight'])) {
      throw new ShippedVariantException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_VARIANT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'weight, when given, is an integer sort weight (rosters order weight-then-label, lighter first); omit it for the default 0.'),
      ]);
    }
    $this->checkOfferedShape($module, $name, $definition, 'INVALID_SHIPPED_VARIANT');
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_VARIANT');
  }

}
