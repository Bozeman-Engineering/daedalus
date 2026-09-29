<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Drush\Commands\ShipCommand;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Ship;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedDefinitionsShipper;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\SkinShipper;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantShipper;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the ship orchestrator and the daedalus_stylish:ship command.
 *
 * Ship runs the kind shippers (skins, variants) over one module: with no
 * names every per-id file is rebuilt whole from the current entities (keys
 * the shipper does not emit carried, entries whose entity is gone dropped,
 * the hand-authored single file read and never written); with names the
 * named site materials are adopted into the module, a new name landing in
 * MODULE.daedalus_stylish.<kind>.NAME.yml. The command prints the report in the sync
 * command's grammar, failures last and non-zero, and a dry run prints the
 * identical lines with nothing written. The target module is created under
 * the test's own site directory (sites/simpletest/ID, a root extension
 * discovery scans), never a real module directory, and removed on
 * teardown; see VariantShipperTest for the two conditions that make
 * discovery see it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishShipTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'daedalus_stylish_ship_target';

  /**
   * The styles of a site-created card variant.
   */
  protected const CARD_STYLES = ['' => ['default' => ['padding' => '2rem']]];

  /**
   * The styles a site edit moves a card variant to.
   */
  protected const EDITED_STYLES = ['' => ['default' => ['padding' => '3rem']]];

  /**
   * The tokens of the site-created dusk skin.
   */
  protected const DUSK_TOKENS = ['space' => ['gutter' => '2rem']];

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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // The vocabulary a definition syncing back through an importer
    // validates against: token scales for the skin tokens tier, base: for
    // the skin defaults.
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
   * The orchestrator, resolved late (installs rebuild the container).
   */
  protected function ship(): Ship {
    return \Drupal::service('daedalus_stylish.ship');
  }

  /**
   * The sync orchestrator, resolved late.
   */
  protected function sync(): ShippedSync {
    return \Drupal::service('daedalus_stylish.shipped_sync');
  }

  /**
   * The variant shipper, resolved late.
   */
  protected function variantShipper(): VariantShipper {
    return \Drupal::service('daedalus_stylish.variant_shipper');
  }

  /**
   * The skin shipper, resolved late.
   */
  protected function skinShipper(): SkinShipper {
    return \Drupal::service('daedalus_stylish.skin_shipper');
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
   * Saves a site-created container variant.
   */
  protected function createVariant(string $machine_name, array $styles = self::CARD_STYLES): DaedalusStylishVariantInterface {
    $variant = DaedalusStylishVariant::create([
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
   * Saves a site-created skin.
   */
  protected function createSkin(string $machine_name): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create([
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
    ]);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $skin->setTokens(self::DUSK_TOKENS);
    $skin->setDefaults(self::DUSK_DEFAULTS);
    $skin->save();
    return $skin;
  }

  /**
   * Edits a shipped variant's styles on the site (the gate's flow).
   */
  protected function editVariant(string $machine_name, array $styles = self::EDITED_STYLES): void {
    $variant = $this->loadVariant($machine_name);
    $variant->setStyles($styles);
    $variant->save();
    $this->assertTrue($this->loadVariant($machine_name)->isModified());
  }

  /**
   * Ships site variants into one per-id file through the kind shipper.
   *
   * The starting state of every module-wide test: a package on disk whose
   * entities the target module provides.
   *
   * @param string $file_id
   *   The per-id file's id (MODULE.daedalus_stylish.variants.FILE_ID.yml).
   * @param string[] $names
   *   Machine names of existing site variants.
   */
  protected function shipVariants(string $file_id, array $names): void {
    $definitions = [];
    foreach ($names as $name) {
      $definitions[$name] = $this->variantShipper()->definition($this->loadVariant($name));
    }
    $this->variantShipper()->ship(self::TARGET, $file_id, $definitions);
  }

  /**
   * A file of the target module, absolute.
   */
  protected function file(string $name): string {
    return $this->targetPath . '/' . self::TARGET . ".$name";
  }

  /**
   * A file's entries as authored.
   */
  protected function entries(string $name): array {
    return Yaml::decode(file_get_contents($this->file($name)));
  }

  /**
   * A fresh tester over the command, built from the current container.
   */
  protected function tester(): CommandTester {
    return new CommandTester(ShipCommand::create($this->container));
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
   * The shippers are keyed by kind in the sync's order: skins, variants.
   */
  public function testShippersKeyedByKind(): void {
    $shippers = $this->ship()->shippers();
    $this->assertSame(['skin', 'variant'], array_keys($shippers));
    $this->assertInstanceOf(SkinShipper::class, $shippers['skin']);
    $this->assertInstanceOf(VariantShipper::class, $shippers['variant']);
  }

  /**
   * With no names, every per-id file is rebuilt from the current entities.
   *
   * A site edit of a shipped variant and of a shipped skin reaches disk
   * as an UPDATE, an untouched entry reads UNCHANGED, the entities are
   * re-stamped (unmodified again), and the sync right after reports
   * UNCHANGED for every name: the site and disk agree.
   */
  public function testModuleWideRebuildFromEntities(): void {
    $this->createVariant('card_left');
    $this->createVariant('card_right', ['' => ['default' => ['padding' => '1rem']]]);
    $this->shipVariants('card', ['card_left', 'card_right']);
    $dusk = $this->createSkin('dusk');
    $this->skinShipper()->ship(self::TARGET, 'dusk', ['dusk' => $this->skinShipper()->definition($dusk)]);

    $this->editVariant('card_left');
    $edited_defaults = ['base:h2' => ['' => ['default' => ['font-size' => '4rem']]]];
    $skin = $this->loadSkin('dusk');
    $skin->setDefaults($edited_defaults);
    $skin->save();
    $this->assertTrue($this->loadSkin('dusk')->isModified());

    // The plan is the dry run: the same report, nothing written.
    $planned = $this->ship()->plan(self::TARGET);
    $this->assertSame([
      'skin' => ['dusk' => ShippedDefinitionsShipper::UPDATED],
      'variant' => [
        'card_left' => ShippedDefinitionsShipper::UPDATED,
        'card_right' => ShippedDefinitionsShipper::UNCHANGED,
      ],
    ], $planned->outcomes());
    $this->assertFalse($planned->hasFailures());
    $this->assertSame(self::CARD_STYLES, $this->entries('daedalus_stylish.variants.card.yml')['card_left']['styles']);
    $this->assertSame(self::DUSK_DEFAULTS, $this->entries('daedalus_stylish.skins.dusk.yml')['dusk']['defaults']);
    $this->assertTrue($this->loadVariant('card_left')->isModified());

    $report = $this->ship()->ship(self::TARGET);
    $this->assertSame($planned->outcomes(), $report->outcomes());
    $this->assertSame(self::TARGET, $report->module());
    $this->assertSame(self::TARGET . '.daedalus_stylish.variants.card.yml', $report->file('variant', 'card_left'));
    $this->assertSame(self::TARGET . '.daedalus_stylish.skins.dusk.yml', $report->file('skin', 'dusk'));
    $this->assertSame(self::EDITED_STYLES, $this->entries('daedalus_stylish.variants.card.yml')['card_left']['styles']);
    $this->assertSame(['card_left', 'card_right'], array_keys($this->entries('daedalus_stylish.variants.card.yml')));
    $this->assertSame($edited_defaults, $this->entries('daedalus_stylish.skins.dusk.yml')['dusk']['defaults']);
    $this->assertFalse($this->loadVariant('card_left')->isModified());
    $this->assertFalse($this->loadSkin('dusk')->isModified());
    $this->assertSame(self::TARGET, $this->loadVariant('card_left')->getProvider());

    $sync = $this->sync()->sync([self::TARGET]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $sync->report('skin')->outcomes());
    $this->assertSame([
      self::TARGET => [
        'card_left' => ShippedDefinitionsImporter::UNCHANGED,
        'card_right' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $sync->report('variant')->outcomes());

    // A second ship of a converged module changes nothing.
    $this->assertSame([
      'skin' => ['dusk' => ShippedDefinitionsShipper::UNCHANGED],
      'variant' => [
        'card_left' => ShippedDefinitionsShipper::UNCHANGED,
        'card_right' => ShippedDefinitionsShipper::UNCHANGED,
      ],
    ], $this->ship()->ship(self::TARGET)->outcomes());
  }

  /**
   * Keys the shipper does not emit are carried from the existing entry.
   *
   * The variants file's `stencil:` key belongs to the stencil shipper;
   * daedalus_stylish never learns what it means, so a rebuild keeps it where its
   * owner put it, and an entry whose only difference is a carried key
   * reads UNCHANGED.
   */
  public function testCarriesKeysTheShipperDoesNotEmit(): void {
    $this->createVariant('card_left');
    $this->shipVariants('card', ['card_left']);
    $entries = $this->entries('daedalus_stylish.variants.card.yml');
    $this->assertSame(['label', 'scope', 'design_system', 'styles'], array_keys($entries['card_left']));
    $owned = [
      'label' => $entries['card_left']['label'],
      'scope' => $entries['card_left']['scope'],
      'stencil' => 'card',
      'design_system' => NULL,
      'styles' => self::CARD_STYLES,
    ];
    file_put_contents($this->file('daedalus_stylish.variants.card.yml'), Yaml::encode(['card_left' => $owned]));

    $this->assertSame(['variant' => ['card_left' => ShippedDefinitionsShipper::UNCHANGED]], $this->ship()->ship(self::TARGET)->outcomes());
    $this->assertSame(['card_left' => $owned], $this->entries('daedalus_stylish.variants.card.yml'));
    $this->assertStringStartsWith('# Machine-written by daedalus_stylish (VariantShipper)', file_get_contents($this->file('daedalus_stylish.variants.card.yml')));

    // The entity wins every key it owns; the carried key stays in place.
    $this->editVariant('card_left');
    $this->assertSame(['variant' => ['card_left' => ShippedDefinitionsShipper::UPDATED]], $this->ship()->ship(self::TARGET)->outcomes());
    $this->assertSame(array_replace($owned, ['styles' => self::EDITED_STYLES]), $this->entries('daedalus_stylish.variants.card.yml')['card_left']);
  }

  /**
   * An entry whose entity no longer exists is dropped from the rewrite.
   *
   * The disk lane never deletes an entity, but a rebuild ships what the
   * site holds: a deleted variant leaves the file, and a file left with
   * no entry leaves disk.
   */
  public function testDropsEntriesWhoseEntityIsGone(): void {
    $this->createVariant('card_left');
    $this->createVariant('card_right', ['' => ['default' => ['padding' => '1rem']]]);
    $this->shipVariants('card', ['card_left', 'card_right']);
    $this->loadVariant('card_right')->delete();

    $dropped = [
      'variant' => [
        'card_left' => ShippedDefinitionsShipper::UNCHANGED,
        'card_right' => ShippedDefinitionsShipper::DROPPED,
      ],
    ];
    $this->assertSame($dropped, $this->ship()->plan(self::TARGET)->outcomes());
    $this->assertSame(['card_left', 'card_right'], array_keys($this->entries('daedalus_stylish.variants.card.yml')));
    $this->assertSame($dropped, $this->ship()->ship(self::TARGET)->outcomes());
    $this->assertSame(['card_left'], array_keys($this->entries('daedalus_stylish.variants.card.yml')));
    $this->assertSame([self::TARGET => ['card_left' => ShippedDefinitionsImporter::UNCHANGED]], $this->sync()->sync([self::TARGET])->report('variant')->outcomes());

    $this->loadVariant('card_left')->delete();
    $this->assertSame(['variant' => ['card_left' => ShippedDefinitionsShipper::DROPPED]], $this->ship()->ship(self::TARGET)->outcomes());
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.variants.card.yml'));
    $this->assertTrue($this->ship()->ship(self::TARGET)->isEmpty());
  }

  /**
   * The hand-authored single file is read, reported, and never written.
   *
   * An entry of MODULE.daedalus_stylish.variants.yml the site edited reads
   * MODIFIED (the author edits the file by hand); an untouched one reads
   * UNCHANGED; the file's bytes never move. Naming such an entry for
   * adoption refuses: the name is shipped by another file of the module.
   */
  public function testHandAuthoredEntriesReportedNeverWritten(): void {
    $hand = [
      'hand_section' => ['label' => 'Hand section', 'scope' => 'kind:container', 'styles' => self::CARD_STYLES],
      'hand_aside' => ['label' => 'Hand aside', 'scope' => 'kind:container', 'styles' => self::CARD_STYLES],
    ];
    file_put_contents($this->file('daedalus_stylish.variants.yml'), Yaml::encode($hand));
    $authored = file_get_contents($this->file('daedalus_stylish.variants.yml'));
    $this->assertSame([
      self::TARGET => [
        'hand_section' => ShippedDefinitionsImporter::CREATED,
        'hand_aside' => ShippedDefinitionsImporter::CREATED,
      ],
    ], $this->sync()->sync([self::TARGET])->report('variant')->outcomes());
    $this->createVariant('card_left');
    $this->shipVariants('card', ['card_left']);
    $this->editVariant('hand_section');

    $report = $this->ship()->ship(self::TARGET);
    $this->assertSame([
      'variant' => [
        'hand_section' => Ship::MODIFIED,
        'hand_aside' => ShippedDefinitionsShipper::UNCHANGED,
        'card_left' => ShippedDefinitionsShipper::UNCHANGED,
      ],
    ], $report->outcomes());
    $this->assertSame(self::TARGET . '.daedalus_stylish.variants.yml', $report->file('variant', 'hand_section'));
    $this->assertFalse($report->hasFailures());
    $this->assertSame($authored, file_get_contents($this->file('daedalus_stylish.variants.yml')));
    $this->assertTrue($this->loadVariant('hand_section')->isModified());

    $report = $this->ship()->ship(self::TARGET, ['variant' => ['hand_section']]);
    $this->assertSame(['variant' => [self::TARGET . '.daedalus_stylish.variants.hand_section.yml' => ShippedDefinitionsImporter::FAILED]], $report->outcomes());
    $errors = $report->errors('variant', self::TARGET . '.daedalus_stylish.variants.hand_section.yml');
    $this->assertCount(1, $errors);
    $this->assertSame('SHIPPED_VARIANT_CONFLICT', $errors[0]->getCode());
    $this->assertStringContainsString(self::TARGET . '.daedalus_stylish.variants.yml', $errors[0]->getMessage());
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.variants.hand_section.yml'));
    $this->assertSame($authored, file_get_contents($this->file('daedalus_stylish.variants.yml')));
  }

  /**
   * Named site materials are adopted into MODULE.daedalus_stylish.<kind>.NAME.yml.
   *
   * A new name lands in its own per-id file and the sync right after
   * reads UNCHANGED; a name already in one of the module's per-id files
   * rebuilds that file (never a second one); a name another module ships,
   * or that no entity carries, is refused with the shipper's own error
   * while the other kind still ships.
   */
  public function testNamedAdoption(): void {
    $this->createVariant('card_blue');
    $this->createSkin('dusk');

    $planned = $this->ship()->plan(self::TARGET, ['variant' => ['card_blue'], 'skin' => ['dusk']]);
    $this->assertSame([
      'skin' => ['dusk' => ShippedDefinitionsShipper::CREATED],
      'variant' => ['card_blue' => ShippedDefinitionsShipper::CREATED],
    ], $planned->outcomes());
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.variants.card_blue.yml'));
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.skins.dusk.yml'));
    $this->assertNull($this->loadVariant('card_blue')->getProvider());

    $report = $this->ship()->ship(self::TARGET, ['variant' => ['card_blue'], 'skin' => ['dusk']]);
    $this->assertSame($planned->outcomes(), $report->outcomes());
    $this->assertSame(['card_blue'], array_keys($this->entries('daedalus_stylish.variants.card_blue.yml')));
    $this->assertSame(['dusk'], array_keys($this->entries('daedalus_stylish.skins.dusk.yml')));
    $this->assertSame(self::TARGET, $this->loadVariant('card_blue')->getProvider());
    $this->assertSame(self::TARGET, $this->loadSkin('dusk')->getProvider());
    $this->assertTrue($this->loadVariant('card_blue')->isPublished());
    $sync = $this->sync()->sync([self::TARGET]);
    $this->assertSame([self::TARGET => ['dusk' => ShippedDefinitionsImporter::UNCHANGED]], $sync->report('skin')->outcomes());
    $this->assertSame([self::TARGET => ['card_blue' => ShippedDefinitionsImporter::UNCHANGED]], $sync->report('variant')->outcomes());

    // A name already this module's rebuilds the file that holds it.
    $this->editVariant('card_blue');
    $this->assertSame(['variant' => ['card_blue' => ShippedDefinitionsShipper::UPDATED]], $this->ship()->ship(self::TARGET, ['variant' => ['card_blue']])->outcomes());
    $this->assertSame(self::EDITED_STYLES, $this->entries('daedalus_stylish.variants.card_blue.yml')['card_blue']['styles']);
    $this->assertSame(['daedalus_stylish.skins.dusk.yml', 'daedalus_stylish.variants.card_blue.yml'], array_map(static fn (string $path): string => substr(basename($path), strlen(self::TARGET) + 1), glob($this->targetPath . '/*.daedalus_stylish.*.yml')));

    // A name shipped by another module, or carried by no entity, refuses
    // its own file; the other names still ship, whatever their kind.
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
    $this->assertSame('daedalus_stylish_skins_test', $this->loadSkin('aurora')->getProvider());
    $this->createVariant('card_red');
    $report = $this->ship()->ship(self::TARGET, ['skin' => ['aurora'], 'variant' => ['ghost', 'card_red']]);
    $this->assertSame([
      'skin' => [self::TARGET . '.daedalus_stylish.skins.aurora.yml' => ShippedDefinitionsImporter::FAILED],
      'variant' => ['ghost' => ShippedDefinitionsImporter::FAILED, 'card_red' => ShippedDefinitionsShipper::CREATED],
    ], $report->outcomes());
    $this->assertTrue($report->hasFailures());
    $this->assertSame('SHIPPED_SKIN_CONFLICT', $report->errors('skin', self::TARGET . '.daedalus_stylish.skins.aurora.yml')[0]->getCode());
    $this->assertSame('SHIPPED_VARIANT_ABSENT', $report->errors('variant', 'ghost')[0]->getCode());
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.skins.aurora.yml'));
    $this->assertFileDoesNotExist($this->file('daedalus_stylish.variants.ghost.yml'));
    $this->assertFileExists($this->file('daedalus_stylish.variants.card_red.yml'));
    $this->assertSame('daedalus_stylish_skins_test', $this->loadSkin('aurora')->getProvider());
    $this->assertSame([
      self::TARGET . ': skin ' . self::TARGET . '.daedalus_stylish.skins.aurora.yml failed',
      '  aurora [SHIPPED_SKIN_CONFLICT]: The machine name "aurora" is already shipped by the "daedalus_stylish_skins_test" module.',
      '    Hint: Machine names are the site-wide skin vocabulary; a name ships from exactly one file of one module. Leave it to its shipper, or rename the skin.',
      self::TARGET . ': variant ghost failed',
      '  ghost [SHIPPED_VARIANT_ABSENT]: No variant named "ghost" exists on this site, so there is nothing to ship.',
      '    Hint: Shipping takes an existing variant to disk and adopts it in place; to bring a definition onto the site, put it in the file and sync.',
    ], $report->failureLines());

    // A module that is not enabled has no directory to ship into.
    $this->expectException(\InvalidArgumentException::class);
    $this->ship()->ship('daedalus_stylish_nowhere', ['variant' => ['card_blue']]);
  }

  /**
   * The command prints one line per entry in the sync command's grammar.
   *
   * Outcomes in kind order (skins, then variants), a hand-authored entry
   * told to be edited by hand, failures last with their path, code,
   * message and hint, and a non-zero exit when anything was refused. A
   * dry run prints the identical lines and writes nothing; a module with
   * nothing to ship is told which files a rebuild reads and which options
   * adopt; a module that is not enabled fails with the reason.
   */
  public function testCommand(): void {
    file_put_contents($this->file('daedalus_stylish.variants.yml'), Yaml::encode([
      'hand_section' => ['label' => 'Hand section', 'scope' => 'kind:container', 'styles' => self::CARD_STYLES],
    ]));
    $authored = file_get_contents($this->file('daedalus_stylish.variants.yml'));
    $this->sync()->sync([self::TARGET]);
    $this->createVariant('card_left');
    $this->createVariant('card_right', ['' => ['default' => ['padding' => '1rem']]]);
    $this->shipVariants('card', ['card_left', 'card_right']);
    $dusk = $this->createSkin('dusk');
    $this->skinShipper()->ship(self::TARGET, 'dusk', ['dusk' => $this->skinShipper()->definition($dusk)]);
    $this->editVariant('card_left');
    $this->editVariant('hand_section');
    $this->loadVariant('card_right')->delete();
    $card = file_get_contents($this->file('daedalus_stylish.variants.card.yml'));

    $expected = [
      self::TARGET . ': skin dusk unchanged',
      self::TARGET . ': variant hand_section modified; edit by hand in ' . self::TARGET . '.daedalus_stylish.variants.yml',
      self::TARGET . ': variant card_left updated',
      self::TARGET . ': variant card_right dropped',
    ];

    // The dry run: the identical lines, nothing written, nothing adopted.
    $tester = $this->tester();
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::TARGET, '--dry-run' => TRUE]));
    $this->assertSame($expected, $this->lines($tester));
    $this->assertSame($card, file_get_contents($this->file('daedalus_stylish.variants.card.yml')));
    $this->assertTrue($this->loadVariant('card_left')->isModified());

    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::TARGET]));
    $this->assertSame($expected, $this->lines($tester));
    $this->assertSame(['card_left'], array_keys($this->entries('daedalus_stylish.variants.card.yml')));
    $this->assertSame(self::EDITED_STYLES, $this->entries('daedalus_stylish.variants.card.yml')['card_left']['styles']);
    $this->assertFalse($this->loadVariant('card_left')->isModified());
    $this->assertSame($authored, file_get_contents($this->file('daedalus_stylish.variants.yml')));

    // Named adoption, repeatable per kind; a refusal prints last and fails
    // the run while the other names ship.
    $this->createVariant('card_blue');
    $this->createSkin('dawn');
    $this->assertSame(Command::FAILURE, $tester->execute([
      'module' => self::TARGET,
      '--variant' => ['card_blue', 'ghost'],
      '--skin' => ['dawn'],
    ]));
    $this->assertSame([
      self::TARGET . ': skin dawn created',
      self::TARGET . ': variant card_blue created',
      self::TARGET . ': variant ghost failed',
      'ghost [SHIPPED_VARIANT_ABSENT]: No variant named "ghost" exists on this site, so there is nothing to ship.',
      'Hint: Shipping takes an existing variant to disk and adopts it in place; to bring a definition onto the site, put it in the file and sync.',
    ], $this->lines($tester));
    $this->assertFileExists($this->file('daedalus_stylish.variants.card_blue.yml'));
    $this->assertFileExists($this->file('daedalus_stylish.skins.dawn.yml'));
    $this->assertSame(self::TARGET, $this->loadSkin('dawn')->getProvider());

    // The sync right after reads the whole package UNCHANGED.
    $sync = $this->sync()->sync([self::TARGET]);
    $this->assertSame([
      self::TARGET => [
        'dawn' => ShippedDefinitionsImporter::UNCHANGED,
        'dusk' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $sync->report('skin')->outcomes());
    $this->assertSame([
      self::TARGET => [
        'hand_section' => ShippedDefinitionsImporter::UNCHANGED,
        'card_left' => ShippedDefinitionsImporter::UNCHANGED,
        'card_blue' => ShippedDefinitionsImporter::UNCHANGED,
      ],
    ], $sync->report('variant')->outcomes());

    // A module with no per-id file and no name is told what a rebuild
    // reads and what adopts; one that is not enabled fails with the reason.
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => 'system']));
    $this->assertSame('system ships no per-id daedalus_stylish file (no system.daedalus_stylish.skins.*.yml or system.daedalus_stylish.variants.*.yml); name a site material to adopt with --skin NAME or --variant NAME.', trim($tester->getDisplay()));
    $this->assertSame(Command::FAILURE, $tester->execute(['module' => 'daedalus_stylish_nowhere']));
    $this->assertSame('The "daedalus_stylish_nowhere" module is not enabled, so there is no directory to ship into.', trim($tester->getDisplay()));
  }

}
