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
use Drupal\daedalus_stylish\Exception\ShippedFontException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\file\FileInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Imports and syncs module-shipped fonts: the type disk lane.
 *
 * A module ships default fonts in a MODULE.daedalus_stylish.fonts.yml at its
 * root (next to its style guide YAML), keyed by machine name, with the
 * font files under the module's assets/fonts/ directory:
 * @code
 * inter:
 *   label: 'Inter'
 *   family: 'Inter'          # optional; the label when omitted
 *   description: 'The body face.'
 *   faces:
 *     - weight: 400
 *       style: normal
 *       source: { file: 'inter-regular.woff2' }   # assets/fonts/inter-regular.woff2
 *     - weight: 700
 *       source: { file: 'inter-bold.woff2' }
 * @endcode
 * A face's source is the typed map the entity stores, with one difference
 * of medium: on disk `file` names a basename under assets/fonts/ (a
 * module has no managed files), on the site it names the managed file's
 * uuid (FontFiles). The importer crosses that gap: it lands each face's
 * bytes through FontFiles::fromBytes() (the daedalus_ui file lane
 * narrowed to woff2 and woff, permanent, held by usage for the font's
 * life) and rewrites the source's `file` from the basename to the uuid;
 * the shipper (FontShipper) does the reverse. A face's file the module
 * does not hold is a developer error (SHIPPED_FONT_FILE_MISSING), never
 * skippable.
 *
 * The family and faces are internal fields (the material gesture is the
 * one door, its validator sees the files), so unlike the other importers
 * this one does not ride the blueprint deserializer: it writes the entity
 * directly after FontValidator, the same gate the gestures converge on,
 * has passed the landed faces.
 *
 * Sync semantics are the animation lane's "one hash, two comparisons",
 * with the hash over the family and the faces by CONTENT
 * (DaedalusStylishFont::shippedData(): each face's file named by the
 * sha256 of its bytes, since neither the uuid nor the basename crosses
 * the lane). shippedPayload() reads a module's entry into that shape, so
 * a face whose bytes changed on disk reads as new defaults and lands as a
 * new file (a face whose bytes did not change keeps the file it has), and
 * a site that swapped a face's file under the developer gate reads as
 * modified. Label and description are metadata the sync never fights the
 * user over; membership and the AI opt-out sync independently of the hash
 * (see the base class).
 */
class FontImporter extends ShippedDefinitionsImporter {

  /**
   * The keys a shipped font definition must carry.
   *
   * The FILE grammar (which keys exist, their PHP types, the basename
   * shape of a face's file) is the importer's; the family and the faces'
   * weights, styles and files are validated by FontValidator once landed.
   */
  protected const REQUIRED_KEYS = ['label', 'faces'];

  /**
   * The keys a shipped font definition may carry.
   */
  protected const OPTIONAL_KEYS = ['family', 'description', 'ai', 'design_system'];

  /**
   * The directory under a module's root that holds its shipped font files.
   */
  public const ASSETS_DIRECTORY = 'assets/fonts';

  /**
   * The keys a shipped font definition consists of, for the messages.
   */
  protected const GRAMMAR = 'A shipped font definition consists of label and faces (a non-empty list of {weight, style, source: {file: NAME}}, NAME a font file under the module\'s assets/fonts/), plus an optional family (the label when omitted), description, design_system, and ai: false (the AI opt-out).';

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    BlueprintDeserializerInterface $deserializer,
    AdminAccountSwitcher $accountSwitcher,
    TimeInterface $time,
    ActiveDesignSystem $activeDesignSystem,
    protected FontValidator $validator,
    protected FontFiles $fontFiles,
    ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $deserializer, $accountSwitcher, $time, $activeDesignSystem, $workspaceManager);
  }

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'font';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.fonts.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedFontException {
    return new ShippedFontException($module, $name, $errors);
  }

  /**
   * The path of a shipped font file: MODULE/assets/fonts/NAME.
   *
   * The one owner of the assets directory's grammar, read by the sync
   * (the bytes to land) and by the shipper (where to write them).
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled.
   */
  public function assetPath(string $module, string $basename): string {
    return $this->moduleHandler->getModule($module)->getPath() . '/' . self::ASSETS_DIRECTORY . '/' . $basename;
  }

  /**
   * Reads a shipped font's current disk payload (the living-source read).
   *
   * The read behind the shipped-state comparisons and the distro lock
   * (FontManager::checkShippedLock()): both need "what does the provider
   * ship RIGHT NOW", not what was shipped at the last sync (the stored
   * hash covers that).
   *
   * @return array|null
   *   The content-addressed payload (shippedPayload()), or NULL when the
   *   font has no disk source or its provider no longer ships an entry
   *   under its machine name (sync never deletes, so such fonts live on
   *   as ordinary site content: no shipped state, no lock).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedFontException
   *   When the shipped entry is malformed or names a file the module
   *   does not hold; a broken shipped file is a developer error, never
   *   skippable.
   */
  public function getShippedData(DaedalusStylishFontInterface $font): ?array {
    $provider = $font->getProvider();
    if ($provider === NULL) {
      return NULL;
    }
    $name = $font->getMachineName();
    $definitions = $this->getShippedDefinitions($provider);
    if (!array_key_exists($name, $definitions)) {
      return NULL;
    }
    $this->checkDefinitionShape($provider, $name, $definitions[$name]);
    return $this->shippedPayload($provider, $name, $definitions[$name]);
  }

  /**
   * A definition's shipped payload in the entity's content-addressed shape.
   *
   * DaedalusStylishFont::shippedData(): the family and the canonical
   * faces, each face's source {hash: sha256 of the module's file}. The
   * one hash function then compares disk and site whatever each calls
   * the file. Public because the shipper stamps the adopted entity with
   * exactly this (after writing the assets), so a sync right after a ship
   * reads UNCHANGED.
   *
   * @param string $module
   *   The shipping module.
   * @param string $name
   *   The entry's machine name (the exception's coordinate).
   * @param array $definition
   *   The definition, shape-checked (checkDefinitionShape()).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedFontException
   *   SHIPPED_FONT_FILE_MISSING when a face names a file the module's
   *   assets/fonts/ does not hold.
   */
  public function shippedPayload(string $module, string $name, array $definition): array {
    $faces = [];
    foreach (FontFacesItem::canonicalizeFaces($definition['faces']) as $index => $face) {
      $basename = $face['source'][FontFacesItem::SOURCE_FILE];
      $path = $this->assetPath($module, $basename);
      $hash = is_file($path) ? hash_file('sha256', $path) : FALSE;
      if ($hash === FALSE) {
        throw new ShippedFontException($module, $name, [
          new BlueprintError("faces.$index.source.file", 'SHIPPED_FONT_FILE_MISSING', sprintf('The "%s" entry\'s face %d names the file "%s", which the "%s" module does not ship.', $name, $index, $basename, $module), ['path' => $path], sprintf('Put the file at %s/%s in the module, or name a file that is there.', self::ASSETS_DIRECTORY, $basename)),
        ]);
      }
      $faces[] = [
        'weight' => $face['weight'],
        'style' => $face['style'],
        'source' => ['hash' => $hash],
      ];
    }
    return ['family' => $this->family($definition), 'faces' => $faces];
  }

  /**
   * Syncs one shipped font; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    $shipped = $this->shippedPayload($module, $name, $definition);

    $storage = $this->entityTypeManager->getStorage('daedalus_font');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $font = reset($existing) ?: NULL;
    assert($font === NULL || $font instanceof DaedalusStylishFontInterface);
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($font !== NULL && $font->getProvider() !== $module) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'SHIPPED_FONT_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $font->getProvider() === NULL ? 'a font minted on this site' : sprintf('a font shipped by the "%s" module', $font->getProvider())), [], 'Machine names are the site-wide type vocabulary (each is the roster id and the disk file\'s key); rename the shipped font.'),
      ]);
    }

    if ($font === NULL) {
      $faces = $this->landFaces($module, $name, $definition, NULL);
      $font = $storage->create([
        'label' => $definition['label'],
        'machine_name' => $name,
        'uid' => $uid,
        'design_system' => $design_system,
      ]);
      assert($font instanceof DaedalusStylishFontInterface);
      $font->setFamily($this->family($definition));
      $font->setFaces($faces);
      $font->setDescription($definition['description'] ?? '');
      $this->syncOffered($font, $definition);
      $font->setShippedSource($module, $shipped);
      $font->setPublished();
      $font->save();
      return self::CREATED;
    }

    // Membership and the AI opt-out are part of the shipped face and sync
    // independently of the payload hash (see the base class).
    if (!$font->hasNewDefaults($shipped)) {
      $changed = $this->syncOffered($font, $definition);
      if ($this->syncMembership($font, $design_system) || $changed) {
        $font->save();
      }
      return self::UNCHANGED;
    }
    if ($font->isModified()) {
      $changed = $this->syncOffered($font, $definition);
      if ($this->syncMembership($font, $design_system) || $changed) {
        $font->save();
      }
      return self::KEPT;
    }

    // Unmodified font, new faces or family on disk: auto-sync as a new
    // revision. Faces whose bytes the site already holds keep their files;
    // the rest land as new files, and the entity hooks move the file
    // usage along.
    $faces = $this->landFaces($module, $name, $definition, $font);
    $font->set('label', $definition['label']);
    $font->setFamily($this->family($definition));
    $font->setFaces($faces);
    $font->setDescription($definition['description'] ?? '');
    $font->setDesignSystem($design_system);
    $font->setNewRevision(TRUE);
    $font->setRevisionUserId($uid);
    $font->setRevisionCreationTime($this->time->getRequestTime());
    $font->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $this->syncOffered($font, $definition);
    $font->setShippedSource($module, $shipped);
    $font->setPublished();
    $font->save();
    return self::UPDATED;
  }

  /**
   * Lands a definition's faces on the site: files in, uuids out.
   *
   * Each face's basename becomes a managed file uuid: a file the existing
   * font already holds with the same bytes is kept (its uuid reused, so an
   * unchanged face survives a sync of its siblings with its file and its
   * usage intact), any other face's bytes enter through the font store.
   * The landed faces then pass FontValidator, the one gate every font
   * write converges on (weights, styles, one face per weight and style,
   * every file a font file); a refusal deletes the files this call landed
   * (nothing else holds them) and throws.
   *
   * @return array
   *   The faces in the entity's shape, source {file: uuid}.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedFontException
   */
  protected function landFaces(string $module, string $name, array $definition, ?DaedalusStylishFontInterface $existing): array {
    $held = [];
    foreach ($existing?->getFaces() ?? [] as $face) {
      $uuid = $face['source'][FontFacesItem::SOURCE_FILE] ?? NULL;
      $file = is_string($uuid) ? $this->fontFiles->load($uuid) : NULL;
      $hash = $file === NULL ? NULL : FontFiles::contentHash($file);
      if ($hash !== NULL) {
        $held[$hash] = $uuid;
      }
    }
    $landed = [];
    $faces = [];
    foreach ($definition['faces'] as $index => $face) {
      $basename = $face['source'][FontFacesItem::SOURCE_FILE];
      $path = $this->assetPath($module, $basename);
      $hash = hash_file('sha256', $path);
      if (isset($held[$hash])) {
        $uuid = $held[$hash];
      }
      else {
        try {
          $file = $this->fontFiles->fromBytes($basename, (string) file_get_contents($path));
        }
        catch (FileLaneException $e) {
          // Meaningful recovery: the lane's refusal becomes the entry's
          // failure on the report, the files landed so far released.
          $this->discard($landed);
          $message = sprintf('The "%s" entry\'s face %d names "%s", which the file lane refuses: %s', $name, $index, $basename, $e->getMessage());
          throw new ShippedFontException($module, $name, [
            new BlueprintError("faces.$index.source.file", 'INVALID_FONT_FILE', $message, [], sprintf('A face\'s file is one of: %s.', implode(', ', FileLane::FONT_EXTENSIONS))),
          ]);
        }
        $landed[] = $file;
        $uuid = $file->uuid();
        $held[$hash] = $uuid;
      }
      $faces[] = ['source' => [FontFacesItem::SOURCE_FILE => $uuid]]
        + array_intersect_key($face, array_flip(['weight', 'style']));
    }
    $errors = $this->validator->validate($this->family($definition), $faces);
    if ($errors !== []) {
      $this->discard($landed);
      throw new ShippedFontException($module, $name, $errors);
    }
    return $faces;
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
   * A definition's family: its own key, or the label when omitted.
   */
  protected function family(array $definition): string {
    return trim((string) ($definition['family'] ?? $definition['label']));
  }

  /**
   * Enforces the fonts-file grammar: the keys, their types, the basenames.
   *
   * The FILE grammar only: the family's vocabulary and the faces'
   * weights, styles and files are validated by FontValidator when the
   * faces land. A basename is checked here because it is the file's
   * grammar (a plain name under assets/fonts/, a font extension), and the
   * asset's existence is the payload read's (shippedPayload()): the ship
   * lane checks a definition's shape before its assets exist.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_FONT', sprintf('The "%s" entry is not a font definition.', $name), [], self::GRAMMAR),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS)) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_FONT', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], self::GRAMMAR),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_FONT', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], self::GRAMMAR),
      ]);
    }
    $family_wrong = isset($definition['family']) && (!is_string($definition['family']) || trim($definition['family']) === '');
    if (!is_string($definition['label']) || trim($definition['label']) === '' || $family_wrong || (isset($definition['description']) && !is_string($definition['description']))) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_FONT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label is a non-empty string; family and description, when given, strings (family non-empty).'),
      ]);
    }
    $faces = $definition['faces'];
    if (!is_array($faces) || !array_is_list($faces) || $faces === []) {
      throw new ShippedFontException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_FONT', sprintf('The "%s" entry has the wrong shape.', $name), [], 'faces is a non-empty list of face maps ({weight, style, source: {file: NAME}}).'),
      ]);
    }
    foreach ($faces as $index => $face) {
      $basename = is_array($face) && !array_is_list($face) ? ($face['source'][FontFacesItem::SOURCE_FILE] ?? NULL) : NULL;
      $extension = is_string($basename) ? strtolower(pathinfo($basename, PATHINFO_EXTENSION)) : '';
      $plain = is_string($basename) && $basename !== '' && basename($basename) === $basename && !str_starts_with($basename, '.');
      if (!is_array($face) || array_is_list($face) || !is_array($face['source'] ?? NULL) || array_keys($face['source']) !== [FontFacesItem::SOURCE_FILE] || !$plain || !in_array($extension, FileLane::FONT_EXTENSIONS, TRUE)) {
        throw new ShippedFontException($module, $name, [
          new BlueprintError("faces.$index", 'INVALID_SHIPPED_FONT', sprintf('The "%s" entry\'s face %d has the wrong shape.', $name, $index), [], sprintf('A face is {weight, style, source: {file: NAME}}: NAME the plain name of a %s file under the module\'s %s/ directory (no directory part).', implode(' or ', FileLane::FONT_EXTENSIONS), self::ASSETS_DIRECTORY)),
        ]);
      }
    }
    $this->checkOfferedShape($module, $name, $definition, 'INVALID_SHIPPED_FONT');
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_FONT');
  }

}
