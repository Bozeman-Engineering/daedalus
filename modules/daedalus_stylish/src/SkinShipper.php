<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\file\FileInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Ships site skins to disk: the skin kind of the shipper base.
 *
 * SkinImporter brings MODULE.daedalus_stylish.skins*.yml INTO a site (sync);
 * this service takes a site's skins OUT to a module (ship), writing
 * MODULE.daedalus_stylish.skins.FILE_ID.yml with the fonts' faces' bytes beside
 * it under assets/fonts/ and adopting each skin in place with the written
 * {tokens, animations, fonts, defaults} payload as its source hash. The lane's
 * mechanics (the whole-file rewrite, the shipped-elsewhere refusal, the
 * workspace escape, the adoption) live in ShippedDefinitionsShipper; this class
 * supplies the skin facts: definition() in SkinImporter's grammar (tokens,
 * animations and fonts as the stored records, {value, description, offered},
 * {steps, description, offered} and {family, faces, description, offered}: a
 * machine-written file never spells the bare shorthand, so what the site holds
 * is what disk holds), the assets written before the file (writeFile()), the
 * importer's payload read of what was written as the hashed slice (so a sync
 * right after a ship reads UNCHANGED), DaedalusStylishSkin's hash, and
 * ShippedSkinException.
 *
 * The importer's reverse, face by face: a site face names its managed
 * file by uuid, the file's own name (unique within the font store, the
 * lane renames on collision) becomes the basename the disk face names,
 * and the bytes are copied to assets/fonts/ under it. Assets are only
 * ever added or refreshed, never removed: the directory is shared with
 * the module's hand-authored skins file, and the disk lane never deletes.
 *
 * Only the package layer ships. The distro lock guards it on a consuming
 * site (SkinPackageLockTrait), so what such a site changes lives in the
 * skin's site layer (site_tokens, site_animations, site_fonts,
 * site_defaults), which is the site's own and never reaches disk. The
 * package author works with the lock lifted (the developer gate): the
 * package layer is edited in place, reads as modified against the disk,
 * and this lane carries it back; sync then reads the shipped file as
 * UNCHANGED. The activation (which skin a site wears) is never touched.
 */
class SkinShipper extends ShippedDefinitionsShipper {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    SkinImporter $importer,
    protected FontFiles $fontFiles,
    ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $importer, $workspaceManager);
  }

  /**
   * Builds a skin's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, design_system (always: an omitted key would mean the target
   *   module's own system on the next sync, and the skin's membership is a
   *   fact to carry, ~ for global), tokens, animations, fonts and defaults
   *   (always, even when empty: the four tiers are what a skins file
   *   ships, and an empty map hashes exactly as an omitted tier does);
   *   each font face's source {file: basename}, the managed file's name.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   *   SHIPPED_FONT_FILE_MISSING when a face's file is gone from the site:
   *   a font that cannot print has no bytes to ship.
   */
  public function definition(DaedalusStylishSkinInterface $skin): array {
    $fonts = $skin->packageFonts();
    foreach ($fonts as $name => $record) {
      if (!is_array($record) || !is_array($record['faces'] ?? NULL)) {
        continue;
      }
      foreach ($record['faces'] as $index => $face) {
        $fonts[$name]['faces'][$index]['source'] = [SkinFontsItem::SOURCE_FILE => $this->file($skin, (string) $name, $index, $face)->getFilename()];
      }
    }
    return [
      'label' => (string) $skin->label(),
      'design_system' => $skin->getDesignSystem(),
      'tokens' => $skin->packageTokens(),
      'animations' => $skin->packageAnimations(),
      'fonts' => $fonts,
      'defaults' => $skin->packageDefaults(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_skin';
  }

  /**
   * {@inheritdoc}
   *
   * The keyed {tokens, animations, fonts, defaults} map as the importer
   * computes it from what this ship wrote (SkinImporter::shippedPayload(),
   * the one reader of the content-addressed fonts shape), so the stamp is
   * exactly what the next sync computes.
   */
  protected function shippedData(array $definition, string $module, string $name): array {
    $importer = $this->importer;
    assert($importer instanceof SkinImporter);
    return $importer->shippedPayload($module, $name, $definition);
  }

  /**
   * {@inheritdoc}
   *
   * A skin's entry is not the whole story: its fonts' faces' bytes live
   * beside the file, so the entry is also changed when the site's files
   * differ by content from the module's assets (a face's file swapped
   * under the developer gate), or when an asset the entry names is gone.
   */
  protected function definitionChanged(array $existing, array $definition, string $module, ShippedSourceInterface $entity): bool {
    if (parent::definitionChanged($existing, $definition, $module, $entity)) {
      return TRUE;
    }
    assert($entity instanceof DaedalusStylishSkinInterface);
    try {
      $disk = $this->shippedData($existing, $module, $entity->getMachineName());
    }
    catch (ShippedSkinException) {
      // Meaningful recovery: a missing asset means disk holds a stale
      // entry the ship rewrites, an UPDATED outcome, not a failure.
      return TRUE;
    }
    return $this->hash($disk['fonts']) !== $this->hash($this->fontFiles->contentAddressed($entity->packageFonts()));
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishSkin::hashData($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSkinException {
    return new ShippedSkinException($module, $name, $errors);
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
    assert($importer instanceof SkinImporter);
    foreach ($definitions as $name => $definition) {
      $skin = $this->load((string) $name);
      assert($skin instanceof DaedalusStylishSkinInterface);
      foreach ($skin->packageFonts() as $font_name => $record) {
        if (!is_array($record) || !is_array($record['faces'] ?? NULL)) {
          continue;
        }
        foreach ($record['faces'] as $index => $face) {
          $file = $this->file($skin, (string) $font_name, $index, $face);
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
    }
    parent::writeFile($module, $file_id, $definitions);
  }

  /**
   * A face's managed file, loudly.
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSkinException
   */
  protected function file(DaedalusStylishSkinInterface $skin, string $font_name, int $index, mixed $face): FileInterface {
    $uuid = is_array($face) ? ($face['source'][SkinFontsItem::SOURCE_FILE] ?? NULL) : NULL;
    $file = is_string($uuid) ? $this->fontFiles->load($uuid) : NULL;
    if ($file === NULL || $file->getFileUri() === NULL || !is_file($file->getFileUri())) {
      throw new ShippedSkinException((string) $skin->getProvider(), $skin->getMachineName(), [
        new BlueprintError("fonts.$font_name.faces.$index.source.file", 'SHIPPED_FONT_FILE_MISSING', sprintf('The "%s" skin\'s font %s names a file at face %d this site no longer holds, so there are no bytes to ship.', $skin->getMachineName(), $font_name, $index), [], 'Upload the face\'s file again (update_material with kind font), or drop the face, then ship.'),
      ]);
    }
    return $file;
  }

}
