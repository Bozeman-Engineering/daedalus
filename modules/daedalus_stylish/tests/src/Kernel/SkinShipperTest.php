<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;
use Drupal\daedalus_stylish\SkinImporter;
use Drupal\daedalus_stylish\SkinShipper;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the skin shipper (the skins lane's site-to-disk direction).
 *
 * A ship writes MODULE.daedalus_stylish.skins.FILE_ID.yml into the target module and
 * adopts the shipped skins in place (same entity, provider stamped, source
 * hash over the keyed {tokens, defaults} payload, published), so the
 * importer's sync right after reports UNCHANGED: the round trip is the
 * lock. The target module is created under the test's own site directory
 * (sites/simpletest/ID, a root extension discovery scans), never a real
 * module directory, and removed on teardown; see VariantShipperTest for
 * the two conditions that make discovery see it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinShipperTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The tokens of the site-created dusk skin.
   */
  protected const DUSK_TOKENS = [
    'space' => ['gutter' => '2rem'],
    'color' => ['accent' => ['default' => '#111111', 'dark' => '#eeeeee']],
  ];

  /**
   * The defaults of the site-created dusk skin.
   */
  protected const DUSK_DEFAULTS = [
    'base:h2' => ['' => ['default' => ['font-size' => '3rem']]],
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
    'daedalus_stylish_control_test',
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
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', ['daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // The vocabulary a skin syncing back through the importer validates
    // against (the deserializer runs the skin field handlers on CREATED and
    // UPDATED): token scales for the tokens tier, base: for the defaults.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
      ],
      'base' => [
        'h2' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
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
   * Loads a skin by machine name, bypassing access.
   */
  protected function loadSkin(string $machine_name): ?DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Saves a site-created skin.
   */
  protected function createSkin(string $machine_name, array $tokens = self::DUSK_TOKENS, array $defaults = self::DUSK_DEFAULTS, array $values = []): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
    ]);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $skin->setTokens($tokens);
    $skin->setDefaults($defaults);
    $skin->save();
    return $skin;
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): SkinShipper {
    return \Drupal::service('daedalus_stylish.skin_shipper');
  }

  /**
   * The importer service, resolved late.
   */
  protected function importer(): SkinImporter {
    return \Drupal::service('daedalus_stylish.skin_importer');
  }

  /**
   * The per-id file the ship writes.
   */
  protected function duskFile(): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.skins.dusk.yml';
  }

  /**
   * A ship writes the per-id file, adopts in place, and syncs UNCHANGED.
   */
  public function testShipAdoptsSiteCreatedSkin(): void {
    $skin = $this->createSkin('dusk');
    $uuid = $skin->uuid();
    $id = $skin->id();
    $this->assertNull($skin->getProvider());
    $this->assertFalse($skin->isShipped());

    $definition = $this->shipper()->definition($skin);
    $this->assertSame([
      'label' => 'Dusk',
      'design_system' => NULL,
      'tokens' => self::DUSK_TOKENS,
      'defaults' => self::DUSK_DEFAULTS,
    ], $definition);
    $this->assertSame(self::TARGET . '.daedalus_stylish.skins.dusk.yml', basename($this->shipper()->filePath(self::TARGET, 'dusk')));

    // The plan is the dry run: nothing written, nothing adopted.
    $this->assertSame(['dusk' => SkinShipper::CREATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk' => $definition]));
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertNull($this->loadSkin('dusk')->getProvider());

    $this->assertSame(['dusk' => SkinShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => $definition]));
    $this->assertFileExists($this->duskFile());
    $this->assertSame(['dusk' => $definition], Yaml::decode(file_get_contents($this->duskFile())));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish (SkinShipper)', file_get_contents($this->duskFile()));

    // Adopted in place: same entity, now shipped by the target, unmodified,
    // the stamp over the keyed {tokens, defaults} payload the importer
    // hashes.
    $adopted = $this->loadSkin('dusk');
    $this->assertSame($uuid, $adopted->uuid());
    $this->assertSame($id, $adopted->id());
    $this->assertSame(self::TARGET, $adopted->getProvider());
    $this->assertTrue($adopted->isShipped());
    $this->assertFalse($adopted->isModified());
    $this->assertFalse($adopted->hasNewDefaults(['tokens' => self::DUSK_TOKENS, 'defaults' => self::DUSK_DEFAULTS]));
    $this->assertSame(DaedalusStylishSkin::hashData(['tokens' => self::DUSK_TOKENS, 'defaults' => self::DUSK_DEFAULTS]), $adopted->getSourceHash());
    $this->assertTrue($adopted->isPublished());
    $this->assertSame(self::DUSK_TOKENS, $adopted->getTokens());
    $this->assertSame(self::DUSK_DEFAULTS, $adopted->getDefaults());
    $revision_id = $adopted->getRevisionId();

    // Disk and site agree: discovery serves the file, sync touches nothing.
    $this->assertSame(['dusk' => $definition], $this->importer()->getShippedDefinitions(self::TARGET));
    $this->assertSame([self::TARGET => ['dusk' => SkinImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($revision_id, $this->loadSkin('dusk')->getRevisionId());

    // Shipping the same thing again changes nothing on disk or the entity.
    $this->assertSame(['dusk' => SkinShipper::UNCHANGED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk' => $definition]));
    $this->assertSame(['dusk' => SkinShipper::UNCHANGED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => $definition]));
    $this->assertSame($revision_id, $this->loadSkin('dusk')->getRevisionId());
  }

  /**
   * A site edit ships as an UPDATE: disk follows the site, hash restamped.
   *
   * Skins carry no distro lock, so a shipped skin edited on the site stays
   * a modified entity that sync leaves alone (disk unchanged reads
   * UNCHANGED, the edit kept); the ship is what carries the edit to disk
   * and clears the modified state.
   */
  public function testEditedSkinShipsAsUpdate(): void {
    $skin = $this->createSkin('dusk');
    $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => $this->shipper()->definition($skin)]);

    // An edit to the defaults tier alone: the one hash covers both tiers.
    $edited_defaults = ['base:h2' => ['' => ['default' => ['font-size' => '4rem']]]];
    $edited = $this->loadSkin('dusk');
    $edited->setDefaults($edited_defaults);
    $edited->save();
    $this->assertTrue($edited->isModified());
    $this->assertSame([self::TARGET => ['dusk' => SkinImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertTrue($this->loadSkin('dusk')->isModified());
    $this->assertSame(self::DUSK_DEFAULTS, Yaml::decode(file_get_contents($this->duskFile()))['dusk']['defaults']);

    $definition = $this->shipper()->definition($edited);
    $this->assertSame(['dusk' => SkinShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'dusk', ['dusk' => $definition]));
    $this->assertSame(['dusk' => SkinShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => $definition]));
    $written = Yaml::decode(file_get_contents($this->duskFile()));
    $this->assertSame($edited_defaults, $written['dusk']['defaults']);
    $this->assertSame(self::DUSK_TOKENS, $written['dusk']['tokens']);

    $shipped = $this->loadSkin('dusk');
    $this->assertSame($skin->uuid(), $shipped->uuid());
    $this->assertFalse($shipped->isModified());
    $this->assertSame(self::TARGET, $shipped->getProvider());
    $this->assertSame([self::TARGET => ['dusk' => SkinImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
  }

  /**
   * The file is rewritten whole: left-out names drop, an empty ship removes.
   */
  public function testShipRewritesFileWhole(): void {
    $dusk = $this->createSkin('dusk');
    $dawn = $this->createSkin('dawn', ['space' => ['gutter' => '1rem']], []);
    $definitions = [
      'dusk' => $this->shipper()->definition($dusk),
      'dawn' => $this->shipper()->definition($dawn),
    ];
    $this->assertSame(['dusk' => SkinShipper::CREATED, 'dawn' => SkinShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', $definitions));
    $this->assertSame(['dusk', 'dawn'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));
    $this->assertSame(self::TARGET, $this->loadSkin('dawn')->getProvider());

    $plan = $this->shipper()->plan(self::TARGET, 'dusk', ['dusk' => $definitions['dusk']]);
    $this->assertSame(['dusk' => SkinShipper::UNCHANGED, 'dawn' => SkinShipper::DROPPED], $plan);
    $this->assertSame($plan, $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => $definitions['dusk']]));
    $this->assertSame(['dusk'], array_keys(Yaml::decode(file_get_contents($this->duskFile()))));
    // The dropped skin lives on as site content: never deleted by disk.
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $this->loadSkin('dawn'));
    $this->assertSame(['dusk'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));

    $this->assertSame(['dusk' => SkinShipper::DROPPED], $this->shipper()->ship(self::TARGET, 'dusk', []));
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertSame([], $this->importer()->getShippedDefinitions(self::TARGET));
  }

  /**
   * A name shipped elsewhere is planned as such and refused by ship().
   *
   * Two shippers count as elsewhere: another module (daedalus_stylish_skins_test
   * ships aurora) and another file of the target module itself (its
   * hand-authored single file), since discovery would refuse the duplicate
   * anyway. Nothing is written when any name conflicts.
   */
  public function testShippedElsewhereRefused(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
    $aurora = $this->loadSkin('aurora');
    $this->assertSame('daedalus_stylish_skins_test', $aurora->getProvider());

    file_put_contents($this->targetPath . '/' . self::TARGET . '.daedalus_stylish.skins.yml', Yaml::encode([
      'hand_skin' => ['label' => 'Hand skin'],
    ]));
    $definitions = [
      'dusk' => $this->shipper()->definition($this->createSkin('dusk')),
      'aurora' => $this->shipper()->definition($aurora),
      'hand_skin' => ['label' => 'Hand skin'],
    ];
    $this->assertSame([
      'dusk' => SkinShipper::CREATED,
      'aurora' => SkinShipper::SHIPPED_ELSEWHERE,
      'hand_skin' => SkinShipper::SHIPPED_ELSEWHERE,
    ], $this->shipper()->plan(self::TARGET, 'dusk', $definitions));

    try {
      $this->shipper()->ship(self::TARGET, 'dusk', $definitions);
      $this->fail('A name shipped elsewhere must not ship again.');
    }
    catch (ShippedSkinException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('aurora', $e->getMachineName());
      $errors = $e->getErrors();
      $this->assertCount(2, $errors);
      $this->assertSame('SHIPPED_SKIN_CONFLICT', $errors[0]->getCode());
      $this->assertStringContainsString('the "daedalus_stylish_skins_test" module', $errors[0]->getMessage());
      $this->assertSame('SHIPPED_SKIN_CONFLICT', $errors[1]->getCode());
      $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.skins.yml', $errors[1]->getMessage());
    }
    // The whole run was refused: no file, nothing adopted.
    $this->assertFileDoesNotExist($this->duskFile());
    $this->assertNull($this->loadSkin('dusk')->getProvider());
    $this->assertSame('daedalus_stylish_skins_test', $this->loadSkin('aurora')->getProvider());
  }

  /**
   * A definition naming no site skin, or malformed, is refused.
   */
  public function testAbsentOrMalformedDefinitionRefused(): void {
    $ghost = ['label' => 'Ghost', 'tokens' => []];
    try {
      $this->shipper()->plan(self::TARGET, 'dusk', ['ghost' => $ghost]);
      $this->fail('A definition with no skin to adopt must not plan.');
    }
    catch (ShippedSkinException $e) {
      $this->assertSame('ghost', $e->getMachineName());
      $this->assertSame('SHIPPED_SKIN_ABSENT', $e->getErrors()[0]->getCode());
    }

    $this->createSkin('dusk');
    try {
      $this->shipper()->ship(self::TARGET, 'dusk', ['dusk' => ['label' => 'Dusk', 'styles' => []]]);
      $this->fail('A definition the importer could not read back must not be written.');
    }
    catch (ShippedSkinException $e) {
      $this->assertSame('INVALID_SHIPPED_SKIN', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->duskFile());
  }

  /**
   * A tier-less skin ships both tiers empty and round-trips unchanged.
   *
   * An empty map hashes exactly as an omitted tier does (the importer reads
   * a missing tier as []), so the bare skin's stamp matches the next sync.
   */
  public function testBareSkinRoundTrips(): void {
    $bare = $this->createSkin('bare', [], []);
    $definition = $this->shipper()->definition($bare);
    $this->assertSame(['label' => 'Bare', 'design_system' => NULL, 'tokens' => [], 'defaults' => []], $definition);
    $this->assertSame(['bare' => SkinShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', ['bare' => $definition]));
    $this->assertFalse($this->loadSkin('bare')->isModified());
    $this->assertSame([self::TARGET => ['bare' => SkinImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
  }

  /**
   * A definition always carries the membership, and it round-trips.
   *
   * Written explicitly (a member's id, ~ for global) because an omitted
   * key would read as the target module's own system on the next sync;
   * a ship then a sync leaves the membership exactly as shipped.
   */
  public function testDefinitionRoundTripsDesignSystem(): void {
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora'])->save();
    $member = $this->createSkin('aurora_dusk', self::DUSK_TOKENS, self::DUSK_DEFAULTS, ['design_system' => 'aurora']);
    $global = $this->createSkin('plain_dusk');
    $definitions = [
      'aurora_dusk' => $this->shipper()->definition($member),
      'plain_dusk' => $this->shipper()->definition($global),
    ];
    $this->assertSame('aurora', $definitions['aurora_dusk']['design_system']);
    $this->assertNull($definitions['plain_dusk']['design_system']);
    $this->assertSame(['label', 'design_system', 'tokens', 'defaults'], array_keys($definitions['plain_dusk']));

    $this->assertSame(['aurora_dusk' => SkinShipper::CREATED, 'plain_dusk' => SkinShipper::CREATED], $this->shipper()->ship(self::TARGET, 'dusk', $definitions));
    $written = Yaml::decode(file_get_contents($this->duskFile()));
    $this->assertSame('aurora', $written['aurora_dusk']['design_system']);
    $this->assertArrayHasKey('design_system', $written['plain_dusk']);
    $this->assertNull($written['plain_dusk']['design_system']);

    $this->assertSame([
      self::TARGET => ['aurora_dusk' => SkinImporter::UNCHANGED, 'plain_dusk' => SkinImporter::UNCHANGED],
    ], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame('aurora', $this->loadSkin('aurora_dusk')->getDesignSystem());
    $this->assertNull($this->loadSkin('plain_dusk')->getDesignSystem());
  }

}
