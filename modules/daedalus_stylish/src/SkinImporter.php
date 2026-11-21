<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Imports and syncs module-shipped skins: the skins disk lane.
 *
 * A module ships default skins in a MODULE.daedalus_stylish.skins.yml at its
 * root, keyed by machine name:
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
 *   animations:
 *     fade_in:
 *       description: 'The quietest entrance.'
 *       steps:
 *         from: { opacity: '0' }
 *         to: { opacity: '1' }
 *   fonts:
 *     inter:
 *       family: 'Inter'
 *       description: 'The body face.'
 *       faces:
 *         - weight: 400
 *           style: normal
 *           source: { file: 'inter-regular.woff2' }   # assets/fonts/inter-regular.woff2
 *         - weight: 700
 *           source: { file: 'inter-bold.woff2' }
 *   defaults:
 *     'base:h2':
 *       '':
 *         default:
 *           font-size: 'var(--text-hero)'
 * @endcode
 * tokens, animations, fonts and defaults are all optional; the token
 * scales and base-tag vocabulary the values validate against are the
 * SITE's declaration (the page_builder.yml family's site: section), so a
 * module shipping a skin couples to the theme/module declaring those
 * scales. A token is stored as a record {value, description, offered}, an
 * animation as {steps, description, offered} and a font as {family, faces,
 * description, offered}; a hand-authored file may spell the bare value or
 * steps map, or the record (gutter: {value: '2rem', description: 'The
 * section gutter.'}), and this door canonicalizes
 * (SkinTokensItem::canonicalizeTokens(),
 * SkinAnimationsItem::canonicalizeAnimations(): offsets settle too,
 * SkinFontsItem::canonicalizeFonts(): faces settle) before the payload is
 * validated or hashed, so the hash the sync compares is over the records
 * the entity stores. Machine-written files (SkinShipper) always hold
 * records. An animation's name is the keyframes ident tail
 * (motion-{name}), so it must fit SkinAnimationsItem::NAME; a font's name
 * is its roster id (SkinFontsItem::NAME).
 *
 * A font's face names its file with one difference of medium: on disk
 * `file` names a basename under the module's assets/fonts/ (a module has
 * no managed files), on the site it names the managed file's uuid
 * (FontFiles). The importer crosses that gap (landFonts()): it lands each
 * face's bytes through FontFiles::fromBytes() (the daedalus_ui file lane
 * narrowed to woff2 and woff, held by the skin for its life)
 * and rewrites the source's `file` from the basename to the uuid; the
 * shipper (SkinShipper) does the reverse. A face's file the module does
 * not hold is a developer error (SHIPPED_FONT_FILE_MISSING), never
 * skippable. The hash covers the fonts tier CONTENT-ADDRESSED
 * (shippedFonts(): each face's source {hash: sha256 of the asset}, the
 * shape DaedalusStylishSkin::shippedData() computes from the site's
 * files), since neither the uuid nor the basename crosses the lane: a
 * face whose bytes changed on disk reads as new defaults and lands as a
 * new file (a face whose bytes did not change keeps the file it has).
 *
 * Every definition rides the blueprint spine exactly like an AI- or
 * UI-authored skin (SkinTokensFieldHandler / SkinDefaultsFieldHandler,
 * converging on StyleMapValidator::validateTokens()/validateDefaults()),
 * the animations tier through the animations seam here (landAnimations(),
 * StyleMapValidator::validateAnimations()), the fonts tier through the
 * font seam (landFonts(), FontValidator::validateFonts(): their doors are
 * the material gestures, as the defaults tier's is update_default), and
 * the importer saves live and published (see the base class for the
 * workspace escape and admin account rationale).
 *
 * Sync writes the PACKAGE layer only (tokens, animations, fonts,
 * defaults: what the file ships), and hashes it: the keyed {tokens,
 * animations, fonts, defaults} shape (DaedalusStylishSkin::shippedData()),
 * so shipping a tier later reads as new defaults. The site layer
 * (site_tokens, site_animations, site_fonts, site_defaults) is
 * the site's own and never on this lane: an UPDATED sync rewrites the
 * package layer under it and the site's changes show through unchanged.
 * That is why sync has no KEPT outcome for skins, unlike the variants
 * and fonts lanes: a consuming site cannot edit the package
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
   * The base's dependencies, plus the defaults, animations and fonts
   * seams: the defaults tier has no blueprint write (update_default is its
   * door) and neither have the animations and fonts tiers (the material
   * gestures are), so this lane validates the three shipped tiers itself
   * (landDefaults(), landAnimations(), landFonts()).
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler: the shipping modules and their paths.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the skin storage.
   * @param \Drupal\daedalus_blueprint\BlueprintDeserializerInterface $deserializer
   *   The blueprint deserializer that lands each definition on the spine.
   * @param \Drupal\Core\DefaultContent\AdminAccountSwitcher $accountSwitcher
   *   The administrative account switch (the base class has the why).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for the shipped-source hash stamp.
   * @param \Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem $activeDesignSystem
   *   The design-system unit: the module default for a skin's membership.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   Optional: with Workspaces, the sync executes outside any active
   *   workspace so shipped defaults land LIVE (the base class has the why).
   * @param \Drupal\daedalus_stylish\StyleMapValidator $validator
   *   The style map validator, the defaults and animations seams.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager: compound condition keys store
   *   canonical.
   * @param \Drupal\daedalus_stylish\FontValidator $fontValidator
   *   The font seam, run on the landed faces.
   * @param \Drupal\daedalus_stylish\FontFiles $fontFiles
   *   The font store: a face's bytes land here, permanent once the
   *   skin holds them.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler: the default theme the defaults validate against
   *   (themeAwaited(): the kind waits while it is named but not installed).
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
    protected FontValidator $fontValidator,
    protected FontFiles $fontFiles,
    protected ThemeHandlerInterface $themeHandler,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $deserializer, $accountSwitcher, $time, $activeDesignSystem, $workspaceManager);
  }

  /**
   * {@inheritdoc}
   *
   * A skin's defaults validate against the default theme's guide
   * (landDefaults(), StyleMapValidator::validateDefaults() resolves the
   * theme from system.theme), and the theme chain layers its declarations
   * only once the theme is installed. So the kind waits while system.theme
   * names a theme the theme handler does not list: the installer's window
   * (core's stark until the config phase imports system.theme), where a
   * chrome default like the branding block's site name would otherwise be
   * refused against a guide that is not the site's yet. No default named
   * (a kernel test, a site with no system.theme) is no wait.
   */
  public function themeAwaited(): ?string {
    $default = $this->themeHandler->getDefault();
    if (!is_string($default) || $default === '') {
      return NULL;
    }
    return isset($this->themeHandler->listInfo()[$default]) ? NULL : $default;
  }

  /**
   * The keys a shipped skin definition must carry.
   */
  protected const REQUIRED_KEYS = ['label'];

  /**
   * The optional definition keys: the four data tiers a skin hosts.
   */
  protected const OPTIONAL_KEYS = ['tokens', 'animations', 'fonts', 'defaults'];

  /**
   * The directory under a module's root that holds its shipped font files.
   */
  public const ASSETS_DIRECTORY = 'assets/fonts';

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
  public function entityTypeId(): string {
    return 'daedalus_skin';
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
   *   The {tokens, animations, fonts, defaults} payload (fonts
   *   content-addressed), or NULL when the skin has no disk source or its
   *   provider no longer ships an entry under its machine name (sync
   *   never deletes, so such a skin lives on as ordinary site content: no
   *   shipped state, no lock).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When the shipped entry is malformed or a face names a file the
   *   module does not hold; a broken shipped file is a developer error,
   *   never skippable.
   */
  public function getShippedData(DaedalusStylishSkinInterface $skin): ?array {
    $definition = $this->shippedDefinition($skin);
    if ($definition === NULL) {
      return NULL;
    }
    return $this->shippedPayload((string) $skin->getProvider(), $skin->getMachineName(), $definition);
  }

  /**
   * The entry a skin's provider ships for it right now, shape-checked, or NULL.
   */
  protected function shippedDefinition(DaedalusStylishSkinInterface $skin): ?array {
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
    return $definitions[$name];
  }

  /**
   * A definition's package layer in the entity's own keyed shape.
   *
   * DaedalusStylishSkin::shippedData(): the one hash covers every tier,
   * tokens, animations and fonts as the records the store holds, the
   * fonts' faces content-addressed (shippedFonts()). Public because the
   * shipper stamps the adopted skin with exactly this (after writing the
   * assets), so a sync right after a ship reads UNCHANGED.
   *
   * @param string $module
   *   The shipping module (the assets' home).
   * @param string $name
   *   The entry's machine name (the exception's coordinate).
   * @param array $definition
   *   The definition, shape-checked (checkDefinitionShape()).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   SHIPPED_FONT_FILE_MISSING when a face names a file the module's
   *   assets/fonts/ does not hold.
   */
  public function shippedPayload(string $module, string $name, array $definition): array {
    return [
      'tokens' => SkinTokensItem::canonicalizeTokens($definition['tokens'] ?? []),
      'animations' => SkinAnimationsItem::canonicalizeAnimations($definition['animations'] ?? []),
      'fonts' => $this->shippedFonts($module, $name, SkinFontsItem::canonicalizeFonts($definition['fonts'] ?? [])),
      'defaults' => $definition['defaults'] ?? [],
    ];
  }

  /**
   * The path of a shipped font file: MODULE/assets/fonts/NAME.
   *
   * The one owner of the assets directory's grammar, read by the sync
   * (the bytes to land) and by the shipper (where to write them).
   *
   * @param string $module
   *   The shipping module.
   * @param string $basename
   *   The file's plain name under assets/fonts/.
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function assetPath(string $module, string $basename): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/' . self::ASSETS_DIRECTORY . '/' . $basename;
  }

  /**
   * A fonts tier in the content-addressed shape the hash covers.
   *
   * Each face's source {file: basename} becomes {hash: sha256 of the
   * module's asset}: the one hash function then compares disk and site
   * whatever each calls the file.
   *
   * @param string $module
   *   The shipping module, whose assets/fonts/ holds the faces' files.
   * @param string $name
   *   The skin's machine name, for the refusal's coordinates.
   * @param array $fonts
   *   The canonical fonts tier as the file spells it (faces naming
   *   basenames).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   SHIPPED_FONT_FILE_MISSING when a face names a file the module's
   *   assets/fonts/ does not hold.
   */
  protected function shippedFonts(string $module, string $name, array $fonts): array {
    foreach ($fonts as $font_name => $record) {
      if (!is_array($record) || !is_array($record['faces'] ?? NULL)) {
        continue;
      }
      foreach ($record['faces'] as $index => $face) {
        if (!is_array($face)) {
          continue;
        }
        $basename = $face['source'][SkinFontsItem::SOURCE_FILE] ?? NULL;
        $path = is_string($basename) ? $this->assetPath($module, $basename) : '';
        $hash = $path !== '' && is_file($path) ? hash_file('sha256', $path) : FALSE;
        if ($hash === FALSE) {
          throw new ShippedSkinException($module, $name, [
            new BlueprintError("fonts.$font_name.faces.$index.source.file", 'SHIPPED_FONT_FILE_MISSING', sprintf('The "%s" skin\'s font %s names the file "%s" at face %d, which the "%s" module does not ship.', $name, $font_name, (string) $basename, $index, $module), ['path' => $path], sprintf('Put the file at %s/%s in the module, or name a file that is there.', self::ASSETS_DIRECTORY, (string) $basename)),
          ]);
        }
        $fonts[$font_name]['faces'][$index] = [
          'weight' => $face['weight'] ?? NULL,
          'style' => $face['style'] ?? NULL,
          'source' => ['hash' => $hash],
        ];
      }
    }
    return $fonts;
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
    $shipped = $this->shippedPayload($module, $name, $definition);

    $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
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
      $this->landFonts($module, $name, $skin, $definition);
      $this->landAnimations($module, $name, $skin, $shipped);
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
    // holds (see the class doc: no KEPT). Every tier of the package layer
    // is written wholesale, so a tier the file no longer ships is
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
    $this->landFonts($module, $name, $skin, $definition);
    $this->landAnimations($module, $name, $skin, $shipped);
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
    $errors = $this->validator->validateDefaults($shipped['defaults'], [
      'skin_tokens' => $shipped['tokens'],
      'skin_animations' => $shipped['animations'],
    ]);
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
   * Lands the shipped animations tier on the skin's package layer, loudly.
   *
   * `landDefaults()`'s twin for the motion section: the animations field has
   * no blueprint write (the material gestures are its door), so the disk
   * lane validates the shipped tier itself through the animations seam
   * (StyleMapValidator::validateAnimations()), against the skin's OWN
   * shipped tokens (self-containment: a step's var() resolves against the
   * skin it ships with), and writes the whole tier through the entity. A
   * tier the file no longer ships lands empty, matching what the hash
   * compares. Lands BEFORE the defaults, which may wear the motion.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When a shipped animation fails the seam; a broken shipped file is a
   *   developer error, never skippable.
   */
  protected function landAnimations(string $module, string $name, DaedalusStylishSkinInterface $skin, array $shipped): void {
    $errors = $this->validator->validateAnimations($shipped['animations'], ['skin_tokens' => $shipped['tokens']]);
    if ($errors) {
      throw new ShippedSkinException($module, $name, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.animations.' . $error->getPath()),
        $errors,
      ));
    }
    $skin->setPackageAnimations($shipped['animations']);
  }

  /**
   * Lands the shipped fonts tier on the skin's package layer, loudly.
   *
   * `landAnimations()`'s twin for the type section: the fonts field has no
   * blueprint write (the material gestures are its door), so the disk
   * lane lands the tier itself. Each face's basename becomes a managed
   * file uuid: a file the skin already holds with the same bytes is kept
   * (its uuid reused, so an unchanged face survives a sync of its
   * siblings with its file intact), any other face's bytes enter through
   * the font store. The landed records then pass the font seam
   * (FontValidator::validateFonts()), the one gate every font write
   * converges on; a refusal deletes the files this call landed (nothing
   * else holds them) and throws. A tier the file no longer ships lands
   * empty, matching what the hash compares. Lands FIRST: nothing else in
   * the skin resolves a font (a stack names a family as a string), so the
   * order is readability.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When a shipped font fails the seam or the lane refuses a file; a
   *   broken shipped file is a developer error, never skippable.
   */
  protected function landFonts(string $module, string $name, DaedalusStylishSkinInterface $skin, array $definition): void {
    $fonts = SkinFontsItem::canonicalizeFonts($definition['fonts'] ?? []);
    $held = $this->heldByContent($skin);
    $landed = [];
    $records = [];
    foreach ($fonts as $font_name => $record) {
      $records[$font_name] = $this->landFaces($module, $name, (string) $font_name, $record, $held, $landed);
    }
    $errors = $this->fontValidator->validateFonts($records);
    if ($errors !== []) {
      $this->discard($landed);
      throw new ShippedSkinException($module, $name, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.fonts.' . $error->getPath()),
        $errors,
      ));
    }
    $skin->setPackageFonts($records);
  }

  /**
   * Lands ONE shipped font of a skin again: the revert gesture's read.
   *
   * SkinMaterialManager::revertFont() under the developer gate: the
   * provider's current record for the font, its faces landed from the
   * module's assets (files the skin already holds by content reused),
   * validated through the seam, in the stored shape.
   *
   * @return array
   *   The record as the store holds it, faces naming uuids.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   When the provider ships no such skin or font, a face names a file
   *   the module does not hold, or the record fails the seam.
   */
  public function landShippedFont(DaedalusStylishSkinInterface $skin, string $font_name): array {
    $module = (string) $skin->getProvider();
    $name = $skin->getMachineName();
    $definition = $this->shippedDefinition($skin);
    $record = $definition === NULL ? NULL : (SkinFontsItem::canonicalizeFonts($definition['fonts'] ?? [])[$font_name] ?? NULL);
    if (!is_array($record)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError("fonts.$font_name", 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module ships no %s font on the "%s" skin.', $module, $font_name, $name), [], 'The font is site content now; edit or delete it instead.'),
      ]);
    }
    $landed = [];
    $stored = $this->landFaces($module, $name, $font_name, $record, $this->heldByContent($skin), $landed);
    $errors = $this->fontValidator->validateFonts([$font_name => $stored]);
    if ($errors !== []) {
      $this->discard($landed);
      throw new ShippedSkinException($module, $name, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.fonts.' . $error->getPath()),
        $errors,
      ));
    }
    return $stored;
  }

  /**
   * The files a skin's fonts hold, by content hash: the reuse map.
   *
   * @return array<string, string>
   *   sha256 of the bytes => the managed file's uuid, over both layers.
   */
  protected function heldByContent(DaedalusStylishSkinInterface $skin): array {
    $held = [];
    $uuids = array_unique([
      ...SkinFontsItem::fileUuids($skin->packageFonts()),
      ...SkinFontsItem::fileUuids($skin->siteFonts()),
    ]);
    foreach ($uuids as $uuid) {
      $file = $this->fontFiles->load($uuid);
      $hash = $file === NULL ? NULL : FontFiles::contentHash($file);
      if ($hash !== NULL) {
        $held[$hash] = $uuid;
      }
    }
    return $held;
  }

  /**
   * Lands one font record's faces: files in, uuids out.
   *
   * @param string $module
   *   The shipping module, whose assets/fonts/ holds the faces' files.
   * @param string $name
   *   The skin's machine name, for the refusal's coordinates.
   * @param string $font_name
   *   The font's name, the refusal's error path root.
   * @param array $record
   *   The canonical record as the file spells it (faces naming basenames).
   * @param array $held
   *   The reuse map (heldByContent()), extended with every file landed.
   * @param \Drupal\file\FileInterface[] $landed
   *   Accumulator of the files this sync landed, for discard() on refusal.
   *
   * @return array
   *   The record in the stored shape, each face's source {file: uuid}; a
   *   record whose faces are not a list passes through for the seam to
   *   refuse.
   */
  protected function landFaces(string $module, string $name, string $font_name, array $record, array &$held, array &$landed): array {
    if (!is_array($record['faces'] ?? NULL) || !array_is_list($record['faces'])) {
      return $record;
    }
    foreach ($record['faces'] as $index => $face) {
      $basename = is_array($face) ? ($face['source'][SkinFontsItem::SOURCE_FILE] ?? NULL) : NULL;
      if (!is_string($basename)) {
        continue;
      }
      $path = $this->assetPath($module, $basename);
      $hash = is_file($path) ? hash_file('sha256', $path) : FALSE;
      if ($hash === FALSE) {
        $this->discard($landed);
        throw new ShippedSkinException($module, $name, [
          new BlueprintError("fonts.$font_name.faces.$index.source.file", 'SHIPPED_FONT_FILE_MISSING', sprintf('The "%s" skin\'s font %s names the file "%s" at face %d, which the "%s" module does not ship.', $name, $font_name, $basename, $index, $module), ['path' => $path], sprintf('Put the file at %s/%s in the module, or name a file that is there.', self::ASSETS_DIRECTORY, $basename)),
        ]);
      }
      if (!isset($held[$hash])) {
        try {
          $file = $this->fontFiles->fromBytes($basename, (string) file_get_contents($path));
        }
        catch (FileLaneException $e) {
          // Meaningful recovery: the lane's refusal becomes the entry's
          // failure on the report, the files landed so far released.
          $this->discard($landed);
          throw new ShippedSkinException($module, $name, [
            new BlueprintError("fonts.$font_name.faces.$index.source.file", 'INVALID_FONT_FILE', sprintf('The "%s" skin\'s font %s names "%s" at face %d, which the file lane refuses: %s', $name, $font_name, $basename, $index, $e->getMessage()), [], sprintf('A face\'s file is one of: %s.', implode(', ', FileLane::FONT_EXTENSIONS))),
          ]);
        }
        $landed[] = $file;
        $held[$hash] = $file->uuid();
      }
      $record['faces'][$index]['source'] = [SkinFontsItem::SOURCE_FILE => $held[$hash]];
    }
    return $record;
  }

  /**
   * Deletes the files a refused landing left behind.
   *
   * @param \Drupal\file\FileInterface[] $files
   *   The files this sync landed for the refused entry.
   */
  protected function discard(array $files): void {
    foreach ($files as $file) {
      $file->delete();
    }
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
   * (the tokens field handler) during deserialization, the animations by
   * landAnimations(), the fonts by landFonts() and the defaults by
   * landDefaults() through their seams. A font face's basename is checked
   * here because it is the file's grammar (a plain name under
   * assets/fonts/, a font extension), and the asset's existence is the
   * payload read's (shippedPayload()): the ship lane checks a definition's
   * shape before its assets exist.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is not a skin definition.', $name), [], 'Each entry maps a machine name to a label key, plus optional tokens, animations, fonts and defaults.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS, self::MEMBERSHIP_KEYS)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped skin definition consists of label, plus optional tokens, animations, fonts, defaults, and design_system.'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped skin definition consists of label, plus optional tokens, animations, fonts and defaults.'),
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
          new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry has the wrong shape.', $name), [], sprintf('%s, when given, is a map (%s).', $tier, match ($tier) {
            'tokens' => '{scale: {name: value or {value, description, offered}}}',
            'animations' => '{name: steps or {steps, description, offered}}',
            'fonts' => '{name: {family, faces, description, offered}}',
            default => '{scope: element-relative styles}',
          })),
        ]);
      }
    }
    foreach ($definition['fonts'] ?? [] as $font_name => $record) {
      $this->checkFontShape($module, $name, (string) $font_name, $record);
    }
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_SKIN');
  }

  /**
   * Enforces one shipped font's FILE grammar: a record whose faces name assets.
   *
   * The record's keys, their PHP types and the basename shape of a face's
   * file; the family's vocabulary and the faces' weights, styles and files
   * are validated by FontValidator when the faces land.
   *
   * @param string $module
   *   The shipping module.
   * @param string $name
   *   The skin's machine name (the exception's coordinate).
   * @param string $font_name
   *   The font's name in the skin's fonts tier.
   * @param mixed $record
   *   The font's definition as authored.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   */
  protected function checkFontShape(string $module, string $name, string $font_name, mixed $record): void {
    $grammar = sprintf('A shipped font is {family, faces, description?, offered?}: family the name stacks reference, faces a non-empty list of {weight, style, source: {file: NAME}} with NAME the plain name of a %s file under the module\'s %s/ directory (no directory part).', implode(' or ', FileLane::FONT_EXTENSIONS), self::ASSETS_DIRECTORY);
    if (!is_array($record) || array_is_list($record)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError("fonts.$font_name", 'INVALID_SHIPPED_SKIN', sprintf('The "%s" skin\'s font %s is not a font record.', $name, $font_name), [], $grammar),
      ]);
    }
    if ($unknown = array_diff(array_keys($record), SkinFontsItem::RECORD_KEYS)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError("fonts.$font_name", 'INVALID_SHIPPED_SKIN', sprintf('Unknown key(s) %s in the "%s" skin\'s font %s.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name, $font_name), [], $grammar),
      ]);
    }
    if (!is_string($record['family'] ?? NULL) || trim($record['family']) === '' || (isset($record['description']) && !is_string($record['description'])) || (isset($record['offered']) && !is_bool($record['offered']))) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError("fonts.$font_name", 'INVALID_SHIPPED_SKIN', sprintf('The "%s" skin\'s font %s has the wrong shape.', $name, $font_name), [], 'family is a non-empty string; description, when given, a string; offered, when given, a boolean.'),
      ]);
    }
    $faces = $record['faces'] ?? NULL;
    if (!is_array($faces) || !array_is_list($faces) || $faces === []) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError("fonts.$font_name", 'INVALID_SHIPPED_SKIN', sprintf('The "%s" skin\'s font %s has the wrong shape.', $name, $font_name), [], 'faces is a non-empty list of face maps ({weight, style, source: {file: NAME}}).'),
      ]);
    }
    foreach ($faces as $index => $face) {
      $basename = is_array($face) && !array_is_list($face) ? ($face['source'][SkinFontsItem::SOURCE_FILE] ?? NULL) : NULL;
      $extension = is_string($basename) ? strtolower(pathinfo($basename, PATHINFO_EXTENSION)) : '';
      $plain = is_string($basename) && $basename !== '' && basename($basename) === $basename && !str_starts_with($basename, '.');
      if (!is_array($face) || array_is_list($face) || !is_array($face['source'] ?? NULL) || array_keys($face['source']) !== [SkinFontsItem::SOURCE_FILE] || !$plain || !in_array($extension, FileLane::FONT_EXTENSIONS, TRUE)) {
        throw new ShippedSkinException($module, $name, [
          new BlueprintError("fonts.$font_name.faces.$index", 'INVALID_SHIPPED_SKIN', sprintf('The "%s" skin\'s font %s has a face (%d) of the wrong shape.', $name, $font_name, $index), [], $grammar),
        ]);
      }
    }
  }

}
