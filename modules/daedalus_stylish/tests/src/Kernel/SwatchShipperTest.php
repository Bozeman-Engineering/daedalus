<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Exception\ShippedSwatchException;
use Drupal\daedalus_stylish\SwatchImporter;
use Drupal\daedalus_stylish\SwatchShipper;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the swatch shipper (the swatches lane's site-to-disk direction).
 *
 * A ship writes MODULE.daedalus_stylish.swatches.FILE_ID.yml into the target
 * module and adopts the shipped swatches in place (same entity, provider
 * stamped, source hash over the keyed {kind, value} payload, published),
 * so the importer's sync right after reports UNCHANGED: the round trip is
 * the lock. The target module is created under the test's own site
 * directory (sites/simpletest/ID, a root extension discovery scans),
 * never a real module directory, and removed on teardown; see
 * VariantShipperTest for the two conditions that make discovery see it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchShipperTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The condition-keyed value of the site-created hero gradient.
   */
  protected const HERO_VALUE = [
    'default' => 'linear-gradient(160deg, #1a1a2e, #0000ff)',
    'dark' => 'linear-gradient(160deg, #0a0a14, #000080)',
  ];

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
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    // Every value-usage table: a save mirrors its references at the
    // chokepoint, and a missing table is a hard error.
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
   * Loads a swatch by machine name, bypassing access.
   */
  protected function loadSwatch(string $machine_name): ?DaedalusStylishSwatchInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_swatch');
    $storage->resetCache();
    $swatches = $storage->loadByProperties(['machine_name' => $machine_name]);
    $swatch = reset($swatches) ?: NULL;
    assert($swatch === NULL || $swatch instanceof DaedalusStylishSwatchInterface);
    return $swatch;
  }

  /**
   * Saves a site-created swatch.
   */
  protected function createSwatch(string $machine_name, string|array $value = '#0000ff', string $kind = 'color', array $values = []): DaedalusStylishSwatchInterface {
    $swatch = DaedalusStylishSwatch::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'kind' => $kind,
    ]);
    assert($swatch instanceof DaedalusStylishSwatchInterface);
    $swatch->setSwatchValue($value);
    $swatch->save();
    return $swatch;
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): SwatchShipper {
    return \Drupal::service('daedalus_stylish.swatch_shipper');
  }

  /**
   * The importer service, resolved late.
   */
  protected function importer(): SwatchImporter {
    return \Drupal::service('daedalus_stylish.swatch_importer');
  }

  /**
   * The per-id file the ship writes.
   */
  protected function brandFile(): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.swatches.brand.yml';
  }

  /**
   * A ship writes the per-id file, adopts in place, and syncs UNCHANGED.
   */
  public function testShipAdoptsSiteCreatedSwatch(): void {
    $swatch = $this->createSwatch('brand_blue', '#0000ff', 'color', ['description' => 'The link and the focus ring.']);
    $uuid = $swatch->uuid();
    $id = $swatch->id();
    $this->assertNull($swatch->getProvider());
    $this->assertFalse($swatch->isShipped());

    $definition = $this->shipper()->definition($swatch);
    $this->assertSame([
      'label' => 'Brand blue',
      'kind' => 'color',
      'description' => 'The link and the focus ring.',
      'design_system' => NULL,
      'value' => '#0000ff',
    ], $definition);
    $this->assertSame(self::TARGET . '.daedalus_stylish.swatches.brand.yml', basename($this->shipper()->filePath(self::TARGET, 'brand')));

    // The plan is the dry run: nothing written, nothing adopted.
    $this->assertSame(['brand_blue' => SwatchShipper::CREATED], $this->shipper()->plan(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertFileDoesNotExist($this->brandFile());
    $this->assertNull($this->loadSwatch('brand_blue')->getProvider());

    $this->assertSame(['brand_blue' => SwatchShipper::CREATED], $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertFileExists($this->brandFile());
    $this->assertSame(['brand_blue' => $definition], Yaml::decode(file_get_contents($this->brandFile())));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish (SwatchShipper)', file_get_contents($this->brandFile()));

    // Adopted in place: same entity, now shipped by the target, unmodified,
    // the stamp over the keyed {kind, value} payload the importer hashes.
    $adopted = $this->loadSwatch('brand_blue');
    $this->assertSame($uuid, $adopted->uuid());
    $this->assertSame($id, $adopted->id());
    $this->assertSame(self::TARGET, $adopted->getProvider());
    $this->assertTrue($adopted->isShipped());
    $this->assertFalse($adopted->isModified());
    $this->assertFalse($adopted->hasNewDefaults(['kind' => 'color', 'value' => '#0000ff']));
    $this->assertSame(DaedalusStylishSwatch::hashData(['kind' => 'color', 'value' => '#0000ff']), $adopted->getSourceHash());
    $this->assertTrue($adopted->isPublished());
    $this->assertSame('#0000ff', $adopted->getSwatchValue());
    $this->assertSame('The link and the focus ring.', $adopted->getDescription());
    $revision_id = $adopted->getRevisionId();

    // Disk and site agree: discovery serves the file, sync touches nothing.
    $this->assertSame(['brand_blue' => $definition], $this->importer()->getShippedDefinitions(self::TARGET));
    $this->assertSame(['kind' => 'color', 'value' => '#0000ff'], $this->importer()->getShippedData($adopted));
    $this->assertSame([self::TARGET => ['brand_blue' => SwatchImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($revision_id, $this->loadSwatch('brand_blue')->getRevisionId());

    // Shipping the same thing again changes nothing on disk or the entity.
    $this->assertSame(['brand_blue' => SwatchShipper::UNCHANGED], $this->shipper()->plan(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertSame(['brand_blue' => SwatchShipper::UNCHANGED], $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertSame($revision_id, $this->loadSwatch('brand_blue')->getRevisionId());
  }

  /**
   * A site edit ships as an UPDATE: disk follows the site, hash restamped.
   *
   * A shipped swatch edited on the site (the developer gate's flow) stays
   * a modified entity that sync leaves alone while disk is unchanged; the
   * ship is what carries the edit to disk and clears the modified state.
   */
  public function testEditedSwatchShipsAsUpdate(): void {
    $swatch = $this->createSwatch('brand_blue');
    $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => $this->shipper()->definition($swatch)]);

    $edited = $this->loadSwatch('brand_blue');
    $edited->setSwatchValue('#0000aa');
    $edited->save();
    $this->assertTrue($edited->isModified());
    $this->assertSame([self::TARGET => ['brand_blue' => SwatchImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertTrue($this->loadSwatch('brand_blue')->isModified());
    $this->assertSame('#0000ff', Yaml::decode(file_get_contents($this->brandFile()))['brand_blue']['value']);

    $definition = $this->shipper()->definition($edited);
    $this->assertSame(['brand_blue' => SwatchShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertSame(['brand_blue' => SwatchShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => $definition]));
    $this->assertSame('#0000aa', Yaml::decode(file_get_contents($this->brandFile()))['brand_blue']['value']);

    $shipped = $this->loadSwatch('brand_blue');
    $this->assertSame($swatch->uuid(), $shipped->uuid());
    $this->assertFalse($shipped->isModified());
    $this->assertSame(self::TARGET, $shipped->getProvider());
    $this->assertSame(DaedalusStylishSwatch::hashData(['kind' => 'color', 'value' => '#0000aa']), $shipped->getSourceHash());
    $this->assertSame([self::TARGET => ['brand_blue' => SwatchImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
  }

  /**
   * The file is rewritten whole: left-out names drop, an empty ship removes.
   */
  public function testShipRewritesFileWhole(): void {
    $blue = $this->createSwatch('brand_blue');
    $hero = $this->createSwatch('hero_fade', self::HERO_VALUE, 'gradient');
    $definitions = [
      'brand_blue' => $this->shipper()->definition($blue),
      'hero_fade' => $this->shipper()->definition($hero),
    ];
    $this->assertSame(['brand_blue' => SwatchShipper::CREATED, 'hero_fade' => SwatchShipper::CREATED], $this->shipper()->ship(self::TARGET, 'brand', $definitions));
    $this->assertSame(['brand_blue', 'hero_fade'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));
    $this->assertSame(self::TARGET, $this->loadSwatch('hero_fade')->getProvider());
    $this->assertSame(self::HERO_VALUE, Yaml::decode(file_get_contents($this->brandFile()))['hero_fade']['value']);

    $plan = $this->shipper()->plan(self::TARGET, 'brand', ['brand_blue' => $definitions['brand_blue']]);
    $this->assertSame(['brand_blue' => SwatchShipper::UNCHANGED, 'hero_fade' => SwatchShipper::DROPPED], $plan);
    $this->assertSame($plan, $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => $definitions['brand_blue']]));
    $this->assertSame(['brand_blue'], array_keys(Yaml::decode(file_get_contents($this->brandFile()))));
    // The dropped swatch lives on as site content: never deleted by disk.
    $this->assertInstanceOf(DaedalusStylishSwatchInterface::class, $this->loadSwatch('hero_fade'));
    $this->assertSame(['brand_blue'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));

    $this->assertSame(['brand_blue' => SwatchShipper::DROPPED], $this->shipper()->ship(self::TARGET, 'brand', []));
    $this->assertFileDoesNotExist($this->brandFile());
    $this->assertSame([], $this->importer()->getShippedDefinitions(self::TARGET));
  }

  /**
   * A name shipped elsewhere is planned as such and refused by ship().
   *
   * Two shippers count as elsewhere: another module
   * (daedalus_stylish_swatches_test ships brand_red) and another file of
   * the target module itself (its hand-authored single file), since
   * discovery would refuse the duplicate anyway. Nothing is written when
   * any name conflicts.
   */
  public function testShippedElsewhereRefused(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $red = $this->loadSwatch('brand_red');
    $this->assertSame('daedalus_stylish_swatches_test', $red->getProvider());

    $hand = ['label' => 'Hand swatch', 'kind' => 'color', 'value' => '#123456'];
    file_put_contents($this->targetPath . '/' . self::TARGET . '.daedalus_stylish.swatches.yml', Yaml::encode([
      'hand_swatch' => $hand,
    ]));
    $definitions = [
      'brand_blue' => $this->shipper()->definition($this->createSwatch('brand_blue')),
      'brand_red' => $this->shipper()->definition($red),
      'hand_swatch' => $hand,
    ];
    $this->assertSame([
      'brand_blue' => SwatchShipper::CREATED,
      'brand_red' => SwatchShipper::SHIPPED_ELSEWHERE,
      'hand_swatch' => SwatchShipper::SHIPPED_ELSEWHERE,
    ], $this->shipper()->plan(self::TARGET, 'brand', $definitions));

    try {
      $this->shipper()->ship(self::TARGET, 'brand', $definitions);
      $this->fail('A name shipped elsewhere must not ship again.');
    }
    catch (ShippedSwatchException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('brand_red', $e->getMachineName());
      $errors = $e->getErrors();
      $this->assertCount(2, $errors);
      $this->assertSame('SHIPPED_SWATCH_CONFLICT', $errors[0]->getCode());
      $this->assertStringContainsString('the "daedalus_stylish_swatches_test" module', $errors[0]->getMessage());
      $this->assertSame('SHIPPED_SWATCH_CONFLICT', $errors[1]->getCode());
      $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.swatches.yml', $errors[1]->getMessage());
    }
    // The whole run was refused: no file, nothing adopted.
    $this->assertFileDoesNotExist($this->brandFile());
    $this->assertNull($this->loadSwatch('brand_blue')->getProvider());
    $this->assertSame('daedalus_stylish_swatches_test', $this->loadSwatch('brand_red')->getProvider());
  }

  /**
   * A definition naming no site swatch, or malformed, is refused.
   */
  public function testAbsentOrMalformedDefinitionRefused(): void {
    $ghost = ['label' => 'Ghost', 'kind' => 'color', 'value' => '#000000'];
    try {
      $this->shipper()->plan(self::TARGET, 'brand', ['ghost' => $ghost]);
      $this->fail('A definition with no swatch to adopt must not plan.');
    }
    catch (ShippedSwatchException $e) {
      $this->assertSame('ghost', $e->getMachineName());
      $this->assertSame('SHIPPED_SWATCH_ABSENT', $e->getErrors()[0]->getCode());
    }

    $this->createSwatch('brand_blue');
    try {
      $this->shipper()->ship(self::TARGET, 'brand', ['brand_blue' => ['label' => 'Brand blue', 'styles' => []]]);
      $this->fail('A definition the importer could not read back must not be written.');
    }
    catch (ShippedSwatchException $e) {
      $this->assertSame('INVALID_SHIPPED_SWATCH', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->brandFile());
  }

  /**
   * A definition carries the shipped face and it round-trips.
   *
   * The membership is written explicitly (a member's id, ~ for global)
   * because an omitted key would read as the target module's own system
   * on the next sync; the AI opt-out is written only to withhold; the
   * description only when set (the importer refuses an empty one). A
   * ship then a sync leaves every fact exactly as shipped.
   */
  public function testDefinitionRoundTripsFace(): void {
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora'])->save();
    $member = $this->createSwatch('aurora_fade', self::HERO_VALUE, 'gradient', ['design_system' => 'aurora']);
    $member->setOffered(FALSE);
    $member->save();
    $global = $this->createSwatch('brand_blue');
    $definitions = [
      'aurora_fade' => $this->shipper()->definition($member),
      'brand_blue' => $this->shipper()->definition($global),
    ];
    $this->assertSame([
      'label' => 'Aurora fade',
      'kind' => 'gradient',
      'ai' => FALSE,
      'design_system' => 'aurora',
      'value' => self::HERO_VALUE,
    ], $definitions['aurora_fade']);
    $this->assertSame(['label', 'kind', 'design_system', 'value'], array_keys($definitions['brand_blue']));
    $this->assertNull($definitions['brand_blue']['design_system']);

    $this->assertSame(['aurora_fade' => SwatchShipper::CREATED, 'brand_blue' => SwatchShipper::CREATED], $this->shipper()->ship(self::TARGET, 'brand', $definitions));
    $written = Yaml::decode(file_get_contents($this->brandFile()));
    $this->assertSame('aurora', $written['aurora_fade']['design_system']);
    $this->assertFalse($written['aurora_fade']['ai']);
    $this->assertArrayNotHasKey('ai', $written['brand_blue']);
    $this->assertArrayHasKey('design_system', $written['brand_blue']);
    $this->assertNull($written['brand_blue']['design_system']);

    $this->assertSame([
      self::TARGET => ['aurora_fade' => SwatchImporter::UNCHANGED, 'brand_blue' => SwatchImporter::UNCHANGED],
    ], $this->importer()->sync([self::TARGET])->outcomes());
    $shipped = $this->loadSwatch('aurora_fade');
    $this->assertSame('aurora', $shipped->getDesignSystem());
    $this->assertFalse($shipped->isOffered());
    $this->assertSame(self::HERO_VALUE, $shipped->getSwatchValue());
    $this->assertNull($this->loadSwatch('brand_blue')->getDesignSystem());
    $this->assertTrue($this->loadSwatch('brand_blue')->isOffered());
  }

}
