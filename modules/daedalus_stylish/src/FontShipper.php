<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\Exception\ShippedFontException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\file\FileInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Ships site fonts to disk: the font kind of the shipper base.
 *
 * FontImporter brings MODULE.daedalus_stylish.fonts*.yml and the files under
 * assets/fonts/ INTO a site (sync); this service takes a site's fonts OUT
 * to a module (ship), writing MODULE.daedalus_stylish.fonts.FILE_ID.yml with the
 * faces' bytes beside it and adopting each font in place with the
 * content-addressed {family, faces} payload as its source hash. The
 * lane's mechanics (the whole-file rewrite, the shipped-elsewhere
 * refusal, the workspace escape, the adoption) live in
 * ShippedDefinitionsShipper; this class supplies the font facts:
 * definition() in FontImporter's grammar, the assets written before the
 * file (writeFile()), the importer's payload read of what was written as
 * the hashed slice (so a sync right after a ship reads UNCHANGED),
 * DaedalusStylishFont's hash, and ShippedFontException.
 *
 * The importer's reverse, face by face: a site face names its managed
 * file by uuid, the file's own name (unique within the font store, the
 * lane renames on collision) becomes the basename the disk face names,
 * and the bytes are copied to assets/fonts/ under it. Assets are only
 * ever added or refreshed, never removed: the directory is shared with
 * the module's hand-authored fonts file, and the disk lane never deletes.
 * A hand-authored entry whose asset shares a basename with a site file's
 * is the author's to rename, exactly as a duplicate machine name is.
 */
class FontShipper extends ShippedDefinitionsShipper {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    FontImporter $importer,
    protected FontFiles $fontFiles,
    ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $importer, $workspaceManager);
  }

  /**
   * Builds a font's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, family (always, even when it equals the label: the file
   *   states the reference name), description (only when set), ai: false
   *   (only when withheld from the AI), design_system (always: an
   *   omitted key would mean the target module's own system on the next
   *   sync, ~ for global), then faces, each source {file: basename}.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedFontException
   *   SHIPPED_FONT_FILE_MISSING when a face's file is gone from the site:
   *   a font that cannot print has no bytes to ship.
   */
  public function definition(DaedalusStylishFontInterface $font): array {
    $definition = [
      'label' => (string) $font->label(),
      'family' => $font->getFamily(),
    ];
    if ($font->getDescription() !== '') {
      $definition['description'] = $font->getDescription();
    }
    // The AI opt-out's one spelling: offered is the default, so the key
    // is written only to withhold (the importer refuses any other value).
    if (!$font->isOffered()) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $font->getDesignSystem();
    $definition['faces'] = [];
    foreach ($font->getFaces() as $index => $face) {
      $definition['faces'][] = [
        'weight' => $face['weight'],
        'style' => $face['style'],
        'source' => [FontFacesItem::SOURCE_FILE => $this->file($font, $index, $face)->getFilename()],
      ];
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_font';
  }

  /**
   * {@inheritdoc}
   *
   * The content-addressed payload read from the assets this ship wrote,
   * through the importer (the one reader of that shape), so the stamp is
   * exactly what the next sync computes.
   */
  protected function shippedData(array $definition, string $module, string $name): array {
    $importer = $this->importer;
    assert($importer instanceof FontImporter);
    return $importer->shippedPayload($module, $name, $definition);
  }

  /**
   * {@inheritdoc}
   *
   * A font's entry is not the whole story: its faces' bytes live beside
   * the file, so the entry is also changed when the site's files differ
   * by content from the module's assets (a face's file swapped under the
   * developer gate), or when an asset the entry names is gone.
   */
  protected function definitionChanged(array $existing, array $definition, string $module, ShippedSourceInterface $entity): bool {
    if (parent::definitionChanged($existing, $definition, $module, $entity)) {
      return TRUE;
    }
    assert($entity instanceof DaedalusStylishFontInterface);
    try {
      $disk = $this->shippedData($existing, $module, $entity->getMachineName());
    }
    catch (ShippedFontException) {
      // Meaningful recovery: a missing asset means disk holds a stale
      // entry the ship rewrites, an UPDATED outcome, not a failure.
      return TRUE;
    }
    $site = ['family' => $entity->getFamily(), 'faces' => $this->fontFiles->contentAddressed($entity->getFaces())];
    return $this->hash($disk) !== $this->hash($site);
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishFont::hashData($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedFontException {
    return new ShippedFontException($module, $name, $errors);
  }

  /**
   * {@inheritdoc}
   *
   * The assets first, then the file: every face's bytes are copied from
   * the site's file to assets/fonts/ under the basename the definition
   * names (definition() derived it from the same file), so the file never
   * names bytes the module does not hold.
   */
  protected function writeFile(string $module, string $file_id, array $definitions): void {
    $importer = $this->importer;
    assert($importer instanceof FontImporter);
    foreach ($definitions as $name => $definition) {
      $font = $this->load((string) $name);
      assert($font instanceof DaedalusStylishFontInterface);
      foreach ($font->getFaces() as $index => $face) {
        $file = $this->file($font, $index, $face);
        $path = $importer->assetPath($module, (string) $file->getFilename());
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, TRUE) && !is_dir($directory)) {
          throw new \RuntimeException(sprintf('The shipped font assets directory %s could not be created.', $directory));
        }
        $bytes = file_get_contents((string) $file->getFileUri());
        if ($bytes === FALSE || file_put_contents($path, $bytes) === FALSE) {
          throw new \RuntimeException(sprintf('The shipped font file %s could not be written.', $path));
        }
      }
    }
    parent::writeFile($module, $file_id, $definitions);
  }

  /**
   * A face's managed file, loudly.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedFontException
   */
  protected function file(DaedalusStylishFontInterface $font, int $index, array $face): FileInterface {
    $uuid = $face['source'][FontFacesItem::SOURCE_FILE] ?? NULL;
    $file = is_string($uuid) ? $this->fontFiles->load($uuid) : NULL;
    if ($file === NULL || $file->getFileUri() === NULL || !is_file($file->getFileUri())) {
      throw new ShippedFontException((string) $font->getProvider(), $font->getMachineName(), [
        new BlueprintError("faces.$index.source.file", 'SHIPPED_FONT_FILE_MISSING', sprintf('The "%s" font\'s face %d names a file this site no longer holds, so there are no bytes to ship.', $font->getMachineName(), $index), [], 'Upload the face\'s file again (update_material with kind font), or drop the face, then ship.'),
      ]);
    }
    return $file;
  }

}
