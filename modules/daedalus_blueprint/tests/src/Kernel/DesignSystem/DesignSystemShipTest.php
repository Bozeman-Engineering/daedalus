<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\DesignSystem;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemShip;
use Drupal\daedalus_blueprint\DesignSystem\ShipReport;
use Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException;
use Drupal\daedalus_blueprint\Drush\Commands\DesignSystemShipCommand;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintShipper;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedDefinitionsShipper;
use Drupal\Tests\daedalus_blueprint_library\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the package ship: one command ships a design system to its module.
 *
 * DesignSystemShip resolves the module (the system's package, or the one
 * named for a system with none), creates and installs it when it does
 * not exist, lands the unit as install config with `package` set, and
 * runs every tagged package shipper over the same report: the blueprint
 * lane graduates the system's content blueprints (their owned variants in
 * the blueprints' own variants files, with their blueprint: keys), then
 * daedalus_stylish's lane rebuilds the module's per-id files and adopts
 * the system's unshipped materials. The plan is the dry run, a second
 * ship of a converged package reads unchanged, and the command prints
 * the report in the sync command's grammar. The target module lives
 * under the test's own site directory (sites/simpletest/ID), never a
 * real module directory, and is removed on teardown.
 */
#[CoversClass(DesignSystemShip::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DesignSystemShipTest extends BlueprintKernelTestBase {

  /**
   * The packaged module the tests ship into.
   */
  protected const TARGET = 'ds_ship_target';

  /**
   * The module the create leg brings into being.
   */
  protected const CREATED = 'borealis_pkg';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish', 'daedalus_stylish_control_test'];

  /**
   * The test site's directory, relative to the Drupal root.
   */
  protected string $site;

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

  /**
   * {@inheritdoc}
   *
   * The target module is written into the test's REAL site directory
   * before the kernel boots and the real path is kept as a scan root
   * through the test_parent_site setting (BlueprintShipperTest explains
   * why). The created module lands in the same directory, after boot:
   * DesignSystemShip forgets core's scan cache so the installer sees it.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->site = (new TestDatabase($this->databasePrefix))->getTestSitePath();
    $this->targetPath = $this->root . '/' . $this->site . '/modules/' . self::TARGET;
    mkdir($this->targetPath, 0777, TRUE);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.info.yml', Yaml::encode([
      'name' => 'Design system ship target',
      'type' => 'module',
      'core_version_requirement' => '*',
      'hidden' => TRUE,
      'dependencies' => ['daedalus:daedalus_stylish', 'daedalus:daedalus_blueprint_library'],
    ]));
    $this->setSetting('test_parent_site', $this->site);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    // The vocabulary a skin definition validates against on the sync
    // right after a ship.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->enableModules([self::TARGET]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->targetPath) && is_dir($site = dirname($this->targetPath, 2))) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
      }
      rmdir($site);
    }
    parent::tearDown();
  }

  /**
   * The package ship, resolved late (installs rebuild the container).
   */
  protected function ship(): DesignSystemShip {
    return \Drupal::service(DesignSystemShip::SERVICE_ID);
  }

  /**
   * The activation service, resolved late.
   */
  protected function active(): ActiveDesignSystem {
    return \Drupal::service(ActiveDesignSystem::SERVICE_ID);
  }

  /**
   * Creates a container-scoped variant, a member of a system when named.
   */
  protected function createVariant(string $machine_name, ?string $design_system = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '2rem']]],
      'design_system' => $design_system,
    ]);
    $variant->save();
    return $variant;
  }

  /**
   * Loads a variant by machine name, uncached.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariant {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariant);
    return $variant;
  }

  /**
   * Creates a site skin, a member of a system when named.
   */
  protected function createSkin(string $machine_name, ?string $design_system = NULL): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create([
      'label' => ucfirst($machine_name),
      'machine_name' => $machine_name,
      'design_system' => $design_system,
    ]);
    $skin->setPackageTokens(['space' => ['gutter' => '2rem']]);
    $skin->save();
    return $skin;
  }

  /**
   * Loads a skin by machine name, uncached.
   */
  protected function loadSkin(string $machine_name): ?DaedalusStylishSkin {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkin);
    return $skin;
  }

  /**
   * Creates a saved blueprint wearing the given variants, a member when named.
   *
   * The document is instantiated the way a capture writes it (one onecol
   * root), so a worn variant must apply to the root: the apply gate, as on
   * a page.
   */
  protected function createMemberBlueprint(string $label, string $machine_name, array $variants = [], ?string $design_system = NULL): Blueprint {
    $root = ['kind' => 'container', 'layout_id' => 'layout_onecol'];
    if ($variants !== []) {
      $root['styles'] = ['' => ['variants' => $variants]];
    }
    return $this->createBlueprintFromTree([
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => "The $label blueprint.",
      'design_system' => $design_system,
    ], [$root]);
  }

  /**
   * A file of the target module, absolute.
   */
  protected function file(string $name): string {
    return $this->targetPath . '/' . self::TARGET . ".$name";
  }

  /**
   * The unit's install config file in a module, absolute.
   */
  protected function unitFile(string $module, string $system_id): string {
    return $this->root . '/' . $this->ship()->unitPath($module, DesignSystem::load($system_id));
  }

  /**
   * A fresh tester over the command, from the current container.
   */
  protected function tester(): CommandTester {
    return new CommandTester(DesignSystemShipCommand::create(\Drupal::getContainer()));
  }

  /**
   * The command's display as trimmed lines.
   *
   * @return string[]
   *   The lines.
   */
  protected function lines(CommandTester $tester): array {
    return array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())), static fn (string $line): bool => $line !== ''));
  }

  /**
   * The whole system ships into its package: the unit, then every lane.
   *
   * The plan and the ship read the same outcomes; the blueprint lane runs
   * first (its owned variant lands in the blueprint's variants file with
   * its blueprint: key, and the materials lane's rebuild of that file
   * right after does not overwrite the outcome); the materials lane
   * adopts the system's unshipped skin and variant; a global content
   * blueprint stays; the graduated blueprint is gone from the site and
   * served from the file; a sync right after reads UNCHANGED; a second
   * ship reads unchanged throughout; and a change to the unit reaches
   * its file as an update.
   */
  public function testShipsIntoThePackage(): void {
    DesignSystem::create(['id' => 'nebula', 'label' => 'Nebula', 'package' => self::TARGET])->save();
    // Active, so the sync right after reads the package instead of
    // deferring it (a package waits for its system's activation).
    $this->active()->activate('nebula');
    $this->createVariant('band_look', 'nebula');
    $blueprint = $this->createMemberBlueprint('Nebula band', 'nebula_band', ['band_look'], 'nebula');
    \Drupal::service('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['band_look']);
    $this->createVariant('card_blue', 'nebula');
    $this->createSkin('dusk', 'nebula');
    $this->createMemberBlueprint('Free band', 'free_band');

    $expected = [
      DesignSystemShip::KIND_UNIT => ['nebula' => DesignSystemShip::CREATED],
      BlueprintShipper::KIND => ['nebula_band' => BlueprintShipper::CREATED],
      'variant' => [
        'band_look' => ShippedDefinitionsShipper::CREATED,
        'card_blue' => ShippedDefinitionsShipper::CREATED,
      ],
      'skin' => ['dusk' => ShippedDefinitionsShipper::CREATED],
    ];
    $plan = $this->ship()->plan('nebula');
    $this->assertSame(self::TARGET, $plan->module());
    $this->assertSame($expected, $plan->outcomes());
    $this->assertFalse($plan->hasFailures());
    $this->assertSame(self::TARGET . '.daedalus_blueprint.blueprints.nebula_band.yml', $plan->file(BlueprintShipper::KIND, 'nebula_band'));
    $this->assertSame(self::TARGET . '.daedalus_stylish.variants.nebula_band.yml', $plan->file('variant', 'band_look'));
    $this->assertSame(self::TARGET . '.daedalus_stylish.variants.card_blue.yml', $plan->file('variant', 'card_blue'));
    $this->assertSame('daedalus_blueprint.design_system.nebula.yml', $plan->file(DesignSystemShip::KIND_UNIT, 'nebula'));
    $this->assertCount(1, $plan->notes());
    $this->assertStringContainsString('blueprint nebula_band graduates: the content blueprint "Nebula band"', $plan->notes()[0]);
    // The dry run touched nothing.
    $this->assertFileDoesNotExist($this->unitFile(self::TARGET, 'nebula'));
    $this->assertFileDoesNotExist($this->file('daedalus_blueprint.blueprints.nebula_band.yml'));
    $this->assertInstanceOf(Blueprint::class, Blueprint::load($blueprint->id()));
    $this->assertNull($this->loadVariant('band_look')->getProvider());

    $report = $this->ship()->ship('nebula');
    $this->assertSame($expected, $report->outcomes());
    $this->assertFalse($report->hasFailures());

    // The unit: the site's record minus its uuid, package and dependency set.
    $unit = Yaml::decode(file_get_contents($this->unitFile(self::TARGET, 'nebula')));
    $this->assertSame([
      'langcode',
      'status',
      'dependencies',
      'id',
      'label',
      'description',
      'icon',
      'default_skin',
      'package',
      'enforcement',
    ], array_keys($unit));
    $this->assertSame('nebula', $unit['id']);
    $this->assertSame(self::TARGET, $unit['package']);
    $this->assertSame(['module' => [self::TARGET]], $unit['dependencies']);
    $this->assertSame('guarded', $unit['enforcement']);
    $this->assertSame(['nebula' => self::TARGET], $this->active()->shippedPackages());

    // The blueprint lane: the file in discovery's envelope, the owned
    // variant with its key, the graduation, the global blueprint left
    // alone.
    $written = Yaml::decode(file_get_contents($this->file('daedalus_blueprint.blueprints.nebula_band.yml')));
    $this->assertSame(['label', 'description', 'design_system', 'scope', 'subject', 'layout'], array_keys($written));
    $this->assertSame('nebula', $written['design_system']);
    $this->assertSame('block', $written['scope']);
    $this->assertSame('entity_test:entity_test', $written['subject']);
    $this->assertSame([['variant' => 'band_look', 'fragment' => '']], $written['layout'][0]['styles']['']['variants']);
    $band = Yaml::decode(file_get_contents($this->file('daedalus_stylish.variants.nebula_band.yml')));
    $this->assertSame(['band_look'], array_keys($band));
    $this->assertSame('nebula_band', $band['band_look']['blueprint']);
    $this->assertSame('nebula', $band['band_look']['design_system']);
    $this->assertNull(Blueprint::load($blueprint->id()), 'The content blueprint graduated.');
    $this->assertCount(1, Blueprint::loadMultiple(), 'The global content blueprint stays.');
    $served = \Drupal::service('daedalus_blueprint_library.discovery')->getBlueprint('nebula_band');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame(self::TARGET, $served['provider']);
    $this->assertSame($written['layout'], $served['layout']);
    $this->assertSame(self::TARGET, $this->loadVariant('band_look')->getProvider());
    $this->assertSame('nebula_band', $this->loadVariant('band_look')->get('blueprint_file')->value);
    $this->assertTrue($this->loadVariant('band_look')->get('blueprint')->isEmpty());

    // The materials lane: the system's unshipped skin and variant adopted.
    $this->assertSame(['card_blue'], array_keys(Yaml::decode(file_get_contents($this->file('daedalus_stylish.variants.card_blue.yml')))));
    $this->assertSame('nebula', Yaml::decode(file_get_contents($this->file('daedalus_stylish.skins.dusk.yml')))['dusk']['design_system']);
    $this->assertSame(self::TARGET, $this->loadVariant('card_blue')->getProvider());
    $this->assertSame(self::TARGET, $this->loadSkin('dusk')->getProvider());

    // Disk and site agree: the sync reads UNCHANGED, the next ship too.
    $sync = \Drupal::service('daedalus_stylish.shipped_sync')->sync([self::TARGET]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $sync->report('skin')->outcomes());
    // Per-id files sync in glob order, so the pin is order-free.
    $this->assertEquals([
      self::TARGET => [
        'band_look' => ShippedDefinitionsImporter::UNCHANGED,
        'card_blue' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $sync->report('variant')->outcomes());
    // No blueprint left to graduate, so the rebuild alone reads the files,
    // in glob order: the pin is order-free.
    $this->assertEquals([
      DesignSystemShip::KIND_UNIT => ['nebula' => ShipReport::UNCHANGED],
      'variant' => [
        'band_look' => ShippedDefinitionsShipper::UNCHANGED,
        'card_blue' => ShippedDefinitionsShipper::UNCHANGED,
      ],
      'skin' => ['dusk' => ShippedDefinitionsShipper::UNCHANGED],
    ], $this->ship()->ship('nebula')->outcomes());

    // A changed unit reaches its file.
    DesignSystem::load('nebula')->set('label', 'Nebula, revised')->save();
    $this->assertSame(DesignSystemShip::UPDATED, $this->ship()->plan('nebula')->outcome(DesignSystemShip::KIND_UNIT, 'nebula'));
    $this->assertSame('Nebula', Yaml::decode(file_get_contents($this->unitFile(self::TARGET, 'nebula')))['label']);
    $this->assertSame(DesignSystemShip::UPDATED, $this->ship()->ship('nebula')->outcome(DesignSystemShip::KIND_UNIT, 'nebula'));
    $this->assertSame('Nebula, revised', Yaml::decode(file_get_contents($this->unitFile(self::TARGET, 'nebula')))['label']);
  }

  /**
   * A refused blueprint fails its line; the other lanes still ship.
   *
   * A content blueprint whose machine name is not a blueprint id (a file
   * name suffix) is refused with the shipper's own error, recorded
   * FAILED under its name, and the materials lane runs regardless.
   */
  public function testRefusalFailsTheLineAlone(): void {
    DesignSystem::create(['id' => 'nebula', 'label' => 'Nebula', 'package' => self::TARGET])->save();
    $this->createMemberBlueprint('Bad band', 'Bad band', [], 'nebula');
    $this->createVariant('card_blue', 'nebula');
    $this->createSkin('dusk', 'nebula');

    $report = $this->ship()->plan('nebula');
    $this->assertSame([
      DesignSystemShip::KIND_UNIT => ['nebula' => DesignSystemShip::CREATED],
      BlueprintShipper::KIND => ['Bad band' => ShipReport::FAILED],
      'skin' => ['dusk' => ShippedDefinitionsShipper::CREATED],
      'variant' => ['card_blue' => ShippedDefinitionsShipper::CREATED],
    ], $report->outcomes());
    $this->assertTrue($report->hasFailures());
    $this->assertSame('BLUEPRINT_SHIP_INVALID_ID', $report->errors(BlueprintShipper::KIND, 'Bad band')[0]->getCode());
    $this->assertSame([
      self::TARGET . ': blueprint Bad band failed',
      '  id [BLUEPRINT_SHIP_INVALID_ID]: "Bad band" is not a blueprint id.',
      '    Hint: Blueprint ids are lower-case letters, digits, and underscores (the file name suffix).',
    ], $report->failureLines());
    $this->assertFileDoesNotExist($this->unitFile(self::TARGET, 'nebula'));

    $this->assertSame($report->outcomes(), $this->ship()->ship('nebula')->outcomes());
    $this->assertFileExists($this->unitFile(self::TARGET, 'nebula'));
    $this->assertFileExists($this->file('daedalus_stylish.skins.dusk.yml'));
    $this->assertCount(1, Blueprint::loadMultiple(), 'The refused blueprint stays on the site.');
  }

  /**
   * A system with no package ships into a module the ship creates.
   *
   * Without a module there is nothing to ship into; with one named, the
   * dry run stops at the module line, and the ship creates the module
   * (an info file depending on every lane's readers), installs it, sets
   * the unit's package and lands the unit; afterwards the system ships
   * without naming the module. Another module is refused for a packaged
   * system, a packaging module is refused for another system, and an
   * unknown id is refused naming the known ids.
   */
  public function testCreatesTheModule(): void {
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->createSkin('dawn', 'borealis');
    try {
      $this->ship()->plan('borealis');
      $this->fail('A system with no package planned into nothing.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('The design system "borealis" has no package; name the module to ship it into (it is created when it does not exist).', $e->getMessage());
    }

    $plan = $this->ship()->plan('borealis', self::CREATED);
    $this->assertSame([DesignSystemShip::KIND_MODULE => [self::CREATED => DesignSystemShip::CREATED]], $plan->outcomes());
    $this->assertStringContainsString('the dry run stops here', $plan->notes()[0]);
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists(self::CREATED));
    $this->assertNull(DesignSystem::load('borealis')->getPackage());

    $report = $this->ship()->ship('borealis', self::CREATED, $this->site . '/modules');
    $this->assertSame([
      DesignSystemShip::KIND_MODULE => [self::CREATED => DesignSystemShip::CREATED],
      DesignSystemShip::KIND_UNIT => ['borealis' => DesignSystemShip::CREATED],
      'skin' => ['dawn' => ShippedDefinitionsShipper::CREATED],
    ], $report->outcomes());
    $this->assertSame(sprintf('%s: created at %s/modules/%s.', self::CREATED, $this->site, self::CREATED), $report->notes()[0]);
    $path = $this->root . '/' . $this->site . '/modules/' . self::CREATED;
    $info = Yaml::decode(file_get_contents($path . '/' . self::CREATED . '.info.yml'));
    $this->assertSame('Borealis', $info['name']);
    $this->assertSame('module', $info['type']);
    $this->assertSame([
      'daedalus:daedalus_blueprint',
      'daedalus:daedalus_blueprint_library',
      'daedalus:daedalus_stylish',
    ], $info['dependencies']);
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists(self::CREATED), 'The created module is installed.');
    $this->assertSame(self::CREATED, DesignSystem::load('borealis')->getPackage());
    $this->assertSame('borealis', $this->active()->packageOf(self::CREATED));
    $unit = Yaml::decode(file_get_contents($path . '/config/install/daedalus_blueprint.design_system.borealis.yml'));
    $this->assertSame(self::CREATED, $unit['package']);
    $this->assertSame(['module' => [self::CREATED]], $unit['dependencies']);
    $this->assertArrayNotHasKey('uuid', $unit);
    $this->assertFileExists($path . '/' . self::CREATED . '.daedalus_stylish.skins.dawn.yml');
    $this->assertSame(self::CREATED, $this->loadSkin('dawn')->getProvider());

    // The package is known now: the module needs no naming, and nothing
    // moves.
    $this->assertSame([
      DesignSystemShip::KIND_UNIT => ['borealis' => ShipReport::UNCHANGED],
      'skin' => ['dawn' => ShippedDefinitionsShipper::UNCHANGED],
    ], $this->ship()->ship('borealis')->outcomes());

    try {
      $this->ship()->plan('borealis', 'elsewhere');
      $this->fail('A packaged system planned into another module.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame(sprintf('The design system "borealis" is packaged by the "%s" module; a design system ships into its package.', self::CREATED), $e->getMessage());
    }
    DesignSystem::create(['id' => 'rival', 'label' => 'Rival'])->save();
    try {
      $this->ship()->plan('rival', self::CREATED);
      $this->fail('A second system planned into a packaging module.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame(sprintf('The "%s" module already packages the design system "borealis"; a module packages at most one.', self::CREATED), $e->getMessage());
    }
    try {
      $this->ship()->plan('rival', 'Not a module');
      $this->fail('A malformed module name was accepted.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringStartsWith('"Not a module" is not a module name', $e->getMessage());
    }
    try {
      $this->ship()->plan('nope');
      $this->fail('An unknown system was planned.');
    }
    catch (UnknownDesignSystemException $e) {
      $this->assertSame('No design system "nope"; the site has borealis, rival.', $e->getMessage());
    }
  }

  /**
   * The command prints the report in the sync command's grammar.
   *
   * The dry run prints the plan's lines and writes nothing; the run
   * prints the same lines and ships; a refusal exits non-zero with its
   * lines last; an unknown system fails with the reason.
   */
  public function testCommand(): void {
    DesignSystem::create(['id' => 'nebula', 'label' => 'Nebula', 'package' => self::TARGET])->save();
    $this->createSkin('dusk', 'nebula');
    $this->createMemberBlueprint('Nebula band', 'nebula_band', [], 'nebula');
    $expected = [
      self::TARGET . ': design_system nebula created',
      self::TARGET . ': blueprint nebula_band created',
      self::TARGET . ': skin dusk created',
    ];

    $tester = $this->tester();
    $this->assertSame(Command::SUCCESS, $tester->execute(['system' => 'nebula', '--dry-run' => TRUE]));
    $lines = $this->lines($tester);
    $this->assertSame($expected, array_slice($lines, 0, 3));
    $this->assertStringContainsString('blueprint nebula_band graduates', $lines[3]);
    $this->assertStringContainsString('Dry run: nothing was written.', end($lines));
    $this->assertFileDoesNotExist($this->file('daedalus_blueprint.blueprints.nebula_band.yml'));
    $this->assertNull($this->loadSkin('dusk')->getProvider());

    $this->assertSame(Command::SUCCESS, $tester->execute(['system' => 'nebula'], ['interactive' => FALSE]));
    $lines = $this->lines($tester);
    $this->assertSame($expected, array_slice($lines, 0, 3));
    $this->assertFileExists($this->file('daedalus_blueprint.blueprints.nebula_band.yml'));
    $this->assertFileExists($this->file('daedalus_stylish.skins.dusk.yml'));
    $this->assertSame(self::TARGET, $this->loadSkin('dusk')->getProvider());
    $this->assertSame([], Blueprint::loadMultiple(), 'The content blueprint graduated.');

    // A blueprint the lane refuses fails the run, its lines last; the
    // unit still reads.
    $this->createMemberBlueprint('Bad band', 'Bad band', [], 'nebula');
    $tester = $this->tester();
    $this->assertSame(Command::FAILURE, $tester->execute(['system' => 'nebula'], ['interactive' => FALSE]));
    $lines = $this->lines($tester);
    $this->assertSame(self::TARGET . ': design_system nebula unchanged', $lines[0]);
    $this->assertSame(self::TARGET . ': skin dusk unchanged', $lines[1]);
    $this->assertSame(self::TARGET . ': blueprint Bad band failed', $lines[2]);
    $this->assertSame('id [BLUEPRINT_SHIP_INVALID_ID]: "Bad band" is not a blueprint id.', $lines[3]);

    $tester = $this->tester();
    $this->assertSame(Command::FAILURE, $tester->execute(['system' => 'nope']));
    $this->assertStringContainsString('No design system "nope"; the site has nebula.', $tester->getDisplay());
  }

}
