<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Imports and syncs module-shipped skins: the skins disk lane.
 *
 * A module ships default skins in a MODULE.daedalus_stylish.skins.yml at its root,
 * keyed by machine name:
 * @code
 * aurora:
 *   label: 'Aurora'
 *   tokens:
 *     color:
 *       accent:
 *         default: '#6941c6'
 *         dark: '#9b7fe0'
 *     space:
 *       gutter: '2rem'
 *   defaults:
 *     'base:h2':
 *       '':
 *         default:
 *           font-size: 'var(--text-hero)'
 * @endcode
 * tokens and defaults are both optional; the token scales and base-tag
 * vocabulary the values validate against are the SITE's declaration (the
 * page_builder.yml family's site: section), so a module shipping a skin
 * couples to the theme/module declaring those scales. A token is stored
 * as a record {value, description, offered}; a hand-authored file may
 * spell the bare value (as above) or the record
 * (gutter: {value: '2rem', description: 'The band gutter.'}), and this
 * door canonicalizes (SkinTokensItem::canonicalizeTokens()) before the
 * payload is validated or hashed, so the hash the sync compares is over
 * the records the entity stores. Machine-written files (SkinShipper)
 * always hold records.
 *
 * Every definition rides the blueprint spine exactly like an AI- or
 * UI-authored skin (SkinTokensFieldHandler / SkinDefaultsFieldHandler,
 * converging on StyleMapValidator::validateTokens()/validateDefaults()),
 * and the importer saves live and published (see the base class for the
 * workspace escape and admin account rationale).
 *
 * Sync writes the PACKAGE layer only (tokens, defaults: what the file
 * ships), and hashes it: the keyed {tokens, defaults} shape
 * (DaedalusStylishSkin::shippedData()), so shipping a defaults tier later
 * reads as new defaults. The site layer (site_tokens, site_defaults) is
 * the site's own and never on this lane: an UPDATED sync rewrites the
 * package layer under it and the site's changes show through unchanged.
 * That is why sync has no KEPT outcome for skins, unlike the variants,
 * swatches and animations lanes: a consuming site cannot edit the package
 * layer (the distro lock refuses it, SkinPackageLockTrait, and this
 * importer's own writes pass shipped_sync), so there is never a site edit
 * to keep, and the one comparison is disk against the stored hash. On a
 * development site (the lock lifted) an edited package layer reads as
 * modified for the ship lane; a sync that finds new defaults on disk still
 * lands them, so ship before pulling. The importer never touches the
 * activation: which skin a site wears is the active design system's
 * default_skin (daedalus_blueprint.settings active_design_system, recipe-
 * or admin-set), a shipped skin is just available.
 */
class SkinImporter extends ShippedDefinitionsImporter {

  /**
   * Constructs the skin importer.
   *
   * The base's dependencies, plus the defaults seam: the defaults tier
   * has no blueprint write (update_default is its door), so this lane
   * validates shipped defaults itself (landDefaults()).
   *
   * @param \Drupal\daedalus_stylish\StyleMapValidator $validator
   *   The style map validator, the defaults seam.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager: compound condition keys store
   *   canonical.
   */
  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    BlueprintDeserializerInterface $deserializer,
    AdminAccountSwitcher $accountSwitcher,
    TimeInterface $time,
    ActiveDesignSystem $activeDesignSystem,
    ?WorkspaceManagerInterface $workspaceManager,
    protected StyleMapValidator $validator,
    protected ConditionTypePluginManager $conditionTypes,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $deserializer, $accountSwitcher, $time, $activeDesignSystem, $workspaceManager);
  }

  /**
   * The keys a shipped skin definition must carry.
   */
  protected const REQUIRED_KEYS = ['label'];

  /**
   * The optional definition keys: the two data tiers a skin hosts.
   */
  protected const OPTIONAL_KEYS = ['tokens', 'defaults'];

  /**
   * The optional metadata key: the membership (see the base class).
   *
   * A package's skin is a member of the package's design system like
   * every other shipped material; the system's default_skin names it.
   */
  protected const MEMBERSHIP_KEYS = ['design_system'];

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'skin';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.skins.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSkinException {
    return new ShippedSkinException($module, $name, $errors);
  }

  /**
   * The package layer a skin's provider ships right now, or NULL.
   *
   * The distro lock's disk read (SkinPackageLockTrait): a skin is locked
   * only while its disk source is LIVING. The payload as the store holds
   * it (tokens canonicalized to records), not what was shipped at the
   * last sync (the stored hash covers that).
   *
   * @return array|null
   *   The {tokens, defaults} payload, or NULL when the skin has no disk
   *   source or its provider no longer ships an entry under its machine
   *   name (sync never deletes, so such a skin lives on as ordinary site
   *   content: no shipped state, no lock).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When the shipped entry is malformed; a broken shipped file is a
   *   developer error, never skippable.
   */
  public function getShippedData(DaedalusStylishSkinInterface $skin): ?array {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      return NULL;
    }
    $name = $skin->getMachineName();
    $definitions = $this->getShippedDefinitions($provider);
    if (!array_key_exists($name, $definitions)) {
      return NULL;
    }
    $this->checkDefinitionShape($provider, $name, $definitions[$name]);
    return $this->shippedPayload($definitions[$name]);
  }

  /**
   * A definition's package layer in the entity's own keyed shape.
   *
   * DaedalusStylishSkin::shippedData(): the one hash covers both tiers,
   * tokens as the records the store holds.
   */
  protected function shippedPayload(array $definition): array {
    return [
      'tokens' => SkinTokensItem::canonicalizeTokens($definition['tokens'] ?? []),
      'defaults' => $definition['defaults'] ?? [],
    ];
  }

  /**
   * Syncs one shipped skin; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED constants: never KEPT, the
   *   package layer has no site edit to keep.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    $shipped = $this->shippedPayload($definition);

    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $skin = reset($existing) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkinInterface);
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($skin !== NULL && $skin->getProvider() !== $module) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'SHIPPED_SKIN_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $skin->getProvider() === NULL ? 'a skin created on this site' : sprintf('a skin shipped by the "%s" module', $skin->getProvider())), [], 'Machine names are the site-wide skin vocabulary; rename the shipped skin.'),
      ]);
    }

    if ($skin === NULL) {
      $skin = $this->deserialize($module, $name, [
        'entity_type' => 'daedalus_skin',
        'bundle' => 'daedalus_skin',
        'fields' => [
          'label' => $definition['label'],
          'machine_name' => $name,
          'design_system' => $design_system,
          'tokens' => $shipped['tokens'],
        ],
      ]);
      $this->landDefaults($module, $name, $skin, $shipped);
      $skin->setShippedSource($module, $shipped);
      $skin->setPublished();
      $skin->save();
      return self::CREATED;
    }

    // Membership is part of the shipped face and syncs independently of
    // the payload hash (see the base class).
    if (!$skin->hasNewDefaults($shipped)) {
      if ($this->syncMembership($skin, $design_system)) {
        $skin->save();
      }
      return self::UNCHANGED;
    }

    // New payload on disk: it lands as a new revision, whatever the entity
    // holds (see the class doc: no KEPT). Both tiers of the package layer
    // are written wholesale, so a tier the file no longer ships is
    // cleared, matching what the hash compares; the site layer is not in
    // the payload and rides the new revision untouched.
    $skin = $this->deserialize($module, $name, [
      'entity_type' => 'daedalus_skin',
      'bundle' => 'daedalus_skin',
      '_root_entity' => ['field' => 'machine_name', 'value' => $name],
      'fields' => [
        'label' => $definition['label'],
        'design_system' => $design_system,
        'tokens' => $shipped['tokens'],
      ],
    ]);
    $this->landDefaults($module, $name, $skin, $shipped);
    $skin->setNewRevision(TRUE);
    $skin->setRevisionUserId($uid);
    $skin->setRevisionCreationTime($this->time->getRequestTime());
    $skin->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $skin->setShippedSource($module, $shipped);
    $skin->setPublished();
    $skin->save();
    return self::UPDATED;
  }

  /**
   * Lands the shipped defaults tier on the skin's package layer, loudly.
   *
   * The defaults field has no blueprint write (SkinDefaultsFieldHandler is
   * the read; update_default is the site's door), so the disk lane
   * validates the shipped tier itself through the same seam
   * (StyleMapValidator::validateDefaults()), against the skin's OWN
   * shipped tokens (self-containment: a shipped skin's defaults deliver
   * only while it is active, so they resolve against its tokens, never
   * the active skin's), and writes the whole tier through the entity with
   * compound conditions canonical, as every defaults store holds them. A
   * tier the file no longer ships lands empty, matching what the hash
   * compares.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When a shipped default fails the seam; a broken shipped file is a
   *   developer error, never skippable.
   */
  protected function landDefaults(string $module, string $name, DaedalusStylishSkinInterface $skin, array $shipped): void {
    $errors = $this->validator->validateDefaults($shipped['defaults'], ['skin_tokens' => $shipped['tokens']]);
    if ($errors) {
      throw new ShippedSkinException($module, $name, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.defaults.' . $error->getPath()),
        $errors,
      ));
    }
    $skin->setPackageDefaults(array_map(
      fn (array $styles): array => $this->conditionTypes->normalizeMapConditions($styles),
      $shipped['defaults'],
    ));
  }

  /**
   * Deserializes a shipped definition through the blueprint spine, loudly.
   */
  protected function deserialize(string $module, string $name, array $blueprint): DaedalusStylishSkinInterface {
    // shipped_sync: disk is the one author the distro lock protects, so the
    // importer's own writes pass the package layer's read-only gate
    // (SkinPackageLockTrait); every other blueprint write refuses.
    // A shipped sync acts for the site (files on disk are the author), so
    // the capability gate is not asked: the system posture.
    $result = $this->deserializer->deserialize($blueprint, ['shipped_sync' => TRUE, 'system' => TRUE]);
    if (!$result->isSuccess()) {
      throw new ShippedSkinException($module, $name, $result->getErrors());
    }
    $skin = $result->getEntity();
    assert($skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Enforces the skins-file grammar: the keys and their PHP types.
   *
   * The FILE grammar only; the token VALUES are validated by the spine
   * (the tokens field handler) during deserialization, the defaults by
   * landDefaults() through the defaults seam.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is not a skin definition.', $name), [], 'Each entry maps a machine name to a label key, plus optional tokens and defaults.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS, self::MEMBERSHIP_KEYS)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped skin definition consists of label, plus optional tokens, defaults, and design_system.'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped skin definition consists of label, plus optional tokens and defaults.'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '') {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label is a non-empty string.'),
      ]);
    }
    foreach (self::OPTIONAL_KEYS as $tier) {
      if (array_key_exists($tier, $definition) && (!is_array($definition[$tier]) || (array_is_list($definition[$tier]) && $definition[$tier] !== []))) {
        throw new ShippedSkinException($module, $name, [
          new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry has the wrong shape.', $name), [], sprintf('%s, when given, is a map (%s).', $tier, $tier === 'tokens' ? '{scale: {name: value or {value, description, offered}}}' : '{scope: element-relative styles}')),
        ]);
      }
    }
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_SKIN');
  }

}
