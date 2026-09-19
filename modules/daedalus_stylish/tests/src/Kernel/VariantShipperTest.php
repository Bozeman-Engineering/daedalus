<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\ShippedVariantException;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\daedalus_stylish\VariantShipper;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the variant shipper (the disk lane's site-to-disk direction).
 *
 * A ship writes MODULE.daedalus_stylish.variants.FILE_ID.yml into the target module
 * and adopts the shipped variants in place (same entity, provider stamped,
 * source hash set, published), so the importer's sync right after reports
 * UNCHANGED: the round trip is the lock. The target module is created
 * under the test's own site directory (sites/simpletest/ID, a root
 * extension discovery scans), never a real module directory, and removed
 * on teardown.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantShipperTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The styles of the site-created card variant.
   */
  protected const CARD_STYLES = ['' => ['default' => ['padding' => '2rem']]];

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

  /**
   * {@inheritdoc}
   *
   * The target module is written into the test's own REAL site directory
   * (sites/simpletest/ID): the vfs site directory the base class sets up is
   * no use, since the importer's per-id discovery globs and no stream
   * wrapper serves glob(). Two conditions make extension discovery see it,
   * both settled here, before the kernel boots: the directory must exist
   * before the boot-time scan (ExtensionDiscovery caches each scanned
   * directory statically for the process, so a module created later is
   * invisible), and the real path must stay a search root after boot, when
   * site.path becomes the vfs URL; the test_parent_site setting is the scan
   * root core keeps for exactly that (see ExtensionDiscovery::scan()).
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
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_animation_usage']);
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
   * Loads a variant by machine name, bypassing access.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariantInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * Saves a site-created container variant.
   */
  protected function createVariant(string $machine_name, array $styles = self::CARD_STYLES, array $values = []): DaedalusStylishVariantInterface {
    $variant = DaedalusStylishVariant::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'scope' => 'kind:container',
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles($styles);
    $variant->save();
    return $variant;
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): VariantShipper {
    return \Drupal::service('daedalus_stylish.variant_shipper');
  }

  /**
   * The importer service, resolved late.
   */
  protected function importer(): VariantImporter {
    return \Drupal::service('daedalus_stylish.variant_importer');
  }

  /**
   * The per-id file the ship writes.
   */
  protected function cardFile(): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.variants.card.yml';
  }

  /**
   * A ship writes the per-id file, adopts in place, and syncs UNCHANGED.
   */
  public function testShipAdoptsSiteCreatedVariant(): void {
    $variant = $this->createVariant('card_left', self::CARD_STYLES, ['description' => 'The card on the left.']);
    $uuid = $variant->uuid();
    $id = $variant->id();
    $this->assertNull($variant->getProvider());
    $this->assertSame(MaterialRecord::ORIGIN_USER, $variant->getOrigin());

    $definition = $this->shipper()->definition($variant);
    $this->assertSame([
      'label' => 'Card left',
      'scope' => 'kind:container',
      'description' => 'The card on the left.',
      'design_system' => NULL,
      'styles' => self::CARD_STYLES,
    ], $definition);
    $this->assertSame(self::TARGET . '.daedalus_stylish.variants.card.yml', basename($this->shipper()->filePath(self::TARGET, 'card')));

    // The plan is the dry run: nothing written, nothing adopted.
    $this->assertSame(['card_left' => VariantShipper::CREATED], $this->shipper()->plan(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertFileDoesNotExist($this->cardFile());
    $this->assertNull($this->loadVariant('card_left')->getProvider());

    $this->assertSame(['card_left' => VariantShipper::CREATED], $this->shipper()->ship(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertFileExists($this->cardFile());
    $this->assertSame(['card_left' => $definition], Yaml::decode(file_get_contents($this->cardFile())));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish', file_get_contents($this->cardFile()));

    // Adopted in place: same entity, now shipped by the target, unmodified.
    $adopted = $this->loadVariant('card_left');
    $this->assertSame($uuid, $adopted->uuid());
    $this->assertSame($id, $adopted->id());
    $this->assertSame(self::TARGET, $adopted->getProvider());
    $this->assertTrue($adopted->isShipped());
    $this->assertFalse($adopted->isModified());
    $this->assertFalse($adopted->hasNewDefaults(self::CARD_STYLES));
    $this->assertTrue($adopted->isPublished());
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $adopted->getOrigin());
    $this->assertSame(self::CARD_STYLES, $adopted->getStyles());
    $revision_id = $adopted->getRevisionId();

    // Disk and site agree: discovery serves the file, sync touches nothing.
    $this->assertSame(self::CARD_STYLES, $this->importer()->getShippedStyles($adopted));
    $this->assertSame([self::TARGET => ['card_left' => VariantImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame($revision_id, $this->loadVariant('card_left')->getRevisionId());

    // Shipping the same thing again changes nothing on disk or the entity.
    $this->assertSame(['card_left' => VariantShipper::UNCHANGED], $this->shipper()->plan(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertSame(['card_left' => VariantShipper::UNCHANGED], $this->shipper()->ship(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertSame($revision_id, $this->loadVariant('card_left')->getRevisionId());
  }

  /**
   * A site edit ships as an UPDATE: disk follows the site, hash restamped.
   */
  public function testEditedVariantShipsAsUpdate(): void {
    $variant = $this->createVariant('card_left');
    $this->shipper()->ship(self::TARGET, 'card', ['card_left' => $this->shipper()->definition($variant)]);

    $edited_styles = ['' => ['default' => ['padding' => '3rem']]];
    $edited = $this->loadVariant('card_left');
    $edited->setStyles($edited_styles);
    $edited->save();
    $this->assertTrue($edited->isModified());

    $definition = $this->shipper()->definition($edited);
    $this->assertSame(['card_left' => VariantShipper::UPDATED], $this->shipper()->plan(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertSame(['card_left' => VariantShipper::UPDATED], $this->shipper()->ship(self::TARGET, 'card', ['card_left' => $definition]));
    $this->assertSame($edited_styles, Yaml::decode(file_get_contents($this->cardFile()))['card_left']['styles']);

    $shipped = $this->loadVariant('card_left');
    $this->assertSame($variant->uuid(), $shipped->uuid());
    $this->assertFalse($shipped->isModified());
    $this->assertSame(self::TARGET, $shipped->getProvider());
    $this->assertSame([self::TARGET => ['card_left' => VariantImporter::UNCHANGED]], $this->importer()->sync([self::TARGET])->outcomes());
  }

  /**
   * The file is rewritten whole: left-out names drop, an empty ship removes.
   */
  public function testShipRewritesFileWhole(): void {
    $left = $this->createVariant('card_left');
    $right = $this->createVariant('card_right', ['' => ['default' => ['padding' => '1rem']]]);
    $definitions = [
      'card_left' => $this->shipper()->definition($left),
      'card_right' => $this->shipper()->definition($right),
    ];
    $this->assertSame(['card_left' => VariantShipper::CREATED, 'card_right' => VariantShipper::CREATED], $this->shipper()->ship(self::TARGET, 'card', $definitions));
    $this->assertSame(['card_left', 'card_right'], array_keys($this->importer()->getShippedDefinitions(self::TARGET)));

    $plan = $this->shipper()->plan(self::TARGET, 'card', ['card_left' => $definitions['card_left']]);
    $this->assertSame(['card_left' => VariantShipper::UNCHANGED, 'card_right' => VariantShipper::DROPPED], $plan);
    $this->assertSame($plan, $this->shipper()->ship(self::TARGET, 'card', ['card_left' => $definitions['card_left']]));
    $this->assertSame(['card_left'], array_keys(Yaml::decode(file_get_contents($this->cardFile()))));
    // The dropped variant lives on as site content: never deleted by disk.
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $this->loadVariant('card_right'));
    $this->assertNull($this->importer()->getShippedStyles($this->loadVariant('card_right')));

    $this->assertSame(['card_left' => VariantShipper::DROPPED], $this->shipper()->ship(self::TARGET, 'card', []));
    $this->assertFileDoesNotExist($this->cardFile());
    $this->assertSame([], $this->importer()->getShippedDefinitions(self::TARGET));
  }

  /**
   * A name shipped elsewhere is planned as such and refused by ship().
   *
   * Two shippers count as elsewhere: another module (daedalus_stylish_variants_test
   * ships testimonial_bold) and another file of the target module itself
   * (its hand-authored single file), since discovery would refuse the
   * duplicate anyway. Nothing is written when any name conflicts.
   */
  public function testShippedElsewhereRefused(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);
    $bold = $this->loadVariant('testimonial_bold');
    $this->assertSame('daedalus_stylish_variants_test', $bold->getProvider());

    file_put_contents($this->targetPath . '/' . self::TARGET . '.daedalus_stylish.variants.yml', Yaml::encode([
      'hand_section' => ['label' => 'Hand section', 'scope' => 'kind:container', 'styles' => []],
    ]));
    $definitions = [
      'card_left' => $this->shipper()->definition($this->createVariant('card_left')),
      'testimonial_bold' => $this->shipper()->definition($bold),
      'hand_section' => ['label' => 'Hand section', 'scope' => 'kind:container', 'styles' => []],
    ];
    $this->assertSame([
      'card_left' => VariantShipper::CREATED,
      'testimonial_bold' => VariantShipper::SHIPPED_ELSEWHERE,
      'hand_section' => VariantShipper::SHIPPED_ELSEWHERE,
    ], $this->shipper()->plan(self::TARGET, 'card', $definitions));

    try {
      $this->shipper()->ship(self::TARGET, 'card', $definitions);
      $this->fail('A name shipped elsewhere must not ship again.');
    }
    catch (ShippedVariantException $e) {
      $this->assertSame(self::TARGET, $e->getProvider());
      $this->assertSame('testimonial_bold', $e->getMachineName());
      $errors = $e->getErrors();
      $this->assertCount(2, $errors);
      $this->assertSame('SHIPPED_VARIANT_CONFLICT', $errors[0]->getCode());
      $this->assertStringContainsString('the "daedalus_stylish_variants_test" module', $errors[0]->getMessage());
      $this->assertSame('SHIPPED_VARIANT_CONFLICT', $errors[1]->getCode());
      $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.variants.yml', $errors[1]->getMessage());
    }
    // The whole run was refused: no file, nothing adopted.
    $this->assertFileDoesNotExist($this->cardFile());
    $this->assertNull($this->loadVariant('card_left')->getProvider());
    $this->assertSame('daedalus_stylish_variants_test', $this->loadVariant('testimonial_bold')->getProvider());
  }

  /**
   * A definition naming no site variant, or malformed, is refused.
   */
  public function testAbsentOrMalformedDefinitionRefused(): void {
    $ghost = ['label' => 'Ghost', 'scope' => 'kind:container', 'styles' => []];
    try {
      $this->shipper()->plan(self::TARGET, 'card', ['ghost' => $ghost]);
      $this->fail('A definition with no variant to adopt must not plan.');
    }
    catch (ShippedVariantException $e) {
      $this->assertSame('ghost', $e->getMachineName());
      $this->assertSame('SHIPPED_VARIANT_ABSENT', $e->getErrors()[0]->getCode());
    }

    $this->createVariant('card_left');
    try {
      $this->shipper()->ship(self::TARGET, 'card', ['card_left' => ['label' => 'Card left', 'styles' => []]]);
      $this->fail('A definition the importer could not read back must not be written.');
    }
    catch (ShippedVariantException $e) {
      $this->assertSame('INVALID_SHIPPED_VARIANT', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->cardFile());
  }

  /**
   * A definition carries the non-default axis, weight and AI opt-out in order.
   *
   * The opt-out has one spelling: ai: false, written only when withheld
   * (an offered variant's definition has no ai key, the default; see
   * testDefinitionRoundTripsDesignSystem's key list).
   */
  public function testDefinitionCarriesAxisAndWeight(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Testimonial loud',
      'machine_name' => 'testimonial_loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
      'weight' => 5,
      'ai_offered' => FALSE,
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles(['' => ['default' => ['font-size' => '1.5rem']]]);
    $variant->save();
    $this->assertSame([
      'label' => 'Testimonial loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
      'weight' => 5,
      'ai' => FALSE,
      'design_system' => NULL,
      'styles' => ['' => ['default' => ['font-size' => '1.5rem']]],
    ], $this->shipper()->definition($variant));
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
    $member = $this->createVariant('aurora_card', self::CARD_STYLES, ['design_system' => 'aurora']);
    $global = $this->createVariant('plain_card');
    $definitions = [
      'aurora_card' => $this->shipper()->definition($member),
      'plain_card' => $this->shipper()->definition($global),
    ];
    $this->assertSame('aurora', $definitions['aurora_card']['design_system']);
    $this->assertNull($definitions['plain_card']['design_system']);
    $this->assertSame(['label', 'scope', 'design_system', 'styles'], array_keys($definitions['plain_card']));

    $this->assertSame(['aurora_card' => VariantShipper::CREATED, 'plain_card' => VariantShipper::CREATED], $this->shipper()->ship(self::TARGET, 'card', $definitions));
    $written = Yaml::decode(file_get_contents($this->cardFile()));
    $this->assertSame('aurora', $written['aurora_card']['design_system']);
    $this->assertArrayHasKey('design_system', $written['plain_card']);
    $this->assertNull($written['plain_card']['design_system']);

    $this->assertSame([
      self::TARGET => ['aurora_card' => VariantImporter::UNCHANGED, 'plain_card' => VariantImporter::UNCHANGED],
    ], $this->importer()->sync([self::TARGET])->outcomes());
    $this->assertSame('aurora', $this->loadVariant('aurora_card')->getDesignSystem());
    $this->assertNull($this->loadVariant('plain_card')->getDesignSystem());
  }

}
