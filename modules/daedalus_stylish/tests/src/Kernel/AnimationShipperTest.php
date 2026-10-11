<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationImporter;
use Drupal\daedalus_stylish\AnimationShipper;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Exception\ShippedAnimationException;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the animation shipper (the motion lane's site-to-disk direction).
 *
 * A ship writes MODULE.daedalus_stylish.animations.FILE_ID.yml into the target
 * module and adopts the shipped animations in place (same entity,
 * provider stamped, source hash over the canonical steps, published), so
 * the importer's sync right after reports UNCHANGED: the round trip is
 * the lock. The target module is created under the test's own site
 * directory (sites/simpletest/ID, a root extension discovery scans),
 * never a real module directory, and removed on teardown; see
 * VariantShipperTest for the two conditions that make discovery see it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationShipperTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The steps of the site-created fade, as stored (canonical offsets).
   */
  protected const FADE_STEPS = [
    '0%' => ['opacity' => '0'],
    '100%' => ['opacity' => '1'],
  ];

  /**
   * The steps a site edit moves the fade to.
   */
  protected const EDITED_STEPS = [
    '0%' => ['opacity' => '0.4'],
    '100%' => ['opacity' => '1'],
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
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_swatch');
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
   * Loads an animation by machine name, bypassing access.
   */
  protected function loadAnimation(string $machine_name): ?DaedalusStylishAnimationInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_animation');
    $storage->resetCache();
    $animations = $storage->loadByProperties(['machine_name' => $machine_name]);
    $animation = reset($animations) ?: NULL;
    assert($animation === NULL || $animation instanceof DaedalusStylishAnimationInterface);
    return $animation;
  }

  /**
   * Saves a site-created animation.
   *
   * Named site_* because daedalus_stylish itself ships fade_in and rise:
   * machine names are the site-wide vocabulary.
   */
  protected function createAnimation(string $machine_name, array $steps = self::FADE_STEPS, array $values = []): DaedalusStylishAnimationInterface {
    $animation = DaedalusStylishAnimation::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
    ]);
    assert($animation instanceof DaedalusStylishAnimationInterface);
    $animation->setSteps($steps);
    $animation->save();
    return $animation;
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): AnimationShipper {
    return \Drupal::service('daedalus_stylish.animation_shipper');
  }

  /**
   * The importer service, resolved late.
   */
  protected function importer(): AnimationImporter {
    return \Drupal::service('daedalus_stylish.animation_importer');
  }

  /**
   * The per-id file the ship writes.
   */
  protected function motionFile(): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.animations.motion.yml';
  }

  /**
   * A ship writes the per-id file, adopts in place, and syncs UNCHANGED.
   *
   * The site animation is minted with the `from`/`to` spelling; the
   * entity stores canonical offsets and the machine-written file carries
   * them, so the stamp is exactly what the next sync computes.
   */
  public function testShipAdoptsSiteCreatedAnimation(): void {
    $animation = $this->createAnimation('site_fade', ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']], ['description' => 'A soft entrance.']);
    $uuid = $animation->uuid();
    $id = $animation->id();
    $this->assertNull($animation->getProvider());
    $this->assertFalse($animation->isShipped());

    $definition = $this->shipper()->definition($animation);
    $this->assertSame([
      'label' => 'Site fade',
      'description' => 'A soft entrance.',
      'design_system' => NULL,
      'steps' => self::FADE_STEPS,
    ], $definition);
    $this->assertSame(self::TARGET . '.daedalus_stylish.animations.motion.yml', basename($this->shipper()->filePath(self::TARGET, 'motion')));

    // The plan is the dry run: nothing written, nothing adopted.
    $this->assertSame(['site_fade' => AnimationShipper::CREATED], $this->shipper()->plan(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertFileDoesNotExist($this->motionFile());
    $this->assertNull($this->loadAnimation('site_fade')->getProvider());

    $this->assertSame(['site_fade' => AnimationShipper::CREATED], $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertFileExists($this->motionFile());
    $this->assertSame(['site_fade' => $definition], Yaml::decode(file_get_contents($this->motionFile())));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish (AnimationShipper)', file_get_contents($this->motionFile()));

    // Adopted in place: same entity, now shipped by the target, unmodified,
    // the stamp over the canonical steps the importer hashes.
    $adopted = $this->loadAnimation('site_fade');
    $this->assertSame($uuid, $adopted->uuid());
    $this->assertSame($id, $adopted->id());
    $this->assertSame(self::TARGET, $adopted->getProvider());
    $this->assertTrue($adopted->isShipped());
    $this->assertFalse($adopted->isModified());
    $this->assertFalse($adopted->hasNewDefaults(self::FADE_STEPS));
    $this->assertSame(DaedalusStylishAnimation::hashData(self::FADE_STEPS), $adopted->getSourceHash());
    $this->assertTrue($adopted->isPublished());
    $this->assertSame(self::FADE_STEPS, $adopted->getSteps());
    $this->assertSame('A soft entrance.', $adopted->getDescription());
    $revision_id = $adopted->getRevisionId();

    // Disk and site agree: discovery serves the file, sync touches nothing.
    $this->assertSame(['site_fade' => $definition], $this->importer()->getShippedDefinitions(self::TARGET));
    $this->assertSame(self::FADE_STEPS, $this->importer()->getShippedData($adopted));
    $this->assertSame([self::TARGET => ['site_fade' => AnimationImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($revision_id, $this->loadAnimation('site_fade')->getRevisionId());

    // Shipping the same thing again changes nothing on disk or the entity.
    $this->assertSame(['site_fade' => AnimationShipper::UNCHANGED], $this->shipper()->plan(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertSame(['site_fade' => AnimationShipper::UNCHANGED], $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertSame($revision_id, $this->loadAnimation('site_fade')->getRevisionId());
  }

  /**
   * A site edit ships as an UPDATE: disk follows the site, hash restamped.
   *
   * A shipped animation edited on the site (the developer gate's flow)
   * stays a modified entity that sync leaves alone while disk is
   * unchanged; the ship is what carries the edit to disk and clears the
   * modified state.
   */
  public function testEditedAnimationShipsAsUpdate(): void {
    $animation = $this->createAnimation('site_fade');
    $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => $this->shipper()->definition($animation)]);

    $edited = $this->loadAnimation('site_fade');
    $edited->setSteps(self::EDITED_STEPS);
    $edited->save();
    $this->assertTrue($edited->isModified());
    $this->assertSame([self::TARGET => ['site_fade' => AnimationImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertTrue($this->loadAnimation('site_fade')->isModified());
    $this->assertSame(self::FADE_STEPS, Yaml::decode(file_get_contents($this->motionFile()))['site_fade']['steps']);

    $definition = $this->shipper()->definition($edited);
    $this->assertSame(['site_fade' => AnimationShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertSame(['site_fade' => AnimationShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => $definition]));
    $this->assertSame(self::EDITED_STEPS, Yaml::decode(file_get_contents($this->motionFile()))['site_fade']['steps']);

    $shipped = $this->loadAnimation('site_fade');
    $this->assertSame($animation->uuid(), $shipped->uuid());
    $this->assertFalse($shipped->isModified());
    $this->assertSame(self::TARGET, $shipped->getProvider());
    $this->assertSame(DaedalusStylishAnimation::hashData(self::EDITED_STEPS), $shipped->getSourceHash());
    $this->assertSame([self::TARGET => ['site_fade' => AnimationImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
  }

  /**
   * The file is rewritten whole: left-out names drop, an empty ship removes.
   */
  public function testShipRewritesFileWhole(): void {
    $fade = $this->createAnimation('site_fade');
    $rise = $this->createAnimation('site_rise', [
      '0%' => ['opacity' => '0', 'transform' => 'translateY(1rem)'],
      '100%' => ['opacity' => '1', 'transform' => 'none'],
    ]);
    $definitions = [
      'site_fade' => $this->shipper()->definition($fade),
      'site_rise' => $this->shipper()->definition($rise),
    ];
    $this->assertSame(['site_fade' => AnimationShipper::CREATED, 'site_rise' => AnimationShipper::CREATED], $this->shipper()->ship(self::TARGET, 'motion', $definitions));
    $this->assertSame(['site_fade', 'site_rise'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));
    $this->assertSame(self::TARGET, $this->loadAnimation('site_rise')->getProvider());

    $plan = $this->shipper()->plan(self::TARGET, 'motion', ['site_fade' => $definitions['site_fade']]);
    $this->assertSame(['site_fade' => AnimationShipper::UNCHANGED, 'site_rise' => AnimationShipper::DROPPED], $plan);
    $this->assertSame($plan, $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => $definitions['site_fade']]));
    $this->assertSame(['site_fade'], array_keys(Yaml::decode(file_get_contents($this->motionFile()))));
    // The dropped animation lives on as site content: never deleted by disk.
    $this->assertInstanceOf(DaedalusStylishAnimationInterface::class, $this->loadAnimation('site_rise'));
    $this->assertSame(['site_fade'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));

    $this->assertSame(['site_fade' => AnimationShipper::DROPPED], $this->shipper()->ship(self::TARGET, 'motion', []));
    $this->assertFileDoesNotExist($this->motionFile());
    $this->assertSame([], $this->importer()->getShippedDefinitions(self::TARGET));
  }

  /**
   * A name shipped elsewhere is planned as such and refused by ship().
   *
   * Two shippers count as elsewhere: another module
   * (daedalus_stylish_animations_test ships test_fade) and another file of
   * the target module itself (its hand-authored single file), since
   * discovery would refuse the duplicate anyway. Nothing is written when
   * any name conflicts.
   */
  public function testShippedElsewhereRefused(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $test_fade = $this->loadAnimation('test_fade');
    $this->assertSame('daedalus_stylish_animations_test', $test_fade->getProvider());

    $hand = ['label' => 'Hand fade', 'steps' => self::FADE_STEPS];
    file_put_contents($this->targetPath . '/' . self::TARGET . '.daedalus_stylish.animations.yml', Yaml::encode([
      'hand_fade' => $hand,
    ]));
    $definitions = [
      'site_fade' => $this->shipper()->definition($this->createAnimation('site_fade')),
      'test_fade' => $this->shipper()->definition($test_fade),
      'hand_fade' => $hand,
    ];
    $this->assertSame([
      'site_fade' => AnimationShipper::CREATED,
      'test_fade' => AnimationShipper::SHIPPED_ELSEWHERE,
      'hand_fade' => AnimationShipper::SHIPPED_ELSEWHERE,
    ], $this->shipper()->plan(self::TARGET, 'motion', $definitions));

    try {
      $this->shipper()->ship(self::TARGET, 'motion', $definitions);
      $this->fail('A name shipped elsewhere must not ship again.');
    }
    catch (ShippedAnimationException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('test_fade', $e->getMachineName());
      $errors = $e->getErrors();
      $this->assertCount(2, $errors);
      $this->assertSame('SHIPPED_ANIMATION_CONFLICT', $errors[0]->getCode());
      $this->assertStringContainsString('the "daedalus_stylish_animations_test" module', $errors[0]->getMessage());
      $this->assertSame('SHIPPED_ANIMATION_CONFLICT', $errors[1]->getCode());
      $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.animations.yml', $errors[1]->getMessage());
    }
    // The whole run was refused: no file, nothing adopted.
    $this->assertFileDoesNotExist($this->motionFile());
    $this->assertNull($this->loadAnimation('site_fade')->getProvider());
    $this->assertSame('daedalus_stylish_animations_test', $this->loadAnimation('test_fade')->getProvider());
  }

  /**
   * A definition naming no site animation, or malformed, is refused.
   */
  public function testAbsentOrMalformedDefinitionRefused(): void {
    $ghost = ['label' => 'Ghost', 'steps' => self::FADE_STEPS];
    try {
      $this->shipper()->plan(self::TARGET, 'motion', ['ghost' => $ghost]);
      $this->fail('A definition with no animation to adopt must not plan.');
    }
    catch (ShippedAnimationException $e) {
      $this->assertSame('ghost', $e->getMachineName());
      $this->assertSame('SHIPPED_ANIMATION_ABSENT', $e->getErrors()[0]->getCode());
    }

    $this->createAnimation('site_fade');
    try {
      $this->shipper()->ship(self::TARGET, 'motion', ['site_fade' => ['label' => 'Site fade', 'styles' => []]]);
      $this->fail('A definition the importer could not read back must not be written.');
    }
    catch (ShippedAnimationException $e) {
      $this->assertSame('INVALID_SHIPPED_ANIMATION', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->motionFile());
  }

  /**
   * A definition carries the shipped face and it round-trips.
   *
   * The membership is written explicitly (a member's id, ~ for global)
   * because an omitted key would read as the target module's own system
   * on the next sync; the AI opt-out is written only to withhold; the
   * description only when set. A ship then a sync leaves every fact
   * exactly as shipped.
   */
  public function testDefinitionRoundTripsFace(): void {
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora'])->save();
    $member = $this->createAnimation('aurora_fade', self::FADE_STEPS, ['design_system' => 'aurora']);
    $member->setOffered(FALSE);
    $member->save();
    $global = $this->createAnimation('site_fade');
    $definitions = [
      'aurora_fade' => $this->shipper()->definition($member),
      'site_fade' => $this->shipper()->definition($global),
    ];
    $this->assertSame([
      'label' => 'Aurora fade',
      'ai' => FALSE,
      'design_system' => 'aurora',
      'steps' => self::FADE_STEPS,
    ], $definitions['aurora_fade']);
    $this->assertSame(['label', 'design_system', 'steps'], array_keys($definitions['site_fade']));
    $this->assertNull($definitions['site_fade']['design_system']);

    $this->assertSame(['aurora_fade' => AnimationShipper::CREATED, 'site_fade' => AnimationShipper::CREATED], $this->shipper()->ship(self::TARGET, 'motion', $definitions));
    $written = Yaml::decode(file_get_contents($this->motionFile()));
    $this->assertSame('aurora', $written['aurora_fade']['design_system']);
    $this->assertFalse($written['aurora_fade']['ai']);
    $this->assertArrayNotHasKey('ai', $written['site_fade']);
    $this->assertArrayHasKey('design_system', $written['site_fade']);
    $this->assertNull($written['site_fade']['design_system']);

    $this->assertSame([
      self::TARGET => ['aurora_fade' => AnimationImporter::UNCHANGED, 'site_fade' => AnimationImporter::UNCHANGED],
    ], $this->importer()->sync([self::TARGET])->outcomes());
    $shipped = $this->loadAnimation('aurora_fade');
    $this->assertSame('aurora', $shipped->getDesignSystem());
    $this->assertFalse($shipped->isOffered());
    $this->assertSame('', $shipped->getDescription());
    $this->assertNull($this->loadAnimation('site_fade')->getDesignSystem());
    $this->assertTrue($this->loadAnimation('site_fade')->isOffered());
  }

}
