<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_stylish\DaedalusStylishFontInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\Exception\ShippedFontException;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\FontImporter;
use Drupal\daedalus_stylish\FontShipper;
use Drupal\daedalus_stylish\Ship;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the font shipper (the type disk lane's site-to-disk direction).
 *
 * A ship writes MODULE.daedalus_stylish.fonts.FILE_ID.yml into the target module
 * with each face's bytes copied under assets/fonts/ (the managed file's
 * name as the basename, the importer's rewrite in reverse), and adopts
 * the shipped fonts in place (same entity, provider stamped, source hash
 * over the content-addressed {family, faces} payload read back from the
 * assets, published), so the importer's sync right after reports
 * UNCHANGED: the round trip is the lock. The target module is created
 * under the test's own site directory (sites/simpletest/ID, a root
 * extension discovery scans), never a real module directory, and removed
 * on teardown; see VariantShipperTest for the two conditions that make
 * discovery see it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FontShipperTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The bytes of the site-created dusk font's two faces.
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
    mkdir($this->targetPath, 0777, TRUE);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.info.yml', Yaml::encode([
      'name' => 'Daedalus Stylish ship target',
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
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_font_usage',
      'daedalus_token_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->enableModules([self::TARGET]);
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
   * Saves a site-created font over two faces (regular woff2, bold woff).
   */
  protected function createFont(string $machine_name, array $values = [], ?array $faces = NULL): DaedalusStylishFontInterface {
    $font = DaedalusStylishFont::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'family' => ucwords(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
    ]);
    assert($font instanceof DaedalusStylishFontInterface);
    $regular = $this->files()->fromBytes("$machine_name-regular.woff2", self::REGULAR);
    $bold = $this->files()->fromBytes("$machine_name-bold.woff", self::BOLD);
    $font->setFaces($faces ?? [
      ['weight' => 400, 'source' => ['file' => $regular->uuid()]],
      ['weight' => 700, 'source' => ['file' => $bold->uuid()]],
    ]);
    $font->save();
    return $font;
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): FontShipper {
    return \Drupal::service('daedalus_stylish.font_shipper');
  }

  /**
   * The importer service, resolved late.
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
   * The per-id file the ship writes.
   */
  protected function duskFile(): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.fonts.dusk.yml';
  }

  /**
   * A shipped asset's path.
   */
  protected function asset(string $basename): string {
    return $this->targetPath . '/' . FontImporter::ASSETS_DIRECTORY . '/' . $basename;
  }

  /**
   * A ship writes the file and the assets, adopts in place, and syncs UNCHANGED.
   */
  public function testShipAdoptsSiteCreatedFont(): void {
    $font = $this->createFont('dusk_sans', ['description' => 'The dusk face.']);
    $uuid = $font->uuid();
    $id = $font->id();
    $this->assertNull($font->getProvider());

    // The definition speaks the importer's grammar: each face's file by
    // the managed file's name, the family stated, the membership carried.
    $definition = $this->shipper()->definition($font);
    $this->assertSame([
      'label' => 'Dusk sans',
      'family' => 'Dusk Sans',
      'description' => 'The dusk face.',
      'design_system' => NULL,
      'faces' => [
        ['weight' => '400', 'style' => 'normal', 'source' => ['file' => 'dusk_sans-regular.woff2']],
        ['weight' => '700', 'style' => 'normal', 'source' => ['file' => 'dusk_sans-bold.woff']],
      ],
    ], $definition);
    $this->assertSame(self::TARGET . '.daedalus_stylish.fonts.dusk.yml', basename($this->shipper()->filePath(self::TARGET, 'dusk')));

    // The plan is the dry run: nothing written, nothing adopted.
    $this->assertSame(['dusk_sans' => FontShipper::CREATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertFileDoesNotExist($this->asset('dusk_sans-regular.woff2'));
    $this->assertNull($this->loadFont('dusk_sans')->getProvider());

    $this->assertSame(['dusk_sans' => FontShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertFileExists($this->duskFile());
    $this->assertSame(['dusk_sans' => $definition], Yaml::decode(file_get_contents($this->duskFile())));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish (FontShipper)', file_get_contents($this->duskFile()));
    // The importer's reverse: the bytes under assets/fonts/ by basename.
    $this->assertSame(self::REGULAR, file_get_contents($this->asset('dusk_sans-regular.woff2')));
    $this->assertSame(self::BOLD, file_get_contents($this->asset('dusk_sans-bold.woff')));

    // Adopted in place: same entity, now shipped by the target,
    // unmodified, the stamp over the content-addressed payload the
    // importer reads from the assets.
    $adopted = $this->loadFont('dusk_sans');
    $this->assertSame($uuid, $adopted->uuid());
    $this->assertSame($id, $adopted->id());
    $this->assertSame(self::TARGET, $adopted->getProvider());
    $this->assertTrue($adopted->isShipped());
    $this->assertSame('shipped', $adopted->getOrigin());
    $this->assertFalse($adopted->isModified());
    $shipped = $this->importer()->getShippedData($adopted);
    $this->assertSame(hash('sha256', self::REGULAR), $shipped['faces'][0]['source']['hash']);
    $this->assertFalse($adopted->hasNewDefaults($shipped));
    $this->assertSame(DaedalusStylishFont::hashData($shipped), $adopted->getSourceHash());
    $this->assertTrue($adopted->isPublished());
    $this->assertSame($font->fileUuids(), $adopted->fileUuids(), 'The site keeps its files; only the bytes crossed.');
    $revision_id = $adopted->getRevisionId();

    // Disk and site agree: discovery serves the file, sync touches nothing.
    $this->assertSame(['dusk_sans' => $definition], $this->importer()->getShippedDefinitions(self::TARGET));
    $this->assertSame([self::TARGET => ['dusk_sans' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($revision_id, $this->loadFont('dusk_sans')->getRevisionId());

    // Shipping the same thing again changes nothing on disk or the entity.
    $this->assertSame(['dusk_sans' => FontShipper::UNCHANGED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame(['dusk_sans' => FontShipper::UNCHANGED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame($revision_id, $this->loadFont('dusk_sans')->getRevisionId());

    // The whole round trip: the site loses the font, disk brings it back
    // with the same bytes under new managed files.
    $this->loadFont('dusk_sans')->delete();
    $this->assertNull($this->loadFont('dusk_sans'));
    $this->assertSame([self::TARGET => ['dusk_sans' => ShippedDefinitionsImporter::CREATED]], $this->importer()->sync([self::TARGET])->outcomes());
    $returned = $this->loadFont('dusk_sans');
    $this->assertSame('Dusk Sans', $returned->getFamily());
    $this->assertSame('The dusk face.', $returned->getDescription());
    $this->assertSame(self::BOLD, file_get_contents($this->files()->load($returned->fileUuids()[1])->getFileUri()));
    $this->assertFalse($returned->isModified());
  }

  /**
   * A site edit ships as an UPDATE: disk follows the site, bytes included.
   *
   * A face's bytes swapped on the site (the developer gate's edit) leave
   * the definition's words unchanged, so the plan reads the change by
   * content; the ship refreshes the asset and restamps.
   */
  public function testEditedFontShipsAsUpdate(): void {
    $font = $this->createFont('dusk_sans');
    $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $this->shipper()->definition($font)]);

    // The bytes change under the same managed file: modified against the
    // stamp, the definition word for word the same.
    $bold = $this->files()->load($font->fileUuids()[1]);
    file_put_contents($bold->getFileUri(), "wOFF\0\0\0\0dusk-bold-v2");
    $edited = $this->loadFont('dusk_sans');
    $this->assertTrue($edited->isModified());
    $definition = $this->shipper()->definition($edited);
    $this->assertSame(['dusk_sans' => $definition], Yaml::decode(file_get_contents($this->duskFile())));
    $this->assertSame([self::TARGET => ['dusk_sans' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes(), 'Disk unchanged, the site edit kept.');

    $this->assertSame(['dusk_sans' => FontShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame(['dusk_sans' => FontShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame("wOFF\0\0\0\0dusk-bold-v2", file_get_contents($this->asset('dusk_sans-bold.woff')));
    $this->assertSame(self::REGULAR, file_get_contents($this->asset('dusk_sans-regular.woff2')));
    $shipped = $this->loadFont('dusk_sans');
    $this->assertFalse($shipped->isModified());
    $this->assertSame([self::TARGET => ['dusk_sans' => ShippedDefinitionsImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());

    // A family change is an UPDATE by the definition's words.
    $shipped->setFamily('Dusk Serif');
    $shipped->save();
    $definition = $this->shipper()->definition($this->loadFont('dusk_sans'));
    $this->assertSame(['dusk_sans' => FontShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame(['dusk_sans' => FontShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->assertSame('Dusk Serif', Yaml::decode(file_get_contents($this->duskFile()))['dusk_sans']['family']);
    $this->assertFalse($this->loadFont('dusk_sans')->isModified());

    // An asset gone from disk reads as a change too: the ship restores it.
    unlink($this->asset('dusk_sans-regular.woff2'));
    $this->assertSame(['dusk_sans' => FontShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definition]));
    $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definition]);
    $this->assertSame(self::REGULAR, file_get_contents($this->asset('dusk_sans-regular.woff2')));
  }

  /**
   * The file is rewritten whole: left-out names drop, an empty ship removes.
   *
   * Assets are never removed: the directory is shared with the module's
   * hand-authored file, and the disk lane never deletes.
   */
  public function testShipRewritesFileWhole(): void {
    $dusk = $this->createFont('dusk_sans');
    $dawn = $this->createFont('dawn_sans', [], [['source' => ['file' => $this->files()->fromBytes('dawn.woff2', 'wOF2dawn')->uuid()]]]);
    $definitions = [
      'dusk_sans' => $this->shipper()->definition($dusk),
      'dawn_sans' => $this->shipper()->definition($dawn),
    ];
    $this->assertSame(['dusk_sans' => FontShipper::CREATED, 'dawn_sans' => FontShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', $definitions));
    $this->assertSame(['dusk_sans', 'dawn_sans'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));
    $this->assertSame(self::TARGET, $this->loadFont('dawn_sans')->getProvider());
    $this->assertFileExists($this->asset('dawn.woff2'));

    $plan = $this->shipper()->plan(self::TARGET, 'dusk', ['dusk_sans' => $definitions['dusk_sans']]);
    $this->assertSame(['dusk_sans' => FontShipper::UNCHANGED, 'dawn_sans' => FontShipper::DROPPED], $plan);
    $this->assertSame($plan, $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => $definitions['dusk_sans']]));
    $this->assertSame(['dusk_sans'], array_keys(Yaml::decode(file_get_contents($this->duskFile()))));
    // The dropped font lives on as site content: never deleted by disk.
    $this->assertInstanceOf(DaedalusStylishFontInterface::class, $this->loadFont('dawn_sans'));
    $this->assertFileExists($this->asset('dawn.woff2'));

    $this->assertSame(['dusk_sans' => FontShipper::DROPPED], $this->shipper()->ship(self::TARGET, 'dusk', []));
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertSame([], $this->importer()->getShippedDefinitions(self::TARGET));
  }

  /**
   * A name shipped elsewhere is planned as such and refused by ship().
   *
   * Two shippers count as elsewhere: another module (daedalus_stylish_fonts_test
   * ships test_sans) and another file of the target module itself (its
   * hand-authored single file). Nothing is written when any name
   * conflicts.
   */
  public function testShippedElsewhereRefused(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_fonts_test']);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.' . $this->importer()->fileSuffix(), Yaml::encode([
      'hand_font' => ['label' => 'Hand font', 'faces' => [['source' => ['file' => 'hand.woff2']]]],
    ]));
    $dusk = $this->createFont('dusk_sans');
    $definitions = [
      'dusk_sans' => $this->shipper()->definition($dusk),
      'test_sans' => $this->shipper()->definition($this->loadFont('test_sans')),
      'hand_font' => ['label' => 'Hand font', 'faces' => [['source' => ['file' => 'hand.woff2']]]],
    ];
    $this->assertSame([
      'dusk_sans' => FontShipper::CREATED,
      'test_sans' => FontShipper::SHIPPED_ELSEWHERE,
      'hand_font' => FontShipper::SHIPPED_ELSEWHERE,
    ], $this->shipper()->plan(self::TARGET, 'dusk', $definitions));

    try {
      $this->shipper()->ship(self::TARGET, 'dusk', $definitions);
      $this->fail('A name shipped elsewhere must not ship again.');
    }
    catch (ShippedFontException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('test_sans', $e->getMachineName());
      $errors = $e->getErrors();
      $this->assertCount(2, $errors);
      $this->assertSame('SHIPPED_FONT_CONFLICT', $errors[0]->getCode());
      $this->assertStringContainsString('the "daedalus_stylish_fonts_test" module', $errors[0]->getMessage());
      $this->assertSame('SHIPPED_FONT_CONFLICT', $errors[1]->getCode());
      $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.fonts.yml', $errors[1]->getMessage());
    }
    // The whole run was refused: no file, no asset, nothing adopted.
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertFileDoesNotExist($this->asset('dusk_sans-regular.woff2'));
    $this->assertNull($this->loadFont('dusk_sans')->getProvider());
    $this->assertSame('daedalus_stylish_fonts_test', $this->loadFont('test_sans')->getProvider());
  }

  /**
   * A face whose file is gone, a missing font, and a bad definition refuse.
   *
   * Through the orchestrator the unshippable definition fails its file
   * on the report (the ship builds definitions inside its refusal net)
   * while the other kinds and files still ship.
   */
  public function testMissingFileRefused(): void {
    $broken = $this->createFont('broken_sans');
    unlink($this->files()->load($broken->fileUuids()[0])->getFileUri());
    try {
      $this->shipper()->definition($broken);
      $this->fail('A font with a lost file built a definition.');
    }
    catch (ShippedFontException $e) {
      $this->assertSame('broken_sans', $e->getMachineName());
      $this->assertSame('SHIPPED_FONT_FILE_MISSING', $e->getErrors()[0]->getCode());
      $this->assertSame('faces.0.source.file', $e->getErrors()[0]->getPath());
    }
    $ship = \Drupal::service('daedalus_stylish.ship');
    assert($ship instanceof Ship);
    $this->createFont('dusk_sans');
    $report = $ship->ship(self::TARGET, ['font' => ['broken_sans', 'dusk_sans']]);
    $this->assertSame([
      'font' => [
        self::TARGET . '.daedalus_stylish.fonts.broken_sans.yml' => ShippedDefinitionsImporter::FAILED,
        'dusk_sans' => FontShipper::CREATED,
      ],
    ], $report->outcomes());
    $this->assertSame('SHIPPED_FONT_FILE_MISSING', $report->errors('font', self::TARGET . '.daedalus_stylish.fonts.broken_sans.yml')[0]->getCode());
    $this->assertSame(self::TARGET, $this->loadFont('dusk_sans')->getProvider());
    $this->assertFileExists($this->asset('dusk_sans-regular.woff2'));

    // A definition naming no site font, or malformed, is refused.
    $ghost = ['label' => 'Ghost', 'faces' => [['source' => ['file' => 'ghost.woff2']]]];
    try {
      $this->shipper()->plan(self::TARGET, 'ghost', ['ghost' => $ghost]);
      $this->fail('A definition with no font to adopt must not plan.');
    }
    catch (ShippedFontException $e) {
      $this->assertSame('SHIPPED_FONT_ABSENT', $e->getErrors()[0]->getCode());
    }
    try {
      $this->shipper()->ship(self::TARGET, 'dusk', ['dusk_sans' => ['label' => 'Dusk', 'styles' => []]]);
      $this->fail('A definition the importer could not read back must not be written.');
    }
    catch (ShippedFontException $e) {
      $this->assertSame('INVALID_SHIPPED_FONT', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->duskFile());
  }

  /**
   * A definition always carries the membership and the opt-out, and they round-trip.
   */
  public function testDefinitionRoundTripsFace(): void {
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora'])->save();
    $member = $this->createFont('aurora_sans', ['design_system' => 'aurora']);
    $member->setOffered(FALSE)->save();
    $global = $this->createFont('plain_sans');
    $definitions = [
      'aurora_sans' => $this->shipper()->definition($member),
      'plain_sans' => $this->shipper()->definition($global),
    ];
    $this->assertSame('aurora', $definitions['aurora_sans']['design_system']);
    $this->assertFalse($definitions['aurora_sans']['ai']);
    $this->assertNull($definitions['plain_sans']['design_system']);
    $this->assertSame(['label', 'family', 'design_system', 'faces'], array_keys($definitions['plain_sans']));

    $this->assertSame(['aurora_sans' => FontShipper::CREATED, 'plain_sans' => FontShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', $definitions));
    $written = Yaml::decode(file_get_contents($this->duskFile()));
    $this->assertSame('aurora', $written['aurora_sans']['design_system']);
    $this->assertFalse($written['aurora_sans']['ai']);
    $this->assertArrayHasKey('design_system', $written['plain_sans']);
    $this->assertNull($written['plain_sans']['design_system']);

    $this->assertSame([
      self::TARGET => [
        'aurora_sans' => ShippedDefinitionsImporter::UNCHANGED,
        'plain_sans' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame('aurora', $this->loadFont('aurora_sans')->getDesignSystem());
    $this->assertFalse($this->loadFont('aurora_sans')->isOffered());
    $this->assertNull($this->loadFont('plain_sans')->getDesignSystem());
  }

}
