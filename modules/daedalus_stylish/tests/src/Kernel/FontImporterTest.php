<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_stylish\DaedalusStylishFontInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\Exception\FontValidationException;
use Drupal\daedalus_stylish\Exception\ShippedFontException;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\FontImporter;
use Drupal\daedalus_stylish\FontManager;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the font importer (the type disk lane's disk-to-site direction).
 *
 * A module ships MODULE.daedalus_stylish.fonts.yml with each face's file under
 * assets/fonts/; the importer lands the bytes through the font store,
 * rewrites each face's source from the basename to the managed file's
 * uuid, and stamps the content-addressed payload (the family and the
 * faces by the sha256 of their bytes) as the source hash, so "one hash,
 * two comparisons" holds across the medium gap: a face whose bytes
 * changed on disk is new defaults, a face the site swapped is a
 * modification. The daedalus_stylish_fonts_test module is the read-only fixture
 * (installed through the module installer, so its install syncs it); a
 * target module written under the test's own site directory (the
 * VariantShipperTest pattern) is the writable one for the update, kept
 * and refusal legs.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FontImporterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The fixture module shipping two fonts.
   */
  protected const FIXTURE = 'daedalus_stylish_fonts_test';

  /**
   * The writable module the tests drop fonts files and assets into.
   */
  protected const TARGET = 'daedalus_stylish_fonts_target';

  /**
   * The regular and bold bytes the target ships for its dusk font.
   */
  protected const REGULAR = "wOF2\0\0\0\0dusk-regular";
  protected const BOLD = "wOFF\0\0\0\0dusk-bold";

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

  /**
   * {@inheritdoc}
   *
   * The target module lives in the test's REAL site directory, created
   * before the kernel boots and kept a scan root through the
   * test_parent_site setting (VariantShipperTest explains why).
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $site = (new TestDatabase($this->databasePrefix))->getTestSitePath();
    $this->targetPath = $this->root . '/' . $site . '/modules/' . self::TARGET;
    mkdir($this->targetPath . '/' . FontImporter::ASSETS_DIRECTORY, 0777, TRUE);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.info.yml', Yaml::encode([
      'name' => 'Daedalus Stylish fonts target',
      'type' => 'module',
      'core_version_requirement' => '*',
      'hidden' => TRUE,
      'dependencies' => ['daedalus_stylish:daedalus_stylish'],
    ]));
    $this->setSetting('test_parent_site', $site);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('daedalus_font');
    // The materials roster's daedalus_stylish provider reads every storage on
    // each build, and every compile builds it.
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_font_usage',
      'daedalus_token_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    // The importer switches to an administrative account (shipped fonts
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Leave nothing behind: the real site directory exists only for the
    // target module.
    if (isset($this->targetPath) && is_dir($site = dirname($this->targetPath, 2))) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
      }
      rmdir($site);
    }
    parent::tearDown();
  }

  /**
   * Loads a font by machine name, bypassing access.
   */
  protected function loadFont(string $machine_name): ?DaedalusStylishFontInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_font');
    $storage->resetCache();
    $fonts = $storage->loadByProperties(['machine_name' => $machine_name]);
    $font = reset($fonts) ?: NULL;
    assert($font === NULL || $font instanceof DaedalusStylishFontInterface);
    return $font;
  }

  /**
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): FontImporter {
    return \Drupal::service('daedalus_stylish.font_importer');
  }

  /**
   * The font store, resolved late.
   */
  protected function files(): FontFiles {
    return \Drupal::service('daedalus_stylish.font_files');
  }

  /**
   * A face's managed file's bytes.
   */
  protected function bytesOf(DaedalusStylishFontInterface $font, int $face): string {
    $file = $this->files()->load($font->getFaces()[$face]['source']['file']);
    $this->assertNotNull($file);
    return (string) file_get_contents($file->getFileUri());
  }

  /**
   * Writes the target's hand-authored fonts file.
   */
  protected function dropFontsFile(array $definitions): void {
    file_put_contents($this->targetPath . '/' . self::TARGET . '.' . $this->importer()->fileSuffix(), Yaml::encode($definitions));
  }

  /**
   * Writes a font file under the target's assets/fonts/.
   */
  protected function dropAsset(string $basename, string $bytes): void {
    file_put_contents($this->targetPath . '/' . FontImporter::ASSETS_DIRECTORY . '/' . $basename, $bytes);
  }

  /**
   * The target's dusk definition: two faces over the two dropped assets.
   */
  protected function duskDefinition(array $overrides = []): array {
    return $overrides + [
      'label' => 'Dusk',
      'family' => 'Dusk Sans',
      'description' => 'The dusk face.',
      'faces' => [
        ['weight' => 400, 'source' => ['file' => 'dusk-regular.woff2']],
        ['weight' => 'bold', 'style' => 'normal', 'source' => ['file' => 'dusk-bold.woff']],
      ],
    ];
  }

  /**
   * Enables the target with the dusk font and its assets on disk, synced.
   */
  protected function landDusk(): DaedalusStylishFontInterface {
    $this->enableModules([self::TARGET]);
    $this->dropAsset('dusk-regular.woff2', self::REGULAR);
    $this->dropAsset('dusk-bold.woff', self::BOLD);
    $this->dropFontsFile(['dusk' => $this->duskDefinition()]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::CREATED]], $this->importer()->sync([self::TARGET])->outcomes());
    $dusk = $this->loadFont('dusk');
    $this->assertInstanceOf(DaedalusStylishFontInterface::class, $dusk);
    return $dusk;
  }

  /**
   * Installing a module imports its shipped fonts, files landed, published.
   */
  public function testInstallImportsShippedFonts(): void {
    \Drupal::service('module_installer')->install([self::FIXTURE]);

    $sans = $this->loadFont('test_sans');
    $this->assertInstanceOf(DaedalusStylishFontInterface::class, $sans);
    $this->assertSame('Test Sans', $sans->label());
    $this->assertSame('Test Sans', $sans->getFamily());
    $this->assertSame('The body face of the fonts test.', $sans->getDescription());
    $this->assertTrue($sans->isPublished());
    $this->assertTrue($sans->isOffered());
    $this->assertTrue($sans->isShipped());
    $this->assertSame(self::FIXTURE, $sans->getProvider());
    $this->assertSame('shipped', $sans->getOrigin());
    $this->assertFalse($sans->isModified());
    // The faces store canonical (bold is 700, the absent style normal),
    // each source rewritten from the disk basename to the managed file's
    // uuid; the file holds the asset's bytes under its name, permanent,
    // held by usage for the font's life.
    $faces = $sans->getFaces();
    $this->assertSame(['400', '700'], array_column($faces, 'weight'));
    $this->assertSame(['normal', 'normal'], array_column($faces, 'style'));
    $module_path = \Drupal::moduleHandler()->getModule(self::FIXTURE)->getPath();
    foreach ([0 => 'test-sans-regular.woff2', 1 => 'test-sans-bold.woff'] as $index => $basename) {
      $file = $this->files()->load($faces[$index]['source']['file']);
      $this->assertNotNull($file, $basename);
      $this->assertSame($basename, $file->getFilename());
      $this->assertTrue($file->isPermanent());
      $this->assertSame(file_get_contents("$module_path/assets/fonts/$basename"), file_get_contents($file->getFileUri()));
      $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$sans->id() => '1']]], \Drupal::service('file.usage')->listUsage($file));
    }
    // The living-source read answers the content-addressed payload, the
    // one the stamp was taken over.
    $shipped = $this->importer()->getShippedData($sans);
    $this->assertSame('Test Sans', $shipped['family']);
    $this->assertSame(hash('sha256', (string) file_get_contents("$module_path/assets/fonts/test-sans-regular.woff2")), $shipped['faces'][0]['source']['hash']);
    $this->assertFalse($sans->hasNewDefaults($shipped));

    // The serif: the family is the label when omitted, ai: false withholds.
    $serif = $this->loadFont('test_serif');
    $this->assertInstanceOf(DaedalusStylishFontInterface::class, $serif);
    $this->assertSame('Test Serif', $serif->getFamily());
    $this->assertFalse($serif->isOffered());
    $this->assertCount(1, $serif->getFaces());
    $this->assertSame('', $serif->getDescription());

    // The compiled fonts file carries both families.
    $css = \Drupal::service('daedalus_stylish.font_stylesheet')->css();
    $this->assertStringContainsString('font-family: "Test Sans";', $css);
    $this->assertStringContainsString('font-family: "Test Serif";', $css);
    $this->assertStringContainsString('format("woff")', $css);

    // A second sync converges: unchanged, no revision, no new file.
    $revision_id = $sans->getRevisionId();
    $file_count = count(\Drupal::entityTypeManager()->getStorage('file')->loadMultiple());
    $this->assertSame([
      self::FIXTURE => [
        'test_sans' => ShippedDefinitionsImporter::UNCHANGED,
        'test_serif' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $this->importer()->sync([self::FIXTURE])->outcomes());
    $this->assertSame($revision_id, $this->loadFont('test_sans')->getRevisionId());
    $this->assertCount($file_count, \Drupal::entityTypeManager()->getStorage('file')->loadMultiple());
    // A module shipping no fonts file is simply not a provider; a site
    // font has no living source.
    $this->assertSame([], $this->importer()->sync(['system'])->outcomes());
    $mine = DaedalusStylishFont::create(['label' => 'Mine', 'family' => 'Mine']);
    $mine->setFaces([['source' => ['file' => $this->files()->fromBytes('mine.woff2', 'wOF2mine')->uuid()]]]);
    $mine->save();
    $this->assertNull($this->importer()->getShippedData($mine));
  }

  /**
   * New bytes or a new family on disk land as an UPDATE, files reused by content.
   *
   * The regular face's bytes did not change, so it keeps its managed
   * file (and the file its usage); the bold face's bytes did, so a new
   * file lands and the old one is released.
   */
  public function testChangedDefaultsUpdateAndReuseFiles(): void {
    $dusk = $this->landDusk();
    [$regular_uuid, $bold_uuid] = $dusk->fileUuids();
    $this->assertSame(self::BOLD, $this->bytesOf($dusk, 1));
    $old_bold = $this->files()->load($bold_uuid);
    $revision_id = $dusk->getRevisionId();

    $this->dropAsset('dusk-bold.woff', "wOFF\0\0\0\0dusk-bold-v2");
    $this->assertTrue($dusk->hasNewDefaults($this->importer()->getShippedData($dusk)));
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UPDATED]], $this->importer()->sync([self::TARGET])->outcomes());
    $updated = $this->loadFont('dusk');
    $this->assertNotSame($revision_id, $updated->getRevisionId());
    $this->assertSame($regular_uuid, $updated->fileUuids()[0], 'The unchanged face keeps its file.');
    $this->assertNotSame($bold_uuid, $updated->fileUuids()[1], 'The changed face lands a new file.');
    $this->assertSame("wOFF\0\0\0\0dusk-bold-v2", $this->bytesOf($updated, 1));
    $this->assertFalse($updated->isModified());
    $this->assertSame([], \Drupal::service('file.usage')->listUsage($old_bold), 'The replaced file is released.');
    $this->assertStringContainsString('Synced new shipped defaults from the ' . self::TARGET . ' module.', (string) $updated->getRevisionLogMessage());

    // A family change alone is new defaults too; the label and the
    // description ride the update.
    $renamed_definition = $this->duskDefinition([
      'label' => 'Dusk II',
      'family' => 'Dusk Serif',
      'description' => 'Renamed.',
    ]);
    $this->dropFontsFile(['dusk' => $renamed_definition]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UPDATED]], $this->importer()->sync([self::TARGET])->outcomes());
    $renamed = $this->loadFont('dusk');
    $this->assertSame('Dusk Serif', $renamed->getFamily());
    $this->assertSame('Dusk II', $renamed->label());
    $this->assertSame('Renamed.', $renamed->getDescription());
    $this->assertSame($updated->fileUuids(), $renamed->fileUuids(), 'The faces keep their files.');
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());

    // The AI opt-out and the membership are the shipped face, synced
    // independently of the hash: the entry reads UNCHANGED and the flag
    // still moves.
    $this->dropFontsFile(['dusk' => ['ai' => FALSE] + $renamed_definition]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertFalse($this->loadFont('dusk')->isOffered());
  }

  /**
   * A site-modified font is KEPT when disk ships new defaults.
   */
  public function testModifiedFontIsKept(): void {
    $dusk = $this->landDusk();
    // The site swaps the bold face's bytes (the developer gate's edit,
    // written here through the entity): modified against the stamp.
    $swapped = $this->files()->fromBytes('dusk-bold-site.woff', "wOFF\0\0\0\0site-bold");
    $faces = $dusk->getFaces();
    $faces[1]['source']['file'] = $swapped->uuid();
    $dusk->setFaces($faces);
    $dusk->save();
    $this->assertTrue($this->loadFont('dusk')->isModified());
    // Disk unchanged: unchanged, the site's edit untouched.
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($swapped->uuid(), $this->loadFont('dusk')->fileUuids()[1]);
    // Disk changed: kept, the site's edit still untouched.
    $this->dropAsset('dusk-bold.woff', "wOFF\0\0\0\0dusk-bold-v2");
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::KEPT]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($swapped->uuid(), $this->loadFont('dusk')->fileUuids()[1]);
    $this->assertSame("wOFF\0\0\0\0site-bold", $this->bytesOf($this->loadFont('dusk'), 1));
  }

  /**
   * The file grammar, a missing asset, a bad face and a conflict refuse loudly.
   *
   * Each failure lands on the report with its code while the file's
   * sound neighbor still lands, and a face the validator refuses after
   * its file landed leaves no orphan file behind.
   */
  public function testRefusals(): void {
    $this->enableModules([self::TARGET]);
    $this->dropAsset('dusk-regular.woff2', self::REGULAR);
    $this->dropAsset('dusk-bold.woff', self::BOLD);
    $sound = ['label' => 'Sound', 'faces' => [['source' => ['file' => 'dusk-regular.woff2']]]];
    $cases = [
      'not_a_map' => ['A list', 'INVALID_SHIPPED_FONT'],
      'unknown_key' => $sound + ['steps' => []],
      'no_faces' => ['label' => 'No faces'],
      'faces_not_list' => ['label' => 'Faces map', 'faces' => ['weight' => 400]],
      'faces_empty' => ['label' => 'Faces empty', 'faces' => []],
      'source_bare' => ['label' => 'Bare source', 'faces' => [['source' => 'dusk-regular.woff2']]],
      'source_uuid_key' => ['label' => 'Uuid source', 'faces' => [['source' => ['uuid' => 'x']]]],
      'source_directory' => ['label' => 'Directory', 'faces' => [['source' => ['file' => '../dusk-regular.woff2']]]],
      'source_ttf' => ['label' => 'Legacy', 'faces' => [['source' => ['file' => 'dusk.ttf']]]],
      'family_empty' => ['label' => 'Empty family', 'family' => ' ', 'faces' => $sound['faces']],
      'ai_true' => $sound + ['ai' => TRUE],
    ];
    $expected = array_fill_keys(array_keys($cases), 'INVALID_SHIPPED_FONT');
    $cases['missing_asset'] = ['label' => 'Missing', 'faces' => [['source' => ['file' => 'nowhere.woff2']]]];
    $expected['missing_asset'] = 'SHIPPED_FONT_FILE_MISSING';
    $cases['bad_weight'] = [
      'label' => 'Heavy',
      'faces' => [['weight' => 'heavy', 'source' => ['file' => 'dusk-regular.woff2']]],
    ];
    $expected['bad_weight'] = 'INVALID_FONT_WEIGHT';
    $cases['duplicate_face'] = [
      'label' => 'Twice',
      'faces' => [['source' => ['file' => 'dusk-regular.woff2']], ['source' => ['file' => 'dusk-bold.woff']]],
    ];
    $expected['duplicate_face'] = 'DUPLICATE_FONT_FACE';
    $cases['generic_family'] = ['label' => 'Generic', 'family' => 'sans-serif', 'faces' => $sound['faces']];
    $expected['generic_family'] = 'INVALID_FONT_FAMILY';
    $cases['sound'] = $sound;
    $this->dropFontsFile($cases);

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $report = $this->importer()->sync([self::TARGET]);
    $outcomes = $report->outcomes()[self::TARGET];
    $this->assertSame(ShippedDefinitionsImporter::CREATED, $outcomes['sound']);
    foreach ($expected as $name => $code) {
      $this->assertSame(ShippedDefinitionsImporter::FAILED, $outcomes[$name], $name);
      $this->assertSame([$code], array_map(static fn ($error) => $error->getCode(), $report->errors(self::TARGET, $name)), $name);
      $this->assertNull($this->loadFont($name), $name);
    }
    // The validator's refusals came after the faces landed, and the
    // landed files were discarded: the sound font's one file is the only
    // file on the site.
    $file_storage->resetCache();
    $this->assertCount(1, $file_storage->loadMultiple());
    $this->assertStringContainsString('does not ship', $report->errors(self::TARGET, 'missing_asset')[0]->getMessage());
    $this->assertSame('faces.0.source.file', $report->errors(self::TARGET, 'missing_asset')[0]->getPath());

    // The loud reader throws the same shape.
    try {
      $this->importer()->checkDefinitionShape(self::TARGET, 'source_ttf', $cases['source_ttf']);
      $this->fail('A legacy format passed the file grammar.');
    }
    catch (ShippedFontException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('source_ttf', $e->getMachineName());
      $this->assertSame('faces.0', $e->getErrors()[0]->getPath());
    }

    // A machine name a site-minted font holds is a conflict, never adopted.
    $mine = DaedalusStylishFont::create(['label' => 'Mine', 'family' => 'Mine', 'machine_name' => 'taken']);
    $mine->setFaces([['source' => ['file' => $this->files()->fromBytes('mine.woff2', 'wOF2mine')->uuid()]]]);
    $mine->save();
    $this->dropFontsFile(['taken' => $sound]);
    $report = $this->importer()->sync([self::TARGET]);
    $this->assertSame(ShippedDefinitionsImporter::FAILED, $report->outcome(self::TARGET, 'taken'));
    $this->assertSame('SHIPPED_FONT_CONFLICT', $report->errors(self::TARGET, 'taken')[0]->getCode());
    $this->assertNull($this->loadFont('taken')->getProvider());
  }

  /**
   * The distro lock reads the living disk source, and the developer gate lifts it.
   *
   * A shipped font whose provider ships it right now is read-only through
   * the manager (update, delete); one whose provider dropped the entry is
   * site content again; and on an authoring site the lock lifts, the
   * edit lands and reads as modified against disk.
   */
  public function testShippedLockReadsTheLivingSource(): void {
    $dusk = $this->landDusk();
    $manager = \Drupal::service('daedalus_stylish.font_manager');
    assert($manager instanceof FontManager);
    $this->setUpCurrentUser([], ['update fonts', 'delete fonts', 'delete fonts in use']);

    foreach ([
      fn () => $manager->update($dusk, description: 'Mine now.'),
      fn () => $manager->deleteFont($dusk),
    ] as $gesture) {
      try {
        $gesture();
        $this->fail('A shipped font with a living source was written on a consuming site.');
      }
      catch (FontValidationException $e) {
        $this->assertSame('STYLE_FONT_SHIPPED_LOCKED', $e->getErrors()[0]->getCode());
        $this->assertSame(self::TARGET, $e->getErrors()[0]->getContext()['provider']);
      }
    }
    $this->assertSame('The dusk face.', $this->loadFont('dusk')->getDescription());

    // The provider drops the entry: no living source, the lock lifts, and
    // the font lives on as site content (sync never deletes).
    $this->dropFontsFile([]);
    $this->assertNull($this->importer()->getShippedData($dusk));
    $manager->update($dusk, description: 'Mine now.');
    $this->assertSame('Mine now.', $this->loadFont('dusk')->getDescription());
    $this->assertSame(self::TARGET, $this->loadFont('dusk')->getProvider());

    // Back on disk, and the developer gate open: the edit lands and the
    // font reads modified against what disk ships.
    $this->dropFontsFile(['dusk' => $this->duskDefinition()]);
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $edited = $manager->update($this->loadFont('dusk'), family: 'Dusk Display');
    $this->assertSame('Dusk Display', $edited->getFamily());
    $this->assertTrue($edited->isModified());
    // A sync leaves the developer's edit alone.
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame('Dusk Display', $this->loadFont('dusk')->getFamily());
    // The gate lifts the delete too; nothing names the family, so it is
    // immediate.
    $this->assertSame(0, $manager->deleteFont($this->loadFont('dusk')));
    $this->assertNull($this->loadFont('dusk'));
  }

}
